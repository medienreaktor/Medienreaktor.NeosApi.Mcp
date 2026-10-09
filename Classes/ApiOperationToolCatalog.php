<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Mcp;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cache\CacheManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Derives the MCP tools from the OpenAPI document, so the API contract stays
 * the single source: a tool's description and input schema are the
 * operation's summary, description, parameters and JSON request body.
 *
 * Which operations become tools is configured under
 * Medienreaktor.NeosApi.Mcp.tools (tool name => operation id plus hints).
 */
#[Flow\Scope('singleton')]
class ApiOperationToolCatalog
{
    private const SPEC_RESOURCE = 'resource://Medienreaktor.NeosApi/Private/OpenApi/openapi.yaml';
    private const HTTP_METHODS = ['get', 'put', 'post', 'patch', 'delete'];

    /**
     * @var array<string, array{operation?: string, readOnly?: bool, destructive?: bool}|null>
     */
    #[Flow\InjectConfiguration(path: 'tools')]
    protected array $toolSettings = [];

    #[Flow\Inject]
    protected CacheManager $cacheManager;

    /**
     * @return list<ApiOperationTool>
     */
    public function getTools(): array
    {
        $specSource = (string)file_get_contents(self::SPEC_RESOURCE);
        $cacheKey = sha1($specSource . json_encode($this->toolSettings, JSON_THROW_ON_ERROR));
        $cache = $this->cacheManager->getCache('MedienreaktorNeosApiMcp_Tools');
        $tools = $cache->get($cacheKey);
        if (is_array($tools)) {
            return $tools;
        }

        $tools = $this->buildTools(Yaml::parse($specSource));
        $cache->set($cacheKey, $tools);

        return $tools;
    }

    /**
     * @param array<string, mixed> $spec
     * @return list<ApiOperationTool>
     */
    private function buildTools(array $spec): array
    {
        $operations = $this->indexOperations($spec);

        $tools = [];
        foreach ($this->toolSettings as $toolName => $toolSetting) {
            if ($toolSetting === null) {
                continue;
            }
            $operationId = $toolSetting['operation'] ?? null;
            if (!is_string($operationId) || !isset($operations[$operationId])) {
                throw new \RuntimeException(sprintf('MCP tool "%s" names the operation "%s", which the OpenAPI document does not contain.', $toolName, (string)$operationId), 1760011201);
            }
            $tools[] = $this->buildTool(
                $toolName,
                $operationId,
                $operations[$operationId],
                $spec,
                (bool)($toolSetting['readOnly'] ?? false),
                (bool)($toolSetting['destructive'] ?? false)
            );
        }

        return $tools;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, array{method: string, path: string, operation: array<string, mixed>}>
     */
    private function indexOperations(array $spec): array
    {
        $operations = [];
        foreach ($spec['paths'] ?? [] as $path => $pathItem) {
            foreach ($pathItem as $method => $operation) {
                if (!in_array($method, self::HTTP_METHODS, true) || !isset($operation['operationId'])) {
                    continue;
                }
                $operations[$operation['operationId']] = ['method' => strtoupper($method), 'path' => $path, 'operation' => $operation];
            }
        }

        return $operations;
    }

    /**
     * @param array{method: string, path: string, operation: array<string, mixed>} $entry
     * @param array<string, mixed> $spec
     */
    private function buildTool(string $toolName, string $operationId, array $entry, array $spec, bool $readOnly, bool $destructive): ApiOperationTool
    {
        $operation = $entry['operation'];
        $properties = [];
        $required = [];
        $pathParameters = [];
        $queryParameters = [];

        foreach ($operation['parameters'] ?? [] as $parameter) {
            $parameter = $this->resolveParameter($parameter, $spec);
            $name = $parameter['name'];
            $property = $parameter['schema'] ?? ['type' => 'string'];
            if (isset($parameter['description'])) {
                $property['description'] = trim($parameter['description']);
            }
            $properties[$name] = $property;
            if (($parameter['required'] ?? false) === true) {
                $required[] = $name;
            }
            match ($parameter['in']) {
                'path' => $pathParameters[] = $name,
                'query' => $queryParameters[] = $name,
                default => throw new \RuntimeException(sprintf('MCP tool "%s": parameters in "%s" are not supported.', $toolName, $parameter['in']), 1760011202),
            };
        }

        $acceptsBody = false;
        if (isset($operation['requestBody'])) {
            $bodySchema = $operation['requestBody']['content']['application/json']['schema'] ?? null;
            if ($bodySchema === null) {
                throw new \RuntimeException(sprintf('MCP tool "%s": operation "%s" has no JSON request body, only JSON bodies can be sent through MCP.', $toolName, $operationId), 1760011203);
            }
            $acceptsBody = true;
            $properties['body'] = $bodySchema + ['description' => 'The JSON request body.'];
            if (($operation['requestBody']['required'] ?? false) === true) {
                $required[] = 'body';
            }
        }

        $inputSchema = ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties];
        if ($required !== []) {
            $inputSchema['required'] = $required;
        }
        $definitions = $this->collectSchemaDefinitions($inputSchema, $spec);
        if ($definitions !== []) {
            $inputSchema['$defs'] = $definitions;
        }

        return new ApiOperationTool(
            name: $toolName,
            operationId: $operationId,
            method: $entry['method'],
            pathTemplate: $entry['path'],
            pathParameters: $pathParameters,
            queryParameters: $queryParameters,
            acceptsBody: $acceptsBody,
            description: trim(($operation['summary'] ?? '') . "\n\n" . ($operation['description'] ?? '')),
            inputSchema: $this->toToolInputSchema($inputSchema),
            readOnly: $readOnly,
            destructive: $destructive,
        );
    }

    /**
     * @param array<string, mixed> $parameter
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function resolveParameter(array $parameter, array $spec): array
    {
        if (!isset($parameter['$ref'])) {
            return $parameter;
        }
        $name = substr($parameter['$ref'], strlen('#/components/parameters/'));

        return $spec['components']['parameters'][$name]
            ?? throw new \RuntimeException(sprintf('Unresolvable parameter reference "%s" in the OpenAPI document.', $parameter['$ref']), 1760011204);
    }

    /**
     * The component schemas the input schema references, transitively - they
     * travel along as $defs, so the tool schema is self-contained.
     *
     * @param array<mixed> $schema
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function collectSchemaDefinitions(array $schema, array $spec): array
    {
        $definitions = [];
        $pending = $this->findSchemaReferences($schema);
        while ($pending !== []) {
            $name = array_shift($pending);
            if (isset($definitions[$name])) {
                continue;
            }
            $definition = $spec['components']['schemas'][$name]
                ?? throw new \RuntimeException(sprintf('Unresolvable schema reference "%s" in the OpenAPI document.', $name), 1760011205);
            $definitions[$name] = $definition;
            array_push($pending, ...$this->findSchemaReferences($definition));
        }

        return $definitions;
    }

    /**
     * @param array<mixed> $schema
     * @return list<string>
     */
    private function findSchemaReferences(array $schema): array
    {
        $references = [];
        array_walk_recursive($schema, static function (mixed $value, int|string $key) use (&$references): void {
            if ($key === '$ref' && is_string($value) && str_starts_with($value, '#/components/schemas/')) {
                $references[] = substr($value, strlen('#/components/schemas/'));
            }
        });

        return $references;
    }

    /**
     * The root of a tool's input schema must be a plain object schema - the
     * empty array allowance (see toJsonSchema) only applies below it, where
     * the SDK's decoding loses the difference.
     *
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private function toToolInputSchema(array $schema): array
    {
        $converted = $this->toJsonSchema($schema);
        $converted['type'] = 'object';
        unset($converted['maxItems']);

        return $converted;
    }

    /**
     * OpenAPI 3.1 schemas are JSON Schema, apart from where they point to.
     * oneOf becomes anyOf: the API documents several payload shapes that
     * overlap (no additionalProperties: false), and oneOf would reject a valid
     * payload for matching more than one of them.
     *
     * Objects also accept an empty array: the MCP SDK decodes the arguments to
     * PHP arrays before validating them, so an empty object such as the
     * dimension space point {} of a repository without dimensions arrives as
     * [] and would fail "type: object".
     *
     * @param array<mixed> $schema
     * @return array<mixed>
     */
    private function toJsonSchema(array $schema): array
    {
        $converted = [];
        foreach ($schema as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $value = str_replace('#/components/schemas/', '#/$defs/', $value);
            }
            if ($key === 'oneOf') {
                $key = 'anyOf';
            }
            $converted[$key] = is_array($value) ? $this->toJsonSchema($value) : $value;
        }
        if (($converted['type'] ?? null) === 'object') {
            $converted['type'] = ['object', 'array'];
            $converted['maxItems'] = 0;
        }

        return $converted;
    }
}

<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Mcp;

use Neos\Flow\Annotations as Flow;

/**
 * An MCP tool that is one documented API operation: calling the tool performs
 * the HTTP operation in-process, so it behaves exactly like the REST endpoint
 * (same policy, scopes and content repository authorization).
 */
#[Flow\Proxy(false)]
final readonly class ApiOperationTool
{
    /**
     * @param list<string> $pathParameters
     * @param list<string> $queryParameters
     * @param array<string, mixed> $inputSchema
     */
    public function __construct(
        public string $name,
        public string $operationId,
        public string $method,
        public string $pathTemplate,
        public array $pathParameters,
        public array $queryParameters,
        public bool $acceptsBody,
        public string $description,
        public array $inputSchema,
        public bool $readOnly,
        public bool $destructive,
    ) {
    }

    /**
     * @param array<string, mixed> $arguments the tool call arguments, already validated against the input schema
     */
    public function resolvePath(array $arguments): string
    {
        $path = $this->pathTemplate;
        foreach ($this->pathParameters as $name) {
            $path = str_replace('{' . $name . '}', rawurlencode((string)$arguments[$name]), $path);
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, string>
     */
    public function resolveQuery(array $arguments): array
    {
        $query = [];
        foreach ($this->queryParameters as $name) {
            if (!array_key_exists($name, $arguments) || $arguments[$name] === null) {
                continue;
            }
            $value = $arguments[$name];
            $query[$name] = match (true) {
                is_bool($value) => $value ? '1' : '0',
                is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                default => (string)$value,
            };
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<mixed>|null
     */
    public function resolveBody(array $arguments): ?array
    {
        if (!$this->acceptsBody || !isset($arguments['body'])) {
            return null;
        }

        return (array)$arguments['body'];
    }
}

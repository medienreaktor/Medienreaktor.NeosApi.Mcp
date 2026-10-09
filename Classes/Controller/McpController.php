<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Mcp\Controller;

use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Result\CallToolResult;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\RequestContext;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\Http\Middleware\DnsRebindingProtectionMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Medienreaktor\NeosApi\Mcp\ApiOperationTool;
use Medienreaktor\NeosApi\Mcp\ApiOperationToolCatalog;
use Medienreaktor\NeosApi\Mcp\FlowCacheSessionStore;
use Medienreaktor\NeosApi\Mcp\InProcessApiClient;
use Medienreaktor\NeosApi\Security\Authentication\Token\ApiBearerToken;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Log\PsrLoggerFactoryInterface;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Security\Context as SecurityContext;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The MCP endpoint (Streamable HTTP). Every tool is one Neos API operation,
 * performed in-process on behalf of the bearer token that called /mcp.
 *
 * Unauthenticated requests are answered here rather than by the firewall, so
 * the 401 carries the WWW-Authenticate header that MCP clients follow to the
 * OAuth discovery documents.
 */
class McpController extends ActionController
{
    /**
     * @var array<string>
     */
    protected $supportedMediaTypes = ['application/json', 'text/event-stream'];

    #[Flow\InjectConfiguration(path: 'enabled')]
    protected bool $enabled = false;

    #[Flow\InjectConfiguration(path: 'instructions')]
    protected string $instructions = '';

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    #[Flow\Inject]
    protected ApiOperationToolCatalog $toolCatalog;

    #[Flow\Inject]
    protected InProcessApiClient $apiClient;

    #[Flow\Inject]
    protected CacheManager $cacheManager;

    #[Flow\Inject]
    protected PackageManager $packageManager;

    #[Flow\Inject]
    protected ThrowableStorageInterface $throwableLog;

    #[Flow\Inject]
    protected PsrLoggerFactoryInterface $loggerFactory;

    /**
     * Authenticated exclusively via sessionless bearer tokens - CSRF does not apply
     */
    #[Flow\SkipCsrfProtection]
    public function handleAction(): string
    {
        if (!$this->enabled) {
            $this->throwStatus(404);
        }

        $httpRequest = $this->request->getHttpRequest();
        if ($httpRequest->getMethod() !== 'OPTIONS' && !$this->isAuthenticatedByBearerToken()) {
            $this->response->setHttpHeader('WWW-Authenticate', sprintf('Bearer resource_metadata="%s"', $this->getBaseUri() . '/.well-known/oauth-protected-resource/mcp'));
            $this->response->setContentType('application/json');
            $this->throwStatus(401, null, json_encode([
                'error' => 'unauthorized',
                'error_description' => 'A bearer access token is required.',
            ], JSON_THROW_ON_ERROR));
        }

        $transport = new StreamableHttpTransport(
            $httpRequest,
            logger: $this->loggerFactory->get('mcpSdkLogger'),
            middleware: [
                new CorsMiddleware(),
                new DnsRebindingProtectionMiddleware([$httpRequest->getUri()->getHost()]),
            ],
        );
        $psrResponse = $this->buildServer($httpRequest)->run($transport);

        $this->response->setStatusCode($psrResponse->getStatusCode());
        foreach ($psrResponse->getHeaders() as $name => $values) {
            $this->response->setHttpHeader($name, implode(', ', $values));
        }
        // An MCP session is per account, and an editing tool wants fresh data.
        $this->response->setHttpHeader('Cache-Control', 'private, no-store');

        return (string)$psrResponse->getBody();
    }

    private function buildServer(ServerRequestInterface $httpRequest): Server
    {
        $builder = Server::builder()
            ->setServerInfo('Neos', (string)$this->packageManager->getPackage('Medienreaktor.NeosApi.Mcp')->getInstalledVersion())
            ->setInstructions(trim($this->instructions) !== '' ? trim($this->instructions) : null)
            ->setLogger($this->loggerFactory->get('mcpSdkLogger'))
            ->setSession(new FlowCacheSessionStore($this->cacheManager->getCache('MedienreaktorNeosApiMcp_Sessions')), gcProbability: 0);

        foreach ($this->toolCatalog->getTools() as $tool) {
            $builder->addTool(
                fn (RequestContext $context): CallToolResult => $this->callTool($tool, $httpRequest, $context),
                name: $tool->name,
                description: $tool->description,
                annotations: new ToolAnnotations(
                    readOnlyHint: $tool->readOnly,
                    destructiveHint: $tool->readOnly ? null : $tool->destructive,
                    openWorldHint: false,
                ),
                inputSchema: $tool->inputSchema,
            );
        }

        return $builder->build();
    }

    /**
     * The API's own answer goes back to the model as is: an error status
     * becomes a tool error with the API's error body, so the model sees
     * exactly what the REST client would and can correct its call.
     */
    private function callTool(ApiOperationTool $tool, ServerRequestInterface $httpRequest, RequestContext $context): CallToolResult
    {
        $request = $context->getRequest();
        if (!$request instanceof CallToolRequest) {
            throw new \LogicException(sprintf('MCP tool "%s" was invoked by a %s instead of a tool call.', $tool->name, get_debug_type($request)), 1760011301);
        }
        $arguments = $request->arguments;

        try {
            $response = $this->apiClient->send(
                $httpRequest,
                $tool->method,
                $tool->resolvePath($arguments),
                $tool->resolveQuery($arguments),
                $tool->resolveBody($arguments),
            );
        } catch (\Throwable $throwable) {
            // Logged here, once and in full: the SDK would log the exception
            // object only, which Flow's logger renders without message or trace.
            $logReference = $this->throwableLog->logThrowable($throwable, [
                'tool' => $tool->name,
                'operation' => $tool->operationId,
                'arguments' => $arguments,
            ]);
            $this->logger->error('NeosApi MCP: tool call failed. ' . $logReference, [
                'tool' => $tool->name,
                'operation' => $tool->operationId,
            ]);

            // The client gets the log reference only - message and trace stay in the log.
            $reference = preg_match('/See also: (\S+)/', $logReference, $matches) === 1 ? ' Log reference: ' . $matches[1] : '';

            return CallToolResult::error([new TextContent('The tool failed with an internal error.' . $reference)]);
        }

        $content = [new TextContent((string)$response->getBody())];
        if ($response->getStatusCode() >= 400) {
            $this->logger->info('NeosApi MCP: tool call answered with an API error', [
                'tool' => $tool->name,
                'operation' => $tool->operationId,
                'status' => $response->getStatusCode(),
            ]);

            return CallToolResult::error($content);
        }

        return CallToolResult::success($content);
    }

    private function isAuthenticatedByBearerToken(): bool
    {
        foreach ($this->securityContext->getAuthenticationTokensOfType(ApiBearerToken::class) as $token) {
            if ($token->isAuthenticated()) {
                return true;
            }
        }

        return false;
    }

    private function getBaseUri(): string
    {
        $uri = $this->request->getHttpRequest()->getUri();
        $baseUri = $uri->getScheme() . '://' . $uri->getHost();
        if ($uri->getPort() !== null && !in_array($uri->getPort(), [80, 443], true)) {
            $baseUri .= ':' . $uri->getPort();
        }

        return $baseUri;
    }
}

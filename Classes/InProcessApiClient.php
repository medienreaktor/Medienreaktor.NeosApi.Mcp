<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Mcp;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\ServerRequestAttributes;
use Neos\Flow\Mvc\ActionRequestFactory;
use Neos\Flow\Mvc\Dispatcher;
use Neos\Flow\Mvc\Routing\Dto\RouteContext;
use Neos\Flow\Mvc\Routing\Dto\RouteParameters;
use Neos\Flow\Mvc\Routing\RouterInterface;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Security\Exception\AccessDeniedException;
use Neos\Flow\Security\Exception\AuthenticationRequiredException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Performs an API operation inside the current request: the operation is
 * routed and dispatched like an incoming HTTP request, on behalf of the
 * already authenticated bearer token. No loopback HTTP, and no second code
 * path next to the REST controllers - the firewall, the controllers' scope
 * checks and the content repository's authorization all apply unchanged.
 */
#[Flow\Scope('singleton')]
class InProcessApiClient
{
    #[Flow\Inject]
    protected RouterInterface $router;

    #[Flow\Inject]
    protected ActionRequestFactory $actionRequestFactory;

    #[Flow\Inject]
    protected Dispatcher $dispatcher;

    #[Flow\Inject]
    protected PackageManager $packageManager;

    /**
     * @param array<string, string> $query
     * @param array<mixed>|null $body
     */
    public function send(ServerRequestInterface $originalRequest, string $method, string $path, array $query, ?array $body): ResponseInterface
    {
        $request = $originalRequest
            ->withMethod($method)
            ->withUri($originalRequest->getUri()->withPath($path)->withQuery(http_build_query($query)))
            ->withQueryParams($query)
            ->withParsedBody($body)
            ->withBody(Utils::streamFor($body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)))
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json, text/html');

        $routeParameters = $originalRequest->getAttribute(ServerRequestAttributes::ROUTING_PARAMETERS) ?? RouteParameters::createEmpty();
        $matchResults = $this->router->route(new RouteContext($request, $routeParameters));
        if (isset($matchResults['@package'])) {
            $matchResults['@package'] = $this->packageManager->getCaseSensitivePackageKey($matchResults['@package']);
        }
        $actionRequest = $this->actionRequestFactory->createActionRequest($request, $matchResults);

        try {
            return $this->dispatcher->dispatch($actionRequest);
        } catch (AuthenticationRequiredException $exception) {
            return $this->errorResponse(401, 'unauthorized', $exception->getMessage());
        } catch (AccessDeniedException|AccessDenied $exception) {
            return $this->errorResponse(403, 'access_denied', $exception->getMessage());
        }
    }

    private function errorResponse(int $statusCode, string $error, string $description): ResponseInterface
    {
        return new Response($statusCode, ['Content-Type' => 'application/json'], json_encode([
            'error' => $error,
            'error_description' => $description,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}

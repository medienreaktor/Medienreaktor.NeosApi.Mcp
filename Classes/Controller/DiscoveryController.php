<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Mcp\Controller;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;

/**
 * Protected resource metadata (RFC 9728) for the MCP endpoint, at the
 * path-suffixed location MCP clients look up first. The authorization server
 * is the Neos API's own.
 */
class DiscoveryController extends ActionController
{
    /**
     * @var array<string>
     */
    protected $supportedMediaTypes = ['application/json'];

    #[Flow\InjectConfiguration(path: 'enabled')]
    protected bool $enabled = false;

    /**
     * @var array<string, mixed>
     */
    #[Flow\InjectConfiguration(package: 'Medienreaktor.NeosApi', path: 'oauth')]
    protected array $oauthSettings;

    public function protectedResourceAction(): string
    {
        if (!$this->enabled) {
            $this->throwStatus(404);
        }

        $baseUri = $this->getBaseUri();
        $issuer = (string)($this->oauthSettings['issuer'] ?? '');

        $this->response->setContentType('application/json');

        return json_encode([
            'resource' => $baseUri . '/mcp',
            'authorization_servers' => [$issuer !== '' ? rtrim($issuer, '/') : $baseUri],
            'scopes_supported' => array_keys((array)($this->oauthSettings['scopes'] ?? [])),
            'bearer_methods_supported' => ['header'],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
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

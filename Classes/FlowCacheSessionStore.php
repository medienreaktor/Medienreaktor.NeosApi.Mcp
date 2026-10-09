<?php

declare(strict_types=1);

namespace Medienreaktor\NeosApi\Mcp;

use Mcp\Server\Session\SessionStoreInterface;
use Neos\Cache\Frontend\FrontendInterface;
use Neos\Flow\Annotations as Flow;
use Symfony\Component\Uid\Uuid;

/**
 * Keeps MCP sessions in a Flow cache. Unlike the SDK's PSR-16 store, a failing
 * backend throws instead of answering false: a lost session would otherwise
 * surface as an empty response with no trace in any log.
 *
 * Expiry is the cache's job (defaultLifetime in Caches.yaml), so there is
 * nothing to collect in gc().
 */
#[Flow\Proxy(false)]
final readonly class FlowCacheSessionStore implements SessionStoreInterface
{
    public function __construct(
        private FrontendInterface $cache,
    ) {
    }

    public function exists(Uuid $id): bool
    {
        return $this->cache->has($this->entryIdentifier($id));
    }

    public function read(Uuid $id): string|false
    {
        $data = $this->cache->get($this->entryIdentifier($id));

        return is_string($data) ? $data : false;
    }

    public function write(Uuid $id, string $data): bool
    {
        $this->cache->set($this->entryIdentifier($id), $data);

        return true;
    }

    public function destroy(Uuid $id): bool
    {
        return $this->cache->remove($this->entryIdentifier($id));
    }

    public function gc(): array
    {
        return [];
    }

    private function entryIdentifier(Uuid $id): string
    {
        return $id->toRfc4122();
    }
}

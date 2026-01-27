<?php

declare(strict_types=1);

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Mcp\Server\Session;

use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Uid\Exception\InvalidArgumentException;
use Symfony\Component\Uid\Uuid;

/**
 * @author luoyue <1569097443@qq.com>
 *
 * PSR-16 compliant cache-based session store.
 *
 * This implementation uses any PSR-16 compliant cache as the storage backend
 * for session data. Each session is stored with a prefixed key using the session ID.
 */
class Psr16StoreSession implements SessionStoreInterface
{
    private const SESSION_IDS_KEY = 'mcp-session-ids';

    public function __construct(
        private readonly CacheInterface $cache,
        private readonly string $prefix = 'mcp-',
        private readonly int $ttl = 3600,
    ) {
    }

    public function exists(Uuid $id): bool
    {
        try {
            return $this->cache->has($this->getKey($id));
        } catch (\Throwable) {
            return false;
        }
    }

    public function read(Uuid $id): string|false
    {
        try {
            return $this->cache->get($this->getKey($id), false);
        } catch (\Throwable) {
            return false;
        }
    }

    public function write(Uuid $id, string $data): bool
    {
        try {
            $result = $this->cache->set($this->getKey($id), $data, $this->ttl);

            if ($result) {
                $this->addSessionId($id);
            }

            return $result;
        } catch (\Throwable) {
            return false;
        }
    }

    public function destroy(Uuid $id): bool
    {
        try {
            $result = $this->cache->delete($this->getKey($id));

            if ($result) {
                $this->removeSessionId($id);
            }

            return $result;
        } catch (\Throwable) {
            return false;
        }
    }

    public function gc(): array
    {
        return [];
    }

    public function getAllSessionIds(): array
    {
        try {
            $sessionIdsData = $this->cache->get(self::SESSION_IDS_KEY, []);

            if (!\is_array($sessionIdsData)) {
                return [];
            }

            $validSessionIds = [];

            foreach ($sessionIdsData as $sessionIdString) {
                try {
                    $uuid = Uuid::fromString($sessionIdString);
                    if ($this->exists($uuid)) {
                        $validSessionIds[] = $uuid;
                    }
                } catch (InvalidArgumentException $e) {
                    // Skip invalid UUIDs
                }
            }

            if (\count($validSessionIds) !== \count($sessionIdsData)) {
                $this->cache->set(
                    self::SESSION_IDS_KEY,
                    array_map(fn (Uuid $id) => $id->toRfc4122(), $validSessionIds),
                    null
                );
            }

            return $validSessionIds;
        } catch (\Throwable) {
            return [];
        }
    }

    private function addSessionId(Uuid $id): void
    {
        try {
            $sessionIds = $this->cache->get(self::SESSION_IDS_KEY, []);

            if (!\is_array($sessionIds)) {
                $sessionIds = [];
            }

            $idString = $id->toRfc4122();
            if (!\in_array($idString, $sessionIds, true)) {
                $sessionIds[] = $idString;
                $this->cache->set(self::SESSION_IDS_KEY, $sessionIds, null);
            }
        } catch (\Throwable) {
            return;
        }
    }

    private function removeSessionId(Uuid $id): void
    {
        try {
            $sessionIds = $this->cache->get(self::SESSION_IDS_KEY, []);

            if (!\is_array($sessionIds)) {
                return;
            }

            $idString = $id->toRfc4122();
            $sessionIds = array_values(array_filter(
                $sessionIds,
                fn ($sid) => $sid !== $idString
            ));

            $this->cache->set(self::SESSION_IDS_KEY, $sessionIds, null);
        } catch (\Throwable) {
            return;
        }
    }

    private function getKey(Uuid $id): string
    {
        return $this->prefix.$id;
    }
}

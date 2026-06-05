<?php

namespace Blesta\Core\Cache;

/**
 * File-based cache adapter wrapping the existing minphp/cache system.
 * Acts as a no-op passthrough since file caching is already handled
 * elsewhere in the application. This adapter exists so that code using
 * CacheFactory always gets a valid adapter even when Redis is unavailable.
 */
class FileCacheAdapter implements CacheAdapterInterface
{
    /**
     * {@inheritdoc}
     */
    public function read(string $key, string $group = 'default')
    {
        // File-based caching for settings is handled by the existing
        // minphp/cache system. This adapter returns false to let callers
        // fall through to the database, matching pre-Redis behavior.
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $key, $value, int $ttl = 0, string $group = 'default'): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key, string $group = 'default'): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteGroup(string $group): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return true;
    }
}

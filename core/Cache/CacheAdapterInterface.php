<?php

namespace Blesta\Core\Cache;

/**
 * Cache adapter interface for pluggable cache backends (file, Redis, etc.)
 */
interface CacheAdapterInterface
{
    /**
     * Read a value from cache
     *
     * @param string $key Cache key
     * @param string $group Optional grouping (subdirectory for file, key prefix for Redis)
     * @return mixed|false Cached value or false if not found/expired
     */
    public function read(string $key, string $group = 'default');

    /**
     * Write a value to cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache (will be serialized)
     * @param int $ttl Time to live in seconds (0 = use default)
     * @param string $group Optional grouping
     * @return bool Success
     */
    public function write(string $key, $value, int $ttl = 0, string $group = 'default'): bool;

    /**
     * Remove a single cache entry
     *
     * @param string $key Cache key
     * @param string $group Optional grouping
     * @return bool Success
     */
    public function delete(string $key, string $group = 'default'): bool;

    /**
     * Remove all entries in a group
     *
     * @param string $group Group to clear
     * @return bool Success
     */
    public function deleteGroup(string $group): bool;

    /**
     * Check if the cache backend is available
     *
     * @return bool
     */
    public function isAvailable(): bool;
}

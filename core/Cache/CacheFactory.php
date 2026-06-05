<?php

namespace Blesta\Core\Cache;

use Configure;

/**
 * Factory that returns the appropriate cache adapter based on configuration.
 * Returns Redis adapter when configured and available, otherwise falls back
 * to the file-based no-op adapter.
 */
class CacheFactory
{
    /**
     * @var CacheAdapterInterface|null Singleton instance
     */
    private static $instance = null;

    /**
     * Get the cache adapter instance
     *
     * @return CacheAdapterInterface
     */
    public static function get(): CacheAdapterInterface
    {
        if (self::$instance === null) {
            $redisConfig = Configure::get('Blesta.redis');

            if ($redisConfig && extension_loaded('redis')) {
                $adapter = new RedisCacheAdapter($redisConfig);
                if ($adapter->isAvailable()) {
                    self::$instance = $adapter;
                }
            }

            // Fallback to file cache (no-op for settings)
            if (self::$instance === null) {
                self::$instance = new FileCacheAdapter();
            }
        }

        return self::$instance;
    }

    /**
     * Reset the singleton instance (useful for testing)
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}

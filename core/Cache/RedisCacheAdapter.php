<?php

namespace Blesta\Core\Cache;

use Configure;

/**
 * Redis-backed cache adapter
 */
class RedisCacheAdapter implements CacheAdapterInterface
{
    /**
     * @var \Redis
     */
    private $redis;

    /**
     * @var string Key prefix
     */
    private $prefix;

    /**
     * @var int Default TTL in seconds
     */
    private $defaultTtl;

    /**
     * @var bool Whether connection is established
     */
    private $connected = false;

    /**
     * @var bool Whether a connection failure has already been logged this request
     */
    private static $failureLogged = false;

    /**
     * @param array $config Redis configuration array with keys:
     *  host, port, password, database, prefix, timeout, read_timeout
     */
    public function __construct(array $config)
    {
        $this->prefix = $config['prefix'] ?? 'blesta:';
        $this->defaultTtl = $this->parseTtl(Configure::get('Blesta.cache_length') ?? '2 hours');

        try {
            $this->redis = new \Redis();
            $this->connected = $this->redis->connect(
                $config['host'] ?? '127.0.0.1',
                (int) ($config['port'] ?? 6379),
                (float) ($config['timeout'] ?? 2.0),
                null,
                0,
                (float) ($config['read_timeout'] ?? 2.0)
            );

            if ($this->connected && !empty($config['password'])) {
                $this->connected = $this->redis->auth($config['password']);
            }

            if ($this->connected && isset($config['database']) && (int) $config['database'] !== 0) {
                $this->connected = $this->redis->select((int) $config['database']);
            }

            if ($this->connected) {
                $this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
                $this->redis->setOption(\Redis::OPT_PREFIX, $this->prefix);
            }
        } catch (\RedisException $e) {
            $this->connected = false;
            $this->logFailure($e->getMessage());
        }
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $key, string $group = 'default')
    {
        if (!$this->connected) {
            return false;
        }

        try {
            $fullKey = $group . ':' . $key;
            $value = $this->redis->get($fullKey);

            return $value; // Returns false if not found (Redis::get behavior)
        } catch (\RedisException $e) {
            $this->handleException($e);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $key, $value, int $ttl = 0, string $group = 'default'): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            $fullKey = $group . ':' . $key;
            $effectiveTtl = $ttl > 0 ? $ttl : $this->defaultTtl;

            // Track key in the group set for group-based invalidation,
            // and set a TTL on the group set so it doesn't leak indefinitely
            $groupSetKey = '_group:' . $group;
            $this->redis->sAdd($groupSetKey, $fullKey);
            $this->redis->expire($groupSetKey, $effectiveTtl);

            return $this->redis->setex($fullKey, $effectiveTtl, $value);
        } catch (\RedisException $e) {
            $this->handleException($e);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key, string $group = 'default'): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            $fullKey = $group . ':' . $key;
            $this->redis->sRem('_group:' . $group, $fullKey);

            return (bool) $this->redis->del($fullKey);
        } catch (\RedisException $e) {
            $this->handleException($e);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteGroup(string $group): bool
    {
        if (!$this->connected) {
            return false;
        }

        try {
            $groupSetKey = '_group:' . $group;
            $members = $this->redis->sMembers($groupSetKey);

            if (!empty($members)) {
                // Delete all keys in the group
                // Keys already have the prefix via OPT_PREFIX, but sMembers returns
                // them without the prefix since they were stored as logical keys.
                $this->redis->del($members);
            }

            // Delete the group tracking set itself
            $this->redis->del($groupSetKey);

            return true;
        } catch (\RedisException $e) {
            $this->handleException($e);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return $this->connected;
    }

    /**
     * Parse a human-readable TTL string into seconds
     *
     * @param string $ttlString e.g. "2 hours", "30 minutes"
     * @return int Seconds
     */
    private function parseTtl(string $ttlString): int
    {
        $seconds = strtotime('+' . $ttlString, 0);

        return $seconds !== false ? $seconds : 7200; // Default 2 hours
    }

    /**
     * Log a connection failure (once per request)
     *
     * @param string $message
     */
    private function logFailure(string $message): void
    {
        if (!self::$failureLogged) {
            self::$failureLogged = true;
            error_log('Blesta Redis cache: connection failed - ' . $message);
        }
    }

    /**
     * Handle a Redis exception during operation
     *
     * @param \RedisException $e
     */
    private function handleException(\RedisException $e): void
    {
        $this->connected = false;
        $this->logFailure($e->getMessage());
    }
}

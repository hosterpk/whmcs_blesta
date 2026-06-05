<?php

namespace Blesta\Core\Database;

/**
 * Logs database queries for debugging and performance analysis
 *
 * @package blesta
 * @subpackage core.Database.Record
 * @copyright Copyright (c) 2026, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class QueryLogger
{
    /**
     * @var array Logged queries
     */
    private static array $queries = [];

    /**
     * Logs a database query
     *
     * @param string $query The SQL query
     * @param array $bindings Query bindings
     * @param float|int $time Execution time in milliseconds
     */
    public static function log(string $query, array $bindings, float|int $time): void
    {
        self::$queries[] = [
            'query' => $query,
            'bindings' => $bindings,
            'time' => round($time, 2),
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
        ];
    }

    /**
     * Retrieves all logged queries
     *
     * @return array Array of logged queries with query, bindings, time, and backtrace
     */
    public static function getQueries(): array
    {
        return self::$queries;
    }

    /**
     * Calculates the total execution time of all logged queries
     *
     * @return float|int Total time in milliseconds
     */
    public static function getTotalTime(): float|int
    {
        return array_sum(array_column(self::$queries, 'time'));
    }

    /**
     * Retrieves queries exceeding the execution time threshold
     *
     * @param int $threshold Minimum execution time in milliseconds (default 50)
     * @return array Array of slow queries
     */
    public static function getSlowQueries(int $threshold = 50): array
    {
        return array_filter(self::$queries, function ($q) use ($threshold) {
            return $q['time'] > $threshold;
        });
    }

    /**
     * Clears all logged queries
     */
    public static function clear(): void
    {
        self::$queries = [];
    }
}

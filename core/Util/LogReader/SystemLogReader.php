<?php

namespace Blesta\Core\Util\LogReader;

/**
 * Reads and parses Monolog-formatted log files from the system log directory
 *
 * @package blesta
 * @subpackage core.Util.LogReader
 * @copyright Copyright (c) 2025, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class SystemLogReader
{
    /**
     * @var string The log directory path
     */
    private $logDir;

    /**
     * @var int Results per page
     */
    private $perPage;

    /**
     * @var array|null Cached entries from the last readFilteredEntries call
     */
    private $cachedEntries;

    /**
     * @var string|null The cache key for the last readFilteredEntries call
     */
    private $cachedKey;

    /**
     * Monolog severity levels in descending order of severity
     */
    private const LEVELS = [
        'emergency' => 600,
        'alert' => 550,
        'critical' => 500,
        'error' => 400,
        'warning' => 300,
        'notice' => 250,
        'info' => 200,
        'debug' => 100
    ];

    /**
     * Creates a new SystemLogReader
     *
     * @param string $logDir The full path to the log directory
     * @param int $perPage The number of results per page (default 25)
     */
    public function __construct(string $logDir, int $perPage = 25)
    {
        $this->logDir = rtrim($logDir, '/\\');
        $this->perPage = $perPage;
    }

    /**
     * Gets a paginated list of log entries matching the given filters
     *
     * @param int $page The page number (1-indexed)
     * @param array $filters An array of filters:
     *  - levels: An array of severity levels to include (default ['emergency','alert','critical','error'])
     *  - source: The log source ('all', 'app', 'cron'; default 'all')
     *  - string: A text string to search for in log messages
     *  - start_date: The start date filter (Y-m-d format)
     *  - end_date: The end date filter (Y-m-d format)
     * @return array A list of log entry objects
     */
    public function getEntries(int $page = 1, array $filters = []): array
    {
        $entries = $this->readFilteredEntries($filters);

        // Sort by date descending (newest first)
        usort($entries, function ($a, $b) {
            return strcmp($b->date, $a->date);
        });

        // Paginate
        $offset = max(0, ($page - 1) * $this->perPage);

        return array_slice($entries, $offset, $this->perPage);
    }

    /**
     * Gets the total count of log entries matching the given filters
     *
     * @param array $filters Same filters as getEntries()
     * @return int The total number of matching entries
     */
    public function getEntryCount(array $filters = []): int
    {
        return count($this->readFilteredEntries($filters));
    }

    /**
     * Gets a list of available severity levels
     *
     * @return array An array of level name => numeric value pairs
     */
    public static function getLevels(): array
    {
        return self::LEVELS;
    }

    /**
     * Formats a log message for readable display.
     *
     * Monolog's LineFormatter with allowInlineLineBreaks=false replaces newlines
     * with spaces, making stack traces and print_r output unreadable. This method
     * restores structure by re-inserting line breaks at known patterns.
     *
     * @param string $message The raw log message
     * @return string The formatted message with restored line breaks
     */
    public static function formatMessage(string $message): string
    {
        // Restore line break before "Stack trace:"
        $message = preg_replace('/\s+(Stack trace:)/', "\n\n$1", $message);

        // Restore line break before each stack frame: #0, #1, #2, etc.
        $message = preg_replace('/\s+(#\d+\s)/', "\n$1", $message);

        // Restore line break before " in /path/to/file.php:123" (end of exception message)
        $message = preg_replace('/ in ((?:[A-Z]:)?[\/\\\\][^\s:]+:\d+)/', "\n  in $1", $message);

        // Add space before "Array" if no space exists (e.g., "textArray (" => "text Array (")
        $message = preg_replace('/(\S)(Array\s*\()/', '$1 $2', $message);

        // Restore print_r Array structure:
        // "Array ( " => "Array (\n"
        $message = preg_replace('/Array\s*\(\s{2,}/', "Array (\n", $message);

        // Restore line breaks before "[key] =>" patterns (print_r output)
        // Match sequences like "     [0] =>" or "     [file] =>"
        $message = preg_replace('/\s{2,}(\[\w+\]\s*=>)/', "\n    $1", $message);

        // Restore closing parentheses on their own lines
        // Match "  )  )" or similar patterns from print_r
        $message = preg_replace('/\s{2,}\)/', "\n)", $message);

        return $message;
    }

    /**
     * Reads and filters log entries from files
     *
     * @param array $filters The filters to apply
     * @return array An array of log entry objects
     */
    private function readFilteredEntries(array $filters): array
    {
        // Return cached results if filters haven't changed
        $cacheKey = serialize($filters);
        if ($this->cachedKey === $cacheKey && $this->cachedEntries !== null) {
            return $this->cachedEntries;
        }

        if (empty($this->logDir) || !is_dir($this->logDir) || !is_readable($this->logDir)) {
            return [];
        }

        $includeLevels = $filters['levels'] ?? ['emergency', 'alert', 'critical', 'error'];
        $source = $filters['source'] ?? 'all';
        $searchString = $filters['string'] ?? '';
        $startDate = $filters['start_date'] ?? '';
        $endDate = $filters['end_date'] ?? '';

        // Validate levels against known levels
        $includeLevels = array_intersect($includeLevels, array_keys(self::LEVELS));
        if (empty($includeLevels)) {
            return [];
        }

        // Find matching log files
        $files = $this->findLogFiles($includeLevels, $source, $startDate, $endDate);

        // Read and parse entries from all matching files
        $entries = [];
        foreach ($files as $file) {
            $fileEntries = $this->parseLogFile($file, $searchString, $startDate, $endDate);
            $entries = array_merge($entries, $fileEntries);
        }

        // Cache results for subsequent calls with the same filters
        $this->cachedKey = $cacheKey;
        $this->cachedEntries = $entries;

        return $entries;
    }

    /**
     * Finds log files matching the given filters
     *
     * @param array $levels The severity levels to include
     * @param string $source The source filter ('all', 'app', 'cron')
     * @param string $startDate The start date (Y-m-d) or empty
     * @param string $endDate The end date (Y-m-d) or empty
     * @return array A list of matching file paths
     */
    private function findLogFiles(array $levels, string $source, string $startDate, string $endDate): array
    {
        $files = [];
        $dirHandle = opendir($this->logDir);
        if ($dirHandle === false) {
            return [];
        }

        while (($filename = readdir($dirHandle)) !== false) {
            if ($filename === '.' || $filename === '..') {
                continue;
            }

            // Match Monolog RotatingFileHandler pattern: general-{level}[-cron]-{Y-m-d}.log
            if (
                !preg_match(
                    '/^general-(' . implode('|', array_keys(self::LEVELS)) . ')(-cron)?-(\d{4}-\d{2}-\d{2})\.log$/',
                    $filename,
                    $matches
                )
            ) {
                continue;
            }

            $fileLevel = $matches[1];
            $isCron = !empty($matches[2]);
            $fileDate = $matches[3];

            // Filter by severity level
            if (!in_array($fileLevel, $levels)) {
                continue;
            }

            // Filter by source
            if ($source === 'app' && $isCron) {
                continue;
            }
            if ($source === 'cron' && !$isCron) {
                continue;
            }

            // Filter by date range (based on filename date)
            if (!empty($startDate) && $fileDate < $startDate) {
                continue;
            }
            if (!empty($endDate) && $fileDate > $endDate) {
                continue;
            }

            $files[] = $this->logDir . DIRECTORY_SEPARATOR . $filename;
        }
        closedir($dirHandle);

        return $files;
    }

    /**
     * Parses a single log file and returns matching entries
     *
     * @param string $filePath The path to the log file
     * @param string $searchString A text string to filter by (empty = no filter)
     * @param string $startDate The start date filter (Y-m-d) or empty
     * @param string $endDate The end date filter (Y-m-d) or empty
     * @return array A list of log entry objects
     */
    private function parseLogFile(string $filePath, string $searchString, string $startDate, string $endDate): array
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            return [];
        }

        $entries = [];
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return [];
        }

        $currentEntry = null;

        while (($line = fgets($handle)) !== false) {
            $parsed = $this->parseLine($line);

            if ($parsed !== null) {
                // Save previous entry if it passes filters
                if ($currentEntry !== null) {
                    $this->addEntryIfMatches($currentEntry, $entries, $searchString, $startDate, $endDate);
                }
                $currentEntry = $parsed;
            } elseif ($currentEntry !== null) {
                // Multi-line continuation (e.g., stack trace)
                $currentEntry->message .= "\n" . rtrim($line, "\r\n");
            }
        }

        // Don't forget the last entry
        if ($currentEntry !== null) {
            $this->addEntryIfMatches($currentEntry, $entries, $searchString, $startDate, $endDate);
        }

        fclose($handle);

        return $entries;
    }

    /**
     * Adds an entry to the results array if it matches the text and date filters
     *
     * @param object $entry The log entry
     * @param array $entries The results array (passed by reference)
     * @param string $searchString The text search filter
     * @param string $startDate The start date filter
     * @param string $endDate The end date filter
     */
    private function addEntryIfMatches(
        object $entry,
        array &$entries,
        string $searchString,
        string $startDate,
        string $endDate
    ): void {
        // Filter by exact date/time range (more precise than filename-based filtering)
        $entryDate = substr($entry->date, 0, 10);
        if (!empty($startDate) && $entryDate < $startDate) {
            return;
        }
        if (!empty($endDate) && $entryDate > $endDate) {
            return;
        }

        // Filter by text search (case-insensitive)
        if (!empty($searchString) && stripos($entry->message, $searchString) === false) {
            return;
        }

        $entries[] = $entry;
    }

    /**
     * Parses a single Monolog log line
     *
     * Monolog LineFormatter default format:
     * [2026-03-24T10:15:30.123456+00:00] general.ERROR: The message {"context":"data"} []
     *
     * @param string $line The log line
     * @return object|null A parsed entry object or null if the line is not a valid log entry start
     */
    private function parseLine(string $line): ?object
    {
        // Match Monolog default format
        if (
            !preg_match(
                '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\]]*)\]\s+\w+\.(\w+):\s+(.*)/s',
                $line,
                $matches
            )
        ) {
            return null;
        }

        $date = $matches[1];
        $level = strtolower($matches[2]);
        $message = rtrim($matches[3], "\r\n");

        return (object) [
            'date' => $date,
            'level' => $level,
            'message' => $message
        ];
    }
}

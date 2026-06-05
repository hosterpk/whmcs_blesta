<?php

namespace Blesta\Core\Util\AI;

/**
 * AI Response Parser
 *
 * Parses structured JSON responses from AI models with multi-level fallback.
 * Handles common issues like markdown code fence wrapping, malformed JSON,
 * and missing fields.
 *
 * @package blesta
 * @subpackage blesta.core.Util.AI
 * @copyright Copyright (c) 2024, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AiResponseParser
{
    /**
     * Parse a structured JSON AI response against an expected schema.
     *
     * Uses a multi-level fallback strategy:
     * 1. Extract JSON from markdown code fences if present
     * 2. Find JSON object in content
     * 3. json_decode the candidate
     * 4. Regex-based field extraction if decode fails
     * 5. Raw content fallback
     *
     * @param string $rawContent The raw AI response string
     * @param array $expectedFields List of field names expected in the JSON
     *   e.g. ['feedback', 'description'] or ['subject', 'html', 'text', 'feedback']
     * @return array Associative array with all $expectedFields as keys (null if missing).
     *   May also include '_parse_method' for diagnostics and 'raw' for fallback content.
     */
    public function parse(string $rawContent, array $expectedFields): array
    {
        if (empty($rawContent)) {
            return $this->buildEmptyResult($expectedFields);
        }

        // Initialize result with all expected fields set to null
        $result = array_fill_keys($expectedFields, null);

        // Strategy 1: Try JSON decode (with code fence extraction)
        $jsonCandidate = $this->extractJsonFromFences($rawContent);
        $parsed = json_decode($jsonCandidate, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($parsed)) {
            foreach ($expectedFields as $field) {
                if (isset($parsed[$field])) {
                    // Coerce non-string values to null
                    $result[$field] = is_string($parsed[$field]) ? $parsed[$field] : null;
                }
            }
            $result['_parse_method'] = 'json';
            return $result;
        }

        // Strategy 2: Regex-based field extraction
        $regexResult = $this->extractFieldsViaRegex($rawContent, $expectedFields);
        if ($regexResult !== null) {
            foreach ($expectedFields as $field) {
                if (isset($regexResult[$field])) {
                    $result[$field] = is_string($regexResult[$field]) ? $regexResult[$field] : null;
                }
            }
            $result['_parse_method'] = 'regex';
            return $result;
        }

        // Strategy 3: Raw fallback
        // For single-field schemas (besides 'feedback'), map raw to that field
        // For multi-field schemas, put raw in 'raw' key only
        $contentFields = array_diff($expectedFields, ['feedback']);

        if (count($contentFields) === 1) {
            $targetField = reset($contentFields);
            $result[$targetField] = $rawContent;
            $result['feedback'] = 'AI response could not be parsed cleanly. Showing raw content — please review and edit as needed.';
        } else {
            $result['raw'] = $rawContent;
            $result['feedback'] = 'AI response could not be parsed cleanly. Please try regenerating.';
        }

        $result['_parse_method'] = 'raw_fallback';
        return $result;
    }

    /**
     * Extract JSON from content that may be wrapped in markdown code fences.
     *
     * Tries multiple extraction strategies:
     * 1. ```json { ... } ``` — standard fenced JSON
     * 2. { ... } — bare JSON object anywhere in content
     *
     * @param string $content Raw content
     * @return string Cleaned JSON string (may still be invalid)
     */
    private function extractJsonFromFences(string $content): string
    {
        // Try markdown code block: ```json { ... } ```
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*\})\s*```/', $content, $matches)) {
            return $matches[1];
        }

        // Try to find a JSON object anywhere in the content
        if (preg_match('/(\{[\s\S]*\})/', $content, $matches)) {
            return $matches[1];
        }

        return $content;
    }

    /**
     * Attempt regex-based field extraction when JSON decode fails.
     *
     * Extracts individual field values using "field": "value" patterns.
     * Handles escaped characters within string values.
     *
     * @param string $content Raw content
     * @param array $fields Expected field names
     * @return array|null Extracted fields or null if no fields found
     */
    private function extractFieldsViaRegex(string $content, array $fields): ?array
    {
        $extracted = [];
        $found = false;

        foreach ($fields as $field) {
            if (preg_match('/"' . preg_quote($field, '/') . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/s', $content, $matches)) {
                $extracted[$field] = $this->unescapeJsonString($matches[1]);
                $found = true;
            }
        }

        return $found ? $extracted : null;
    }

    /**
     * Unescape a JSON string value extracted via regex.
     *
     * Handles common JSON escape sequences that appear in string values.
     *
     * @param string $str The escaped string from JSON
     * @return string The unescaped string
     */
    private function unescapeJsonString(string $str): string
    {
        $replacements = [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\"' => '"',
            '\\\\' => '\\'
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $str);
    }

    /**
     * Build an empty result with all expected fields set to null.
     *
     * @param array $expectedFields List of field names
     * @return array Result array
     */
    private function buildEmptyResult(array $expectedFields): array
    {
        $result = array_fill_keys($expectedFields, null);
        $result['_parse_method'] = 'empty';
        return $result;
    }
}

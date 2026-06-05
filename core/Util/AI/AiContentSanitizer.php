<?php

namespace Blesta\Core\Util\AI;

/**
 * AI Content Sanitizer
 *
 * Shared sanitization helpers for AI-generated content. Handles code fence
 * removal, plain text cleanup, CKEditor template tag decoding, and HTML
 * purification. Callers supply the allowed-tag and allowed-CSS policy so
 * each context (email template vs. package description) can keep its own
 * permissions while sharing the purifier wiring.
 *
 * @package blesta
 * @subpackage blesta.core.Util.AI
 * @copyright Copyright (c) 2024, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class AiContentSanitizer
{
    /**
     * Strip markdown code fence markers from content.
     *
     * Removes standalone ``` lines (with optional language tag) anywhere
     * in the content. Does not affect the content between fences.
     *
     * @param string $content Content that may contain code fences
     * @return string Content with fence markers removed
     */
    public function stripCodeFences(string $content): string
    {
        return preg_replace('/^```[a-z]*\s*$\n?/im', '', $content);
    }

    /**
     * Extract content from inside a markdown code fence block.
     *
     * If the content contains a fenced block (```...```), returns only the
     * inner content, discarding any commentary outside the fences (e.g.,
     * preamble text or "Key improvements:" summaries).
     *
     * If no fenced block is found, returns the content as-is.
     *
     * @param string $content Content that may be wrapped in code fences
     * @return string Extracted inner content or original content
     */
    public function extractFromCodeFences(string $content): string
    {
        if (preg_match('/```[a-z]*\s*\n([\s\S]*?)\n\s*```/i', $content, $matches)) {
            return $matches[1];
        }

        return $content;
    }

    /**
     * Sanitize plain text content from AI.
     *
     * Extracts from code fences (discarding commentary), strips remaining
     * fence markers, removes any HTML tags, and trims whitespace.
     *
     * @param string $text Raw text from AI
     * @return string Clean plain text
     */
    public function sanitizeText(string $text): string
    {
        if (empty($text)) {
            return '';
        }

        $text = $this->extractFromCodeFences($text);
        $text = $this->stripCodeFences($text);
        $text = strip_tags($text);
        $text = $this->unescapeTemplateTags($text);

        return trim($text);
    }

    /**
     * Remove Markdown backslash escapes from within template tags.
     *
     * AI models often escape underscores and other characters inside {tags}
     * when generating Markdown/text content (e.g., {service.cpanel\_domain}
     * instead of {service.cpanel_domain}). This breaks template tag resolution.
     *
     * @param string $content Content with potentially escaped template tags
     * @return string Content with clean template tags
     */
    public function unescapeTemplateTags(string $content): string
    {
        if (empty($content)) {
            return '';
        }

        // Remove backslash escapes within {tag} patterns
        return preg_replace_callback('/\{([^}]+)\}/', function ($m) {
            return '{' . str_replace('\\', '', $m[1]) . '}';
        }, $content);
    }

    /**
     * Decode URL-encoded template tags in href attributes.
     *
     * CKEditor's link model percent-encodes { and } as %7B and %7D inside
     * href attribute values. This method decodes them back so that H2O
     * template tags like {verification_url} are preserved correctly.
     *
     * Only operates on href="..." attributes to avoid corrupting legitimate
     * percent-encoded URLs elsewhere in the HTML.
     *
     * @param string $html HTML content with potentially encoded template tags
     * @return string HTML with decoded template tags in href attributes
     */
    public function decodeHrefTemplateTags(string $html): string
    {
        if (empty($html)) {
            return '';
        }

        return preg_replace_callback(
            '/href\s*=\s*"([^"]*?)"/i',
            function ($m) {
                return 'href="' . str_replace(['%7B', '%7D'], ['{', '}'], $m[1]) . '"';
            },
            $html
        );
    }

    /**
     * Sanitize HTML through HTMLPurifier with a caller-supplied policy.
     *
     * Centralizes the purifier wiring (autoload, cache directory, common
     * AutoFormat flags) so each caller declares only its tag/CSS allowlist
     * and any context-specific overrides.
     *
     * @param string $html Raw HTML to sanitize
     * @param array $allowedHtml Entries for HTMLPurifier "HTML.Allowed"
     *   (e.g. ['p', 'a[href|title|target]', 'ul', 'li'])
     * @param array $allowedCss Entries for "CSS.AllowedProperties"
     *   (e.g. ['color', 'font-size', 'padding'])
     * @param array $extraConfig Additional HTMLPurifier config key/value
     *   pairs to set after the defaults (e.g. ['HTML.Nofollow' => false])
     * @return string Purified, trimmed HTML
     */
    public function purifyHtml(
        string $html,
        array $allowedHtml,
        array $allowedCss,
        array $extraConfig = []
    ): string {
        if ($html === '') {
            return '';
        }

        if (!class_exists('HTMLPurifier_Config')) {
            require_once VENDORDIR . 'ezyang' . DS . 'htmlpurifier' . DS . 'library' . DS . 'HTMLPurifier.auto.php';
        }

        $config = \HTMLPurifier_Config::createDefault();

        $cacheDir = CACHEDIR . 'htmlpurifier';
        if (!file_exists($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }
        $config->set('Cache.SerializerPath', $cacheDir);
        $config->set('HTML.Allowed', implode(',', $allowedHtml));
        $config->set('CSS.AllowedProperties', implode(',', $allowedCss));
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('AutoFormat.RemoveEmpty.RemoveNbsp', true);
        $config->set('AutoFormat.AutoParagraph', false);
        $config->set('Output.TidyFormat', false);

        foreach ($extraConfig as $key => $value) {
            $config->set($key, $value);
        }

        return trim((new \HTMLPurifier($config))->purify($html));
    }
}

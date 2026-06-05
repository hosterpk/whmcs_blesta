<?php

namespace Blesta\Core\Util\Validate;

use Configure;

class Server
{
    /**
     * Determines whether the given domain is a valid domain name.
     * NOTE: This does not check IDN/punycode/UTF-8 domains
     *
     * @param string $domain The domain or subdomain to check (e.g. "domain.com", "sub.domain.com")
     * @return bool True if the $domain is valid, false otherwise
     */
    public function isDomain($domain)
    {
        // Domain may not exceed 255 characters in length
        if (strlen($domain) > 255) {
            return false;
        }

        return (bool)preg_match('/^((?!-)[a-z0-9-]{1,63}(?<!-)\.)+[a-z]{2,}$/i', $domain);
    }

    /**
     * Determines whether the given IP is a valid IP address
     *
     * @param string $ip The IP address to validate
     * @return bool True if the IP address is valid, false otherwise
     */
    public function isIp($ip)
    {
        return (bool)filter_var($ip, FILTER_VALIDATE_IP);
    }

    /**
     * Determines whether the given URL is valid
     *required
     * @param string $url The URL to validate (protocol not required, e.g. "http://")
     * @return bool True if the URL is valid, false otherwise
     */
    public function isUrl($url)
    {
        // Add a protocol to the beginning of the URL to pass validation
        $parts = parse_url($url);
        if ($parts && !isset($parts['scheme']) && substr($url, 0, 2) !== '//') {
            $url = 'http://' . $url;
        }

        return (bool)filter_var($url, FILTER_VALIDATE_URL);
    }

    /**
     * Validates that a URL is safe to use (e.g., as a form action or redirect target).
     *
     * Only allows:
     * - Relative URLs starting with / (e.g., /client/services/delete/1/)
     * - Absolute URLs with http/https scheme matching the trusted hostname
     *
     * @param string|null $url The URL to validate
     * @param string|null $trustedHostname The trusted hostname to validate against.
     *     If null, uses Configure::get('Blesta.company')->hostname
     * @return string|null The validated URL, or null if invalid
     */
    public static function validateSafeUrl($url, $trustedHostname = null)
    {
        if ($url === null || $url === '') {
            return null;
        }

        // Normalize: trim whitespace and decode HTML entities
        $url = trim(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));

        // Remove null bytes and control characters
        $url = preg_replace('/[\x00-\x1f\x7f]/', '', $url);

        // Allow relative URLs starting with / (but not protocol-relative //)
        if (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
            return $url;
        }

        // For absolute URLs, parse and validate
        $parsedUrl = parse_url($url);
        if ($parsedUrl === false) {
            return null;
        }

        // Only allow http and https schemes
        $allowedSchemes = ['http', 'https'];
        if (!isset($parsedUrl['scheme']) || !in_array(strtolower($parsedUrl['scheme']), $allowedSchemes, true)) {
            return null;
        }

        // Must have a host
        if (!isset($parsedUrl['host'])) {
            return null;
        }

        // Get trusted hostname from Configure if not provided
        if ($trustedHostname === null) {
            $company = Configure::get('Blesta.company');
            $trustedHostname = $company->hostname ?? null;
        }

        if ($trustedHostname === null) {
            return null;
        }

        // Compare hosts (case-insensitive)
        if (strcasecmp($parsedUrl['host'], $trustedHostname) !== 0) {
            return null;
        }

        return $url;
    }
}

<?php

namespace Webnic;

/**
 * Pure helpers for the order-input surface (WN-3-4b): the pre-submission gate's decision
 * logic plus the per-TLD field-key encoding/labeling.
 *
 * Extracted off the Webnic god-class (retro T4: collaborators live in lib/) so the gate
 * logic — nameserver counting, required-field presence, the AC4 unknown-key label fallback,
 * and the collision-free form-token encoding — is CI-testable WITHOUT the license-gated
 * Blesta runtime. No Blesta deps and no clock; mirrors the WebnicStatus/TldFieldset/ContactsMap
 * pure-class precedent.
 */
class OrderInput
{
    /**
     * Counts the NON-BLANK nameservers (ns1..ns5) in the provisioning input.
     *
     * A whitespace-only value is not a nameserver — resolveNameservers() trims it away — so
     * the gate must trim too, or its min-2 decision diverges from the effective NS set.
     *
     * @param array $vars The provisioning input
     * @return int The number of non-blank ns1..ns5 entries (0..5)
     */
    public static function countSuppliedNs(array $vars): int
    {
        $count = 0;
        for ($i = 1; $i <= 5; $i++) {
            $ns = $vars['ns' . $i] ?? null;
            if (is_scalar($ns) && trim((string) $ns) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Returns the form tokens of every REQUIRED per-TLD descriptor missing a value (AC3/AC4).
     *
     * Tickboxes are inherently optional (unchecked = absent); a required text/select must
     * carry a non-blank value. The submitted map is keyed by the same safe token the renderer
     * used (safeFieldToken), so the lookup matches the rendered field name and the caller can
     * bind the error to tld_req[<token>] (UX-DR16).
     *
     * @param array $descriptors The surfaced descriptors (from TldFieldset::build)
     * @param array $submitted The submitted tld_req map (keyed by form token)
     * @return string[] The safe tokens of the missing required fields
     */
    public static function findMissingRequired(array $descriptors, array $submitted): array
    {
        $missing = [];
        foreach ($descriptors as $descriptor) {
            if (empty($descriptor['required']) || ($descriptor['field_type'] ?? '') === 'tickbox') {
                continue;
            }
            $token = self::safeFieldToken((string) ($descriptor['name'] ?? ''));
            $value = $submitted[$token] ?? null;
            $is_blank = $value === null
                || (is_scalar($value) && trim((string) $value) === '')
                || (is_array($value) && count($value) === 0);
            if ($is_blank) {
                $missing[] = $token;
            }
        }

        return $missing;
    }

    /**
     * Encodes an arbitrary key into a collision-free, form-safe, reversible token (P7).
     *
     * Field names/ids and POST array keys must be [A-Za-z0-9_]-safe and injective: an unknown
     * WebNIC rule key (AC4) or an IDN TLD can carry brackets, quotes, dots, or non-ASCII bytes
     * that reshape the POST body or collapse two keys onto one id. Already-safe keys stay
     * readable ('s_'); anything else is hex-encoded ('h_' + bin2hex). The two disjoint prefixes
     * make the mapping reversible (strip 's_', or hex2bin after 'h_'), so the raw key is always
     * recoverable for labels/validation/persistence.
     *
     * @param string $key The raw key (a rule key or a TLD)
     * @return string A safe, injective token usable as a field name/id and POST array key
     */
    public static function safeFieldToken(string $key): string
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $key) === 1
            ? 's_' . $key
            : 'h_' . bin2hex($key);
    }

    /**
     * Humanizes a camelCase/underscored WebNIC rule key into a readable label (AC4 fallback).
     *
     * @param string $key The raw rule key (e.g. "registrantType")
     * @return string A spaced, title-cased label (e.g. "Registrant Type")
     */
    public static function humanizeRuleKey(string $key): string
    {
        $spaced = preg_replace('/(?<!^)([A-Z])/', ' $1', $key);

        return ucwords(str_replace(['_', '-'], ' ', $spaced));
    }
}

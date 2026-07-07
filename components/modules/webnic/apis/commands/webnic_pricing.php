<?php
/**
 * WebNIC pricing & catalogue command group.
 *
 * Home for the WebNIC `/domain/v2/exts*` family: the sellable extensions list
 * (this story), plus extension pricing (WN-2-2) and extension rules (WN-2-6),
 * which later stories add to this same class. Mirrors the logicboxes command-group
 * structure (INV-2): a thin wrapper that translates a WebNIC op into a single
 * WebnicApi::submit() call, never reading raw JSON itself (that is WebnicResponse's
 * job). The pure mappers below are I/O-free so they are unit-testable with no Blesta.
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class WebnicPricing
{
    /**
     * @var WebnicApi
     */
    private $api;

    /**
     * Sets the API to use for communication.
     *
     * @param WebnicApi $api The API to use for communication
     */
    public function __construct(WebnicApi $api)
    {
        $this->api = $api;
    }

    /**
     * Retrieves the sellable extension (TLD) catalogue.
     *
     * WebNIC op: Get Domain Extensions (GET /domain/v2/exts). No request params;
     * bearer auth is handled by WebnicApi/TokenStore. The path is given without a
     * leading slash to match the existing submit() convention (WebnicApi::buildUrl
     * prepends the env base URL).
     *
     * @return WebnicResponse Normalized WebNIC response whose data() is a flat
     *  array of dotless lowercase extension strings (incl. native-script IDNs)
     */
    public function getDomainExtensions()
    {
        return $this->api->submit('domain/v2/exts', [], 'GET');
    }

    /**
     * Retrieves reseller-cost extension pricing for the given actions.
     *
     * WebNIC op: Get Extension Price (POST /domain/v2/exts/pricing). The body is a
     * filter list (>=1 required) plus pagination; WebnicApi::submit() JSON-encodes
     * the args for POST. The `transtype` filter is ALWAYS present — it alone
     * satisfies the ">=1 filter" rule, which is how the Domain Manager path fetches
     * the whole catalogue (empty $tlds) and filters locally. A `productKey` filter
     * is added only when $tlds is non-empty; each value is stripped of its leading
     * dot and ASCII-lowercased (the inverse of normalizeExtensions) so Blesta's
     * `.com` becomes WebNIC's `com` — WebNIC matches nothing on a dotted `.com`.
     *
     * @param array $tlds Blesta `.tld` strings to scope by, or [] to fetch all
     * @param array $transtypes WebNIC action keys (register/renewal/transfer/...)
     * @param int $page 1-based page number
     * @param int $pageSize Page size
     * @return WebnicResponse Normalized WebNIC response whose data() is the paged
     *  object {pageSize,totalPages,totalItems,items:[...]}
     */
    public function getExtensionPrice(array $tlds, array $transtypes, int $page, int $pageSize): WebnicResponse
    {
        $filters = [];

        // productKey filter first (apidoc order), only when TLDs are requested.
        $scoped_tlds = !empty($tlds);
        if (!empty($tlds)) {
            $keys = [];
            foreach ($tlds as $tld) {
                if (!is_string($tld)) {
                    continue;
                }

                // Inverse of normalizeExtensions: drop the leading dot, ASCII-lowercase
                // only (so native-script IDNs pass through intact).
                $key = ltrim(trim($tld), '.');
                $key = mb_check_encoding($key, 'ASCII') ? strtolower($key) : $key;
                if ($key !== '') {
                    $keys[] = $key;
                }
            }

            if (!empty($keys)) {
                $filters[] = ['field' => 'productKey', 'value' => implode(',', $keys)];
            }
        }

        if ($scoped_tlds && empty($keys)) {
            return new WebnicResponse(
                ['code' => 'invalid_filter', 'error' => ['subCode' => 'invalid_filter']],
                400
            );
        }

        // transtype is always present — the lone filter that satisfies the ">=1" rule.
        $filters[] = ['field' => 'transtype', 'value' => implode(',', $transtypes)];

        return $this->api->submit(
            'domain/v2/exts/pricing',
            [
                'filters' => $filters,
                'pagination' => ['page' => $page, 'pageSize' => $pageSize],
            ],
            'POST'
        );
    }

    /**
     * Retrieves WebNIC extension rules for a registration or transfer flow.
     *
     * WebNIC op: Get Extensions Rule (GET /domain/v2/ext-rules). The ext value is
     * accepted in WebNIC's dotless shape (`my`, `hk`); callers normalize Blesta's
     * dotted TLD before reaching this command. Supported ruleType values for this
     * story are `registration` (RG) and `rertransfer` (TF).
     *
     * @param string $ext Dotless extension key
     * @param string $ruleType WebNIC rule type (`registration` or `rertransfer`)
     * @return WebnicResponse Normalized WebNIC response whose data() carries
     *  extension rules
     */
    public function getExtensionRule($ext, $ruleType): WebnicResponse
    {
        return $this->api->submit('domain/v2/ext-rules', ['ext' => $ext, 'ruleType' => $ruleType], 'GET');
    }

    /**
     * Decides whether a requested registration/transfer term is valid.
     *
     * Pure helper for Webnic::isValidTerm(). WebNIC RG rules may carry
     * data.rules.terms as year strings; TF rules do not, so missing/unavailable
     * terms fall back to Blesta's 1..10 default bound. A listed RG terms array is
     * authoritative when present.
     *
     * @param WebnicResponse|null $resp Final response, or null when no fetch was possible
     * @param mixed $term Requested term
     * @param bool $transfer True for transfer validation
     * @return array Decision: [
     *  'valid' => bool,
     *  'source' => string,      // rule|default
     *  'log_failure' => bool
     * ]
     */
    public static function decideTermValidity(?WebnicResponse $resp, $term, bool $transfer): array
    {
        $term = (int) $term;
        if ($term < 1 || $term > 10) {
            return [
                'valid' => false,
                'source' => 'default',
                'log_failure' => false,
            ];
        }

        if ($resp === null) {
            return [
                'valid' => true,
                'source' => 'default',
                'log_failure' => false,
            ];
        }

        if ($resp->success()) {
            $data = $resp->data();
            $rules = is_array($data) ? ($data['rules'] ?? null) : null;
            $terms = is_array($rules) ? ($rules['terms'] ?? null) : null;

            if (is_array($terms) && !empty($terms)) {
                return [
                    'valid' => in_array((string) $term, array_map('strval', $terms), true),
                    'source' => 'rule',
                    'log_failure' => false,
                ];
            }

            return [
                'valid' => true,
                'source' => 'default',
                'log_failure' => false,
            ];
        }

        $resp->errorClass();

        return [
            'valid' => true,
            'source' => 'default',
            'log_failure' => true,
        ];
    }

    /**
     * Decides whether a live registry status is eligible for restore under the GRACE rule.
     *
     * Pure helper for restore display/submit gates. The captured GRACE rule carries day counts:
     * renewPeriod, redemptPeriod, pendingDelete. Restore needs a known restorable live status and a
     * positive redemption period. When expiry and now are supplied, the combined renew+redemption
     * window bounds stale expired-status reads; without dates, live status + rule are authoritative.
     *
     * @param array|null $rule Captured data.rules from `ruleType=grace`
     * @param string $domainStatus Raw info().data.status
     * @param string|int|null $expiresAt Optional expiry timestamp/datetime
     * @param string|int|null $now Optional current timestamp/datetime for deterministic tests
     * @return array Decision: [
     *  'eligible' => bool,
     *  'reason' => string|null,
     *  'error_key' => string|null,
     *  'log_failure' => bool
     * ]
     */
    public static function decideGraceRestoreEligibility($rule, $domainStatus, $expiresAt = null, $now = null): array
    {
        if (!is_array($rule)) {
            return [
                'eligible' => false,
                'reason' => 'rule_unavailable',
                'error_key' => 'restore_grace_rule_unavailable',
                'log_failure' => true,
            ];
        }

        $renew_period = self::nonNegativeInt($rule['renewPeriod'] ?? null);
        $pending_delete = self::nonNegativeInt($rule['pendingDelete'] ?? null);
        $redempt_period = self::nonNegativeInt($rule['redemptPeriod'] ?? null);
        if ($renew_period === null || $pending_delete === null || $redempt_period === null) {
            return [
                'eligible' => false,
                'reason' => 'rule_unavailable',
                'error_key' => 'restore_grace_rule_unavailable',
                'log_failure' => true,
            ];
        }

        $status = strtolower(trim((string) $domainStatus));
        $eligible_statuses = Configure::get('Webnic.restore_eligible_statuses') ?: ['expired', 'redemption_grace'];
        $eligible_statuses = array_map('strval', (array) $eligible_statuses);
        $eligible_statuses = array_map('strtolower', array_map('trim', $eligible_statuses));
        if (!in_array($status, $eligible_statuses, true)) {
            return [
                'eligible' => false,
                'reason' => 'not_in_grace',
                'error_key' => 'restore_not_in_grace',
                'log_failure' => false,
            ];
        }

        if ($redempt_period <= 0) {
            return [
                'eligible' => false,
                'reason' => 'rule_denied',
                'error_key' => 'restore_grace_rule_denied',
                'log_failure' => false,
            ];
        }

        $now_ts = self::timestampOrNull($now);
        if ($now !== null && $now !== '' && $now_ts === null) {
            return [
                'eligible' => false,
                'reason' => 'window_unavailable',
                'error_key' => 'restore_grace_rule_unavailable',
                'log_failure' => true,
            ];
        }
        if ($now_ts !== null) {
            $expires_ts = self::timestampOrNull($expiresAt);
            if ($expires_ts === null) {
                return [
                    'eligible' => false,
                    'reason' => 'window_unavailable',
                    'error_key' => 'restore_grace_rule_unavailable',
                    'log_failure' => true,
                ];
            }

            $days_since_expiry = (int) floor(($now_ts - $expires_ts) / 86400);
            if ($days_since_expiry > ($renew_period + $redempt_period)) {
                return [
                    'eligible' => false,
                    'reason' => 'expired_window',
                    'error_key' => 'restore_grace_window_expired',
                    'log_failure' => false,
                ];
            }
        }

        return [
            'eligible' => true,
            'reason' => null,
            'error_key' => null,
            'log_failure' => false,
        ];
    }

    /**
     * Extracts the restore-context price from an extension-pricing response.
     *
     * This intentionally reads only `productPricing.price.restore` for the restore prompt. It does
     * not change transformPricing() or the normal Domain Manager register/renew/transfer map.
     *
     * @param WebnicResponse|null $resp Extension pricing response
     * @param string $tld Blesta or WebNIC TLD shape
     * @param int $term Restore term bucket, usually 1
     * @return array Decision: [
     *  'available' => bool,
     *  'price' => float|null,
     *  'currency' => string|null,
     *  'reason' => string|null,
     *  'error_key' => string|null,
     *  'log_failure' => bool
     * ]
     */
    public static function decideRestorePrice(?WebnicResponse $resp, string $tld, int $term = 1): array
    {
        if ($resp === null || !$resp->success()) {
            return self::restorePriceUnavailable('price_unavailable');
        }

        $data = $resp->data();
        $items = is_array($data) ? ($data['items'] ?? null) : null;
        if (!is_array($items)) {
            return self::restorePriceUnavailable('price_unavailable');
        }

        $price = self::extractRestorePrice($items, $tld, $term);
        if ($price === null) {
            return self::restorePriceUnavailable('missing_price');
        }

        $config = Configure::get('Webnic.pricing_transform') ?: [];

        return [
            'available' => true,
            'price' => $price,
            'currency' => $config['source_currency'] ?? 'USD',
            'reason' => null,
            'error_key' => null,
            'log_failure' => false,
        ];
    }

    /**
     * Reads a restore price from WebNIC pricing items.
     *
     * @param array $items WebNIC `data.items[]`
     * @param string $tld TLD to match
     * @param int $term Term bucket
     * @return float|null Restore price or null when unavailable/malformed
     */
    public static function extractRestorePrice(array $items, string $tld, int $term = 1)
    {
        $normalized_tld = self::normalizeExtensions([$tld])[0] ?? null;
        if ($normalized_tld === null) {
            return null;
        }

        $config = Configure::get('Webnic.pricing_transform') ?: [];
        $variant = $config['variant'] ?? 'ascii';
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['productKey'])) {
                continue;
            }

            $item_tld = self::normalizeExtensions([$item['productKey']])[0] ?? null;
            if ($item_tld !== $normalized_tld) {
                continue;
            }

            $year_map = $item['productPricing']['price']['restore'][$variant] ?? null;
            if (!is_array($year_map)) {
                continue;
            }

            $value = $year_map[$term] ?? ($year_map[(string) $term] ?? null);
            if ($value === null || !is_numeric($value)) {
                continue;
            }

            $price = (float) $value;
            if ($price <= 0) {
                continue;
            }

            return $price;
        }

        return null;
    }

    /**
     * Maps WebNIC extension-rule keys to future ModuleFields field types (INV-8).
     *
     * Known keys use Configure::get('Webnic.tld_rule_field_types'); unknown keys
     * are deliberately retained as required text fields so new provider rules are
     * visible to later rendering work instead of being silently dropped.
     *
     * @param array $rules Raw WebNIC data.rules map
     * @return array Rule map keyed by original WebNIC rule name
     */
    public static function mapExtensionRuleFields(array $rules): array
    {
        $field_types = Configure::get('Webnic.tld_rule_field_types') ?: [];
        $fields = [];

        foreach ($rules as $rule => $value) {
            $known = is_string($rule) && array_key_exists($rule, $field_types);
            $fields[$rule] = [
                'field_type' => $known ? $field_types[$rule] : 'text',
                'required' => !$known,
                'value' => $value,
            ];
        }

        return $fields;
    }

    /**
     * Normalizes WebNIC extension strings into Blesta's TLD shape.
     *
     * WebNIC returns dotless, lowercase strings (`["com","ac.id","中国"]`); Blesta's
     * Domain Manager wants `['.com', '.ac.id', '.中国']`. Pure transform (no I/O, no
     * clock, no DB — AR23): per entry it rejects non-string values, trims whitespace
     * and any pre-existing leading dot, lowercases ASCII only (so native-script IDNs
     * pass through intact), prepends exactly one dot, skips blank entries, and
     * de-duplicates preserving first-seen order.
     *
     * @param array $data Flat array of WebNIC extension strings
     * @return array Flat, numerically-indexed array of `.tld` strings
     */
    public static function normalizeExtensions(array $data): array
    {
        $tlds = [];

        foreach ($data as $ext) {
            if (!is_string($ext)) {
                continue;
            }

            $ext = trim(ltrim(trim((string) $ext), '.'));
            if ($ext === '') {
                // A blank/dot-only entry is not a TLD; skip rather than emit ".".
                continue;
            }

            // Lowercase ASCII only. The ASCII guard keeps native-script IDN TLDs
            // (中国/香港) untouched while still lowercasing ASCII (incl. xn-- punycode).
            $ext = mb_check_encoding($ext, 'ASCII') ? strtolower($ext) : $ext;

            $tlds[] = '.' . $ext;
        }

        return array_values(array_unique($tlds));
    }

    /**
     * Normalizes non-negative integer rule fields.
     *
     * @param mixed $value Raw rule value
     * @return int|null Non-negative integer or null
     */
    private static function nonNegativeInt($value)
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false || $int < 0) {
            return null;
        }

        return (int) $int;
    }

    /**
     * Parses a testable timestamp/datetime input.
     *
     * @param string|int|null $value Timestamp or datetime
     * @return int|null Unix timestamp or null
     */
    private static function timestampOrNull($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        try {
            return (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Builds the standard unavailable restore-price decision.
     *
     * @param string $reason Reason code
     * @return array Restore price decision
     */
    private static function restorePriceUnavailable(string $reason): array
    {
        return [
            'available' => false,
            'price' => null,
            'currency' => null,
            'reason' => $reason,
            'error_key' => 'restore_price_unavailable',
            'log_failure' => true,
        ];
    }

    /**
     * Encodes the catalogue branch decision from a final WebnicResponse (AR23).
     *
     * This is the testable home for the AC2/AC5 logic, so Webnic::getTlds() stays
     * thin glue around cache I/O and row resolution. It performs NO I/O — no Cache,
     * no log, no Record — only inspects the already-classified response:
     *  - success with a non-empty catalogue -> the list, write the cache;
     *  - success with empty data -> [] (a legitimately empty account), no write,
     *    no failure log (and crucially no cache clobber of last-known-good);
     *  - any failure (retryable/indeterminate/terminal) -> [] plus a failure log,
     *    so a transient outage is non-destructive but is NEVER a silent [].
     *
     * @param WebnicResponse $resp The final classified WebNIC response
     * @return array Decision: [
     *  'tlds' => array,          // normalized catalogue ([] on failure/empty)
     *  'write_cache' => bool,    // persist as last-known-good only when non-empty
     *  'log_failure' => bool     // log a failed exchange (transient != silent [])
     * ]
     */
    public static function decideCatalogue(WebnicResponse $resp): array
    {
        if (!$resp->success()) {
            return [
                'tlds' => [],
                'write_cache' => false,
                'log_failure' => true,
            ];
        }

        $data = $resp->data();
        if (!is_array($data)) {
            return [
                'tlds' => [],
                'write_cache' => false,
                'log_failure' => true,
            ];
        }

        $tlds = self::normalizeExtensions($data);

        return [
            'tlds' => $tlds,
            'write_cache' => count($tlds) > 0,
            'log_failure' => false,
        ];
    }

    /**
     * Transforms WebNIC pricing items into a source-currency cost map (AR23).
     *
     * Pure transform (no I/O, no clock, no DB, no Currencies) — the FX fan-out is
     * integration-tier glue in Webnic::getFilteredTldPricing(). Maps WebNIC
     * `data.items[]` into `[ '.tld' => [<blesta_action> => [<year> => <cost>]] ]`
     * in the reseller source currency, driven entirely by
     * Configure::get('Webnic.pricing_transform') (AR21):
     *  - renames action keys via `action_map` (`renewal` -> `renew`) and emits ONLY
     *    the actions listed there, so `restore`/`rereg`/`proxy`/`whois` are dropped;
     *  - dot-prefixes + ASCII-lowercases the productKey (reusing normalizeExtensions,
     *    so native-script IDNs pass through intact);
     *  - reads the `variant` block (`ascii` for MVP; `idn` is FR49, deferred);
     *  - intersects year keys with the config `terms` range and emits them in
     *    `terms` order (deterministic for assertSame), enforcing AC1's 1..10 bound;
     *  - carries ONLY the years WebNIC actually returns per [tld][action] — partial
     *    coverage is preserved, never padded/synthesized (INV-2);
     *  - skips malformed entries (non-string productKey, non-array pricing,
     *    non-numeric prices, missing years) and omits a TLD entirely when no
     *    in-scope action survives (never emits `['.tld' => []]`).
     *
     * @param array $items WebNIC `data.items[]`
     * @return array Source-currency cost map keyed by `.tld`
     */
    public static function transformPricing(array $items): array
    {
        $config = Configure::get('Webnic.pricing_transform') ?: [];
        $action_map = $config['action_map'] ?? [];
        $variant = $config['variant'] ?? 'ascii';
        $terms = $config['terms'] ?? range(1, 10);

        if (empty($action_map)) {
            return [];
        }

        $prices = [];

        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['productKey'])) {
                continue;
            }

            // Reuse the catalogue normalizer for the dot/IDN/non-string handling.
            $tld = self::normalizeExtensions([$item['productKey']])[0] ?? null;
            if ($tld === null) {
                continue;
            }

            $price_block = $item['productPricing']['price'] ?? null;
            if (!is_array($price_block)) {
                continue;
            }

            $tld_actions = [];

            // Emit only actions present in action_map (WebNIC key => Blesta key).
            foreach ($action_map as $webnic_action => $blesta_action) {
                $year_map = $price_block[$webnic_action][$variant] ?? null;
                if (!is_array($year_map)) {
                    continue;
                }

                $action_prices = [];

                // Iterate the configured terms so output keys are int, in-range, and
                // deterministically ordered; a year WebNIC omits stays omitted (INV-2).
                foreach ($terms as $year) {
                    $year = (int) $year;
                    $value = $year_map[$year] ?? ($year_map[(string) $year] ?? null);
                    if ($value === null || !is_numeric($value)) {
                        continue;
                    }

                    $action_prices[$year] = $value;
                }

                if (!empty($action_prices)) {
                    $tld_actions[$blesta_action] = $action_prices;
                }
            }

            // Omit a TLD whose only blocks were out-of-scope actions (restore/rereg).
            if (!empty($tld_actions)) {
                $prices[$tld] = array_replace_recursive($prices[$tld] ?? [], $tld_actions);
            }
        }

        return $prices;
    }

    /**
     * Normalizes pricing filters to the same shape used by the source map.
     *
     * A present-but-empty filter means "match nothing"; an absent filter still means
     * "all". This keeps malformed direct calls from widening to unfiltered syncs.
     *
     * @param array $filters Raw getFilteredTldPricing filters
     * @return array Normalized filters
     */
    public static function normalizePricingFilters(array $filters): array
    {
        $normalized = [];

        if (array_key_exists('tlds', $filters)) {
            $normalized['tlds'] = is_array($filters['tlds'])
                ? self::normalizeExtensions($filters['tlds'])
                : [];
        }

        if (array_key_exists('currencies', $filters)) {
            $currencies = [];
            foreach (is_array($filters['currencies']) ? $filters['currencies'] : [] as $currency) {
                if (!is_string($currency)) {
                    continue;
                }

                $currency = strtoupper(trim($currency));
                if ($currency !== '') {
                    $currencies[] = $currency;
                }
            }
            $normalized['currencies'] = array_values(array_unique($currencies));
        }

        if (array_key_exists('terms', $filters)) {
            $terms = [];
            foreach (is_array($filters['terms']) ? $filters['terms'] : [] as $term) {
                if (!is_numeric($term)) {
                    continue;
                }

                $term = (int) $term;
                if ($term > 0) {
                    $terms[] = $term;
                }
            }
            $normalized['terms'] = array_values(array_unique($terms));
        }

        return $normalized;
    }

    /**
     * Checks whether an array is shaped like transformPricing() output.
     *
     * @param array $source_map Candidate source-currency pricing map
     * @return bool True if the source map is non-empty and structurally valid
     */
    public static function isPricingSourceMap(array $source_map): bool
    {
        if (empty($source_map)) {
            return false;
        }

        $config = Configure::get('Webnic.pricing_transform') ?: [];
        $action_map = $config['action_map'] ?? [];
        $allowed_actions = array_values($action_map);
        $terms = array_map('intval', $config['terms'] ?? range(1, 10));

        if (empty($allowed_actions) || empty($terms)) {
            return false;
        }

        foreach ($source_map as $tld => $actions) {
            if (!is_string($tld) || $tld === '' || $tld[0] !== '.' || !is_array($actions) || empty($actions)) {
                return false;
            }

            foreach ($actions as $action => $year_map) {
                if (!is_string($action) || !in_array($action, $allowed_actions, true) || !is_array($year_map) || empty($year_map)) {
                    return false;
                }

                foreach ($year_map as $year => $value) {
                    if (!is_int($year) && !(is_string($year) && ctype_digit($year))) {
                        return false;
                    }

                    $year = (int) $year;
                    if (!in_array($year, $terms, true) || !is_numeric($value)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Verifies transformed pricing output reconciles with the fetched item count.
     *
     * @param array $source_map transformPricing() output
     * @param int $total_items Declared totalItems from the provider
     * @return bool True when the transformed source map is cache-safe
     */
    public static function pricingSourceMapComplete(array $source_map, int $total_items): bool
    {
        if ($total_items < 0) {
            return false;
        }

        if ($total_items === 0) {
            return count($source_map) === 0;
        }

        return count($source_map) === $total_items && self::isPricingSourceMap($source_map);
    }

    /**
     * Encodes the pricing branch decision from a final WebnicResponse (AR23).
     *
     * Mirrors decideCatalogue but guards at the correct depth: pricing `data()` is
     * the paged object `{pageSize,totalPages,totalItems,items:[...]}`, so the items
     * array is one level down. A success envelope whose `data` is not an array, or
     * lacks an `items` array, is MALFORMED -> log_failure (never "sells nothing").
     * Any non-success -> log_failure. `write_cache` is advisory (set only when the
     * page carries items); the glue's post-merge completeness check governs the
     * actual cross-page write (Task 3/4).
     *
     * @param WebnicResponse $resp The final classified WebNIC response
     * @return array Decision: [
     *  'items' => array,         // this page's items ([] on failure/empty)
     *  'write_cache' => bool,    // advisory: page carried items
     *  'log_failure' => bool     // log a failed/malformed exchange
     * ]
     */
    public static function decidePricing(WebnicResponse $resp): array
    {
        if (!$resp->success()) {
            return [
                'items' => [],
                'write_cache' => false,
                'log_failure' => true,
            ];
        }

        $data = $resp->data();
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            return [
                'items' => [],
                'write_cache' => false,
                'log_failure' => true,
            ];
        }

        $items = $data['items'];

        return [
            'items' => $items,
            'write_cache' => count($items) > 0,
            'log_failure' => false,
        ];
    }

    /**
     * Verifies that a paged pricing read reconciles before it is cacheable.
     *
     * Pure guard for the Webnic::getFilteredTldPricing() page loop. The row-scoped
     * pricing cache is valid only for a complete catalogue, so declared page/item
     * metadata must reconcile exactly. A success-empty first page is allowed, but
     * empty pages before the declared end and absurd totals are failed reads.
     *
     * @param int $merged_count Count of items accumulated across fetched pages
     * @param int $total_items Declared totalItems from the response
     * @param int $total_pages Declared totalPages from the response
     * @param int $page_size Requested pageSize
     * @param int $last_page Last page number fetched before stopping
     * @param int $hard_max Maximum pages allowed by the bounded loop
     * @param bool $stopped_on_empty_page True if the loop stopped on an empty page
     * @return bool True when the fetched pages prove a complete catalogue
     */
    public static function pricingPagesComplete(
        int $merged_count,
        int $total_items,
        int $total_pages,
        int $page_size,
        int $last_page,
        int $hard_max,
        bool $stopped_on_empty_page
    ): bool {
        if ($merged_count < 0
            || $total_items < 0
            || $total_pages < 0
            || $page_size <= 0
            || $last_page < 1
            || $hard_max < 1
            || $total_pages > $hard_max
        ) {
            return false;
        }

        if ($total_items === 0) {
            return $merged_count === 0 && $last_page === 1 && $total_pages <= 1;
        }

        if ($total_pages < 1) {
            return false;
        }

        $expected_pages = (int) ceil($total_items / $page_size);
        if ($total_pages !== $expected_pages) {
            return false;
        }

        if ($stopped_on_empty_page && $last_page < $total_pages) {
            return false;
        }

        return $last_page >= $total_pages && $merged_count === $total_items;
    }
}

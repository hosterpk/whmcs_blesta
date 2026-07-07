<?php
/**
 * WebNIC Module
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class Webnic extends RegistrarModule
{
    /**
     * Connectivity result string used when the classifier cannot be loaded.
     */
    private const CONNECTIVITY_RESULT_PASS = 'pass';

    /**
     * Connectivity result string used when the classifier cannot be loaded.
     */
    private const CONNECTIVITY_RESULT_FAILURE = 'failure';

    /**
     * Sentinel service id for the register intent opened during addService.
     *
     * Blesta invokes addService() BEFORE the service row is inserted (services.php:
     * 2089 vs 2160), so the new service id does not yet exist. The webnic_orders
     * idempotency key is scoped as (module_row_id, service_id, domain); a stable
     * sentinel keeps the key consistent across the first add and any Blesta
     * provisioning-retry within the owning module row. The order<->service link is
     * established by-domain by the 3.3 reconciler / 3.4a UI.
     */
    private const REGISTER_INTENT_SERVICE_ID = 0;

    /**
     * Domain-level nameserver management is exposed through five Blesta fields.
     */
    private const NAMESERVER_FIELD_LIMIT = 5;

    /**
     * LogicBoxes-parity baseline for "basic" DNS records before live WebNIC filtering.
     */
    private const DNS_RECORD_PARITY_TYPES = ['NS', 'A', 'AAAA', 'MX', 'CNAME', 'TXT', 'SRV'];

    /**
     * WN-5.5 T0-supported WebNIC basic record types.
     */
    private const DNS_RECORD_T0_SUPPORTED_TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'SRV', 'TXT'];

    /**
     * Zone-read subcodes that mean the domain has no usable WebNIC basic DNS zone.
     */
    private const DNS_RECORD_NO_ZONE_SUBCODES = ['DNS4200', 'DNS4201'];

    /**
     * Default TTL used when the submitted DNS record form leaves TTL blank.
     */
    private const DNS_RECORD_DEFAULT_TTL = 3600;

    /**
     * Largest TTL this form will pass through to WebNIC.
     */
    private const DNS_RECORD_MAX_TTL = 2147483647;

    /**
     * Largest DNSSEC key tag (RFC 4034 16-bit unsigned key-tag field).
     */
    private const DNSSEC_KEYTAG_MAX = 65535;

    /**
     * DNSSEC DS algorithm enum allowlist (gate1, re-confirmed at WN-5.7 T0).
     */
    private const DNSSEC_ALGORITHM_ALLOWLIST = [3, 5, 7, 8, 10, 12, 13, 14, 15, 16];

    /**
     * DNSSEC DS digest-type enum allowlist (gate1, re-confirmed at WN-5.7 T0).
     */
    private const DNSSEC_DIGESTTYPE_ALLOWLIST = [1, 2, 3, 4];

    /**
     * Registry status values that mean registrar transfer lock is enabled.
     */
    private const SETTINGS_LOCKED_STATUSES = ['transfer_protected', 'name_protected'];

    /**
     * Module row meta fields persisted by this module
     *
     * @var array
     */
    private $meta_fields = ['username', 'secret', 'environment'];

    /**
     * Module row meta fields encrypted by Blesta on save
     *
     * @var array
     */
    private $encrypted_fields = ['secret'];

    /**
     * Default module view path (the components/modules/webnic/ root passed to
     * View::setDefaultView so .pdt lookups resolve under this module). Mirrors the
     * bundled registrars (namesilo.php:32). Declared here because manageModule/
     * manageAddRow/manageEditRow and the 3.4a service-info/recovery views all call
     * setDefaultView(self::$defaultModuleView) — without the declaration it resolved
     * to null and mis-rooted the lookup (Dev Notes §D).
     *
     * @var string
     */
    private static $defaultModuleView;

    /**
     * Outcome of the most recent resendTransferEmail() call, for the resend tab to pick the
     * right inline copy (WN-4-2). resendTransferEmail() is the canonical RegistrarModule hook
     * (returns bool + Input errors on failure); the tab needs the finer 'ok' vs benign 'already'
     * distinction (both return true with no Input error), which a bool can't carry. One of
     * 'ok'|'already'|'failed'|null (null before any call).
     *
     * @var string|null
     */
    private $last_resend_outcome;

    /**
     * Structured outcome of the most recent performDelete() call.
     *
     * The admin delete tab needs more than the bool returned by performDelete(): it must
     * pick benign already-deleted copy and append the non-refundable warning only when the
     * registry response actually carries refund=false.
     *
     * @var array|null
     */
    private $last_delete_result;

    /**
     * Structured outcome of the most recent performRestore() call.
     *
     * The admin restore tab can use this to choose pending-vs-accepted copy without
     * parsing logs or provider messages.
     *
     * @var array|null
     */
    private $last_restore_result;

    /**
     * Structured outcome of the most recent WHOIS/contact update.
     *
     * @var array|null
     */
    private $last_contact_update_result;

    /**
     * Structured outcome of the most recent nameserver update.
     *
     * @var array|null
     */
    private $last_nameserver_update_result;

    /**
     * Structured outcome of the most recent Settings-tab provider action.
     *
     * @var array|null
     */
    private $last_settings_action_result;

    /**
     * Request-scoped cache for service-page live domain info reads.
     *
     * getAdmin/ClientServiceInfo and getAdmin/ClientServiceTabs may run in the same page render. WN-5-1
     * summary, restore-preview, delete-tab, and future parity-tab gates must see one consistent
     * info() payload for a row/domain instead of issuing duplicate non-atomic reads.
     *
     * @var array
     */
    private $service_info_domain_cache = [];

    /**
     * Marks the most recent forwarding POST as indeterminate (write likely applied but a
     * follow-up read could not confirm it). Per WN-5.6 AC #5 option A, an inconclusive
     * email-delete verify routes to the neutral refresh hint instead of a success notice or
     * an `outcome=ok` audit, so the apply path never double-records or over-claims success.
     *
     * @var bool
     */
    private $forwarding_post_inconclusive = false;

    /**
     * Initializes the module
     */
    public function __construct()
    {
        // Load configuration required by this module
        $this->loadConfig(__DIR__ . DS . 'config.json');

        // Load components required by this module
        Loader::loadComponents($this, ['Input']);

        // Load the language required by this module
        Language::loadLang('webnic', null, __DIR__ . DS . 'language' . DS);

        // Load the canonical data maps (config/webnic.php)
        Configure::load('webnic', __DIR__ . DS . 'config' . DS);

        // Set default module view root (Dev Notes §D)
        self::$defaultModuleView = 'components' . DS . 'modules' . DS . 'webnic' . DS;
    }

    /**
     * Returns the rendered view of the manage module page
     *
     * @param mixed $module A stdClass object representing the module and its rows
     * @param array $vars An array of post data submitted to or on the manage module page
     *  (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the manager module page
     */
    public function manageModule($module, array &$vars)
    {
        $this->view = new View('manage', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        $this->view->set('module', $module);

        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the add module row page
     *
     * @param array $vars An array of post data submitted to or on the add module row page
     *  (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the add module row page
     */
    public function manageAddRow(array &$vars)
    {
        $this->view = new View('add_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars['environment'])) {
            $vars['environment'] = 'production';
        }

        $this->view->set('vars', (object) $vars);

        return $this->view->fetch();
    }

    /**
     * Returns the rendered view of the edit module row page
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of post data submitted to or on the edit module row page
     *  (used to repopulate fields after an error)
     * @return string HTML content containing information to display when viewing the edit module row page
     */
    public function manageEditRow($module_row, array &$vars)
    {
        $this->view = new View('edit_row', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView(self::$defaultModuleView);

        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        if (empty($vars)) {
            $vars = (array) $module_row->meta;
            unset($vars['secret']);
        }

        if (empty($vars['environment'])) {
            $vars['environment'] = 'production';
        }

        $this->view->set('vars', (object) $vars);

        return $this->view->fetch();
    }

    /**
     * Adds the module row. Sets Input errors on failure, preventing the row
     * from being added.
     *
     * @param array $vars An array of module info to add
     * @return array A numerically indexed array of meta fields for the module row containing:
     *
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     */
    public function addModuleRow(array &$vars)
    {
        if (empty($vars['environment'])) {
            $vars['environment'] = 'production';
        }

        $this->Input->setRules($this->getRowRules($vars));

        if ($this->Input->validates($vars)) {
            return $this->getRowMeta($vars);
        }
    }

    /**
     * Edits the module row. Sets Input errors on failure, preventing the row
     * from being updated.
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     * @param array $vars An array of module info to update
     * @return array A numerically indexed array of meta fields for the module row containing:
     *
     *  - key The key for this meta field
     *  - value The value for this key
     *  - encrypted Whether or not this field should be encrypted (default 0, not encrypted)
     */
    public function editModuleRow($module_row, array &$vars)
    {
        $secret_blank = !array_key_exists('secret', $vars) || $vars['secret'] === '' || $vars['secret'] === null;
        if ($secret_blank && isset($module_row->meta->secret)) {
            $vars['secret'] = $module_row->meta->secret;
        }

        if (empty($vars['environment'])) {
            $vars['environment'] = 'production';
        }

        $this->Input->setRules($this->getRowRules($vars));

        if ($this->Input->validates($vars)) {
            return $this->getRowMeta($vars);
        }
    }

    /**
     * Builds module row meta for Blesta to persist
     *
     * @param array $vars An array of module info
     * @return array A list of module row meta records
     */
    private function getRowMeta(array $vars)
    {
        $meta = [];

        foreach ($this->meta_fields as $field) {
            if (array_key_exists($field, $vars)) {
                $meta[] = [
                    'key' => $field,
                    'value' => $vars[$field],
                    'encrypted' => in_array($field, $this->encrypted_fields) ? 1 : 0
                ];
            }
        }

        return $meta;
    }

    /**
     * Builds and returns the rules required to add/edit a module row
     *
     * @param array $vars An array reference of key/value data pairs
     * @return array An array of Input rules suitable for Input::setRules()
     */
    private function getRowRules(array &$vars)
    {
        return [
            'username' => [
                'empty' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Webnic.!error.username.empty', true)
                ]
            ],
            'secret' => [
                'empty' => [
                    // Short-circuit the secret field so a blank secret fails here
                    // and never triggers the live connectivity call below.
                    'last' => true,
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Webnic.!error.secret.empty', true)
                ],
                // Validate-on-save: prove the credentials connect to WebNIC before
                // the row persists (mirror logicboxes/namesilo valid_connection).
                // The static message is the no-placeholder generic key; the adapter
                // always sets the specific variant error directly and returns true,
                // so this static copy is a never-rendered fallback (and must not
                // carry the %1$s connectivity_failure key, which would surface a
                // literal %1$s if ever shown).
                'valid_connection' => [
                    'rule' => [
                        [$this, 'testConnectionRule'],
                        $vars['username'] ?? '',
                        $vars['environment'] ?? 'production'
                    ],
                    'message' => Language::_('Webnic.!error.connectivity_failure_generic', true)
                ]
            ],
            'environment' => [
                'valid' => [
                    'rule' => ['in_array', ['ote', 'production']],
                    'message' => Language::_('Webnic.!error.environment.valid', true)
                ]
            ]
        ];
    }

    /**
     * Input rule adapter for the secret field's valid_connection rule.
     *
     * Blesta's Input prepends the bound field's submitted value, so this receives
     * the secret first, then the two rule extras (username, environment).
     *
     * Prerequisite guard runs FIRST: 'last' => true short-circuits the secret
     * field only, so this rule still fires when a sibling field (username,
     * environment) is blank/invalid. Running the live test then would waste a
     * ~10-30s timeout on a half-filled form, and its setErrors() would replace
     * the whole error set, clobbering those fields' clearer errors. So when a
     * prerequisite is missing/invalid, return true and set NO error here, letting
     * the per-field empty/valid rules surface them.
     *
     * @param string $secret The submitted API secret (field value)
     * @param string $username The submitted API username
     * @param string $environment The submitted environment
     * @return bool Always true; a connectivity failure is reported via setErrors()
     */
    public function testConnectionRule($secret, $username, $environment): bool
    {
        if (!is_string($username)
            || trim($username) === ''
            || !is_string($secret)
            || trim($secret) === ''
            || !in_array($environment, ['ote', 'production'], true)
        ) {
            return true;
        }

        $result = $this->testConnection($username, $secret, $environment);
        if (($result['result'] ?? self::CONNECTIVITY_RESULT_FAILURE) !== self::CONNECTIVITY_RESULT_PASS) {
            // Set the specific variant error directly (namesilo idiom) and return
            // true; Input::validates() still fails because errors were set, and the
            // static rule message does not double up.
            $this->Input->setErrors(['secret' => ['connectivity' => $result['message']]]);
        }

        return true;
    }

    /**
     * Input rule: required WHOIS/contact field.
     *
     * @param mixed $value Submitted field value
     * @return bool
     */
    public function validateWhoisContactRequired($value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    /**
     * Input rule: submitted contact id must match the fresh domain attachment.
     *
     * @param mixed $value Submitted external contact id
     * @param string|null $expected Fresh contact id from domain info
     * @return bool
     */
    public function validateWhoisContactAttachedId($value, $expected): bool
    {
        return is_string($expected)
            && $expected !== ''
            && is_scalar($value)
            && trim((string) $value) === $expected;
    }

    /**
     * Input rule: email syntax.
     *
     * @param mixed $value Submitted email
     * @return bool
     */
    public function validateWhoisContactEmail($value): bool
    {
        return is_scalar($value)
            && filter_var(trim((string) $value), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Input rule: ISO-3166 alpha-2 country.
     *
     * @param mixed $value Submitted country code
     * @return bool
     */
    public function validateWhoisContactCountry($value): bool
    {
        return is_scalar($value) && preg_match('/^[A-Za-z]{2}$/', trim((string) $value)) === 1;
    }

    /**
     * Input rule: WebNIC phone shape accepted by contact/create/modify.
     *
     * @param mixed $value Submitted phone number
     * @return bool
     */
    public function validateWhoisContactPhone($value): bool
    {
        return is_scalar($value) && preg_match('/^\+[0-9]{1,4}\.[0-9][0-9 .-]*$/', trim((string) $value)) === 1;
    }

    /**
     * Input rule: nameserver submissions must arrive as text fields.
     *
     * @param mixed $value Submitted nameserver value
     * @return bool
     */
    public function validateNameserverText($value): bool
    {
        return is_string($value);
    }

    /**
     * Input rule: DNS record form values must arrive as scalar text fields.
     *
     * @param mixed $value Submitted DNS record value
     * @return bool
     */
    public function validateDnsRecordText($value): bool
    {
        return is_string($value) || is_int($value);
    }

    /**
     * Runs a read-only WebNIC connectivity test for the submitted credentials.
     *
     * Mints a fresh JWT against the submitted credentials and issues one
     * lightweight authenticated availability read — two KINDS of call, never a
     * registration, transfer, or any state-changing/billable call (FR4). A
     * throwaway in-memory token store is injected so the durable webnic_tokens
     * cache is never read or written (no phantom row on add where there is no
     * module_row_id yet, no stale-token false-pass on edit — INV-1): get()
     * returns null to force a fresh mint, save()/delete() are no-ops. The pure
     * WebnicConnectivity mapper classifies the outcome, which is rendered as a
     * localized, secret-free message (NFR1/NFR10).
     *
     * Live glue (real cURL, $_SERVER, Loader) is integration-only; the pure
     * classification is unit-covered in WebnicConnectivityTest.
     *
     * @param string $username WebNIC API username
     * @param string $secret WebNIC API secret
     * @param string $environment production or ote
     * @return array Structured result:
     *  - result string One of the WebnicConnectivity outcome constants
     *  - message string Localized, secret-free result message
     *  - environment string Localized environment label
     */
    public function testConnection($username, $secret, $environment): array
    {
        $env_label = $this->environmentLabel($environment);

        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_connectivity.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_api.php');

            // Throwaway in-memory store (duck-typed get/save/delete). get()->null
            // forces a fresh mint against the submitted creds every call; the no-op
            // save()/delete() never touch webnic_tokens. module_row_id 0 only scopes
            // the now no-op cache, never a write.
            $store = new class {
                public function get($id)
                {
                    return null;
                }

                public function save($id, $token, $expires_at)
                {
                }

                public function delete($id)
                {
                }
            };

            $api = new \WebnicApi(0, $username, $secret, $environment, $store);

            // One mint + one availability read. example.com is a valid-format
            // sentinel (namesilo precedent) so a DOM2400 cannot masquerade as a
            // connectivity failure; an availability read never charges.
            $response = $api->submit('domain/v2/query', ['domainName' => 'example.com'], 'GET');

            $result = \WebnicConnectivity::classify($response);

            return [
                'result' => $result,
                'message' => $this->connectivityMessage($result, $env_label, $response),
                'environment' => $env_label,
            ];
        } catch (\Throwable $e) {
            // A live call inside the row controller must never surface a raw
            // exception (Loader miss, cURL unavailable, client construction). No
            // WebnicResponse/status exists here, so use the no-placeholder generic
            // key — the %1$s connectivity_failure key cannot be safely interpolated.
            return [
                'result' => self::CONNECTIVITY_RESULT_FAILURE,
                'message' => Language::_('Webnic.!error.connectivity_failure_generic', true),
                'environment' => $env_label,
            ];
        }
    }

    /**
     * Resolves the localized environment label for a connectivity result.
     *
     * @param string $environment production or ote
     * @return string Localized environment label
     */
    private function environmentLabel($environment): string
    {
        return $environment === 'ote'
            ? Language::_('Webnic.env_badge.ote', true)
            : Language::_('Webnic.env_badge.production', true);
    }

    /**
     * Builds the localized, secret-free connectivity-result message.
     *
     * Messages are assembled only from static localized copy plus safe scalars
     * (environment label, server IP, integer HTTP status) — never the secret, the
     * JWT, or raw provider text (NFR1).
     *
     * @param string $result A WebnicConnectivity outcome constant
     * @param string $env_label Localized environment label
     * @param WebnicResponse $response The classified response
     * @return string Localized result message
     */
    private function connectivityMessage($result, $env_label, WebnicResponse $response): string
    {
        switch ($result) {
            case \WebnicConnectivity::PASS:
                return Language::_('Webnic.!success.connectivity', true, $env_label);
            case \WebnicConnectivity::IP_ALLOWLIST:
                $ip = $_SERVER['SERVER_ADDR'] ?? null;
                if ($ip === null || $ip === '') {
                    // No egress IP available — render the message without a literal
                    // IP rather than a blank or fabricated address (NFR14).
                    return Language::_('Webnic.!error.connectivity_ip_allowlist_no_ip', true);
                }

                return Language::_('Webnic.!error.connectivity_ip_allowlist', true, $ip);
            case \WebnicConnectivity::INVALID_CREDENTIAL:
                return Language::_('Webnic.!error.connectivity_invalid_credential', true);
            default:
                // connectivity_failure REQUIRES its %1$s status arg — always pass it.
                return Language::_('Webnic.!error.connectivity_failure', true, (int) $response->status());
        }
    }

    /**
     * Gets a list of the TLDs sellable through the connected WebNIC account.
     *
     * Feeds the Domain Manager's import / available-TLD path: the admin import
     * picker, the per-TLD `supported` validation rule, the change-package check,
     * and the storefront order form all consume the returned `.tld` strings with
     * in_array(). The result is always a flat, numerically-indexed array of
     * lowercase, single-leading-dot strings (incl. native-script IDNs), never
     * associative, null, or false — a wrong shape silently breaks TLD import.
     *
     * Cache-first (AC7): a warm last-known-good catalogue short-circuits before any
     * API call, so a transient outage is invisible while the cache is valid. On a
     * cache miss the WebNIC Get Domain Extensions read runs; a failure is logged
     * (never silent) and returns a non-destructive [] (the import is additive, so
     * already-imported TLDs survive). A genuine success-with-empty-data returns []
     * without writing the cache (the account legitimately sells nothing).
     *
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array A list of all TLDs supported by the registrar module
     */
    public function getTlds($module_row_id = null)
    {
        if ($module_row_id !== null && (!is_numeric($module_row_id) || (int) $module_row_id <= 0)) {
            // Explicit invalid IDs must fail closed. Passing 0/"" through would make
            // getModuleRow() take the no-arg path and could serve a preset row instead.
            return [];
        }

        $row = $this->getModuleRow($module_row_id);
        if ($row === false) {
            // An explicit module_row_id resolved to a missing/wrong-company/wrong-module
            // row. Fail closed — never substitute a different row's catalogue or
            // credentials (INV-1 cross-row leak).
            return [];
        }

        if (empty($row) && $module_row_id === null) {
            // No-row consumer: the admin import picker, the per-TLD validation rule, the
            // change-package check, and the storefront list all reach getTlds() through
            // moduleRpc() with no row id, so getModuleRow(null) is unset. Mirror
            // namesilo's first-row fallback so those surfaces are not blanked.
            $rows = $this->getModuleRows();
            $row = is_array($rows) ? ($rows[0] ?? null) : null;
        }

        if (empty($row)) {
            // Genuinely no module row configured at all — a legitimately unconfigured
            // account, not a failure.
            return [];
        }

        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_api.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_pricing.php');

            $cache_key = 'tlds_' . (int) $row->id;
            $cache_dir = Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'webnic' . DS;

            // Serve the warm last-known-good cache first (AC7). fetchCache() returns false
            // on a miss/expiry, so a truthy `if ($cache)` is required — a `!== null` check
            // would deserialize false and break the array contract. The key is row-scoped
            // to preserve INV-1 across multiple WebNIC accounts.
            $cache = Cache::fetchCache($cache_key, $cache_dir);
            if ($cache) {
                $decoded = base64_decode($cache, true);
                $cached_tlds = $decoded === false ? false : safe_unserialize($decoded);
                if (is_array($cached_tlds)) {
                    $cached_tlds = \WebnicPricing::normalizeExtensions($cached_tlds);
                    if (count($cached_tlds) > 0) {
                        return $cached_tlds;
                    }
                }
            }

            // Build the client from the RESOLVED row (INV-1): a real, durable TokenStore
            // (not testConnection's throwaway store) so the bearer token persists across
            // the Domain Manager's repeated reads instead of re-minting on every call.
            $api = new \WebnicApi(
                $row->id,
                $row->meta->username,
                $row->meta->secret,
                $row->meta->environment,
                new \Webnic\TokenStore()
            );
            $response = (new \WebnicPricing($api))->getDomainExtensions();

            // The AC2/AC5 branch decision lives in the pure, unit-tested helper.
            $decision = \WebnicPricing::decideCatalogue($response);

            if ($decision['log_failure']) {
                // Transient/terminal failure with no warm cache to fall back on (the
                // cache was already checked first and was cold). The [] is non-destructive
                // (additive import) but must NEVER be silent — log request + response.
                $this->log('domain/v2/exts', serialize($api->lastRequest()), 'input', false);
                $this->log('domain/v2/exts', serialize($response->body()), 'output', false);

                return [];
            }

            $tlds = $decision['tlds'];

            // Persist the successful catalogue as the last-known-good. Write only when
            // non-empty so a transient success-with-empty-data cannot clobber a good
            // cache (mirrors namesilo). writeCache stores a string, never a raw array.
            if ($decision['write_cache'] && Configure::get('Caching.on') && is_writable(CACHEDIR)) {
                try {
                    Cache::writeCache(
                        $cache_key,
                        base64_encode(serialize($tlds)),
                        strtotime(Configure::get('Blesta.cache_length')) - time(),
                        $cache_dir
                    );
                } catch (\Throwable $e) {
                    // Write failed — disable caching rather than throw.
                    Configure::set('Caching.on', false);
                }
            }

            return $tlds;
        } catch (\Throwable $e) {
            // A registrar getTlds() must never throw to the Domain Manager. No
            // WebnicResponse exists here (Loader miss, client construction, unexpected
            // throw), so log the localized catalogue-unavailable message and return [].
            try {
                $this->log('domain/v2/exts', Language::_('Webnic.!error.tlds_unavailable', true), 'output', false);
            } catch (\Throwable $inner) {
                // Logging must never turn a read into a thrown error.
            }

            return [];
        }
    }

    /**
     * Gets a list of the TLD prices in the operator's configured currencies.
     *
     * Domain Manager price-sync entry point; delegates to getFilteredTldPricing so
     * the reflection gate (admin_domains.php) sees getFilteredTldPricing declared on
     * Webnic and reports price sync as supported (AC5).
     *
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array [tld => [currency => [year# => ['register' => price, 'transfer' => price, 'renew' => price]]]]
     */
    public function getTldPricing($module_row_id = null)
    {
        return $this->getFilteredTldPricing($module_row_id);
    }

    /**
     * Gets a filtered list of the TLD prices in the operator's configured currencies.
     *
     * Mirrors getTlds() exactly (guard order, cache idiom, pure-helper delegation,
     * never-throw discipline). Returns RAW reseller cost converted into each company
     * currency with NO module-applied markup and NO rounding (FR8/AC3) — Blesta's
     * tld_sync::formatPricing applies operator markup + rounding downstream.
     *
     * The cached artifact is the PRE-conversion source-currency map (transformPricing
     * output), not this converted/filtered return. So a warm cache short-circuits the
     * WebNIC read (a transient outage is invisible, AC6) while the Currencies->convert
     * + $filters fan-out still runs on every call — which is what makes the single
     * row-scoped key safe under arbitrary $filters (AC8). The converted map is never
     * cached; it is recomputed every call (mirrors LogicBoxes' uncached return).
     *
     * Failure is always non-destructive: any read failure / missing source currency /
     * unexpected throw logs and returns [] (tld_sync skips empty results, preserving
     * existing prices), never a silent empty and never a throw to the Domain Manager
     * (AC6/AC7).
     *
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @param array $filters Optional criteria: 'tlds' (['.com',...]), 'currencies'
     *  ([code,...]), 'terms' ([1..10]); an absent key means "all". The current caller
     *  (tld_sync::synchronizePrices) passes only tlds + currencies.
     * @return array [tld => [currency => [year# => ['register' => price, 'transfer' => price, 'renew' => price]]]]
     */
    public function getFilteredTldPricing($module_row_id = null, $filters = [])
    {
        $endpoint = 'domain/v2/exts/pricing';
        $log_unavailable = function () use ($endpoint): void {
            try {
                $this->log($endpoint, Language::_('Webnic.!error.pricing_unavailable', true), 'output', false);
            } catch (\Throwable $inner) {
                // Logging must never turn a read into a thrown error.
            }
        };

        if ($module_row_id !== null && (!is_numeric($module_row_id) || (int) $module_row_id <= 0)) {
            // Explicit invalid IDs must fail closed (INV-1) before getModuleRow() can
            // take the no-arg path and serve a preset/foreign row's pricing.
            $log_unavailable();
            return [];
        }

        $row = $this->getModuleRow($module_row_id);
        if ($row === false) {
            // Explicit id resolved to a missing/wrong-company/wrong-module row — fail
            // closed, never substitute a different row's pricing or credentials.
            $log_unavailable();
            return [];
        }

        if (empty($row) && $module_row_id === null) {
            // tld_sync calls with a null id (it pre-sets the first row via setModuleRow);
            // mirror getTlds()' first-row fallback so price sync is not blanked.
            $rows = $this->getModuleRows();
            $row = is_array($rows) ? ($rows[0] ?? null) : null;
        }

        if (empty($row)) {
            // No module row configured at all — unconfigured account, not a failure.
            return [];
        }

        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_api.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_pricing.php');
            $filters = \WebnicPricing::normalizePricingFilters((array) $filters);

            // Canonical map (AR21): all naming/term/currency decisions flow from here.
            $transform = Configure::get('Webnic.pricing_transform') ?: [];
            $action_map = (isset($transform['action_map']) && is_array($transform['action_map']))
                ? $transform['action_map']
                : [];
            $transtypes = array_keys($action_map);
            $source_currency = $transform['source_currency'] ?? 'USD';
            $terms = (isset($transform['terms']) && is_array($transform['terms']))
                ? $transform['terms']
                : range(1, 10);
            $company_id = Configure::get('Blesta.company_id');

            // Distinct prefix from the catalogue cache (tlds_) so the two never cross.
            $cache_key = 'tld_prices_' . (int) $row->id;
            $cache_dir = $company_id . DS . 'modules' . DS . 'webnic' . DS;

            // (4) Cache-first read of the SOURCE-currency map (pre-conversion). An empty
            // cached map falls through to a fresh fetch (never short-circuits to []).
            $source_map = null;
            $cache = Cache::fetchCache($cache_key, $cache_dir);
            if ($cache) {
                $decoded = base64_decode($cache, true);
                $cached_map = $decoded === false ? false : safe_unserialize($decoded);
                if (is_array($cached_map) && \WebnicPricing::isPricingSourceMap($cached_map)) {
                    $source_map = $cached_map;
                }
            }

            if ($source_map === null) {
                // (5) Build the client from the RESOLVED row with a durable TokenStore.
                $api = new \WebnicApi(
                    $row->id,
                    $row->meta->username,
                    $row->meta->secret,
                    $row->meta->environment,
                    new \Webnic\TokenStore()
                );
                $pricing = new \WebnicPricing($api);

                // (6) Paginate the full catalogue (empty $tlds => fetch-all-by-transtype),
                // bounded so a bad totalPages cannot spin forever.
                $page = 1;
                $page_size = 100;
                $hard_max = 100;
                $merged = [];
                $merged_count = 0;
                $total_items = null;
                $total_pages_decl = null;
                $expected_pages = null;
                $response = null;
                $last_page = 0;
                $stopped_on_empty_page = false;
                $pagination_valid = true;

                while ($page <= $hard_max) {
                    $response = $pricing->getExtensionPrice([], $transtypes, $page, $page_size);
                    $decision = \WebnicPricing::decidePricing($response);

                    // (7) Failed/malformed page => log input + output, return [] (no write).
                    if ($decision['log_failure']) {
                        $this->log($endpoint, serialize($api->lastRequest()), 'input', false);
                        $this->log($endpoint, serialize($response->body()), 'output', false);

                        return [];
                    }

                    $items = $decision['items'];
                    $data = $response->data();
                    $last_page = $page;
                    if (!isset($data['totalItems'], $data['totalPages'])
                        || !is_numeric($data['totalItems'])
                        || !is_numeric($data['totalPages'])
                        || (isset($data['pageSize']) && !is_numeric($data['pageSize']))
                    ) {
                        $pagination_valid = false;
                        break;
                    }

                    $page_total_items = (int) $data['totalItems'];
                    $page_total_pages = (int) $data['totalPages'];
                    $page_size_decl = isset($data['pageSize']) ? (int) $data['pageSize'] : $page_size;
                    if ($page_size_decl !== $page_size) {
                        $pagination_valid = false;
                        break;
                    }

                    if ($total_items === null) {
                        $total_items = $page_total_items;
                        $total_pages_decl = $page_total_pages;
                        $by_items = $total_items > 0 ? (int) ceil($total_items / $page_size) : 0;
                        $expected_pages = min($hard_max, max($total_pages_decl, $by_items, 1));
                    } elseif ($page_total_items !== $total_items || $page_total_pages !== $total_pages_decl) {
                        $pagination_valid = false;
                        break;
                    }

                    $merged = array_merge($merged, $items);
                    $merged_count += count($items);

                    // An empty page stops the loop; its validity is settled by the
                    // completeness check below (benign only as the first/last page).
                    if (count($items) === 0) {
                        $stopped_on_empty_page = true;
                        break;
                    }

                    if ($page >= $expected_pages) {
                        break;
                    }

                    $page++;
                }

                // Pagination completeness — NEVER cache a partial catalogue (the single
                // row-only key is valid only because $source_map is the complete catalogue).
                // A short merge, a mid-stream empty page, or inconsistent/absurd totals =>
                // failed read: log + [] + skip the write (non-destructive).
                $complete = $pagination_valid
                    && $total_items !== null
                    && $total_pages_decl !== null
                    && \WebnicPricing::pricingPagesComplete(
                        $merged_count,
                        $total_items,
                        $total_pages_decl,
                        $page_size,
                        $last_page,
                        $hard_max,
                        $stopped_on_empty_page
                    );

                if (!$complete) {
                    $this->log($endpoint, serialize($api->lastRequest()), 'input', false);
                    $this->log($endpoint, serialize($response !== null ? $response->body() : null), 'output', false);

                    return [];
                }

                // (8) Transform the merged catalogue, then cache the SOURCE map (never the
                // converted return) when non-empty so a transient empty cannot clobber it.
                $source_map = \WebnicPricing::transformPricing($merged);

                if (!\WebnicPricing::pricingSourceMapComplete($source_map, (int) $total_items)) {
                    $this->log($endpoint, serialize($api->lastRequest()), 'input', false);
                    $this->log($endpoint, serialize($response !== null ? $response->body() : null), 'output', false);

                    return [];
                }

                if (count($source_map) > 0 && Configure::get('Caching.on') && is_writable(CACHEDIR)) {
                    try {
                        Cache::writeCache(
                            $cache_key,
                            base64_encode(serialize($source_map)),
                            strtotime(Configure::get('Blesta.cache_length')) - time(),
                            $cache_dir
                        );
                    } catch (\Throwable $e) {
                        Configure::set('Caching.on', false);
                    }
                }
            }

            // (9) Fan-out: source map x company currencies x terms, honoring $filters.
            // Runs on EVERY call (warm or cold) so the current filters are always applied.
            Loader::loadModels($this, ['Currencies']);

            $currencies = [];
            $company_currencies = $this->Currencies->getAll($company_id);
            foreach ((array) $company_currencies as $currency) {
                if (isset($currency->code)) {
                    $currencies[$currency->code] = $currency;
                }
            }

            if (!isset($currencies[$source_currency])) {
                // The reseller source currency must exist among the operator's currencies
                // to convert FROM. Missing => log + [] (no setErrors in the cron path, no
                // throw). An operator with no USD configured syncs nothing until they add
                // it or override source_currency in the map.
                $log_unavailable();

                return [];
            }

            $terms_filter = isset($filters['terms']) ? $filters['terms'] : null;

            $prices = [];
            foreach ($source_map as $tld => $actions) {
                if (array_key_exists('tlds', $filters) && !in_array($tld, $filters['tlds'], true)) {
                    continue;
                }

                $register = (isset($actions['register']) && is_array($actions['register'])) ? $actions['register'] : [];
                $renew = (isset($actions['renew']) && is_array($actions['renew'])) ? $actions['renew'] : [];
                $transfer = (isset($actions['transfer']) && is_array($actions['transfer'])) ? $actions['transfer'] : [];

                // Emit only the years WebNIC actually returned (no padding, INV-2),
                // restricted to the configured term range and ordered deterministically.
                $years = [];
                foreach ($terms as $year) {
                    $year = (int) $year;
                    if (isset($register[$year]) || isset($renew[$year]) || isset($transfer[$year])) {
                        $years[] = $year;
                    }
                }

                foreach ($currencies as $currency) {
                    if (array_key_exists('currencies', $filters) && !in_array($currency->code, $filters['currencies'], true)) {
                        continue;
                    }

                    foreach ($years as $year) {
                        if ($terms_filter !== null && !in_array($year, $terms_filter, true)) {
                            continue;
                        }

                        // Transfer is per-year if present, else the year-1 rate (only the
                        // year-1 transfer survives formatPricing, so do not over-build).
                        $transfer_source = $transfer[$year] ?? ($transfer[1] ?? null);

                        $prices[$tld][$currency->code][$year] = [
                            'register' => isset($register[$year])
                                ? $this->Currencies->convert($register[$year], $source_currency, $currency->code, $company_id)
                                : null,
                            'transfer' => $transfer_source !== null
                                ? $this->Currencies->convert($transfer_source, $source_currency, $currency->code, $company_id)
                                : null,
                            'renew' => isset($renew[$year])
                                ? $this->Currencies->convert($renew[$year], $source_currency, $currency->code, $company_id)
                                : null,
                        ];
                    }
                }
            }

            return $prices;
        } catch (\Throwable $e) {
            // A registrar pricing method must never throw to the Domain Manager. No
            // WebnicResponse exists here (Loader miss, client construction, Currencies
            // miss, unexpected throw), so log the localized message and return [].
            $log_unavailable();

            return [];
        }
    }

    /**
     * Determines whether a registration or transfer term is valid for a TLD.
     *
     * Overrides RegistrarModule::isValidTerm with WebNIC extension-rule validation
     * while preserving the base 1..10 fallback. A rule read is an optimization and
     * refinement: unavailable rules, missing rows, transport failures, and any throw
     * default open to the 1..10 bound so a transient registry read never hard-blocks
     * a legitimate order.
     *
     * @param string $tld The TLD to validate, usually with a leading dot from Blesta
     * @param int $term The requested registration/transfer term
     * @param bool $transfer True when validating a transfer term
     * @return bool True if the term is valid, false otherwise
     */
    public function isValidTerm($tld, $term, $transfer = false)
    {
        $term = (int) $term;
        if ($term < 1 || $term > 10) {
            return false;
        }

        $endpoint = 'domain/v2/ext-rules';
        $default = function () use ($term): bool {
            return $term >= 1 && $term <= 10;
        };
        $log_exception = function (\Throwable $e) use ($endpoint): void {
            try {
                $context = [
                    'error' => get_class($e),
                    'message' => $e->getMessage(),
                ];
                if (class_exists('\Webnic\Support\Redactor')) {
                    $context = \Webnic\Support\Redactor::scrub($context);
                }

                $this->log($endpoint, serialize($context), 'output', false);
            } catch (\Throwable $inner) {
                // Logging must never turn term validation into a thrown error.
            }
        };

        try {
            $ext = ltrim(trim((string) $tld), '.');
            $ext = mb_check_encoding($ext, 'ASCII') ? strtolower($ext) : $ext;
            if ($ext === '') {
                return $default();
            }

            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_api.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_pricing.php');

            $row = $this->getModuleRow();
            if (empty($row)) {
                $rows = $this->getModuleRows();
                $row = is_array($rows) ? ($rows[0] ?? null) : null;
            }

            if (empty($row)) {
                $decision = \WebnicPricing::decideTermValidity(null, $term, (bool) $transfer);

                return (bool) $decision['valid'];
            }

            $rule_type = $transfer ? 'rertransfer' : 'registration';
            $cache_key = 'ext_rule_' . $rule_type . '_' . (int) $row->id . '_' . $ext;
            $cache_dir = Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'webnic' . DS;

            $cache = Cache::fetchCache($cache_key, $cache_dir);
            if ($cache) {
                $decoded = base64_decode($cache, true);
                $cached_body = $decoded === false ? false : safe_unserialize($decoded);
                if (is_array($cached_body)) {
                    $cached_response = new \WebnicResponse($cached_body, 200);
                    if ($cached_response->success()) {
                        $decision = \WebnicPricing::decideTermValidity(
                            $cached_response,
                            $term,
                            (bool) $transfer
                        );

                        return (bool) $decision['valid'];
                    }
                }
            }

            $api = new \WebnicApi(
                $row->id,
                $row->meta->username,
                $row->meta->secret,
                $row->meta->environment,
                new \Webnic\TokenStore()
            );
            $response = (new \WebnicPricing($api))->getExtensionRule($ext, $rule_type);
            $decision = \WebnicPricing::decideTermValidity($response, $term, (bool) $transfer);

            if ($decision['log_failure']) {
                try {
                    $this->log($endpoint, serialize($api->lastRequest()), 'input', false);
                    $this->log($endpoint, serialize($response->body()), 'output', false);
                } catch (\Throwable $e) {
                    // Preserve the term decision when module logging is unavailable.
                }
            }

            if ($response->success() && Configure::get('Caching.on') && is_writable(CACHEDIR)) {
                try {
                    Cache::writeCache(
                        $cache_key,
                        base64_encode(serialize($response->body())),
                        strtotime(Configure::get('Blesta.cache_length')) - time(),
                        $cache_dir
                    );
                } catch (\Throwable $e) {
                    Configure::set('Caching.on', false);
                }
            }

            return (bool) $decision['valid'];
        } catch (\Throwable $e) {
            $log_exception($e);

            return $default();
        }
    }

    /**
     * Determines whether a single domain is available to register.
     *
     * Overrides RegistrarModule::checkAvailability (which returns true for every
     * domain) with a real WebNIC Get Domain (GET /domain/v2/query) read. The
     * contract is a plain bool — true = registerable, false = not — surfaced through
     * the WebnicDomains command group and never reading raw WebNIC JSON here (AR13).
     *
     * FR41 — never a false "taken". A bare false reads as "taken" to the Domain
     * Manager, so the ONLY false-with-no-error is a success envelope carrying
     * data.available === false. Every other non-success (transport failure, 5xx,
     * 401, business error) returns false WITH a module error via the side-channel:
     * retryable/indeterminate -> "temporarily unavailable; try again"; a terminal
     * DOM2400 -> the invalid-domain message (a legitimate "can't register this").
     * The method NEVER throws — a Throwable reaching the Domain Manager triggers a
     * lossy WHOIS fallback (domains_domains.php:764-767), so the API path is wrapped
     * in try/catch and a thrown error degrades to temporarily-unavailable + false.
     *
     * Row resolution fails closed (INV-1): an explicit invalid / wrong-company /
     * wrong-module id never substitutes another row's credentials; the first-row
     * fallback fires only when $module_row_id is null (mirrors getTlds()).
     *
     * @param string $domain The domain to check
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the domain is available, false otherwise
     */
    public function checkAvailability($domain, $module_row_id = null)
    {
        $endpoint = 'domain/v2/query';
        $unavailable = function (): bool {
            // FR41 side-channel: a transient/closed-row outcome must read as
            // "temporarily unavailable", never as a silent "taken".
            try {
                $this->Input->setErrors([
                    'availability' => [
                        'temporarily_unavailable' => Language::_('Webnic.!error.temporarily_unavailable', true),
                    ],
                ]);
            } catch (\Throwable $e) {
                // Even the side-channel must not turn availability into a thrown error.
            }

            return false;
        };
        $log_exception = function (\Throwable $e) use ($endpoint): void {
            try {
                $context = [
                    'error' => get_class($e),
                    'message' => $e->getMessage(),
                ];
                if (class_exists('\Webnic\Support\Redactor')) {
                    $context = \Webnic\Support\Redactor::scrub($context);
                }

                $this->log(
                    $endpoint,
                    serialize($context),
                    'output',
                    false
                );
            } catch (\Throwable $inner) {
                // Logging must never turn a read into a thrown error.
            }
        };

        try {
            if ($module_row_id !== null && (!is_numeric($module_row_id) || (int) $module_row_id <= 0)) {
                // Explicit invalid IDs must fail closed (INV-1) before getModuleRow()
                // takes the no-arg path and serves a preset/foreign row's credentials.
                return $unavailable();
            }

            $row = $this->getModuleRow($module_row_id);
            if ($row === false) {
                // Explicit id resolved to a missing/wrong-company/wrong-module row — fail
                // closed, never substitute a different row's credentials.
                return $unavailable();
            }

            if (empty($row) && $module_row_id === null) {
                // No-row consumer (storefront/admin availability dispatch with a null id);
                // mirror getTlds()' first-row fallback so the check is not blanked.
                $rows = $this->getModuleRows();
                $row = is_array($rows) ? ($rows[0] ?? null) : null;
            }

            if (empty($row)) {
                // No module row configured at all — cannot answer; never read as "taken".
                return $unavailable();
            }
        } catch (\Throwable $e) {
            $log_exception($e);

            return $unavailable();
        }

        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_api.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_domains.php');

            // Durable, row-scoped client (INV-1) with the real TokenStore (not
            // testConnection's throwaway store), so the bearer token persists across
            // the storefront's repeated availability reads instead of re-minting.
            $api = new \WebnicApi(
                $row->id,
                $row->meta->username,
                $row->meta->secret,
                $row->meta->environment,
                new \Webnic\TokenStore()
            );
            $response = (new \WebnicDomains($api))->queryDomain($domain);

            // The AC1/AC3 branch decision lives in the pure, unit-tested helper.
            $decision = \WebnicDomains::decideAvailability($response);

            if ($decision['log_failure']) {
                // A failed exchange is logged (request scrubbed via lastRequest()),
                // never silent — but logging must never turn the read into a throw.
                try {
                    $this->log($endpoint, serialize($api->lastRequest()), 'input', false);
                    $this->log($endpoint, serialize($response->body()), 'output', false);
                } catch (\Throwable $e) {
                    // Preserve the original availability decision even when Blesta's
                    // module logger is unavailable.
                }
            }

            if ($decision['class'] === 'available') {
                return true;
            }

            if ($decision['class'] === 'taken') {
                // The only false with no error: a success envelope, available:false.
                return false;
            }

            // retryable | indeterminate | terminal: false WITH a surfaced error so the
            // storefront never presents the domain as taken. error_key doubles as the
            // language-key suffix and the inner error key (DOM2400 or
            // temporarily_unavailable).
            $this->Input->setErrors([
                'availability' => [
                    $decision['error_key'] => Language::_('Webnic.!error.' . $decision['error_key'], true),
                ],
            ]);

            return false;
        } catch (\Throwable $e) {
            // Must never throw to the Domain Manager (a Throwable -> lossy WHOIS
            // fallback). No WebnicResponse exists here (Loader miss, client
            // construction, unexpected throw); log the localized message and degrade
            // to temporarily-unavailable + false.
            try {
                $this->log($endpoint, Language::_('Webnic.!error.temporarily_unavailable', true), 'output', false);
            } catch (\Throwable $inner) {
                // Logging must never turn a read into a thrown error.
            }
            $log_exception($e);

            return $unavailable();
        }
    }

    /**
     * Determines whether a single domain is eligible to transfer in.
     *
     * Overrides RegistrarModule::checkTransferAvailability (which defaults to the
     * inverse of checkAvailability) with WebNIC Query Transfer Type
     * (GET /domain/v2/query-transfer-type). The contract is a plain bool: true for
     * `registrar_transfer` / `reseller_transfer`, false for `domain_owner` and every
     * error path. Genuine errors surface through the `transfer` Input namespace so a
     * transient failure never looks like a silent eligibility answer.
     *
     * Row resolution and error handling mirror checkAvailability() exactly: explicit
     * invalid row ids fail closed, the first-row fallback is used only when no row id
     * is supplied, one durable TokenStore-backed client is built, and no Throwable is
     * allowed to escape to the Domain Manager.
     *
     * @param string $domain The domain to check
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return bool True if the domain is eligible for transfer, false otherwise
     */
    public function checkTransferAvailability($domain, $module_row_id = null)
    {
        $endpoint = 'domain/v2/query-transfer-type';
        $unavailable = function (): bool {
            try {
                $this->Input->setErrors([
                    'transfer' => [
                        'transfer_temporarily_unavailable' => Language::_(
                            'Webnic.!error.transfer_temporarily_unavailable',
                            true
                        ),
                    ],
                ]);
            } catch (\Throwable $e) {
                // Even the side-channel must not turn transfer eligibility into a thrown error.
            }

            return false;
        };
        $log_exception = function (\Throwable $e) use ($endpoint): void {
            try {
                $context = [
                    'error' => get_class($e),
                    'message' => $e->getMessage(),
                ];
                if (class_exists('\Webnic\Support\Redactor')) {
                    $context = \Webnic\Support\Redactor::scrub($context);
                }

                $this->log(
                    $endpoint,
                    serialize($context),
                    'output',
                    false
                );
            } catch (\Throwable $inner) {
                // Logging must never turn a read into a thrown error.
            }
        };

        try {
            if ($module_row_id !== null && (!is_numeric($module_row_id) || (int) $module_row_id <= 0)) {
                return $unavailable();
            }

            $row = $this->getModuleRow($module_row_id);
            if ($row === false) {
                return $unavailable();
            }

            if (empty($row) && $module_row_id === null) {
                $rows = $this->getModuleRows();
                $row = is_array($rows) ? ($rows[0] ?? null) : null;
            }

            if (empty($row)) {
                return $unavailable();
            }
        } catch (\Throwable $e) {
            $log_exception($e);

            return $unavailable();
        }

        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_api.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_domains.php');

            $api = new \WebnicApi(
                $row->id,
                $row->meta->username,
                $row->meta->secret,
                $row->meta->environment,
                new \Webnic\TokenStore()
            );
            $response = (new \WebnicDomains($api))->queryTransferType($domain);
            $decision = \WebnicDomains::decideTransferEligibility($response);

            if ($decision['log_failure']) {
                try {
                    $this->log($endpoint, serialize($api->lastRequest()), 'input', false);
                    $this->log($endpoint, serialize($response->body()), 'output', false);
                } catch (\Throwable $e) {
                    // Preserve the original transfer decision when module logging is unavailable.
                }
            }

            if ($decision['class'] === 'eligible') {
                return true;
            }

            if ($decision['class'] === 'owned') {
                return false;
            }

            $this->Input->setErrors([
                'transfer' => [
                    $decision['error_key'] => Language::_('Webnic.!error.' . $decision['error_key'], true),
                ],
            ]);

            return false;
        } catch (\Throwable $e) {
            try {
                $this->log(
                    $endpoint,
                    Language::_('Webnic.!error.transfer_temporarily_unavailable', true),
                    'output',
                    false
                );
            } catch (\Throwable $inner) {
                // Logging must never turn a read into a thrown error.
            }
            $log_exception($e);

            return $unavailable();
        }
    }

    /**
     * Determines availability for multiple domains with one resolved WebNIC client.
     *
     * WebNIC has no verified batch availability endpoint, so the safe batch size is 1:
     * this method loops the same GET /domain/v2/query read used by checkAvailability(),
     * but resolves the module row once, builds one durable TokenStore-backed client, and
     * folds every final response through WebnicDomains::decideBulkAvailability().
     *
     * The return contract is Blesta's plain domain => bool map. Genuine per-domain
     * errors are surfaced through one Input->setErrors() call after the loop so one
     * failed TLD never clobbers a sibling result or falls through as a silent "taken".
     *
     * @param array $domains List of domain names to check
     * @param int $module_row_id The ID of the module row to fetch for the current module
     * @return array Array of domain => availability (true/false) results
     */
    public function bulkCheckAvailability($domains, $module_row_id = null)
    {
        $endpoint = 'domain/v2/query';
        $fallback_results = function () use ($domains): array {
            $results = [];
            foreach ((array) $domains as $domain) {
                $results[$domain] = false;
            }

            return $results;
        };
        $set_domain_errors = function (array $errors_by_domain): void {
            if (empty($errors_by_domain)) {
                return;
            }

            $errors = ['availability' => []];
            foreach ($errors_by_domain as $domain => $error_key) {
                $errors['availability'][$domain] = Language::_('Webnic.!error.' . $error_key, true);
            }

            try {
                $this->Input->setErrors($errors);
            } catch (\Throwable $e) {
                // Even the side-channel must not turn bulk availability into a throw.
            }
        };
        $unavailable = function () use ($fallback_results, $set_domain_errors): array {
            $results = $fallback_results();
            $errors = [];
            foreach (array_keys($results) as $domain) {
                $errors[$domain] = 'temporarily_unavailable';
            }

            // An empty domain list has nothing to report; a phantom 'bulk' error would
            // surface a spurious storefront banner for a check-of-nothing. setErrors()
            // no-ops on an empty map, matching the happy path's empty-input behavior.
            $set_domain_errors($errors);

            return $results;
        };
        $log_exception = function (\Throwable $e) use ($endpoint): void {
            try {
                $context = [
                    'error' => get_class($e),
                    'message' => $e->getMessage(),
                ];
                if (class_exists('\Webnic\Support\Redactor')) {
                    $context = \Webnic\Support\Redactor::scrub($context);
                }

                $this->log(
                    $endpoint,
                    serialize($context),
                    'output',
                    false
                );
            } catch (\Throwable $inner) {
                // Logging must never turn a read into a thrown error.
            }
        };

        try {
            if ($module_row_id !== null && (!is_numeric($module_row_id) || (int) $module_row_id <= 0)) {
                // Explicit invalid IDs fail closed before getModuleRow() can serve a
                // preset/foreign row's credentials.
                return $unavailable();
            }

            $row = $this->getModuleRow($module_row_id);
            if ($row === false) {
                return $unavailable();
            }

            if (empty($row) && $module_row_id === null) {
                $rows = $this->getModuleRows();
                $row = is_array($rows) ? ($rows[0] ?? null) : null;
            }

            if (empty($row)) {
                return $unavailable();
            }
        } catch (\Throwable $e) {
            $log_exception($e);

            return $unavailable();
        }

        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_api.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_domains.php');

            $api = new \WebnicApi(
                $row->id,
                $row->meta->username,
                $row->meta->secret,
                $row->meta->environment,
                new \Webnic\TokenStore()
            );
            $domains_cmd = new \WebnicDomains($api);
            $responses = [];
            $requests = [];

            foreach ((array) $domains as $domain) {
                try {
                    $responses[$domain] = $domains_cmd->queryDomain($domain);
                    $requests[$domain] = $api->lastRequest();
                } catch (\Throwable $e) {
                    // Confine an unexpected per-domain throw to that domain so one failed
                    // read never clobbers already-resolved siblings (UX-DR14). submit()
                    // is exception-safe for transport, but lastRequest()/Redactor::scrub
                    // or an \Error could still escape; a synthetic retryable response
                    // folds to false + temporarily_unavailable through the same helper.
                    $responses[$domain] = new \WebnicResponse(null, 0, 'retryable');
                    $requests[$domain] = ['domain' => $domain];
                    $log_exception($e);
                }
            }

            $decision = \WebnicDomains::decideBulkAvailability($responses);

            foreach ($decision['log'] as $domain => $log_failure) {
                if (!$log_failure || !isset($responses[$domain])) {
                    continue;
                }

                try {
                    $this->log($endpoint, serialize($requests[$domain] ?? $api->lastRequest()), 'input', false);
                    $this->log($endpoint, serialize($responses[$domain]->body()), 'output', false);
                } catch (\Throwable $e) {
                    // Preserve the original availability decision even when logging fails.
                }
            }

            $set_domain_errors($decision['errors']);

            return $decision['availability'];
        } catch (\Throwable $e) {
            try {
                $this->log($endpoint, Language::_('Webnic.!error.temporarily_unavailable', true), 'output', false);
            } catch (\Throwable $inner) {
                // Logging must never turn a read into a thrown error.
            }
            $log_exception($e);

            return $unavailable();
        }
    }

    /**
     * Returns the package-configuration fields exposing the registrar package defaults (AC1/FR38).
     *
     * The five FR38 package items, mirroring the bundled-registrar idiom
     * (logicboxes.php:974, opensrs.php:725): TLD/extension selection (from the cache-first,
     * INV-1-scoped getTlds() catalogue), default term, ID-protection default, nameserver
     * defaults, and DNS-management default. Bound values pre-fill from $vars->meta on edit.
     *
     * @param stdClass|null $vars A stdClass object representing a set of post fields
     * @return ModuleFields The package configuration fields
     */
    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);

        // Normalize so $vars->meta[...] reads are always safe (edit pre-fill or fresh add).
        if (!is_object($vars)) {
            $vars = new \stdClass();
        }
        if (!isset($vars->meta) || !is_array($vars->meta)) {
            $vars->meta = [];
        }

        $fields = new ModuleFields();

        // TLD/extension selection — checkbox list from the data-driven catalogue (FR38/§G).
        // Source ONLY through getTlds() (cache-first, INV-1-scoped); never a second path.
        $tld_options = $fields->label(Language::_('Webnic.package_fields.tld_options', true));
        $tlds = $this->getTlds();
        sort($tlds);
        $selected_tlds = isset($vars->meta['tlds']) && is_array($vars->meta['tlds']) ? $vars->meta['tlds'] : [];
        foreach ($tlds as $tld) {
            $tld_id = 'webnic_pkg_tld_' . $this->safeFieldToken($tld);
            $tld_label = $fields->label($tld, $tld_id);
            $tld_options->attach(
                $fields->fieldCheckbox('meta[tlds][]', $tld, in_array($tld, $selected_tlds, true), ['id' => $tld_id], $tld_label)
            );
        }
        $fields->setField($tld_options);

        // Default term (years).
        $term = $fields->label(Language::_('Webnic.package_fields.term', true), 'webnic_pkg_term');
        $term_options = [];
        foreach (range(1, 10) as $year) {
            $term_options[$year] = $year;
        }
        $term->attach($fields->fieldSelect('meta[term]', $term_options, ($vars->meta['term'] ?? 1), ['id' => 'webnic_pkg_term']));
        $term->attach($fields->tooltip(Language::_('Webnic.package_fields.tooltip.term', true)));
        $fields->setField($term);

        // ID-protection default.
        $id_protection = $fields->label(Language::_('Webnic.package_fields.id_protection', true), 'webnic_pkg_id_protection');
        $id_protection->attach(
            $fields->fieldCheckbox('meta[id_protection]', '1', !empty($vars->meta['id_protection']), ['id' => 'webnic_pkg_id_protection'])
        );
        $id_protection->attach($fields->tooltip(Language::_('Webnic.package_fields.tooltip.id_protection', true)));
        $fields->setField($id_protection);

        // Nameserver defaults (ns1..ns5 stored as the meta[ns][] array the order form reads).
        for ($i = 1; $i <= 5; $i++) {
            $ns = $fields->label(Language::_('Webnic.package_fields.ns', true, $i), 'webnic_pkg_ns' . $i);
            $ns->attach(
                $fields->fieldText('meta[ns][]', ($vars->meta['ns'][$i - 1] ?? null), ['id' => 'webnic_pkg_ns' . $i])
            );
            $fields->setField($ns);
        }

        // DNS-management default.
        $dns = $fields->label(Language::_('Webnic.package_fields.dns_management', true), 'webnic_pkg_dns_management');
        $dns->attach(
            $fields->fieldCheckbox('meta[dns_management]', '1', !empty($vars->meta['dns_management']), ['id' => 'webnic_pkg_dns_management'])
        );
        $dns->attach($fields->tooltip(Language::_('Webnic.package_fields.tooltip.dns_management', true)));
        $fields->setField($dns);

        return $fields;
    }

    /**
     * Returns the admin add-service order fields (AC2/AC3/AC4).
     *
     * @param stdClass $package The selected package
     * @param stdClass|null $vars A stdClass object representing a set of post fields
     * @return ModuleFields The order fields (domain editable for admin) + per-TLD fieldset
     */
    public function getAdminAddFields($package, $vars = null)
    {
        return $this->buildOrderFields($package, $vars, false);
    }

    /**
     * Returns the client add-service order fields (AC2/AC3/AC4).
     *
     * @param stdClass $package The selected package
     * @param stdClass|null $vars A stdClass object representing a set of post fields
     * @return ModuleFields The order fields (domain hidden for client) + per-TLD fieldset
     */
    public function getClientAddFields($package, $vars = null)
    {
        return $this->buildOrderFields($package, $vars, true);
    }

    /**
     * Returns the admin edit-service fields (AC2 — minimal).
     *
     * The order is already placed; editing term/NS/contacts post-registration has no
     * registry effect in MVP, so this NEVER re-collects provisioning input. It exposes at
     * most a read-only domain reference for the operator.
     *
     * @param stdClass $package The selected package
     * @param stdClass|null $vars A stdClass object representing a set of post fields
     * @return ModuleFields A minimal, read-only reference (never provisioning input)
     */
    public function getAdminEditFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Html']);
        if (!is_object($vars)) {
            $vars = new \stdClass();
        }

        $fields = new ModuleFields();
        $domain = $fields->label(Language::_('Webnic.service_field.domain', true), 'webnic_domain');
        $domain->attach(
            $fields->fieldText('domain', ($vars->domain ?? null), ['id' => 'webnic_domain', 'readonly' => 'readonly'])
        );
        $fields->setField($domain);

        return $fields;
    }

    /**
     * Builds the shared add-service order fields for the admin and client variants (AC2-AC4).
     *
     * @param stdClass $package The selected package
     * @param stdClass|null $vars The post fields
     * @param bool $is_client True for the client storefront variant (domain hidden)
     * @return ModuleFields The base order fields plus any per-TLD requirements fieldset
     */
    private function buildOrderFields($package, $vars, bool $is_client)
    {
        Loader::loadHelpers($this, ['Html']);
        if (!is_object($vars)) {
            $vars = new \stdClass();
        }

        // Transfer order fields (WN-4-1 AC7). A truthy `transfer` flag OR a non-empty
        // `auth-code` means a transfer-in: render the EPP input instead of the register-only
        // NS / per-TLD-fieldset surface. Truthiness, not bare isset — transfer='0' is a register.
        $auth_code = $vars->{'auth-code'} ?? null;
        if (self::isTruthyFlag($vars->transfer ?? null) || (is_string($auth_code) && trim($auth_code) !== '')) {
            return $this->transferOrderFields($package, $vars, $is_client);
        }

        $fields = $this->baseOrderFields($package, $vars, $is_client);

        // Per-TLD registry requirements (AC3/AC4). Server-driven re-render: this builder is
        // a pure function of $vars->domain; Blesta re-invokes it on the order form refresh,
        // so changing the TLD re-derives the extension and rebuilds the group (no bespoke
        // SPA / no setHtml JS needed).
        return $this->appendTldFieldset($fields, $vars);
    }

    /**
     * Builds the transfer-in order fields: domain + the EPP/auth-code input (WN-4-1 AC7).
     *
     * Mirrors Logicboxes.transfer_fields (logicboxes/config/logicboxes.php:84): a transfer
     * collects the EPP/auth code, NOT the register-only nameserver inputs or the per-TLD
     * requirements fieldset (transfer-in carries neither — the captured body has no NS/hosts and
     * surfaces no register-time tld_req). The four contacts + registrant are sourced from the
     * Blesta client record in buildSagaContext (the same client-record sourcing as register), so
     * no contact fieldset is rendered here. The EPP input field is shown (the customer types it);
     * the STORED auth code is never echoed back on screen (UX-DR3 — email-only, Epic 5).
     *
     * @param stdClass $package The selected package
     * @param stdClass $vars The post fields
     * @param bool $is_client True for the client variant (domain hidden)
     * @return ModuleFields The transfer order fields
     */
    private function transferOrderFields($package, $vars, bool $is_client)
    {
        $fields = new ModuleFields();

        // Domain — hidden for the client (the storefront already fixed it, mirror
        // logicboxes.php:1151); a locked reference for the admin.
        if ($is_client) {
            $fields->setField($fields->fieldHidden('domain', ($vars->domain ?? null), ['id' => 'webnic_domain']));
        } else {
            $domain = $fields->label(Language::_('Webnic.service_field.domain', true), 'webnic_domain');
            $domain->attach(
                $fields->fieldText('domain', ($vars->domain ?? null), ['id' => 'webnic_domain', 'readonly' => 'readonly'])
            );
            $fields->setField($domain);
        }

        // Keep the transfer indicator on the form so the post round-trips through a re-render.
        $fields->setField($fields->fieldHidden('transfer', '1', ['id' => 'webnic_transfer']));

        // The EPP / auth-code input (mirror Logicboxes.transfer_fields). A SECRET (AC2/UX-DR3):
        // rendered as a password-type input (never shoulder-surfable plain text) and NEVER
        // repopulated — the value is passed as null so a submitted EPP is not echoed back into the
        // HTML on a validation re-render. The server still receives it on (re)submit; it is just
        // never written to screen (email-only beyond entry, Epic-5 FR33). WN-4-1 round-1 P2.
        $auth = $fields->label(Language::_('Webnic.order_field.auth_code', true), 'webnic_auth_code');
        $auth->attach(
            $fields->fieldPassword('auth-code', ['id' => 'webnic_auth_code'])
        );
        $fields->setField($auth);

        return $fields;
    }

    /**
     * Builds the base order fields: domain, ns1..ns5, and the ID-protection selection (AC2).
     *
     * Contacts are NOT rendered here — they are sourced from the Blesta client record in
     * buildSagaContext() (webnic.php:1662), so FR38's "contacts" is satisfied by the
     * client-record sourcing the saga already does. Do NOT add a contact fieldset (Dev
     * Notes §G); admin contact-override would be a scoped, separate addition.
     *
     * @param stdClass $package The selected package
     * @param stdClass $vars The post fields
     * @param bool $is_client True for the client variant (domain hidden)
     * @return ModuleFields The base order fields
     */
    private function baseOrderFields($package, $vars, bool $is_client)
    {
        $fields = new ModuleFields();

        // Domain — hidden for the client (the storefront already fixed it, mirror
        // logicboxes.php:1151); editable for the admin.
        if ($is_client) {
            $fields->setField($fields->fieldHidden('domain', ($vars->domain ?? null), ['id' => 'webnic_domain']));
        } else {
            $domain = $fields->label(Language::_('Webnic.service_field.domain', true), 'webnic_domain');
            $domain->attach($fields->fieldText('domain', ($vars->domain ?? null), ['id' => 'webnic_domain']));
            $fields->setField($domain);
        }

        // Nameservers — default-fill from the package NS defaults when none was submitted
        // (mirror logicboxes.php:1064-1071). Term is pricing-driven, NOT a module field:
        // Blesta's pricing selector owns it (resolveTerm reads pricing_id); we only validate
        // the resolved term (T7), never render a competing term <select>.
        // Default-fill nameservers from the package ONLY when (a) the customer supplied none
        // at all — never clobber a partially-filled set — and (b) the package itself provides
        // at least TWO non-blank defaults. A single package default would pre-fill exactly one
        // field, which the min-2 gate then rejects as an under-supply, making the package
        // un-orderable; in that case leave the fields blank so the customer supplies none and
        // resolveNameservers falls back to the >=2 WebNIC shared defaults.
        $customer_supplied_ns = false;
        for ($i = 1; $i <= 5; $i++) {
            $existing = $vars->{'ns' . $i} ?? null;
            if (is_scalar($existing) && trim((string) $existing) !== '') {
                $customer_supplied_ns = true;
                break;
            }
        }
        if (!$customer_supplied_ns && isset($package->meta->ns)) {
            $defaults = [];
            foreach ((array) $package->meta->ns as $ns) {
                if (is_scalar($ns) && trim((string) $ns) !== '') {
                    $defaults[] = $ns;
                }
            }
            if (count($defaults) >= 2) {
                $i = 1;
                foreach ($defaults as $ns) {
                    if ($i > 5) {
                        break;
                    }
                    $vars->{'ns' . $i++} = $ns;
                }
            }
        }
        for ($i = 1; $i <= 5; $i++) {
            $ns = $fields->label(Language::_('Webnic.service_field.ns', true, $i), 'webnic_ns' . $i);
            $ns->attach($fields->fieldText('ns' . $i, ($vars->{'ns' . $i} ?? null), ['id' => 'webnic_ns' . $i]));
            $fields->setField($ns);
        }

        // ID-protection selection. Seed the checkbox from the package default
        // (meta[id_protection]) when the order itself has not set a value — mirror the NS
        // default-fill — so a package configured "enable ID protection by default" pre-checks
        // the box on a fresh order. Once the customer has interacted (the var is present),
        // honour their choice.
        $id_protection_checked = isset($vars->id_protection)
            ? !empty($vars->id_protection)
            : !empty($package->meta->id_protection ?? null);
        $id_protection = $fields->label(Language::_('Webnic.service_field.id_protection', true), 'webnic_id_protection');
        $id_protection->attach(
            $fields->fieldCheckbox('id_protection', '1', $id_protection_checked, ['id' => 'webnic_id_protection'])
        );
        $id_protection->attach($fields->tooltip(Language::_('Webnic.service_field.tooltip.id_protection', true)));
        $fields->setField($id_protection);

        return $fields;
    }

    /**
     * Appends the per-TLD "Registry requirements" fieldset to the order fields (AC3/AC4).
     *
     * Renders a legend heading "Registry requirements for .{tld}" followed by each surfaced
     * requirement as a labeled field with explicit aria-described help. An
     * EMPTY descriptor set appends NOTHING — no legend, no fields — so a well-known TLD
     * leaves no empty box in the DOM (AC3). Fail-open: any rule-read failure yields an empty
     * descriptor set, so the base order fields render unimpeded (never block a legitimate
     * order on a transient registry read).
     *
     * @param ModuleFields $fields The base order fields to append to
     * @param stdClass $vars The post fields (carrying ->domain and any ->tld_req values)
     * @return ModuleFields The fields, with the per-TLD group appended when non-empty
     */
    private function appendTldFieldset($fields, $vars)
    {
        $domain = isset($vars->domain) ? trim((string) $vars->domain) : '';
        if ($domain === '') {
            return $fields;
        }

        $descriptors = $this->buildTldDescriptors($domain);
        if (empty($descriptors)) {
            return $fields;
        }

        $tld = $this->domainExtension($domain);
        $tld = mb_check_encoding($tld, 'ASCII') ? strtolower($tld) : $tld;

        // Submitted per-TLD values (re-render pre-fill). Blesta may pass tld_req as an array
        // or an object depending on the form path; normalize to an array.
        $submitted = [];
        if (isset($vars->tld_req)) {
            $submitted = is_array($vars->tld_req) ? $vars->tld_req : (array) $vars->tld_req;
        }

        // Legend heading (the titled group). A bare label renders as the section title.
        $fields->setField($fields->label(Language::_('Webnic.tld_fieldset.legend', true, $tld)));

        $help_html = '';
        foreach ($descriptors as $descriptor) {
            // Encode the (possibly unknown / metacharacter-bearing) rule key into a safe,
            // collision-free token for the field id, the POST array key, and the re-render
            // value lookup (P7). The gate + persist paths encode the same way so they match.
            $token = $this->safeFieldToken($descriptor['name']);
            $field_id = 'webnic_tld_' . $token;
            $name = 'tld_req[' . $token . ']';
            $current = $submitted[$token] ?? null;
            $help_id = $field_id . '_help';
            $field_attrs = ['id' => $field_id];
            if ($descriptor['help'] !== '') {
                $field_attrs['aria-describedby'] = $help_id;
                $help_html .= '<div id="' . htmlspecialchars($help_id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '" class="visually-hidden sr-only webnic-tld-help">'
                    . htmlspecialchars($descriptor['help'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</div>' . "\n";
            }

            $label = $fields->label($descriptor['label'], $field_id);
            switch ($descriptor['field_type']) {
                case 'tickbox':
                    $label->attach($fields->fieldCheckbox($name, '1', !empty($current), $field_attrs));
                    break;
                case 'select':
                    $options = [];
                    if (is_array($descriptor['value'])) {
                        foreach ($descriptor['value'] as $option) {
                            $options[(string) $option] = (string) $option;
                        }
                    }
                    $label->attach($fields->fieldSelect($name, $options, $current, $field_attrs));
                    break;
                case 'text':
                default:
                    $label->attach($fields->fieldText($name, ($current === null ? '' : $current), $field_attrs));
                    break;
            }
            if ($descriptor['help'] !== '') {
                $label->attach($fields->tooltip($descriptor['help']));
            }
            $fields->setField($label);
        }
        if ($help_html !== '') {
            $existing_html = method_exists($fields, 'getHtml') ? (string) $fields->getHtml() : '';
            $fields->setHtml($existing_html . $help_html);
        }

        return $fields;
    }

    /**
     * Builds the surfaced per-TLD requirement descriptors for a domain (AC3/AC4).
     *
     * Single source consumed by BOTH the renderer (appendTldFieldset) and the
     * pre-submission validation gate (validateRegistrationInput) so they can never drift.
     * Fetches the registration rules cache-first (fail-open), maps them through the WN-2-6
     * transform, and partitions them via the pure TldFieldset presenter + the suppress set.
     *
     * @param string $domain The domain (raw or normalized; the extension is normalized here)
     * @param stdClass|null $row The provisioning module row to scope the rule read to; null
     *  resolves it from context (the renderer path, which has no explicit provisioning row)
     * @return array Render-ready descriptors (empty => no fieldset)
     */
    private function buildTldDescriptors(string $domain, $row = null): array
    {
        $ext = $this->domainExtension(trim($domain));
        if ($ext === '') {
            return [];
        }

        $rules = $this->fetchRegistrationRules($ext, $row);
        if (empty($rules)) {
            return [];
        }

        Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_pricing.php');
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_tld_fieldset.php');

        $rule_fields = \WebnicPricing::mapExtensionRuleFields($rules);
        // Runtime promotion is data-driven by ABSENCE from the suppress set — a key not in
        // suppress is surfaced. The companion `Webnic.tld_rule_fieldset_promoted` set is NOT
        // consulted here: it is a CI guard-only declaration (WebnicConfigContractTest asserts
        // every known key is suppressed OR promoted, §C); to promote a key, move it from
        // suppress to promoted (removing it from suppress is what actually surfaces it).
        $suppress = Configure::get('Webnic.tld_rule_fieldset_suppress') ?: [];

        return \Webnic\TldFieldset::build($rule_fields, $suppress, $this->tldFieldLabel(), $this->tldFieldHelp());
    }

    /**
     * Fetches the WebNIC registration extension rules for an extension, cache-first.
     *
     * Mirrors the isValidTerm() idiom exactly (cache key, INV-1 row scope, fail-open): a
     * warm cache short-circuits; a miss runs Get Extensions Rule; any failure (missing row,
     * non-success, transport error, throw) returns null (no fieldset) after a scrubbed log.
     * Never throws to the form.
     *
     * @param string $ext The dotless extension key
     * @param stdClass|null $row The provisioning module row to scope to; null resolves from
     *  context (the renderer path). The gate passes the row it already resolved for THIS
     *  package so the cache key + credentials are scoped to it (INV-1).
     * @return array|null The raw data.rules map, or null on any fail-open path
     */
    private function fetchRegistrationRules(string $ext, $row = null): ?array
    {
        $endpoint = 'domain/v2/ext-rules';
        try {
            $ext = ltrim(trim($ext), '.');
            $ext = mb_check_encoding($ext, 'ASCII') ? strtolower($ext) : $ext;
            if ($ext === '') {
                return null;
            }

            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_api.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_pricing.php');

            // Prefer the caller-supplied provisioning row (gate path); else resolve from
            // context (renderer path), mirroring the getTlds()/isValidTerm() idiom.
            if (empty($row)) {
                $row = $this->getModuleRow();
                if (empty($row)) {
                    $rows = $this->getModuleRows();
                    $row = is_array($rows) ? ($rows[0] ?? null) : null;
                }
            }
            if (empty($row)) {
                // Fail open, but NOT silently (T5): record the missing-row read so a
                // misconfigured install is traceable. Carries no secret (no row/credentials).
                try {
                    $this->log($endpoint, serialize(['error' => 'no_module_row', 'ext' => $ext]), 'output', false);
                } catch (\Throwable $e) {
                    // Logging must never turn a rule read into a thrown error.
                }

                return null;
            }

            $cache_key = 'ext_rule_registration_' . (int) $row->id . '_' . $ext;
            $cache_dir = Configure::get('Blesta.company_id') . DS . 'modules' . DS . 'webnic' . DS;

            $cache = Cache::fetchCache($cache_key, $cache_dir);
            if ($cache) {
                $decoded = base64_decode($cache, true);
                $cached_body = $decoded === false ? false : safe_unserialize($decoded);
                if (is_array($cached_body)) {
                    $cached_response = new \WebnicResponse($cached_body, 200);
                    if ($cached_response->success()) {
                        $cached_rules = $this->rulesFromResponse($cached_response);
                        if ($cached_rules !== null) {
                            return $cached_rules;
                        }
                        // Cached success but no usable data.rules map (malformed/partial body):
                        // don't serve null for the whole cache lifetime — fall through to a
                        // live read, which self-heals the cache on the rewrite below.
                    }
                }
            }

            $api = new \WebnicApi(
                $row->id,
                $row->meta->username,
                $row->meta->secret,
                $row->meta->environment,
                new \Webnic\TokenStore()
            );
            $response = (new \WebnicPricing($api))->getExtensionRule($ext, 'registration');

            if (!$response->success()) {
                try {
                    // INV-7/INV-9: never log a raw provider envelope. Scrub the request and
                    // response bodies through the WebNIC redactor (mirrors the catch path below
                    // and logServiceInfoException) so a capability-read failure cannot dump
                    // credentials/tokens/secrets from the ext-rules response.
                    Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
                    $request = $api->lastRequest();
                    $body = $response->body();
                    if (class_exists('\Webnic\Support\Redactor')) {
                        $request = \Webnic\Support\Redactor::scrub(is_array($request) ? $request : ['request' => $request]);
                        $body = \Webnic\Support\Redactor::scrub(is_array($body) ? $body : ['body' => $body]);
                    }
                    $this->log($endpoint, serialize($request), 'input', false);
                    $this->log($endpoint, serialize($body), 'output', false);
                } catch (\Throwable $e) {
                    // Logging must never turn a rule read into a thrown error.
                }

                return null;
            }

            $rules = $this->rulesFromResponse($response);

            // Normalize the body we cache. When a success envelope carries NO usable data.rules
            // map (a malformed/partial body, or a TLD whose success response legitimately omits
            // rules), force data.rules to [] before caching so the NEXT read short-circuits to
            // [] (rulesFromResponse returns a non-null empty array) instead of falling through
            // to a live read on EVERY render. Without this the cached success-but-null body
            // would re-trigger the fall-through forever, hammering the API for the cache
            // lifetime. Net: at most ONE refetch per cache cycle to self-heal a transient
            // malformed entry, then it settles. (round-2 kimi)
            $cache_body = $response->body();
            if ($rules === null) {
                if (!is_array($cache_body)) {
                    $cache_body = ['code' => '1000'];
                }
                if (!isset($cache_body['data']) || !is_array($cache_body['data'])) {
                    $cache_body['data'] = [];
                }
                $cache_body['data']['rules'] = [];
                $rules = [];
            }

            if (Configure::get('Caching.on') && is_writable(CACHEDIR)) {
                try {
                    Cache::writeCache(
                        $cache_key,
                        base64_encode(serialize($cache_body)),
                        strtotime(Configure::get('Blesta.cache_length')) - time(),
                        $cache_dir
                    );
                } catch (\Throwable $e) {
                    Configure::set('Caching.on', false);
                }
            }

            return $rules;
        } catch (\Throwable $e) {
            try {
                $context = ['error' => get_class($e), 'message' => $e->getMessage()];
                if (class_exists('\Webnic\Support\Redactor')) {
                    $context = \Webnic\Support\Redactor::scrub($context);
                }
                $this->log($endpoint, serialize($context), 'output', false);
            } catch (\Throwable $inner) {
                // Logging must never turn a rule read into a thrown error.
            }

            return null;
        }
    }

    /**
     * Extracts the raw data.rules map from a successful extension-rule response.
     *
     * @param WebnicResponse $resp A successful extension-rule response
     * @return array|null The data.rules map, or null when absent/malformed
     */
    private function rulesFromResponse(\WebnicResponse $resp): ?array
    {
        $data = $resp->data();
        $rules = is_array($data) ? ($data['rules'] ?? null) : null;

        return is_array($rules) ? $rules : null;
    }

    /**
     * Returns the per-TLD field label resolver (AC4: unknown rules labeled from the key).
     *
     * @return callable fn(string $ruleKey): string
     */
    private function tldFieldLabel(): callable
    {
        return function (string $key): string {
            $lang_key = 'Webnic.tld_fieldset.field.' . $key;
            $translated = Language::_($lang_key, true);
            if ($translated === $lang_key || $translated === '' || $translated === null) {
                return $this->humanizeRuleKey($key);
            }

            return $translated;
        };
    }

    /**
     * Returns the per-TLD inline-help resolver (UX-DR16: every surfaced field has help).
     *
     * @return callable fn(string $ruleKey): string
     */
    private function tldFieldHelp(): callable
    {
        return function (string $key): string {
            $lang_key = 'Webnic.tld_fieldset.field_help.' . $key;
            $translated = Language::_($lang_key, true);
            if ($translated === $lang_key || $translated === '' || $translated === null) {
                return Language::_('Webnic.tld_fieldset.field_help_generic', true);
            }

            return $translated;
        };
    }

    /**
     * Humanizes a camelCase WebNIC rule key into a readable label (AC4 fallback).
     *
     * @param string $key The raw rule key (e.g. "registrantType")
     * @return string A spaced, title-cased label (e.g. "Registrant Type")
     */
    private function humanizeRuleKey(string $key): string
    {
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_order_input.php');

        return \Webnic\OrderInput::humanizeRuleKey($key);
    }

    /**
     * Encodes an arbitrary key into a collision-free, form-safe token (WN-3-4b P7).
     *
     * Form field names/ids and POST array keys must be [A-Za-z0-9_]-safe and injective: an
     * unknown WebNIC rule key (AC4) or an IDN TLD can carry brackets, quotes, dots, or
     * non-ASCII bytes that would reshape the POST body or collapse two distinct keys onto the
     * same sanitized id. Keys that are already safe stay readable (prefixed 's_'); anything
     * else is hex-encoded ('h_' + bin2hex). The two prefixes keep the namespaces disjoint and
     * the mapping is reversible (strip 's_', or hex2bin after 'h_'), so the raw key is always
     * recoverable for labels/validation/persistence.
     *
     * @param string $key The raw key (a rule key or a TLD)
     * @return string A safe, injective token usable as a field name/id and POST array key
     */
    private function safeFieldToken(string $key): string
    {
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_order_input.php');

        return \Webnic\OrderInput::safeFieldToken($key);
    }

    /**
     * Pre-submission validation gate for a module-provisioned registration order (AC3/INV-4).
     *
     * Runs at the TOP of addService BEFORE any order is opened or any saga API call is made,
     * so a failure returns false + an Input error LEGITIMATELY (the order never reached an
     * async-pending state — INV-4 governs only the post-saga path, Dev Notes §F). Validates:
     * term 1-10 (reuse isValidTerm), min-2 nameservers (0 -> package/WebNIC defaults via
     * resolveNameservers; 1 -> under-supply rejected), and each surfaced required per-TLD
     * requirement present (same descriptors the form rendered, via buildTldDescriptors).
     *
     * @param stdClass $package The package being provisioned
     * @param array $vars The provisioning input
     * @param stdClass $row The resolved module row
     * @param string $domain The normalized domain
     * @return bool True when input is valid; false (with Input errors set) otherwise
     */
    private function validateRegistrationInput($package, array $vars, $row, string $domain, bool $is_transfer = false): bool
    {
        $errors = [];
        $ext = $this->domainExtension($domain);

        // Term 1-10 (+ rule-refined). isValidTerm short-circuits >10/<1 with no API call.
        // WN-4-1: a transfer validates the term against the `rertransfer` rule arm (transfer=true),
        // a register against the registration arm (transfer=false).
        $term = $this->resolveTerm($package, $vars);
        if (!$this->isValidTerm($ext, $term, $is_transfer)) {
            // Term is pricing-driven (Blesta's pricing selector, not a module field), so this
            // has no rendered input to bind to and stays a summary-level message by design.
            $errors['term']['invalid'] = Language::_('Webnic.!error.term_invalid', true);
        }

        // WN-4-1: transfer-in mirrors the renderer's transfer branch — it carries no nameserver
        // inputs and surfaces no register-time per-TLD requirements (the captured transfer body has
        // neither), so the register-only NS-min-2 and tld_req checks are skipped. The EPP (auth-code)
        // is validated by the saga submit itself (a bad authInfo is a registry reject, not a form rule).
        if ($is_transfer) {
            // The EPP/auth code must be PRESENT (a non-empty scalar) BEFORE any order is opened or
            // any contact/registrant API write happens — a blank/missing code would otherwise create
            // durable state and burn a submit only to be rejected by the registry (Round-1 P8). This
            // is a PRESENCE check, not correctness: a wrong-but-present authInfo is still a registry
            // reject at submit (AC7), never a form rule. Bound to the rendered auth-code field.
            $auth = $vars['auth-code'] ?? null;
            if (!is_scalar($auth) || trim((string) $auth) === '') {
                $errors['auth-code']['required'] = Language::_('Webnic.!error.transfer_auth_code_required', true);
            }

            if (!empty($errors)) {
                try {
                    $this->Input->setErrors($errors);
                } catch (\Throwable $e) {
                    // The error side-channel must not throw out of provisioning.
                }

                return false;
            }

            return true;
        }

        // Min-2 nameservers. A customer who supplies NONE gets the package/WebNIC defaults
        // (resolveNameservers); supplying exactly ONE is an under-supply the form rejects so
        // a half-filled set is never silently replaced by defaults.
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_order_input.php');

        // Min-2 nameservers: count only NON-BLANK entries (a whitespace-only value is not a
        // nameserver and would otherwise diverge from resolveNameservers' trimmed effective
        // set). 0 supplied -> package/WebNIC defaults; exactly 1 -> under-supply rejected.
        $supplied = \Webnic\OrderInput::countSuppliedNs($vars);
        if ($supplied === 1) {
            // Bind the min-2 error to the first BLANK nameserver slot (UX-DR16 per-field
            // binding) so Blesta highlights a field the customer should fill, not the one they
            // already supplied. The key matches the rendered field name ns1..ns5; falls back to
            // ns2 (the minimum second nameserver).
            $ns_target = 'ns2';
            for ($i = 1; $i <= 5; $i++) {
                $ns = $vars['ns' . $i] ?? null;
                if (!is_scalar($ns) || trim((string) $ns) === '') {
                    $ns_target = 'ns' . $i;
                    break;
                }
            }
            $errors[$ns_target]['min'] = Language::_('Webnic.!error.nameservers_min', true);
        }

        // Each surfaced required per-TLD requirement must be present (AC3/AC4). Tickboxes are
        // inherently optional (unchecked = absent); required text/select fields must carry a
        // value. Uses the SAME descriptors the renderer produced (single source).
        // Each surfaced required per-TLD requirement must be present (AC3/AC4), using the SAME
        // descriptors the renderer produced (single source) and the same form-token lookup.
        // Tickboxes are inherently optional; a whitespace-only value counts as absent. Key each
        // error by the rendered field name tld_req[<token>] so Blesta binds it to the input
        // (per-field highlight / aria-describedby — UX-DR16), not just the generic summary.
        $submitted = isset($vars['tld_req']) && is_array($vars['tld_req']) ? $vars['tld_req'] : [];
        $descriptors = $this->buildTldDescriptors($domain, $row);
        foreach (\Webnic\OrderInput::findMissingRequired($descriptors, $submitted) as $token) {
            $errors['tld_req[' . $token . ']']['required'] = Language::_('Webnic.!error.tld_requirement_missing', true);
        }

        if (!empty($errors)) {
            try {
                $this->Input->setErrors($errors);
            } catch (\Throwable $e) {
                // The error side-channel must not throw out of provisioning.
            }

            return false;
        }

        return true;
    }

    /**
     * Provisions a domain registration through the money-safe saga (FR14-18a/INV-4).
     *
     * Overrides the base no-op. When provisioning via the module, this opens the
     * durable intent first, then delegates the contacts -> registrant -> >=2 hosts ->
     * Register saga to Webnic\Saga\RegistrationSaga and maps its result onto Blesta's
     * return contract: the complete service-field array on success (active or
     * registrar_pending), or false + a localized Input error ONLY on a definitive
     * failed outcome. An async-pending order returns success (never an Input error),
     * so Blesta does not trip incrementServiceAttempt and double-register (INV-4).
     *
     * @param stdClass $package The package being provisioned
     * @param array $vars The provisioning input (client_id, pricing_id, domain,
     *  use_module, ns1..nsN, configoptions)
     * @param stdClass $parent_package The parent package, if an addon
     * @param stdClass $parent_service The parent service, if an addon
     * @param string $status The status of the service being added
     * @return array|false The complete service-field array, or false on failure
     */
    public function addService(
        $package,
        array $vars = null,
        $parent_package = null,
        $parent_service = null,
        $status = 'pending'
    ) {
        $vars = $vars ?: [];
        $run_id = uniqid('wn_', true);

        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');

        $row = $this->resolveProvisionRow($package);
        $domain = isset($vars['domain']) ? \Webnic\Orders::normalizeDomain((string) $vars['domain']) : '';

        // Not provisioning via the module (or no row/domain): record the domain field
        // only, exactly like the bundled registrars when use_module is off.
        if (empty($row) || $domain === '' || ($vars['use_module'] ?? null) !== 'true') {
            return [['key' => 'domain', 'value' => $vars['domain'] ?? $domain, 'encrypted' => 0]];
        }

        // Transfer-in (WN-4-1) is an ADDITIVE second operation: a truthy `transfer` flag or an
        // `auth-code` (EPP) present dispatches the TransferSaga instead of the RegistrationSaga.
        // Truthiness, not bare isset — the order form posts transfer='0' for a register (AC1/§K).
        $is_transfer = $this->isTransferOrder($vars);
        $operation = $is_transfer ? \Webnic\Orders::OPERATION_TRANSFER : \Webnic\Orders::OPERATION_REGISTER;

        // Pre-submission validation gate (WN-3-4b AC3/T7; WN-4-1 transfer-aware). Runs BEFORE any
        // order is opened or any saga API call — a failure returns false + an Input error legitimately
        // because the order never reached an async-pending state (INV-4 governs only the
        // post-saga path, Dev Notes §F). Fail-open inside; never throws to provisioning.
        try {
            if (!$this->validateRegistrationInput($package, $vars, $row, $domain, $is_transfer)) {
                return false;
            }
        } catch (\Throwable $e) {
            // FAIL CLOSED. The gate's whole purpose is to reject bad input BEFORE any order
            // is opened; an unexpected throw here is NOT the "transient registry read" the
            // fieldset fail-open targets (those are caught inside fetchRegistrationRules and
            // yield an empty descriptor set, never an exception that escapes here). Letting
            // execution fall through to the saga would provision UNVALIDATED input, so reject
            // pre-saga: log (scrubbed) + a non-leaking Input error + return false. Still
            // INV-4-safe — no order has been opened, so false + Input error is legitimate.
            $this->logSagaException($run_id, $row->id, $domain, $e);
            $this->setRegistrationError('register_failed');

            return false;
        }

        $this->loadSagaDependencies();

        try {
            $context = $this->buildSagaContext($package, $vars, $row, $domain, $run_id, $operation);
        } catch (\Throwable $e) {
            $this->logSagaException($run_id, $row->id, $domain, $e);
            $this->setRegistrationError($is_transfer ? 'transfer_context_invalid' : 'register_context_invalid');

            return false;
        }

        $api = $this->buildProvisionApi($row);

        $context['prior_state'] = $this->priorOrderState($row->id, $context['service_id'], $domain);

        $saga = $is_transfer
            ? $this->buildTransferSaga($api, $row)
            : $this->buildRegistrationSaga($api, $row);

        try {
            $result = $saga->run($context);
        } catch (\Throwable $e) {
            $this->logSagaException($run_id, $row->id, $domain, $e);
            $this->setRegistrationError($is_transfer ? 'transfer_failed' : 'register_failed');

            return false;
        }

        if ($result['outcome'] === 'success') {
            // Post-register side-effect (WN-3-5/§K): when ID-protection was selected and the TLD
            // supports it, enable WHOIS privacy via the SEPARATE toggle endpoint. The domain is
            // already registered at this point, so a toggle failure must NEVER fail the
            // registration (INV-4) — applyWhoisPrivacy swallows everything to a scrubbed log.
            // Skipped for transfer: the domain is only transfer-PENDING (not yet at our registrar),
            // so a privacy toggle has nothing to act on — it is an Epic-5 post-completion concern.
            if (!$is_transfer) {
                $this->applyWhoisPrivacy($api, $vars, $domain, $row);
            }

            return $this->mergePersistedOrderFields($result['fields'], $vars, $domain, $row);
        }

        $this->setRegistrationError($result['error_key']);

        return false;
    }

    /**
     * Determines whether an add-service order is a transfer-in (WN-4-1 AC1/AC7).
     *
     * Truthiness, not bare isset — the order form posts `transfer='0'`/`''` for a register,
     * which isset() would wrongly treat as a transfer (the §K/AC7 bug). A transfer is
     * indicated by a truthy `transfer` flag OR a non-empty `auth-code` (EPP) field.
     *
     * @param array $vars The provisioning input
     * @return bool True when the order is a transfer-in
     */
    private function isTransferOrder(array $vars): bool
    {
        if (self::isTruthyFlag($vars['transfer'] ?? null)) {
            return true;
        }

        $auth = $vars['auth-code'] ?? null;

        return is_string($auth) && trim($auth) !== '';
    }

    /**
     * Interprets a form value as a truthy boolean flag (treats '0'/''/'false'/'no'/'off' as false).
     *
     * @param mixed $value The raw form value
     * @return bool The truthiness of the flag
     */
    private static function isTruthyFlag($value): bool
    {
        if ($value === null) {
            return false;
        }
        // Reject non-scalars (a crafted transfer[]=1 array/object) — casting one to string yields
        // "Array"/a notice and would wrongly read as truthy, misdispatching to the TransferSaga
        // (and casting a non-scalar auth-code to "Array" as authInfo). Round-1 P14a.
        if (!is_scalar($value)) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }

        $normalized = strtolower(trim((string) $value));

        return !in_array($normalized, ['', '0', 'false', 'no', 'off'], true);
    }

    /**
     * Builds the transfer saga used by addService (WN-4-1, mirrors buildRegistrationSaga).
     *
     * @param \WebnicApi $api The row-scoped API handle
     * @param stdClass $row The provisioning module row
     * @return \Webnic\Saga\TransferSaga The transfer saga
     */
    protected function buildTransferSaga($api, $row)
    {
        return new \Webnic\Saga\TransferSaga(
            new \Webnic\Orders(),
            new \Webnic\ContactsMap(),
            new \WebnicContacts($api),
            new \WebnicTransfers($api),
            new \WebnicDomains($api),
            function (array $record): void {
                $this->logSaga($record);
            },
            function ($order_row_id, $transfer_id) use ($row): void {
                $this->persistTransferId((int) $row->id, $order_row_id, $transfer_id);
            }
        );
    }

    /**
     * Builds the row-scoped API handle used by the provisioning saga.
     *
     * Kept as a protected factory so the full-runtime harness can exercise addService's real
     * post-success branch with a recording transport and no WebNIC egress.
     *
     * @param stdClass $row The provisioning module row
     * @return \WebnicApi The row-scoped API client
     */
    protected function buildProvisionApi($row)
    {
        return $this->buildWebnicApi($row);
    }

    /**
     * Builds the registration saga used by addService.
     *
     * @param \WebnicApi $api The row-scoped API handle
     * @param stdClass $row The provisioning module row
     * @return \Webnic\Saga\RegistrationSaga The registration saga
     */
    protected function buildRegistrationSaga($api, $row)
    {
        return new \Webnic\Saga\RegistrationSaga(
            new \Webnic\Orders(),
            new \Webnic\ContactsMap(),
            new \WebnicContacts($api),
            new \WebnicHosts($api),
            new \WebnicDomains($api),
            function (array $record): void {
                $this->logSaga($record);
            },
            function ($order_row_id, $webnic_order_id) use ($row): void {
                $this->persistWebnicOrderId((int) $row->id, $order_row_id, $webnic_order_id);
            }
        );
    }

    /**
     * Enables WHOIS privacy on a freshly-registered domain when ID-protection was selected (WN-3-5/§K).
     *
     * A SEPARATE post-register side-effect: runs ONLY after the saga returns a successful outcome
     * (the domain is already registered), so it can NEVER turn a completed registration into a
     * false/error or drive an order to `failed` (INV-4). No-op when ID-protection is unselected,
     * when the TLD's ext-rules report no `whoisPrivacy` capability, or when the capability read
     * fails (API registrations default privacy OFF, so the desired state is already reached). A
     * toggle failure is logged (scrubbed, INV-7) and left recoverable — the persisted
     * `id_protection=1` lets a later retry/finalise re-attempt it. The toggle is idempotent
     * (already-active = success). Reuses the saga's row-scoped $api (INV-1).
     *
     * @param WebnicApi $api The row-scoped API handle the saga already built
     * @param array $vars The provisioning input (carries id_protection)
     * @param string $domain The normalized, registered domain
     * @param stdClass $row The provisioning module row
     */
    private function applyWhoisPrivacy($api, array $vars, string $domain, $row): void
    {
        try {
            if (!$this->idProtectionSelected($vars)) {
                return;
            }

            // Capability gate (AC5): only TLDs whose ext-rules report whoisPrivacy:true. Cache-first,
            // row-scoped, fail-open — an empty/failed read skips the toggle (never blocks a register).
            $rules = $this->fetchRegistrationRules($this->domainExtension($domain), $row);
            if (empty($rules) || ($rules['whoisPrivacy'] ?? null) !== true) {
                return;
            }

            Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_domains.php');
            $response = (new \WebnicDomains($api))->toggleWhoisPrivacy($domain, true);

            if (!\WebnicResponse::privacyToggleSucceeded($response, true)) {
                $this->logWhoisPrivacyFailure((int) $row->id, $domain, $response);
            }
        } catch (\Throwable $e) {
            // A post-registration privacy toggle must NEVER undo a completed registration.
            $this->logWhoisPrivacyFailure((int) ($row->id ?? 0), $domain, null, $e);
        }
    }

    /**
     * Returns whether the submitted/replayed ID-protection value is explicitly selected.
     *
     * Blesta checkboxes submit `'1'`; package/service replay also stores `'1'`. Do not use
     * loose truthiness here, because strings such as `'false'` or `'off'` are non-empty.
     *
     * @param array $vars The provisioning or replay input
     * @return bool True only for the canonical selected value
     */
    private function idProtectionSelected(array $vars): bool
    {
        return isset($vars['id_protection']) && trim((string) $vars['id_protection']) === '1';
    }

    /**
     * Replays persisted ID-protection intent for an already-registered service.
     *
     * Used by failed-order finalise and pending->active reconciliation. The domain is already
     * registered/active in both paths; failures are swallowed by applyWhoisPrivacy() exactly like
     * the original post-register side-effect.
     *
     * @param stdClass $service The Blesta service carrying stored fields
     * @param stdClass|null $row The owning module row
     * @param string $domain The normalized domain
     */
    private function applyPersistedWhoisPrivacy($service, $row, string $domain): void
    {
        try {
            if (empty($row) || $domain === '') {
                return;
            }

            $vars = $this->idProtectionFromService($service);
            if (empty($vars)) {
                return;
            }

            $this->applyWhoisPrivacy($this->buildWebnicApi($row), $vars, $domain, $row);
        } catch (\Throwable $e) {
            $this->logWhoisPrivacyFailure((int) ($row->id ?? 0), $domain, null, $e);
        }
    }

    /**
     * Logs a scrubbed WHOIS-privacy toggle failure (INV-7); never throws (WN-3-5).
     *
     * @param int $module_row_id The provisioning module row id
     * @param string $domain The registered domain (not a secret)
     * @param WebnicResponse|null $response The failed toggle response, if any
     * @param \Throwable|null $e The throw, if the failure was an exception
     */
    private function logWhoisPrivacyFailure(int $module_row_id, string $domain, $response = null, \Throwable $e = null): void
    {
        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
            $body = ($response !== null) ? $response->body() : null;
            $context = [
                'module_row_id' => $module_row_id,
                'domain' => $domain,
                'result' => 'whois_privacy_toggle_failed',
                'intended_active' => true,
                'http_status' => ($response !== null && method_exists($response, 'status')) ? $response->status() : null,
                'code' => is_array($body) ? ($body['code'] ?? null) : null,
                'sub_code' => is_array($body) ? ($body['error']['subCode'] ?? null) : null,
                'provider_message' => is_array($body) ? ($body['error']['message'] ?? ($body['message'] ?? null)) : null,
                'error' => $e !== null ? get_class($e) . ': ' . $e->getMessage() : null,
                'message' => Language::_('Webnic.!error.whois_privacy_toggle_failed', true),
            ];
            if (class_exists('\Webnic\Support\Redactor')) {
                $context = \Webnic\Support\Redactor::scrub($context);
            }
            $this->log('domain/v2/whois-privacy/toggle', serialize($context), 'output', false);
        } catch (\Throwable $inner) {
            // Logging must never turn a completed registration into a thrown error.
        }
    }

    /**
     * Merges the collected ID-protection + surfaced per-TLD values into the service fields
     * Blesta persists on a successful registration (WN-3-4b P2 / Dev Notes §K).
     *
     * The saga returns only domain / expiry / nameservers, but the order form ALSO collects an
     * ID-protection selection and any surfaced per-TLD requirement, and the gate validates
     * them. Without this merge those values are validated and then dropped — the operator loses
     * the record the story promised to persist. The register WIRE-format (addons.whoisPrivacy /
     * customFields) is OTE-accepted (WN-3-6: register_optionals), but the saga / buildRegisterBody
     * deliberately still does NOT send it (§K — privacy is the post-register toggle); this only
     * records the selections as Blesta service fields. setFields()
     * full-replaces, but the cron reconciler never re-emits service fields (it only transitions
     * webnic_orders + sends notices), so the single success-path append is durable; a
     * failed-order retry replays NS via retryNameservers and could replay these the same way if
     * the §K wire-up later makes them registry-affecting.
     *
     * @param array $fields The saga's service-field array (full-replaced by Blesta)
     * @param array $vars The provisioning input
     * @param string $domain The normalized domain
     * @param stdClass|null $row The provisioning module row (scopes the descriptor rule read)
     * @return array The service-field array with the persisted selections appended
     */
    private function mergePersistedOrderFields(array $fields, array $vars, string $domain, $row = null): array
    {
        // ID-protection: record the selection durably (1 = protected, 0 = not) so operator
        // intent survives provisioning even though the registry wire-up is deferred (§K).
        $fields[] = [
            'key' => 'id_protection',
            'value' => !empty($vars['id_protection']) ? '1' : '0',
            'encrypted' => 0,
        ];

        // Surfaced per-TLD requirement values. Forward-compat: empty for every currently-mapped
        // TLD (all known keys are suppressed), so this is a no-op today; it persists only the
        // descriptors the form actually surfaced, with a non-blank value, keyed stably so a
        // later billable write can replay them. Best-effort — the domain is already registered,
        // so an optional-field persist must never fail a completed registration.
        $submitted = isset($vars['tld_req']) && is_array($vars['tld_req']) ? $vars['tld_req'] : [];
        try {
            foreach ($this->buildTldDescriptors($domain, $row) as $descriptor) {
                $token = $this->safeFieldToken($descriptor['name']);
                $value = $submitted[$token] ?? null;
                if ($value === null || (is_scalar($value) && trim((string) $value) === '')) {
                    continue;
                }
                $fields[] = [
                    'key' => 'tld_req_' . $token,
                    'value' => is_scalar($value) ? (string) $value : json_encode($value),
                    'encrypted' => 0,
                ];
            }
        } catch (\Throwable $e) {
            // Optional per-TLD persistence must never undo a completed registration.
        }

        return $fields;
    }

    /**
     * Resolves the module row that provisions this service (INV-1).
     *
     * @param stdClass $package The package being provisioned
     * @return stdClass|null The resolved module row, or null when none is configured
     */
    private function resolveProvisionRow($package)
    {
        $explicit = $package->module_row ?? null;
        $row = $this->getModuleRow($explicit);

        // An explicit module-row id that cannot resolve must FAIL CLOSED — never silently
        // fall back to the first module row (that would provision under the wrong
        // account's credentials, INV-1; the recovery retry pins this id deliberately).
        // Only fall back to the first row when no id was specified at all.
        if (empty($row) && ($explicit === null || $explicit === '' || (int) $explicit <= 0)) {
            $rows = $this->getModuleRows();
            $row = is_array($rows) ? ($rows[0] ?? null) : null;
        }

        return empty($row) ? null : $row;
    }

    /**
     * Loads the apis/ + lib/ classes the saga depends on.
     */
    private function loadSagaDependencies(): void
    {
        Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_api.php');
        Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_domains.php');
        Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_contacts.php');
        Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_hosts.php');
        Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_transfers.php');
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_contacts_map.php');
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_saga.php');
    }

    /**
     * Assembles the saga registration context from Blesta package/client data.
     *
     * @param stdClass $package The package being provisioned
     * @param array $vars The provisioning input
     * @param stdClass $row The resolved module row
     * @param string $domain The normalized domain
     * @param string $run_id The per-call observability run id
     * @return array The registration context for RegistrationSaga::run()
     * @throws \RuntimeException When the client cannot be resolved
     */
    protected function buildSagaContext($package, array $vars, $row, string $domain, string $run_id, string $operation = 'register'): array
    {
        if (!isset($this->Clients)) {
            Loader::loadModels($this, ['Clients']);
        }
        if (!isset($this->Contacts)) {
            Loader::loadModels($this, ['Contacts']);
        }

        $client_id = isset($vars['client_id']) ? (int) $vars['client_id'] : 0;
        $client = $client_id > 0 ? $this->Clients->get($client_id) : null;
        if (empty($client)) {
            throw new \RuntimeException('WebNIC addService: client could not be resolved for registration.');
        }

        $numbers = $this->Contacts->getNumbers($client->contact_id, 'phone');
        $contact_source = [
            'first_name' => $client->first_name ?? '',
            'last_name' => $client->last_name ?? '',
            'company' => $client->company ?? '',
            'address1' => $client->address1 ?? '',
            'address2' => $client->address2 ?? '',
            'city' => $client->city ?? '',
            'state' => $client->state ?? '',
            'zip' => $client->zip ?? '',
            'country' => $client->country ?? '',
            'email' => $client->email ?? '',
            'phone' => isset($numbers[0]->number) ? $numbers[0]->number : '',
        ];

        return [
            'run_id' => $run_id,
            'service_id' => self::REGISTER_INTENT_SERVICE_ID,
            'module_row_id' => (int) $row->id,
            'client_id' => $client_id,
            'domain' => $domain,
            'ext' => $this->domainExtension($domain),
            'term' => $this->resolveTerm($package, $vars),
            'nameservers' => $this->resolveNameservers($vars),
            'contact' => \Webnic\ContactsMap::mapClientToContact($contact_source),
            'registrant_username' => $this->registrantUsername((int) $row->id, $client_id),
            // WN-4-1: the operation discriminator + the client's EPP (auth-code). The transfer saga
            // reads auth_code into the Submit Registrar Transfer In `authInfo`; register ignores both.
            'operation' => $operation,
            'auth_code' => isset($vars['auth-code']) ? (string) $vars['auth-code'] : '',
        ];
    }

    /**
     * Derives the registration term (years) from the package pricing by pricing_id.
     *
     * @param stdClass $package The package being provisioned
     * @param array $vars The provisioning input
     * @return int The term in years (default 1)
     */
    private function resolveTerm($package, array $vars): int
    {
        if (isset($vars['pricing_id']) && isset($package->pricing) && is_array($package->pricing)) {
            foreach ($package->pricing as $pricing) {
                if ($pricing->id == $vars['pricing_id']) {
                    return max(1, (int) $pricing->term);
                }
            }
        }

        return 1;
    }

    /**
     * Resolves the nameserver list, falling back to the WebNIC shared NS (FR15).
     *
     * @param array $vars The provisioning input
     * @return array At least two nameservers
     */
    private function resolveNameservers(array $vars): array
    {
        $nameservers = [];
        for ($i = 1; $i <= 5; $i++) {
            $key = 'ns' . $i;
            // Trim BEFORE deciding to append so a whitespace-only value is not counted as a
            // nameserver. Otherwise this resolver and the gate disagree: the gate trims
            // (OrderInput::countSuppliedNs) and sees 0 supplied -> defaults, while a raw
            // !empty() here would append two blank strings (["",""], count 2 >= 2) and send
            // blank nameservers to the saga instead of the WebNIC shared defaults.
            $value = isset($vars[$key]) && is_scalar($vars[$key]) ? strtolower(trim((string) $vars[$key])) : '';
            if ($value !== '') {
                $nameservers[] = $value;
            }
        }

        return count($nameservers) >= 2 ? $nameservers : \Webnic\Saga\RegistrationSaga::DEFAULT_NAMESERVERS;
    }

    /**
     * Extracts the WebNIC extension (TLD without the leading dot) from a domain.
     *
     * @param string $domain The normalized domain
     * @return string The extension (everything after the first dot)
     */
    private function domainExtension(string $domain): string
    {
        $pos = strpos($domain, '.');

        return $pos === false ? '' : substr($domain, $pos + 1);
    }

    /**
     * Derives a deterministic, reusable registrant username (>=10 alphanumerics).
     *
     * Deterministic per (module row, client) so a re-mint is idempotent; the cached
     * registrant_user_id is reused once minted, so this is the cold-start seed only.
     *
     * @param int $module_row_id The owning module row
     * @param int $client_id The Blesta client id
     * @return string The registrant username
     */
    private function registrantUsername($module_row_id, $client_id): string
    {
        return 'hpkwn' . str_pad((string) $module_row_id, 5, '0', STR_PAD_LEFT)
            . str_pad((string) $client_id, 8, '0', STR_PAD_LEFT);
    }

    /**
     * Reads the prior order state for the revived-terminal retry guard (AC5).
     *
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param int $service_id The intent service id (sentinel)
     * @param string $domain The normalized domain
     * @return string|null The prior state, or null when no row exists in this scope
     */
    private function priorOrderState($module_row_id, $service_id, string $domain)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        $row = $this->Record->select(['state'])
            ->from('webnic_orders')
            ->where('service_id', '=', $service_id)
            ->where('domain', '=', $domain)
            ->where('module_row_id', '=', $module_row_id)
            ->fetch();

        return $row ? $row->state : null;
    }

    /**
     * Persists webnic_order_id onto the order row (scoped non-state column write).
     *
     * The single-writer rule (INV-3) governs the `state` column; webnic_order_id is a
     * normal column written here by id (the same direct write the WN-3-1 harness uses).
     *
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param int $order_row_id The webnic_orders row id
     * @param string $webnic_order_id The pendingOrderId to persist
     */
    private function persistWebnicOrderId($module_row_id, $order_row_id, $webnic_order_id): void
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        $this->Record->where('id', '=', $order_row_id)
            ->where('module_row_id', '=', $module_row_id)
            ->update('webnic_orders', ['webnic_order_id' => $webnic_order_id]);
    }

    /**
     * Persists the transfer correlation id onto the order row (WN-4-1, mirrors persistWebnicOrderId).
     *
     * A scoped NON-state write (INV-1 module-row scope; the `state` column is untouched). The
     * transfer_id is a best-effort audit handle — transfer reconcile is by-domain via
     * transfer-in/status, not by this id — so it may legitimately be null (the success envelope
     * is [UNVERIFIED]); persist() only calls this when a non-null id was parsed.
     *
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param int $order_row_id The webnic_orders row id
     * @param string $transfer_id The transfer correlation id
     */
    private function persistTransferId($module_row_id, $order_row_id, $transfer_id): void
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        $this->Record->where('id', '=', $order_row_id)
            ->where('module_row_id', '=', $module_row_id)
            ->update('webnic_orders', ['transfer_id' => $transfer_id]);
    }

    /**
     * Sets the localized registration failure error (NEVER for an async-pending order).
     *
     * @param string $error_key The language-key suffix under Webnic.!error.*
     */
    private function setRegistrationError($error_key): void
    {
        try {
            $this->Input->setErrors([
                'webnic' => [
                    $error_key => Language::_('Webnic.!error.' . $error_key, true),
                ],
            ]);
        } catch (\Throwable $e) {
            // Even the error side-channel must not throw out of provisioning.
        }
    }

    /**
     * Writes a scrubbed INV-9 saga record to the Blesta module log (release blocker).
     *
     * @param array $record The structured saga record
     */
    private function logSaga(array $record): void
    {
        try {
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $success = !isset($record['error_class']) || $record['error_class'] === null;
            $this->log('domain/v2/register', serialize($scrubbed), 'output', $success);
        } catch (\Throwable $e) {
            // Logging must never interrupt provisioning.
        }
    }

    /**
     * Logs a scrubbed saga exception without leaking secrets.
     *
     * @param string $run_id The per-call observability run id
     * @param int $module_row_id The owning module row
     * @param string $domain The normalized domain
     * @param \Throwable $e The caught exception
     */
    private function logSagaException($run_id, $module_row_id, string $domain, \Throwable $e): void
    {
        $this->logSaga([
            'run_id' => $run_id,
            'module_row_id' => $module_row_id,
            'domain' => $domain,
            'command' => 'exception',
            'error_class' => 'indeterminate',
            'message' => get_class($e) . ': ' . $e->getMessage(),
        ]);
    }

    /**
     * Gets the domain registration date, sourced live from the WebNIC registry (AC3/AR18).
     *
     * The Domain Manager pulls dates THROUGH this module hook (the module is the sole
     * source, the DM is the cache), so there is no independent DM registry poll and no
     * two-writer race on the expiry field. Mirrors the bundled registrars; the
     * registration date is the registry `info.dtcreate`.
     *
     * @param stdClass $service The service belonging to the domain to lookup
     * @param string $format The format to return the registration date in
     * @return string|false The domain registration date in the given format, or false on failure
     * @see Services::get()
     */
    public function getRegistrationDate($service, $format = 'Y-m-d H:i:s')
    {
        return $this->registryDate($service, 'dtcreate', $format);
    }

    /**
     * Gets the domain expiration date, sourced live from the WebNIC registry (AC3/AR18).
     *
     * Same read-through contract as getRegistrationDate(); the expiry is the registry
     * `info.dtexpire`. The module is the sole writer of registration/expiry (AC7).
     *
     * @param stdClass $service The service belonging to the domain to lookup
     * @param string $format The format to return the expiration date in
     * @return string|false The domain expiration date in the given format, or false on failure
     * @see Services::get()
     */
    public function getExpirationDate($service, $format = 'Y-m-d H:i:s')
    {
        return $this->registryDate($service, 'dtexpire', $format);
    }

    /**
     * Reads a registry date field from WebNIC Get Domain Info for a service (AC3).
     *
     * The provider datetimes carry their own zone (info dtexpire `Z`, dtcreate
     * `+08:00`), which the Date helper honors, so the raw value is passed through
     * exactly like the bundled registrars. Any failure (no domain/row, transport,
     * non-success envelope, missing field) yields false so the Domain Manager keeps
     * its cached value rather than clobbering it.
     *
     * @param stdClass $service The service to read dates for
     * @param string $field The info field to read (dtcreate|dtexpire)
     * @param string $format The output date format
     * @return string|false The formatted date, or false on any failure
     */
    private function registryDate($service, string $field, string $format)
    {
        Loader::loadHelpers($this, ['Date']);
        $this->loadReconcilerDependencies();

        $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
        if ($domain === '') {
            return false;
        }

        $row = $this->getModuleRow($service->module_row_id ?? null);
        if (empty($row)) {
            return false;
        }

        try {
            $api = new \WebnicApi(
                $row->id,
                $row->meta->username,
                $row->meta->secret,
                $row->meta->environment,
                new \Webnic\TokenStore()
            );
            $response = (new \WebnicDomains($api))->info($domain);
        } catch (\Throwable $e) {
            return false;
        }

        if (!$response->success()) {
            return false;
        }

        $data = $response->data();
        $raw = is_array($data) ? ($data[$field] ?? null) : null;

        if (!is_string($raw) || trim($raw) === '') {
            return false;
        }

        try {
            $formatted = $this->Date->format($format, $raw);
        } catch (\Throwable $e) {
            return false;
        }

        return $formatted ?: false;
    }

    /**
     * Reads and normalizes one row/domain info() payload for all service-page consumers.
     *
     * The snapshot is cached for the module instance so service-info, restore-preview, delete-tab,
     * and future parity-tab checks use one consistent registry state during a page render. Local
     * malformed prerequisites (empty domain / missing row) are marked separately from external
     * failures so they can fail minimal instead of showing the AC5 outage note.
     *
     * @param stdClass $service The service being rendered
     * @param string $context Log context when $log_failure is true
     * @param bool $log_failure Whether non-local failures should be observable immediately
     * @return array Normalized snapshot
     */
    private function domainInfoSnapshot($service, string $context = 'summary', bool $log_failure = false): array
    {
        try {
            return $this->domainInfoSnapshotForRowDomain(
                (string) $this->getServiceDomain($service),
                isset($service->module_row_id) ? (int) $service->module_row_id : 0,
                $context,
                $log_failure,
                $service
            );
        } catch (\Throwable $e) {
            $snapshot = $this->failedDomainInfoSnapshot(
                '',
                0,
                null,
                'exception',
                get_class($e) . ': ' . $e->getMessage(),
                $e
            );
            if ($log_failure) {
                $this->logDomainInfoSnapshotFailure($context, $service, $snapshot);
            }

            return $snapshot;
        }
    }

    /**
     * Reads and normalizes one row/domain info() payload using the shared request cache.
     *
     * @param string $domain Domain name
     * @param int $module_row_id Module row id
     * @param string $context Log context when $log_failure is true
     * @param bool $log_failure Whether non-local failures should be observable immediately
     * @param mixed $service Optional service object for log correlation
     * @return array Normalized snapshot
     */
    private function domainInfoSnapshotForRowDomain(
        string $domain,
        int $module_row_id,
        string $context = 'summary',
        bool $log_failure = false,
        $service = null
    ): array {
        $raw_domain = $domain;
        $domain = '';
        $cache_key = null;

        try {
            $this->loadReconcilerDependencies();
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_status.php');

            $domain = \Webnic\Orders::normalizeDomain($raw_domain);
            $cache_key = $module_row_id . ':' . ($domain !== '' ? $domain : '_empty');
            if (isset($this->service_info_domain_cache[$cache_key])) {
                $snapshot = $this->service_info_domain_cache[$cache_key];
                if ($log_failure) {
                    $this->logDomainInfoSnapshotFailure($context, $service, $snapshot);
                }

                return $snapshot;
            }

            if ($domain === '' || $module_row_id <= 0) {
                return $this->cacheDomainInfoSnapshot($this->localInvalidDomainInfoSnapshot(
                    $domain,
                    $module_row_id,
                    $cache_key
                ));
            }

            $row = $this->getModuleRow($module_row_id);
            if (empty($row)) {
                return $this->cacheDomainInfoSnapshot($this->localInvalidDomainInfoSnapshot(
                    $domain,
                    $module_row_id,
                    $cache_key
                ));
            }

            $response = $this->buildDomainsApi($row)->info($domain);
            if (!$response->success()) {
                $snapshot = $this->cacheDomainInfoSnapshot($this->failedDomainInfoSnapshot(
                    $domain,
                    $module_row_id,
                    $cache_key,
                    'non_success',
                    'WebNIC info returned a non-success envelope',
                    null,
                    method_exists($response, 'status') ? $response->status() : null
                ));
                if ($log_failure) {
                    $this->logDomainInfoSnapshotFailure($context, $service, $snapshot);
                }

                return $snapshot;
            }

            $data = $response->data();
            if (!is_array($data)) {
                $snapshot = $this->cacheDomainInfoSnapshot($this->failedDomainInfoSnapshot(
                    $domain,
                    $module_row_id,
                    $cache_key,
                    'malformed',
                    'WebNIC info returned an unreadable data payload'
                ));
                if ($log_failure) {
                    $this->logDomainInfoSnapshotFailure($context, $service, $snapshot);
                }

                return $snapshot;
            }

            $status = isset($data['status']) ? strtolower(trim((string) $data['status'])) : '';
            if ($status === '') {
                $snapshot = $this->cacheDomainInfoSnapshot($this->failedDomainInfoSnapshot(
                    $domain,
                    $module_row_id,
                    $cache_key,
                    'malformed',
                    'WebNIC info returned no registry status'
                ));
                if ($log_failure) {
                    $this->logDomainInfoSnapshotFailure($context, $service, $snapshot);
                }

                return $snapshot;
            }

            return $this->cacheDomainInfoSnapshot([
                'ok' => true,
                'local_invalid' => false,
                'cache_key' => $cache_key,
                'domain' => $domain,
                'module_row_id' => $module_row_id,
                'data' => $data,
                'status' => $status,
                'status_alias' => \WebnicStatus::fromRegistryStatus(
                    $status,
                    self::isRegistryTrue($data['suspended'] ?? false)
                ),
                'logged_contexts' => [],
            ]);
        } catch (\Throwable $e) {
            $snapshot = $this->failedDomainInfoSnapshot(
                $domain,
                $module_row_id,
                $cache_key,
                'exception',
                get_class($e) . ': ' . $e->getMessage(),
                $e
            );
            if ($cache_key !== null) {
                $snapshot = $this->cacheDomainInfoSnapshot($snapshot);
            }
            if ($log_failure) {
                $this->logDomainInfoSnapshotFailure($context, $service, $snapshot);
            }

            return $snapshot;
        }
    }

    /**
     * Builds a local-invalid info snapshot.
     *
     * @param string $domain Normalized domain, if any
     * @param int $module_row_id Module row id, if any
     * @param string|null $cache_key Cache key for row/domain invalidity
     * @return array
     */
    private function localInvalidDomainInfoSnapshot(string $domain, int $module_row_id, $cache_key = null): array
    {
        return [
            'ok' => false,
            'local_invalid' => true,
            'cache_key' => $cache_key,
            'domain' => $domain,
            'module_row_id' => $module_row_id,
            'data' => null,
            'status' => '',
            'status_alias' => null,
            'failure' => 'local_invalid',
            'message' => 'Local service prerequisites are incomplete',
            'logged_contexts' => [],
        ];
    }

    /**
     * Builds an external/read-failure info snapshot.
     *
     * @param string $domain Normalized domain, if any
     * @param int $module_row_id Module row id, if any
     * @param string|null $cache_key Cache key
     * @param string $failure Failure class
     * @param string $message Safe diagnostic message
     * @param \Throwable|null $exception Original exception, if any
     * @param int|null $http_status HTTP status, if known
     * @return array
     */
    private function failedDomainInfoSnapshot(
        string $domain,
        int $module_row_id,
        $cache_key,
        string $failure,
        string $message,
        ?\Throwable $exception = null,
        $http_status = null
    ): array {
        return [
            'ok' => false,
            'local_invalid' => false,
            'cache_key' => $cache_key,
            'domain' => $domain,
            'module_row_id' => $module_row_id,
            'data' => null,
            'status' => '',
            'status_alias' => null,
            'failure' => $failure,
            'message' => $message,
            'exception' => $exception,
            'http_status' => $http_status,
            'logged_contexts' => [],
        ];
    }

    /**
     * Stores a row/domain info snapshot in the request cache.
     *
     * @param array $snapshot Snapshot to cache
     * @return array The same snapshot
     */
    private function cacheDomainInfoSnapshot(array $snapshot): array
    {
        $cache_key = $snapshot['cache_key'] ?? null;
        if ($cache_key !== null && $cache_key !== '') {
            $this->service_info_domain_cache[$cache_key] = $snapshot;
        }

        return $snapshot;
    }

    /**
     * Logs an external info-read failure once per row/domain/context.
     *
     * @param string $context Service-info log context
     * @param mixed $service Service being rendered
     * @param array $snapshot Failed info snapshot
     */
    private function logDomainInfoSnapshotFailure(string $context, $service, array $snapshot): void
    {
        if (!empty($snapshot['ok']) || !empty($snapshot['local_invalid'])) {
            return;
        }

        $cache_key = $snapshot['cache_key'] ?? null;
        if ($cache_key !== null
            && !empty($this->service_info_domain_cache[$cache_key]['logged_contexts'][$context])) {
            return;
        }

        $e = $snapshot['exception'] ?? null;
        if (!$e instanceof \Throwable) {
            $message = (string) ($snapshot['message'] ?? 'WebNIC info read failed');
            if (isset($snapshot['http_status']) && $snapshot['http_status'] !== null) {
                $message .= ' (HTTP ' . (int) $snapshot['http_status'] . ')';
            }
            $e = new \RuntimeException($message);
        }

        $this->logServiceInfoException($context, $service, $e);

        if ($cache_key !== null && isset($this->service_info_domain_cache[$cache_key])) {
            $this->service_info_domain_cache[$cache_key]['logged_contexts'][$context] = true;
        }
    }

    /**
     * True when a registry info snapshot proves the domain is live-manageable.
     *
     * @param array $snapshot Normalized info snapshot
     * @return bool
     */
    private function domainInfoSnapshotIsManageable(array $snapshot): bool
    {
        return !empty($snapshot['ok'])
            && $this->classifyDeletionWindowStatus((string) ($snapshot['status'] ?? ''))['allowed'];
    }

    /**
     * True when a domain-info snapshot shows the registrar transfer lock enabled.
     *
     * @param array $snapshot Normalized info snapshot
     * @return bool
     */
    private function domainInfoSnapshotIsLocked(array $snapshot): bool
    {
        return !empty($snapshot['ok'])
            && in_array((string) ($snapshot['status'] ?? ''), self::SETTINGS_LOCKED_STATUSES, true);
    }

    /**
     * True when a registry info snapshot permits WHOIS/contact edits.
     *
     * @param array $snapshot Normalized info snapshot
     * @return bool
     */
    private function domainInfoSnapshotAllowsWhoisContacts(array $snapshot): bool
    {
        return $this->domainInfoSnapshotIsManageable($snapshot)
            && !self::isRegistryTrue(($snapshot['data'] ?? [])['suspended'] ?? false);
    }

    /**
     * Parses WebNIC boolean-ish registry fields strictly.
     *
     * @param mixed $value Raw provider field
     * @return bool True only for boolean true, 1, or explicit true-ish strings
     */
    private static function isRegistryTrue($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (!is_string($value)) {
            return false;
        }

        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Normalizes the registry nameserver list for safe rendering.
     *
     * @param mixed $value Raw nameservers payload
     * @return array Trimmed non-blank hostnames
     */
    private static function normalizedRegistryNameservers($value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $nameservers = [];
        foreach ($value as $nameserver) {
            if (!is_string($nameserver)) {
                continue;
            }
            $nameserver = trim($nameserver);
            if ($nameserver !== '') {
                $nameservers[] = $nameserver;
            }
        }

        return $nameservers;
    }

    /**
     * Builds the active-domain summary from a cached Get Domain Info snapshot (WN-5-1, AC1/AC5).
     *
     * @param stdClass $service The active service being viewed
     * @param array|null $snapshot Optional pre-read info snapshot
     * @return array|null Normalized, view-safe active summary cells, or null
     */
    private function fetchDomainSummary($service, ?array $snapshot = null)
    {
        Loader::loadHelpers($this, ['Date']);
        $snapshot = $snapshot ?? $this->domainInfoSnapshot($service, 'summary', true);
        if (!$this->domainInfoSnapshotIsManageable($snapshot)) {
            return null;
        }

        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        $status = (string) ($snapshot['status'] ?? '');

        return [
            // Live status badge — derived from the cached info().status (+ suspended), never forced active.
            'status_alias' => $snapshot['status_alias'],
            'registration' => $this->formatRegistryDate($data['dtcreate'] ?? null),
            'expiry' => $this->formatRegistryDate($data['dtexpire'] ?? null),
            // Gate-1 lock semantics: a registrar/name lock is a status value, displayed read-only here
            // (the toggle is Story 5.8a). There is no getDomainIsLocked yet.
            'locked' => in_array($status, ['transfer_protected', 'name_protected'], true),
            'whois_privacy' => self::isRegistryTrue($data['whoisPrivacy'] ?? false),
            'nameservers' => self::normalizedRegistryNameservers($data['nameservers'] ?? []),
        ];
    }

    /**
     * Formats a raw registry datetime for the summary, honouring its embedded zone (AC1).
     *
     * Same Date-helper passthrough as registryDate() (the provider datetimes carry their own zone —
     * dtcreate +08:00, dtexpire Z — which the Date helper honours). Returns '' on any unparseable or
     * blank value so the view shows its em-dash fallback rather than a broken date.
     *
     * @param mixed $raw The raw info() datetime value
     * @return string The formatted date, or '' when unavailable
     */
    private function formatRegistryDate($raw): string
    {
        if (!is_string($raw) || trim($raw) === '') {
            return '';
        }

        try {
            $formatted = $this->Date->format('M j, Y', $raw);
        } catch (\Throwable $e) {
            return '';
        }

        return is_string($formatted) && $formatted !== '' ? $formatted : '';
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Service-info lifecycle UI (Story 3.4a / AC2/AC4). Renders webnic_orders.state
    // as the amber pending banner / red failed callout on the Blesta service page.
    // The order<->service link is BY-DOMAIN (sentinel service_id=0), so the order is
    // resolved from the service's domain + module row, never the real id (Dev Notes §B).
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Fetches the client-area service-info lifecycle chrome (AC2/AC4).
     *
     * @param stdClass $service The service being viewed
     * @param stdClass $package The service's package
     * @return string The rendered HTML, or '' for an active/no-order/non-webnic service
     */
    public function getClientServiceInfo($service, $package)
    {
        return $this->renderServiceInfo($service, false);
    }

    /**
     * Fetches the admin-area service-info lifecycle chrome (AC2/AC4/AC6).
     *
     * @param stdClass $service The service being viewed
     * @param stdClass $package The service's package
     * @return string The rendered HTML, or '' for an active/no-order/non-webnic service
     */
    public function getAdminServiceInfo($service, $package)
    {
        return $this->renderServiceInfo($service, true);
    }

    /**
     * Renders the pending/failed lifecycle chrome for a service (AC2/AC4).
     *
     * Pending => amber badge + age-softened pending banner. Failed => red callout
     * (client = support-only; admin = + masked tracking record + recovery action).
     * Locally cancelled services render the grey terminal badge and no actions (AC4).
     * Active/no-order stay minimal ('') — the Domain Manager owns the rich domain view
     * (Dev Notes §E). Never fatals the service page (FR39c fail-safe).
     *
     * @param stdClass $service The service being viewed
     * @param bool $is_admin True for the admin variant, false for the client variant
     * @return string The rendered HTML, or '' when there is nothing to chrome
     */
    private function renderServiceInfo($service, bool $is_admin): string
    {
        try {
            Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_status.php');

            $is_cancelled = $this->isLocalCancelledService($service);
            $order = $is_cancelled ? null : $this->resolveOrderByDomain($service);
            $alias = $is_cancelled
                ? \WebnicStatus::CANCELLED
                : ($order === null ? null : \WebnicStatus::fromOrderState((string) $order->state));
            $local_status = strtolower((string) ($service->status ?? ''));
            $is_local_active = $local_status === 'active';
            $is_suspended = $local_status === 'suspended';
            $show_suspended_reason = false;
            $restore_prompt = null;
            $snapshot = null;
            if (!$is_admin && !$is_cancelled && $is_local_active) {
                $state = $order !== null && is_object($order) ? ($order->state ?? null) : null;
                if ($order === null || $state === \Webnic\Orders::STATE_ACTIVE) {
                    $snapshot = $this->domainInfoSnapshot($service, 'summary', true);
                    $restore_prompt = $this->restorePromptState($service, $snapshot);
                }
            }

            // Precedence (AC2): in-flight/failed order chrome wins first (it carries recovery/resend
            // context); then a local suspend reason; then the client grace/redemption restore prompt;
            // and ONLY when none of those apply does the FR36 active-domain summary render (LAST), so a
            // pending/failed/cancelled service NEVER reads as active.
            $summary = null;
            $summary_unavailable = false;
            if ($alias !== \WebnicStatus::PENDING
                && $alias !== \WebnicStatus::FAILED
                && $alias !== \WebnicStatus::CANCELLED) {
                if ($is_suspended) {
                    $alias = \WebnicStatus::SUSPENDED;
                    $show_suspended_reason = true;
                } elseif ($restore_prompt !== null) {
                    $alias = null;
                } elseif (!$is_local_active) {
                    $alias = null;
                } else {
                    // Active, no blocking order, not locally suspended, no restore prompt -> render the
                    // live summary from the cached single info() read (FR36/AC1).
                    if ($snapshot === null) {
                        $snapshot = $this->domainInfoSnapshot($service, 'summary', true);
                    }
                    if (empty($snapshot['ok'])) {
                        if (!empty($snapshot['local_invalid'])) {
                            $alias = null;
                        } else {
                            // AC5: the live read failed -> show the localized unavailable note over a
                            // local-truth Active badge, never '' / a fatal.
                            $summary_unavailable = true;
                            $alias = \WebnicStatus::ACTIVE;
                        }
                    } else {
                        // AC2: the badge is the LIVE registry status (so a registry-side
                        // expired/redemption/pending_delete shows its real bucket, not a forced Active).
                        $alias = $snapshot['status_alias'];
                        $summary = $this->fetchDomainSummary($service, $snapshot);
                    }
                }
            }

            if ($alias === null && $summary === null && !$summary_unavailable && $restore_prompt === null) {
                return '';
            }

            $this->view = new View($is_admin ? 'admin_service_info' : 'client_service_info', 'default');
            Loader::loadHelpers($this, ['Form', 'Html']);

            // The pending banner is only ever rendered for a PENDING alias backed by a real order, so
            // compute it only there — this also keeps the new active-summary path (where $order may be
            // null) from reading a property on null. WN-4-1 AC4 / decision #8: a transfer-pending service
            // shows its OWN days-horizon narrative — never register-pending's "few minutes" copy (transfer
            // has no soften window); register keeps the reassurance-window soften logic.
            $banner_key = null;
            if ($alias === \WebnicStatus::PENDING && is_object($order)) {
                if (($order->operation ?? \Webnic\Orders::OPERATION_REGISTER) === \Webnic\Orders::OPERATION_TRANSFER) {
                    $banner_key = 'Webnic.service_info.transfer_pending_banner';
                } else {
                    $soften_after = (int) (Configure::get('Webnic.pending_banner_soften_after') ?: 3600);
                    $banner_key = \WebnicStatus::pendingBannerKey(
                        (string) ($order->created ?? ''),
                        gmdate('Y-m-d H:i:s'),
                        $soften_after
                    );
                }
            }

            $this->view->set('alias', $alias);
            $this->view->set('is_admin', $is_admin);
            $this->view->set('banner_key', $banner_key);
            $this->view->set('suspended_reason_key', 'Webnic.service_info.suspended_reason');
            $this->view->set('order', $order ? $this->maskedTrackingRecord($order) : null);
            $this->view->set('recovery_url', $is_admin ? $this->recoveryTabUrl($service) : '');
            $this->view->set('restore_prompt', $restore_prompt);
            $this->view->set('summary', $summary);
            $this->view->set('summary_unavailable', $summary_unavailable);
            $this->view->set('show_suspended_reason', $show_suspended_reason);
            $this->view->setDefaultView(self::$defaultModuleView);

            return $this->view->fetch();
        } catch (\Throwable $e) {
            // Service-info must never fatal the service page — but a swallowed DB outage,
            // missing view, or unexpected error should be observable, not silent (P16).
            $this->logServiceInfoException('render', $service, $e);

            return '';
        }
    }

    /**
     * Resolves the webnic_orders row for a service BY-DOMAIN within its module row.
     *
     * The order is keyed by the sentinel service_id=0 (addService runs before the
     * service row exists, Dev Notes §B), so it CANNOT be found by the real service id.
     * It is resolved by (module_row_id, domain) — the same by-domain link the reconciler
     * uses. INV-1: every read carries the module_row_id scope.
     *
     * @param stdClass $service The service being viewed
     * @return stdClass|null The most recent order row, or null when none exists in scope
     */
    private function resolveOrderByDomain($service)
    {
        $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
        if ($domain === '') {
            return null;
        }

        $module_row_id = isset($service->module_row_id) ? (int) $service->module_row_id : 0;
        if ($module_row_id <= 0) {
            return null;
        }

        return $this->resolveOrderByModuleRowDomain($module_row_id, $domain);
    }

    /**
     * Resolves the preferred webnic_orders row by module row and domain.
     *
     * @param int $module_row_id The module row scope
     * @param string $domain The normalized domain
     * @return stdClass|null The preferred order row, or null
     */
    private function resolveOrderByModuleRowDomain(int $module_row_id, string $domain)
    {
        if ($module_row_id <= 0 || trim($domain) === '') {
            return null;
        }

        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }

        // WN-4-1 (C10 b / AC6 + round-1 D2): a state-preference, not latest-by-id. Once transfer and
        // register rows can share a (module_row_id, domain), a newer non-failed row must NOT mask an
        // older `failed` row that still needs recovery (the WN-3-4a forward-binding hazard) — BUT a
        // stale `failed` row must equally not mask a strictly-NEWER `active` success of the other
        // operation (D2). preferOrderRow resolves both: failed wins unless superseded by a newer
        // active. The fetch is id DESC so the first row of each state is the most recent.
        $orders = $this->Record->select()
            ->from('webnic_orders')
            ->where('module_row_id', '=', $module_row_id)
            ->where('domain', '=', $domain)
            ->order(['id' => 'desc'])
            ->fetchAll();
        $this->Record->reset();

        // Round-2 R2-2: the pure, order-independent supersede rule lives on Webnic\Orders (purely
        // unit-tested there); this helper just supplies the by-domain rows.
        return \Webnic\Orders::preferOrderRow(is_array($orders) ? $orders : []);
    }

    /**
     * Builds the masked tracking record carried into the failed-order view (AC4/INV-7).
     *
     * Only the safe lifecycle/timestamp columns are exposed; webnic_order_id is a
     * secret and is deliberately NEVER carried into the view.
     *
     * @param stdClass $order The webnic_orders row
     * @return stdClass The masked, view-safe tracking record
     */
    private function maskedTrackingRecord($order)
    {
        return (object) [
            'id' => $order->id ?? null,
            'state' => $order->state ?? null,
            'attempts' => $order->attempts ?? 0,
            'created' => $order->created ?? null,
            'last_polled' => $order->last_polled ?? null,
            'give_up_at' => $order->give_up_at ?? null,
        ];
    }

    /**
     * Builds the admin Recovery-tab URL for the failed-order recovery action (AC4).
     *
     * The recovery POST surface is the conditional admin tab (Dev Notes §E); the
     * failed callout links to it rather than carrying an inline form. Best-effort:
     * returns '' when base_uri/service ids are unavailable, in which case the view
     * shows an informational hint instead.
     *
     * @param stdClass $service The service being viewed
     * @return string The admin service-tab URL, or '' when it cannot be built
     */
    private function recoveryTabUrl($service): string
    {
        if (empty($this->base_uri) || !isset($service->client_id, $service->id)) {
            return '';
        }

        return $this->base_uri . 'clients/servicetab/' . (int) $service->client_id
            . '/' . (int) $service->id . '/tabRecovery/';
    }

    /**
     * Logs a swallowed service-info/recovery exception (observable, never re-throws) (P16).
     *
     * The service hooks (renderServiceInfo/getAdminServiceTabs/tabRecovery) deliberately
     * swallow Throwables so a webnic edge can never fatal the service page — but a silent
     * swallow hides DB outages / missing views / unexpected errors from operators. This
     * emits a scrubbed, error-level module-log record so the failure stays visible, and is
     * itself fully guarded so observability can never turn a swallow into a fatal.
     *
     * @param string $context render|admin_tabs|tabRecovery
     * @param mixed $service The service being rendered (for the service_id, if any)
     * @param \Throwable $e The swallowed exception
     */
    private function logServiceInfoException(string $context, $service, \Throwable $e): void
    {
        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
            $record = [
                'level' => 'error',
                'service_id' => is_object($service) ? ($service->id ?? null) : null,
                'command' => 'service_info.' . $context,
                'error_class' => 'indeterminate',
                'message' => get_class($e) . ': ' . $e->getMessage(),
            ];
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $this->log('webnic/service_info', serialize($scrubbed), 'output', false);
        } catch (\Throwable $ignored) {
            // Observability must never turn a swallowed error into a fatal.
        }
    }

    /**
     * Returns the admin service tabs from the array-driven registry (AC3/AC4/C15).
     *
     * The 7 LogicBoxes-parity management tabs (canonical UX-DR15 order, gated by method_exists
     * so an unbuilt 5.2-5.8 slot renders no dead link) plus the absorbed conditional lifecycle
     * tabs — Recovery (failed), Resend (registrar_pending), Restore (grace/redemption), Delete
     * (active/manageable) — composed from one ordered manifest (\Webnic\TabRegistry). The
     * lifecycle predicates are byte-for-byte the WN-3-4a/4-2/4-4/4-6 gating, kept mutually
     * exclusive. Never fatals the admin service page (the []-on-error contract is preserved).
     *
     * @param stdClass $service A stdClass object representing the service
     * @return array The composed tab map, or [] when there is nothing to offer
     */
    public function getAdminServiceTabs($service)
    {
        return $this->composeServiceTabs($service, true);
    }

    /**
     * Returns the client service tabs from the array-driven registry (AC3/AC4/C15).
     *
     * The 7 client parity tabs (icon-bearing, same canonical order) plus the one client-side
     * lifecycle tab — Resend, while registrar_pending (AC2/FR22). Recovery/Restore/Delete are
     * admin-only and never leak into the client strip (the registry's `sides` filter enforces
     * this). Built parity methods appear on active/manageable domains in canonical order while
     * unbuilt slots stay hidden. Never fatals the client service page.
     *
     * @param stdClass $service A stdClass object representing the service
     * @return array The composed tab map, or [] when there is nothing to offer
     */
    public function getClientServiceTabs($service)
    {
        return $this->composeServiceTabs($service, false);
    }

    /**
     * Admin WHOIS/contacts tab.
     *
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @return string Rendered tab HTML
     */
    public function tabWhois($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageWhoisContacts('admin/tab_whois', $package, $service, $get, $post, $files, true);
    }

    /**
     * Client WHOIS/contacts tab.
     *
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @return string Rendered tab HTML
     */
    public function tabClientWhois($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageWhoisContacts('client/tab_client_whois', $package, $service, $get, $post, $files, false);
    }

    /**
     * Admin nameservers tab.
     *
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @return string Rendered tab HTML
     */
    public function tabNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageNameservers('admin/tab_nameservers', $package, $service, $get, $post, $files, true);
    }

    /**
     * Client nameservers tab.
     *
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @return string Rendered tab HTML
     */
    public function tabClientNameservers($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageNameservers('client/tab_client_nameservers', $package, $service, $get, $post, $files, false);
    }

    /**
     * Admin DNS records tab.
     *
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @return string Rendered tab HTML
     */
    public function tabDnsRecords($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnsRecords('admin/tab_dnsrecords', $package, $service, $get, $post, $files, true);
    }

    /**
     * Client DNS records tab.
     *
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @return string Rendered tab HTML
     */
    public function tabClientDnsRecords($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnsRecords('client/tab_client_dnsrecords', $package, $service, $get, $post, $files, false);
    }

    /**
     * Admin URL/email forwarding tab.
     *
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @return string Rendered tab HTML
     */
    public function tabForwarder($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageForwarder('admin/tab_forwarder', $package, $service, $get, $post, $files, true);
    }

    /**
     * Client URL/email forwarding tab.
     *
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @return string Rendered tab HTML
     */
    public function tabClientForwarder($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageForwarder('client/tab_client_forwarder', $package, $service, $get, $post, $files, false);
    }

    /**
     * Admin DNSSEC (DS records) tab.
     *
     * Declaring this public method (with its client twin) is the entire activation of the
     * `dnssec` parity slot (registry line 75); no registry edit is required (WN-5.5 mechanism).
     *
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @return string Rendered tab HTML
     */
    public function tabDnssec($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnssec('admin/tab_dnssec', $package, $service, $get, $post, $files, true);
    }

    /**
     * Client DNSSEC (DS records) tab.
     *
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @return string Rendered tab HTML
     */
    public function tabClientDnssec($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageDnssec('client/tab_client_dnssec', $package, $service, $get, $post, $files, false);
    }

    /**
     * Admin Settings tab.
     *
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @return string Rendered tab HTML
     */
    public function tabSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageSettings('admin/tab_settings', $package, $service, $get, $post, $files, true);
    }

    /**
     * Client Settings tab.
     *
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @return string Rendered tab HTML
     */
    public function tabClientSettings($package, $service, array $get = null, array $post = null, array $files = null)
    {
        return $this->manageSettings('client/tab_client_settings', $package, $service, $get, $post, $files, false);
    }

    /**
     * Returns whether the live WebNIC domain has registrar transfer lock enabled.
     *
     * @param string $domain The domain to lookup
     * @param int|null $module_row_id The module row id
     * @return bool True when the live status is transfer/name protected
     */
    public function getDomainIsLocked($domain, $module_row_id = null)
    {
        try {
            $this->loadReconcilerDependencies();
            $normalized = \Webnic\Orders::normalizeDomain((string) $domain);
            $snapshot = $this->domainInfoSnapshotForRowDomain(
                $normalized,
                (int) $module_row_id,
                'settings_lock_read',
                true
            );

            return $this->domainInfoSnapshotIsLocked($snapshot);
        } catch (\Throwable $e) {
            $this->logServiceInfoException('settings_lock_read', null, $e);
        }

        return false;
    }

    /**
     * Enables the registrar transfer lock for a WebNIC domain.
     *
     * @param string $domain The domain to lock
     * @param int|null $module_row_id The module row id
     * @return bool True when WebNIC accepted the status update
     */
    public function lockDomain($domain, $module_row_id = null)
    {
        return $this->performSettingsDomainAction((string) $domain, (int) $module_row_id, 'lock');
    }

    /**
     * Disables the registrar transfer lock for a WebNIC domain.
     *
     * @param string $domain The domain to unlock
     * @param int|null $module_row_id The module row id
     * @return bool True when WebNIC accepted the status update
     */
    public function unlockDomain($domain, $module_row_id = null)
    {
        return $this->performSettingsDomainAction((string) $domain, (int) $module_row_id, 'unlock');
    }

    /**
     * Emails the EPP/auth code through WebNIC without exposing the code to Blesta.
     *
     * @param string $domain The domain whose auth code should be emailed
     * @param int|null $module_row_id The module row id
     * @return bool True when WebNIC accepted the send action
     */
    public function sendEppEmail($domain, $module_row_id = null)
    {
        return $this->performSettingsDomainAction((string) $domain, (int) $module_row_id, 'send_epp');
    }

    /**
     * Resets the EPP/auth code at WebNIC without returning or rendering the new code.
     *
     * @param string $domain The domain whose auth code should be reset
     * @param string $epp_code Ignored; WebNIC generates the new value
     * @param int|null $module_row_id The module row id
     * @param array $vars Additional vars ignored by WebNIC
     * @return bool True when WebNIC accepted the reset action
     */
    public function updateEppCode($domain, $epp_code, $module_row_id = null, array $vars = [])
    {
        return $this->performSettingsDomainAction((string) $domain, (int) $module_row_id, 'reset_epp');
    }

    /**
     * Gets the current WebNIC nameservers for an active domain in Blesta registrar shape.
     *
     * @param string $domain The domain to lookup
     * @param int|null $module_row_id The module row id
     * @return array list of ['url' => string, 'ips' => []]
     */
    public function getDomainNameServers($domain, $module_row_id = null)
    {
        try {
            $context = $this->loadNameserverContext($domain, $module_row_id, false, false);
            if ($context === null || empty($context['snapshot']['ok'])) {
                return [];
            }

            return $this->nameserverRowsFromSnapshot($context['snapshot']);
        } catch (\Throwable $e) {
            $this->logNameserverException('nameservers.read', (string) $domain, (int) $module_row_id, $e);
        }

        return [];
    }

    /**
     * Updates the assigned nameservers for an active manageable WebNIC domain.
     *
     * @param string $domain The domain for which to assign nameservers
     * @param int|null $module_row_id The module row id
     * @param array $vars Flat nameserver list from the Blesta registrar contract
     * @return bool True when WebNIC accepted the update
     */
    public function setDomainNameservers($domain, $module_row_id = null, array $vars = [])
    {
        $this->last_nameserver_update_result = null;

        $nameservers = $this->normalizeNameserverAssignment($vars);
        if ($nameservers === null) {
            return false;
        }

        try {
            $context = $this->loadNameserverContext($domain, $module_row_id, true);
            if ($context === null || !$this->serviceAllowsNameservers($context['service'], $context['snapshot'], true)) {
                return false;
            }

            return $this->updateNameserversFromContext($context, $nameservers);
        } catch (\Throwable $e) {
            $this->logNameserverException('nameservers.update', (string) $domain, (int) $module_row_id, $e);
            $this->setNameserverError('nameservers', 'update_failed', 'nameserver_update_unavailable');
        }

        return false;
    }

    /**
     * Gets the current WebNIC WHOIS/contact sets for an active manageable domain.
     *
     * @param string $domain The domain to lookup
     * @param int|null $module_row_id The module row id
     * @return array role => normalized contact
     */
    public function getDomainContacts($domain, $module_row_id = null)
    {
        try {
            $context = $this->loadWhoisContactContext($domain, $module_row_id);
            if ($context === null || !$this->whoisContactContextIsManageable($context)) {
                return [];
            }

            return $this->getWhoisContactsFromContext($context);
        } catch (\Throwable $e) {
            $this->logWhoisContactException('contacts.read', (string) $domain, (int) $module_row_id, $e);
            $this->setWhoisContactError('contacts', 'contact_unavailable');
        }

        return [];
    }

    /**
     * Updates current WebNIC contact handles attached to an active manageable domain.
     *
     * @param string $domain The domain for which to update contact info
     * @param array $vars Submitted contact values under contacts[role]
     * @param int|null $module_row_id The module row id
     * @return bool True if all changed contacts were updated or accepted
     */
    public function setDomainContacts($domain, array $vars = [], $module_row_id = null)
    {
        $this->last_contact_update_result = null;

        try {
            $context = $this->loadWhoisContactContext($domain, $module_row_id);
            if ($context === null || !$this->whoisContactContextIsManageable($context)) {
                return false;
            }

            return $this->setDomainContactsFromContext($context, $vars);
        } catch (\Throwable $e) {
            $this->logWhoisContactException('contacts.update', (string) $domain, (int) $module_row_id, $e);
            $this->setWhoisContactError('contacts', 'contact_update_unavailable');
        }

        return false;
    }

    /**
     * Updates WebNIC contacts using an already resolved row/domain info context.
     *
     * @param array $context WHOIS contact context
     * @param array $vars Submitted contact values
     * @return bool True if all changed contacts were updated or accepted
     */
    private function setDomainContactsFromContext(array $context, array $vars = []): bool
    {
        $this->last_contact_update_result = null;

        if (!$this->whoisContactContextIsManageable($context)) {
            return false;
        }

        try {
            Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_whois_contacts.php');
            $attached_ids = \Webnic\WhoisContacts::contactIdsFromInfo($context['info']);
            $submitted = $this->normalizeWhoisContactSubmission($vars, $attached_ids);
            if ($submitted === null) {
                return false;
            }

            $contacts_api = $this->buildContactsApi($context['row']);
            $queried = [];
            foreach (array_unique(array_filter(array_column($submitted, 'external_id'))) as $contact_id) {
                $response = $contacts_api->queryContact($contact_id);
                if (!$response->success() || !is_array($response->data())) {
                    $this->setWhoisContactError('contacts', 'contact_unavailable');
                    return false;
                }
                $queried[$contact_id] = $response->data();
            }

            $updates = [];
            foreach ($submitted as $role => $contact) {
                $contact_id = $contact['external_id'];
                $details = \Webnic\WhoisContacts::mergeEditableDetails($queried[$contact_id], $contact);
                if (!\Webnic\WhoisContacts::detailsChanged($queried[$contact_id], $details)) {
                    continue;
                }

                $fingerprint = json_encode($details);
                if (isset($updates[$contact_id]) && $updates[$contact_id]['fingerprint'] !== $fingerprint) {
                    $this->setWhoisContactError('contacts[' . $role . ']', 'contact_shared_conflict');
                    return false;
                }
                $updates[$contact_id] = ['details' => $details, 'fingerprint' => $fingerprint];
            }

            $accepted = false;
            foreach ($updates as $contact_id => $update) {
                $decision = \WebnicContacts::decideModifyContact(
                    $contacts_api->modifyContact($contact_id, $update['details'])
                );
                if ($decision['outcome'] === 'failed') {
                    $key = ($decision['error_class'] ?? null) === 'retryable'
                        ? 'contact_update_unavailable'
                        : 'contact_update_failed';
                    $this->setWhoisContactError('contacts', $key);
                    $this->last_contact_update_result = $decision;
                    return false;
                }
                if (!empty($decision['pending'])) {
                    $accepted = true;
                }
            }

            $this->last_contact_update_result = [
                'outcome' => $accepted ? 'accepted' : 'ok',
                'pending' => $accepted,
                'updated' => count($updates),
            ];

            return true;
        } catch (\Throwable $e) {
            $this->logWhoisContactException(
                'contacts.update',
                (string) ($context['domain'] ?? ''),
                (int) ($context['module_row_id'] ?? 0),
                $e
            );
            $this->setWhoisContactError('contacts', 'contact_update_unavailable');
        }

        return false;
    }

    /**
     * Renders the shared admin/client WHOIS contact tab.
     *
     * @param string $view View path
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @param bool $is_admin Whether this is the admin surface
     * @return string Rendered HTML
     */
    private function manageWhoisContacts(
        string $view,
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null,
        bool $is_admin = false
    ): string {
        $contacts = [];
        $manageable = false;

        try {
            Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_whois_contacts.php');
            $snapshot = $this->domainInfoSnapshot($service, $is_admin ? 'admin_whois' : 'client_whois', true);
            $manageable = $this->serviceAllowsWhoisContacts($service, $snapshot);
            $context = $manageable ? $this->whoisContactContextFromSnapshot($snapshot) : null;

            if ($manageable && $context !== null && !empty($post)) {
                $post['_webnic_whois_require_editable'] = true;
                if ($this->setDomainContactsFromContext($context, $post)) {
                    $pending = !empty($this->last_contact_update_result['pending']);
                    $this->setMessage(
                        'notice',
                        Language::_($pending ? 'Webnic.contact_update.pending' : 'Webnic.contact_update.ok', true)
                    );
                    $context = $this->whoisContactContextFromSnapshot($snapshot);
                } else {
                    $this->setMessage('error', $this->firstInputErrorMessage('contact_update_failed'));
                }
            }

            if ($manageable && $context !== null) {
                $contacts = $this->getWhoisContactsFromContext($context);
            }
        } catch (\Throwable $e) {
            $this->logWhoisContactException(
                $is_admin ? 'contacts.admin_tab' : 'contacts.client_tab',
                (string) $this->getServiceDomain($service),
                (int) ($service->module_row_id ?? 0),
                $e
            );
            $manageable = false;
        }

        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('contacts', $contacts);
        $this->view->set('roles', \Webnic\WhoisContacts::ROLES);
        $this->view->set('manageable', $manageable);
        $this->view->set('is_admin', $is_admin);
        $this->view->set('contact_errors', $this->whoisContactErrorsForView());
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Renders the shared admin/client nameservers tab.
     *
     * @param string $view View path
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @param bool $is_admin Whether this is the admin surface
     * @return string Rendered HTML
     */
    private function manageNameservers(
        string $view,
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null,
        bool $is_admin = false
    ): string {
        $form_state = $this->nameserverFormState(null);
        $nameservers = $form_state['values'];
        $manageable = false;
        $empty_nameservers = !empty($form_state['empty']);
        $nameserver_alert_key = $form_state['alert_key'];

        try {
            $snapshot = $this->domainInfoSnapshot($service, $is_admin ? 'admin_nameservers' : 'client_nameservers', true);
            $form_state = $this->nameserverFormState($snapshot);
            $manageable = $this->serviceAllowsNameservers($service, $snapshot, false)
                && !empty($form_state['editable']);
            $empty_nameservers = !empty($form_state['empty']);
            $nameserver_alert_key = $form_state['alert_key'];
            $nameservers = $form_state['values'];

            if (!empty($post)) {
                if ($manageable) {
                    $context = $this->nameserverContextFromServiceSnapshot($service, $snapshot);
                    if ($context !== null && $this->setDomainNameserversFromContext($context, $post, true)) {
                        $snapshot = $this->domainInfoSnapshot($service, $is_admin ? 'admin_nameservers' : 'client_nameservers', true);
                        $form_state = $this->nameserverFormState($snapshot, true);
                        $manageable = $this->serviceAllowsNameservers($service, $snapshot, false)
                            && !empty($form_state['editable']);
                        $empty_nameservers = !empty($form_state['empty']);
                        $nameserver_alert_key = $form_state['alert_key'];
                        $nameservers = $form_state['values'];
                        if ($manageable) {
                            $this->setMessage('notice', Language::_('Webnic.tab_nameservers.update_ok', true));
                        }
                    } else {
                        $this->setMessage('error', $this->firstInputErrorMessage('nameserver_update_failed'));
                        $nameservers = $this->nameserverValuesFromVars($post);
                    }
                } else {
                    $this->serviceAllowsNameservers($service, $snapshot, true);
                    $this->setMessage('error', $this->firstInputErrorMessage('nameserver_not_manageable'));
                    $nameservers = $this->nameserverValuesFromVars($post);
                }
            }
        } catch (\Throwable $e) {
            $this->logServiceInfoException($is_admin ? 'nameservers_admin_tab' : 'nameservers_client_tab', $service, $e);
            $this->setNameserverError('nameservers', 'unavailable', 'nameserver_unavailable');
            $manageable = false;
        }

        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('nameservers', $nameservers);
        $this->view->set('manageable', $manageable);
        $this->view->set('is_admin', $is_admin);
        $this->view->set('empty_nameservers', $empty_nameservers);
        $this->view->set('nameserver_alert_key', $nameserver_alert_key);
        $this->view->set('nameserver_errors', $this->nameserverErrorsForView());
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Renders the shared admin/client DNS records tab.
     *
     * @param string $view View path
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @param bool $is_admin Whether this is the admin surface
     * @return string Rendered HTML
     */
    private function manageDnsRecords(
        string $view,
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null,
        bool $is_admin = false
    ): string {
        $records = [];
        $unsupported_records = [];
        $record_types = $this->dnsRecordTypesAllowlist();
        $manageable = false;
        $empty_records = false;
        $dns_alert_key = 'Webnic.tab_dnsrecords.unavailable';
        $dns_form_state = $this->emptyDnsRecordFormState();

        try {
            $snapshot = $this->domainInfoSnapshot($service, $is_admin ? 'admin_dnsrecords' : 'client_dnsrecords', true);
            $context = null;
            $base_manageable = $this->serviceAllowsDnsRecords($service, $snapshot, false);

            if ($base_manageable) {
                $context = $this->dnsRecordContextFromServiceSnapshot($service, $snapshot);
                if ($context !== null) {
                    $state = $this->dnsRecordStateFromContext($context, false);
                    $records = $state['records'];
                    $unsupported_records = $state['unsupported_records'];
                    $record_types = $state['record_types'];
                    $manageable = $state['manageable'];
                    $empty_records = $state['empty'];
                    $dns_alert_key = $state['alert_key'];
                }
            }

            if (!empty($post)) {
                $dns_form_state = $this->dnsRecordFormStateFromPost($post);
                if ($base_manageable && $context !== null) {
                    $state = $this->dnsRecordStateFromContext($context, false);
                    $record_types = $state['record_types'];
                    $manageable = $state['manageable'];
                    $dns_alert_key = $state['alert_key'];

                    if ($manageable && $this->applyDnsRecordPost($context, $post, $record_types, $state['records'])) {
                        $message_key = ((string) ($post['action'] ?? '')) === 'delete'
                            ? 'Webnic.tab_dnsrecords.record_deleted'
                            : 'Webnic.tab_dnsrecords.update_ok';
                        $this->setMessage('notice', Language::_($message_key, true));

                        $pre_write_state = $state;
                        $state = $this->dnsRecordStateFromContext($context, false);
                        if (!$state['manageable'] && !$state['empty']) {
                            $records = $pre_write_state['records'];
                            $unsupported_records = $pre_write_state['unsupported_records'];
                            $record_types = $pre_write_state['record_types'];
                            $manageable = false;
                            $empty_records = $pre_write_state['empty'];
                            $dns_alert_key = 'Webnic.tab_dnsrecords.refresh_error';
                        } else {
                            $records = $state['records'];
                            $unsupported_records = $state['unsupported_records'];
                            $record_types = $state['record_types'];
                            $manageable = $state['manageable'];
                            $empty_records = $state['empty'];
                            $dns_alert_key = $state['alert_key'];
                        }
                        $dns_form_state = $this->emptyDnsRecordFormState();
                    } else {
                        if (!$manageable) {
                            $error_key = 'dnsrecord_not_manageable';
                            if ($dns_alert_key === 'Webnic.tab_dnsrecords.not_webnic_dns') {
                                $error_key = 'dnsrecord_not_webnic_dns';
                            } elseif ($dns_alert_key === 'Webnic.tab_dnsrecords.load_error') {
                                $error_key = 'dnsrecord_unavailable';
                            } elseif ($dns_alert_key === 'Webnic.tab_dnsrecords.no_supported_types') {
                                $error_key = 'dnsrecord_no_supported_types';
                            }
                            $this->setMessage('error', Language::_('Webnic.!error.' . $error_key, true));
                        } else {
                            $this->setMessage('error', $this->firstInputErrorMessage('dnsrecord_update_failed'));
                        }
                    }
                } else {
                    $this->serviceAllowsDnsRecords($service, $snapshot, true);
                    $this->setMessage('error', $this->firstInputErrorMessage('dnsrecord_not_manageable'));
                }
            }
        } catch (\Throwable $e) {
            $this->logServiceInfoException($is_admin ? 'dnsrecords_admin_tab' : 'dnsrecords_client_tab', $service, $e);
            $this->setDnsRecordError('records', 'unavailable', 'dnsrecord_unavailable');
            $manageable = false;
        }

        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('dns_records', $records);
        $this->view->set('unsupported_dns_records', $unsupported_records);
        $this->view->set('record_types', $record_types);
        $this->view->set('manageable', $manageable);
        $this->view->set('is_admin', $is_admin);
        $this->view->set('empty_records', $empty_records);
        $this->view->set('dns_alert_key', $dns_alert_key);
        $this->view->set('dns_record_errors', $this->dnsRecordErrorsForView());
        $this->view->set('dns_form_state', $dns_form_state);
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Renders the shared admin/client URL and email forwarding tab.
     *
     * @param string $view View path
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @param bool $is_admin Whether this is the admin surface
     * @return string Rendered HTML
     */
    private function manageForwarder(
        string $view,
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null,
        bool $is_admin = false
    ): string {
        $url_forwardings = [];
        $email_forwardings = [];
        $manageable = false;
        $empty_forwarding = false;
        $forwarding_alert_key = 'Webnic.tab_forwarding.unavailable';
        $forwarding_form_state = $this->emptyForwardingFormState();
        $domain = (string) $this->getServiceDomain($service);

        try {
            $snapshot = $this->domainInfoSnapshot($service, $is_admin ? 'admin_forwarding' : 'client_forwarding', true);
            $context = null;
            $base_manageable = $this->serviceAllowsForwarding($service, $snapshot, false);

            if ($base_manageable) {
                $context = $this->forwardingContextFromServiceSnapshot($service, $snapshot, !empty($post));
                if ($context !== null) {
                    $state = $this->forwardingStateFromContext($context, false);
                    $url_forwardings = $state['url_forwardings'];
                    $email_forwardings = $state['email_forwardings'];
                    $manageable = $state['manageable'];
                    $empty_forwarding = $state['empty'];
                    $forwarding_alert_key = $state['alert_key'];
                    $domain = $context['domain'];
                }
            }

            if (!empty($post)) {
                $forwarding_form_state = $this->forwardingFormStateFromPost($post);
                if ($base_manageable && $context !== null) {
                    $state = $this->forwardingStateFromContext($context, false);
                    $manageable = $state['manageable'];
                    $forwarding_alert_key = $state['alert_key'];

                    if ($manageable && $this->applyForwardingPost(
                        $context,
                        $post,
                        $state['url_forwardings'],
                        $state['email_forwardings']
                    )) {
                        $message_key = strpos((string) ($post['action'] ?? ''), 'delete_') === 0
                            ? 'Webnic.tab_forwarding.record_deleted'
                            : 'Webnic.tab_forwarding.update_ok';
                        $this->setMessage('notice', Language::_($message_key, true));

                        $pre_write_state = $state;
                        $state = $this->forwardingStateFromContext($context, false);
                        if (!$state['manageable'] && !$state['empty']) {
                            $url_forwardings = $pre_write_state['url_forwardings'];
                            $email_forwardings = $pre_write_state['email_forwardings'];
                            $manageable = false;
                            $empty_forwarding = $pre_write_state['empty'];
                            $forwarding_alert_key = 'Webnic.tab_forwarding.refresh_error';
                        } else {
                            $url_forwardings = $state['url_forwardings'];
                            $email_forwardings = $state['email_forwardings'];
                            $manageable = $state['manageable'];
                            $empty_forwarding = $state['empty'];
                            $forwarding_alert_key = $state['alert_key'];
                        }
                        $forwarding_form_state = $this->emptyForwardingFormState();
                    } elseif ($this->forwarding_post_inconclusive) {
                        // AC #5 option A: the write likely applied but the follow-up read could not
                        // confirm it. Show the neutral refresh hint (no success notice, no error).
                        $url_forwardings = $state['url_forwardings'];
                        $email_forwardings = $state['email_forwardings'];
                        $manageable = false;
                        $empty_forwarding = $state['empty'];
                        $forwarding_alert_key = 'Webnic.tab_forwarding.refresh_error';
                        $forwarding_form_state = $this->emptyForwardingFormState();
                    } else {
                        if (!$manageable) {
                            $error_key = $forwarding_alert_key === 'Webnic.tab_forwarding.not_webnic_dns'
                                ? 'forwarding_not_webnic_dns'
                                : 'forwarding_not_manageable';
                            if ($forwarding_alert_key === 'Webnic.tab_forwarding.load_error') {
                                $error_key = 'forwarding_unavailable';
                            }
                            $this->setMessage('error', Language::_('Webnic.!error.' . $error_key, true));
                        } else {
                            $this->setMessage('error', $this->firstInputErrorMessage('forwarding_update_failed'));
                        }
                    }
                } else {
                    $this->serviceAllowsForwarding($service, $snapshot, true);
                    $this->setMessage('error', $this->firstInputErrorMessage('forwarding_not_manageable'));
                }
            }
        } catch (\Throwable $e) {
            $this->logServiceInfoException($is_admin ? 'forwarding_admin_tab' : 'forwarding_client_tab', $service, $e);
            $this->setForwardingError('forwarding', 'unavailable', 'forwarding_unavailable');
            $manageable = false;
        }

        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('forwarding_urls', $url_forwardings);
        $this->view->set('forwarding_emails', $email_forwardings);
        $this->view->set('forwarding_domain', $domain);
        $this->view->set('manageable', $manageable);
        $this->view->set('is_admin', $is_admin);
        $this->view->set('empty_forwarding', $empty_forwarding);
        $this->view->set('forwarding_alert_key', $forwarding_alert_key);
        $this->view->set('forwarding_errors', $this->forwardingErrorsForView());
        $this->view->set('forwarding_form_state', $forwarding_form_state);
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Renders the shared admin/client DNSSEC (DS records) tab.
     *
     * Mirrors manageDnsRecords but adds the AC2 TLD-support gate: a live
     * Check DNSSEC Supported read up front drives the supported/unsupported/temporarily-
     * unavailable states, and add/remove controls are only reachable when supported.
     *
     * @param string $view View path
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @param bool $is_admin Whether this is the admin surface
     * @return string Rendered HTML
     */
    private function manageDnssec(
        string $view,
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null,
        bool $is_admin = false
    ): string {
        $records = [];
        $supported = false;
        $support_state = 'unknown';
        $manageable = false;
        $empty_records = false;
        $dnssec_alert_key = 'Webnic.tab_dnssec.unavailable';
        $dnssec_form_state = $this->emptyDnssecFormState();
        $domain = (string) $this->getServiceDomain($service);

        try {
            $snapshot = $this->domainInfoSnapshot($service, $is_admin ? 'admin_dnssec' : 'client_dnssec', true);
            $context = null;
            $base_manageable = $this->serviceAllowsDnssec($service, $snapshot, false);

            if ($base_manageable) {
                $context = $this->dnssecContextFromServiceSnapshot($service, $snapshot, !empty($post));
                if ($context !== null) {
                    $domain = $context['domain'];
                    $state = $this->dnssecStateFromContext($context, false);
                    $records = $state['records'];
                    $supported = $state['supported'];
                    $support_state = $state['support_state'];
                    $manageable = $state['manageable'];
                    $empty_records = $state['empty'];
                    $dnssec_alert_key = $state['alert_key'];
                }
            } elseif (empty($snapshot['ok']) && empty($snapshot['local_invalid'])) {
                $dnssec_alert_key = 'Webnic.!error.dnssec_unavailable';
            }

            if (!empty($post)) {
                $dnssec_form_state = $this->dnssecFormStateFromPost($post);
                if ($base_manageable && $context !== null) {
                    $state = $this->dnssecStateFromContext($context, false);
                    $supported = $state['supported'];
                    $support_state = $state['support_state'];
                    $manageable = $state['manageable'];
                    $dnssec_alert_key = $state['alert_key'];

                    if ($manageable && $this->applyDnssecPost($context, $post, $state['records'])) {
                        $message_key = ((string) ($post['action'] ?? '')) === 'delete'
                            ? 'Webnic.tab_dnssec.record_removed'
                            : 'Webnic.tab_dnssec.update_ok';
                        $this->setMessage('notice', Language::_($message_key, true));

                        $pre_write_state = $state;
                        $state = $this->dnssecStateFromContext($context, false);
                        if (!$state['manageable']) {
                            // WN-5.5 round-2 landmine (l): never render success beside a failed
                            // refresh read — keep the notice, the pre-write rows, and a neutral hint.
                            $records = $pre_write_state['records'];
                            $supported = $pre_write_state['supported'];
                            $support_state = $pre_write_state['support_state'];
                            $manageable = false;
                            $empty_records = $pre_write_state['empty'];
                            $dnssec_alert_key = 'Webnic.tab_dnssec.refresh_error';
                        } else {
                            $records = $state['records'];
                            $supported = $state['supported'];
                            $support_state = $state['support_state'];
                            $manageable = $state['manageable'];
                            $empty_records = $state['empty'];
                            $dnssec_alert_key = $state['alert_key'];
                        }
                        $dnssec_form_state = $this->emptyDnssecFormState();
                    } else {
                        if (!$manageable) {
                            $error_key = 'dnssec_not_manageable';
                            if ($dnssec_alert_key === 'Webnic.tab_dnssec.unsupported') {
                                $error_key = 'dnssec_unsupported';
                            } elseif (in_array($dnssec_alert_key, ['Webnic.tab_dnssec.load_error', 'Webnic.!error.dnssec_unavailable'], true)) {
                                $error_key = 'dnssec_unavailable';
                            }
                            $this->setMessage('error', Language::_('Webnic.!error.' . $error_key, true));
                        } else {
                            $this->setMessage('error', $this->firstInputErrorMessage('dnssec_update_failed'));
                        }
                    }
                } else {
                    $this->serviceAllowsDnssec($service, $snapshot, true);
                    $this->setMessage('error', $this->firstInputErrorMessage('dnssec_not_manageable'));
                }
            }
        } catch (\Throwable $e) {
            $this->logServiceInfoException($is_admin ? 'dnssec_admin_tab' : 'dnssec_client_tab', $service, $e);
            $this->setDnssecError('records', 'unavailable', 'dnssec_unavailable');
            $manageable = false;
            // A genuine swallowed failure must not leave the initial "available once active"
            // copy showing — render an explicit load-error alert and a clean support state.
            $supported = false;
            $support_state = 'unknown';
            $dnssec_alert_key = 'Webnic.tab_dnssec.load_error';
        }

        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('dnssec_records', $records);
        $this->view->set('dnssec_domain', $domain);
        $this->view->set('supported', $supported);
        $this->view->set('support_state', $support_state);
        $this->view->set('manageable', $manageable);
        $this->view->set('is_admin', $is_admin);
        $this->view->set('empty_records', $empty_records);
        $this->view->set('dnssec_alert_key', $dnssec_alert_key);
        $this->view->set('dnssec_algorithms', $this->dnssecAlgorithmAllowlist());
        $this->view->set('dnssec_digest_types', $this->dnssecDigestTypeAllowlist());
        $this->view->set('dnssec_errors', $this->dnssecErrorsForView());
        $this->view->set('dnssec_form_state', $dnssec_form_state);
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Handles rendering and POST actions for the Settings tab.
     *
     * @param string $view View path
     * @param stdClass $package Current package
     * @param stdClass $service Current service
     * @param array|null $get GET parameters
     * @param array|null $post POST parameters
     * @param array|null $files FILES parameters
     * @param bool $is_admin Whether this is the admin surface
     * @return string Rendered HTML
     */
    private function manageSettings(
        string $view,
        $package,
        $service,
        array $get = null,
        array $post = null,
        array $files = null,
        bool $is_admin = false
    ): string {
        $domain = (string) $this->getServiceDomain($service);
        $manageable = false;
        $settings_alert_key = 'Webnic.tab_settings.unavailable';
        $settings_state = [
            'domain' => $domain,
            'status' => '',
            'locked' => false,
            'whois_privacy' => false,
            'auto_renew' => null,
            'privacy' => [
                'state' => 'unavailable',
                'capable' => false,
                'alert_key' => 'Webnic.tab_settings.privacy_capability_unavailable',
            ],
            'privacy_capable' => false,
            'privacy_state' => 'unavailable',
            'epp_sendable' => true,
            'epp_resettable' => true,
        ];

        try {
            $snapshot = $this->domainInfoSnapshot($service, $is_admin ? 'admin_settings' : 'client_settings', true);
            $context = null;
            $base_manageable = $this->serviceAllowsSettings($service, $snapshot, false);

            if ($base_manageable) {
                $context = $this->settingsContextFromServiceSnapshot($service, $snapshot, !empty($post));
                if ($context !== null) {
                    $domain = $context['domain'];
                    $settings_state = $this->settingsStateFromSnapshot($service, $snapshot, $context['row']);
                    $manageable = true;
                }
            } elseif (empty($snapshot['ok']) && empty($snapshot['local_invalid'])) {
                $settings_alert_key = 'Webnic.tab_settings.load_error';
            }

            if (!empty($post)) {
                if ($base_manageable && $context !== null) {
                    $ok = $this->applySettingsPost($context, $post, $is_admin);
                    if ($ok) {
                        $snapshot = $this->domainInfoSnapshotForRowDomain(
                            (string) $context['domain'],
                            (int) $context['module_row_id'],
                            $is_admin ? 'admin_settings_refresh' : 'client_settings_refresh',
                            true,
                            $service
                        );
                        if ($this->serviceAllowsSettings($service, $snapshot, false)) {
                            $context['snapshot'] = $snapshot;
                            $settings_state = $this->settingsStateFromSnapshot($service, $snapshot, $context['row']);
                            $manageable = true;
                        } else {
                            $manageable = false;
                            $settings_alert_key = empty($snapshot['ok']) && empty($snapshot['local_invalid'])
                                ? 'Webnic.tab_settings.refresh_error'
                                : 'Webnic.tab_settings.unavailable';
                        }
                    } elseif (empty($this->last_settings_action_result)) {
                        $this->setMessage('error', $this->firstInputErrorMessage('settings_update_failed'));
                    }
                } else {
                    $this->serviceAllowsSettings($service, $snapshot, true);
                    $this->setMessage('error', $this->firstInputErrorMessage('settings_not_manageable'));
                }
            }
        } catch (\Throwable $e) {
            $this->logServiceInfoException($is_admin ? 'settings_admin_tab' : 'settings_client_tab', $service, $e);
            $this->setSettingsError('settings', 'unavailable', 'settings_unavailable');
            $manageable = false;
            $settings_alert_key = 'Webnic.tab_settings.load_error';
        }

        $this->view = new View($view, 'default');
        Loader::loadHelpers($this, ['Form', 'Html']);

        $this->view->set('settings_domain', $domain);
        $this->view->set('settings_state', $settings_state);
        $this->view->set('manageable', $manageable);
        $this->view->set('is_admin', $is_admin);
        $this->view->set('settings_alert_key', $settings_alert_key);
        $this->view->set('settings_errors', $this->settingsErrorsForView());
        $this->view->setDefaultView(self::$defaultModuleView);

        return $this->view->fetch();
    }

    /**
     * Builds the WebnicDnssec command client for a module row.
     *
     * @param stdClass $row Module row
     * @return WebnicDnssec
     */
    private function buildDnssecApi($row)
    {
        $this->loadReconcilerDependencies();
        Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_dnssec.php');

        return new \WebnicDnssec($this->buildWebnicApi($row));
    }

    /**
     * Returns whether the current service may view or mutate Settings actions.
     *
     * @param stdClass $service Service being managed
     * @param array $snapshot Cached domain info snapshot
     * @param bool $set_errors Whether to populate Input errors on rejection
     * @return bool True when local, order, and live registry gates all pass
     */
    private function serviceAllowsSettings($service, array $snapshot, bool $set_errors = true): bool
    {
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');

        if ($this->isLocalCancelledService($service)) {
            if ($set_errors) {
                $this->setSettingsError('settings', 'not_manageable', 'settings_not_manageable');
            }
            return false;
        }

        if (strtolower((string) ($service->status ?? '')) !== 'active') {
            if ($set_errors) {
                $this->setSettingsError('settings', 'not_manageable', 'settings_not_manageable');
            }
            return false;
        }

        $order = $this->resolveOrderByDomain($service);
        if ($order !== null && (string) ($order->state ?? '') !== \Webnic\Orders::STATE_ACTIVE) {
            if ($set_errors) {
                $this->setSettingsError('settings', 'not_manageable', 'settings_not_manageable');
            }
            return false;
        }

        if (empty($snapshot['ok'])) {
            if ($set_errors) {
                $this->setSettingsError('settings', 'unavailable', 'settings_unavailable');
            }
            return false;
        }

        if (!$this->domainInfoSnapshotIsManageable($snapshot)
            || self::isRegistryTrue(($snapshot['data'] ?? [])['suspended'] ?? false)
        ) {
            if ($set_errors) {
                $this->setSettingsError('settings', 'not_manageable', 'settings_not_manageable');
            }
            return false;
        }

        return true;
    }

    /**
     * Builds a Settings operation context from the already-read service-tab snapshot.
     *
     * @param stdClass $service Service being managed
     * @param array $snapshot Cached domain info snapshot
     * @param bool $set_errors Whether local prerequisite failures should populate Input errors
     * @return array|null Settings context, or null when local data is incomplete
     */
    private function settingsContextFromServiceSnapshot($service, array $snapshot, bool $set_errors = false)
    {
        $this->loadReconcilerDependencies();

        $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
        $module_row_id = (int) ($service->module_row_id ?? 0);
        if ($domain === '') {
            if ($set_errors) {
                $this->setSettingsError('domain', 'missing', 'settings_domain_missing');
            }
            return null;
        }
        if ($module_row_id <= 0) {
            if ($set_errors) {
                $this->setSettingsError('module_row_id', 'row_unavailable', 'settings_row_unavailable');
            }
            return null;
        }

        $row = $this->getModuleRow($module_row_id);
        if (empty($row)) {
            if ($set_errors) {
                $this->setSettingsError('module_row_id', 'row_unavailable', 'settings_row_unavailable');
            }
            return null;
        }

        return [
            'domain' => $domain,
            'module_row_id' => $module_row_id,
            'row' => $row,
            'service' => $service,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Builds a row/domain context for direct RegistrarModule Settings hooks.
     *
     * @param string $domain Domain name
     * @param int $module_row_id Module row id
     * @param bool $set_errors Whether local failures should populate Input errors
     * @param mixed $service Optional service for tab-side audit correlation
     * @return array|null Settings context, or null when local data is incomplete
     */
    private function settingsRegistrarContext(string $domain, int $module_row_id, bool $set_errors = false, $service = null)
    {
        $this->loadReconcilerDependencies();

        $domain = \Webnic\Orders::normalizeDomain($domain);
        if ($domain === '') {
            if ($set_errors) {
                $this->setSettingsError('domain', 'missing', 'settings_domain_missing');
            }
            return null;
        }
        if ($module_row_id <= 0) {
            if ($set_errors) {
                $this->setSettingsError('module_row_id', 'row_unavailable', 'settings_row_unavailable');
            }
            return null;
        }

        $row = $this->getModuleRow($module_row_id);
        if (empty($row)) {
            if ($set_errors) {
                $this->setSettingsError('module_row_id', 'row_unavailable', 'settings_row_unavailable');
            }
            return null;
        }

        return [
            'domain' => $domain,
            'module_row_id' => $module_row_id,
            'row' => $row,
            'service' => $service,
        ];
    }

    /**
     * Builds view-safe Settings state from the cached domain-info snapshot.
     *
     * @param stdClass $service Service being managed
     * @param array $snapshot Cached domain info snapshot
     * @param stdClass $row Module row
     * @return array View state
     */
    private function settingsStateFromSnapshot($service, array $snapshot, $row): array
    {
        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        $privacy = $this->settingsPrivacyCapabilityState($service, $snapshot, $row);

        return [
            'domain' => (string) ($snapshot['domain'] ?? $this->getServiceDomain($service)),
            'status' => (string) ($snapshot['status'] ?? ''),
            'locked' => $this->domainInfoSnapshotIsLocked($snapshot),
            'whois_privacy' => self::isRegistryTrue($data['whoisPrivacy'] ?? false),
            'auto_renew' => array_key_exists('autoRenew', $data) && $data['autoRenew'] !== null
                ? self::isRegistryTrue($data['autoRenew'])
                : null,
            'privacy' => $privacy,
            'privacy_capable' => ($privacy['state'] ?? '') === 'capable',
            'privacy_state' => (string) ($privacy['state'] ?? 'unavailable'),
            'epp_sendable' => true,
            'epp_resettable' => true,
        ];
    }

    /**
     * Reads the WHOIS privacy TLD capability as capable/not_capable/unavailable.
     *
     * @param stdClass $service Service being managed
     * @param array $snapshot Cached domain info snapshot
     * @param stdClass $row Module row
     * @return array Tri-state privacy capability
     */
    private function settingsPrivacyCapabilityState($service, array $snapshot, $row): array
    {
        try {
            $domain = (string) ($snapshot['domain'] ?? $this->getServiceDomain($service));
            $ext = $this->domainExtension($domain);
            if ($ext === '') {
                return [
                    'state' => 'unavailable',
                    'capable' => false,
                    'alert_key' => 'Webnic.tab_settings.privacy_capability_unavailable',
                ];
            }

            $rules = $this->fetchRegistrationRules($ext, $row);
            // Capability is only definitive when the ext-rules map carries an explicit
            // whoisPrivacy flag. A null read (failure/no-row) OR a success envelope with no
            // usable whoisPrivacy key (an empty/partial/self-healed rules map) is UNKNOWN,
            // not proven unsupported — render temporary-unavailable, never the definitive
            // "not available for this TLD" copy. (review: CLAUDE,CODEX)
            if (!is_array($rules) || !array_key_exists('whoisPrivacy', $rules)) {
                return [
                    'state' => 'unavailable',
                    'capable' => false,
                    'alert_key' => 'Webnic.tab_settings.privacy_capability_unavailable',
                ];
            }

            if ($rules['whoisPrivacy'] === true) {
                return [
                    'state' => 'capable',
                    'capable' => true,
                    'alert_key' => null,
                ];
            }

            // The provider explicitly reported whoisPrivacy and it is not enabled for this TLD.
            return [
                'state' => 'not_capable',
                'capable' => false,
                'alert_key' => 'Webnic.tab_settings.privacy_not_capable',
            ];
        } catch (\Throwable $e) {
            $this->logServiceInfoException('settings_privacy_capability', $service, $e);

            return [
                'state' => 'unavailable',
                'capable' => false,
                'alert_key' => 'Webnic.tab_settings.privacy_capability_unavailable',
            ];
        }
    }

    /**
     * Applies a validated Settings POST action.
     *
     * @param array $context Settings context
     * @param array $post Submitted POST
     * @param bool $is_admin Whether this is the admin surface
     * @return bool True when the provider accepted the action
     */
    private function applySettingsPost(array $context, array $post, bool $is_admin): bool
    {
        $this->last_settings_action_result = null;
        $action = $this->settingsActionFromPost($post, $is_admin);
        if ($action === null) {
            return false;
        }

        if (!$this->serviceAllowsSettings($context['service'], $context['snapshot'], true)) {
            return false;
        }

        if (in_array($action, ['privacy_on', 'privacy_off'], true)) {
            $privacy = $this->settingsPrivacyCapabilityState($context['service'], $context['snapshot'], $context['row']);
            if (($privacy['state'] ?? '') !== 'capable') {
                $this->setSettingsError(
                    'privacy',
                    'not_capable',
                    ($privacy['state'] ?? '') === 'not_capable'
                        ? 'settings_privacy_not_capable'
                        : 'settings_privacy_capability_unavailable'
                );

                return false;
            }

            $ok = $this->applySettingsPrivacyAction($context, $action === 'privacy_on');
            $this->setMessage(
                $ok ? 'notice' : 'error',
                $ok
                    ? Language::_($this->settingsSuccessMessageKey($action), true)
                    : $this->firstInputErrorMessage('settings_update_failed')
            );

            return $ok;
        }

        $ok = $this->performSettingsDomainAction(
            (string) $context['domain'],
            (int) $context['module_row_id'],
            $action,
            $context['service']
        );
        // The EPP-sent seam now fires inside performSettingsDomainAction() so direct
        // RegistrarModule calls emit it too; no tab-side re-emit here (review: GLM).
        $this->setMessage(
            $ok ? 'notice' : 'error',
            $ok
                ? Language::_($this->settingsSuccessMessageKey($action), true)
                : $this->firstInputErrorMessage('settings_update_failed')
        );

        return $ok;
    }

    /**
     * Extracts and validates the requested Settings action.
     *
     * @param array $post Submitted POST
     * @param bool $is_admin Whether this is the admin surface
     * @return string|null Valid action, or null when rejected
     */
    private function settingsActionFromPost(array $post, bool $is_admin)
    {
        $raw = $post['action'] ?? '';
        if (!is_scalar($raw)) {
            $this->setSettingsError('action', 'invalid', 'settings_action_invalid');
            return null;
        }

        $action = strtolower(trim((string) $raw));
        $allowed = ['lock', 'unlock', 'send_epp', 'privacy_on', 'privacy_off'];
        if ($is_admin) {
            $allowed[] = 'reset_epp';
        }
        if (!in_array($action, $allowed, true)) {
            $this->setSettingsError('action', 'invalid', 'settings_action_invalid');
            return null;
        }

        return $action;
    }

    /**
     * Applies registrar-lock/EPP actions through the WebNIC domain command group.
     *
     * @param string $domain Domain name
     * @param int $module_row_id Module row id
     * @param string $action lock|unlock|send_epp|reset_epp
     * @param mixed $service Optional service for tab-side audit correlation
     * @return bool True when WebNIC accepted the action
     */
    private function performSettingsDomainAction(string $domain, int $module_row_id, string $action, $service = null): bool
    {
        $this->last_settings_action_result = null;
        $context = null;

        try {
            if (!in_array($action, ['lock', 'unlock', 'send_epp', 'reset_epp'], true)) {
                $this->setSettingsError('action', 'invalid', 'settings_action_invalid');
                return false;
            }

            $context = $this->settingsRegistrarContext($domain, $module_row_id, true, $service);
            if ($context === null) {
                return false;
            }

            // Direct RegistrarModule hook surface (lockDomain/unlockDomain/sendEppEmail/
            // updateEppCode) carries no $service, so the tab's local/order gate never ran.
            // Decision (review: CLAUDE,GLM): fail closed on a live registry-manageability check
            // before any provider write, so Blesta core / Domain Manager cannot drive a Settings
            // write for a domain the tab would hide or reject. The tab POST path passes a
            // $service and has already run serviceAllowsSettings(), so it skips this read.
            if ($service === null && !$this->settingsLiveManageable($context)) {
                $this->setSettingsError('settings', 'not_manageable', 'settings_not_manageable');
                return false;
            }

            $api = $this->buildDomainsApi($context['row']);
            switch ($action) {
                case 'lock':
                    $response = $api->updateDomainStatus($context['domain'], 'transfer_protected');
                    break;
                case 'unlock':
                    $response = $api->updateDomainStatus($context['domain'], 'active');
                    break;
                case 'send_epp':
                    $response = $api->sendAuthorizationInfo($context['domain']);
                    break;
                default:
                    $response = $api->resetAuthorizationInfo($context['domain']);
                    break;
            }

            $provider_ok = $response instanceof \WebnicResponse && $response->success();
            $error_class = $provider_ok ? null : ($response instanceof \WebnicResponse ? $response->errorClass() : 'indeterminate');
            $ok = $provider_ok;
            if (!$provider_ok) {
                $this->setSettingsError('settings', 'update_failed', $this->settingsProviderFailureKey($error_class));
            }

            // The write-attempt audit reflects the provider's own verdict (one record per
            // accepted write), independent of the post-write confirmation below.
            $this->last_settings_action_result = [
                'outcome' => $provider_ok ? 'ok' : 'failed',
                'command' => 'service_info.settings.' . $action,
                'error_class' => $error_class,
                'action' => $action,
            ];
            $this->logSettingsProviderAttempt($context, $this->last_settings_action_result);

            if ($provider_ok) {
                $this->clearDomainInfoSnapshotCache((int) $context['module_row_id'], (string) $context['domain']);
                if (in_array($action, ['lock', 'unlock'], true)) {
                    // Finding 3: a code:1000 accept is not proof the registry status flipped.
                    // Confirm against a fresh read before surfacing success; an unconfirmed or
                    // contradicting read shows the unconfirmed state instead of a success notice
                    // beside stale controls (review: CODEX,GLM). The confirm read repopulates the
                    // row/domain cache so the tab refresh reuses it (no extra info() call).
                    if (!$this->settingsLockChangeConfirmed($context, $action === 'lock')) {
                        $ok = false;
                        $this->setSettingsError('settings', 'update_unconfirmed', 'settings_update_unconfirmed');
                    }
                } elseif ($action === 'send_epp') {
                    // Fire the Story 6.1 EPP-sent seam from the command path so a direct
                    // RegistrarModule sendEppEmail() call (Blesta core / Domain Manager) gets
                    // the webnic/epp_sent record + client-notice hook, not only tab POSTs.
                    // (review: GLM) The direct hook carries no $service, so resolve one by domain
                    // (best-effort) before firing so dispatchClientNotice('epp_sent') reaches the
                    // client too, matching the tab path. (review round-2: Kimi)
                    if (!is_object($context['service'] ?? null)) {
                        $module = $this->getModule();
                        $module_id = is_object($module) ? (int) ($module->id ?? 0) : 0;
                        $context['service'] = $this->resolveServiceByDomain(
                            $module_id,
                            (int) ($context['module_row_id'] ?? 0),
                            (string) ($context['domain'] ?? '')
                        );
                    }
                    $this->emitSettingsEppSent($context);
                }
            }

            return $ok;
        } catch (\Throwable $e) {
            // Emit exactly ONE scrubbed webnic/service_info record for the attempted write,
            // always with the structured command=service_info.settings.<action> shape (review:
            // CLAUDE,CODEX,GLM). The exception diagnostic rides inside that single record
            // (Redactor-scrubbed) rather than spawning a second generic
            // service_info.settings_<action> record via logServiceInfoException. A throw before
            // the full context exists still gets the structured shape from a minimal fallback.
            $log_context = is_array($context) ? $context : [
                'service' => $service,
                'module_row_id' => $module_row_id,
                'domain' => (string) $domain,
            ];
            $this->last_settings_action_result = [
                'outcome' => 'failed',
                'command' => 'service_info.settings.' . $action,
                'error_class' => 'indeterminate',
                'action' => $action,
                // Record only the exception CLASS, never getMessage(): the message can carry a
                // secret-bearing fragment (bare token, URL-path secret, unknown key) that the
                // key-based Redactor does not mask, and the class is enough for triage. Keeps the
                // structured record to the AC6 field set + a class marker. (review round-2: GLM)
                'message' => get_class($e),
            ];
            $this->logSettingsProviderAttempt($log_context, $this->last_settings_action_result);
            $this->setSettingsError('settings', 'unavailable', 'settings_update_unavailable');
        }

        return false;
    }

    /**
     * Fail-closed live registry-manageability gate for the direct RegistrarModule hook surface.
     *
     * The local/order portion of serviceAllowsSettings() needs a $service object the direct
     * hooks do not have, so this enforces the live half — registry-manageable and not suspended
     * — before a hook-driven provider write. Any read failure or exception fails closed.
     *
     * @param array $context Settings registrar context (domain, module_row_id, service?)
     * @return bool True only when the live registry status permits a settings write
     */
    private function settingsLiveManageable(array $context): bool
    {
        try {
            $snapshot = $this->domainInfoSnapshotForRowDomain(
                (string) ($context['domain'] ?? ''),
                (int) ($context['module_row_id'] ?? 0),
                'settings_hook_gate',
                false,
                $context['service'] ?? null
            );

            return !empty($snapshot['ok'])
                && $this->domainInfoSnapshotIsManageable($snapshot)
                && !self::isRegistryTrue(($snapshot['data'] ?? [])['suspended'] ?? false);
        } catch (\Throwable $e) {
            $this->logServiceInfoException('settings_hook_gate', $context['service'] ?? null, $e);

            return false;
        }
    }

    /**
     * Confirms a lock/unlock write actually flipped the live registry status.
     *
     * Reads a fresh (cache already cleared) snapshot and checks the locked state matches the
     * requested target. A read failure, exception, or contradicting status returns false so the
     * caller surfaces the unconfirmed state rather than a success notice beside stale controls.
     *
     * @param array $context Settings registrar context
     * @param bool $expect_locked True after a lock, false after an unlock
     * @return bool True only when the fresh read confirms the requested state
     */
    private function settingsLockChangeConfirmed(array $context, bool $expect_locked): bool
    {
        try {
            $snapshot = $this->domainInfoSnapshotForRowDomain(
                (string) ($context['domain'] ?? ''),
                (int) ($context['module_row_id'] ?? 0),
                'settings_lock_confirm',
                false,
                $context['service'] ?? null
            );
            if (empty($snapshot['ok'])) {
                return false;
            }

            return $this->domainInfoSnapshotIsLocked($snapshot) === $expect_locked;
        } catch (\Throwable $e) {
            $this->logServiceInfoException('settings_lock_confirm', $context['service'] ?? null, $e);

            return false;
        }
    }

    /**
     * Applies a WHOIS privacy toggle through the already-cracked WN-3-5 command/classifier.
     *
     * @param array $context Settings context
     * @param bool $active Desired privacy state
     * @return bool True when privacy ends in the desired state
     */
    private function applySettingsPrivacyAction(array $context, bool $active): bool
    {
        $action = $active ? 'privacy_on' : 'privacy_off';

        try {
            $response = $this->buildDomainsApi($context['row'])->toggleWhoisPrivacy($context['domain'], $active);
            // Mirror the lock/EPP path's guard: an unexpected non-WebnicResponse return is
            // classified indeterminate here rather than fataling on ->errorClass() and falling
            // through to the catch. (review: GLM)
            $ok = $response instanceof \WebnicResponse && \WebnicResponse::privacyToggleSucceeded($response, $active);
            $error_class = $ok ? null : ($response instanceof \WebnicResponse ? $response->errorClass() : 'indeterminate');
            if (!$ok) {
                $this->setSettingsError('privacy', 'update_failed', $this->settingsProviderFailureKey($error_class));
            }

            $result = [
                'outcome' => $ok ? 'ok' : 'failed',
                'command' => 'service_info.settings.' . $action,
                'error_class' => $error_class,
                'action' => $action,
            ];
            $this->last_settings_action_result = $result;
            $this->logSettingsProviderAttempt($context, $result);

            if ($ok) {
                $this->clearDomainInfoSnapshotCache((int) $context['module_row_id'], (string) $context['domain']);
            }

            return $ok;
        } catch (\Throwable $e) {
            // One scrubbed webnic/service_info record only (review: CLAUDE,CODEX,GLM): the
            // exception diagnostic rides inside the structured attempt record rather than a
            // second generic service_info.settings_<action> record.
            $result = [
                'outcome' => 'failed',
                'command' => 'service_info.settings.' . $action,
                'error_class' => 'indeterminate',
                'action' => $action,
                // Class only, never getMessage() — see the lock/EPP catch above. (review round-2: GLM)
                'message' => get_class($e),
            ];
            $this->last_settings_action_result = $result;
            $this->logSettingsProviderAttempt($context, $result);
            $this->setSettingsError('privacy', 'unavailable', 'settings_update_unavailable');
        }

        return false;
    }

    /**
     * Logs successful EPP-send seam for Story 6.1 email/template integration.
     *
     * @param array $context Settings context
     */
    private function emitSettingsEppSent(array $context): void
    {
        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
            $record = [
                'level' => 'info',
                'service_id' => is_object($context['service'] ?? null) ? ($context['service']->id ?? null) : null,
                'module_row_id' => (int) ($context['module_row_id'] ?? 0),
                'domain' => (string) ($context['domain'] ?? ''),
                'command' => 'service_info.settings.epp_sent',
                'outcome' => 'ok',
            ];
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $this->log('webnic/epp_sent', serialize($scrubbed), 'output', true);
        } catch (\Throwable $ignored) {
            // Observability must never interrupt the settings action.
        }

        if (is_object($context['service'] ?? null)) {
            $this->dispatchClientNotice('epp_sent', $context['service'], ['domain' => $context['domain'] ?? null]);
        }
    }

    /**
     * Maps provider failure class to a localized settings error key.
     *
     * @param string|null $error_class retryable|terminal|indeterminate
     * @return string Webnic.!error.* suffix
     */
    private function settingsProviderFailureKey($error_class): string
    {
        return $error_class === 'retryable' ? 'settings_update_unavailable' : 'settings_update_failed';
    }

    /**
     * Returns the localized success message key for one Settings action.
     *
     * @param string $action Settings action
     * @return string Language key
     */
    private function settingsSuccessMessageKey(string $action): string
    {
        $keys = [
            'lock' => 'Webnic.tab_settings.lock_on_ok',
            'unlock' => 'Webnic.tab_settings.lock_off_ok',
            'send_epp' => 'Webnic.tab_settings.epp_send_ok',
            'reset_epp' => 'Webnic.tab_settings.epp_reset_ok',
            'privacy_on' => 'Webnic.tab_settings.privacy_on_ok',
            'privacy_off' => 'Webnic.tab_settings.privacy_off_ok',
        ];

        return $keys[$action] ?? 'Webnic.tab_settings.update_ok';
    }

    /**
     * Sets a localized Settings field error, merging into the current Input error bag.
     *
     * @param string $field Field name
     * @param string $code Error code
     * @param string $language_key Language key suffix under Webnic.!error.*
     */
    private function setSettingsError(string $field, string $code, string $language_key): void
    {
        if (isset($this->Input)) {
            $errors = $this->errors();
            if (!is_array($errors)) {
                $errors = [];
            }
            if (!isset($errors[$field]) || !is_array($errors[$field])) {
                $errors[$field] = [];
            }
            $errors[$field][$code] = Language::_('Webnic.!error.' . $language_key, true);
            $this->Input->setErrors($errors);
        }
    }

    /**
     * Flattens current Input errors for Settings tab rendering.
     *
     * @return array field => string[]
     */
    private function settingsErrorsForView(): array
    {
        $errors = $this->errors();
        if (!is_array($errors)) {
            return [];
        }

        $known = ['action', 'domain', 'module_row_id', 'settings', 'privacy'];
        $flat = [];
        foreach ($errors as $field => $messages) {
            $field = (string) $field;
            $bucket = in_array($field, $known, true) ? $field : '_general';
            foreach ($this->flattenWhoisContactMessages($messages) as $message) {
                $flat[$bucket][] = $message;
            }
        }

        return $flat;
    }

    /**
     * Logs Settings provider write attempts through the scrubbed service-info sink.
     *
     * @param array $context Settings context
     * @param array $result Attempt result
     */
    private function logSettingsProviderAttempt(array $context, array $result): void
    {
        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
            $outcome = (string) ($result['outcome'] ?? 'failed');
            $record = [
                'level' => $outcome === 'ok' ? 'info' : 'error',
                'service_id' => is_object($context['service'] ?? null) ? ($context['service']->id ?? null) : null,
                'module_row_id' => (int) ($context['module_row_id'] ?? 0),
                'domain' => (string) ($context['domain'] ?? ''),
                'command' => (string) ($result['command'] ?? 'service_info.settings'),
                'outcome' => $outcome,
                'error_class' => $result['error_class'] ?? null,
            ];
            // Carry the exception diagnostic (when present) inside this single record so the
            // write path needs no second logServiceInfoException entry; Redactor scrubs it.
            if (isset($result['message']) && $result['message'] !== '') {
                $record['message'] = (string) $result['message'];
            }
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $this->log('webnic/service_info', serialize($scrubbed), 'output', $outcome === 'ok');
        } catch (\Throwable $ignored) {
            // Logging must never interrupt service management.
        }
    }

    /**
     * Returns whether the current service may view or mutate DNSSEC DS records.
     *
     * Identical local/order/live gate as serviceAllowsNameservers/serviceAllowsDnsRecords.
     * The TLD-support gate is NOT here — it is a live read in dnssecStateFromContext (AC2),
     * kept out of the shared domainInfoSnapshotIsManageable() helper (WN-5.x landmine).
     *
     * @param stdClass $service Service being managed
     * @param array $snapshot Cached domain info snapshot
     * @param bool $set_errors Whether to populate Input errors on rejection
     * @return bool True when local, order, and live registry gates all pass
     */
    private function serviceAllowsDnssec($service, array $snapshot, bool $set_errors = true): bool
    {
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');

        if ($this->isLocalCancelledService($service)) {
            if ($set_errors) {
                $this->setDnssecError('records', 'not_manageable', 'dnssec_not_manageable');
            }
            return false;
        }

        if (strtolower((string) ($service->status ?? '')) !== 'active') {
            if ($set_errors) {
                $this->setDnssecError('records', 'not_manageable', 'dnssec_not_manageable');
            }
            return false;
        }

        $order = $this->resolveOrderByDomain($service);
        if ($order !== null && (string) ($order->state ?? '') !== \Webnic\Orders::STATE_ACTIVE) {
            if ($set_errors) {
                $this->setDnssecError('records', 'not_manageable', 'dnssec_not_manageable');
            }
            return false;
        }

        if (empty($snapshot['ok'])) {
            if ($set_errors) {
                $this->setDnssecError('records', 'unavailable', 'dnssec_unavailable');
            }
            return false;
        }

        if (!$this->domainInfoSnapshotIsManageable($snapshot)
            || self::isRegistryTrue(($snapshot['data'] ?? [])['suspended'] ?? false)
        ) {
            if ($set_errors) {
                $this->setDnssecError('records', 'not_manageable', 'dnssec_not_manageable');
            }
            return false;
        }

        return true;
    }

    /**
     * Builds a DNSSEC operation context from the already-read service-tab snapshot.
     *
     * @param stdClass $service Service being managed
     * @param array $snapshot Cached domain info snapshot
     * @param bool $set_errors Whether incomplete local data should populate Input errors
     * @return array|null DNSSEC context, or null when local data is incomplete
     */
    private function dnssecContextFromServiceSnapshot($service, array $snapshot, bool $set_errors = false)
    {
        $this->loadReconcilerDependencies();

        $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
        $module_row_id = (int) ($service->module_row_id ?? 0);
        if ($domain === '') {
            if ($set_errors) {
                $this->setDnssecError('domain', 'missing', 'dnssec_domain_missing');
            }
            return null;
        }
        if ($module_row_id <= 0) {
            if ($set_errors) {
                $this->setDnssecError('module_row_id', 'row_unavailable', 'dnssec_row_unavailable');
            }
            return null;
        }

        $row = $this->getModuleRow($module_row_id);
        if (empty($row)) {
            if ($set_errors) {
                $this->setDnssecError('module_row_id', 'row_unavailable', 'dnssec_row_unavailable');
            }
            return null;
        }

        return [
            'domain' => $domain,
            'module_row_id' => $module_row_id,
            'row' => $row,
            'service' => $service,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Reads TLD support and current DS records for the DNSSEC tab.
     *
     * @param array $context DNSSEC context
     * @param bool $set_errors Whether failed reads should populate Input errors
     * @return array records/supported/support_state/manageable/empty/alert_key
     */
    private function dnssecStateFromContext(array $context, bool $set_errors = false): array
    {
        $state = [
            'records' => [],
            'supported' => false,
            'support_state' => 'unknown',
            'manageable' => false,
            'empty' => false,
            'alert_key' => 'Webnic.tab_dnssec.unavailable',
        ];

        try {
            $dnssec_api = $this->buildDnssecApi($context['row']);

            $support_response = $dnssec_api->getDnssecSupport($context['domain']);
            $supported = $this->dnssecSupportFromResponse($support_response);
            if ($supported === null) {
                // Distinct from a false "unsupported": a read failure is unavailable/load-error.
                $error_class = $support_response->errorClass();
                if ($set_errors) {
                    $this->setDnssecError(
                        'records',
                        $error_class === 'retryable' ? 'unavailable' : 'read_failed',
                        $error_class === 'retryable' ? 'dnssec_unavailable' : 'dnssec_read_failed'
                    );
                }
                $state['alert_key'] = $error_class === 'retryable'
                    ? 'Webnic.!error.dnssec_unavailable'
                    : 'Webnic.tab_dnssec.load_error';
                // Read failures route through the scrubbed exception path, NOT the write-attempt
                // audit logger (AC6 scopes webnic/service_info write audits to add|delete).
                $this->logServiceInfoException(
                    'dnssec_support',
                    $context['service'] ?? null,
                    new \RuntimeException('DNSSEC support read failed (' . (string) $error_class . ')')
                );

                return $state;
            }

            if ($supported === false) {
                $state['support_state'] = 'unsupported';
                $state['alert_key'] = 'Webnic.tab_dnssec.unsupported';

                return $state;
            }

            $state['support_state'] = 'supported';
            $state['supported'] = true;

            $records_response = $dnssec_api->getDnssec($context['domain']);
            if (!$records_response->success()) {
                $error_class = $records_response->errorClass();
                if ($set_errors) {
                    $this->setDnssecError(
                        'records',
                        $error_class === 'retryable' ? 'unavailable' : 'read_failed',
                        $error_class === 'retryable' ? 'dnssec_unavailable' : 'dnssec_read_failed'
                    );
                }
                $state['alert_key'] = $error_class === 'retryable'
                    ? 'Webnic.!error.dnssec_unavailable'
                    : 'Webnic.tab_dnssec.load_error';
                $this->logServiceInfoException(
                    'dnssec_read',
                    $context['service'] ?? null,
                    new \RuntimeException('DNSSEC record read failed (' . (string) $error_class . ')')
                );

                return $state;
            }

            $normalized = $this->dnssecRecordsFromResponse($records_response);
            if ($normalized === null) {
                if ($set_errors) {
                    $this->setDnssecError('records', 'unavailable', 'dnssec_unavailable');
                }
                $state['alert_key'] = 'Webnic.tab_dnssec.load_error';
                $this->logServiceInfoException(
                    'dnssec_normalize',
                    $context['service'] ?? null,
                    new \RuntimeException('DNSSEC record read returned a malformed success envelope')
                );

                return $state;
            }

            $state['records'] = $normalized;
            $state['manageable'] = true;
            $state['empty'] = $normalized === [];
            $state['alert_key'] = $state['empty']
                ? 'Webnic.tab_dnssec.empty'
                : 'Webnic.tab_dnssec.unavailable';
        } catch (\Throwable $e) {
            $this->logServiceInfoException('dnssec_state', $context['service'] ?? null, $e);
            if ($set_errors) {
                $this->setDnssecError('records', 'unavailable', 'dnssec_unavailable');
            }
            $state['support_state'] = 'unknown';
            $state['supported'] = false;
            $state['manageable'] = false;
            $state['alert_key'] = 'Webnic.tab_dnssec.load_error';
        }

        return $state;
    }

    /**
     * Extracts the boolean DNSSEC-support flag from a Check DNSSEC Supported response.
     *
     * @param WebnicResponse $response Provider response
     * @return bool|null true/false support, or null when the read failed/was malformed
     */
    private function dnssecSupportFromResponse(\WebnicResponse $response)
    {
        if (!$response->success()) {
            return null;
        }

        $data = $response->data();
        if (!is_array($data) || !array_key_exists('dnssecSupported', $data)) {
            return null;
        }

        // Only a strict boolean or a recognized bool-ish scalar is decisive. A malformed
        // value on a 200 must NOT collapse to a false "unsupported" — return null so the
        // caller renders the "temporarily unavailable" load state instead (AC2/UX-DR17).
        $value = $data['dnssecSupported'];
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }

        return null;
    }

    /**
     * Normalizes WebNIC DS records into view-safe string-tuple rows.
     *
     * The captured read returns every dsData field as a STRING; rows are kept as
     * trimmed strings (digest upper-cased) and carry a signed row token.
     *
     * @param WebnicResponse $response Provider response
     * @return array|null Editable DS rows, or null when malformed
     */
    private function dnssecRecordsFromResponse(\WebnicResponse $response)
    {
        if (!$response->success()) {
            return null;
        }

        $data = $response->data();
        if (is_array($data) && isset($data['dsDatas']) && is_array($data['dsDatas'])) {
            $list = $data['dsDatas'];
        } elseif (is_array($data) && $data === []) {
            $list = [];
        } else {
            return null;
        }
        if ($list !== [] && array_keys($list) !== range(0, count($list) - 1)) {
            return null;
        }

        $rows = [];
        foreach ($list as $record) {
            if (!is_array($record)) {
                return null;
            }
            foreach (['keyTag', 'algorithm', 'digestType', 'digest'] as $field) {
                if (!isset($record[$field]) || !is_scalar($record[$field])) {
                    return null;
                }
            }

            $row = [
                'keyTag' => trim((string) $record['keyTag']),
                'algorithm' => trim((string) $record['algorithm']),
                'digestType' => trim((string) $record['digestType']),
                'digest' => strtoupper(trim((string) $record['digest'])),
                'editable' => true,
            ];
            if ($row['keyTag'] === '' || $row['algorithm'] === ''
                || $row['digestType'] === '' || $row['digest'] === ''
            ) {
                return null;
            }
            // Validate the provider row shape before it becomes editable state: keyTag bounded,
            // algorithm/digestType numeric (membership NOT enforced so an enum outside the add
            // allowlist is still preserved on re-PUT — INV-8), digest even-length hex. This stops
            // a malformed value (e.g. "abc") from being silently cast to 0 in
            // dnssecProviderDsDatasFromRows() and corrupting the preserved DS set; oversized values
            // are also rejected before PHP can overflow-cast them. The whole read is treated as
            // malformed/unavailable.
            if (!$this->validateDnssecKeyTag($row['keyTag'])
                || !$this->validateDnssecProviderEnum($row['algorithm'])
                || !$this->validateDnssecProviderEnum($row['digestType'])
                || !$this->validateDnssecDigest($row['digest'])
            ) {
                return null;
            }
            $row['record_token'] = $this->dnssecRecordToken($row);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Builds the provider dsDatas[] payload from normalized DS rows (full-list replace).
     *
     * Rebuilds from ALL current rows so a record with an enum outside the add allowlist is
     * preserved on add/remove re-PUTs (no silent data loss / INV-8 forward compatibility).
     *
     * @param array $rows Normalized DS rows
     * @return array Provider dsDatas list
     */
    private function dnssecProviderDsDatasFromRows(array $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            $list[] = [
                'keyTag' => (int) $row['keyTag'],
                'algorithm' => (int) $row['algorithm'],
                'digestType' => (int) $row['digestType'],
                'digest' => strtoupper(trim((string) $row['digest'])),
            ];
        }

        return $list;
    }

    /**
     * Applies one DNSSEC add/remove submission via full-list replace semantics.
     *
     * Add = re-PUT the current rows plus the validated new record. Remove = re-PUT the
     * current rows minus the token-matched record, or DELETE when it was the last one
     * (the captured contract rejects an empty-list POST). Emits exactly one scrubbed
     * webnic/service_info audit per provider write attempt.
     *
     * @param array $context DNSSEC context
     * @param array $post Submitted values
     * @param array $current_rows Current editable DS rows from the pre-write read
     * @return bool True when the provider accepted the change
     */
    private function applyDnssecPost(array $context, array $post, array $current_rows): bool
    {
        $submission = $this->normalizeDnssecSubmission($post, $current_rows);
        if ($submission === null) {
            return false;
        }

        $dnssec_api = $this->buildDnssecApi($context['row']);

        if ($submission['action'] === 'delete') {
            $remaining = [];
            foreach ($current_rows as $row) {
                if (!hash_equals($this->dnssecRecordToken($row), $submission['record_token'])) {
                    $remaining[] = $row;
                }
            }
            $command = 'service_info.dnssec.delete';
            $response = $remaining === []
                ? $dnssec_api->deleteDnssec($context['domain'])
                : $dnssec_api->updateDnssec($context['domain'], $this->dnssecProviderDsDatasFromRows($remaining));
        } else {
            $list = $this->dnssecProviderDsDatasFromRows($current_rows);
            $new = $submission['record'];
            $duplicate = false;
            foreach ($list as $existing) {
                if ($existing == $new) {
                    $duplicate = true;
                    break;
                }
            }
            if (!$duplicate) {
                $list[] = $new;
            }
            $command = 'service_info.dnssec.add';
            $response = $dnssec_api->updateDnssec($context['domain'], $list);
        }

        if (!$response->success()) {
            // DOM5000 is the captured invalid-record rejection and rides an HTTP 500, so
            // WebnicResponse::errorClass() defaults it to retryable (5xx before the subcode map).
            // It is a terminal, fix-the-record failure, not "temporarily unavailable" (AC5).
            $error_class = $this->dnssecResponseIsInvalidRecord($response)
                ? 'terminal'
                : $response->errorClass();
            $key = $error_class === 'retryable'
                ? 'dnssec_update_unavailable'
                : 'dnssec_update_failed';
            $this->setDnssecError('records', 'update_failed', $key);
            $this->logDnssecProviderFailure($context, [
                'outcome' => 'failed',
                'command' => $command,
                'error_class' => $error_class,
            ]);

            return false;
        }

        $this->logDnssecProviderAttempt($context, [
            'outcome' => 'ok',
            'command' => $command,
        ]);

        return true;
    }

    /**
     * Returns whether a failed write response is the captured invalid-record rejection.
     *
     * WebNIC rejects an out-of-range/malformed DS record with subCode DOM5000 on an HTTP 500,
     * which the generic classifier would treat as retryable. Server-side validation normally
     * catches these first; if one slips through it must surface as a terminal, field-specific
     * failure, never "temporarily unavailable".
     *
     * @param WebnicResponse $response Failed provider response
     * @return bool
     */
    private function dnssecResponseIsInvalidRecord(\WebnicResponse $response): bool
    {
        $body = $response->body();
        if (!is_array($body) || !is_array($body['error'] ?? null)) {
            return false;
        }

        return trim((string) ($body['error']['subCode'] ?? '')) === 'DOM5000';
    }

    /**
     * Validates and normalizes one DNSSEC DS submission.
     *
     * @param array $vars Submitted values
     * @param array $current_rows Current editable DS rows from the pre-write read
     * @return array|null Normalized submission, or null when validation failed
     */
    private function normalizeDnssecSubmission(array $vars, array $current_rows)
    {
        if ($this->dnssecHasNonScalarFields($vars)) {
            $this->setDnssecError('records', 'invalid', 'dnssec_invalid');
            return null;
        }

        $flat = $this->dnssecFieldVars($vars);
        $action = strtolower(trim((string) $flat['action']));
        if (!in_array($action, ['add', 'delete'], true)) {
            $this->setDnssecError('action', 'invalid', 'dnssec_action_invalid');
            return null;
        }

        if ($action === 'delete') {
            $token = trim((string) $flat['record_token']);
            if ($token === '') {
                $this->setDnssecError('records', 'row_token', 'dnssec_row_token');
                return null;
            }
            if ($this->dnssecEditableRecordByToken($current_rows, $token) === null) {
                $this->setDnssecError('records', 'target_invalid', 'dnssec_target_invalid');
                return null;
            }

            return ['action' => 'delete', 'record_token' => $token];
        }

        // Validate the add fields through Blesta Input (AC3) — deterministic whole-number /
        // enum / hex callbacks, no floats, no ad-hoc parsing (mirrors WN-5.5 DNS records).
        $rules = [
            'keytag' => [
                'format' => [
                    'rule' => [[$this, 'validateDnssecKeyTag']],
                    'message' => Language::_('Webnic.!error.dnssec_keytag_invalid', true),
                ],
            ],
            'algorithm' => [
                'format' => [
                    'rule' => [[$this, 'validateDnssecAlgorithm']],
                    'message' => Language::_('Webnic.!error.dnssec_algorithm_invalid', true),
                ],
            ],
            'digesttype' => [
                'format' => [
                    'rule' => [[$this, 'validateDnssecDigestType']],
                    'message' => Language::_('Webnic.!error.dnssec_digesttype_invalid', true),
                ],
            ],
            'digest' => [
                'format' => [
                    'rule' => [[$this, 'validateDnssecDigest']],
                    'message' => Language::_('Webnic.!error.dnssec_digest_invalid', true),
                ],
            ],
        ];
        $this->Input->setRules($rules);
        if (!$this->Input->validates($flat)) {
            return null;
        }

        return [
            'action' => 'add',
            'record' => [
                'keyTag' => (int) trim((string) $flat['keytag']),
                'algorithm' => (int) trim((string) $flat['algorithm']),
                'digestType' => (int) trim((string) $flat['digesttype']),
                'digest' => strtoupper(trim((string) $flat['digest'])),
            ],
        ];
    }

    /**
     * Whitelists tab/form input to known DNSSEC fields.
     *
     * @param array $vars Submitted values
     * @return array Whitelisted flat values
     */
    private function dnssecFieldVars(array $vars): array
    {
        $fields = ['action', 'keytag', 'algorithm', 'digesttype', 'digest', 'record_token'];
        $flat = [];
        foreach ($fields as $field) {
            $value = $vars[$field] ?? '';
            // Coerce non-scalars to '' (mirrors WN-5.6 forwardingFieldVars) so a crafted array
            // can never trigger an "Array to string conversion" warning when the form re-renders;
            // dnssecHasNonScalarFields still inspects the raw POST to reject the submission.
            $flat[$field] = is_scalar($value) ? (string) $value : '';
        }

        return $flat;
    }

    /**
     * Returns whether any known DNSSEC field arrived non-scalar (array/object injection).
     *
     * @param array $vars Submitted values
     * @return bool
     */
    private function dnssecHasNonScalarFields(array $vars): bool
    {
        foreach (['action', 'keytag', 'algorithm', 'digesttype', 'digest', 'record_token'] as $field) {
            if (array_key_exists($field, $vars) && $vars[$field] !== null && !is_scalar($vars[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns an empty DNSSEC form-state payload for the template.
     *
     * @return array
     */
    private function emptyDnssecFormState(): array
    {
        return ['scope' => '', 'key' => '', 'values' => []];
    }

    /**
     * Captures which DNSSEC form submitted so the view can scope errors and values.
     *
     * @param array $vars Submitted values
     * @return array Form-state payload
     */
    private function dnssecFormStateFromPost(array $vars): array
    {
        $flat = $this->dnssecFieldVars($vars);
        $action = strtolower(trim((string) $flat['action']));
        if ($action === 'add') {
            return ['scope' => 'add', 'key' => '', 'values' => $flat];
        }
        if ($action === 'delete') {
            return ['scope' => 'record', 'key' => trim((string) $flat['record_token']), 'values' => $flat];
        }

        return ['scope' => 'general', 'key' => '', 'values' => $flat];
    }

    /**
     * Input rule: a DNSSEC key tag is a whole number in the 16-bit unsigned DNS range.
     *
     * @param mixed $value Submitted key tag
     * @return bool
     */
    public function validateDnssecKeyTag($value): bool
    {
        return $this->dnssecWholeNumberInRange($value, 0, self::DNSSEC_KEYTAG_MAX);
    }

    /**
     * Input rule: a DNSSEC algorithm is a whole number in the server-side allowlist.
     *
     * @param mixed $value Submitted algorithm
     * @return bool
     */
    public function validateDnssecAlgorithm($value): bool
    {
        return $this->dnssecWholeNumberInAllowlist($value, self::DNSSEC_ALGORITHM_ALLOWLIST);
    }

    /**
     * Input rule: a DNSSEC digest type is a whole number in the server-side allowlist.
     *
     * @param mixed $value Submitted digest type
     * @return bool
     */
    public function validateDnssecDigestType($value): bool
    {
        return $this->dnssecWholeNumberInAllowlist($value, self::DNSSEC_DIGESTTYPE_ALLOWLIST);
    }

    /**
     * Input rule: a DS digest is an even-length hexadecimal string.
     *
     * T0 (WN-5.7) only captured a non-hex rejection (DOM5000 "XML Schema Validation Error");
     * it did NOT confirm per-digest-type fixed lengths, so this does NOT enforce RFC per-type
     * lengths (AC3 — apply only T0-confirmed rules). An even-length hex digest of an unexpected
     * length is forwarded to the registry, which is authoritative.
     *
     * @param mixed $value Submitted digest
     * @return bool
     */
    public function validateDnssecDigest($value): bool
    {
        if (!is_scalar($value)) {
            return false;
        }
        $text = strtoupper(trim((string) $value));

        return $text !== '' && preg_match('/^[0-9A-F]+$/', $text) === 1 && strlen($text) % 2 === 0;
    }

    /**
     * Returns whether a value is a whole number within an inclusive range.
     *
     * Deterministic integer handling (no floats/loose coercion); the canonical decimal must
     * round-trip so overflow forms are rejected.
     *
     * @param mixed $value Submitted value
     * @param int $min Minimum (inclusive)
     * @param int $max Maximum (inclusive)
     * @return bool
     */
    private function dnssecWholeNumberInRange($value, int $min, int $max): bool
    {
        if (!is_scalar($value)) {
            return false;
        }
        $text = is_int($value) ? (string) $value : trim((string) $value);
        if ($text === '' || preg_match('/^[0-9]+$/', $text) !== 1) {
            return false;
        }
        $integer = (int) $text;
        if ((string) $integer !== ltrim($text, '0') && preg_match('/^0+$/', $text) !== 1) {
            return false;
        }

        return $integer >= $min && $integer <= $max;
    }

    /**
     * Returns whether a value is a whole number present in an allowlist.
     *
     * @param mixed $value Submitted value
     * @param array $allowlist Allowed integer values
     * @return bool
     */
    private function dnssecWholeNumberInAllowlist($value, array $allowlist): bool
    {
        if (!is_scalar($value)) {
            return false;
        }
        $text = is_int($value) ? (string) $value : trim((string) $value);

        return $text !== '' && preg_match('/^[0-9]+$/', $text) === 1 && in_array((int) $text, $allowlist, true);
    }

    /**
     * Returns whether a provider-supplied enum can be safely re-emitted as a JSON integer.
     *
     * Provider rows may contain future enum values not present in the add allowlist; preserve
     * those values only when PHP can round-trip them without overflow.
     *
     * @param mixed $value Provider-supplied enum value
     * @return bool
     */
    private function validateDnssecProviderEnum($value): bool
    {
        return $this->dnssecWholeNumberInRange($value, 0, PHP_INT_MAX);
    }

    /**
     * Returns the algorithm allowlist as form options (value => value).
     *
     * @return array<int,int>
     */
    private function dnssecAlgorithmAllowlist(): array
    {
        return self::DNSSEC_ALGORITHM_ALLOWLIST;
    }

    /**
     * Returns the digest-type allowlist as form options (value => value).
     *
     * @return array<int,int>
     */
    private function dnssecDigestTypeAllowlist(): array
    {
        return self::DNSSEC_DIGESTTYPE_ALLOWLIST;
    }

    /**
     * Finds the submitted delete target in the freshly read editable row set.
     *
     * @param array $rows Current editable DS rows
     * @param mixed $token Submitted row token
     * @return array|null Current editable DS row
     */
    private function dnssecEditableRecordByToken(array $rows, $token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['editable'])) {
                continue;
            }
            if (hash_equals($this->dnssecRecordToken($row), $token)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Builds a signed token over a DS record's canonical (string) identity tuple.
     *
     * @param array $row Normalized DS row
     * @return string HMAC row token
     */
    private function dnssecRecordToken(array $row): string
    {
        $payload = [
            'keyTag' => trim((string) ($row['keyTag'] ?? '')),
            'algorithm' => trim((string) ($row['algorithm'] ?? '')),
            'digestType' => trim((string) ($row['digestType'] ?? '')),
            'digest' => strtoupper(trim((string) ($row['digest'] ?? ''))),
        ];
        $secret = (string) Configure::get('Blesta.system_key');
        if ($secret === '') {
            $secret = __FILE__;
        }

        return hash_hmac('sha256', json_encode($payload), $secret);
    }

    /**
     * Sets a localized DNSSEC field error, merging into the current Input error bag.
     *
     * @param string $field Field name
     * @param string $code Error code key
     * @param string $language_key Language key suffix under Webnic.!error.*
     */
    private function setDnssecError(string $field, string $code, string $language_key): void
    {
        if (isset($this->Input)) {
            $errors = $this->errors();
            if (!is_array($errors)) {
                $errors = [];
            }
            if (!isset($errors[$field]) || !is_array($errors[$field])) {
                $errors[$field] = [];
            }
            $errors[$field][$code] = Language::_('Webnic.!error.' . $language_key, true);
            $this->Input->setErrors($errors);
        }
    }

    /**
     * Flattens current Input errors for DNSSEC tab rendering.
     *
     * @return array field => string[]
     */
    private function dnssecErrorsForView(): array
    {
        $errors = $this->errors();
        if (!is_array($errors)) {
            return [];
        }

        $known = ['action', 'keytag', 'algorithm', 'digesttype', 'digest', 'records'];
        $flat = [];
        foreach ($errors as $field => $messages) {
            $field = (string) $field;
            $bucket = in_array($field, $known, true) ? $field : '_general';
            foreach ($this->flattenWhoisContactMessages($messages) as $message) {
                $flat[$bucket][] = $message;
            }
        }

        return $flat;
    }

    /**
     * Logs provider-declared DNSSEC failures through the scrubbed service-info sink.
     *
     * @param array $context DNSSEC context
     * @param array $result Failure result
     */
    private function logDnssecProviderFailure(array $context, array $result): void
    {
        $this->logDnssecProviderAttempt($context, $result);
    }

    /**
     * Logs DNSSEC write/read attempts through the scrubbed service-info sink.
     *
     * Mirrors WN-5.6 logForwardingProviderAttempt: one scrubbed webnic/service_info record
     * per provider write attempt; outcome=ok only after provider acceptance.
     *
     * @param array $context DNSSEC context
     * @param array $result Attempt result
     */
    private function logDnssecProviderAttempt(array $context, array $result): void
    {
        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
            $outcome = (string) ($result['outcome'] ?? 'failed');
            $record = [
                'level' => $outcome === 'ok' ? 'info' : 'error',
                'service_id' => is_object($context['service'] ?? null) ? ($context['service']->id ?? null) : null,
                'module_row_id' => (int) ($context['module_row_id'] ?? 0),
                'domain' => (string) ($context['domain'] ?? ''),
                'command' => (string) ($result['command'] ?? 'service_info.dnssec'),
                'outcome' => $outcome,
                'error_class' => $result['error_class'] ?? null,
            ];
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $this->log('webnic/service_info', serialize($scrubbed), 'output', $outcome === 'ok');
        } catch (\Throwable $ignored) {
            // Logging must never interrupt service management.
        }
    }

    /**
     * Returns whether the current service may view or mutate WHOIS contacts.
     *
     * @param stdClass $service Service being managed
     * @param array $snapshot Cached domain info snapshot
     * @return bool
     */
    private function serviceAllowsWhoisContacts($service, array $snapshot): bool
    {
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');

        if ($this->isLocalCancelledService($service)) {
            $this->setWhoisContactError('contacts', 'contact_not_manageable');
            return false;
        }

        if (strtolower((string) ($service->status ?? '')) !== 'active') {
            $this->setWhoisContactError('contacts', 'contact_not_manageable');
            return false;
        }

        $order = $this->resolveOrderByDomain($service);
        if ($order !== null && (string) ($order->state ?? '') !== \Webnic\Orders::STATE_ACTIVE) {
            $this->setWhoisContactError('contacts', 'contact_not_manageable');
            return false;
        }

        if (!$this->domainInfoSnapshotAllowsWhoisContacts($snapshot)) {
            $this->setWhoisContactError('contacts', 'contact_not_manageable');
            return false;
        }

        return true;
    }

    /**
     * Returns whether the current service may mutate nameservers.
     *
     * @param stdClass $service Service being managed
     * @param array $snapshot Cached domain info snapshot
     * @param bool $set_errors Whether to populate Input errors on rejection
     * @return bool True when local, order, and live registry gates all pass
     */
    private function serviceAllowsNameservers($service, array $snapshot, bool $set_errors = true): bool
    {
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');

        if ($this->isLocalCancelledService($service)) {
            if ($set_errors) {
                $this->setNameserverError('nameservers', 'not_manageable', 'nameserver_not_manageable');
            }
            return false;
        }

        if (strtolower((string) ($service->status ?? '')) !== 'active') {
            if ($set_errors) {
                $this->setNameserverError('nameservers', 'not_manageable', 'nameserver_not_manageable');
            }
            return false;
        }

        $order = $this->resolveOrderByDomain($service);
        if ($order !== null && (string) ($order->state ?? '') !== \Webnic\Orders::STATE_ACTIVE) {
            if ($set_errors) {
                $this->setNameserverError('nameservers', 'not_manageable', 'nameserver_not_manageable');
            }
            return false;
        }

        if (empty($snapshot['ok'])) {
            if ($set_errors) {
                $this->setNameserverError('nameservers', 'unavailable', 'nameserver_unavailable');
            }
            return false;
        }

        if (!$this->domainInfoSnapshotIsManageable($snapshot)
            || self::isRegistryTrue(($snapshot['data'] ?? [])['suspended'] ?? false)
        ) {
            if ($set_errors) {
                $this->setNameserverError('nameservers', 'not_manageable', 'nameserver_not_manageable');
            }
            return false;
        }

        return true;
    }

    /**
     * Returns whether the current service may view or mutate DNS records.
     *
     * The optional zone response is the DNS-path-only WebNIC-DNS gate. T0 proved
     * domain/v2/info has no durable zone-managed flag and Get Zone Records returns
     * DNS4200/DNS4201 when the usable WebNIC DNS zone is unavailable.
     *
     * @param stdClass $service Service being managed
     * @param array $snapshot Cached domain info snapshot
     * @param bool $set_errors Whether to populate Input errors on rejection
     * @param WebnicResponse|null $zone_response Optional Get Zone Records response
     * @return bool True when local, order, live, and DNS-zone gates all pass
     */
    private function serviceAllowsDnsRecords($service, array $snapshot, bool $set_errors = true, $zone_response = null): bool
    {
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');

        if ($this->isLocalCancelledService($service)) {
            if ($set_errors) {
                $this->setDnsRecordError('records', 'not_manageable', 'dnsrecord_not_manageable');
            }
            return false;
        }

        if (strtolower((string) ($service->status ?? '')) !== 'active') {
            if ($set_errors) {
                $this->setDnsRecordError('records', 'not_manageable', 'dnsrecord_not_manageable');
            }
            return false;
        }

        $order = $this->resolveOrderByDomain($service);
        if ($order !== null && (string) ($order->state ?? '') !== \Webnic\Orders::STATE_ACTIVE) {
            if ($set_errors) {
                $this->setDnsRecordError('records', 'not_manageable', 'dnsrecord_not_manageable');
            }
            return false;
        }

        if (empty($snapshot['ok'])) {
            if ($set_errors) {
                $this->setDnsRecordError('records', 'unavailable', 'dnsrecord_unavailable');
            }
            return false;
        }

        if (!$this->domainInfoSnapshotIsManageable($snapshot)
            || self::isRegistryTrue(($snapshot['data'] ?? [])['suspended'] ?? false)
        ) {
            if ($set_errors) {
                $this->setDnsRecordError('records', 'not_manageable', 'dnsrecord_not_manageable');
            }
            return false;
        }

        if ($zone_response instanceof \WebnicResponse && $this->dnsZoneResponseIsNotWebnicDns($zone_response)) {
            if ($set_errors) {
                $this->setDnsRecordError('records', 'not_webnic_dns', 'dnsrecord_not_webnic_dns');
            }
            return false;
        }

        return true;
    }

    /**
     * Returns whether the current service may view or mutate URL/email forwarding.
     *
     * The optional forwarding list response is the forwarding-path-only WebNIC-DNS gate.
     * It reuses the DNS42xx zone classifier captured for WN-5.5/WN-5.6 without widening
     * shared service-summary manageability.
     *
     * @param stdClass $service Service being managed
     * @param array $snapshot Cached domain info snapshot
     * @param bool $set_errors Whether to populate Input errors on rejection
     * @param WebnicResponse|null $list_response Optional forwarding list response
     * @return bool True when local, order, live, and DNS-zone gates all pass
     */
    private function serviceAllowsForwarding($service, array $snapshot, bool $set_errors = true, $list_response = null): bool
    {
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');

        if ($this->isLocalCancelledService($service)) {
            if ($set_errors) {
                $this->setForwardingError('forwarding', 'not_manageable', 'forwarding_not_manageable');
            }
            return false;
        }

        if (strtolower((string) ($service->status ?? '')) !== 'active') {
            if ($set_errors) {
                $this->setForwardingError('forwarding', 'not_manageable', 'forwarding_not_manageable');
            }
            return false;
        }

        $order = $this->resolveOrderByDomain($service);
        if ($order !== null && (string) ($order->state ?? '') !== \Webnic\Orders::STATE_ACTIVE) {
            if ($set_errors) {
                $this->setForwardingError('forwarding', 'not_manageable', 'forwarding_not_manageable');
            }
            return false;
        }

        if (empty($snapshot['ok'])) {
            if ($set_errors) {
                $this->setForwardingError('forwarding', 'unavailable', 'forwarding_unavailable');
            }
            return false;
        }

        if (!$this->domainInfoSnapshotIsManageable($snapshot)
            || self::isRegistryTrue(($snapshot['data'] ?? [])['suspended'] ?? false)
        ) {
            if ($set_errors) {
                $this->setForwardingError('forwarding', 'not_manageable', 'forwarding_not_manageable');
            }
            return false;
        }

        if ($list_response instanceof \WebnicResponse && $this->dnsZoneResponseIsNotWebnicDns($list_response)) {
            if ($set_errors) {
                $this->setForwardingError('forwarding', 'not_webnic_dns', 'forwarding_not_webnic_dns');
            }
            return false;
        }

        return true;
    }

    /**
     * Composes one service-tab surface from the array-driven registry (AC3/AC4/C15).
     *
     * Loads the registry, fails closed on a locally cancelled service ([]), resolves the
     * by-domain order once, builds the gating context, and asks the registry to compose the
     * ordered map for the requested side. Guarded so a tab-resolution failure can never break
     * the service page (logged, then []).
     *
     * @param stdClass $service The service being viewed
     * @param bool $is_admin True for the admin surface, false for the client surface
     * @return array The composed tab map, or [] on a cancelled service / any failure
     */
    private function composeServiceTabs($service, bool $is_admin): array
    {
        try {
            Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_status.php');
            Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_tab_registry.php');

            if ($this->isLocalCancelledService($service)) {
                return [];
            }

            $order = $this->resolveOrderByDomain($service);

            return \Webnic\TabRegistry::resolve(
                $is_admin ? 'admin' : 'client',
                $this->buildTabContext($service, $order, $is_admin)
            );
        } catch (\Throwable $e) {
            // A tab-resolution failure must never break the service page (but log it).
            $this->logServiceInfoException($is_admin ? 'admin_tabs' : 'client_tabs', $service, $e);
        }

        return [];
    }

    /**
     * Builds the registry gating context for a service + its by-domain order (AC3/AC4).
     *
     * Every eligibility flag is computed here (the registry stays pure). The admin-only
     * lifecycle reads are short-circuited behind `$is_admin` so the CLIENT surface never
     * triggers the restore/delete live registry reads — preserving the pre-5.1 client read
     * count exactly. The eligibility helpers themselves short-circuit (a non-active local state
     * returns false before any registry read), so the admin read count is unchanged too:
     *   - recovery: a `failed` by-domain order (admin-only via the registry `sides` filter);
     *   - resend:   resendEligible() — STATE_REGISTRAR_PENDING (both surfaces);
     *   - restore:  restoreTabEligible() — local-active + live grace/redemption (admin-only);
     *   - delete:   deleteTabEligible() — local-active + live non-grace status (admin-only);
     *   - parity:   active/manageable locally (order null-or-active, not suspended). Each built
     *               parity tab is still method-gated, so unbuilt slots render no dead links.
     *
     * @param stdClass $service The service being viewed
     * @param stdClass|null $order The resolved by-domain order, or null
     * @param bool $is_admin True for the admin surface
     * @return array The gating context consumed by \Webnic\TabRegistry::resolve()
     */
    private function buildTabContext($service, $order, bool $is_admin): array
    {
        $order_state = is_object($order) ? (string) ($order->state ?? '') : null;
        $local_status = strtolower((string) ($service->status ?? ''));
        $local_active = $local_status === 'active';
        $order_active = $order === null || $order_state === \Webnic\Orders::STATE_ACTIVE;
        $side = $is_admin ? 'admin' : 'client';
        $has_public_parity_method = $this->hasPublicParityMethodForSide($side);
        $needs_live_read = $local_active && $order_active && ($is_admin || $has_public_parity_method);
        $snapshot = $needs_live_read
            ? $this->domainInfoSnapshot($service, $is_admin ? 'admin_tabs' : 'client_tabs', true)
            : null;

        // Restore and Delete are mutually exclusive by live registry status (grace/redemption -> restore,
        // active -> delete). Both consume the same cached info() snapshot, so the tab strip and summary are
        // consistent and ordinary active pages do not perform duplicate live reads.
        $restore_eligible = $is_admin && $local_active
            && $this->restoreTabEligible($service, $order, true, $snapshot);
        $live_manageable = $snapshot !== null && $this->domainInfoSnapshotIsManageable($snapshot);
        $parity_manageable = $live_manageable
            && !self::isRegistryTrue(($snapshot['data'] ?? [])['suspended'] ?? false);
        $whois_manageable = $snapshot !== null && $this->domainInfoSnapshotAllowsWhoisContacts($snapshot);

        return [
            'is_admin' => $is_admin,
            'parity_active' => $local_active && $order_active && $parity_manageable && !$restore_eligible,
            'whois_active' => $local_active && $order_active && $whois_manageable && !$restore_eligible,
            'recovery_eligible' => $order_state === \Webnic\Orders::STATE_FAILED,
            'resend_eligible' => $this->resendEligible($order),
            'restore_eligible' => $restore_eligible,
            'delete_eligible' => !$restore_eligible
                && $is_admin
                && $local_active
                && $this->deleteTabEligible($service, $order, true, $snapshot),
            'has_method' => function ($key) {
                return $this->hasPublicServiceTabMethod((string) $key);
            },
        ];
    }

    /**
     * Returns whether any parity tab method is publicly dispatchable for a side.
     *
     * @param string $side admin|client
     * @return bool
     */
    private function hasPublicParityMethodForSide(string $side): bool
    {
        foreach (\Webnic\TabRegistry::descriptors() as $descriptor) {
            if (empty($descriptor['parity'])) {
                continue;
            }
            $sides = (string) ($descriptor['sides'] ?? '');
            if ($sides !== 'both' && $sides !== $side) {
                continue;
            }
            $key = $side === 'admin' ? ($descriptor['admin_key'] ?? '') : ($descriptor['client_key'] ?? '');
            if ($this->hasPublicServiceTabMethod((string) $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether a Webnic-declared service-tab method is public and dispatchable by Blesta.
     *
     * @param string $key Method name
     * @return bool
     */
    private function hasPublicServiceTabMethod(string $key): bool
    {
        if ($key === '' || !method_exists($this, $key)) {
            return false;
        }

        try {
            $method = new \ReflectionMethod($this, $key);

            return $method->isPublic()
                && $method->getDeclaringClass()->getName() === self::class;
        } catch (\ReflectionException $e) {
            return false;
        }
    }

    /**
     * Whether an order may be offered/perform the resend action (AC2/AC7).
     *
     * Scoped strictly to STATE_REGISTRAR_PENDING — the registry has accepted the request and is
     * awaiting verification. This covers BOTH transfer-pending (operation=transfer — the primary case)
     * AND async register-pending (FR22/AC7: the resend op is operation-agnostic, OTE-proven — it fired
     * on a register awaiting verification), while EXCLUDING the transient pre-submit saga states
     * (intent/contacts_done/registrant_done/hosts_done/submitted) and the unknown-state default. In
     * those states the registry has no record yet, so a resend returns DOM2400 — classified terminal —
     * and would surface a hard "contact support" error during an otherwise-healthy in-flight order
     * (round-1 D1). active/failed/cancelled are likewise excluded, so the resend tab and the failed
     * Recovery tab stay mutually exclusive by construction. Re-checked on the POST action path
     * (tabResendVerification) so eligibility holds for the mutating call, not only by navigation.
     *
     * @param stdClass|null $order The resolved by-domain order, or null
     * @return bool Whether the resend action is allowed for this order's current state
     */
    private function resendEligible($order): bool
    {
        return $order !== null
            && (string) $order->state === \Webnic\Orders::STATE_REGISTRAR_PENDING;
    }

    /**
     * Whether the admin Delete Domain tab/action may be offered for this local state (FR26/AC1).
     *
     * The irreversible registry write is admin-only, hidden from cancelled/suspended service rows,
     * hidden while an order is pending or failed (resend/recovery own those states), and offered for
     * active/no-order services only. The live grace/redemption status is still re-checked by
     * performDelete immediately before the write (AC3).
     *
     * @param stdClass $service The service being viewed
     * @param stdClass|null $order The resolved by-domain order
     * @param bool $check_registry Whether to perform the non-mutating live status read
     * @return bool Whether to offer/perform the admin delete action
     */
    private function deleteTabEligible($service, $order, bool $check_registry = false, $snapshot = null): bool
    {
        if ($this->isLocalCancelledService($service)) {
            return false;
        }

        $status = strtolower((string) ($service->status ?? ''));
        if ($status !== 'active') {
            return false;
        }

        $local_ok = $order === null
            || (string) ($order->state ?? '') === \Webnic\Orders::STATE_ACTIVE;

        return $local_ok && (!$check_registry || $this->deleteRegistryStatusAllowsTab($service, $snapshot));
    }

    /**
     * Returns true only when a non-mutating registry info read proves the delete tab is safe to render.
     *
     * This path deliberately emits no webnic/cancel INV-9 attempt logs: normal tab display is not an
     * operator delete attempt. POST still re-runs the full logged guard in performDelete().
     *
     * @param stdClass $service The service being rendered
     * @return bool Whether the live status is known-safe for offering the delete tab
     */
    private function deleteRegistryStatusAllowsTab($service, $snapshot = null): bool
    {
        $snapshot = is_array($snapshot)
            ? $snapshot
            : $this->domainInfoSnapshot($service, 'delete_tab_status', true);

        return $this->domainInfoSnapshotIsManageable($snapshot);
    }

    /**
     * Whether the admin Restore tab may be offered for this local and live registry state.
     *
     * @param stdClass $service The service being viewed
     * @param stdClass|null $order The resolved by-domain order
     * @param bool $check_registry Whether to perform the non-mutating live preview read
     * @return bool Whether to offer/perform the admin restore action
     */
    private function restoreTabEligible($service, $order, bool $check_registry = false, $snapshot = null): bool
    {
        if ($this->isLocalCancelledService($service)) {
            return false;
        }

        if (strtolower((string) ($service->status ?? '')) !== 'active') {
            return false;
        }

        $local_ok = $order === null
            || (string) ($order->state ?? '') === \Webnic\Orders::STATE_ACTIVE;
        if (!$local_ok) {
            return false;
        }

        return !$check_registry || $this->restorePromptState($service, $snapshot) !== null;
    }

    /**
     * Returns the restore prompt state for service-info/tab display, or null when unavailable.
     *
     * This is deliberately read-only: it emits no webnic/restore lifecycle record and never submits
     * the registry restore command. The mutating path re-runs performRestore().
     *
     * @param stdClass $service The service being viewed
     * @param array|null $snapshot Optional cached info snapshot
     * @param bool $log_failure Whether read failures should be observable immediately
     * @return array|null Restore prompt data
     */
    private function restorePromptState($service, $snapshot = null, bool $log_failure = false)
    {
        return $this->restorePreview($service, $snapshot, $log_failure);
    }

    /**
     * Builds a non-mutating restore preview from live status, GRACE rule, and restore price.
     *
     * @param stdClass $service The service being viewed
     * @param array|null $snapshot Optional cached info snapshot
     * @param bool $log_failure Whether read failures should be observable immediately
     * @return array|null Prompt data, or null when restore cannot be offered deterministically
     */
    private function restorePreview($service, $snapshot = null, bool $log_failure = false)
    {
        try {
            $this->loadReconcilerDependencies();
            if (is_array($snapshot)) {
                if ($log_failure) {
                    $this->logDomainInfoSnapshotFailure('restore_preview', $service, $snapshot);
                }
            } else {
                $snapshot = $this->domainInfoSnapshot($service, 'restore_preview', $log_failure);
            }
            if (empty($snapshot['ok'])) {
                return null;
            }

            if (!$this->restorableState($service)['allowed']) {
                return null;
            }

            $domain = (string) ($snapshot['domain'] ?? '');
            $module_row_id = (int) ($snapshot['module_row_id'] ?? 0);
            $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
            $status = (string) ($snapshot['status'] ?? '');
            $expires = $data['dtexpire'] ?? null;
            if ($domain === '' || $module_row_id <= 0 || $status === '') {
                return null;
            }

            $restore_statuses = $this->configuredStatusList('Webnic.restore_eligible_statuses', [
                'expired',
                'redemption_grace',
            ]);
            if (!in_array($status, $restore_statuses, true)) {
                return null;
            }

            $ext = $this->restoreExtFromDomain($domain);
            if ($ext === '') {
                return null;
            }

            $row = $this->getModuleRow($module_row_id);
            if (empty($row)) {
                return null;
            }
            $pricingApi = $this->buildPricingApi($row);
            $rule_response = $pricingApi->getExtensionRule($ext, 'grace');
            if (!$rule_response->success()) {
                return null;
            }
            $rule_data = $rule_response->data();
            $rule = is_array($rule_data) && isset($rule_data['rules']) && is_array($rule_data['rules'])
                ? $rule_data['rules']
                : null;

            $eligibility = \WebnicPricing::decideGraceRestoreEligibility($rule, $status, $expires, $this->restoreNow());
            if (!$eligibility['eligible']) {
                return null;
            }

            $price_response = $pricingApi->getExtensionPrice([$ext], ['restore'], 1, 1);
            $price = \WebnicPricing::decideRestorePrice($price_response, $ext, 1);
            if (!$price['available']) {
                return null;
            }

            return [
                'domain' => $domain,
                'registry_status' => strtolower(trim($status)),
                'price' => $price['price'],
                'currency' => $price['currency'],
            ];
        } catch (\Throwable $e) {
            $this->logServiceInfoException('restore_preview', $service, $e);

            return null;
        }
    }

    /**
     * Whether a local Blesta service status is terminal/read-only for lifecycle tabs.
     *
     * @param stdClass|null $service The service being rendered
     * @return bool True for locally cancelled/canceled services
     */
    private function isLocalCancelledService($service): bool
    {
        $status = strtolower((string) (is_object($service) ? ($service->status ?? '') : ''));

        return in_array($status, ['canceled', 'cancelled'], true);
    }

    /**
     * Resend-verification tab: the one allowed action while a transfer/registration is pending (AC1/AC2/AC6).
     *
     * Served on BOTH the client and admin surfaces (one method, both getClientServiceTabs and
     * getAdminServiceTabs point here). On POST action=resend it calls the canonical hook
     * resendTransferEmail($domain, module_row_id) (AC1) and surfaces an inline confirmation via
     * setMessage AFTER the call returns — never an optimistic pre-response "sent" (EXPERIENCE.md
     * L121). A failure's Input error is mirrored by the service-tab dispatch (no extra setMessage).
     * Then it renders tab_resend.pdt (the operation-aware pending banner + a single resend button —
     * single-click, no confirm modal, AC6). Guards the whole body so it can never fatal the service
     * page (Dev Notes §J), logging via logServiceInfoException and rendering a safe inline error.
     *
     * @param stdClass $package The service's package
     * @param stdClass $service The service being managed
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters ($post['action'] === 'resend')
     * @param array $files Any FILES parameters
     * @return string The rendered tab HTML
     */
    public function tabResendVerification($package, $service, array $get = null, array $post = null, array $files = null)
    {
        try {
            Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_status.php');

            // Resolve the order ONCE up front: it both re-gates the mutating action (below) and
            // drives the operation-aware banner (further down). Resend never transitions (NFR5), so
            // the order state is identical before and after the action — reuse is safe.
            $order = $this->resolveOrderByDomain($service);

            // Re-gate the action on the order's CURRENT state (AC2): a stale tab (the order left
            // registrar_pending between render and submit) or a forged POST must not fire a resend
            // outside the eligibility window — enforce it on the action path, not only by navigation.
            if (!empty($post['action']) && (string) $post['action'] === 'resend' && $this->resendEligible($order)) {
                $domain = (string) $this->getServiceDomain($service);
                if ($this->resendTransferEmail($domain, $service->module_row_id ?? null)) {
                    // ok and benign 'already' are both non-error; pick the benign copy when applicable.
                    $is_already = $this->last_resend_outcome === 'already';
                    $this->setMessage(
                        $is_already ? 'notice' : 'success',
                        Language::_($is_already ? 'Webnic.resend.already' : 'Webnic.resend.ok', true)
                    );
                }
                // On failure resendTransferEmail set Input->errors, which the service-tab dispatch
                // mirrors into the page (setServiceTabMessages prioritizes errors) — no extra message.
            }

            $this->view = new View('tab_resend', 'default');
            Loader::loadHelpers($this, ['Form', 'Html']);

            // Operation-aware banner (mirrors renderServiceInfo): a transfer-pending order shows the
            // transfer "we'll email you" copy; a register-pending order shows the register copy.
            $is_transfer = $order !== null
                && ($order->operation ?? \Webnic\Orders::OPERATION_REGISTER) === \Webnic\Orders::OPERATION_TRANSFER;
            $this->view->set('alias', $order ? \WebnicStatus::fromOrderState((string) $order->state) : null);
            $this->view->set('banner_key', $is_transfer
                ? 'Webnic.service_info.transfer_pending_banner'
                : 'Webnic.service_info.pending_banner');
            $this->view->setDefaultView(self::$defaultModuleView);

            return $this->view->fetch();
        } catch (\Throwable $e) {
            $this->logServiceInfoException('tabResend', $service, $e);

            return '<div class="alert alert-danger" role="alert">'
                . '<i class="bi bi-exclamation-triangle me-2"></i>'
                . Language::_('Webnic.tab_resend.load_error', true)
                . '</div>';
        }
    }

    /**
     * Admin Delete Domain tab: explicit, confirmed registry deletion (FR26/AC1/AC3).
     *
     * Re-gates on POST so a stale tab cannot delete while an order moved in-flight/cancelled
     * after render. The actual registry side-action is delegated to performDelete(), which owns
     * row scoping, pending-order blocking, deletion-window refusal, classification, and INV-9.
     *
     * @param stdClass $package The service's package
     * @param stdClass $service The service being managed
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters ($post['action'] === 'delete')
     * @param array $files Any FILES parameters
     * @return string The rendered tab HTML
     */
    public function tabDelete($package, $service, array $get = null, array $post = null, array $files = null)
    {
        try {
            Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_status.php');

            $order = $this->resolveOrderByDomain($service);
            $is_delete_post = !empty($post['action']) && (string) $post['action'] === 'delete';
            $eligible = $this->deleteTabEligible($service, $order, !$is_delete_post);
            if ($is_delete_post) {
                if ($this->deleteTabEligible($service, $order, false)) {
                    $deleted = $this->handleDeleteAction($service);
                    $eligible = false;
                } else {
                    $this->setMessage('error', Language::_('Webnic.tab_delete.unavailable', true));
                    $deleted = false;
                }
                if (!$deleted) {
                    $eligible = false;
                }
            }

            $this->view = new View('tab_delete', 'default');
            Loader::loadHelpers($this, ['Form', 'Html']);

            $domain = '';
            try {
                $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
            } catch (\Throwable $e) {
                $domain = '';
            }

            $this->view->set('domain', $domain);
            $this->view->set('eligible', $eligible);
            $this->view->set('alias', $order ? \WebnicStatus::fromOrderState((string) $order->state) : null);
            $this->view->setDefaultView(self::$defaultModuleView);

            return $this->view->fetch();
        } catch (\Throwable $e) {
            $this->logServiceInfoException('tabDelete', $service, $e);

            return '<div class="alert alert-danger" role="alert">'
                . '<i class="bi bi-exclamation-triangle me-2"></i>'
                . Language::_('Webnic.tab_delete.load_error', true)
                . '</div>';
        }
    }

    /**
     * Admin Restore tab: explicit, fee-acknowledged registry restore (FR24/AC3/AC4).
     *
     * Normal render uses a read-only preview and emits no webnic/restore attempt log. POST re-gates
     * local state, requires an explicit fee acknowledgement, and delegates to performRestore().
     *
     * @param stdClass $package The service's package
     * @param stdClass $service The service being managed
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters ($post['action'] === 'restore')
     * @param array $files Any FILES parameters
     * @return string The rendered tab HTML
     */
    public function tabRestore($package, $service, array $get = null, array $post = null, array $files = null)
    {
        try {
            Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_status.php');

            $order = $this->resolveOrderByDomain($service);
            $is_restore_post = !empty($post['action']) && (string) $post['action'] === 'restore';
            $eligible = $this->restoreTabEligible($service, $order, !$is_restore_post);
            $preview = $this->restorePromptState($service, null, true);
            $restore_accepted = false;
            $restore_pending = false;

            if ($is_restore_post) {
                if (!$this->restoreTabEligible($service, $order, false) || $preview === null) {
                    $reason = $preview === null
                        ? 'not_in_grace'
                        : $this->restoreTabBlockReason($service, $order);
                    $this->auditRestoreTabBlock($service, $reason);
                    $this->setMessage('error', Language::_('Webnic.tab_restore.unavailable', true));
                    $eligible = false;
                } elseif (($post['ack_restore_fee'] ?? null) !== '1') {
                    $this->auditRestoreTabBlock($service, 'ack_missing');
                    $this->setMessage('error', Language::_('Webnic.tab_restore.ack_required', true));
                } elseif ($this->handleRestoreAction($service)) {
                    $restore_accepted = true;
                    $restore_pending = is_array($this->last_restore_result ?? null)
                        && !empty($this->last_restore_result['pending']);
                    $eligible = false;
                    $preview = null;
                }
            }

            $this->view = new View('tab_restore', 'default');
            Loader::loadHelpers($this, ['Form', 'Html']);

            $domain = '';
            try {
                $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
            } catch (\Throwable $e) {
                $domain = '';
            }

            $this->view->set('domain', $domain);
            $this->view->set('eligible', $eligible && $preview !== null);
            $this->view->set('restore_prompt', $preview);
            $this->view->set('restore_accepted', $restore_accepted);
            $this->view->set('restore_pending', $restore_pending);
            $this->view->set('alias', $order ? \WebnicStatus::fromOrderState((string) $order->state) : null);
            $this->view->setDefaultView(self::$defaultModuleView);

            return $this->view->fetch();
        } catch (\Throwable $e) {
            $this->logServiceInfoException('tabRestore', $service, $e);

            return '<div class="alert alert-danger" role="alert">'
                . '<i class="bi bi-exclamation-triangle me-2"></i>'
                . Language::_('Webnic.tab_restore.load_error', true)
                . '</div>';
        }
    }

    /**
     * Admin Recovery tab: the money-safe retry/finalise/resolve surface + trace (AC4/AC5/AC6).
     *
     * The idiomatic POST endpoint for failed-order recovery (an inline service-info form
     * cannot handle POST — Dev Notes §E). On POST it dispatches the action through the
     * existing money-safe primitives, records the INV-9 recovery event, and surfaces a
     * Blesta admin message; then it (re-)renders the tracking record, the correlated
     * structured-log trace, and the action buttons against the order's current state.
     *
     * @param stdClass $package The service's package
     * @param stdClass $service The service being managed
     * @param array $get Any GET parameters
     * @param array $post Any POST parameters ($post['action'] in {retry,finalise,resolve})
     * @param array $files Any FILES parameters
     * @return string The rendered tab HTML
     */
    public function tabRecovery($package, $service, array $get = null, array $post = null, array $files = null)
    {
        // The recovery surface is reached in a degraded situation and is the most
        // I/O-heavy of the service hooks (loads 5 classes, the Logs model, a module row,
        // a DB read and a View fetch). Like its sibling hooks it must never break the
        // admin service page: guard the whole body, log the failure (P16), and render a
        // safe inline error instead of propagating a fatal (P9).
        try {
            Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_status.php');
            Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
            Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_failed_order.php');
            Loader::loadModels($this, ['Logs']);

            $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
            $module_row_id = isset($service->module_row_id) ? (int) $service->module_row_id : 0;
            $row = $this->getModuleRow($service->module_row_id ?? null);

            $recovery = new \Webnic\FailedOrder(
                new \Webnic\Orders(),
                function (array $record): void {
                    $this->logRecover($record);
                }
            );

            if (!empty($post['action'])) {
                $this->handleRecoveryAction((string) $post['action'], $package, $service, $row, $module_row_id, $domain, $recovery);
            }

            $this->view = new View('tab_recovery', 'default');
            Loader::loadHelpers($this, ['Form', 'Html']);

            $order = $this->resolveOrderByDomain($service);
            $this->view->set('order', $order ? $this->maskedTrackingRecord($order) : null);
            $this->view->set('alias', $order ? \WebnicStatus::fromOrderState((string) $order->state) : null);
            $this->view->set('is_failed', $order !== null && (string) $order->state === \Webnic\Orders::STATE_FAILED);
            // INV-1/AC6: the owning module id scopes the trace read. `$module` is PRIVATE on
            // the base Module class (with a final getModule() accessor), so it MUST be read
            // through getModule() — reading `$this->module` from this subclass resolves to
            // null (=> 0) and the trace's module_id filter would silently discard every row.
            $module = $this->getModule();
            $module_id = is_object($module) ? (int) ($module->id ?? 0) : 0;
            $this->view->set('trace', $recovery->trace($this->Logs, $module_id, $domain));
            $this->view->setDefaultView(self::$defaultModuleView);

            return $this->view->fetch();
        } catch (\Throwable $e) {
            $this->logServiceInfoException('tabRecovery', $service, $e);

            return '<div class="alert alert-danger" role="alert">'
                . '<i class="bi bi-exclamation-triangle me-2"></i>'
                . Language::_('Webnic.tab_recovery.load_error', true)
                . '</div>';
        }
    }

    /**
     * Dispatches a recovery POST action through the money-safe primitives (AC5/§H).
     *
     * retry re-runs the saga via addService (reconcile-by-domain gates it — never blind
     * resubmits, INV-5); finalise drives a registry-confirmed registered order to active;
     * resolve transitions failed -> cancelled. Each sets a Blesta admin message.
     *
     * @param string $action One of retry|finalise|resolve
     * @param stdClass $package The service's package
     * @param stdClass $service The service being managed
     * @param stdClass|null $row The resolved module row (decrypted meta)
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param string $domain The normalized order domain
     * @param \Webnic\FailedOrder $recovery The recovery presenter
     */
    private function handleRecoveryAction($action, $package, $service, $row, $module_row_id, string $domain, $recovery): void
    {
        if ($action === 'resolve') {
            $result = $recovery->resolve($module_row_id, $domain);
            $this->setMessage($result['ok'] ? 'success' : 'error', Language::_('Webnic.' . $result['message_key'], true));
            if ($result['ok']) {
                $this->dispatchClientNotice('failed_order_resolved', $service, ['domain' => $domain]);
            }

            return;
        }

        if ($action === 'finalise') {
            if (empty($row)) {
                $this->setMessage('error', Language::_('Webnic.recover.finalise_unavailable', true));

                return;
            }

            $result = $recovery->finalise($module_row_id, $domain, $this->buildDomainsApi($row));
            $this->setMessage($result['ok'] ? 'success' : 'error', Language::_('Webnic.' . $result['message_key'], true));
            if ($result['ok']) {
                $this->applyPersistedWhoisPrivacy($service, $row, $domain);
                $this->dispatchClientNotice('failed_order_resolved', $service, ['domain' => $domain]);
            }

            return;
        }

        if ($action === 'retry') {
            // INV-3/INV-5 guard: only a still-`failed` order may be retried. resolve and
            // finalise gate via findFailed(); retry must too, else a forged POST after a
            // resolve could re-seed a `cancelled` order (addService revives ANY terminal
            // row via openIntent). A non-failed order yields a benign "nothing to retry".
            $failed = $recovery->findFailed($module_row_id, $domain);
            if ($failed === null) {
                $this->logRecover($this->recoverRecord('recover.retry', $module_row_id, $domain, 'failed', null, null, 'not_failed'));
                $this->setMessage('error', Language::_('Webnic.recover.not_failed', true));

                return;
            }
            $attempt = (int) ($failed->attempts ?? 0);

            // Re-run the registration saga through addService. The saga revives the terminal
            // row and reconciles by-domain BEFORE any resubmit (INV-5): a domain already
            // registered out-of-band is NOT resubmitted (no double-charge) — the saga returns
            // it to `failed`, and FINALISE (not retry) is the action that drives an already-
            // registered order to active. INV-1: pin the saga to the SERVICE's own module row
            // (a multi-row package must not resolve a different row). Replay the customer's
            // stored nameservers so the retry mirrors the original order, not the shared-NS
            // default.
            $retry_package = is_object($package) ? clone $package : new \stdClass();
            $retry_package->module_row = $module_row_id;
            $vars = array_merge(
                [
                    'domain' => $domain,
                    'client_id' => $service->client_id ?? null,
                    'pricing_id' => $service->pricing_id ?? null,
                    'use_module' => 'true',
                ],
                $this->retryNameservers($service),
                // Replay the customer's ID-protection choice so a retried order re-enables WHOIS
                // privacy (WN-3-5) and mergePersistedOrderFields doesn't reset it to '0'.
                $this->idProtectionFromService($service)
            );

            $result = $this->addService($retry_package, $vars);

            // addService returns a truthy domain-field array WITHOUT running the saga when
            // no module row resolves; a genuine retry always moves the order out of `failed`.
            // "Still failed after addService" is a non-dispatch, not a success.
            $still_failed = $recovery->findFailed($module_row_id, $domain) !== null;
            if ($result === false || $still_failed) {
                // A non-dispatch / saga failure is NOT a confirmed-terminal (refund-eligible)
                // outcome — classify it indeterminate, not terminal, so the trace doesn't
                // mislabel a transient retry failure as refund-terminal (codex #4).
                $this->logRecover($this->recoverRecord(
                    'recover.retry',
                    $module_row_id,
                    $domain,
                    'failed',
                    'failed',
                    'indeterminate',
                    $result === false ? 'saga_failed' : 'not_dispatched',
                    $attempt
                ));
                $this->setMessage('error', Language::_('Webnic.recover.retry_failed', true));

                return;
            }

            // The order is back in-flight. Only a SYNCHRONOUS reconcile-to-active is a
            // resolution worth emailing now; for the async path the order is `pending`
            // again and AC7's pending->active confirmation closes the loop, so firing
            // failed_order_resolved here would be premature and would double-email.
            $order_after = $this->resolveOrderByDomain($service);
            $now_active = $order_after !== null && (string) $order_after->state === \Webnic\Orders::STATE_ACTIVE;
            $this->logRecover($this->recoverRecord(
                'recover.retry',
                $module_row_id,
                $domain,
                'failed',
                $now_active ? \Webnic\Orders::STATE_ACTIVE : null,
                null,
                $now_active ? 'resubmitted_active' : 'resubmitted_pending',
                $attempt
            ));
            $this->setMessage('success', Language::_('Webnic.recover.retry_ok', true));
            if ($now_active) {
                $this->dispatchClientNotice('failed_order_resolved', $service, ['domain' => $domain]);
            }

            return;
        }

        $this->setMessage('error', Language::_('Webnic.recover.unknown_action', true));
    }

    /**
     * Dispatches the confirmed Delete Domain POST through performDelete() (FR26/AC1).
     *
     * @param stdClass $service The service being managed
     * @return bool True when the registry delete is accepted or already satisfied
     */
    private function handleDeleteAction($service): bool
    {
        try {
            $domain = (string) $this->getServiceDomain($service);
        } catch (\Throwable $e) {
            $this->logCancel($this->cancelRecord(
                'delete',
                empty($service->module_row_id) ? -1 : (int) $service->module_row_id,
                '',
                'failed',
                'indeterminate',
                get_class($e),
                $this->cancelServiceId($service)
            ));
            $this->setCancelError('cancel_unavailable');
            $this->setMessage('error', Language::_('Webnic.!error.cancel_unavailable', true));

            return false;
        }

        if ($this->performDelete($domain, null, $service)) {
            $outcome = is_array($this->last_delete_result ?? null)
                ? ($this->last_delete_result['outcome'] ?? 'ok')
                : 'ok';
            $this->setMessage(
                $outcome === 'already' ? 'notice' : 'success',
                $this->deleteSuccessMessage()
            );

            return true;
        }

        $this->setMessage('error', $this->firstInputErrorMessage('cancel_failed'));

        return false;
    }

    /**
     * Dispatches the fee-acknowledged Restore POST through performRestore().
     *
     * @param stdClass $service The service being managed
     * @return bool True when the registry restore is accepted
     */
    private function handleRestoreAction($service): bool
    {
        try {
            $this->loadReconcilerDependencies();
            $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
            $row_id = $service->module_row_id ?? null;
            $row = empty($row_id) ? null : $this->getModuleRow($row_id);
        } catch (\Throwable $e) {
            $this->logRestore($this->restoreRecord(
                'restore',
                empty($service->module_row_id) ? -1 : (int) $service->module_row_id,
                '',
                'failed',
                'indeterminate',
                get_class($e),
                $this->restoreServiceId($service)
            ));
            $this->setRestoreError('restore_unavailable');
            $this->setMessage('error', Language::_('Webnic.!error.restore_unavailable', true));

            return false;
        }

        $decision = $this->performRestore($domain, $row, $service, ['ack_restore_fee' => true]);
        if (($decision['outcome'] ?? 'failed') === 'ok') {
            $pending = is_array($this->last_restore_result ?? null)
                ? (bool) ($this->last_restore_result['pending'] ?? false)
                : false;
            $this->setMessage($pending ? 'notice' : 'success', $this->restoreSuccessMessage());

            return true;
        }

        $this->setRestoreError($decision['error_key'] ?? 'restore_failed');
        $this->setMessage('error', $this->firstInputErrorMessage('restore_failed'));

        return false;
    }

    /**
     * Builds the delete-success banner from the last registry outcome.
     *
     * @return string Localized success/benign copy, with refund warning only on refund=false
     */
    private function deleteSuccessMessage(): string
    {
        $result = is_array($this->last_delete_result ?? null) ? $this->last_delete_result : [];
        $message = Language::_(
            ($result['outcome'] ?? 'ok') === 'already' ? 'Webnic.delete.already' : 'Webnic.cancel.ok',
            true
        );

        if (array_key_exists('refund', $result) && $result['refund'] === false) {
            $message .= ' ' . Language::_('Webnic.!error.cancel_non_refundable', true);
        }

        return $message;
    }

    /**
     * Builds the restore success banner from the last registry outcome.
     *
     * @return string Localized restore success or pending copy
     */
    private function restoreSuccessMessage(): string
    {
        $result = is_array($this->last_restore_result ?? null) ? $this->last_restore_result : [];

        return Language::_(!empty($result['pending']) ? 'Webnic.restore.pending' : 'Webnic.restore.ok', true);
    }

    /**
     * Returns the first current Input error message, or a localized fallback key.
     *
     * @param string $fallback_key The Webnic.!error.* key suffix
     * @return string The display-safe error message
     */
    private function firstInputErrorMessage(string $fallback_key): string
    {
        $errors = $this->errors();
        if (is_array($errors)) {
            foreach ($errors as $field_errors) {
                if (!is_array($field_errors)) {
                    continue;
                }
                foreach ($field_errors as $message) {
                    if (is_string($message) && trim($message) !== '') {
                        return $message;
                    }
                }
            }
        }

        return Language::_('Webnic.!error.' . $fallback_key, true);
    }

    /**
     * Builds a scrubbed INV-9 recovery record for the retry action (AC5/AC6/INV-9).
     *
     * Mirrors FailedOrder::emit()'s record shape so the operator-triggered retry command
     * appears in the same correlated trace as resolve/finalise. The level follows the
     * taxonomy (terminal => error; retryable/indeterminate => notice; otherwise info).
     *
     * @param string $command The recovery command (recover.retry)
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param string $domain The order domain
     * @param string|null $from_state The from state
     * @param string|null $to_state The to state
     * @param string|null $error_class retryable|terminal|indeterminate, or null
     * @param string $message The redacted marker message
     * @param int $attempt The order's attempt count (INV-9 shape parity with emit())
     * @return array The structured recovery record
     */
    private function recoverRecord(string $command, $module_row_id, string $domain, $from_state, $to_state, $error_class, string $message, int $attempt = 0): array
    {
        $level = $error_class === 'terminal'
            ? 'error'
            : (in_array($error_class, ['retryable', 'indeterminate'], true) ? 'notice' : 'info');

        return [
            'run_id' => uniqid('wnret_', true),
            'level' => $level,
            'module_row_id' => (int) $module_row_id,
            'service_id' => self::REGISTER_INTENT_SERVICE_ID,
            'domain' => $domain,
            'command' => $command,
            'from_state' => $from_state,
            'to_state' => $to_state,
            'error_class' => $error_class,
            'attempt' => $attempt,
            'message' => $message,
        ];
    }

    /**
     * Reads the customer's stored nameservers (ns1..ns5) off a service for retry replay.
     *
     * A failed-order retry must re-register with the SAME nameservers the client chose,
     * not silently fall back to the WebNIC shared NS (resolveNameservers' default). Pulls
     * them from the service fields; best-effort — an unreadable/absent field set yields []
     * and the saga keeps its existing default behaviour (no regression).
     *
     * @param stdClass $service The service being retried
     * @return array The ns1..ns5 entries present on the service (possibly empty)
     */
    private function retryNameservers($service): array
    {
        $nameservers = [];
        try {
            if (!empty($service->fields)) {
                $fields = $this->serviceFieldsToObject($service->fields);
                for ($i = 1; $i <= 5; $i++) {
                    $key = 'ns' . $i;
                    if (!empty($fields->{$key})) {
                        $nameservers[$key] = (string) $fields->{$key};
                    }
                }
            }
        } catch (\Throwable $e) {
            // Best-effort replay; fall back to the saga's default nameservers.
        }

        return $nameservers;
    }

    /**
     * Reads the customer's stored ID-protection choice off a service for retry replay (WN-3-5).
     *
     * A failed-order retry must re-apply the WHOIS privacy the customer paid for, not silently
     * drop it: the recovery retry replays ns via retryNameservers but, without this, would re-run
     * addService with $vars lacking id_protection — resetting the persisted value to '0' and
     * skipping the post-register privacy toggle. Mirrors retryNameservers: best-effort; an
     * absent/unreadable field yields [] (the retry simply doesn't re-enable privacy — no regression).
     *
     * @param stdClass $service The service being retried
     * @return array ['id_protection' => '1'] when the stored choice was on, else []
     */
    private function idProtectionFromService($service): array
    {
        try {
            if (!empty($service->fields)) {
                $fields = $this->serviceFieldsToObject($service->fields);
                if (isset($fields->id_protection) && (string) $fields->id_protection === '1') {
                    return ['id_protection' => '1'];
                }
            }
        } catch (\Throwable $e) {
            // Best-effort replay; absence just means the retry won't re-enable privacy.
        }

        return [];
    }

    /**
     * Builds the WebnicDomains by-domain read command for a module row.
     *
     * @param stdClass $row The module row (decrypted meta)
     * @return \WebnicDomains The by-domain read command bound to this row's credentials
     */
    private function buildDomainsApi($row)
    {
        $this->loadReconcilerDependencies();
        Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_domains.php');

        return new \WebnicDomains($this->buildWebnicApi($row));
    }

    /**
     * Builds the WebnicContacts command group for a module row.
     *
     * @param stdClass $row The module row (decrypted meta)
     * @return \WebnicContacts The contact command group bound to this row
     */
    private function buildContactsApi($row)
    {
        $this->loadReconcilerDependencies();
        Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_contacts.php');

        return new \WebnicContacts($this->buildWebnicApi($row));
    }

    /**
     * Builds the WebnicDns command group for a module row.
     *
     * @param stdClass $row The module row (decrypted meta)
     * @return \WebnicDns The DNS command group bound to this row
     */
    private function buildDnsApi($row)
    {
        $this->loadReconcilerDependencies();
        Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_dns.php');

        return new \WebnicDns($this->buildWebnicApi($row));
    }

    /**
     * Loads a row/domain info context for nameserver operations.
     *
     * @param string $domain Domain name
     * @param int|null $module_row_id Module row id
     * @param bool $require_service Whether a real active service must resolve
     * @param bool $set_errors Whether local prerequisite failures should populate Input errors
     * @return array|null ['domain'=>string,'module_row_id'=>int,'row'=>stdClass,'service'=>stdClass,'snapshot'=>array]
     */
    private function loadNameserverContext($domain, $module_row_id, bool $require_service, bool $set_errors = true)
    {
        $this->loadReconcilerDependencies();

        $domain = \Webnic\Orders::normalizeDomain((string) $domain);
        $module_row_id = (int) $module_row_id;

        if ($domain === '') {
            if ($set_errors) {
                $this->setNameserverError('domain', 'missing', 'nameserver_domain_missing');
            }
            return null;
        }
        if ($module_row_id <= 0) {
            if ($set_errors) {
                $this->setNameserverError('module_row_id', 'row_unavailable', 'nameserver_row_unavailable');
            }
            return null;
        }

        $row = $this->getModuleRow($module_row_id);
        if (empty($row)) {
            if ($set_errors) {
                $this->setNameserverError('module_row_id', 'row_unavailable', 'nameserver_row_unavailable');
            }
            return null;
        }

        $service = $this->nameserverSyntheticService($domain, $module_row_id, 'active');
        if ($require_service) {
            $module = $this->getModule();
            $module_id = is_object($module) ? (int) ($module->id ?? 0) : 0;
            $resolved = $this->resolveServiceByDomain($module_id, $module_row_id, $domain);
            if (!is_object($resolved)) {
                if ($set_errors) {
                    $this->setNameserverError('nameservers', 'not_manageable', 'nameserver_not_manageable');
                }
                return null;
            }
            $service = $this->nameserverServiceWithDomain($resolved, $domain, $module_row_id);
        }

        return [
            'domain' => $domain,
            'module_row_id' => $module_row_id,
            'row' => $row,
            'service' => $service,
            'snapshot' => $this->domainInfoSnapshot($service, 'nameservers', true),
        ];
    }

    /**
     * Builds a nameserver operation context from the already-read service-tab snapshot.
     *
     * @param stdClass $service Service being managed
     * @param array $snapshot Cached domain info snapshot
     * @return array|null Nameserver context, or null when local data is incomplete
     */
    private function nameserverContextFromServiceSnapshot($service, array $snapshot)
    {
        $this->loadReconcilerDependencies();

        $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
        $module_row_id = (int) ($service->module_row_id ?? 0);
        if ($domain === '') {
            $this->setNameserverError('domain', 'missing', 'nameserver_domain_missing');
            return null;
        }
        if ($module_row_id <= 0) {
            $this->setNameserverError('module_row_id', 'row_unavailable', 'nameserver_row_unavailable');
            return null;
        }

        $row = $this->getModuleRow($module_row_id);
        if (empty($row)) {
            $this->setNameserverError('module_row_id', 'row_unavailable', 'nameserver_row_unavailable');
            return null;
        }

        return [
            'domain' => $domain,
            'module_row_id' => $module_row_id,
            'row' => $row,
            'service' => $service,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Updates nameservers using an already resolved row/domain info context.
     *
     * @param array $context Nameserver context
     * @param array $vars Submitted nameserver values
     * @param bool $field_specific Whether min-2 errors should bind to ns fields
     * @return bool True when WebNIC accepted the update
     */
    private function setDomainNameserversFromContext(array $context, array $vars = [], bool $field_specific = false): bool
    {
        $this->last_nameserver_update_result = null;

        $nameservers = $this->normalizeNameserverAssignment($vars, $field_specific);
        if ($nameservers === null) {
            return false;
        }

        if (!$this->serviceAllowsNameservers($context['service'], $context['snapshot'], true)) {
            return false;
        }

        return $this->updateNameserversFromContext($context, $nameservers);
    }

    /**
     * Executes the WebNIC nameserver update command for a validated context.
     *
     * @param array $context Nameserver context
     * @param array $nameservers Effective normalized nameservers
     * @return bool True when WebNIC accepted the update
     */
    private function updateNameserversFromContext(array $context, array $nameservers): bool
    {
        $response = $this->buildDomainsApi($context['row'])->updateNameservers($context['domain'], $nameservers);
        if (!$response->success()) {
            $key = $response->errorClass() === 'retryable'
                ? 'nameserver_update_unavailable'
                : 'nameserver_update_failed';
            $this->setNameserverError('nameservers', 'update_failed', $key);
            $this->last_nameserver_update_result = [
                'outcome' => 'failed',
                'error_class' => $response->errorClass(),
            ];
            $this->logNameserverProviderFailure($context, $this->last_nameserver_update_result);

            return false;
        }

        $this->last_nameserver_update_result = [
            'outcome' => 'ok',
            'nameservers' => $nameservers,
        ];
        $this->clearDomainInfoSnapshotCache((int) $context['module_row_id'], (string) $context['domain']);

        return true;
    }

    /**
     * Builds the form state from a registry snapshot without mutating Input errors.
     *
     * @param array|null $snapshot Domain info snapshot
     * @param bool $after_update Whether this read followed a successful provider write
     * @return array values/editable/empty/alert_key
     */
    private function nameserverFormState(?array $snapshot = null, bool $after_update = false): array
    {
        $default = [
            'values' => $this->padNameserverValues([]),
            'editable' => false,
            'empty' => false,
            'alert_key' => 'Webnic.tab_nameservers.unavailable',
        ];

        if (empty($snapshot['ok'])) {
            if ($after_update) {
                $default['alert_key'] = 'Webnic.tab_nameservers.load_error';
            }

            return $default;
        }

        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        if (!array_key_exists('nameservers', $data) || !is_array($data['nameservers'])) {
            $default['alert_key'] = 'Webnic.tab_nameservers.load_error';

            return $default;
        }

        $nameservers = self::normalizedRegistryNameservers($data['nameservers']);
        if (count($nameservers) > self::NAMESERVER_FIELD_LIMIT) {
            return [
                'values' => $this->padNameserverValues($nameservers),
                'editable' => false,
                'empty' => false,
                'alert_key' => 'Webnic.tab_nameservers.too_many',
            ];
        }

        if ($nameservers === []) {
            Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_saga.php');
            $nameservers = \Webnic\Saga\RegistrationSaga::DEFAULT_NAMESERVERS;

            return [
                'values' => $this->padNameserverValues($nameservers),
                'editable' => true,
                'empty' => true,
                'alert_key' => 'Webnic.tab_nameservers.empty',
            ];
        }

        return [
            'values' => $this->padNameserverValues($nameservers),
            'editable' => true,
            'empty' => false,
            'alert_key' => 'Webnic.tab_nameservers.unavailable',
        ];
    }

    /**
     * Returns five form values from submitted nameserver vars without default-fill.
     *
     * @param array $vars Submitted values
     * @return array<int,string> Five nameserver field values
     */
    private function nameserverValuesFromVars(array $vars): array
    {
        $values = [];
        foreach ($this->nameserverFieldVars($vars) as $value) {
            $values[] = is_string($value) ? trim($value) : '';
        }

        return $this->padNameserverValues($values);
    }

    /**
     * Pads or trims nameserver form values to the five-field UI shape.
     *
     * @param array $values Nameserver values
     * @return array<int,string> Five values
     */
    private function padNameserverValues(array $values): array
    {
        $values = array_values(array_slice($values, 0, self::NAMESERVER_FIELD_LIMIT));
        while (count($values) < self::NAMESERVER_FIELD_LIMIT) {
            $values[] = '';
        }

        return $values;
    }

    /**
     * Builds a minimal service object carrying the domain field.
     *
     * @param string $domain Domain name
     * @param int $module_row_id Module row id
     * @param string $status Local service status
     * @return stdClass Service-like object
     */
    private function nameserverSyntheticService(string $domain, int $module_row_id, string $status)
    {
        return $this->nameserverServiceWithDomain((object) [
            'id' => 0,
            'client_id' => 0,
            'module_row_id' => $module_row_id,
            'status' => $status,
        ], $domain, $module_row_id);
    }

    /**
     * Ensures a service-like object carries the domain field used by getServiceDomain().
     *
     * @param stdClass $service Service-like object
     * @param string $domain Domain name
     * @param int $module_row_id Module row id
     * @return stdClass Service-like object with a domain field
     */
    private function nameserverServiceWithDomain($service, string $domain, int $module_row_id)
    {
        $copy = clone $service;
        $copy->module_row_id = $module_row_id;

        $field = new \stdClass();
        $field->key = 'domain';
        $field->value = $domain;
        $copy->fields = [$field];

        return $copy;
    }

    /**
     * Converts a snapshot's raw nameservers into Blesta registrar rows.
     *
     * @param array $snapshot Domain info snapshot
     * @return array list of ['url'=>string,'ips'=>[]]
     */
    private function nameserverRowsFromSnapshot(array $snapshot): array
    {
        $data = is_array($snapshot['data'] ?? null) ? $snapshot['data'] : [];
        $rows = [];
        foreach (self::normalizedRegistryNameservers($data['nameservers'] ?? []) as $nameserver) {
            $rows[] = ['url' => $nameserver, 'ips' => []];
        }

        return $rows;
    }

    /**
     * Normalizes the flat Blesta nameserver assignment list using register-time semantics.
     *
     * @param array $vars Submitted flat or ns1..ns5 nameserver values
     * @return array|null Effective nameservers, or null when validation failed
     */
    private function normalizeNameserverAssignment(array $vars, bool $field_specific = false)
    {
        $ns_vars = $field_specific ? $this->nameserverFieldVars($vars) : $this->nameserverAssignmentVars($vars);
        if (!$this->validateNameserverAssignmentVars($ns_vars)) {
            return null;
        }

        $supplied_count = $this->countSuppliedNameserverVars($ns_vars);
        if ($supplied_count > self::NAMESERVER_FIELD_LIMIT) {
            $this->setNameserverError('nameservers', 'max', 'nameservers_max');

            return null;
        }

        if ($supplied_count === 1) {
            $field = $field_specific ? $this->nameserverMinErrorField($ns_vars) : 'nameservers';
            $this->setNameserverError($field, 'min', 'nameservers_min');

            return null;
        }

        return $this->resolveNameserverAssignmentValues($ns_vars);
    }

    /**
     * Adapts a flat base-contract list to the existing ns1..ns5 resolver shape.
     *
     * @param array $vars Submitted values
     * @return array ns1..ns5 values
     */
    private function nameserverAssignmentVars(array $vars): array
    {
        if ($this->hasNameserverFieldKeys($vars)) {
            return $this->nameserverFieldVars($vars);
        }

        $mapped = [];
        $i = 1;
        foreach ($vars as $value) {
            $mapped['ns' . $i] = $value;
            $i++;
        }

        return $mapped;
    }

    /**
     * Returns whether an input array uses ns1..ns5 field keys.
     *
     * @param array $vars Submitted values
     * @return bool
     */
    private function hasNameserverFieldKeys(array $vars): bool
    {
        for ($i = 1; $i <= self::NAMESERVER_FIELD_LIMIT; $i++) {
            if (array_key_exists('ns' . $i, $vars)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whitelists tab/form input to the five nameserver fields.
     *
     * @param array $vars Submitted values
     * @return array ns1..ns5 values
     */
    private function nameserverFieldVars(array $vars): array
    {
        $mapped = [];
        for ($i = 1; $i <= self::NAMESERVER_FIELD_LIMIT; $i++) {
            $mapped['ns' . $i] = $vars['ns' . $i] ?? '';
        }

        return $mapped;
    }

    /**
     * Validates submitted nameserver values through Blesta Input.
     *
     * @param array $vars nsN => value
     * @return bool
     */
    private function validateNameserverAssignmentVars(array $vars): bool
    {
        $rules = [];
        foreach ($vars as $field => $value) {
            $rules[(string) $field] = [
                'format' => [
                    'rule' => [[$this, 'validateNameserverText']],
                    'message' => Language::_('Webnic.!error.nameserver_invalid', true),
                ],
            ];
        }

        $this->Input->setRules($rules);

        return $this->Input->validates($vars);
    }

    /**
     * Counts non-blank submitted nameserver text values.
     *
     * @param array $vars nsN => value
     * @return int
     */
    private function countSuppliedNameserverVars(array $vars): int
    {
        $count = 0;
        foreach ($vars as $value) {
            if (is_string($value) && trim($value) !== '') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Applies min-2/default-fill semantics without rewriting hostname casing.
     *
     * @param array $vars nsN => value
     * @return array Effective nameservers
     */
    private function resolveNameserverAssignmentValues(array $vars): array
    {
        $nameservers = [];
        foreach ($vars as $value) {
            $value = is_string($value) ? trim($value) : '';
            if ($value !== '') {
                $nameservers[] = $value;
            }
        }

        if ($nameservers === []) {
            Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_saga.php');

            return \Webnic\Saga\RegistrationSaga::DEFAULT_NAMESERVERS;
        }

        return $nameservers;
    }

    /**
     * Chooses the field that should receive the min-2 validation message.
     *
     * @param array $vars ns1..ns5 values
     * @return string Field key
     */
    private function nameserverMinErrorField(array $vars): string
    {
        $supplied = [];
        for ($i = 1; $i <= self::NAMESERVER_FIELD_LIMIT; $i++) {
            $value = $vars['ns' . $i] ?? '';
            if (is_string($value) && trim($value) !== '') {
                $supplied[] = $i;
            }
        }

        if (count($supplied) === 1) {
            $index = $supplied[0];
            if ($index < self::NAMESERVER_FIELD_LIMIT) {
                return 'ns' . ($index + 1);
            }
            if ($index > 1) {
                return 'ns' . ($index - 1);
            }
        }

        return 'nameservers';
    }

    /**
     * Clears one cached domain info snapshot after a successful nameserver mutation.
     *
     * @param int $module_row_id Module row id
     * @param string $domain Domain name
     */
    private function clearDomainInfoSnapshotCache(int $module_row_id, string $domain): void
    {
        unset($this->service_info_domain_cache[$module_row_id . ':' . $domain]);
    }

    /**
     * Sets a localized nameserver Input error.
     *
     * @param string $field Input field
     * @param string $code Error array key under the field
     * @param string $language_key Webnic.!error.* suffix
     */
    private function setNameserverError(string $field, string $code, string $language_key): void
    {
        if (isset($this->Input)) {
            $this->Input->setErrors([
                $field => [
                    $code => Language::_('Webnic.!error.' . $language_key, true),
                ],
            ]);
        }
    }

    /**
     * Flattens current Input errors for nameserver tab rendering.
     *
     * @return array field => string[]
     */
    private function nameserverErrorsForView(): array
    {
        $errors = $this->errors();
        if (!is_array($errors)) {
            return [];
        }

        $flat = [];
        foreach ($errors as $field => $messages) {
            $field = (string) $field;
            $bucket = preg_match('/^ns[1-5]$/', $field) ? $field : '_general';
            foreach ($this->flattenWhoisContactMessages($messages) as $message) {
                $flat[$bucket][] = $message;
            }
        }

        return $flat;
    }

    /**
     * Logs safe nameserver exceptions without provider payloads or credentials.
     *
     * @param string $command Command label
     * @param string $domain Domain
     * @param int $module_row_id Module row id
     * @param \Throwable $e Exception
     */
    private function logNameserverException(string $command, string $domain, int $module_row_id, \Throwable $e): void
    {
        try {
            $record = [
                'command' => $command,
                'module_row_id' => $module_row_id,
                'domain' => $domain,
                'message' => get_class($e) . ': ' . $e->getMessage(),
            ];
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $this->log('webnic/nameservers', serialize($scrubbed), 'output', false);
        } catch (\Throwable $ignored) {
            // Logging must never interrupt service management.
        }
    }

    /**
     * Logs provider-declared nameserver failures through the scrubbed service-info sink.
     *
     * @param array $context Nameserver context
     * @param array $result Failure result
     */
    private function logNameserverProviderFailure(array $context, array $result): void
    {
        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
            $record = [
                'level' => 'error',
                'service_id' => is_object($context['service'] ?? null) ? ($context['service']->id ?? null) : null,
                'module_row_id' => (int) ($context['module_row_id'] ?? 0),
                'domain' => (string) ($context['domain'] ?? ''),
                'command' => 'service_info.nameservers.update',
                'outcome' => (string) ($result['outcome'] ?? 'failed'),
                'error_class' => $result['error_class'] ?? null,
            ];
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $this->log('webnic/service_info', serialize($scrubbed), 'output', false);
        } catch (\Throwable $ignored) {
            // Logging must never interrupt service management.
        }
    }

    /**
     * Builds a DNS-record operation context from the already-read service-tab snapshot.
     *
     * @param stdClass $service Service being managed
     * @param array $snapshot Cached domain info snapshot
     * @return array|null DNS record context, or null when local data is incomplete
     */
    private function dnsRecordContextFromServiceSnapshot($service, array $snapshot)
    {
        $this->loadReconcilerDependencies();

        $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
        $module_row_id = (int) ($service->module_row_id ?? 0);
        if ($domain === '') {
            $this->setDnsRecordError('domain', 'missing', 'dnsrecord_domain_missing');
            return null;
        }
        if ($module_row_id <= 0) {
            $this->setDnsRecordError('module_row_id', 'row_unavailable', 'dnsrecord_row_unavailable');
            return null;
        }

        $row = $this->getModuleRow($module_row_id);
        if (empty($row)) {
            $this->setDnsRecordError('module_row_id', 'row_unavailable', 'dnsrecord_row_unavailable');
            return null;
        }

        return [
            'domain' => $domain,
            'module_row_id' => $module_row_id,
            'row' => $row,
            'service' => $service,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Reads supported types and current zone records for the DNS records tab.
     *
     * @param array $context DNS record context
     * @param bool $set_errors Whether failed reads should populate Input errors
     * @return array records/unsupported_records/record_types/manageable/empty/alert_key
     */
    private function dnsRecordStateFromContext(array $context, bool $set_errors = false): array
    {
        $state = [
            'records' => [],
            'unsupported_records' => [],
            'record_types' => $this->dnsRecordTypesAllowlist(),
            'manageable' => false,
            'empty' => false,
            'alert_key' => 'Webnic.tab_dnsrecords.unavailable',
        ];

        try {
            $dns_api = $this->buildDnsApi($context['row']);
            $types_response = $dns_api->getSupportedRecordTypes();
            $supported = $this->supportedDnsRecordTypesFromResponse($types_response);
            if (!$types_response->success() || $supported === null) {
                if ($set_errors) {
                    $this->setDnsRecordError('records', 'unavailable', 'dnsrecord_unavailable');
                }
                $state['alert_key'] = 'Webnic.tab_dnsrecords.load_error';
                $this->logDnsRecordProviderFailure($context, [
                    'outcome' => 'failed',
                    'command' => 'service_info.dnsrecords.types',
                    'error_class' => $types_response->errorClass(),
                ]);

                return $state;
            }

            $state['record_types'] = $this->dnsRecordTypesAllowlist($supported);
            $records_response = $dns_api->getZoneRecords($context['domain']);
            if (!$this->serviceAllowsDnsRecords(
                $context['service'],
                $context['snapshot'],
                $set_errors,
                $records_response
            )) {
                $state['alert_key'] = $this->dnsZoneResponseIsNotWebnicDns($records_response)
                    ? 'Webnic.tab_dnsrecords.not_webnic_dns'
                    : 'Webnic.tab_dnsrecords.unavailable';

                return $state;
            }

            if (!$records_response->success()) {
                if ($set_errors) {
                    $this->setDnsRecordError('records', 'unavailable', 'dnsrecord_unavailable');
                }
                $state['alert_key'] = 'Webnic.tab_dnsrecords.load_error';
                $this->logDnsRecordProviderFailure($context, [
                    'outcome' => 'failed',
                    'command' => 'service_info.dnsrecords.read',
                    'error_class' => $records_response->errorClass(),
                ]);

                return $state;
            }

            $normalized = $this->dnsRecordsFromResponse($records_response, $state['record_types']);
            if ($normalized === null) {
                if ($set_errors) {
                    $this->setDnsRecordError('records', 'unavailable', 'dnsrecord_unavailable');
                }
                $state['alert_key'] = 'Webnic.tab_dnsrecords.load_error';
                $this->logDnsRecordProviderFailure($context, [
                    'outcome' => 'failed',
                    'command' => 'service_info.dnsrecords.normalize',
                    'error_class' => 'malformed_success',
                ]);

                return $state;
            }

            $state['records'] = $normalized['records'];
            $state['unsupported_records'] = $normalized['unsupported_records'];
            $state['manageable'] = $state['record_types'] !== [];
            $state['empty'] = $state['records'] === [] && $state['unsupported_records'] === [];
            if ($state['record_types'] === []) {
                $state['alert_key'] = 'Webnic.tab_dnsrecords.no_supported_types';
            } else {
                $state['alert_key'] = $state['empty']
                    ? 'Webnic.tab_dnsrecords.empty'
                    : 'Webnic.tab_dnsrecords.unavailable';
            }
        } catch (\Throwable $e) {
            $this->logServiceInfoException('dnsrecords_state', $context['service'] ?? null, $e);
            if ($set_errors) {
                $this->setDnsRecordError('records', 'unavailable', 'dnsrecord_unavailable');
            }
            $state['alert_key'] = 'Webnic.tab_dnsrecords.load_error';
        }

        return $state;
    }

    /**
     * Builds a forwarding operation context from the already-read service-tab snapshot.
     *
     * @param stdClass $service Service being managed
     * @param array $snapshot Cached domain info snapshot
     * @param bool $set_errors Whether local prerequisite failures should populate Input errors
     * @return array|null Forwarding context, or null when local data is incomplete
     */
    private function forwardingContextFromServiceSnapshot($service, array $snapshot, bool $set_errors = false)
    {
        $this->loadReconcilerDependencies();

        $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
        $module_row_id = (int) ($service->module_row_id ?? 0);
        if ($domain === '') {
            if ($set_errors) {
                $this->setForwardingError('domain', 'missing', 'forwarding_domain_missing');
            }
            return null;
        }
        if ($module_row_id <= 0) {
            if ($set_errors) {
                $this->setForwardingError('module_row_id', 'row_unavailable', 'forwarding_row_unavailable');
            }
            return null;
        }

        $row = $this->getModuleRow($module_row_id);
        if (empty($row)) {
            if ($set_errors) {
                $this->setForwardingError('module_row_id', 'row_unavailable', 'forwarding_row_unavailable');
            }
            return null;
        }

        return [
            'domain' => $domain,
            'module_row_id' => $module_row_id,
            'row' => $row,
            'service' => $service,
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Reads current URL and email forwarding rows for the forwarding tab.
     *
     * @param array $context Forwarding context
     * @param bool $set_errors Whether failed reads should populate Input errors
     * @return array url_forwardings/email_forwardings/manageable/empty/alert_key
     */
    private function forwardingStateFromContext(array $context, bool $set_errors = false): array
    {
        $state = [
            'url_forwardings' => [],
            'email_forwardings' => [],
            'manageable' => false,
            'empty' => false,
            'alert_key' => 'Webnic.tab_forwarding.unavailable',
        ];

        try {
            $dns_api = $this->buildDnsApi($context['row']);
            $url_response = $dns_api->getUrlForwardings($context['domain']);
            if (!$this->serviceAllowsForwarding(
                $context['service'],
                $context['snapshot'],
                $set_errors,
                $url_response
            )) {
                $state['alert_key'] = $this->dnsZoneResponseIsNotWebnicDns($url_response)
                    ? 'Webnic.tab_forwarding.not_webnic_dns'
                    : 'Webnic.tab_forwarding.unavailable';

                return $state;
            }

            if (!$url_response->success()) {
                if ($set_errors) {
                    $this->setForwardingError('forwarding', 'unavailable', 'forwarding_unavailable');
                }
                $state['alert_key'] = 'Webnic.tab_forwarding.load_error';
                $this->logForwardingProviderFailure($context, [
                    'outcome' => 'failed',
                    'command' => 'service_info.forwarding.url_read',
                    'error_class' => $url_response->errorClass(),
                ]);

                return $state;
            }

            $email_response = $dns_api->getEmailForwardings($context['domain']);
            if (!$this->serviceAllowsForwarding(
                $context['service'],
                $context['snapshot'],
                $set_errors,
                $email_response
            )) {
                $state['alert_key'] = $this->dnsZoneResponseIsNotWebnicDns($email_response)
                    ? 'Webnic.tab_forwarding.not_webnic_dns'
                    : 'Webnic.tab_forwarding.unavailable';

                return $state;
            }

            if (!$email_response->success()) {
                if ($set_errors) {
                    $this->setForwardingError('forwarding', 'unavailable', 'forwarding_unavailable');
                }
                $state['alert_key'] = 'Webnic.tab_forwarding.load_error';
                $this->logForwardingProviderFailure($context, [
                    'outcome' => 'failed',
                    'command' => 'service_info.forwarding.email_read',
                    'error_class' => $email_response->errorClass(),
                ]);

                return $state;
            }

            $url_rows = $this->forwardingRowsFromResponse($url_response, 'url');
            $email_rows = $this->forwardingRowsFromResponse($email_response, 'email');
            if ($url_rows === null || $email_rows === null) {
                if ($set_errors) {
                    $this->setForwardingError('forwarding', 'unavailable', 'forwarding_unavailable');
                }
                $state['alert_key'] = 'Webnic.tab_forwarding.load_error';
                $this->logForwardingProviderFailure($context, [
                    'outcome' => 'failed',
                    'command' => 'service_info.forwarding.normalize',
                    'error_class' => 'malformed_success',
                ]);

                return $state;
            }

            $state['url_forwardings'] = $url_rows;
            $state['email_forwardings'] = $email_rows;
            $state['manageable'] = true;
            $state['empty'] = $url_rows === [] && $email_rows === [];
            $state['alert_key'] = $state['empty']
                ? 'Webnic.tab_forwarding.empty'
                : 'Webnic.tab_forwarding.unavailable';
        } catch (\Throwable $e) {
            $this->logServiceInfoException('forwarding_state', $context['service'] ?? null, $e);
            if ($set_errors) {
                $this->setForwardingError('forwarding', 'unavailable', 'forwarding_unavailable');
            }
            $state['alert_key'] = 'Webnic.tab_forwarding.load_error';
        }

        return $state;
    }

    /**
     * Normalizes provider forwarding rows into view-safe editable rows.
     *
     * @param WebnicResponse $response Provider response
     * @param string $kind url|email
     * @return array|null Normalized rows, or null when malformed
     */
    private function forwardingRowsFromResponse(\WebnicResponse $response, string $kind)
    {
        if (!$response->success()) {
            return null;
        }

        $data = $response->data();
        if (!is_array($data)) {
            return null;
        }
        if ($data !== [] && array_keys($data) !== range(0, count($data) - 1)) {
            return null;
        }

        $rows = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                return null;
            }
            $normalized = $kind === 'email'
                ? $this->normalizeEmailForwardingRow($row)
                : $this->normalizeUrlForwardingRow($row);
            if ($normalized === null) {
                return null;
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * Normalizes one URL forwarding row.
     *
     * @param array $row Provider row
     * @return array|null View row, or null when malformed
     */
    private function normalizeUrlForwardingRow(array $row)
    {
        if (!is_scalar($row['subdomain'] ?? null) || !is_scalar($row['targetUrl'] ?? null)) {
            return null;
        }

        $subdomain = $this->forwardingProviderSubdomain($row['subdomain']);
        $target = trim((string) $row['targetUrl']);
        if ($target === '') {
            return null;
        }

        $normalized = [
            'kind' => 'url',
            'subdomain' => $subdomain,
            'display_subdomain' => $this->forwardingDisplaySubdomain($subdomain),
            'targetUrl' => $target,
            'status' => is_scalar($row['status'] ?? null) ? trim((string) $row['status']) : '',
            'dtcreate' => is_scalar($row['dtcreate'] ?? null) ? trim((string) $row['dtcreate']) : '',
            'dtmodify' => is_scalar($row['dtmodify'] ?? null) ? trim((string) $row['dtmodify']) : '',
            'editable' => true,
        ];
        $normalized['record_token'] = $this->forwardingRecordToken($normalized);

        return $normalized;
    }

    /**
     * Normalizes one email forwarding row.
     *
     * @param array $row Provider row
     * @return array|null View row, or null when malformed
     */
    private function normalizeEmailForwardingRow(array $row)
    {
        if (!is_scalar($row['user'] ?? null) || !is_scalar($row['targetEmail'] ?? null)) {
            return null;
        }

        $user = trim((string) $row['user']);
        $target = trim((string) $row['targetEmail']);
        if ($user === '' || $target === '') {
            return null;
        }

        $normalized = [
            'kind' => 'email',
            'user' => $user,
            'targetEmail' => $target,
            'status' => is_scalar($row['status'] ?? null) ? trim((string) $row['status']) : '',
            'dtcreate' => is_scalar($row['dtcreate'] ?? null) ? trim((string) $row['dtcreate']) : '',
            'dtmodify' => is_scalar($row['dtmodify'] ?? null) ? trim((string) $row['dtmodify']) : '',
            'editable' => true,
        ];
        $normalized['record_token'] = $this->forwardingRecordToken($normalized);

        return $normalized;
    }

    /**
     * Applies an add/delete forwarding POST against WebNIC.
     *
     * @param array $context Forwarding context
     * @param array $post Submitted values
     * @param array $url_rows Current URL forwarding rows
     * @param array $email_rows Current email forwarding rows
     * @return bool True when WebNIC accepted the change
     */
    private function applyForwardingPost(array $context, array $post, array $url_rows, array $email_rows): bool
    {
        $this->forwarding_post_inconclusive = false;

        $submission = $this->normalizeForwardingSubmission($post, $url_rows, $email_rows);
        if ($submission === null) {
            return false;
        }

        $dns_api = $this->buildDnsApi($context['row']);
        switch ($submission['action']) {
            case 'add_url':
                $response = $dns_api->addUrlForwarding($context['domain'], [
                    'subdomain' => $submission['subdomain'],
                    'targetUrl' => $submission['targetUrl'],
                    'overrideConflictingRecord' => false,
                ]);
                break;
            case 'delete_url':
                $response = $dns_api->removeUrlForwarding($context['domain'], $submission['subdomain']);
                break;
            case 'add_email':
                $response = $dns_api->addEmailForwarding($context['domain'], [
                    'user' => $submission['user'],
                    'targetEmail' => $submission['targetEmail'],
                    'overrideConflictingRecord' => false,
                ]);
                break;
            case 'delete_email':
                $response = $dns_api->removeEmailForwarding($context['domain'], $submission['user']);
                break;
            default:
                $this->setForwardingError('action', 'invalid', 'forwarding_action_invalid');
                return false;
        }

        if (!$response->success()) {
            if ($submission['action'] === 'delete_email') {
                $verdict = $this->emailForwardingDeleteVerdict($context, $submission['user'], $response);
                if ($verdict === 'confirmed') {
                    $this->logForwardingProviderAttempt($context, [
                        'outcome' => 'ok',
                        'command' => 'service_info.forwarding.' . $submission['action'],
                        'accepted_non_success' => true,
                    ]);

                    return true;
                }
                if ($verdict === 'inconclusive') {
                    // AC #5 option A: do not soft-accept an unconfirmed delete. No success notice
                    // and no outcome=ok audit; the caller renders the neutral refresh hint instead.
                    $this->forwarding_post_inconclusive = true;

                    return false;
                }
                // 'present' → the row remains; fall through to the normal failure handling below.
            }

            if (!$this->setForwardingProviderErrors($response, $submission['action'])) {
                $key = $response->errorClass() === 'retryable'
                    ? 'forwarding_update_unavailable'
                    : 'forwarding_update_failed';
                $this->setForwardingError('forwarding', 'update_failed', $key);
            }
            $this->logForwardingProviderFailure($context, [
                'outcome' => 'failed',
                'command' => 'service_info.forwarding.' . $submission['action'],
                'error_class' => $response->errorClass(),
            ]);

            return false;
        }

        $this->logForwardingProviderAttempt($context, [
            'outcome' => 'ok',
            'command' => 'service_info.forwarding.' . $submission['action'],
        ]);

        return true;
    }

    /**
     * Validates and normalizes one forwarding submission.
     *
     * @param array $vars Submitted values
     * @param array $url_rows Current URL forwarding rows
     * @param array $email_rows Current email forwarding rows
     * @return array|null Normalized submission, or null when validation failed
     */
    private function normalizeForwardingSubmission(array $vars, array $url_rows, array $email_rows)
    {
        $flat = $this->forwardingFieldVars($vars);
        if ($this->forwardingHasNonScalarFields($vars)) {
            $this->setForwardingError('forwarding', 'invalid', 'forwarding_invalid');
            return null;
        }

        $rules = [];
        foreach ($flat as $field => $value) {
            $rules[$field] = [
                'format' => [
                    'rule' => [[$this, 'validateDnsRecordText']],
                    'message' => Language::_('Webnic.!error.forwarding_invalid', true),
                ],
            ];
        }
        $this->Input->setRules($rules);
        if (!$this->Input->validates($flat)) {
            return null;
        }

        $action = strtolower(trim((string) $flat['action']));
        if (!in_array($action, ['add_url', 'delete_url', 'add_email', 'delete_email'], true)) {
            $this->setForwardingError('action', 'invalid', 'forwarding_action_invalid');
            return null;
        }

        if ($action === 'delete_url' || $action === 'delete_email') {
            $token = trim((string) $flat['record_token']);
            if ($token === '') {
                $this->setForwardingError('record_token', 'missing', 'forwarding_row_token');
                return null;
            }
            $target = $this->forwardingEditableRowByToken(
                $action === 'delete_url' ? $url_rows : $email_rows,
                $token
            );
            if ($target === null) {
                $this->setForwardingError('forwarding', 'target_invalid', 'forwarding_target_invalid');
                return null;
            }

            return $action === 'delete_url'
                ? ['action' => 'delete_url', 'subdomain' => $target['subdomain']]
                : ['action' => 'delete_email', 'user' => $target['user']];
        }

        if ($action === 'add_url') {
            $subdomain = $this->forwardingProviderSubdomain($flat['subdomain']);
            $valid = true;
            if (!$this->validForwardingSubdomain($subdomain)) {
                $this->setForwardingError('subdomain', 'invalid', 'forwarding_subdomain');
                $valid = false;
            }

            $target = trim((string) $flat['targetUrl']);
            if ($target === '' || preg_match('/^https?:\/\//i', $target) !== 1
                || filter_var($target, FILTER_VALIDATE_URL) === false
            ) {
                $this->setForwardingError('targetUrl', 'invalid', 'forwarding_url_target');
                $valid = false;
            }
            if (!$valid) {
                return null;
            }

            return [
                'action' => 'add_url',
                'subdomain' => $subdomain,
                'targetUrl' => $target,
            ];
        }

        $user = trim((string) $flat['user']);
        $valid = true;
        if (!$this->validForwardingUser($user)) {
            $this->setForwardingError('user', 'invalid', 'forwarding_user');
            $valid = false;
        }

        $target = trim((string) $flat['targetEmail']);
        if (!$this->validateWhoisContactEmail($target)) {
            $this->setForwardingError('targetEmail', 'invalid', 'forwarding_email_target');
            $valid = false;
        }
        if (!$valid) {
            return null;
        }

        return [
            'action' => 'add_email',
            'user' => $user,
            'targetEmail' => $target,
        ];
    }

    /**
     * Whitelists tab/form input to known forwarding fields.
     *
     * @param array $vars Submitted values
     * @return array Whitelisted flat values
     */
    private function forwardingFieldVars(array $vars): array
    {
        $fields = ['action', 'subdomain', 'targetUrl', 'user', 'targetEmail', 'record_token'];
        $flat = [];
        foreach ($fields as $field) {
            $value = $vars[$field] ?? '';
            $flat[$field] = is_scalar($value) ? (string) $value : '';
        }

        return $flat;
    }

    /**
     * Returns whether any known forwarding field was submitted as a non-scalar.
     *
     * @param array $vars Submitted values
     * @return bool True when a submitted field cannot safely be rendered/cast
     */
    private function forwardingHasNonScalarFields(array $vars): bool
    {
        foreach (['action', 'subdomain', 'targetUrl', 'user', 'targetEmail', 'record_token'] as $field) {
            if (array_key_exists($field, $vars) && !is_scalar($vars[$field])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns an empty forwarding form-state payload for the template.
     *
     * @return array
     */
    private function emptyForwardingFormState(): array
    {
        return ['scope' => '', 'key' => '', 'values' => []];
    }

    /**
     * Captures which forwarding form submitted so the view can scope errors and values.
     *
     * @param array $vars Submitted values
     * @return array Form-state payload
     */
    private function forwardingFormStateFromPost(array $vars): array
    {
        $flat = $this->forwardingFieldVars($vars);
        $action = strtolower(trim((string) $flat['action']));
        if ($action === 'add_url' || $action === 'add_email') {
            return ['scope' => $action, 'key' => '', 'values' => $flat];
        }

        if ($action === 'delete_url' || $action === 'delete_email') {
            return [
                'scope' => 'record',
                'key' => trim((string) $flat['record_token']),
                'values' => $flat,
            ];
        }

        return ['scope' => 'general', 'key' => '', 'values' => $flat];
    }

    /**
     * Finds the submitted delete target in the freshly read editable row set.
     *
     * @param array $rows Current editable rows
     * @param string $token Submitted row token
     * @return array|null Current editable row
     */
    private function forwardingEditableRowByToken(array $rows, string $token)
    {
        foreach ($rows as $row) {
            if (!is_array($row) || empty($row['editable'])) {
                continue;
            }
            if (hash_equals($this->forwardingRecordToken($row), $token)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Builds a signed token over a stable forwarding row identity.
     *
     * @param array $row Normalized editable forwarding row
     * @return string HMAC row token
     */
    private function forwardingRecordToken(array $row): string
    {
        $kind = (string) ($row['kind'] ?? '');
        $payload = ['kind' => $kind];
        if ($kind === 'email') {
            $payload['user'] = trim((string) ($row['user'] ?? ''));
            $payload['targetEmail'] = trim((string) ($row['targetEmail'] ?? ''));
        } else {
            $payload['subdomain'] = $this->forwardingProviderSubdomain($row['subdomain'] ?? '');
            $payload['targetUrl'] = trim((string) ($row['targetUrl'] ?? ''));
        }

        $secret = (string) Configure::get('Blesta.system_key');
        if ($secret === '') {
            $secret = __FILE__;
        }

        return hash_hmac('sha256', json_encode($payload), $secret);
    }

    /**
     * Normalizes a submitted/display URL-forward subdomain to the provider selector.
     *
     * @param mixed $subdomain Submitted or provider subdomain
     * @return string Provider subdomain; blank represents the zone apex
     */
    private function forwardingProviderSubdomain($subdomain): string
    {
        $subdomain = trim((string) $subdomain);

        return $subdomain === '@' ? '' : $subdomain;
    }

    /**
     * Normalizes a provider subdomain to the UI display value.
     *
     * @param mixed $subdomain Provider subdomain
     * @return string Display value
     */
    private function forwardingDisplaySubdomain($subdomain): string
    {
        $subdomain = trim((string) $subdomain);

        return $subdomain === '' ? '@' : $subdomain;
    }

    /**
     * Returns whether a submitted URL-forward subdomain is acceptable for WebNIC.
     *
     * @param string $subdomain Provider-normalized subdomain
     * @return bool True when the selector is apex or an OTE-accepted ASCII hostname fragment
     */
    private function validForwardingSubdomain(string $subdomain): bool
    {
        if ($subdomain === '') {
            return true;
        }

        return preg_match('/^(?=.{1,253}$)[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*$/', $subdomain) === 1;
    }

    /**
     * Returns whether a submitted email-forward source local-part is acceptable.
     *
     * @param string $user Source local-part
     * @return bool True when the local-part is non-blank and email-safe
     */
    private function validForwardingUser(string $user): bool
    {
        return $user !== ''
            && strpos($user, '@') === false
            && filter_var($user . '@example.com', FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Classifies OTE's email-delete non-success envelope against a follow-up list read.
     *
     * Per WN-5.6 AC #5 (option A): the captured `DNS2400` "remove email forwarding record failed"
     * envelope is treated as success **iff** a follow-up list proves the row is gone. A follow-up
     * read that fails or throws is indeterminate — it must NOT be soft-accepted (no success notice,
     * no `outcome=ok` audit); the caller routes it to the neutral refresh hint instead.
     *
     * @param array $context Forwarding context
     * @param string $user Source local-part selector
     * @param WebnicResponse $delete_response Delete response
     * @return string One of 'confirmed' (row gone), 'present' (row remains → real failure),
     *                or 'inconclusive' (follow-up read failed/threw → neutral refresh hint)
     */
    private function emailForwardingDeleteVerdict(
        array $context,
        string $user,
        \WebnicResponse $delete_response
    ): string {
        if ($this->dnsResponseSubCode($delete_response) !== 'DNS2400') {
            return 'present';
        }

        $body = $delete_response->body();
        $message = is_array($body) && is_array($body['error'] ?? null) && is_scalar($body['error']['message'] ?? null)
            ? strtolower(trim((string) $body['error']['message']))
            : '';
        if (stripos($message, 'remove email forwarding record failed') === false) {
            return 'present';
        }

        try {
            $response = $this->buildDnsApi($context['row'])->getEmailForwardings($context['domain']);
            $rows = $this->forwardingRowsFromResponse($response, 'email');
            if ($rows === null) {
                return 'inconclusive';
            }

            foreach ($rows as $row) {
                if (isset($row['user']) && hash_equals((string) $row['user'], $user)) {
                    return 'present';
                }
            }

            return 'confirmed';
        } catch (\Throwable $e) {
            $this->logServiceInfoException('forwarding_email_delete_verify', $context['service'] ?? null, $e);
        }

        return 'inconclusive';
    }

    /**
     * Sets a localized forwarding Input error.
     *
     * @param string $field Input field
     * @param string $code Error array key under the field
     * @param string $language_key Webnic.!error.* suffix
     */
    private function setForwardingError(string $field, string $code, string $language_key): void
    {
        if (isset($this->Input)) {
            $errors = $this->errors();
            if (!is_array($errors)) {
                $errors = [];
            }
            if (!isset($errors[$field]) || !is_array($errors[$field])) {
                $errors[$field] = [];
            }
            $errors[$field][$code] = Language::_('Webnic.!error.' . $language_key, true);
            $this->Input->setErrors($errors);
        }
    }

    /**
     * Maps provider-declared forwarding failures to field errors when possible.
     *
     * @param WebnicResponse $response Provider response
     * @param string $action Submitted forwarding action
     * @return bool True when a field-specific error was set
     */
    private function setForwardingProviderErrors(\WebnicResponse $response, string $action): bool
    {
        $field = $this->forwardingProviderErrorField($response, $action);
        if ($field === null) {
            return false;
        }

        $keys = [
            'subdomain' => 'forwarding_subdomain',
            'targetUrl' => 'forwarding_url_target',
            'user' => 'forwarding_user',
            'targetEmail' => 'forwarding_email_target',
        ];
        $this->setForwardingError($field, 'provider_invalid', $keys[$field] ?? 'forwarding_update_failed');

        return true;
    }

    /**
     * Infers the forwarding form field identified by a provider validation response.
     *
     * @param WebnicResponse $response Provider response
     * @param string $action Submitted forwarding action
     * @return string|null Field name, or null when no safe mapping exists
     */
    private function forwardingProviderErrorField(\WebnicResponse $response, string $action)
    {
        $body = $response->body();
        $field_text = '';
        if (is_array($body)) {
            if (isset($body['validationErrors']) && is_array($body['validationErrors'])) {
                foreach ($body['validationErrors'] as $error) {
                    if (is_array($error) && is_scalar($error['field'] ?? null)) {
                        $field_text .= ' ' . (string) $error['field'];
                    }
                    if (is_array($error) && is_scalar($error['message'] ?? null)) {
                        $field_text .= ' ' . (string) $error['message'];
                    }
                }
            }
            if (is_array($body['error'] ?? null) && is_scalar($body['error']['message'] ?? null)) {
                $field_text .= ' ' . (string) $body['error']['message'];
            }
            if (is_scalar($body['message'] ?? null)) {
                $field_text .= ' ' . (string) $body['message'];
            }
        }

        $field_text = strtolower($field_text);
        if ($field_text === '') {
            return null;
        }

        if ($action === 'delete_url' || $action === 'delete_email') {
            return null;
        }

        if ($action === 'add_email') {
            if (strpos($field_text, 'user') !== false) {
                return 'user';
            }
            if (strpos($field_text, 'target') !== false
                || strpos($field_text, 'destination') !== false
                || strpos($field_text, 'email') !== false
            ) {
                return 'targetEmail';
            }

            return null;
        }

        if ($action === 'add_url') {
            if (strpos($field_text, 'url') !== false || strpos($field_text, 'http') !== false) {
                return 'targetUrl';
            }
            if (strpos($field_text, 'subdomain') !== false
                || strpos($field_text, 'record name') !== false
                || strpos($field_text, 'a record exists') !== false
            ) {
                return 'subdomain';
            }
        }

        return null;
    }

    /**
     * Flattens current Input errors for forwarding tab rendering.
     *
     * @return array field => string[]
     */
    private function forwardingErrorsForView(): array
    {
        $errors = $this->errors();
        if (!is_array($errors)) {
            return [];
        }

        $known = ['action', 'subdomain', 'targetUrl', 'user', 'targetEmail', 'record_token', 'forwarding'];
        $flat = [];
        foreach ($errors as $field => $messages) {
            $field = (string) $field;
            $bucket = in_array($field, $known, true) ? $field : '_general';
            foreach ($this->flattenWhoisContactMessages($messages) as $message) {
                $flat[$bucket][] = $message;
            }
        }

        return $flat;
    }

    /**
     * Logs provider-declared forwarding failures through the scrubbed service-info sink.
     *
     * @param array $context Forwarding context
     * @param array $result Failure result
     */
    private function logForwardingProviderFailure(array $context, array $result): void
    {
        $this->logForwardingProviderAttempt($context, $result);
    }

    /**
     * Logs forwarding write/read attempts through the scrubbed service-info sink.
     *
     * @param array $context Forwarding context
     * @param array $result Attempt result
     */
    private function logForwardingProviderAttempt(array $context, array $result): void
    {
        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
            $outcome = (string) ($result['outcome'] ?? 'failed');
            $record = [
                'level' => $outcome === 'ok' ? 'info' : 'error',
                'service_id' => is_object($context['service'] ?? null) ? ($context['service']->id ?? null) : null,
                'module_row_id' => (int) ($context['module_row_id'] ?? 0),
                'domain' => (string) ($context['domain'] ?? ''),
                'command' => (string) ($result['command'] ?? 'service_info.forwarding'),
                'outcome' => $outcome,
                'error_class' => $result['error_class'] ?? null,
            ];
            if (array_key_exists('accepted_non_success', $result)) {
                $record['accepted_non_success'] = (bool) $result['accepted_non_success'];
            }
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $this->log('webnic/service_info', serialize($scrubbed), 'output', $outcome === 'ok');
        } catch (\Throwable $ignored) {
            // Logging must never interrupt service management.
        }
    }

    /**
     * Returns the editable DNS type allowlist for WN-5.5.
     *
     * @param array|null $supported Provider supported-type list, or null for the T0 default
     * @return array<int,string> Uppercase editable record types
     */
    private function dnsRecordTypesAllowlist(array $supported = null): array
    {
        $baseline = array_values(array_intersect(self::DNS_RECORD_PARITY_TYPES, self::DNS_RECORD_T0_SUPPORTED_TYPES));
        if ($supported === null) {
            return $baseline;
        }

        $provider_types = [];
        foreach ($supported as $type) {
            $type = strtoupper(trim((string) $type));
            if ($type !== '') {
                $provider_types[] = $type;
            }
        }

        return array_values(array_intersect($baseline, array_unique($provider_types)));
    }

    /**
     * Extracts supported DNS record types from the WebNIC response.
     *
     * @param WebnicResponse $response Provider response
     * @return array<int,string>|null Supported type list, or null when malformed
     */
    private function supportedDnsRecordTypesFromResponse(\WebnicResponse $response)
    {
        if (!$response->success()) {
            return null;
        }

        $data = $response->data();
        if (is_array($data) && isset($data['recordTypes']) && is_array($data['recordTypes'])) {
            $data = $data['recordTypes'];
        }
        if (!is_array($data)) {
            return null;
        }

        $types = [];
        foreach ($data as $type) {
            if (!is_scalar($type)) {
                return null;
            }
            $type = strtoupper(trim((string) $type));
            if ($type !== '') {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * Normalizes WebNIC zone records into view-safe editable/read-only rows.
     *
     * @param WebnicResponse $response Provider response
     * @param array $allowlist Editable record types
     * @return array|null records/unsupported_records, or null when malformed
     */
    private function dnsRecordsFromResponse(\WebnicResponse $response, array $allowlist)
    {
        if (!$response->success()) {
            return null;
        }

        $data = $response->data();
        if (is_array($data) && isset($data['records']) && is_array($data['records'])) {
            $records = $data['records'];
        } elseif (is_array($data) && $data === []) {
            $records = [];
        } elseif (is_array($data) && array_keys($data) === range(0, count($data) - 1)) {
            $records = $data;
        } else {
            return null;
        }

        $normalized = ['records' => [], 'unsupported_records' => []];
        foreach ($records as $record) {
            if (!is_array($record)) {
                return null;
            }

            $type = strtoupper(trim((string) ($record['type'] ?? '')));
            $rdatas = isset($record['rdatas']) && is_array($record['rdatas']) ? array_values($record['rdatas']) : [];
            if (!in_array($type, $allowlist, true)) {
                $normalized['unsupported_records'][] = $this->unsupportedDnsRecordRow($record, $allowlist);
                continue;
            }

            if (count($rdatas) !== 1 || !is_array($rdatas[0])) {
                $reason_key = count($rdatas) > 1
                    ? 'Webnic.tab_dnsrecords.read_only_multi_value'
                    : 'Webnic.tab_dnsrecords.read_only_invalid_value';
                $normalized['unsupported_records'][] = $this->unsupportedDnsRecordRow($record, $allowlist, $reason_key);
                continue;
            }

            $row = $this->normalizeDnsRecordRow($record, $rdatas[0], $allowlist);
            if ($row === null) {
                $normalized['unsupported_records'][] = $this->unsupportedDnsRecordRow(
                    $record,
                    $allowlist,
                    'Webnic.tab_dnsrecords.read_only_invalid_value'
                );
                continue;
            }

            $normalized['records'][] = $row;
        }

        return $normalized;
    }

    /**
     * Normalizes one editable WebNIC DNS record row.
     *
     * @param array $record Provider record
     * @param array $rdata Provider rdata row
     * @param array $allowlist Editable record types
     * @return array|null View-safe row, or null when the row is not editable
     */
    private function normalizeDnsRecordRow(array $record, array $rdata, array $allowlist)
    {
        $type = strtoupper(trim((string) ($record['type'] ?? '')));
        if (!in_array($type, $allowlist, true)) {
            return null;
        }

        $name = $this->dnsProviderName($record['name'] ?? '');
        $value = trim((string) ($rdata['value'] ?? ''));
        if ($value === '') {
            return null;
        }

        $priority = '';
        $weight = '';
        $port = '';
        if ($type === 'MX') {
            $parts = preg_split('/\s+/', $value, 2);
            if (count($parts) === 2 && preg_match('/^[0-9]+$/', $parts[0]) === 1) {
                $priority = $parts[0];
                $value = $parts[1];
            } else {
                return null;
            }
        } elseif ($type === 'SRV') {
            $parts = preg_split('/\s+/', $value, 4);
            if (count($parts) === 4
                && preg_match('/^[0-9]+$/', $parts[0]) === 1
                && preg_match('/^[0-9]+$/', $parts[1]) === 1
                && preg_match('/^[0-9]+$/', $parts[2]) === 1
            ) {
                $priority = $parts[0];
                $weight = $parts[1];
                $port = $parts[2];
                $value = $parts[3];
            } else {
                return null;
            }
        }

        $ttl = $record['ttl'] ?? self::DNS_RECORD_DEFAULT_TTL;
        $ttl = is_scalar($ttl) ? trim((string) $ttl) : (string) self::DNS_RECORD_DEFAULT_TTL;

        $row = [
            'type' => $type,
            'name' => $this->dnsDisplayName($name),
            'provider_name' => $name,
            'value' => $value,
            'ttl' => $ttl,
            'priority' => $priority,
            'weight' => $weight,
            'port' => $port,
            'editable' => true,
        ];
        $row['record_token'] = $this->dnsRecordToken($row);

        return $row;
    }

    /**
     * Builds a read-only row for provider records outside the WN-5.5 editable shape.
     *
     * @param array $record Provider record
     * @param array $allowlist Editable record types
     * @param string|null $reason_key Localized read-only reason override
     * @return array View-safe read-only row
     */
    private function unsupportedDnsRecordRow(array $record, array $allowlist, string $reason_key = null): array
    {
        $type = strtoupper(trim((string) ($record['type'] ?? '')));
        $name = $this->dnsProviderName($record['name'] ?? '');
        $rdatas = isset($record['rdatas']) && is_array($record['rdatas']) ? $record['rdatas'] : [];
        $values = [];
        foreach ($rdatas as $rdata) {
            if (is_array($rdata) && is_scalar($rdata['value'] ?? null)) {
                $values[] = trim((string) $rdata['value']);
            }
        }

        return [
            'type' => $type,
            'name' => $this->dnsDisplayName($name),
            'value' => implode(', ', array_filter($values, static function ($value) {
                return $value !== '';
            })),
            'ttl' => is_scalar($record['ttl'] ?? null) ? trim((string) $record['ttl']) : '',
            'reason_key' => $reason_key ?? (
                in_array($type, $allowlist, true)
                    ? 'Webnic.tab_dnsrecords.read_only_multi_value'
                    : 'Webnic.tab_dnsrecords.read_only_type'
            ),
        ];
    }

    /**
     * Applies an add/edit/delete DNS record POST against WebNIC.
     *
     * @param array $context DNS record context
     * @param array $post Submitted values
     * @param array $allowlist Editable record types
     * @param array $editable_records Current editable records from the pre-write read
     * @return bool True when WebNIC accepted the change
     */
    private function applyDnsRecordPost(array $context, array $post, array $allowlist, array $editable_records): bool
    {
        $submission = $this->normalizeDnsRecordSubmission($post, $allowlist, $editable_records);
        if ($submission === null) {
            return false;
        }

        $dns_api = $this->buildDnsApi($context['row']);
        if ($submission['action'] === 'delete') {
            $response = $dns_api->deleteZoneRecord(
                $context['domain'],
                $submission['type'],
                $submission['name']
            );
        } else {
            $response = $dns_api->saveZoneRecord($context['domain'], $submission['record']);
        }

        if (!$response->success()) {
            if (!$this->setDnsRecordProviderErrors($response)) {
                $key = $response->errorClass() === 'retryable'
                    ? 'dnsrecord_update_unavailable'
                    : 'dnsrecord_update_failed';
                $this->setDnsRecordError('records', 'update_failed', $key);
            }
            $this->logDnsRecordProviderFailure($context, [
                'outcome' => 'failed',
                'command' => 'service_info.dnsrecords.' . $submission['action'],
                'error_class' => $response->errorClass(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Validates and normalizes one DNS record submission.
     *
     * @param array $vars Submitted values
     * @param array $allowlist Editable record types
     * @param array $editable_records Current editable records from the pre-write read
     * @return array|null Normalized submission, or null when validation failed
     */
    private function normalizeDnsRecordSubmission(array $vars, array $allowlist, array $editable_records)
    {
        $flat = $this->dnsRecordFieldVars($vars);
        $rules = [];
        foreach ($flat as $field => $value) {
            $rules[$field] = [
                'format' => [
                    'rule' => [[$this, 'validateDnsRecordText']],
                    'message' => Language::_('Webnic.!error.dnsrecord_invalid', true),
                ],
            ];
        }
        $this->Input->setRules($rules);
        if (!$this->Input->validates($flat)) {
            return null;
        }

        $action = strtolower(trim((string) $flat['action']));
        if (!in_array($action, ['add', 'edit', 'delete'], true)) {
            $this->setDnsRecordError('action', 'invalid', 'dnsrecord_invalid');
            return null;
        }

        if ($action === 'delete' || $action === 'edit') {
            $target_record = $this->dnsEditableRecordByToken($editable_records, $flat['record_token']);
            if ($target_record === null) {
                $this->setDnsRecordError('records', 'target_invalid', 'dnsrecord_target_invalid');
                return null;
            }
            $target_type = $target_record['type'];
            $target_name = $target_record['provider_name'];
        } else {
            $target_type = $flat['type'];
            $target_name = $flat['name'];
        }

        $type = strtoupper(trim((string) $target_type));
        if ($type === '' || !in_array($type, $allowlist, true)) {
            $this->setDnsRecordError('type', 'type_invalid', 'dnsrecord_type_invalid');
            return null;
        }

        $name = $this->dnsProviderName($target_name);
        if ($action === 'delete') {
            return [
                'action' => 'delete',
                'type' => $type,
                'name' => $name,
            ];
        }

        $value = trim((string) $flat['value']);
        if ($value === '') {
            $this->setDnsRecordError('value', 'required', 'dnsrecord_required');
            return null;
        }

        $ttl = $this->dnsIntegerValue('ttl', $flat['ttl'], false, 0, self::DNS_RECORD_MAX_TTL);
        if ($ttl === null && $this->dnsInputHasValue($flat['ttl'])) {
            return null;
        }
        $ttl = $ttl ?? self::DNS_RECORD_DEFAULT_TTL;

        $provider_value = $this->dnsProviderValueFromSubmission($type, $value, $flat);
        if ($provider_value === null) {
            return null;
        }

        return [
            'action' => $action,
            'type' => $type,
            'name' => $name,
            'record' => [
                'name' => $name,
                'type' => $type,
                'ttl' => $ttl,
                'rdatas' => [
                    ['value' => $provider_value],
                ],
            ],
        ];
    }

    /**
     * Whitelists tab/form input to known DNS fields.
     *
     * @param array $vars Submitted values
     * @return array Whitelisted flat values
     */
    private function dnsRecordFieldVars(array $vars): array
    {
        $fields = ['action', 'type', 'name', 'value', 'ttl', 'priority', 'weight', 'port', 'record_token'];
        $flat = [];
        foreach ($fields as $field) {
            $flat[$field] = $vars[$field] ?? '';
        }

        return $flat;
    }

    /**
     * Returns an empty DNS form-state payload for the template.
     *
     * @return array
     */
    private function emptyDnsRecordFormState(): array
    {
        return ['scope' => '', 'key' => '', 'values' => []];
    }

    /**
     * Captures which DNS form submitted so the view can scope errors and values.
     *
     * @param array $vars Submitted values
     * @return array Form-state payload
     */
    private function dnsRecordFormStateFromPost(array $vars): array
    {
        $flat = $this->dnsRecordFieldVars($vars);
        $action = strtolower(trim((string) $flat['action']));
        if ($action === 'add') {
            return ['scope' => 'add', 'key' => '', 'values' => $flat];
        }

        if ($action === 'edit' || $action === 'delete') {
            return [
                'scope' => 'record',
                'key' => trim((string) $flat['record_token']),
                'values' => $flat,
            ];
        }

        return ['scope' => 'general', 'key' => '', 'values' => $flat];
    }

    /**
     * Finds the submitted edit/delete target in the freshly read editable row set.
     *
     * @param array $records Current editable records
     * @param mixed $token Submitted row token
     * @return array|null Current editable record row
     */
    private function dnsEditableRecordByToken(array $records, $token)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }

        foreach ($records as $record) {
            if (!is_array($record) || empty($record['editable'])) {
                continue;
            }
            if (hash_equals($this->dnsRecordToken($record), $token)) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Builds a signed token over the editable provider row's current identity and value.
     *
     * @param array $record Normalized editable DNS record
     * @return string HMAC row token
     */
    private function dnsRecordToken(array $record): string
    {
        $payload = [
            'type' => strtoupper(trim((string) ($record['type'] ?? ''))),
            'provider_name' => $this->dnsProviderName($record['provider_name'] ?? ''),
            'value' => trim((string) ($record['value'] ?? '')),
            'ttl' => trim((string) ($record['ttl'] ?? '')),
            'priority' => trim((string) ($record['priority'] ?? '')),
            'weight' => trim((string) ($record['weight'] ?? '')),
            'port' => trim((string) ($record['port'] ?? '')),
        ];
        $secret = (string) Configure::get('Blesta.system_key');
        if ($secret === '') {
            $secret = __FILE__;
        }

        return hash_hmac('sha256', json_encode($payload), $secret);
    }

    /**
     * Returns whether a submitted scalar has non-blank text.
     *
     * @param mixed $value Submitted value
     * @return bool
     */
    private function dnsInputHasValue($value): bool
    {
        return is_string($value) ? trim($value) !== '' : is_int($value);
    }

    /**
     * Parses an integer field without floats or loose numeric coercion.
     *
     * @param string $field Field name
     * @param mixed $value Submitted value
     * @param bool $required Whether blank is invalid
     * @param int $min Minimum allowed value
     * @param int|null $max Maximum allowed value
     * @return int|null Parsed integer, or null when blank/invalid
     */
    private function dnsIntegerValue(string $field, $value, bool $required, int $min = 0, int $max = null)
    {
        $language_key = 'dnsrecord_' . $field . '_invalid';
        if (!$this->dnsInputHasValue($value)) {
            if ($required) {
                $this->setDnsRecordError($field, 'required', 'dnsrecord_required');
            }
            return null;
        }

        $text = is_int($value) ? (string) $value : trim((string) $value);
        if (preg_match('/^[0-9]+$/', $text) !== 1) {
            $this->setDnsRecordError($field, 'invalid', $language_key);
            return null;
        }

        $integer = (int) $text;
        if ((string) $integer !== ltrim($text, '0') && !preg_match('/^0+$/', $text)) {
            $this->setDnsRecordError($field, 'invalid', $language_key);
            return null;
        }
        if ($integer < $min || ($max !== null && $integer > $max)) {
            $this->setDnsRecordError($field, 'invalid', $language_key);
            return null;
        }

        return $integer;
    }

    /**
     * Builds the provider rdata value from submitted type-specific fields.
     *
     * @param string $type Record type
     * @param string $value Submitted value
     * @param array $flat Whitelisted submitted values
     * @return string|null Provider rdata value, or null when validation failed
     */
    private function dnsProviderValueFromSubmission(string $type, string $value, array $flat)
    {
        if ($type === 'MX') {
            $priority = $this->dnsIntegerValue('priority', $flat['priority'], true, 0, 65535);
            if ($priority === null) {
                return null;
            }

            return $priority . ' ' . $value;
        }

        if ($type === 'SRV') {
            $priority = $this->dnsIntegerValue('priority', $flat['priority'], true, 0, 65535);
            $weight = $this->dnsIntegerValue('weight', $flat['weight'], true, 0, 65535);
            $port = $this->dnsIntegerValue('port', $flat['port'], true, 1, 65535);
            if ($priority === null || $weight === null || $port === null) {
                return null;
            }

            return $priority . ' ' . $weight . ' ' . $port . ' ' . $value;
        }

        return $value;
    }

    /**
     * Normalizes user/display record names to WebNIC provider names.
     *
     * @param mixed $name Submitted/display name
     * @return string Provider name; blank represents the zone apex
     */
    private function dnsProviderName($name): string
    {
        $name = trim((string) $name);

        return $name === '@' ? '' : $name;
    }

    /**
     * Normalizes provider record names for display.
     *
     * @param mixed $name Provider name
     * @return string Display name
     */
    private function dnsDisplayName($name): string
    {
        $name = trim((string) $name);

        return $name === '' ? '@' : $name;
    }

    /**
     * Sets a localized DNS-record Input error.
     *
     * @param string $field Input field
     * @param string $code Error array key under the field
     * @param string $language_key Webnic.!error.* suffix
     */
    private function setDnsRecordError(string $field, string $code, string $language_key): void
    {
        if (isset($this->Input)) {
            $errors = $this->errors();
            if (!is_array($errors)) {
                $errors = [];
            }
            if (!isset($errors[$field]) || !is_array($errors[$field])) {
                $errors[$field] = [];
            }
            $errors[$field][$code] = Language::_('Webnic.!error.' . $language_key, true);
            $this->Input->setErrors($errors);
        }
    }

    /**
     * Maps provider-declared DNS validation failures to field errors when possible.
     *
     * @param WebnicResponse $response Provider response
     * @return bool True when a field-specific error was set
     */
    private function setDnsRecordProviderErrors(\WebnicResponse $response): bool
    {
        $field = $this->dnsRecordProviderErrorField($response);
        if ($field === null) {
            return false;
        }

        $keys = [
            'name' => 'dnsrecord_name_invalid',
            'type' => 'dnsrecord_type_invalid',
            'value' => 'dnsrecord_value_invalid',
            'ttl' => 'dnsrecord_ttl_invalid',
            'priority' => 'dnsrecord_priority_invalid',
            'weight' => 'dnsrecord_weight_invalid',
            'port' => 'dnsrecord_port_invalid',
        ];
        $this->setDnsRecordError($field, 'provider_invalid', $keys[$field] ?? 'dnsrecord_update_failed');

        return true;
    }

    /**
     * Infers the DNS form field identified by a provider validation response.
     *
     * @param WebnicResponse $response Provider response
     * @return string|null Field name, or null when no safe mapping exists
     */
    private function dnsRecordProviderErrorField(\WebnicResponse $response)
    {
        $body = $response->body();
        $field_text = '';
        if (is_array($body)) {
            if (isset($body['validationErrors']) && is_array($body['validationErrors'])) {
                foreach ($body['validationErrors'] as $error) {
                    if (is_array($error) && is_scalar($error['field'] ?? null)) {
                        $field_text .= ' ' . (string) $error['field'];
                    }
                    if (is_array($error) && is_scalar($error['message'] ?? null)) {
                        $field_text .= ' ' . (string) $error['message'];
                    }
                }
            }
            if (is_array($body['error'] ?? null) && is_scalar($body['error']['message'] ?? null)) {
                $field_text .= ' ' . (string) $body['error']['message'];
            }
            if (is_scalar($body['message'] ?? null)) {
                $field_text .= ' ' . (string) $body['message'];
            }
        }

        $field_text = strtolower($field_text);
        if ($field_text === '') {
            return null;
        }

        $map = [
            'priority' => ['priority'],
            'weight' => ['weight'],
            'port' => ['port'],
            'ttl' => ['ttl'],
            'type' => ['type', 'record type', 'unsupported'],
            'name' => ['name', 'host'],
            'value' => ['rdata', 'value', 'ipv4', 'ipv6', 'cname', 'txt', 'srv', 'mx'],
        ];
        foreach ($map as $field => $needles) {
            foreach ($needles as $needle) {
                if (strpos($field_text, $needle) !== false) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * Flattens current Input errors for DNS record tab rendering.
     *
     * @return array field => string[]
     */
    private function dnsRecordErrorsForView(): array
    {
        $errors = $this->errors();
        if (!is_array($errors)) {
            return [];
        }

        $known = ['action', 'type', 'name', 'value', 'ttl', 'priority', 'weight', 'port', 'records'];
        $flat = [];
        foreach ($errors as $field => $messages) {
            $field = (string) $field;
            $bucket = in_array($field, $known, true) ? $field : '_general';
            foreach ($this->flattenWhoisContactMessages($messages) as $message) {
                $flat[$bucket][] = $message;
            }
        }

        return $flat;
    }

    /**
     * Logs provider-declared DNS failures through the scrubbed service-info sink.
     *
     * @param array $context DNS record context
     * @param array $result Failure result
     */
    private function logDnsRecordProviderFailure(array $context, array $result): void
    {
        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
            $record = [
                'level' => 'error',
                'service_id' => is_object($context['service'] ?? null) ? ($context['service']->id ?? null) : null,
                'module_row_id' => (int) ($context['module_row_id'] ?? 0),
                'domain' => (string) ($context['domain'] ?? ''),
                'command' => (string) ($result['command'] ?? 'service_info.dnsrecords'),
                'outcome' => (string) ($result['outcome'] ?? 'failed'),
                'error_class' => $result['error_class'] ?? null,
            ];
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $this->log('webnic/service_info', serialize($scrubbed), 'output', false);
        } catch (\Throwable $ignored) {
            // Logging must never interrupt service management.
        }
    }

    /**
     * Extracts the provider error subcode from a WebNIC response.
     *
     * @param WebnicResponse $response Provider response
     * @return string|null Provider subcode
     */
    private function dnsResponseSubCode(\WebnicResponse $response)
    {
        $body = $response->body();
        if (!is_array($body) || !is_array($body['error'] ?? null)) {
            return null;
        }

        $sub_code = $body['error']['subCode'] ?? null;
        return is_scalar($sub_code) ? trim((string) $sub_code) : null;
    }

    /**
     * Returns whether a zone read proved the domain is not using WebNIC basic DNS.
     *
     * @param WebnicResponse $response Get Zone Records response
     * @return bool True when WebNIC reports no usable DNS zone
     */
    private function dnsZoneResponseIsNotWebnicDns(\WebnicResponse $response): bool
    {
        return in_array($this->dnsResponseSubCode($response), self::DNS_RECORD_NO_ZONE_SUBCODES, true);
    }

    /**
     * Loads a fresh row/domain info context for WHOIS/contact operations.
     *
     * @param string $domain Domain name
     * @param int|null $module_row_id Module row id
     * @return array|null ['domain'=>string,'module_row_id'=>int,'row'=>stdClass,'info'=>array]
     */
    private function loadWhoisContactContext($domain, $module_row_id)
    {
        $this->loadReconcilerDependencies();
        $domain = \Webnic\Orders::normalizeDomain((string) $domain);
        $module_row_id = (int) $module_row_id;

        if ($domain === '') {
            $this->setWhoisContactError('domain', 'contact_domain_missing');
            return null;
        }
        if ($module_row_id <= 0) {
            $this->setWhoisContactError('module_row_id', 'contact_row_unavailable');
            return null;
        }

        $row = $this->getModuleRow($module_row_id);
        if (empty($row)) {
            $this->setWhoisContactError('module_row_id', 'contact_row_unavailable');
            return null;
        }

        $response = $this->buildDomainsApi($row)->info($domain);
        if (!$response->success() || !is_array($response->data())) {
            $this->setWhoisContactError('contacts', 'contact_unavailable');
            return null;
        }

        return [
            'domain' => $domain,
            'module_row_id' => $module_row_id,
            'row' => $row,
            'info' => $response->data(),
        ];
    }

    /**
     * Builds a WHOIS/contact context from a cached domainInfoSnapshot().
     *
     * @param array $snapshot Cached domain info snapshot
     * @return array|null WHOIS contact context
     */
    private function whoisContactContextFromSnapshot(array $snapshot)
    {
        if (empty($snapshot['ok']) || !is_array($snapshot['data'] ?? null)) {
            return null;
        }

        $module_row_id = (int) ($snapshot['module_row_id'] ?? 0);
        if ($module_row_id <= 0) {
            return null;
        }

        $row = $this->getModuleRow($module_row_id);
        if (empty($row)) {
            return null;
        }

        return [
            'domain' => (string) ($snapshot['domain'] ?? ''),
            'module_row_id' => $module_row_id,
            'row' => $row,
            'info' => $snapshot['data'],
        ];
    }

    /**
     * Reads role contacts from an already validated row/domain context.
     *
     * @param array $context WHOIS contact context
     * @return array role => normalized contact
     */
    private function getWhoisContactsFromContext(array $context): array
    {
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_whois_contacts.php');
        $ids = \Webnic\WhoisContacts::contactIdsFromInfo($context['info']);
        $contacts_api = $this->buildContactsApi($context['row']);
        $queried = [];

        foreach (array_unique(array_filter($ids)) as $contact_id) {
            $response = $contacts_api->queryContact($contact_id);
            $queried[$contact_id] = $response->success() && is_array($response->data())
                ? $response->data()
                : null;
        }

        $contacts = [];
        foreach (\Webnic\WhoisContacts::ROLES as $role) {
            $contact_id = $ids[$role] ?? null;
            if ($contact_id === null || !isset($queried[$contact_id]) || $queried[$contact_id] === null) {
                $contacts[$role] = $this->unavailableWhoisContact($role, $contact_id, 'contact_unavailable');
                continue;
            }

            $contacts[$role] = \Webnic\WhoisContacts::normalizeProviderContact(
                $role,
                $contact_id,
                $queried[$contact_id]
            );
        }

        return $this->annotateSharedWhoisContacts($contacts);
    }

    /**
     * Marks contacts that share the same WebNIC handle across role sections.
     *
     * @param array $contacts role => normalized contact
     * @return array Annotated contacts
     */
    private function annotateSharedWhoisContacts(array $contacts): array
    {
        $counts = [];
        foreach ($contacts as $contact) {
            $id = trim((string) ($contact['external_id'] ?? ''));
            if ($id !== '') {
                $counts[$id] = ($counts[$id] ?? 0) + 1;
            }
        }

        foreach ($contacts as $role => $contact) {
            $id = trim((string) ($contact['external_id'] ?? ''));
            if ($id !== '' && ($counts[$id] ?? 0) > 1) {
                $contacts[$role]['shared_handle'] = true;
                $contacts[$role]['shared_count'] = $counts[$id];
            }
        }

        return $contacts;
    }

    /**
     * Returns whether a fresh info() context permits WHOIS contact edits.
     *
     * @param array $context WHOIS contact context
     * @return bool
     */
    private function whoisContactContextIsManageable(array $context): bool
    {
        $info = $context['info'] ?? [];
        $status = is_array($info) ? (string) ($info['status'] ?? '') : '';
        $decision = $this->classifyDeletionWindowStatus($status);
        if (empty($decision['allowed']) || self::isRegistryTrue($info['suspended'] ?? false)) {
            $this->setWhoisContactError('contacts', 'contact_not_manageable');
            return false;
        }

        return true;
    }

    /**
     * Normalizes and validates submitted contacts through Blesta Input.
     *
     * @param array $vars Submitted values
     * @param array $attached_ids role => current contact id
     * @return array|null role => normalized submitted contact, or null on validation failure
     */
    private function normalizeWhoisContactSubmission(array $vars, array $attached_ids)
    {
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_whois_contacts.php');
        $require_editable_marker = !empty($vars['_webnic_whois_require_editable']);
        $raw_contacts = isset($vars['contacts']) && is_array($vars['contacts']) ? $vars['contacts'] : $vars;
        $submitted = [];
        $flat = [];
        $rules = [];
        $errors = [];

        foreach ($raw_contacts as $role => $contact) {
            $role = (string) $role;
            if (!\Webnic\WhoisContacts::validRole($role) || !is_array($contact)) {
                $errors['contacts'][$role] = Language::_('Webnic.!error.contact_role_invalid', true);
                continue;
            }
            if ($require_editable_marker && empty($contact['editable'])) {
                continue;
            }

            $submitted[$role] = [
                'external_id' => trim((string)($contact['external_id'] ?? '')),
                'first_name' => trim((string)($contact['first_name'] ?? '')),
                'last_name' => trim((string)($contact['last_name'] ?? '')),
                'organization' => trim((string)($contact['organization'] ?? '')),
                'address1' => trim((string)($contact['address1'] ?? '')),
                'address2' => trim((string)($contact['address2'] ?? '')),
                'city' => trim((string)($contact['city'] ?? '')),
                'state' => trim((string)($contact['state'] ?? '')),
                'zip' => trim((string)($contact['zip'] ?? '')),
                'country' => strtoupper(trim((string)($contact['country'] ?? ''))),
                'phone' => trim((string)($contact['phone'] ?? '')),
                'fax' => trim((string)($contact['fax'] ?? '')),
                'email' => trim((string)($contact['email'] ?? '')),
            ];
            $submitted[$role]['_submitted_fields'] = array_values(array_intersect(
                array_keys($contact),
                ['first_name', 'last_name', 'organization', 'address1', 'address2', 'city', 'state', 'zip', 'country', 'phone', 'fax', 'email']
            ));

            $base = 'contacts.' . $role . '.';
            foreach ($submitted[$role] as $field => $value) {
                if ($field === '_submitted_fields') {
                    continue;
                }
                $flat[$base . $field] = $value;
            }
            $rules[$base . 'external_id'] = [
                'attached' => [
                    'rule' => [[$this, 'validateWhoisContactAttachedId'], $attached_ids[$role] ?? null],
                    'message' => Language::_('Webnic.!error.contact_id_mismatch', true),
                ],
            ];
            foreach (['first_name', 'last_name', 'address1', 'city', 'state', 'zip'] as $field) {
                $rules[$base . $field] = [
                    'required' => [
                        'rule' => [[$this, 'validateWhoisContactRequired']],
                        'message' => Language::_('Webnic.!error.contact_required', true),
                    ],
                ];
            }
            $rules[$base . 'email'] = [
                'required' => [
                    'rule' => [[$this, 'validateWhoisContactRequired']],
                    'message' => Language::_('Webnic.!error.contact_required', true),
                    'last' => true,
                ],
                'format' => [
                    'rule' => [[$this, 'validateWhoisContactEmail']],
                    'message' => Language::_('Webnic.!error.contact_email_invalid', true),
                ],
            ];
            $rules[$base . 'phone'] = [
                'required' => [
                    'rule' => [[$this, 'validateWhoisContactRequired']],
                    'message' => Language::_('Webnic.!error.contact_required', true),
                    'last' => true,
                ],
                'format' => [
                    'rule' => [[$this, 'validateWhoisContactPhone']],
                    'message' => Language::_('Webnic.!error.contact_phone_invalid', true),
                ],
            ];
            $rules[$base . 'country'] = [
                'required' => [
                    'rule' => [[$this, 'validateWhoisContactRequired']],
                    'message' => Language::_('Webnic.!error.contact_required', true),
                    'last' => true,
                ],
                'format' => [
                    'rule' => [[$this, 'validateWhoisContactCountry']],
                    'message' => Language::_('Webnic.!error.contact_country_invalid', true),
                ],
            ];
        }

        if (empty($submitted) && empty($errors)) {
            if ($require_editable_marker) {
                return [];
            }
            $errors['contacts']['required'] = Language::_('Webnic.!error.contact_required', true);
        }
        if (!empty($errors)) {
            $this->Input->setErrors($errors);
            return null;
        }

        $this->Input->setRules($rules);
        if (!$this->Input->validates($flat)) {
            return null;
        }

        return $submitted;
    }

    /**
     * Builds an explicit unavailable role contact.
     *
     * @param string $role Contact role
     * @param string|null $external_id Provider contact id, if known
     * @param string $error_key Webnic.!error.* suffix
     * @return array
     */
    private function unavailableWhoisContact(string $role, $external_id, string $error_key): array
    {
        return [
            'role' => $role,
            'external_id' => $external_id,
            'first_name' => '',
            'last_name' => '',
            'organization' => '',
            'address1' => '',
            'address2' => null,
            'city' => '',
            'state' => '',
            'zip' => '',
            'country' => '',
            'email' => '',
            'phone' => '',
            'fax' => null,
            'editable' => false,
            'unavailable' => true,
            'error_key' => $error_key,
            'error' => Language::_('Webnic.!error.' . $error_key, true),
            'provider_metadata' => [],
        ];
    }

    /**
     * Sets a localized WHOIS/contact Input error.
     *
     * @param string $field Input field
     * @param string $key Webnic.!error.* suffix
     */
    private function setWhoisContactError(string $field, string $key): void
    {
        if (isset($this->Input)) {
            $this->Input->setErrors([$field => [$key => Language::_('Webnic.!error.' . $key, true)]]);
        }
    }

    /**
     * Flattens current Input errors for field-level WHOIS tab rendering.
     *
     * @return array field => string[]
     */
    private function whoisContactErrorsForView(): array
    {
        $errors = $this->errors();
        if (!is_array($errors)) {
            return [];
        }

        $flat = [];
        foreach ($errors as $field => $messages) {
            $field = (string) $field;
            $bucket = preg_match('/^contacts\.[^.]+\.[^.]+$/', $field) ? $field : '_general';
            foreach ($this->flattenWhoisContactMessages($messages) as $message) {
                $flat[$bucket][] = $message;
            }
        }

        return $flat;
    }

    /**
     * Extracts string messages from nested Input error arrays.
     *
     * @param mixed $messages Error value
     * @return array<int,string>
     */
    private function flattenWhoisContactMessages($messages): array
    {
        if (is_string($messages)) {
            $message = trim($messages);
            return $message === '' ? [] : [$message];
        }
        if (!is_array($messages)) {
            return [];
        }

        $flat = [];
        foreach ($messages as $message) {
            foreach ($this->flattenWhoisContactMessages($message) as $item) {
                $flat[] = $item;
            }
        }

        return $flat;
    }

    /**
     * Logs safe WHOIS/contact exceptions without provider PII payloads.
     *
     * @param string $command Command label
     * @param string $domain Domain
     * @param int $module_row_id Module row id
     * @param \Throwable $e Exception
     */
    private function logWhoisContactException(string $command, string $domain, int $module_row_id, \Throwable $e): void
    {
        try {
            $record = [
                'command' => $command,
                'module_row_id' => $module_row_id,
                'domain' => $domain,
                'message' => get_class($e) . ': ' . $e->getMessage(),
            ];
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $this->log('webnic/whois_contacts', serialize($scrubbed), 'output', false);
        } catch (\Throwable $ignored) {
            // Logging must never interrupt service management.
        }
    }

    /**
     * Builds the WebnicPricing command group for a module row.
     *
     * @param stdClass $row The module row (decrypted meta)
     * @return \WebnicPricing The pricing/rules command bound to this row's credentials
     */
    private function buildPricingApi($row)
    {
        $this->loadReconcilerDependencies();
        Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_pricing.php');

        return new \WebnicPricing($this->buildWebnicApi($row));
    }

    /**
     * Builds a row-scoped WebNIC API handle.
     *
     * @param stdClass $row The module row (decrypted meta)
     * @return \WebnicApi The API client bound to this row's credentials
     */
    protected function buildWebnicApi($row)
    {
        $this->loadReconcilerDependencies();

        return new \WebnicApi(
            $row->id,
            $row->meta->username,
            $row->meta->secret,
            $row->meta->environment,
            new \Webnic\TokenStore()
        );
    }

    /**
     * Writes a scrubbed INV-9 recovery record to the Blesta module log (AC5/AC6/INV-9).
     *
     * Mirrors logReconcile(): the Redactor scrubs secrets (webnic_order_id, token) and
     * the success flag follows the structured level (only `error` logs as failure). The
     * `webnic/recover` group keeps recovery events distinguishable while staying
     * correlated to the order by `domain` (the trace reads across groups by-domain).
     *
     * @param array $record The structured recovery record
     */
    private function logRecover(array $record): void
    {
        try {
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $success = ($record['level'] ?? 'info') !== 'error';
            $this->log('webnic/recover', serialize($scrubbed), 'output', $success);
        } catch (\Throwable $e) {
            // Logging must never interrupt a recovery action.
        }
    }

    /**
     * Resends the WebNIC domain verification email — the canonical RegistrarModule hook (FR22/AC3).
     *
     * Overrides RegistrarModule::resendTransferEmail (registrar_module.php:443; base returns the
     * `unsupported` common error + false). The base hook has ZERO core callers, so the module
     * surfaces it via the resend service tab (Dev Notes §B); mirrors internetbs.php:2764. This is a
     * STATELESS side-action: it opens no webnic_orders row, runs no transition(), and a transient or
     * error response NEVER advances or terminates the order — the cron reconciler stays the only
     * writer that can flip the state (NFR5). INV-1: the op runs through the SERVICE's own module row.
     *
     * Returns true on success OR the idempotent-benign already-verified case; false + Input errors
     * on a definitive/transient failure (the base bool contract). The finer 'ok' vs benign 'already'
     * distinction (for the tab's inline copy) is stashed in $last_resend_outcome — a bool can't carry
     * it and both are non-error.
     *
     * @param string $domain The domain to resend verification for
     * @param int|null $module_row_id The service's module row (INV-1 scope)
     * @return bool True on success/already-verified, false on failure
     */
    public function resendTransferEmail($domain, $module_row_id = null)
    {
        $decision = $this->performResend((string) $domain, $module_row_id);
        $this->last_resend_outcome = $decision['outcome'];

        if ($decision['outcome'] === 'failed') {
            if (isset($this->Input)) {
                $this->Input->setErrors([
                    'webnic_resend' => [
                        $decision['error_key'] => Language::_('Webnic.!error.' . $decision['error_key'], true),
                    ],
                ]);
            }

            return false;
        }

        return true;
    }

    /**
     * Runs a resend attempt end-to-end and returns its classified outcome (WN-4-2/AC6).
     *
     * Resolves the row (INV-1), builds the row-scoped transfers API, calls the resend op,
     * classifies via the pure decideResendVerification, and emits exactly ONE INV-9 observability
     * record (no from_state/to_state — resend never transitions). Never throws: a missing row, or a
     * thrown build/transport, is a benign 'failed' outcome, never a fatal — and the free-text
     * exception sink emits get_class($e), never the raw message (§G/INV-7).
     *
     * @param string $domain The domain to resend verification for
     * @param int|null $module_row_id The service's module row (INV-1 scope)
     * @return array ['outcome' => 'ok'|'already'|'failed', 'error_key' => string|null]
     */
    private function performResend(string $domain, $module_row_id): array
    {
        Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');

        try {
            $row = $this->getModuleRow($module_row_id);
        } catch (\Throwable $e) {
            // A THROWN row resolution (decrypt/DB/infra failure) is indeterminate, not terminal:
            // surface the soft "try again", never a hard "contact support" (NFR5/round-1 P2). Emit
            // get_class($e), not the raw message (§G/AC6), and stamp -1 (not 0) so the unresolved row
            // is never confused with a real row id.
            $this->logResend(
                $this->resendRecord('resend_verification', -1, $domain, 'failed', 'indeterminate', get_class($e))
            );

            return ['outcome' => 'failed', 'error_key' => 'resend_unavailable'];
        }

        if (empty($row)) {
            // No row resolved (missing/unknown id) — terminal. -1 marks the unresolved row id so the
            // INV-9 record is not confused with a real row 0 (round-1 P2).
            $this->logResend(
                $this->resendRecord('resend_verification', -1, $domain, 'failed', 'terminal', 'resend_row_unavailable')
            );

            return ['outcome' => 'failed', 'error_key' => 'resend_row_unavailable'];
        }

        try {
            $response = $this->buildTransfersApi($row)->resendVerificationEmail($domain);
            $decision = \WebnicTransfers::decideResendVerification($response);
        } catch (\Throwable $e) {
            // An unexpected build/transport throw is indeterminate — surface "try again", never a
            // failed STATE (NFR5). Emit the class, not the message (§G).
            $this->logResend(
                $this->resendRecord('resend_verification', (int) $row->id, $domain, 'failed', 'indeterminate', get_class($e))
            );

            return ['outcome' => 'failed', 'error_key' => 'resend_unavailable'];
        }

        $this->logResend($this->resendRecord(
            'resend_verification',
            (int) $row->id,
            $domain,
            $decision['outcome'],
            $decision['error_class'] ?? null,
            $decision['outcome'] === 'failed' ? ($decision['error_key'] ?? 'resend_failed') : $decision['outcome']
        ));

        return [
            'outcome' => $decision['outcome'],
            'error_key' => $decision['error_key'] ?? null,
        ];
    }

    /**
     * Builds the WebnicTransfers command bound to a module row's credentials (INV-1).
     *
     * Mirrors buildDomainsApi. loadReconcilerDependencies() already loads webnic_transfers.php.
     *
     * @param stdClass $row The module row (decrypted meta)
     * @return \WebnicTransfers The transfer/resend command bound to this row's credentials
     */
    private function buildTransfersApi($row)
    {
        $this->loadReconcilerDependencies();

        return new \WebnicTransfers($this->buildWebnicApi($row));
    }

    /**
     * Writes a scrubbed INV-9 resend record to the Blesta module log (AC6).
     *
     * Mirrors logRecover(): the Redactor scrubs any secret (order/transfer id, token) and the
     * success flag follows the structured outcome (`ok` only). The `webnic/resend` group keeps
     * resend events distinguishable while staying correlated by `domain`. Never throws.
     *
     * @param array $record The structured resend record
     */
    private function logResend(array $record): void
    {
        try {
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $success = ($record['outcome'] ?? '') === 'ok';
            $this->log('webnic/resend', serialize($scrubbed), 'output', $success);
        } catch (\Throwable $e) {
            // Logging must never interrupt a resend action.
        }
    }

    /**
     * Builds a structured INV-9 resend record (AC6). NO from_state/to_state — resend never
     * transitions (NFR5). Level follows error_class so a benign success logs `info`, a terminal
     * failure `error`, a transient `notice`.
     *
     * @param string $command The command tag (resend_verification)
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param string $domain The normalized domain
     * @param string $outcome ok|already|failed
     * @param string|null $error_class terminal|retryable|indeterminate|null
     * @param string $message A safe descriptor (key/outcome, or get_class($e) — never raw provider text)
     * @return array The structured record
     */
    private function resendRecord(string $command, int $module_row_id, string $domain, string $outcome, ?string $error_class, string $message): array
    {
        $level = $error_class === 'terminal'
            ? 'error'
            : (in_array($error_class, ['retryable', 'indeterminate'], true) ? 'notice' : 'info');

        return [
            'run_id' => uniqid('wnres_', true),
            'level' => $level,
            'module_row_id' => $module_row_id,
            'service_id' => self::REGISTER_INTENT_SERVICE_ID,
            'domain' => $domain,
            'command' => $command,
            'outcome' => $outcome,
            'error_class' => $error_class,
            'message' => $message,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Renew a domain (WN-4-3 / FR23). A SYNCHRONOUS, STATELESS lifecycle side-action
    // — NOT a saga, NOT a state change: renew opens NO webnic_orders row, runs NO
    // transition(), and a transient/error response NEVER advances or terminates an
    // order (NFR5 — the reconciler stays the sole state authority). Mirrors the
    // WN-4-2/WN-3-5 sync-op playbook, not the Epic-3 saga.
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Renews a paid WebNIC domain service for its pricing term — the core-called hook (FR23/AC1).
     *
     * Overrides Module::renewService (module.php:589; base returns null). Blesta's billing engine calls
     * this from Services::renew() (services.php:4083) AFTER a renewal invoice is PAID, so the customer/
     * admin renewal UI (term select, invoice) is Blesta's — the module ships NO new view/tab (the WN-4-2
     * contrast). The flow: (AC4/FR39d) block unless the WebNIC order is active; derive the term from
     * $service->pricing_id; submit the captured Renew op through the service's OWN row (INV-1); on success
     * return null to MAINTAIN the existing meta (both bundled registrars return null from renewService);
     * on failure set an Input error (no throw) so core records the attempt and re-tries next pass
     * (services.php:4088-4095). Renew is idempotent/payment-safe via the AC5 expiry-extension guard, NOT
     * an intent/CAS row (§F).
     *
     * @param stdClass $package The service's package (carries ->pricing for the term)
     * @param stdClass $service The service being renewed
     * @param stdClass|null $parent_package Unused (no addon-domain semantics)
     * @param stdClass|null $parent_service Unused
     * @return null Always null — maintain the existing service meta (AC1)
     */
    public function renewService($package, $service, $parent_package = null, $parent_service = null)
    {
        // AC4/FR39d: block renewal unless the WebNIC order is active. fail-open on a missing order
        // (legacy import / completed transfer with no surviving intent row); a thrown gate read is
        // indeterminate -> block-and-retry, never a terminal failure (NFR5). No registry call on block.
        $gate = $this->renewableState($service);
        if (!$gate['allowed']) {
            $this->setRenewError($this->renewBlockKey($gate['reason']));
            // INV-9 (round-2 P3): a gate-blocked attempt is still a renew attempt — emit one audit
            // record (no registry call) so operators keep the trail for exactly the states most likely
            // to need support context.
            $this->logBlockedRenew($service, $gate['reason']);

            return null;
        }

        // Round-2 P2: resolve domain/term/row INSIDE the exception boundary. A thrown DB/decrypt read
        // here would otherwise bubble into Blesta's billing hook, which records the RAW $e->getMessage()
        // (services.php:4082-4085) — violating §J's clean-Input-error contract and AC7's get_class()
        // redaction. On a throw, emit a scrubbed get_class($e) record and a soft "try again", never raw text.
        try {
            $this->loadReconcilerDependencies();
            $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
            $term = $this->resolveRenewTerm($package, $service);
            // INV-1 fail-closed (round-2 P4): a falsy module_row_id must NOT silently fall back to the
            // module's DEFAULT row (getModuleRow(null) returns it) and renew against another account's
            // creds. An empty id => no row => performRenew's empty($row) guard => renew_row_unavailable.
            $row_id = $service->module_row_id ?? null;
            $row = empty($row_id) ? null : $this->getModuleRow($row_id);
        } catch (\Throwable $e) {
            $this->logRenew($this->renewRecord('renew', -1, '', 0, 'failed', 'indeterminate', get_class($e), $this->renewServiceId($service)));
            $this->setRenewError('renew_unavailable');

            return null;
        }

        $decision = $this->performRenew($domain, $term, $row, $service);

        if ($decision['outcome'] === 'failed') {
            $this->setRenewError($decision['error_key'] ?: 'renew_failed');

            return null;
        }

        // ok (including the AC5 "already extended" skip) -> maintain the existing service meta.
        return null;
    }

    /**
     * Renews a domain by name — the domain-scoped RegistrarModule capability sibling (AC7).
     *
     * Overrides RegistrarModule::renewDomain (registrar_module.php:498; base returns unsupported+false,
     * ZERO core callers). Implemented so the registrar advertises the capability and an admin/API caller
     * can renew by domain. It shares the same performRenew() core as renewService but takes the term from
     * $vars and applies NO AC4 order-gate (a direct registry capability, not the billing path) — it still
     * honors AC5's expiry-extension guard and AC3's no-auto-renew body. renewService is the keystone path.
     *
     * @param string $domain The domain to renew
     * @param int|null $module_row_id The module row to use (INV-1 scope)
     * @param array $vars Renew vars (years|term, default 1)
     * @return bool True on success, false + Input errors on failure (the base bool contract)
     */
    public function renewDomain($domain, $module_row_id = null, array $vars = [])
    {
        // Round-2 P2: resolve INSIDE the exception boundary (a thrown row-resolution otherwise escapes
        // the capability caller). A null $module_row_id resolving to the module's DEFAULT row is the
        // documented capability convention here (NOT the billing path's INV-1 fail-closed rule) — an
        // admin/API caller may omit the row on a single-row install.
        try {
            $this->loadReconcilerDependencies();
            $normalized = \Webnic\Orders::normalizeDomain((string) $domain);
            $term = $this->renewVarsTerm($vars);
            $row = $this->getModuleRow($module_row_id);
        } catch (\Throwable $e) {
            $this->logRenew($this->renewRecord('renew', -1, '', 0, 'failed', 'indeterminate', get_class($e), self::REGISTER_INTENT_SERVICE_ID));
            $this->setRenewError('renew_unavailable');

            return false;
        }

        $decision = $this->performRenew($normalized, $term, $row, null);

        if ($decision['outcome'] === 'failed') {
            $this->setRenewError($decision['error_key'] ?: 'renew_failed');

            return false;
        }

        return true;
    }

    /**
     * Runs a renew attempt end-to-end and returns its classified outcome (AC1/AC5/AC7).
     *
     * The single core shared by renewService (with $service) and renewDomain (without). Builds the
     * row-scoped domains API (INV-1), applies the AC5 expiry-extension guard (skip => benign no-op),
     * submits the captured Renew op, classifies via the pure decideRenew, refreshes the cached expiry
     * (AC2, service path only), and emits exactly ONE INV-9 record (no from_state/to_state — renew never
     * transitions, NFR5). NEVER throws: a missing row, or a thrown build/transport, is a benign 'failed'
     * outcome, and the free-text exception sink emits get_class($e), never the raw message (§F/INV-7).
     *
     * @param string $domain The normalized domain to renew
     * @param int $term The renewal term in years
     * @param stdClass|null $row The provisioning module row (INV-1)
     * @param stdClass|null $service The service (renewService path) or null (renewDomain path)
     * @return array ['outcome' => 'ok'|'failed', 'error_key' => string|null, 'skipped' => bool]
     */
    private function performRenew(string $domain, int $term, $row, $service = null): array
    {
        Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');

        // Round-2 P1: fail closed on an empty/unresolvable domain — never submit a billable
        // renew('', term) that the registry only rejects (DOM4000). The gate fail-opens on an empty
        // domain (resolveOrderByDomain('') => null), so this is the guard that stops the wasted egress.
        if (trim($domain) === '') {
            $this->logRenew($this->renewRecord('renew', -1, $domain, $term, 'failed', 'terminal', 'renew_domain_missing', $this->renewServiceId($service)));

            return ['outcome' => 'failed', 'error_key' => 'renew_domain_missing', 'skipped' => false];
        }

        if (empty($row)) {
            // No row resolved (missing/unknown id) — terminal. -1 marks the unresolved row id so the
            // INV-9 record is not confused with a real row 0 (the WN-4-2 round-1 P2 precedent).
            $this->logRenew($this->renewRecord('renew', -1, $domain, $term, 'failed', 'terminal', 'renew_row_unavailable', $this->renewServiceId($service)));

            return ['outcome' => 'failed', 'error_key' => 'renew_row_unavailable'];
        }

        $module_row_id = (int) $row->id;
        $service_id = $this->renewServiceId($service);

        try {
            $domainsApi = $this->buildDomainsApi($row);
        } catch (\Throwable $e) {
            // A thrown build (decrypt/DB/infra) is indeterminate -> soft "try again", never failed STATE
            // (NFR5). Emit the class, not the message (§F/INV-7).
            $this->logRenew($this->renewRecord('renew', $module_row_id, $domain, $term, 'failed', 'indeterminate', get_class($e), $service_id));

            return ['outcome' => 'failed', 'error_key' => 'renew_unavailable'];
        }

        // AC5 payment-safety / idempotency (§F): honor a pending ServiceChanges date_renews so a manual
        // multi-year renewal is not truncated, then submit ONLY if +term years actually extends past the
        // current registry expiry. A retry after a successful renew reads the already-advanced live expiry
        // and SKIPS — no second WebNIC order, no second registry charge (FR18). An unreadable current
        // expiry does NOT skip (better a benign re-extend WebNIC itself dedups than a wrongly-skipped paid
        // renewal). This is renew's idempotency mechanism — NOT an intent row / CAS guard.
        $term = $this->bumpTermForPendingRenewal($term, $service);
        $current_expiry = $this->currentRegistryExpiry($domain, $domainsApi);
        // Round-2 (decision #3): compute the target in UTC with calendar-correct year math (DateInterval,
        // leap-safe) so it is comparable against the UTC registry expiry — never raw strtotime('+N years')
        // in the server-default tz against a mixed-zone expiry string.
        $target = $this->utcTimestampPlusYears($term);
        if ($current_expiry !== null && $target !== null && $target <= $current_expiry) {
            $this->logRenew($this->renewRecord('renew', $module_row_id, $domain, $term, 'ok', null, 'renew_skipped_already_extended', $service_id));

            return ['outcome' => 'ok', 'error_key' => null, 'skipped' => true];
        }

        try {
            $response = $domainsApi->renew($domain, $term);
            $decision = \WebnicDomains::decideRenew($response);
        } catch (\Throwable $e) {
            // An unexpected transport throw is indeterminate -> "try again", never a failed STATE (NFR5).
            $this->logRenew($this->renewRecord('renew', $module_row_id, $domain, $term, 'failed', 'indeterminate', get_class($e), $service_id));

            return ['outcome' => 'failed', 'error_key' => 'renew_unavailable'];
        }

        // AC2 immediacy cache: on a real success, refresh the local expiration_date from the renew
        // response (service path only — by-domain renewDomain has no service field to write). Best-effort;
        // the live read-through getExpirationDate stays authoritative.
        if ($decision['outcome'] === 'ok' && $service !== null) {
            $this->refreshServiceExpiration($service, $response);
        }

        $this->logRenew($this->renewRecord(
            'renew',
            $module_row_id,
            $domain,
            $term,
            $decision['outcome'],
            $decision['error_class'] ?? null,
            $decision['outcome'] === 'failed' ? ($decision['error_key'] ?? 'renew_failed') : $decision['outcome'],
            $service_id
        ));

        return [
            'outcome' => $decision['outcome'],
            'error_key' => $decision['error_key'] ?? null,
        ];
    }

    /**
     * Decides whether a renewal is allowed by the order's lifecycle state (AC4/FR39d/§G).
     *
     * Gates via resolveOrderByDomain (the by-domain/sentinel-service_id=0 + preferOrderRow
     * state-preference): null => allow (a legacy import / completed transfer with no surviving intent row
     * must still renew — never block on the ABSENCE of an order); STATE_ACTIVE => allow; any other KNOWN
     * state => block with the matching reason; a thrown read => indeterminate (block-and-retry, never a
     * terminal failure — NFR5). The same fail-open posture as WN-3-5's capability gate. Adds NO new state.
     *
     * @param stdClass $service The service being renewed
     * @return array ['allowed' => bool, 'reason' => 'pending'|'failed'|'cancelled'|'indeterminate'|null]
     */
    private function renewableState($service): array
    {
        $this->loadReconcilerDependencies();

        try {
            $order = $this->resolveOrderByDomain($service);
        } catch (\Throwable $e) {
            if (isset($this->Record)) {
                $this->Record->reset();
            }

            return ['allowed' => false, 'reason' => 'indeterminate'];
        }

        if ($order === null) {
            return ['allowed' => true, 'reason' => null];
        }

        $state = is_object($order) ? ($order->state ?? null) : null;
        if ($state === \Webnic\Orders::STATE_ACTIVE) {
            return ['allowed' => true, 'reason' => null];
        }
        if ($state === \Webnic\Orders::STATE_FAILED) {
            return ['allowed' => false, 'reason' => 'failed'];
        }
        if ($state === \Webnic\Orders::STATE_CANCELLED) {
            return ['allowed' => false, 'reason' => 'cancelled'];
        }

        // Any other KNOWN state is the pending family (intent/contacts_done/registrant_done/hosts_done/
        // submitted/registrar_pending): block-and-retry until active (FR39d — block, never queue).
        return ['allowed' => false, 'reason' => 'pending'];
    }

    /**
     * Maps a renew block reason to its localized Input error key (AC4).
     *
     * @param string|null $reason pending|failed|cancelled|indeterminate
     * @return string The Webnic.!error.* key suffix
     */
    private function renewBlockKey($reason): string
    {
        switch ($reason) {
            case 'pending':
                return 'renew_blocked_pending';
            case 'failed':
            case 'cancelled':
                return 'renew_blocked_terminal';
            default:
                // indeterminate (thrown gate read) -> the soft retryable copy (NFR5).
                return 'renew_unavailable';
        }
    }

    /**
     * Sets a single localized renew Input error (no throw; the billing-flow contract, §J).
     *
     * @param string $key The Webnic.!error.* key suffix
     */
    private function setRenewError(string $key): void
    {
        if (isset($this->Input)) {
            $this->Input->setErrors(['webnic_renew' => [$key => Language::_('Webnic.!error.' . $key, true)]]);
        }
    }

    /**
     * Emits the INV-9 audit record for a gate-blocked renew attempt (round-2 P3/AC7/T6).
     *
     * A blocked attempt makes NO registry call but is still a renew attempt, so it gets one scrubbed
     * `webnic/renew` record (outcome `blocked`, never a from/to_state — renew never transitions). The
     * level follows the reason: failed/cancelled (terminal) -> error; pending (soft block, core auto-
     * retries) and indeterminate -> notice. Domain is best-effort (a thrown read leaves it empty); the
     * message is the block-reason key, never raw text. Never throws.
     *
     * @param stdClass $service The service whose renewal was blocked
     * @param string|null $reason pending|failed|cancelled|indeterminate
     */
    private function logBlockedRenew($service, $reason): void
    {
        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');

            $domain = '';
            try {
                $this->loadReconcilerDependencies();
                $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
            } catch (\Throwable $e) {
                $domain = '';
            }

            $error_class = in_array($reason, ['failed', 'cancelled'], true)
                ? 'terminal'
                : ($reason === 'indeterminate' ? 'indeterminate' : 'retryable');
            $module_row_id = empty($service->module_row_id) ? -1 : (int) $service->module_row_id;

            $this->logRenew($this->renewRecord(
                'renew',
                $module_row_id,
                $domain,
                0,
                'blocked',
                $error_class,
                $this->renewBlockKey($reason),
                $this->renewServiceId($service)
            ));
        } catch (\Throwable $e) {
            // Audit logging must never interrupt the billing flow.
        }
    }

    /**
     * Derives the renewal term in years from the service's pricing (FR23/§D).
     *
     * resolveTerm() reads $vars['pricing_id'] (the PROVISIONING input renew lacks), so this matches
     * $service->pricing_id against $package->pricing instead (the internetbs.php:803-808 loop). Domains
     * use the `year` period, so term is the year count; default 1.
     *
     * @param stdClass $package The service's package
     * @param stdClass $service The service being renewed
     * @return int The renewal term in years (>= 1)
     */
    private function resolveRenewTerm($package, $service): int
    {
        if (isset($package->pricing) && is_array($package->pricing) && isset($service->pricing_id)) {
            foreach ($package->pricing as $pricing) {
                if ($pricing->id == $service->pricing_id) {
                    return max(1, (int) $pricing->term);
                }
            }
        }

        return 1;
    }

    /**
     * Derives the renew term for the by-domain capability path from $vars (AC7/§D).
     *
     * @param array $vars The renew vars
     * @return int The renewal term in years (>= 1)
     */
    private function renewVarsTerm(array $vars): int
    {
        // Round-2 P7: validate as a strict integer before casting. is_numeric('1e3') is true but
        // (int)'1e3' is 1, so an admin/API caller passing years=1e3 would silently renew for 1 year.
        // FILTER_VALIDATE_INT rejects scientific notation / floats / junk -> fall through to the default.
        foreach (['years', 'term'] as $key) {
            if (isset($vars[$key])) {
                $n = filter_var($vars[$key], FILTER_VALIDATE_INT);
                if ($n !== false) {
                    return max(1, (int) $n);
                }
            }
        }

        return 1;
    }

    /**
     * Honors a pending ServiceChanges date_renews so a manual multi-year renewal is not truncated (AC5).
     *
     * Mirrors internetbs.php:810-827: if a pending service change carries a later date_renews than the
     * pricing term implies, bump the submitted term UP so the customer is never under-renewed below what
     * they paid. Service path only (renewDomain has no $service/ServiceChanges). Best-effort, never throws.
     *
     * @param int $term The pricing-derived term in years
     * @param stdClass|null $service The service being renewed (or null on the by-domain path)
     * @return int The effective term in years (>= the pricing term)
     */
    private function bumpTermForPendingRenewal(int $term, $service): int
    {
        if ($service === null || !isset($service->id)) {
            return $term;
        }

        try {
            Loader::loadModels($this, ['ServiceChanges']);
            $next_renewal_date = null;
            $changes = $this->ServiceChanges->getAll('pending', (int) $service->id);
            if (is_array($changes)) {
                foreach ($changes as $change) {
                    if (isset($change->data->date_renews)) {
                        $next_renewal_date = $change->data->date_renews;
                        break;
                    }
                }
            }

            $reference = $next_renewal_date ?? ($service->date_renews ?? null);
            if ($reference === null) {
                return $term;
            }

            // Round-2 (decision #1): calendar-safe, leap-year-correct whole-year delta in UTC — replaces
            // round((reference - now)/86400)/365, which drifted on leap years and down-rounded an exact
            // N.5 into an UNDER-renewal. Compare in UTC so a non-UTC server tz cannot shift the boundary.
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $ref = $this->parseToUtcDateTime((string) $reference);
            if ($ref === null) {
                return $term;
            }
            // Bump UP only (never truncate below the pricing term the customer paid).
            return max($term, \WebnicDomains::roundYearsBetween($now, $ref));
        } catch (\Throwable $e) {
            // Best-effort; fall back to the pricing term.
        }

        return $term;
    }

    /**
     * Parses a provider/Blesta datetime string to a UTC DateTimeImmutable via the safe Reconciler::toUtc
     * (handles the WN-4-1 §L nanosecond-precision dtexpire overflow trap), or null when unparseable.
     *
     * @param string $raw The datetime string
     * @return \DateTimeImmutable|null The instant in UTC, or null
     */
    private function parseToUtcDateTime(string $raw): ?\DateTimeImmutable
    {
        if (trim($raw) === '') {
            return null;
        }

        $this->loadReconcilerDependencies();
        $utc = \Webnic\Reconciler::toUtc($raw);
        if ($utc === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($utc, new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Returns the UNIX timestamp of now + $years, computed in UTC with calendar-correct (leap-safe)
     * year arithmetic — the AC5 guard's target, comparable against the UTC registry expiry (decision #3).
     *
     * @param int $years The term in years
     * @return int|null The target UNIX timestamp, or null on failure
     */
    private function utcTimestampPlusYears(int $years)
    {
        try {
            return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->add(new \DateInterval('P' . max(0, $years) . 'Y'))
                ->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Reads the current registry expiry as a UTC UNIX timestamp for the AC5 guard (§F, decision #3).
     *
     * Round-2: both paths read the RAW registry info().dtexpire and parse it via Reconciler::toUtc, then
     * compare in UTC. The service path no longer routes through getExpirationDate($service) — that returns
     * a company-tz-FORMATTED string, which, parsed against a server/UTC target, double-applied the company
     * offset and could wrongly skip/resubmit a paid renewal at the year boundary; and raw toUtc also
     * neutralizes the nanosecond-precision dtexpire trap that raw strtotime returned false for. The
     * authoritative read-through getExpirationDate stays the Domain Manager's display source (AC2); this is
     * only the money-safety guard. A false/unreadable expiry returns null so the caller does NOT skip —
     * better a possible benign re-extend (WebNIC dedups) than a wrongly-skipped paid renewal.
     *
     * @param string $domain The normalized domain
     * @param \WebnicDomains $domainsApi The row-scoped domains API
     * @return int|null The current expiry as a UTC UNIX timestamp, or null when unreadable
     */
    private function currentRegistryExpiry(string $domain, $domainsApi)
    {
        try {
            $response = $domainsApi->info($domain);
            if (!$response->success()) {
                return null;
            }
            $data = $response->data();
            $raw = is_array($data) ? ($data['dtexpire'] ?? null) : null;
            if (!is_string($raw) || trim($raw) === '') {
                return null;
            }
            $parsed = $this->parseToUtcDateTime($raw);

            return $parsed === null ? null : $parsed->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Upserts the cached expiration_date service field from a successful renew response (AC2/T4).
     *
     * Mirrors Reconciler::writeServiceExpiration (:1110): parse the response dtexpire via the safe
     * Reconciler::toUtc() (NOT raw DateTime — the WN-4-1 §L nanosecond-precision overflow trap), then
     * select-then-update-or-insert on service_id+key. A missing/unparseable expiry is a non-fatal no-op
     * (never clobber a good cached value with false) — the read-through getExpirationDate stays
     * authoritative. The module does NOT write Blesta's date_renews (Blesta owns it, services.php:4035).
     *
     * @param stdClass $service The renewed service (carries ->id)
     * @param WebnicResponse $response The successful renew response
     */
    private function refreshServiceExpiration($service, $response): void
    {
        try {
            if (!isset($service->id)) {
                return;
            }

            $data = $response->data();
            $raw = is_array($data) ? ($data['dtexpire'] ?? null) : null;
            if (!is_string($raw) || trim($raw) === '') {
                return;
            }

            $this->loadReconcilerDependencies();
            $utc = \Webnic\Reconciler::toUtc($raw);
            if ($utc === null) {
                return;
            }

            if (!isset($this->Record)) {
                Loader::loadComponents($this, ['Record']);
            }

            // service_fields' PK is the composite (service_id, key) — there is NO `id` column — so the
            // upsert keys on that pair, not on a surrogate id (the Reconciler::writeServiceExpiration
            // pattern selects/updates by `id`, which throws "Unknown column 'id'" and is silently
            // swallowed there; harmless for its best-effort cache, but renew's AC2 needs the write to
            // land). select-then-update-or-insert on service_id+key.
            $existing = $this->Record->select(['value'])
                ->from('service_fields')
                ->where('service_id', '=', (int) $service->id)
                ->where('key', '=', 'expiration_date')
                ->fetch();
            $this->Record->reset();

            if ($existing) {
                // Round-2 P6: set serialized => 0 alongside the plain Y-m-d H:i:s string (matching the
                // INSERT) so a pre-existing serialized=1 flag can't make Blesta read the value back through
                // the unserialize path.
                $this->Record->where('service_id', '=', (int) $service->id)
                    ->where('key', '=', 'expiration_date')
                    ->update('service_fields', ['value' => $utc, 'serialized' => 0, 'encrypted' => 0]);
            } else {
                $this->Record->insert('service_fields', [
                    'service_id' => (int) $service->id,
                    'key' => 'expiration_date',
                    'value' => $utc,
                    'serialized' => 0,
                    'encrypted' => 0,
                ]);
            }
        } catch (\Throwable $e) {
            // Immediacy nicety only; the read-through getExpirationDate stays authoritative.
        } finally {
            if (isset($this->Record)) {
                $this->Record->reset();
            }
        }
    }

    /**
     * Returns the INV-9 service_id for a renew record: the real id when known, else the by-domain sentinel.
     *
     * @param stdClass|null $service The service (service path) or null (by-domain path)
     * @return int The service id, or REGISTER_INTENT_SERVICE_ID (0) when unknown
     */
    private function renewServiceId($service): int
    {
        return ($service !== null && isset($service->id)) ? (int) $service->id : self::REGISTER_INTENT_SERVICE_ID;
    }

    /**
     * Writes a scrubbed INV-9 renew record to the Blesta module log (AC6/AC7).
     *
     * Mirrors logResend(): the Redactor scrubs any secret, and the success flag follows the structured
     * outcome (`ok` only). The `webnic/renew` group keeps renew events distinguishable while staying
     * correlated by `domain`. Never throws.
     *
     * @param array $record The structured renew record
     */
    private function logRenew(array $record): void
    {
        try {
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $success = ($record['outcome'] ?? '') === 'ok';
            $this->log('webnic/renew', serialize($scrubbed), 'output', $success);
        } catch (\Throwable $e) {
            // Logging must never interrupt a renew action.
        }
    }

    /**
     * Builds a structured INV-9 renew record (AC6/AC7). NO from_state/to_state — renew never transitions
     * (NFR5). Level follows error_class so a benign success/skip logs `info`, a terminal failure `error`,
     * a transient `notice`.
     *
     * @param string $command The command tag (renew)
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param string $domain The normalized domain
     * @param int $term The renewal term in years
     * @param string $outcome ok|already|failed
     * @param string|null $error_class terminal|retryable|indeterminate|null
     * @param string $message A safe descriptor (key/outcome, or get_class($e) — never raw provider text)
     * @param int $service_id The real service id when known, else the sentinel (0)
     * @return array The structured record
     */
    private function renewRecord(string $command, int $module_row_id, string $domain, int $term, string $outcome, ?string $error_class, string $message, int $service_id): array
    {
        $level = $error_class === 'terminal'
            ? 'error'
            : (in_array($error_class, ['retryable', 'indeterminate'], true) ? 'notice' : 'info');

        return [
            'run_id' => uniqid('wnrnw_', true),
            'level' => $level,
            'module_row_id' => $module_row_id,
            'service_id' => $service_id,
            'domain' => $domain,
            'term' => $term,
            'command' => $command,
            'outcome' => $outcome,
            'error_class' => $error_class,
            'message' => $message,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Restore a domain (WN-4-4 / FR24). A SYNCHRONOUS, STATELESS registry
    // side-action: no order row, no saga, no transition(), no cron, no DDL.
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Restores a WebNIC domain from grace/redemption through the exact RegistrarModule hook.
     *
     * A falsy row id fails closed for this billable action: unlike generic by-domain lookups, restore
     * never falls back to the default module row. The shared performRestore() core owns live status,
     * GRACE rule, restore price, provider submit, and audit logging.
     *
     * @param string $domain The domain to restore
     * @param int|null $module_row_id The module row to use (INV-1 scope)
     * @param array $vars Restore vars
     * @return bool True on accepted restore, false with Input errors on refusal/failure
     */
    public function restoreDomain($domain, $module_row_id = null, array $vars = [])
    {
        try {
            $this->loadReconcilerDependencies();
            $normalized = \Webnic\Orders::normalizeDomain((string) $domain);
            $row = empty($module_row_id) ? null : $this->getModuleRow($module_row_id);
        } catch (\Throwable $e) {
            $this->logRestore($this->restoreRecord(
                'restore',
                -1,
                '',
                'failed',
                'indeterminate',
                get_class($e),
                self::REGISTER_INTENT_SERVICE_ID
            ));
            $this->setRestoreError('restore_unavailable');

            return false;
        }

        $decision = $this->performRestore($normalized, $row, null, $vars);
        if (($decision['outcome'] ?? 'failed') !== 'ok') {
            $this->setRestoreError($decision['error_key'] ?? 'restore_failed');

            return false;
        }

        return true;
    }

    /**
     * Runs a restore attempt end-to-end and returns its classified outcome.
     *
     * This is shared by restoreDomain() and the service-tab restore surface. It fails closed unless live
     * registry status, GRACE rule, and restore price are all deterministic; then it submits the captured
     * restore command and emits exactly one scrubbed webnic/restore lifecycle record. It never mutates
     * webnic_orders and never calls transition().
     *
     * @param string $domain The normalized domain
     * @param stdClass|false|null $row The service's own module row
     * @param object|null $service The service, when known
     * @param array $vars Restore vars
     * @return array ['outcome' => 'ok'|'blocked'|'failed', 'error_key' => string|null, 'pending' => bool]
     */
    private function performRestore(string $domain, $row, ?object $service = null, array $vars = []): array
    {
        Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');

        $this->last_restore_result = null;
        $service_id = $this->restoreServiceId($service);
        $module_row_id = is_object($row) && isset($row->id) ? (int) $row->id : -1;

        if (trim($domain) === '') {
            $this->logRestore($this->restoreRecord(
                'restore',
                -1,
                $domain,
                'failed',
                'terminal',
                'restore_domain_missing',
                $service_id
            ));

            return ['outcome' => 'failed', 'error_key' => 'restore_domain_missing', 'pending' => false];
        }

        if (empty($row)) {
            $this->logRestore($this->restoreRecord(
                'restore',
                -1,
                $domain,
                'failed',
                'terminal',
                'restore_row_unavailable',
                $service_id
            ));

            return ['outcome' => 'failed', 'error_key' => 'restore_row_unavailable', 'pending' => false];
        }

        $gate = $this->restorableState($service, $domain, $module_row_id);
        if (!$gate['allowed']) {
            return $this->restoreBlockedDecision($domain, $module_row_id, $service_id, $gate['reason']);
        }

        try {
            $domainsApi = $this->buildDomainsApi($row);
            $pricingApi = $this->buildPricingApi($row);
        } catch (\Throwable $e) {
            $this->logRestore($this->restoreRecord(
                'restore',
                $module_row_id,
                $domain,
                'failed',
                'indeterminate',
                get_class($e),
                $service_id
            ));

            return ['outcome' => 'failed', 'error_key' => 'restore_unavailable', 'pending' => false];
        }

        try {
            $info = $domainsApi->info($domain);
        } catch (\Throwable $e) {
            $this->logRestore($this->restoreRecord(
                'restore',
                $module_row_id,
                $domain,
                'failed',
                'indeterminate',
                get_class($e),
                $service_id
            ));

            return ['outcome' => 'failed', 'error_key' => 'restore_unavailable', 'pending' => false];
        }

        if (!$info->success()) {
            $this->logRestore($this->restoreRecord(
                'restore',
                $module_row_id,
                $domain,
                'failed',
                $info->errorClass() ?: 'indeterminate',
                'restore_unavailable',
                $service_id
            ));

            return ['outcome' => 'failed', 'error_key' => 'restore_unavailable', 'pending' => false];
        }

        $info_data = $info->data();
        $status = is_array($info_data) && isset($info_data['status']) ? (string) $info_data['status'] : '';
        $expires = is_array($info_data) ? ($info_data['dtexpire'] ?? null) : null;
        if (trim($status) === '') {
            $this->logRestore($this->restoreRecord(
                'restore',
                $module_row_id,
                $domain,
                'failed',
                'indeterminate',
                'restore_unavailable',
                $service_id
            ));

            return ['outcome' => 'failed', 'error_key' => 'restore_unavailable', 'pending' => false];
        }

        $ext = $this->restoreExtFromDomain($domain);
        if ($ext === '') {
            $this->logRestore($this->restoreRecord(
                'restore',
                $module_row_id,
                $domain,
                'failed',
                'terminal',
                'restore_domain_missing',
                $service_id
            ));

            return ['outcome' => 'failed', 'error_key' => 'restore_domain_missing', 'pending' => false];
        }

        try {
            $rule_response = $pricingApi->getExtensionRule($ext, 'grace');
        } catch (\Throwable $e) {
            $this->logRestore($this->restoreRecord(
                'restore',
                $module_row_id,
                $domain,
                'failed',
                'indeterminate',
                get_class($e),
                $service_id,
                ['registry_status' => strtolower(trim($status))]
            ));

            return ['outcome' => 'failed', 'error_key' => 'restore_grace_rule_unavailable', 'pending' => false];
        }

        $rule = null;
        if ($rule_response->success()) {
            $rule_data = $rule_response->data();
            $rule = is_array($rule_data) && isset($rule_data['rules']) && is_array($rule_data['rules'])
                ? $rule_data['rules']
                : null;
        }
        if (!$rule_response->success()) {
            $this->logRestore($this->restoreRecord(
                'restore',
                $module_row_id,
                $domain,
                'failed',
                $rule_response->errorClass() ?: 'indeterminate',
                'restore_grace_rule_unavailable',
                $service_id,
                ['registry_status' => strtolower(trim($status))]
            ));

            return ['outcome' => 'failed', 'error_key' => 'restore_grace_rule_unavailable', 'pending' => false];
        }

        $eligibility = \WebnicPricing::decideGraceRestoreEligibility(
            $rule,
            $status,
            $expires,
            $vars['now'] ?? $this->restoreNow()
        );
        if (!$eligibility['eligible']) {
            $error_class = $eligibility['log_failure'] ? 'indeterminate' : 'terminal';
            $this->logRestore($this->restoreRecord(
                'restore',
                $module_row_id,
                $domain,
                'blocked',
                $error_class,
                $eligibility['error_key'] ?: 'restore_unavailable',
                $service_id,
                [
                    'registry_status' => strtolower(trim($status)),
                    'reason' => $eligibility['reason'] ?? null,
                ]
            ));

            return [
                'outcome' => 'blocked',
                'error_key' => $eligibility['error_key'] ?: 'restore_unavailable',
                'pending' => false,
            ];
        }

        try {
            $price_response = $pricingApi->getExtensionPrice([$ext], ['restore'], 1, 1);
        } catch (\Throwable $e) {
            $this->logRestore($this->restoreRecord(
                'restore',
                $module_row_id,
                $domain,
                'failed',
                'indeterminate',
                get_class($e),
                $service_id,
                ['registry_status' => strtolower(trim($status))]
            ));

            return ['outcome' => 'failed', 'error_key' => 'restore_price_unavailable', 'pending' => false];
        }

        $price = \WebnicPricing::decideRestorePrice($price_response, $ext, 1);
        if (!$price['available']) {
            $this->logRestore($this->restoreRecord(
                'restore',
                $module_row_id,
                $domain,
                'blocked',
                'indeterminate',
                $price['error_key'] ?: 'restore_price_unavailable',
                $service_id,
                [
                    'registry_status' => strtolower(trim($status)),
                    'reason' => $price['reason'] ?? null,
                ]
            ));

            return [
                'outcome' => 'blocked',
                'error_key' => $price['error_key'] ?: 'restore_price_unavailable',
                'pending' => false,
            ];
        }

        $gate = $this->restorableState($service, $domain, $module_row_id);
        if (!$gate['allowed']) {
            return $this->restoreBlockedDecision($domain, $module_row_id, $service_id, $gate['reason']);
        }

        try {
            $response = $domainsApi->restore($domain, ['agreeRestorePolicy' => true]);
            $decision = \WebnicDomains::decideRestore($response);
        } catch (\Throwable $e) {
            $this->logRestore($this->restoreRecord(
                'restore',
                $module_row_id,
                $domain,
                'failed',
                'indeterminate',
                get_class($e),
                $service_id,
                ['registry_status' => strtolower(trim($status))]
            ));

            return ['outcome' => 'failed', 'error_key' => 'restore_unavailable', 'pending' => false];
        }

        $outcome = $decision['outcome'] ?? 'failed';
        $pending = (bool) ($decision['pending'] ?? false);
        $extra = [
            'registry_status' => strtolower(trim($status)),
            'pending' => $pending,
            'restore_price' => $price['price'],
            'currency' => $price['currency'],
        ];

        if ($outcome !== 'ok') {
            $error_key = $decision['error_key'] ?? 'restore_failed';
            $this->last_restore_result = [
                'outcome' => 'failed',
                'pending' => $pending,
            ];
            $this->logRestore($this->restoreRecord(
                'restore',
                $module_row_id,
                $domain,
                'failed',
                $decision['error_class'] ?? 'indeterminate',
                $error_key,
                $service_id,
                $extra
            ));

            return ['outcome' => 'failed', 'error_key' => $error_key, 'pending' => $pending];
        }

        $this->last_restore_result = [
            'outcome' => 'ok',
            'pending' => $pending,
        ];
        $this->logRestore($this->restoreRecord(
            'restore',
            $module_row_id,
            $domain,
            'ok',
            null,
            $pending ? 'restore_pending' : 'restore_ok',
            $service_id,
            $extra
        ));

        return ['outcome' => 'ok', 'error_key' => null, 'pending' => $pending];
    }

    /**
     * Decides whether local service/order state can submit a restore side-action.
     *
     * @param object|null $service The service being restored, or null on direct restoreDomain()
     * @param string $domain The normalized domain
     * @param int $module_row_id The module row id
     * @return array ['allowed' => bool, 'reason' => string|null]
     */
    private function restorableState($service, string $domain = '', int $module_row_id = 0): array
    {
        if ($service === null) {
            if ($module_row_id <= 0 || trim($domain) === '') {
                return ['allowed' => true, 'reason' => null];
            }

            $this->loadReconcilerDependencies();
            try {
                $order = $this->resolveOrderByModuleRowDomain($module_row_id, $domain);
            } catch (\Throwable $e) {
                if (isset($this->Record)) {
                    $this->Record->reset();
                }

                return ['allowed' => false, 'reason' => 'indeterminate'];
            }

            return $this->restoreOrderState($order);
        }

        $this->loadReconcilerDependencies();

        if ($this->isLocalCancelledService($service)) {
            return ['allowed' => false, 'reason' => 'cancelled'];
        }

        try {
            $order = $this->resolveOrderByDomain($service);
        } catch (\Throwable $e) {
            if (isset($this->Record)) {
                $this->Record->reset();
            }

            return ['allowed' => false, 'reason' => 'indeterminate'];
        }

        return $this->restoreOrderState($order);
    }

    /**
     * Classifies local order state for restore side-actions.
     *
     * @param stdClass|null $order The preferred local order row
     * @return array ['allowed' => bool, 'reason' => string|null]
     */
    private function restoreOrderState($order): array
    {
        if ($order === null) {
            return ['allowed' => true, 'reason' => null];
        }

        $state = is_object($order) ? ($order->state ?? null) : null;
        if ($state === \Webnic\Orders::STATE_ACTIVE) {
            return ['allowed' => true, 'reason' => null];
        }
        if (in_array($state, [\Webnic\Orders::STATE_FAILED, \Webnic\Orders::STATE_CANCELLED], true)) {
            return ['allowed' => false, 'reason' => 'terminal'];
        }

        return ['allowed' => false, 'reason' => 'pending'];
    }

    /**
     * Writes an audit record for admin restore POSTs rejected before performRestore().
     *
     * @param object|null $service The service being restored
     * @param string $reason Local block reason
     */
    private function auditRestoreTabBlock($service, string $reason): void
    {
        $domain = '';
        try {
            $this->loadReconcilerDependencies();
            $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
        } catch (\Throwable $e) {
            $domain = '';
        }

        $module_row_id = is_object($service) && !empty($service->module_row_id)
            ? (int) $service->module_row_id
            : -1;

        $this->logRestore($this->restoreRecord(
            'restore',
            $module_row_id,
            $domain,
            'blocked',
            $reason === 'indeterminate' ? 'indeterminate' : null,
            $reason === 'ack_missing' ? 'restore_fee_ack_missing' : $this->restoreBlockKey($reason),
            $this->restoreServiceId($service),
            ['reason' => $reason]
        ));
    }

    /**
     * Determines the local block reason for a rejected admin restore POST.
     *
     * @param object|null $service The service being restored
     * @param stdClass|null $order The preferred local order row
     * @return string Local block reason
     */
    private function restoreTabBlockReason($service, $order): string
    {
        if ($this->isLocalCancelledService($service)) {
            return 'cancelled';
        }
        if ($order === null) {
            return 'indeterminate';
        }

        $state = is_object($order) ? ($order->state ?? null) : null;
        if (in_array($state, [\Webnic\Orders::STATE_FAILED, \Webnic\Orders::STATE_CANCELLED], true)) {
            return 'terminal';
        }

        return 'pending';
    }

    /**
     * Returns the current timestamp source for restore window checks.
     *
     * @return int Unix timestamp
     */
    protected function restoreNow()
    {
        return time();
    }

    /**
     * Logs and returns the common local-state blocked restore decision.
     *
     * @param string $domain The normalized domain
     * @param int $module_row_id The module row id
     * @param int $service_id The service id or sentinel
     * @param string|null $reason The local block reason
     * @return array Restore decision
     */
    private function restoreBlockedDecision(string $domain, int $module_row_id, int $service_id, $reason): array
    {
        $error_key = $this->restoreBlockKey($reason);
        $error_class = in_array($reason, ['terminal', 'cancelled'], true)
            ? 'terminal'
            : ($reason === 'indeterminate' ? 'indeterminate' : 'retryable');
        $this->logRestore($this->restoreRecord(
            'restore',
            $module_row_id,
            $domain,
            'blocked',
            $error_class,
            $error_key,
            $service_id
        ));

        return ['outcome' => 'blocked', 'error_key' => $error_key, 'pending' => false];
    }

    /**
     * Maps a local restore block reason to a localized error key.
     *
     * @param string|null $reason pending|terminal|cancelled|indeterminate|null
     * @return string The Webnic.!error.* key suffix
     */
    private function restoreBlockKey($reason): string
    {
        switch ($reason) {
            case 'pending':
                return 'restore_blocked_pending';
            case 'not_in_grace':
                return 'restore_not_in_grace';
            case 'terminal':
            case 'cancelled':
                return 'restore_blocked_terminal';
            default:
                return 'restore_unavailable';
        }
    }

    /**
     * Returns the dotless extension key from a normalized domain.
     *
     * @param string $domain Normalized domain name
     * @return string Dotless TLD/SLD extension, or empty when not parseable
     */
    private function restoreExtFromDomain(string $domain): string
    {
        $domain = trim($domain, " \t\n\r\0\x0B.");
        $dot = strpos($domain, '.');
        if ($dot === false || $dot === strlen($domain) - 1) {
            return '';
        }

        $ext = substr($domain, $dot + 1);

        return mb_check_encoding($ext, 'ASCII') ? strtolower($ext) : $ext;
    }

    /**
     * Sets a single localized restore Input error.
     *
     * @param string $key The Webnic.!error.* key suffix
     */
    private function setRestoreError(string $key): void
    {
        if (isset($this->Input)) {
            $this->Input->setErrors(['webnic_restore' => [$key => Language::_('Webnic.!error.' . $key, true)]]);
        }
    }

    /**
     * Returns the INV-9 service_id for restore records.
     *
     * @param object|null $service The service, when known
     * @return int The service id, or REGISTER_INTENT_SERVICE_ID when unknown
     */
    private function restoreServiceId($service): int
    {
        return ($service !== null && isset($service->id)) ? (int) $service->id : self::REGISTER_INTENT_SERVICE_ID;
    }

    /**
     * Writes a scrubbed restore lifecycle record to the Blesta module log.
     *
     * @param array $record The structured restore record
     */
    private function logRestore(array $record): void
    {
        try {
            if (!class_exists('\Webnic\Support\Redactor')) {
                Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
            }
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $success = ($record['outcome'] ?? '') === 'ok';
            $this->log('webnic/restore', serialize($scrubbed), 'output', $success);
        } catch (\Throwable $e) {
            // Logging must never interrupt a restore action.
        }
    }

    /**
     * Builds a structured restore lifecycle record. No from_state/to_state: restore is stateless.
     *
     * @param string $command restore
     * @param int $module_row_id The owning module row, or -1 when unresolved
     * @param string $domain The normalized domain
     * @param string $outcome ok|blocked|failed
     * @param string|null $error_class terminal|retryable|indeterminate|null
     * @param string $message Safe key/class/outcome text, never raw provider text
     * @param int $service_id The real service id when known, else sentinel 0
     * @param array $extra Additional safe structured fields
     * @return array The structured record
     */
    private function restoreRecord(string $command, int $module_row_id, string $domain, string $outcome, ?string $error_class, string $message, int $service_id, array $extra = []): array
    {
        if ($outcome === 'blocked') {
            $level = in_array($error_class, ['retryable', 'indeterminate'], true) ? 'notice' : 'info';
        } else {
            $level = $error_class === 'terminal'
                ? 'error'
                : (in_array($error_class, ['retryable', 'indeterminate'], true) ? 'notice' : 'info');
        }

        return array_merge([
            'run_id' => uniqid('wnrst_', true),
            'level' => $level,
            'module_row_id' => $module_row_id,
            'service_id' => $service_id,
            'domain' => $domain,
            'command' => $command,
            'outcome' => $outcome,
            'error_class' => $error_class,
            'message' => $message,
        ], $extra);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Suspend / unsuspend a domain (WN-4-5 / FR25). T0 OTE capture chose Path B:
    // local-only Blesta status changes. WebNIC's reachable suspend endpoint did not
    // flip info.suspended, and unsuspend requires WebNIC support, so a registry call
    // would be unsafe. These hooks therefore open NO order, run NO transition(), and
    // build NO WebNIC API client (NFR5).
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Allows Blesta to suspend the local service after WN-4-5 Path B checks and audit.
     *
     * @param stdClass $package The service's package
     * @param stdClass $service The service being suspended
     * @param stdClass|null $parent_package Unused
     * @param stdClass|null $parent_service Unused
     * @return null Always null; Input errors decide whether Blesta aborts the local flip
     */
    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->handleSuspendService($service, true);
    }

    /**
     * Allows Blesta to unsuspend the local service after WN-4-5 Path B checks and audit.
     *
     * @param stdClass $package The service's package
     * @param stdClass $service The service being unsuspended
     * @param stdClass|null $parent_package Unused
     * @param stdClass|null $parent_service Unused
     * @return null Always null; Input errors decide whether Blesta aborts the local flip
     */
    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return $this->handleSuspendService($service, false);
    }

    /**
     * Shared core for Blesta's suspend/unsuspend hooks (FR25/FR39d/INV-1).
     *
     * @param stdClass $service The service being changed
     * @param bool $suspend True for suspend, false for unsuspend
     * @return null Always null by Module contract
     */
    private function handleSuspendService($service, bool $suspend)
    {
        if ($suspend) {
            $gate = $this->suspendableState($service);
            if (!$gate['allowed']) {
                $this->setSuspendError($this->suspendBlockKey($gate['reason'], $suspend));
                $this->logBlockedSuspend($service, $gate['reason'], $suspend);

                return null;
            }
        }

        try {
            $this->loadReconcilerDependencies();
            $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
            $row_id = $service->module_row_id ?? null;
            $row = empty($row_id) ? null : $this->getModuleRow($row_id);
        } catch (\Throwable $e) {
            $command = $suspend ? 'suspend' : 'unsuspend';
            $this->logSuspend($this->suspendRecord($command, -1, '', 'failed', 'indeterminate', get_class($e), $this->suspendServiceId($service)));
            $this->setSuspendError($this->suspendUnavailableKey($suspend));

            return null;
        }

        $decision = $this->performSuspend($domain, $suspend, $row, $service);
        if ($decision['outcome'] === 'failed') {
            $this->setSuspendError($decision['error_key'] ?: $this->suspendFailedKey($suspend));
        }

        return null;
    }

    /**
     * Performs the Path B local-only action and returns its classified outcome.
     *
     * @param string $domain The normalized domain
     * @param bool $suspend True for suspend, false for unsuspend
     * @param stdClass|false|null $row The service's own module row
     * @param stdClass|null $service The service, when known
     * @return array ['outcome' => 'ok'|'failed', 'error_key' => string|null]
     */
    private function performSuspend(string $domain, bool $suspend, $row, $service = null): array
    {
        Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');

        $command = $suspend ? 'suspend' : 'unsuspend';
        $service_id = $this->suspendServiceId($service);

        if (trim($domain) === '') {
            $key = $command . '_domain_missing';
            $this->logSuspend($this->suspendRecord($command, -1, $domain, 'failed', 'terminal', $key, $service_id));

            return ['outcome' => 'failed', 'error_key' => $key];
        }

        if (empty($row)) {
            $key = $command . '_row_unavailable';
            $this->logSuspend($this->suspendRecord($command, -1, $domain, 'failed', 'terminal', $key, $service_id));

            return ['outcome' => 'failed', 'error_key' => $key];
        }

        // Path B, from T0 evidence: no WebNIC write is safe to call. Let Blesta flip the local
        // service status and keep one audit record for operator traceability (INV-9).
        $this->logSuspend($this->suspendRecord(
            $command,
            (int) $row->id,
            $domain,
            'ok',
            null,
            $command . '_local_only',
            $service_id
        ));

        return ['outcome' => 'ok', 'error_key' => null];
    }

    /**
     * Decides whether a suspend/unsuspend action is allowed by the order state (FR39d).
     *
     * @param stdClass $service The service being changed
     * @return array ['allowed' => bool, 'reason' => 'pending'|'failed'|'cancelled'|'indeterminate'|null]
     */
    private function suspendableState($service): array
    {
        $this->loadReconcilerDependencies();

        try {
            $order = $this->resolveOrderByDomain($service);
        } catch (\Throwable $e) {
            if (isset($this->Record)) {
                $this->Record->reset();
            }

            return ['allowed' => false, 'reason' => 'indeterminate'];
        }

        if ($order === null) {
            return ['allowed' => true, 'reason' => null];
        }

        $state = is_object($order) ? ($order->state ?? null) : null;
        if ($state === \Webnic\Orders::STATE_ACTIVE) {
            return ['allowed' => true, 'reason' => null];
        }
        if ($state === \Webnic\Orders::STATE_FAILED) {
            return ['allowed' => false, 'reason' => 'failed'];
        }
        if ($state === \Webnic\Orders::STATE_CANCELLED) {
            return ['allowed' => false, 'reason' => 'cancelled'];
        }

        return ['allowed' => false, 'reason' => 'pending'];
    }

    /**
     * Maps a suspend block reason to the localized Input error key.
     *
     * @param string|null $reason pending|failed|cancelled|indeterminate
     * @param bool $suspend True for suspend, false for unsuspend
     * @return string The Webnic.!error.* key suffix
     */
    private function suspendBlockKey($reason, bool $suspend): string
    {
        $prefix = $suspend ? 'suspend' : 'unsuspend';
        switch ($reason) {
            case 'pending':
                return $prefix . '_blocked_pending';
            case 'failed':
            case 'cancelled':
                return $prefix . '_blocked_terminal';
            default:
                return $prefix . '_unavailable';
        }
    }

    /**
     * Sets a single localized suspend/unsuspend Input error.
     *
     * @param string $key The Webnic.!error.* key suffix
     */
    private function setSuspendError(string $key): void
    {
        if (isset($this->Input)) {
            $this->Input->setErrors(['webnic_suspend' => [$key => Language::_('Webnic.!error.' . $key, true)]]);
        }
    }

    /**
     * Emits the INV-9 audit record for a gate-blocked suspend/unsuspend attempt.
     *
     * @param stdClass $service The blocked service
     * @param string|null $reason pending|failed|cancelled|indeterminate
     * @param bool $suspend True for suspend, false for unsuspend
     */
    private function logBlockedSuspend($service, $reason, bool $suspend): void
    {
        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');

            $domain = '';
            try {
                $this->loadReconcilerDependencies();
                $domain = \Webnic\Orders::normalizeDomain((string) $this->getServiceDomain($service));
            } catch (\Throwable $e) {
                $domain = '';
            }

            $error_class = in_array($reason, ['failed', 'cancelled'], true)
                ? 'terminal'
                : ($reason === 'indeterminate' ? 'indeterminate' : 'retryable');
            $module_row_id = empty($service->module_row_id) ? -1 : (int) $service->module_row_id;
            $command = $suspend ? 'suspend' : 'unsuspend';

            $this->logSuspend($this->suspendRecord(
                $command,
                $module_row_id,
                $domain,
                'blocked',
                $error_class,
                $this->suspendBlockKey($reason, $suspend),
                $this->suspendServiceId($service)
            ));
        } catch (\Throwable $e) {
            // Audit logging must never interrupt the billing flow.
        }
    }

    /**
     * Returns the default unavailable key for the requested command.
     *
     * @param bool $suspend True for suspend, false for unsuspend
     * @return string The Webnic.!error.* key suffix
     */
    private function suspendUnavailableKey(bool $suspend): string
    {
        return $suspend ? 'suspend_unavailable' : 'unsuspend_unavailable';
    }

    /**
     * Returns the default failed key for the requested command.
     *
     * Reserved for a future Path A wire classifier. Under WN-4-5 Path B, performSuspend()
     * returns specific row/domain error keys for every failed outcome.
     *
     * @param bool $suspend True for suspend, false for unsuspend
     * @return string The Webnic.!error.* key suffix
     */
    private function suspendFailedKey(bool $suspend): string
    {
        return $suspend ? 'suspend_failed' : 'unsuspend_failed';
    }

    /**
     * Returns the INV-9 service_id for a suspend record.
     *
     * @param stdClass|null $service The service, when known
     * @return int The service id, or REGISTER_INTENT_SERVICE_ID when unknown
     */
    private function suspendServiceId($service): int
    {
        return ($service !== null && isset($service->id)) ? (int) $service->id : self::REGISTER_INTENT_SERVICE_ID;
    }

    /**
     * Writes a scrubbed INV-9 suspend record to the Blesta module log.
     *
     * The success flag follows the structured outcome (`ok` only), so blocked and
     * failed records remain visible to operators filtering module logs for failures.
     *
     * @param array $record The structured suspend record
     */
    private function logSuspend(array $record): void
    {
        try {
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $success = ($record['outcome'] ?? '') === 'ok';
            $this->log('webnic/suspend', serialize($scrubbed), 'output', $success);
        } catch (\Throwable $e) {
            // Logging must never interrupt a suspend action.
        }
    }

    /**
     * Builds a structured INV-9 suspend/unsuspend record. No from_state/to_state:
     * WN-4-5 is stateless and never transitions a webnic_orders row (NFR5).
     *
     * @param string $command suspend|unsuspend
     * @param int $module_row_id The owning module row, or -1 when unresolved
     * @param string $domain The normalized domain
     * @param string $outcome ok|blocked|failed
     * @param string|null $error_class terminal|retryable|indeterminate|null
     * @param string $message Safe key/class/outcome text, never raw provider text
     * @param int $service_id The real service id when known, else sentinel 0
     * @return array The structured record
     */
    private function suspendRecord(string $command, int $module_row_id, string $domain, string $outcome, ?string $error_class, string $message, int $service_id): array
    {
        $level = $error_class === 'terminal'
            ? 'error'
            : (in_array($error_class, ['retryable', 'indeterminate'], true) ? 'notice' : 'info');

        return [
            'run_id' => uniqid('wnsus_', true),
            'level' => $level,
            'module_row_id' => $module_row_id,
            'service_id' => $service_id,
            'domain' => $domain,
            'command' => $command,
            'mode' => $command,
            'suspend' => $command === 'suspend',
            'outcome' => $outcome,
            'error_class' => $error_class,
            'message' => $message,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Cancel / change package (WN-4-6 / FR26-FR27). These Blesta core-called hooks
    // are billing lifecycle hooks, not provisioning commands. Package changes never
    // re-register, transfer, or mutate registry state.
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Keeps a WebNIC domain registration untouched when Blesta cancels billing (FR26).
     *
     * Overrides Module::cancelService with the inherited signature. Billing cancellation is
     * intentionally not a registry delete: preserve the registration and existing service meta,
     * make no WebNIC API call, and never gate a customer teardown path on registry/order state.
     *
     * @param stdClass $package The service's package
     * @param stdClass $service The service being cancelled
     * @param stdClass|null $parent_package Unused
     * @param stdClass|null $parent_service Unused
     * @return null Always null; preserve existing service meta
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        $this->logCancel($this->cancelRecord(
            'cancel',
            empty($service->module_row_id) ? -1 : (int) $service->module_row_id,
            $this->cancelLogDomain($service),
            'ok',
            null,
            'cancel_noop',
            $this->cancelServiceId($service)
        ));

        return null;
    }

    /**
     * Keeps a WebNIC domain registration untouched when Blesta changes billing package (FR27).
     *
     * Overrides Module::changeServicePackage with the inherited signature. The registered
     * domain's TLD is immutable at the registry; a Blesta package change only remaps local
     * billing/term metadata and must never construct a WebNIC API client, register, or transfer.
     *
     * @param stdClass $package_from The current package
     * @param stdClass $package_to The new package
     * @param stdClass $service The current service
     * @param stdClass|null $parent_package Unused
     * @param stdClass|null $parent_service Unused
     * @return null Always null; preserve existing service meta
     */
    public function changeServicePackage(
        $package_from,
        $package_to,
        $service,
        $parent_package = null,
        $parent_service = null
    ) {
        $this->logCancel($this->cancelRecord(
            'change_package',
            empty($service->module_row_id) ? -1 : (int) $service->module_row_id,
            $this->cancelLogDomain($service),
            'ok',
            null,
            'change_package_noop',
            $this->cancelServiceId($service)
        ));

        return null;
    }

    /**
     * Performs the deliberate admin registry delete side-action (FR26/AC1/AC3).
     *
     * This is never called by cancelService. It gates only against in-flight provisioning and
     * live deletion-window statuses, then calls the captured WebNIC delete command and emits one
     * cancel-family audit record for the attempt. It never mutates webnic_orders (NFR5).
     *
     * @param string $domain The normalized domain
     * @param stdClass|false|null $row The service's own module row
     * @param stdClass|null $service The service, when known
     * @return bool True when delete intent is accepted/satisfied; false with Input errors otherwise
     */
    protected function performDelete(string $domain, $row, $service = null): bool
    {
        Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');

        $this->last_delete_result = null;
        $service_id = $this->cancelServiceId($service);
        $module_row_id = -1;

        try {
            $this->loadReconcilerDependencies();
            $domain = \Webnic\Orders::normalizeDomain($domain);

            if ($service !== null) {
                $row_id = $service->module_row_id ?? null;
                $module_row_id = empty($row_id) ? -1 : (int) $row_id;
                $row = empty($row_id) ? null : $this->getModuleRow($row_id);
            } elseif (is_object($row) && isset($row->id)) {
                $module_row_id = (int) $row->id;
            }
        } catch (\Throwable $e) {
            $this->logCancel($this->cancelRecord(
                'delete',
                $module_row_id,
                $domain,
                'failed',
                'indeterminate',
                get_class($e),
                $service_id
            ));
            $this->setCancelError('cancel_unavailable');

            return false;
        }

        if (trim($domain) === '') {
            $this->logCancel($this->cancelRecord(
                'delete',
                -1,
                $domain,
                'failed',
                'terminal',
                'cancel_domain_missing',
                $service_id
            ));
            $this->setCancelError('cancel_domain_missing');

            return false;
        }

        if (empty($row)) {
            $this->logCancel($this->cancelRecord(
                'delete',
                -1,
                $domain,
                'failed',
                'terminal',
                'cancel_row_unavailable',
                $service_id
            ));
            $this->setCancelError('cancel_row_unavailable');

            return false;
        }

        $module_row_id = (int) $row->id;
        $gate = $this->deletableState($service);
        if (!$gate['allowed']) {
            if ($gate['reason'] === 'pending') {
                $this->logCancel($this->cancelRecord(
                    'delete',
                    $module_row_id,
                    $domain,
                    'blocked',
                    'retryable',
                    'cancel_blocked_pending',
                    $service_id
                ));
                $this->setCancelError('cancel_blocked_pending');

                return false;
            }

            $this->logCancel($this->cancelRecord(
                'delete',
                $module_row_id,
                $domain,
                'failed',
                'indeterminate',
                $gate['message'] ?? 'cancel_unavailable',
                $service_id
            ));
            $this->setCancelError('cancel_unavailable');

            return false;
        }

        $window = $this->deletionWindowState($domain, $row, $service);
        if (!$window['allowed']) {
            $this->setCancelError($window['error_key'] ?: 'cancel_unavailable');

            return false;
        }

        // Race posture (WN-4-6 review): re-check local order state immediately before
        // egress. This closes the cheap TOCTOU gap between the registry info read and
        // the irreversible delete without introducing an engine lock into this stateless hook.
        $gate = $this->deletableState($service);
        if (!$gate['allowed']) {
            if ($gate['reason'] === 'pending') {
                $this->logCancel($this->cancelRecord(
                    'delete',
                    $module_row_id,
                    $domain,
                    'blocked',
                    'retryable',
                    'cancel_blocked_pending',
                    $service_id
                ));
                $this->setCancelError('cancel_blocked_pending');

                return false;
            }

            $this->logCancel($this->cancelRecord(
                'delete',
                $module_row_id,
                $domain,
                'failed',
                'indeterminate',
                $gate['message'] ?? 'cancel_unavailable',
                $service_id
            ));
            $this->setCancelError('cancel_unavailable');

            return false;
        }

        try {
            $domainsApi = $this->buildDomainsApi($row);
            $response = $domainsApi->delete($domain);
            $decision = \WebnicDomains::decideDelete($response);
        } catch (\Throwable $e) {
            $this->logCancel($this->cancelRecord(
                'delete',
                $module_row_id,
                $domain,
                'failed',
                'indeterminate',
                get_class($e),
                $service_id
            ));
            $this->setCancelError('cancel_unavailable');

            return false;
        }

        $extra = [
            'pending' => (bool) ($decision['pending'] ?? false),
        ];
        $data = $response->data();
        $refund = is_array($data) && array_key_exists('refund', $data) && is_bool($data['refund'])
            ? $data['refund']
            : null;
        if ($refund !== null) {
            $extra['refund'] = $refund;
            if ($refund === false) {
                $extra['warning'] = 'cancel_non_refundable';
            }
        }

        $outcome = $decision['outcome'] ?? 'failed';
        $error_class = $decision['error_class'] ?? null;
        if ($outcome === 'failed') {
            $error_key = ($decision['error_key'] ?? null) === 'delete_failed'
                ? 'cancel_failed'
                : 'cancel_unavailable';
            $this->last_delete_result = [
                'outcome' => 'failed',
                'refund' => $refund,
                'pending' => (bool) ($decision['pending'] ?? false),
            ];
            $this->logCancel($this->cancelRecord(
                'delete',
                $module_row_id,
                $domain,
                'failed',
                $error_class,
                $error_key,
                $service_id,
                $extra
            ));
            $this->setCancelError($error_key);

            return false;
        }

        $this->logCancel($this->cancelRecord(
            'delete',
            $module_row_id,
            $domain,
            $outcome,
            null,
            $outcome === 'already' ? 'delete_already' : 'delete_ok',
            $service_id,
            $extra
        ));
        $this->last_delete_result = [
            'outcome' => $outcome,
            'refund' => $refund,
            'pending' => (bool) ($decision['pending'] ?? false),
        ];

        return true;
    }

    /**
     * Decides whether a deliberate registry delete is allowed by order state (DG-3).
     *
     * Only no-order and active rows are allowed. Failed/cancelled rows are terminal local
     * states with their own surfaces; any other known order state is the pending family
     * and is blocked before registry egress.
     *
     * @param stdClass|null $service The service being deleted
     * @return array ['allowed' => bool, 'reason' => 'pending'|'indeterminate'|null, 'message' => string|null]
     */
    private function deletableState($service): array
    {
        $this->loadReconcilerDependencies();

        if ($this->isLocalCancelledService($service)) {
            return ['allowed' => false, 'reason' => 'unavailable', 'message' => 'cancel_unavailable'];
        }

        try {
            $order = $this->resolveOrderByDomain($service);
        } catch (\Throwable $e) {
            if (isset($this->Record)) {
                $this->Record->reset();
            }

            return ['allowed' => false, 'reason' => 'indeterminate', 'message' => get_class($e)];
        }

        if ($order === null) {
            return ['allowed' => true, 'reason' => null, 'message' => null];
        }

        $state = is_object($order) ? ($order->state ?? null) : null;
        if ($state === \Webnic\Orders::STATE_ACTIVE) {
            return ['allowed' => true, 'reason' => null, 'message' => null];
        }

        if (in_array($state, [\Webnic\Orders::STATE_FAILED, \Webnic\Orders::STATE_CANCELLED], true)) {
            return ['allowed' => false, 'reason' => 'unavailable', 'message' => 'cancel_unavailable'];
        }

        return ['allowed' => false, 'reason' => 'pending', 'message' => 'cancel_blocked_pending'];
    }

    /**
     * Refuses a registry delete when the live registry status is in the deletion window (AC3).
     *
     * Reads the row-scoped registry info status immediately before delete. If info cannot prove
     * the domain is outside the grace/redemption/delete window, the guard fails closed and emits
     * one scrubbed cancel audit record. No order state is mutated (NFR5).
     *
     * @param string $domain The normalized domain
     * @param stdClass|false|null $row The service's own module row
     * @param stdClass|null $service The service, when known
     * @return array ['allowed' => bool, 'reason' => string|null, 'error_key' => string|null, 'error_class' => string|null, 'status' => string|null]
     */
    private function deletionWindowState(string $domain, $row, $service = null): array
    {
        $module_row_id = is_object($row) && isset($row->id) ? (int) $row->id : -1;
        $service_id = $this->cancelServiceId($service);

        try {
            $domainsApi = $this->buildDomainsApi($row);
            $response = $domainsApi->info($domain);
        } catch (\Throwable $e) {
            $this->logCancel($this->cancelRecord(
                'delete',
                $module_row_id,
                $domain,
                'failed',
                'indeterminate',
                get_class($e),
                $service_id
            ));

            return [
                'allowed' => false,
                'reason' => 'indeterminate',
                'error_key' => 'cancel_unavailable',
                'error_class' => 'indeterminate',
                'status' => null,
            ];
        }

        if (!$response->success()) {
            $class = $response->errorClass() ?: 'indeterminate';
            $this->logCancel($this->cancelRecord(
                'delete',
                $module_row_id,
                $domain,
                'failed',
                $class,
                'cancel_unavailable',
                $service_id
            ));

            return [
                'allowed' => false,
                'reason' => 'indeterminate',
                'error_key' => 'cancel_unavailable',
                'error_class' => $class,
                'status' => null,
            ];
        }

        $data = $response->data();
        $status = is_array($data) && isset($data['status']) ? (string) $data['status'] : '';
        $decision = $this->classifyDeletionWindowStatus($status);

        if (!$decision['allowed']) {
            $extra = [];
            if ($decision['status'] !== null) {
                $extra['registry_status'] = $decision['status'];
            }
            $this->logCancel($this->cancelRecord(
                'delete',
                $module_row_id,
                $domain,
                'blocked',
                $decision['error_class'],
                $decision['error_key'] ?: 'cancel_unavailable',
                $service_id,
                $extra
            ));

            return $decision;
        }

        return $decision;
    }

    /**
     * Classifies a raw registry status for the WN-4-6 deletion-window guard.
     *
     * Known deletion-window statuses block with the explicit grace error. Known active
     * statuses allow the delete. Everything else fails closed as indeterminate.
     *
     * @param string $raw_status Raw info().data.status
     * @return array ['allowed' => bool, 'reason' => string|null, 'error_key' => string|null, 'error_class' => string|null, 'status' => string|null]
     */
    private function classifyDeletionWindowStatus(string $raw_status): array
    {
        $status = strtolower(trim($raw_status));
        if ($status === '') {
            return [
                'allowed' => false,
                'reason' => 'indeterminate',
                'error_key' => 'cancel_unavailable',
                'error_class' => 'indeterminate',
                'status' => null,
            ];
        }

        $blocked = $this->configuredStatusList('Webnic.deletion_window_statuses', [
            'redemption_grace',
            'expired',
            'pending_delete',
            'deleted',
        ]);
        if (in_array($status, $blocked, true)) {
            return [
                'allowed' => false,
                'reason' => 'grace',
                'error_key' => 'cancel_blocked_grace',
                'error_class' => 'terminal',
                'status' => $status,
            ];
        }

        $safe = $this->configuredStatusList('Webnic.deletion_safe_statuses', [
            'active',
            'transfer_protected',
            'name_protected',
        ]);
        if (!in_array($status, $safe, true)) {
            return [
                'allowed' => false,
                'reason' => 'indeterminate',
                'error_key' => 'cancel_unavailable',
                'error_class' => 'indeterminate',
                'status' => $status,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'error_key' => null,
            'error_class' => null,
            'status' => $status,
        ];
    }

    /**
     * Loads a configured status list, falling back on malformed or explicit-empty config.
     *
     * @param string $key Configure key
     * @param array $defaults Lowercase default statuses
     * @return array Normalized lowercase status strings
     */
    private function configuredStatusList(string $key, array $defaults): array
    {
        $raw = Configure::get($key);
        if (!is_array($raw) || empty($raw)) {
            return $defaults;
        }

        $values = [];
        foreach ($raw as $value) {
            if (!is_string($value)) {
                continue;
            }
            $value = strtolower(trim($value));
            if ($value !== '') {
                $values[] = $value;
            }
        }

        $values = array_values(array_unique($values));

        return empty($values) ? $defaults : $values;
    }

    /**
     * Sets a single localized cancel/delete Input error.
     *
     * @param string $key The Webnic.!error.* key suffix
     */
    private function setCancelError(string $key): void
    {
        if (isset($this->Input)) {
            $this->Input->setErrors(['webnic_cancel' => [$key => Language::_('Webnic.!error.' . $key, true)]]);
        }
    }

    /**
     * Best-effort domain extraction for cancel/change-package audit records.
     *
     * @param stdClass|null $service The service, when available
     * @return string The normalized-ish domain for logs, or empty string
     */
    private function cancelLogDomain($service): string
    {
        try {
            return strtolower(trim((string) $this->getServiceDomain($service)));
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Returns the INV-9 service_id for cancel-family records.
     *
     * @param stdClass|null $service The service, when known
     * @return int The service id, or REGISTER_INTENT_SERVICE_ID when unknown
     */
    private function cancelServiceId($service): int
    {
        return ($service !== null && isset($service->id)) ? (int) $service->id : self::REGISTER_INTENT_SERVICE_ID;
    }

    /**
     * Writes a scrubbed INV-9 cancel-family record to the Blesta module log.
     *
     * The success flag follows the structured outcome (`ok` only), so blocked and
     * failed records remain visible to operators filtering module logs for failures.
     *
     * @param array $record The structured cancel-family record
     */
    private function logCancel(array $record): void
    {
        try {
            if (!class_exists('\Webnic\Support\Redactor')) {
                Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
            }
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $success = ($record['outcome'] ?? '') === 'ok';
            $this->log('webnic/cancel', serialize($scrubbed), 'output', $success);
        } catch (\Throwable $e) {
            // Logging must never interrupt a cancel/change-package action.
        }
    }

    /**
     * Builds a structured INV-9 cancel-family record. No from_state/to_state:
     * WN-4-6 is stateless and never transitions a webnic_orders row (NFR5).
     *
     * @param string $command cancel|delete|change_package
     * @param int $module_row_id The owning module row, or -1 when unresolved
     * @param string $domain The normalized domain
     * @param string $outcome ok|already|blocked|failed
     * @param string|null $error_class terminal|retryable|indeterminate|null
     * @param string $message Safe key/class/outcome text, never raw provider text
     * @param int $service_id The real service id when known, else sentinel 0
     * @param array $extra Additional safe structured fields
     * @return array The structured record
     */
    private function cancelRecord(string $command, int $module_row_id, string $domain, string $outcome, ?string $error_class, string $message, int $service_id, array $extra = []): array
    {
        $level = $error_class === 'terminal'
            ? 'error'
            : (in_array($error_class, ['retryable', 'indeterminate'], true) ? 'notice' : 'info');

        return array_merge([
            'run_id' => uniqid('wncxl_', true),
            'level' => $level,
            'module_row_id' => $module_row_id,
            'service_id' => $service_id,
            'domain' => $domain,
            'command' => $command,
            'outcome' => $outcome,
            'error_class' => $error_class,
            'message' => $message,
        ], $extra);
    }

    /**
     * Fires a client lifecycle notice through Blesta email, degrading gracefully (AC5/AC7).
     *
     * The TRIGGER for the two closing client emails — registration_confirmed_after_pending
     * (AC7, from the reconciler) and failed_order_resolved (AC5, from recovery) — is owned
     * here; the email TEMPLATE/group/tags/copy are Story 6.1's. Until 6.1 registers the
     * action group, this seam degrades to a non-fatal observability log (FR42 `domain`
     * tag included) rather than throwing out of a recovery action or cron run.
     *
     * @param string $action registration_confirmed_after_pending|failed_order_resolved
     * @param stdClass $service The service the notice concerns
     * @param array $tags Status tags (must include `domain` — FR42)
     */
    private function dispatchClientNotice(string $action, $service, array $tags = []): void
    {
        try {
            $tags = array_merge(['domain' => $tags['domain'] ?? null], $tags);
            $company_id = Configure::get('Blesta.company_id');
            $client_email = $this->resolveClientEmail($service);

            if (empty($client_email) || empty($company_id)) {
                $this->logNotice($action, $tags, 'no_recipient');

                return;
            }

            Loader::loadModels($this, ['Emails']);

            // Story 6.1 registers the 'Webnic.<action>' email group. Until then send()
            // no-ops/errors and we degrade to a log; never fatal the caller.
            $this->Emails->send('Webnic.' . $action, $company_id, null, $client_email, $tags);
            $errors = $this->Emails->errors();
            $this->logNotice($action, $tags, empty($errors) ? 'sent' : 'template_unregistered');
        } catch (\Throwable $e) {
            $this->logNotice($action, $tags, 'dispatch_error');
        }
    }

    /**
     * Resolves the client email for a service (best-effort, never throws).
     *
     * @param stdClass $service The service whose client to resolve
     * @return string|null The client email, or null when it cannot be resolved
     */
    private function resolveClientEmail($service): ?string
    {
        try {
            $client_id = (int) ($service->client_id ?? 0);
            if ($client_id <= 0) {
                return null;
            }
            if (!isset($this->Clients)) {
                Loader::loadModels($this, ['Clients']);
            }
            $client = $this->Clients->get($client_id);

            return $client && !empty($client->email) ? (string) $client->email : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Logs a scrubbed client-notice observability record (no secrets).
     *
     * @param string $action The notice action
     * @param array $tags The notice tags (domain + status; non-secret)
     * @param string $outcome sent|template_unregistered|no_recipient|dispatch_error
     */
    private function logNotice(string $action, array $tags, string $outcome): void
    {
        try {
            $record = [
                'command' => 'notice.' . $action,
                'domain' => $tags['domain'] ?? null,
                'outcome' => $outcome,
            ];
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $this->log('webnic/notice', serialize($scrubbed), 'output', $outcome !== 'dispatch_error');
        } catch (\Throwable $e) {
            // Observability must never interrupt the caller.
        }
    }

    /**
     * Idempotently seeds the lifecycle notification email groups + per-language
     * templates (WN-6-1, AC4/AC5).
     *
     * Emails->send('Webnic.<action>') resolves through TWO DB rows (emails.php:156-162):
     * an `email_groups` row keyed by action AND an ACTIVE per-company, per-language
     * `emails` template row. A group with no active template makes send() return false,
     * so both are seeded together. The email group is global, but `emails` rows are
     * company/language-scoped, so idempotency is checked at the template-row level:
     * reuse the group, add only missing rows, and never overwrite operator copy.
     * Mirrors the canonical precedent support_manager_plugin.php:362-399, with a
     * stricter sender fallback so `support@mydomain.com` is never persisted as a
     * production placeholder. Best-effort: a failure never aborts install()/upgrade()
     * (FR42 templates are additive seam data, not schema).
     */
    private function seedLifecycleEmails(): void
    {
        $emails = Configure::get('Webnic.install.emails');
        if (!is_array($emails) || empty($emails)) {
            return;
        }

        try {
            Loader::loadModels($this, ['Emails', 'EmailGroups', 'Languages']);

            $company_id = Configure::get('Blesta.company_id');
            if (empty($company_id)) {
                $this->logLifecycleEmailSeedFailure('', 'missing_company_id');
                return;
            }

            $languages = $this->Languages->getAll($company_id);
            if (!is_array($languages) || empty($languages)) {
                $this->logLifecycleEmailSeedFailure('', 'missing_company_languages', ['company_id' => $company_id]);
                return;
            }

            foreach ($emails as $email) {
                if (empty($email['action'])) {
                    continue;
                }

                $group = $this->EmailGroups->getByAction($email['action']);
                $group_id = $group ? $group->id : null;

                if (empty($group_id)) {
                    $group_id = $this->EmailGroups->add([
                        'action' => $email['action'],
                        'type' => $email['type'],
                        'plugin_dir' => $email['plugin_dir'],
                        'tags' => $email['tags'],
                    ]);
                    if (empty($group_id)) {
                        $this->logLifecycleEmailSeedFailure($email['action'], 'email_group_add_failed');
                        continue;
                    }
                }

                $from = $this->lifecycleEmailFromAddress($email);
                if ($from === null) {
                    continue;
                }

                foreach ($languages as $language) {
                    $lang = (string) ($language->code ?? '');
                    if ($lang === '') {
                        continue;
                    }

                    $existing = $this->lifecycleEmailTemplate($group_id, $company_id, $lang);
                    if ($existing) {
                        $this->repairLifecycleEmailSender($existing, $from, $email['action'], $company_id, $lang);
                        continue;
                    }

                    $email_id = $this->Emails->add([
                        'email_group_id' => $group_id,
                        'company_id' => $company_id,
                        'lang' => $lang,
                        'from' => $from,
                        'from_name' => $email['from_name'],
                        'subject' => $email['subject'],
                        'text' => $email['text'],
                        'html' => $email['html'],
                        'status' => 'active',
                    ]);
                    if (empty($email_id)) {
                        $this->logLifecycleEmailSeedFailure(
                            $email['action'],
                            'email_add_failed',
                            ['company_id' => $company_id, 'lang' => $lang]
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logLifecycleEmailSeedFailure('', 'exception', ['message' => $e->getMessage()]);
        }
    }

    /**
     * Returns the exact email-template row without Emails->getByType() language fallback.
     *
     * @param int|string $group_id
     * @param int|string $company_id
     * @param string $lang
     * @return mixed
     */
    private function lifecycleEmailTemplate($group_id, $company_id, string $lang)
    {
        $record = new stdClass();
        Loader::loadComponents($record, ['Record']);

        return $record->Record->select(['emails.*'])
            ->from('emails')
            ->where('email_group_id', '=', $group_id)
            ->where('company_id', '=', $company_id)
            ->where('lang', '=', $lang)
            ->fetch();
    }

    /**
     * Repairs legacy seed-owned placeholder senders without touching operator copy.
     *
     * @param stdClass $template
     * @param string $from
     * @param string $action
     * @param int|string $company_id
     * @param string $lang
     * @return void
     */
    private function repairLifecycleEmailSender(stdClass $template, string $from, string $action, $company_id, string $lang): void
    {
        if (($template->status ?? '') !== 'active') {
            $this->logLifecycleEmailSeedFailure(
                $action,
                'inactive_template_blocks_reseed',
                ['company_id' => $company_id, 'lang' => $lang]
            );
        }

        if (strpos((string) ($template->from ?? ''), '@mydomain.com') === false) {
            return;
        }

        $vars = [
            'email_group_id' => $template->email_group_id,
            'company_id' => $template->company_id,
            'lang' => $template->lang,
            'from' => $from,
            'from_name' => $template->from_name,
            'subject' => $template->subject,
            'text' => $template->text,
            'html' => $template->html,
            'status' => $template->status,
        ];

        foreach (['email_signature_id', 'email_template_group_id', 'include_attachments'] as $field) {
            if (property_exists($template, $field)) {
                $vars[$field] = $template->{$field};
            }
        }

        $this->Emails->edit($template->id, $vars);
        if ($this->Emails->errors()) {
            $this->logLifecycleEmailSeedFailure(
                $action,
                'placeholder_sender_repair_failed',
                ['company_id' => $company_id, 'lang' => $lang]
            );
        }
    }

    /**
     * Builds a validated sender address for lifecycle templates.
     *
     * The config default deliberately uses support@mydomain.com as a Blesta-style
     * placeholder. Persist it only after replacing the domain with a valid company
     * hostname; otherwise skip template seeding and log a repairable warning.
     *
     * @param array $email
     * @return string|null
     */
    private function lifecycleEmailFromAddress(array $email): ?string
    {
        $from = (string) ($email['from'] ?? '');
        $company = Configure::get('Blesta.company');
        $hostname = (is_object($company) && !empty($company->hostname))
            ? $this->normalizeLifecycleEmailHostname((string) $company->hostname)
            : null;

        if (strpos($from, '@mydomain.com') !== false) {
            if ($hostname === null) {
                $this->logLifecycleEmailSeedFailure((string) ($email['action'] ?? ''), 'missing_valid_sender_hostname');
                return null;
            }
            $from = str_replace('@mydomain.com', '@' . $hostname, $from);
        }

        if (!$this->isValidLifecycleEmailAddress($from)) {
            $this->logLifecycleEmailSeedFailure(
                (string) ($email['action'] ?? ''),
                'invalid_sender_address',
                []
            );
            return null;
        }

        return $from;
    }

    /**
     * Normalizes a company hostname into a mail-domain candidate.
     *
     * @param string $hostname
     * @return string|null
     */
    private function normalizeLifecycleEmailHostname(string $hostname): ?string
    {
        $hostname = trim($hostname);
        if ($hostname === '') {
            return null;
        }

        $host = $hostname;
        if (strpos($host, '://') !== false) {
            $host = (string) parse_url($host, PHP_URL_HOST);
        } elseif (strpos($host, '/') !== false) {
            $host = (string) parse_url('https://' . $host, PHP_URL_HOST);
        }

        if (strpos($host, ':') !== false) {
            $host = (string) parse_url('https://' . $host, PHP_URL_HOST);
        }

        $host = strtolower(trim($host, ". \t\n\r\0\x0B"));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false
            || !preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+$/', $host)) {
            return null;
        }

        return $host;
    }

    /**
     * @param string $email
     * @return bool
     */
    private function isValidLifecycleEmailAddress(string $email): bool
    {
        if (strpos($email, '@mydomain.com') !== false) {
            return false;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Logs lifecycle email seed failures without aborting install()/upgrade().
     *
     * @param string $action
     * @param string $reason
     * @param array $context
     * @return void
     */
    private function logLifecycleEmailSeedFailure(string $action, string $reason, array $context = []): void
    {
        $fallback = ['action' => $action, 'reason' => $reason];
        foreach (['company_id', 'lang'] as $key) {
            if (array_key_exists($key, $context)) {
                $fallback[$key] = $context[$key];
            }
        }

        try {
            Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
            $record = array_merge(
                ['command' => 'email.lifecycle.seed', 'action' => $action, 'reason' => $reason],
                $context
            );
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $this->log('webnic/email_seed', serialize($scrubbed), 'output', false);
        } catch (\Throwable $ignored) {
            // Preserve best-effort install/upgrade semantics even if logging is unavailable.
            error_log('WebNIC lifecycle email seed failure: ' . json_encode($fallback));
        }
    }

    /**
     * Performs any necessary bootstraping actions. Sets Input errors on
     * failure, preventing the module from being added.
     *
     * WebNIC is multi-row: reseller accounts are added through the Add Row UI
     * (Story 1.2), so install() creates this module's schema and registers its
     * cron task but returns no module-row meta.
     */
    public function install()
    {
        Loader::loadComponents($this, ['Record']);

        // Token cache + refresh lease, scoped per module row (INV-1). Idempotent:
        // create('webnic_tokens', true) => CREATE TABLE IF NOT EXISTS (AC#1/AC#2).
        $this->Record
            ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
            ->setField('module_row_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('token', ['type' => 'text'])
            ->setField('expires_at', ['type' => 'datetime'])
            ->setField('locked_until', ['type' => 'datetime', 'is_null' => true, 'default' => null])
            ->setField('locked_by', ['type' => 'varchar', 'size' => 255, 'is_null' => true, 'default' => null])
            ->setKey(['id'], 'primary')
            // One cache row per module row (architecture L538) so Story 1.4 can upsert
            // deterministically and concurrent refreshes can't duplicate the cache row.
            ->setKey(['module_row_id'], 'unique')
            ->create('webnic_tokens', true);

        // Async order lifecycle spine (WN-3-1). Calls the same builder as the
        // upgrade() 1.1.0 guard, so fresh-install and upgrade schemas are identical.
        $this->createWebnicOrdersTable();

        // Reusable contact/registrant handle cache (WN-3-2). Calls the same builder
        // as the upgrade() 1.2.0 guard, so fresh-install and upgrade schemas match.
        $this->createWebnicContactsTable();

        // Seed the FR42 lifecycle notification email groups/templates (WN-6-1). The
        // same idempotent helper backs the 1.9.1 upgrade guard, so a fresh install and
        // an upgraded install converge on the same email rows. It is independent of
        // cron registration and remains best-effort/non-fatal.
        $this->seedLifecycleEmails();

        // Register this module's cron task(s). This is the sole cron-registration
        // path at 1.0.0 (upgrade() reconciles cron only inside a version-pinned guard).
        if (!$this->addCronTasks($this->getCronTasks())) {
            return;
        }
    }

    /**
     * Builds the webnic_orders table (WN-3-1).
     *
     * The async lifecycle spine: ONE durable row per (service, domain) that is
     * simultaneously the FR18a intent record and the FR39a state machine. Both
     * install() and the upgrade() 1.1.0 guard call THIS one helper, so the
     * fresh-install and upgrade schemas are byte-identical by construction
     * (NFR11/AR22 — the parity harness proves it). Idempotent:
     * create('webnic_orders', true) => CREATE TABLE IF NOT EXISTS, a re-run no-op.
     *
     * Columns are VARCHAR + app-level validation, never native ENUM (architecture
     * L342-343); the 9 legal `state` values and the single writer (transition())
     * live in Webnic\Orders. webnic_order_id/transfer_id are secrets (INV-7);
     * module_row_id is the INV-1 scope. The 3.3 reconciler owns the poll/lock/date
     * columns (next_poll_at/locked_until/...) and any future registry-date column.
     */
    private function createWebnicOrdersTable()
    {
        $this->Record
            ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
            ->setField('service_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('module_row_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('operation', ['type' => 'varchar', 'size' => 16, 'default' => 'register'])
            ->setField('domain', ['type' => 'varchar', 'size' => 255])
            ->setField('state', ['type' => 'varchar', 'size' => 32, 'default' => 'intent'])
            ->setField('webnic_order_id', ['type' => 'varchar', 'size' => 64, 'is_null' => true, 'default' => null])
            ->setField('transfer_id', ['type' => 'varchar', 'size' => 64, 'is_null' => true, 'default' => null])
            ->setField('attempts', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'default' => 0])
            ->setField('created', ['type' => 'datetime'])
            ->setField('last_polled', ['type' => 'datetime', 'is_null' => true, 'default' => null])
            ->setField('next_poll_at', ['type' => 'datetime', 'is_null' => true, 'default' => null])
            ->setField('give_up_at', ['type' => 'datetime', 'is_null' => true, 'default' => null])
            ->setField('locked_until', ['type' => 'datetime', 'is_null' => true, 'default' => null])
            ->setField('locked_by', ['type' => 'varchar', 'size' => 255, 'is_null' => true, 'default' => null])
            ->setKey(['id'], 'primary')
            // The idempotency key (INV-5/INV-1): one row per (module row, service, domain).
            ->setKey(['module_row_id', 'service_id', 'domain'], 'unique', 'uniq_webnic_orders_scope_intent')
            // The 3.3 CAS claim scans WHERE next_poll_at <= now.
            ->setKey(['next_poll_at'], 'index', 'idx_webnic_orders_next_poll_at')
            ->create('webnic_orders', true);
    }

    /**
     * Migrates the WN-3-1 webnic_orders unique key to the scoped WN-3-2 shape.
     *
     * Existing 1.1.0 installs already have webnic_orders, so create(..., true) will not
     * update the old UNIQUE(service_id, domain) key. The saga uses a sentinel service id
     * before Blesta has inserted the service row, so the idempotency key must include
     * module_row_id to preserve INV-1 scoping across module rows.
     */
    private function ensureWebnicOrdersScopedUniqueKey(): void
    {
        $scoped_key = 'uniq_webnic_orders_scope_intent';
        $old_key = $this->findWebnicOrdersIntentKey(['service_id', 'domain']);
        $has_scoped_key = $this->findWebnicOrdersIntentKey(['module_row_id', 'service_id', 'domain']) !== null;

        if ($has_scoped_key) {
            return;
        }

        if ($old_key !== null) {
            $this->Record->query(
                'ALTER TABLE `webnic_orders` DROP INDEX `' . str_replace('`', '``', $old_key) . '`'
            );
        }

        $this->Record->query(
            'ALTER TABLE `webnic_orders` '
            . 'ADD UNIQUE KEY `' . $scoped_key . '` (`module_row_id`, `service_id`, `domain`)'
        );
    }

    /**
     * Finds a unique webnic_orders index whose ordered columns exactly match $columns.
     *
     * @param array $columns The ordered column names to match
     * @return string|null The matching key name, or null when absent
     */
    private function findWebnicOrdersIntentKey(array $columns)
    {
        $rows = $this->Record->query(
            'SELECT INDEX_NAME AS key_name, COLUMN_NAME AS column_name, SEQ_IN_INDEX AS seq_in_index '
            . 'FROM information_schema.statistics '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 '
            . 'ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            ['webnic_orders']
        )->fetchAll();

        $keys = [];
        foreach ($rows as $row) {
            if ($row->key_name === 'PRIMARY') {
                continue;
            }
            $keys[$row->key_name][(int) $row->seq_in_index] = $row->column_name;
        }

        foreach ($keys as $key => $key_columns) {
            ksort($key_columns);
            if (array_values($key_columns) === $columns) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Builds the webnic_contacts table (WN-3-2).
     *
     * The reusable contact-handle cache (FR14/AR16): ONE durable row per
     * (module_row_id, client_id) holding the four WebNIC contact handles plus the
     * shared registrant account id, so a client's handles are looked up and reused
     * across registrations instead of re-minted on every order. Both install() and
     * the upgrade() 1.2.0 guard call THIS one helper, so the fresh-install and
     * upgrade schemas are byte-identical by construction (NFR11/AR22 — the parity
     * harness proves it). Idempotent: create('webnic_contacts', true) =>
     * CREATE TABLE IF NOT EXISTS, a re-run no-op.
     *
     * The handle column names mirror the four register roles (registrant/admin/
     * technical/billing) and registrant_user_id; the WebNIC field semantics behind
     * them live in config/webnic.php `contact_map` (AR21 single source). The
     * UNIQUE(module_row_id, client_id) key is the reuse key (INV-1): reuse is
     * per-client and never crosses clients (architecture L564-565). datetimes are
     * UTC gmdate (never native TIMESTAMP), utf8mb4 via the Blesta Record default.
     */
    private function createWebnicContactsTable()
    {
        $this->Record
            ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
            ->setField('module_row_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('client_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('registrant_handle', ['type' => 'varchar', 'size' => 64, 'is_null' => true, 'default' => null])
            ->setField('admin_handle', ['type' => 'varchar', 'size' => 64, 'is_null' => true, 'default' => null])
            ->setField('technical_handle', ['type' => 'varchar', 'size' => 64, 'is_null' => true, 'default' => null])
            ->setField('billing_handle', ['type' => 'varchar', 'size' => 64, 'is_null' => true, 'default' => null])
            ->setField('registrant_user_id', ['type' => 'varchar', 'size' => 64, 'is_null' => true, 'default' => null])
            ->setField('created', ['type' => 'datetime'])
            ->setField('updated', ['type' => 'datetime', 'is_null' => true, 'default' => null])
            ->setKey(['id'], 'primary')
            // The reuse key (INV-1): one handle-cache row per (module row, client).
            ->setKey(['module_row_id', 'client_id'], 'unique')
            ->create('webnic_contacts', true);
    }

    /**
     * Performs migration of data from $current_version (the current installed version)
     * to the given file set version. Sets Input errors on failure, preventing
     * the module from being upgraded.
     *
     * @param string $current_version The current installed version of this module
     */
    public function upgrade($current_version)
    {
        // Outer guard (mirror namesilo): only act when the file-set version is newer.
        if (version_compare($this->getVersion(), $current_version, '>')) {
            if (!isset($this->Record)) {
                Loader::loadComponents($this, ['Record']);
            }

            // webnic_orders lands at 1.1.0 (WN-3-1). Version-guarded + existence-checked
            // (create('webnic_orders', true)), so a re-run is a no-op (NFR11). Calls the
            // SAME builder as install() => the two schemas are byte-identical (AR22). No
            // .sql file, nothing under components/upgrades/ (module schema lives here).
            if (version_compare($current_version, '1.1.0', '<')) {
                $this->createWebnicOrdersTable();
            }

            // webnic_contacts lands at 1.2.0 (WN-3-2). Same version-guard + existence
            // check pattern: calls the SAME builder as install() so the two schemas are
            // byte-identical (AR22), and create('webnic_contacts', true) makes a re-run
            // a no-op (NFR11). No .sql file, nothing under components/upgrades/.
            if (version_compare($current_version, '1.2.0', '<')) {
                $this->ensureWebnicOrdersScopedUniqueKey();
                $this->createWebnicContactsTable();
            }

            // 1.3.0 (WN-4-1, transfer-in) adds NO schema: the transfer_id column landed at 1.1.0
            // (createWebnicOrdersTable), and the reconcile/saga changes are code-only. So there is
            // deliberately no `< 1.3.0` guard here — a 1.2.0 -> 1.3.0 upgrade is a clean no-op.

            // 1.4.0 (WN-4-2, resend verification email) adds NO schema: resend touches no table
            // (it opens no order and runs no transition — NFR5). The version bump records the new
            // resendTransferEmail registrar capability + the first client service tab, but a
            // 1.3.0 -> 1.4.0 upgrade is a deliberate clean no-op (no `< 1.4.0` guard).

            // 1.5.0 (WN-4-3, renew a domain) adds NO schema: renew touches no table (the
            // expiration_date service field already exists and the reconciler writes it today; renew
            // opens no order and runs no transition — NFR5). The bump records the new renewService +
            // renewDomain registrar capabilities, but a 1.4.0 -> 1.5.0 upgrade is a clean no-op.

            // 1.6.0 (WN-4-5, suspend/unsuspend a domain) adds NO schema: suspend touches no table,
            // opens no order, and runs no transition (NFR5). T0 chose Path B local-only hooks, so a
            // 1.5.0 -> 1.6.0 upgrade is a deliberate clean no-op (no `< 1.6.0` guard).

            // 1.7.0 (WN-4-6, cancel/change-package) adds NO schema: cancel/change-package touch no
            // table, open no order, and run no transition (NFR5). Clean no-op, no `< 1.7.0` guard.

            // 1.8.0 (WN-4-4, restore) adds NO schema: restore touches no table, opens no order,
            // and runs no transition (NFR5). The bump records the new restoreDomain capability and
            // service surfaces only; clean no-op, no `< 1.8.0` guard.

            // 1.9.0 (WN-5-1, service-info summary + array-driven tab registry) adds NO schema: it is a
            // read + presentation story (one info() read for the active summary, the tab registry, the
            // new partials/language keys) — no table, no order, no transition (NFR5). The bump records
            // the FR36 summary + the C15 tab registry; a 1.8.0 -> 1.9.0 upgrade is a clean no-op.

            // 1.9.1 (WN-6-1, localization audit + email seam) adds NO schema: it seeds the FR42
            // lifecycle notification email_groups/emails rows so existing installs gain the email
            // surfaces the already-landed send seams (WN-3-4a/4-1/5-8a) call. The bump exists ONLY so
            // upgrade() fires for installs already at 1.9.0; seedLifecycleEmails() is idempotent
            // (getByAction guard), so a re-run never duplicates or overwrites operator copy.
            if (version_compare($current_version, '1.9.1', '<')) {
                $this->seedLifecycleEmails();
            }

            // Do NOT call addCronTasks() on upgrade: cron is registered in install() from
            // 1.0.0, so there is no pre-cron version to backfill, and addTaskRun() bare-
            // inserts into cron_task_runs (no unique key) — each call would leak a
            // duplicate task-run row (AR14). A later version that CHANGES the cron task
            // reconciles it INSIDE that version's own guard (mirror namesilo's `< 3.4.1`).
        }
    }

    /**
     * Performs any necessary cleanup actions
     *
     * @param int $module_id The ID of the module being uninstalled
     * @param bool $last_instance True if $module_id is the last instance across
     *  all companies for this module, false otherwise
     */
    public function uninstall($module_id, $last_instance)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }
        Loader::loadModels($this, ['CronTasks']);

        // Drop the module-owned shared tables only on the last instance. They are
        // keyed by module_row_id / service_id (shared tables, not per-company physical
        // tables), so an unconditional drop would erase them for every other company
        // still running WebNIC (AC#3). Record->drop() is wrapped per table so one
        // missing table can't abort the rest. Story 3.2 (webnic_contacts) adds its drop
        // inside this same block.
        if ($last_instance) {
            try {
                $this->Record->drop('webnic_tokens');
            } catch (\Throwable $e) {
                // Table absent or no permissions — safe to ignore.
            }

            try {
                $this->Record->drop('webnic_orders');
            } catch (\Throwable $e) {
                // Table absent or no permissions — safe to ignore.
            }

            try {
                $this->Record->drop('webnic_contacts');
            } catch (\Throwable $e) {
                // Table absent or no permissions — safe to ignore.
            }

            // Remove the WN-6-1 lifecycle email groups (and their per-company `emails`
            // rows, which EmailGroups->delete() cascades) only on the last instance —
            // the groups are shared/global, so another company still running WebNIC
            // must keep them. A per-company uninstall leaves this company's `emails`
            // rows in place (harmless: send() filters by company_id); they clear with
            // the company. Best-effort, mirroring the table-drop try/catch above.
            try {
                Loader::loadModels($this, ['EmailGroups']);
                foreach ((array) Configure::get('Webnic.install.emails') as $email) {
                    if (empty($email['action'])) {
                        continue;
                    }
                    $group = $this->EmailGroups->getByAction($email['action']);
                    if ($group) {
                        $this->EmailGroups->delete($group->id);
                    }
                }
            } catch (\Throwable $e) {
                // Email group absent or no permissions — safe to ignore.
            }
        }

        $cron_tasks = $this->getCronTasks();

        // The cron task is shared/global: remove it only on the last instance.
        if ($last_instance) {
            foreach ($cron_tasks as $task) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $this->CronTasks->deleteTask($cron_task->id, $task['task_type'], $task['dir']);
                }
            }
        } else {
            // The cron task run is per-company. Delete only this company's run(s)
            // without letting CronTasks::deleteTaskRun() remove the shared task when
            // no other task runs currently exist.
            foreach ($cron_tasks as $task) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $this->Record->from('cron_task_runs')
                        ->where('task_id', '=', $cron_task->id)
                        ->where('company_id', '=', Configure::get('Blesta.company_id'))
                        ->delete();
                }
            }
        }
    }

    /**
     * Prunes the row-scoped cached bearer token when a module row is deleted.
     *
     * Blesta passes the row before deleting it; the orphaned token is otherwise
     * a harmless short-TTL re-mintable cache entry, but pruning keeps the table
     * clean (INV-1).
     *
     * @param stdClass $module_row The stdClass representation of the existing module row
     */
    public function deleteModuleRow($module_row)
    {
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_token_store.php');

        $store = new Webnic\TokenStore();
        $store->delete($module_row->id);
    }

    /**
     * Runs the cron task identified by the key used to create the cron task
     *
     * @param string $key The key used to create the cron task
     * @see CronTasks::add()
     */
    public function cron($key)
    {
        if ($key == 'reconcile_orders') {
            $this->reconcileOrders();
        }
    }

    /**
     * Dispatches the reconcile_orders cron to Webnic\Reconciler (AC1/AC8).
     *
     * Builds the reconciler with a scrub+write INV-9 sink and runs it for the
     * current company. The cron path sets NO Input errors (INV-4, no user present):
     * a run-level failure is logged, never surfaced.
     */
    private function reconcileOrders(): void
    {
        $this->loadReconcilerDependencies();

        // Named arguments: logger/clock/alerter/confirmer are positionally adjacent,
        // same-type (callable) params, so a positional call is easy to transpose (the 3rd
        // arg is the alerter, not the clock). Naming them keeps a future caller correct (P14).
        $reconciler = new \Webnic\Reconciler(
            logger: function (array $record): void {
                $this->logReconcile($record);
            },
            clock: null,
            alerter: function (array $alert): void {
                $this->alertOperator($alert);
            },
            confirmer: function (array $notice): void {
                $this->confirmRegistration($notice);
            }
        );

        try {
            $reconciler->run(Configure::get('Blesta.company_id'));
        } catch (\Throwable $e) {
            $this->logReconcile([
                'run_id' => null,
                'command' => 'exception',
                'error_class' => 'indeterminate',
                'message' => get_class($e) . ': ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * Pushes the operator alert when an order terminally fails (AC3 — the concrete sink).
     *
     * AC3 requires a PUSH at the moment of failure, not a list the operator must visit.
     * Recommended mechanism (Dev Notes §G, confirm in review): a staff alert email via
     * Blesta Emails using the 'Webnic.operator_order_failed' action (template/group owned
     * by Story 6.1). Until that template is registered the push DEGRADES to an
     * error-level module log — never a fatal in the cron. The durable refund-eligible
     * record (the failed row + the INV-9 give-up record) is separate and unchanged; this
     * only adds the push on top. The payload carries no secrets (no webnic_order_id).
     *
     * @param array $alert The alert payload from the reconciler (reason/domain/detail/...)
     */
    private function alertOperator(array $alert): void
    {
        $pushed = false;

        try {
            $company_id = Configure::get('Blesta.company_id');
            $recipient = $this->operatorAlertRecipient($company_id);

            if (!empty($company_id) && !empty($recipient)) {
                Loader::loadModels($this, ['Emails']);
                $tags = [
                    'domain' => $alert['domain'] ?? null,
                    'domain_html' => htmlspecialchars((string) ($alert['domain'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    'reason' => $alert['reason'] ?? null,
                    'detail' => $alert['detail'] ?? null,
                    'reason_html' => htmlspecialchars((string) ($alert['reason'] ?? ''), ENT_QUOTES, 'UTF-8'),
                    'detail_html' => htmlspecialchars((string) ($alert['detail'] ?? ''), ENT_QUOTES, 'UTF-8'),
                ];
                $this->Emails->send('Webnic.operator_order_failed', $company_id, null, $recipient, $tags);
                $pushed = empty($this->Emails->errors());
            }
        } catch (\Throwable $e) {
            $pushed = false;
        }

        // Degraded path (no template/recipient yet, or send failed): an ERROR-level
        // module log so the terminal failure is never silently dropped (AC3 fallback).
        if (!$pushed) {
            $this->logReconcile([
                'level' => 'error',
                // Carry the reconciler's run_id so the degraded alert correlates with the
                // terminal-failure INV-9 record from the same run (AC6 trace).
                'run_id' => $alert['run_id'] ?? null,
                'module_row_id' => $alert['module_row_id'] ?? null,
                'service_id' => $alert['service_id'] ?? null,
                'domain' => $alert['domain'] ?? null,
                'command' => 'operator-alert',
                'error_class' => 'terminal',
                'message' => 'push_degraded_to_log; reason=' . ($alert['reason'] ?? '') . '; ' . ($alert['detail'] ?? ''),
            ]);
        }
    }

    /**
     * Fires the AC7 registration-confirmed-after-pending client notice (the concrete sink).
     *
     * Resolves the real Blesta service by-domain (the order is keyed by the sentinel
     * service_id=0, Dev Notes §B) and dispatches the client email through the shared
     * dispatchClientNotice seam. Degrades to a non-fatal log when no service resolves.
     *
     * @param array $notice The notice payload from the reconciler (module_id/domain/...)
     */
    private function confirmRegistration(array $notice): void
    {
        // WN-4-1 AC5: a transfer that resolves to active fires the transfer-completed notice
        // ("we'll email you when it completes"), NOT the register-confirmed copy. 4.1 owns only the
        // firing seam; the template/tag/en_us copy is Story 6.1 (a launch gate, C12). A working stub
        // notice key is acceptable now. The whois-privacy re-apply is register-only (it persisted the
        // id_protection intent at register time); transfer has no such pre-persisted intent.
        $is_transfer = (($notice['operation'] ?? \Webnic\Orders::OPERATION_REGISTER) === \Webnic\Orders::OPERATION_TRANSFER);
        $notice_key = $is_transfer ? 'transfer_completed' : 'registration_confirmed_after_pending';

        try {
            $service = $this->resolveServiceByDomain(
                (int) ($notice['module_id'] ?? 0),
                (int) ($notice['module_row_id'] ?? 0),
                (string) ($notice['domain'] ?? '')
            );

            if ($service === null) {
                $this->logNotice($notice_key, ['domain' => $notice['domain'] ?? null], 'no_service');

                return;
            }

            if (!$is_transfer) {
                $row = $this->getModuleRow($notice['module_row_id'] ?? null);
                $this->applyPersistedWhoisPrivacy($service, $row, (string) ($notice['domain'] ?? ''));
            }
            $this->dispatchClientNotice($notice_key, $service, ['domain' => $notice['domain'] ?? null]);
        } catch (\Throwable $e) {
            $this->logNotice($notice_key, ['domain' => $notice['domain'] ?? null], 'dispatch_error');
        }
    }

    /**
     * Resolves the live Blesta service for a domain within a module row (by-domain, INV-1).
     *
     * @param int $module_id The owning module id
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param string $domain The order domain
     * @return stdClass|null The resolved service, or null when none matches in scope
     */
    private function resolveServiceByDomain($module_id, $module_row_id, string $domain)
    {
        if ((int) $module_id <= 0 || $domain === '') {
            return null;
        }
        if (!isset($this->Services)) {
            Loader::loadModels($this, ['Services']);
        }

        $services = $this->Services->searchServiceFields((int) $module_id, 'domain', $domain);
        if (!is_array($services)) {
            return null;
        }

        foreach ($services as $service) {
            if ((int) ($service->module_row_id ?? 0) !== (int) $module_row_id) {
                continue;
            }
            // Skip a stale cancelled service for the same domain so a re-registration's
            // confirmation can't be addressed to a dead service (P10). The AC7 notice
            // carries no client_id, so a non-cancelled status is the disambiguator here —
            // mirroring the reconciler's own resolveService().
            if (in_array($service->status ?? '', ['canceled', 'cancelled'], true)) {
                continue;
            }

            return $service;
        }

        return null;
    }

    /**
     * Resolves a best-effort operator alert recipient (admin staff email).
     *
     * Story 6.1 will register a proper staff alert group; until then this resolves the
     * company's admin/staff contact so a push can be attempted. Returns null when none
     * can be resolved (the caller then degrades to an error-level log).
     *
     * @param int|null $company_id The company whose operator to alert
     * @return string|null The recipient email, or null when unresolved
     */
    private function operatorAlertRecipient($company_id): ?string
    {
        try {
            if (empty($company_id)) {
                return null;
            }
            Loader::loadModels($this, ['Staff']);
            $staff = method_exists($this->Staff, 'getList') ? $this->Staff->getList($company_id, 'active') : null;
            if (is_array($staff)) {
                foreach ($staff as $member) {
                    if (!empty($member->email)) {
                        return (string) $member->email;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Fall through to null — the caller degrades to an error-level log.
        }

        return null;
    }

    /**
     * Loads the apis/ + lib/ classes the reconciler and date hooks depend on.
     */
    private function loadReconcilerDependencies(): void
    {
        Loader::load(__DIR__ . DS . 'apis' . DS . 'redactor.php');
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_token_store.php');
        Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_api.php');
        Loader::load(__DIR__ . DS . 'apis' . DS . 'webnic_status.php');
        Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_domains.php');
        Loader::load(__DIR__ . DS . 'apis' . DS . 'commands' . DS . 'webnic_transfers.php');
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_orders.php');
        Loader::load(__DIR__ . DS . 'lib' . DS . 'webnic_reconciler.php');
    }

    /**
     * Writes a scrubbed INV-9 reconciliation record to the Blesta module log.
     *
     * Mirrors logSaga(): the Redactor scrubs secrets (webnic_order_id, token) and
     * the success flag follows the structured level (only `error` logs as failure).
     * Logging must never interrupt the cron run.
     *
     * @param array $record The structured reconciliation record
     */
    private function logReconcile(array $record): void
    {
        try {
            $scrubbed = class_exists('\Webnic\Support\Redactor')
                ? \Webnic\Support\Redactor::scrub($record)
                : $record;
            $success = ($record['level'] ?? 'info') !== 'error';
            $this->log('cron/reconcile_orders', serialize($scrubbed), 'output', $success);
        } catch (\Throwable $e) {
            // Logging must never interrupt the cron run.
        }
    }

    /**
     * Retrieves cron tasks available to this module along with their default values
     *
     * @return array A list of cron tasks
     */
    private function getCronTasks()
    {
        return [
            [
                'key' => 'reconcile_orders',
                'task_type' => 'module',
                'dir' => 'webnic',
                'name' => Language::_('Webnic.getCronTasks.reconcile_orders_name', true),
                'description' => Language::_('Webnic.getCronTasks.reconcile_orders_desc', true),
                'type' => 'interval',
                'type_value' => 5, // minutes; tuned in Story 3.3
                'enabled' => 1
            ]
        ];
    }

    /**
     * Attempts to add new cron tasks for this module
     *
     * @param array $tasks A list of cron tasks to add
     * @return bool True if every task and company run exists, false otherwise
     */
    private function addCronTasks(array $tasks)
    {
        Loader::loadModels($this, ['CronTasks']);
        foreach ($tasks as $task) {
            $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
            $task_id = $cron_task ? $cron_task->id : $this->CronTasks->add($task);

            if (!$task_id) {
                if (($errors = $this->CronTasks->errors())) {
                    $this->Input->setErrors($errors);
                }

                return false;
            }

            if ($this->CronTasks->getTaskRunByKey($task['key'], $task['dir'], false, $task['task_type'])) {
                continue;
            }

            $task_vars = ['enabled' => $task['enabled']];
            if ($task['type'] === 'time') {
                $task_vars['time'] = $task['type_value'];
            } else {
                $task_vars['interval'] = $task['type_value'];
            }

            if (!$this->CronTasks->addTaskRun($task_id, $task_vars)) {
                if (($errors = $this->CronTasks->errors())) {
                    $this->Input->setErrors($errors);
                }

                return false;
            }
        }

        return true;
    }
}

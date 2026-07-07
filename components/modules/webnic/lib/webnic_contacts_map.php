<?php
/**
 * WebNIC reusable contact-handle cache + pure contact normalization (WN-3-2).
 *
 * Owns the `webnic_contacts` table: ONE durable row per (module_row_id, client_id)
 * holding the four WebNIC contact handles and the shared registrant account id, so
 * a client's handles are looked up and reused across registrations instead of
 * re-minted on every order (FR14). The pure mapClientToContact() mapper translates
 * a Blesta client/contact into the WebNIC `contact_map` field set (config/webnic.php,
 * AR21) with no I/O, so it is unit-testable with no DB and no Blesta bootstrap.
 *
 * Reuse is per-client and NEVER crosses clients: every read/write is scoped by both
 * module_row_id and client_id (INV-1), backed by UNIQUE(module_row_id, client_id).
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
namespace Webnic;

class ContactsMap
{
    /**
     * @var string Company fallback for registry contacts with no company.
     *
     * WebNIC `company` is REQUIRED (min 1) even for an individual; an empty value
     * is a DOM4000 field-validation reject (config/webnic.php contact_map). This is
     * a registry data value sent to WebNIC, not a user-facing UI string, so it is a
     * constant here rather than a language key — the same convention the bundled
     * logicboxes registrar uses ('Not Applicable').
     */
    public const INDIVIDUAL_COMPANY_FALLBACK = 'Not Applicable';

    /**
     * @var int WebNIC `company` max length (config/webnic.php contact_map).
     */
    private const COMPANY_MAX = 80;

    /**
     * @var array ISO-3166 alpha-2 -> E.164 calling code (digits, no '+').
     *
     * Blesta's `countries` table has no calling code, so a bare local phone number
     * (no "+CC" prefix) derives its calling code from the contact's country here.
     * NANP members (US/CA/Caribbean) all map to '1'.
     */
    private const CALLING_CODES = [
        'AD' => '376', 'AE' => '971', 'AF' => '93', 'AG' => '1', 'AI' => '1',
        'AL' => '355', 'AM' => '374', 'AO' => '244', 'AR' => '54', 'AS' => '1',
        'AT' => '43', 'AU' => '61', 'AW' => '297', 'AX' => '358', 'AZ' => '994',
        'BA' => '387', 'BB' => '1', 'BD' => '880', 'BE' => '32', 'BF' => '226',
        'BG' => '359', 'BH' => '973', 'BI' => '257', 'BJ' => '229', 'BL' => '590',
        'BM' => '1', 'BN' => '673', 'BO' => '591', 'BQ' => '599', 'BR' => '55',
        'BS' => '1', 'BT' => '975', 'BW' => '267', 'BY' => '375', 'BZ' => '501',
        'CA' => '1', 'CC' => '61', 'CD' => '243', 'CF' => '236', 'CG' => '242',
        'CH' => '41', 'CI' => '225', 'CK' => '682', 'CL' => '56', 'CM' => '237',
        'CN' => '86', 'CO' => '57', 'CR' => '506', 'CU' => '53', 'CV' => '238',
        'CW' => '599', 'CX' => '61', 'CY' => '357', 'CZ' => '420', 'DE' => '49',
        'DJ' => '253', 'DK' => '45', 'DM' => '1', 'DO' => '1', 'DZ' => '213',
        'EC' => '593', 'EE' => '372', 'EG' => '20', 'EH' => '212', 'ER' => '291',
        'ES' => '34', 'ET' => '251', 'FI' => '358', 'FJ' => '679', 'FK' => '500',
        'FM' => '691', 'FO' => '298', 'FR' => '33', 'GA' => '241', 'GB' => '44',
        'GD' => '1', 'GE' => '995', 'GF' => '594', 'GG' => '44', 'GH' => '233',
        'GI' => '350', 'GL' => '299', 'GM' => '220', 'GN' => '224', 'GP' => '590',
        'GQ' => '240', 'GR' => '30', 'GT' => '502', 'GU' => '1', 'GW' => '245',
        'GY' => '592', 'HK' => '852', 'HN' => '504', 'HR' => '385', 'HT' => '509',
        'HU' => '36', 'ID' => '62', 'IE' => '353', 'IL' => '972', 'IM' => '44',
        'IN' => '91', 'IO' => '246', 'IQ' => '964', 'IR' => '98', 'IS' => '354',
        'IT' => '39', 'JE' => '44', 'JM' => '1', 'JO' => '962', 'JP' => '81',
        'KE' => '254', 'KG' => '996', 'KH' => '855', 'KI' => '686', 'KM' => '269',
        'KN' => '1', 'KP' => '850', 'KR' => '82', 'KW' => '965', 'KY' => '1',
        'KZ' => '7', 'LA' => '856', 'LB' => '961', 'LC' => '1', 'LI' => '423',
        'LK' => '94', 'LR' => '231', 'LS' => '266', 'LT' => '370', 'LU' => '352',
        'LV' => '371', 'LY' => '218', 'MA' => '212', 'MC' => '377', 'MD' => '373',
        'ME' => '382', 'MF' => '590', 'MG' => '261', 'MH' => '692', 'MK' => '389',
        'ML' => '223', 'MM' => '95', 'MN' => '976', 'MO' => '853', 'MP' => '1',
        'MQ' => '596', 'MR' => '222', 'MS' => '1', 'MT' => '356', 'MU' => '230',
        'MV' => '960', 'MW' => '265', 'MX' => '52', 'MY' => '60', 'MZ' => '258',
        'NA' => '264', 'NC' => '687', 'NE' => '227', 'NF' => '672', 'NG' => '234',
        'NI' => '505', 'NL' => '31', 'NO' => '47', 'NP' => '977', 'NR' => '674',
        'NU' => '683', 'NZ' => '64', 'OM' => '968', 'PA' => '507', 'PE' => '51',
        'PF' => '689', 'PG' => '675', 'PH' => '63', 'PK' => '92', 'PL' => '48',
        'PM' => '508', 'PR' => '1', 'PS' => '970', 'PT' => '351', 'PW' => '680',
        'PY' => '595', 'QA' => '974', 'RE' => '262', 'RO' => '40', 'RS' => '381',
        'RU' => '7', 'RW' => '250', 'SA' => '966', 'SB' => '677', 'SC' => '248',
        'SD' => '249', 'SE' => '46', 'SG' => '65', 'SH' => '290', 'SI' => '386',
        'SJ' => '47', 'SK' => '421', 'SL' => '232', 'SM' => '378', 'SN' => '221',
        'SO' => '252', 'SR' => '597', 'SS' => '211', 'ST' => '239', 'SV' => '503',
        'SX' => '1', 'SY' => '963', 'SZ' => '268', 'TC' => '1', 'TD' => '235',
        'TG' => '228', 'TH' => '66', 'TJ' => '992', 'TK' => '690', 'TL' => '670',
        'TM' => '993', 'TN' => '216', 'TO' => '676', 'TR' => '90', 'TT' => '1',
        'TV' => '688', 'TW' => '886', 'TZ' => '255', 'UA' => '380', 'UG' => '256',
        'US' => '1', 'UY' => '598', 'UZ' => '998', 'VA' => '39', 'VC' => '1',
        'VE' => '58', 'VG' => '1', 'VI' => '1', 'VN' => '84', 'VU' => '678',
        'WF' => '681', 'WS' => '685', 'XK' => '383', 'YE' => '967', 'YT' => '262',
        'ZA' => '27', 'ZM' => '260', 'ZW' => '263',
    ];

    /**
     * @var array The columns store() is allowed to write (INV-1: never id/keys here).
     */
    private const HANDLE_COLUMNS = [
        'registrant_handle',
        'admin_handle',
        'technical_handle',
        'billing_handle',
        'registrant_user_id',
    ];

    /**
     * Initializes the Record-backed contact-handle cache.
     */
    public function __construct()
    {
        \Loader::loadComponents($this, ['Record']);
    }

    /**
     * Looks up a client's cached contact handles within a module row (FR14/INV-1).
     *
     * Deterministic lookup-before-create: the saga calls this first and reuses the
     * stored handles instead of re-minting. The (module_row_id, client_id) scope is
     * mandatory — reuse must never cross clients or module rows.
     *
     * @param int $client_id The Blesta client id
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @return \stdClass|null The cached row, or null when none exists in this scope
     */
    public function findByClient($client_id, $module_row_id)
    {
        $row = $this->Record->select()
            ->from('webnic_contacts')
            ->where('module_row_id', '=', $module_row_id)
            ->where('client_id', '=', $client_id)
            ->fetch();

        return $row ?: null;
    }

    /**
     * Upserts a client's contact handles, keyed UNIQUE(module_row_id, client_id).
     *
     * SELECT-then-act upsert (not a blind INSERT … ON DUPLICATE KEY): the first call
     * inserts a fresh row; a later call merges only the supplied handle columns and
     * stamps `updated`, preserving prior handles. Reuse MUST NOT cross clients, so
     * the write is always scoped by both keys. Only the HANDLE_COLUMNS allowlist is
     * writable — id/module_row_id/client_id/created are owned by this method.
     *
     * @param int $client_id The Blesta client id
     * @param int $module_row_id The owning module row (INV-1 scope)
     * @param array $fields A subset of the four handles + registrant_user_id
     * @return \stdClass The stored row (freshly read)
     */
    public function store($client_id, $module_row_id, array $fields)
    {
        $values = array_intersect_key($fields, array_flip(self::HANDLE_COLUMNS));
        $now = gmdate('Y-m-d H:i:s');

        $existing = $this->findByClient($client_id, $module_row_id);
        if ($existing !== null) {
            return $this->mergeIntoExisting($client_id, $module_row_id, $existing, $values, $now);
        }

        $values['module_row_id'] = $module_row_id;
        $values['client_id'] = $client_id;
        $values['created'] = $now;
        try {
            $this->Record->insert('webnic_contacts', $values);
        } catch (\PDOException $e) {
            if (!$this->isDuplicateKeyException($e)) {
                throw $e;
            }

            if (method_exists($this->Record, 'reset')) {
                $this->Record->reset();
            }

            $existing = $this->findByClient($client_id, $module_row_id);
            if ($existing === null) {
                throw $e;
            }

            return $this->mergeIntoExisting($client_id, $module_row_id, $existing, $fields, $now);
        }

        return $this->findByClient($client_id, $module_row_id);
    }

    /**
     * Merges supplied handles into an existing scoped row without overwriting winners.
     *
     * @param int $client_id The Blesta client id
     * @param int $module_row_id The owning module row
     * @param \stdClass $existing The existing scoped row
     * @param array $fields Allowed handle fields to merge
     * @param string $now UTC timestamp
     * @return \stdClass The stored row
     */
    private function mergeIntoExisting($client_id, $module_row_id, $existing, array $fields, string $now)
    {
        $values = [];
        foreach (array_intersect_key($fields, array_flip(self::HANDLE_COLUMNS)) as $field => $value) {
            if (($existing->{$field} ?? null) === null || $existing->{$field} === '') {
                $values[$field] = $value;
            }
        }

        if (!empty($values)) {
            $values['updated'] = $now;
            $this->Record->where('module_row_id', '=', $module_row_id)
                ->where('client_id', '=', $client_id)
                ->update('webnic_contacts', $values);
        }

        return $this->findByClient($client_id, $module_row_id);
    }

    /**
     * Identifies a MySQL duplicate-key error from a concurrent insert race.
     *
     * @param \PDOException $e The caught PDO exception
     * @return bool True iff the exception represents a duplicate key
     */
    private function isDuplicateKeyException(\PDOException $e): bool
    {
        if (isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
            return true;
        }

        return strpos($e->getMessage(), '1062') !== false
            && strpos($e->getMessage(), 'Duplicate') !== false;
    }

    /**
     * Maps a Blesta client/contact to the WebNIC contact_map field set (pure).
     *
     * No I/O — no Record, no Language, no time() — so the FR14 normalization is
     * unit-testable with no Blesta bootstrap. Output keys are exactly the
     * config/webnic.php `contact_map` keys (AR21); the caller nests this under a role
     * ({registrant|administrator|technical|billing}) for POST contact/create.
     *
     * Conservative by design (gate1 accepted-unknowns #4): this mapper always EMITS the
     * `individual` category. WN-3-6 confirmed on the wire that WebNIC also ACCEPTS an
     * `organization` category, a non-MY country + free-text state, and alternate "+CC.number"
     * phone shapes (contact_create_org / contact_create_gb) — so those are verified-accepted,
     * not unknown; emitting `organization` is a deliberately deferred PRODUCT choice (not a gap),
     * to be made when org/company contacts are productised. `company` falls back to a non-empty
     * placeholder because an empty value is a register-time per-TLD reject (WN-3-6: empty company
     * is accepted at contact/create, the min-1 rule is register-time); phone is passed through in
     * the verified "+CC.number" shape; customFields is an empty MAP (per-TLD requirements are
     * Story 3.4b). It MUST serialize as a JSON object `{}`, never an array `[]` —
     * WebNIC's deserializer rejects `[]` here with DOM4002 "Unparseable JSON input"
     * (captured live on OTE 2026-06-16); PHP `json_encode([])` is `[]`, so an empty
     * PHP array would be wrong — cast to object.
     *
     * @param array $source Normalized client/contact source. Recognized keys:
     *  first_name, last_name, company, address1, address2, city, state, zip, country
     *  (ISO-3166 alpha-2), email, phone ("+CC.number"), or phone_cc + phone_number,
     *  fax.
     * @return array The WebNIC contact_map field set
     */
    public static function mapClientToContact(array $source): array
    {
        return [
            'category' => 'individual',
            'company' => self::normalizeCompany($source['company'] ?? ''),
            'firstName' => self::str($source, 'first_name'),
            'lastName' => self::str($source, 'last_name'),
            'address1' => self::str($source, 'address1'),
            'address2' => self::nullableStr($source, 'address2'),
            'city' => self::str($source, 'city'),
            'state' => self::str($source, 'state'),
            'zip' => self::str($source, 'zip'),
            'countryCode' => strtoupper(self::str($source, 'country')),
            'phoneNumber' => self::normalizePhone($source),
            'faxNumber' => self::nullableStr($source, 'fax'),
            'email' => self::str($source, 'email'),
            // Empty MAP -> JSON `{}` (NOT `[]`); WebNIC rejects an array with DOM4002.
            'customFields' => (object) [],
        ];
    }

    /**
     * Formats a country-calling-code + number into the observed "+CC.number" shape.
     *
     * Pure helper the saga glue uses when Blesta exposes the calling code and the
     * subscriber number separately. Non-digits in each part are stripped; an empty
     * code or number yields an empty string (surfaced upstream as a required-field
     * reject rather than a malformed wire value).
     *
     * @param string $calling_code The country calling code (digits, no '+')
     * @param string $number The subscriber number
     * @return string "+CC.number", or '' when either part is empty
     */
    public static function formatPhone($calling_code, $number): string
    {
        $cc = preg_replace('/\D+/', '', (string) $calling_code);
        $digits = preg_replace('/\D+/', '', (string) $number);

        if ($cc === '' || $digits === '') {
            return '';
        }

        return '+' . $cc . '.' . $digits;
    }

    /**
     * Resolves the phone into WebNIC's strict "+CC.<digits>" shape (DOM4000 trap).
     *
     * WebNIC's contact/create accepts ONLY "+<callingcode>.<digits>" with NO spaces
     * or dashes — captured live on OTE 2026-06-21: "+92.300 2465967" is rejected
     * (DOM4000 "Field validation error") while "+92.3002465967" is accepted. The
     * WHMCS->Blesta import left phones in mixed shapes: ~54% as "+CC.number" (often
     * with spaces/dashes) and ~46% as bare local numbers with no calling code at
     * all. Both were previously passed through verbatim, so contact/create — and
     * thus every registration — failed for imported clients. Coerce here:
     *  - an international "+CC.number" form: split on the FIRST dot and let
     *    formatPhone() strip every non-digit from each part;
     *  - otherwise build "+CC.<digits>" from an explicit phone_cc, or the calling
     *    code derived from the contact's ISO country when the number carries none.
     *
     * @param array $source The contact source
     * @return string The "+CC.<digits>" phone, or '' when no usable value exists
     */
    private static function normalizePhone(array $source): string
    {
        $phone = trim((string) ($source['phone'] ?? ''));

        // International "+CC.number" (the dominant import shape): split on the first
        // dot so formatPhone() can strip the spaces/dashes WebNIC rejects.
        if ($phone !== '' && $phone[0] === '+' && strpos($phone, '.') !== false) {
            [$cc, $number] = explode('.', substr($phone, 1), 2);
            $formatted = self::formatPhone($cc, $number);
            if ($formatted !== '') {
                return $formatted;
            }
        }

        // Otherwise resolve a calling code: an explicit part first, else one derived
        // from the contact's ISO country (a bare local number carries none, and
        // WebNIC requires it).
        $cc = trim((string) ($source['phone_cc'] ?? ''));
        if ($cc === '') {
            $cc = self::callingCodeForCountry((string) ($source['country'] ?? ''));
        }

        $number = $phone !== '' ? $phone : trim((string) ($source['phone_number'] ?? ''));

        // A leading "+CC" without a dot (or an import that concatenated CC+number)
        // would otherwise double the calling code — drop one leading copy when the
        // value was written in international ("+") form and already begins with it.
        $digits = preg_replace('/\D+/', '', $number);
        if ($cc !== '' && $number !== '' && $number[0] === '+'
            && $digits !== '' && strpos($digits, $cc) === 0
        ) {
            $number = substr($digits, strlen($cc));
        }

        return self::formatPhone($cc, $number);
    }

    /**
     * Maps an ISO-3166 alpha-2 country to its E.164 calling code (digits, no '+').
     *
     * Blesta's `countries` table carries no calling code, so a bare local phone
     * number (no "+CC" prefix) has its calling code derived here from the contact's
     * country. Returns '' for an unknown/blank country, which surfaces upstream as
     * an empty phone (a required-field reject) rather than a malformed WebNIC call.
     *
     * @param string $country ISO-3166 alpha-2 country code
     * @return string The calling code digits, or '' when unknown
     */
    private static function callingCodeForCountry(string $country): string
    {
        $code = strtoupper(trim($country));

        return self::CALLING_CODES[$code] ?? '';
    }

    /**
     * Coerces company to a non-empty value within the WebNIC length bound.
     *
     * @param string $company The raw company value
     * @return string A non-empty company, max COMPANY_MAX chars
     */
    private static function normalizeCompany($company): string
    {
        $company = trim((string) $company);
        if ($company === '') {
            $company = self::INDIVIDUAL_COMPANY_FALLBACK;
        }

        return self::truncate($company, self::COMPANY_MAX);
    }

    /**
     * Reads a trimmed string field, defaulting to ''.
     *
     * @param array $source The source array
     * @param string $key The key to read
     * @return string The trimmed value or ''
     */
    private static function str(array $source, string $key): string
    {
        return trim((string) ($source[$key] ?? ''));
    }

    /**
     * Reads a trimmed string field, mapping an empty value to null (observed nulls).
     *
     * @param array $source The source array
     * @param string $key The key to read
     * @return string|null The trimmed value or null when empty
     */
    private static function nullableStr(array $source, string $key)
    {
        $value = self::str($source, $key);

        return $value === '' ? null : $value;
    }

    /**
     * Truncates a multibyte string to a maximum length without splitting a char.
     *
     * @param string $value The value to truncate
     * @param int $max The maximum length
     * @return string The truncated value
     */
    private static function truncate(string $value, int $max): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $max);
        }

        return substr($value, 0, $max);
    }
}

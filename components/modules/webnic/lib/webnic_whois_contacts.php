<?php
/**
 * Pure WHOIS/contact normalization helpers for WebNIC management tabs (WN-5-2).
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
namespace Webnic;

require_once __DIR__ . DIRECTORY_SEPARATOR . 'webnic_contacts_map.php';

class WhoisContacts
{
    public const ROLES = ['registrant', 'admin', 'tech', 'billing'];

    private const INFO_CONTACT_KEYS = [
        'registrant' => 'registrant',
        'admin' => 'admin',
        'tech' => 'technical',
        'billing' => 'billing',
    ];

    private const EDITABLE_DETAIL_KEYS = [
        'first_name' => 'firstName',
        'last_name' => 'lastName',
        'organization' => 'company',
        'address1' => 'address1',
        'address2' => 'address2',
        'city' => 'city',
        'state' => 'state',
        'zip' => 'zip',
        'country' => 'countryCode',
        'phone' => 'phoneNumber',
        'fax' => 'faxNumber',
        'email' => 'email',
    ];

    /**
     * Extracts the current domain-attached contact handles from info().data.
     *
     * @param array $info_data WebNIC domain info data
     * @return array role => contact id|null
     */
    public static function contactIdsFromInfo(array $info_data): array
    {
        $raw = isset($info_data['contactId']) && is_array($info_data['contactId'])
            ? $info_data['contactId']
            : [];
        $ids = [];

        foreach (self::INFO_CONTACT_KEYS as $role => $provider_key) {
            $value = trim((string)($raw[$provider_key] ?? ''));
            $ids[$role] = $value === '' ? null : $value;
        }

        return $ids;
    }

    /**
     * Normalizes a contact/query payload to the Blesta registrar contact shape.
     *
     * @param string $role One of registrant|admin|tech|billing
     * @param string|null $attached_contact_id The handle attached to the current domain role
     * @param array $provider_contact WebNIC contact/query data
     * @return array Normalized role contact
     */
    public static function normalizeProviderContact(string $role, $attached_contact_id, array $provider_contact): array
    {
        $details = isset($provider_contact['details']) && is_array($provider_contact['details'])
            ? $provider_contact['details']
            : [];
        $provider_id = trim((string)($provider_contact['contactId'] ?? ''));
        $external_id = $provider_id !== '' ? $provider_id : ($attached_contact_id === null ? null : (string) $attached_contact_id);

        return [
            'role' => $role,
            'external_id' => $external_id,
            'first_name' => self::str($details, 'firstName'),
            'last_name' => self::str($details, 'lastName'),
            'organization' => self::str($details, 'company'),
            'address1' => self::str($details, 'address1'),
            'address2' => self::nullableStr($details, 'address2'),
            'city' => self::str($details, 'city'),
            'state' => self::str($details, 'state'),
            'zip' => self::str($details, 'zip'),
            'country' => strtoupper(self::str($details, 'countryCode')),
            'email' => self::str($details, 'email'),
            'phone' => self::str($details, 'phoneNumber'),
            'fax' => self::nullableStr($details, 'faxNumber'),
            'editable' => $attached_contact_id !== null && $provider_id !== '' && $provider_id === (string) $attached_contact_id,
            'provider_metadata' => [
                'contact_type' => self::str($provider_contact, 'contactType'),
            ],
        ];
    }

    /**
     * Maps submitted Blesta registrar fields to WebNIC contact details.
     *
     * @param array $source Submitted contact fields
     * @return array WebNIC contact details payload
     */
    public static function blestaContactToDetails(array $source): array
    {
        return ContactsMap::mapClientToContact([
            'first_name' => self::str($source, 'first_name'),
            'last_name' => self::str($source, 'last_name'),
            'company' => self::str($source, 'organization'),
            'address1' => self::str($source, 'address1'),
            'address2' => self::nullableStr($source, 'address2'),
            'city' => self::str($source, 'city'),
            'state' => self::str($source, 'state'),
            'zip' => self::str($source, 'zip'),
            'country' => strtoupper(self::str($source, 'country')),
            'phone' => self::str($source, 'phone'),
            'fax' => self::nullableStr($source, 'fax'),
            'email' => self::str($source, 'email'),
        ]);
    }

    /**
     * Merges submitted editable fields into the freshly queried provider details.
     *
     * @param array $provider_contact WebNIC contact/query data
     * @param array $source Submitted contact fields
     * @return array Full WebNIC details payload for Modify Contact
     */
    public static function mergeEditableDetails(array $provider_contact, array $source): array
    {
        $details = isset($provider_contact['details']) && is_array($provider_contact['details'])
            ? $provider_contact['details']
            : [];
        $mapped = self::blestaContactToDetails($source);

        foreach (self::submittedEditableFields($source) as $field) {
            $provider_key = self::EDITABLE_DETAIL_KEYS[$field] ?? null;
            if ($provider_key === null) {
                continue;
            }
            $details[$provider_key] = $mapped[$provider_key];
        }

        if (array_key_exists('customFields', $details) && is_array($details['customFields']) && empty($details['customFields'])) {
            $details['customFields'] = (object) [];
        }

        return $details;
    }

    /**
     * Compares a contact/query payload against details prepared for modify.
     *
     * @param array $provider_contact WebNIC contact/query data
     * @param array $details WebNIC details payload
     * @return bool True when at least one submitted detail differs
     */
    public static function detailsChanged(array $provider_contact, array $details): bool
    {
        $current = isset($provider_contact['details']) && is_array($provider_contact['details'])
            ? $provider_contact['details']
            : [];

        foreach ($details as $key => $value) {
            $current_value = $current[$key] ?? null;
            if (self::normalizeComparable($current_value) !== self::normalizeComparable($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns whether a role is one of the four supported registrar contact roles.
     *
     * @param string $role Role value
     * @return bool
     */
    public static function validRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    private static function normalizeComparable($value)
    {
        if (is_object($value) && get_object_vars($value) === []) {
            return [];
        }
        if (is_array($value) && empty($value)) {
            return [];
        }
        if ($value === null) {
            return null;
        }

        return is_string($value) ? trim($value) : $value;
    }

    private static function submittedEditableFields(array $source): array
    {
        if (isset($source['_submitted_fields']) && is_array($source['_submitted_fields'])) {
            $fields = $source['_submitted_fields'];
        } else {
            $fields = array_keys($source);
        }

        $allowed = array_flip(array_keys(self::EDITABLE_DETAIL_KEYS));
        $submitted = [];
        foreach ($fields as $field) {
            $field = (string) $field;
            if (isset($allowed[$field])) {
                $submitted[] = $field;
            }
        }

        return array_values(array_unique($submitted));
    }

    private static function str(array $source, string $key): string
    {
        return trim((string)($source[$key] ?? ''));
    }

    private static function nullableStr(array $source, string $key)
    {
        $value = self::str($source, $key);

        return $value === '' ? null : $value;
    }
}

<?php
/**
 * WebNIC token cache store.
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
namespace Webnic;

class TokenStore
{
    /**
     * Initializes the Record-backed token store.
     */
    public function __construct()
    {
        \Loader::loadComponents($this, ['Record']);
    }

    /**
     * Gets the cached token row for a module row.
     *
     * @param int $module_row_id Module row id
     * @return array|null Cached token data
     */
    public function get($module_row_id)
    {
        $row = $this->Record->select(['token', 'expires_at'])
            ->from('webnic_tokens')
            ->where('module_row_id', '=', $module_row_id)
            ->fetch();

        if (!$row) {
            return null;
        }

        return [
            'token' => $row->token,
            'expires_at' => $this->parseExpiresAt($row->expires_at),
        ];
    }

    /**
     * Atomically saves a module row's cached token.
     *
     * @param int $module_row_id Module row id
     * @param string $token Bearer token
     * @param int $expires_at Unix epoch expiration time
     */
    public function save($module_row_id, $token, $expires_at)
    {
        $vars = [
            'module_row_id' => $module_row_id,
            'token' => $token,
            'expires_at' => gmdate('Y-m-d H:i:s', (int)$expires_at),
        ];

        $this->Record
            ->duplicate('token', '=', $vars['token'])
            ->duplicate('expires_at', '=', $vars['expires_at'])
            ->insert('webnic_tokens', $vars, array_keys($vars));
    }

    /**
     * Deletes a module row's cached token.
     *
     * @param int $module_row_id Module row id
     */
    public function delete($module_row_id)
    {
        $this->Record->from('webnic_tokens')
            ->where('module_row_id', '=', $module_row_id)
            ->delete();
    }

    /**
     * Determines whether a cached token is inside the proactive refresh window.
     *
     * @param int $expires_at Unix epoch expiration time
     * @param int $now Unix epoch current time
     * @param int $margin Refresh margin in seconds
     * @return bool True when the token should be refreshed
     */
    public static function needsRefresh($expires_at, $now, $margin): bool
    {
        return (int)$now >= ((int)$expires_at - (int)$margin);
    }

    /**
     * Parses a UTC DATETIME into a unix timestamp.
     *
     * @param string $expires_at UTC DATETIME from MySQL
     * @return int Unix epoch expiration time
     */
    private function parseExpiresAt($expires_at): int
    {
        $timestamp = strtotime($expires_at . ' UTC');

        return $timestamp === false ? 0 : $timestamp;
    }
}

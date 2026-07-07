<?php
/**
 * WebNIC service-tab registry (WN-5-1, AC3/AC4 — the C15 Epic-4-retro carry-forward).
 *
 * The single, data-driven tab-composition pattern for the module. Before 5.1 the
 * tab strip was a hand-rolled if/return chain inside getAdminServiceTabs /
 * getClientServiceTabs (WN-3-4a recovery, WN-4-2 resend, WN-4-4 restore, WN-4-6
 * delete + cancelled/grace precedence). This collaborator absorbs all of it into
 * one ordered descriptor manifest so Stories 5.2-5.8 register their parity tab by
 * adding/enabling a data entry — never by editing a shared method body.
 *
 * It is PURE by construction: resolve() takes a pre-computed $ctx (the module owns
 * every DB/registry read and passes only booleans + a has_method probe) and returns
 * the Blesta tab map. No I/O, no Configure, no Record — fully unit-testable without a
 * Blesta bootstrap (Language::_ is the only collaborator, faked in the test bootstrap).
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
namespace Webnic;

/**
 * Ordered, side-aware service-tab descriptor manifest + composer.
 */
class TabRegistry
{
    /**
     * Returns the ordered tab descriptor manifest.
     *
     * The 7 LogicBoxes-parity slots come first in the canonical UX-DR15 order
     * (WHOIS/contacts · nameservers · child nameservers · DNS records · forwarding ·
     * DNSSEC · settings), gated by `parity` so an unbuilt 5.2-5.8 method renders no
     * dead link. The conditional lifecycle slots follow, each carrying the exact
     * predicate the hand-rolled chain used; their predicates are mutually exclusive by
     * construction, and Delete is declared last so an active strip reads
     * [parity…, Delete]. recovery/resend/restore never co-occur with parity because
     * they fire only in non-active states (parity_active === false).
     *
     * Each descriptor:
     *   - id          stable identifier (tests/ordering only)
     *   - sides       'admin' | 'client' | 'both'
     *   - admin_key   the admin tab method name (or null)
     *   - client_key  the client tab method name (or null)
     *   - label_key   the Language key for the tab title
     *   - icon        the client-side Font Awesome icon (parity tabs only)
     *   - parity      true => gated by has_method($key) so unbuilt slots never render
     *   - visible     fn(array $ctx): bool — whether this slot shows for $ctx
     *
     * Parity method names + FA icons mirror logicboxes.php (tabWhois/tabClientWhois …)
     * so 5.2-5.8 implement the parity contract the rest of the WebNIC plan tracks.
     *
     * @return array The ordered descriptor list
     */
    public static function descriptors(): array
    {
        $whenActive = function (array $ctx) {
            return !empty($ctx['parity_active']);
        };
        $whenWhoisActive = function (array $ctx) {
            return array_key_exists('whois_active', $ctx)
                ? !empty($ctx['whois_active'])
                : !empty($ctx['parity_active']);
        };

        return [
            // ── 7 always-on parity slots (canonical UX-DR15 order, both sides) ──
            self::parity('whois', 'tabWhois', 'tabClientWhois', 'Webnic.tab_whois.title', 'fas fa-users', $whenWhoisActive),
            self::parity('nameservers', 'tabNameservers', 'tabClientNameservers', 'Webnic.tab_nameservers.title', 'fas fa-server', $whenActive),
            self::parity('childnameservers', 'tabChildNameservers', 'tabClientChildNameservers', 'Webnic.tab_childnameservers.title', 'fas fa-clone', $whenActive),
            self::parity('dnsrecords', 'tabDnsRecords', 'tabClientDnsRecords', 'Webnic.tab_dnsrecords.title', 'fas fa-sitemap', $whenActive),
            self::parity('forwarding', 'tabForwarder', 'tabClientForwarder', 'Webnic.tab_forwarding.title', 'fas fa-reply', $whenActive),
            self::parity('dnssec', 'tabDnssec', 'tabClientDnssec', 'Webnic.tab_dnssec.title', 'fas fa-globe-americas', $whenActive),
            self::parity('settings', 'tabSettings', 'tabClientSettings', 'Webnic.tab_settings.title', 'fas fa-cog', $whenActive),

            // ── Conditional lifecycle slots (absorbed hand-rolled branches) ──
            // WN-3-4a Recovery — admin-only, only on a `failed` order.
            [
                'id' => 'recovery',
                'sides' => 'admin',
                'admin_key' => 'tabRecovery',
                'client_key' => null,
                'label_key' => 'Webnic.tab_recovery.title',
                'icon' => null,
                'parity' => false,
                'visible' => function (array $ctx) {
                    return !empty($ctx['recovery_eligible']);
                },
            ],
            // WN-4-2 Resend — both surfaces, only while registrar_pending (transfer- OR register-pending).
            [
                'id' => 'resend',
                'sides' => 'both',
                'admin_key' => 'tabResendVerification',
                'client_key' => 'tabResendVerification',
                'label_key' => 'Webnic.tab_resend.title',
                'icon' => null, // flat key => title on the client (preserves WN-4-2 shape)
                'parity' => false,
                'visible' => function (array $ctx) {
                    return !empty($ctx['resend_eligible']);
                },
            ],
            // WN-4-4 Restore — admin-only, only on a grace/redemption domain.
            [
                'id' => 'restore',
                'sides' => 'admin',
                'admin_key' => 'tabRestore',
                'client_key' => null,
                'label_key' => 'Webnic.tab_restore.title',
                'icon' => null,
                'parity' => false,
                'visible' => function (array $ctx) {
                    return !empty($ctx['restore_eligible']);
                },
            ],
            // WN-4-6 Delete — admin-only, only on an active/manageable domain (co-exists with parity, declared last).
            [
                'id' => 'delete',
                'sides' => 'admin',
                'admin_key' => 'tabDelete',
                'client_key' => null,
                'label_key' => 'Webnic.tab_delete.title',
                'icon' => null,
                'parity' => false,
                'visible' => function (array $ctx) {
                    return !empty($ctx['delete_eligible']);
                },
            ],
        ];
    }

    /**
     * Composes the Blesta tab map for one surface from the manifest + context.
     *
     * Iterates the descriptors in declared order, keeping each whose `sides` matches
     * the requested side, whose parity method exists (parity slots only), and whose
     * `visible($ctx)` predicate is true. Admin tabs are a flat `key => title` map;
     * client tabs carry the `['name' => …, 'icon' => …]` shape when an icon is declared
     * (parity), otherwise the flat shape (resend).
     *
     * @param string $side 'admin' or 'client'
     * @param array $ctx Pre-computed gating context (booleans + a has_method callable)
     * @return array The ordered tab map for the requested surface
     */
    public static function resolve(string $side, array $ctx): array
    {
        $tabs = [];
        $has_method = $ctx['has_method'] ?? null;

        foreach (self::descriptors() as $descriptor) {
            if (!self::sideMatches($descriptor['sides'], $side)) {
                continue;
            }

            $key = $side === 'admin' ? $descriptor['admin_key'] : $descriptor['client_key'];
            if ($key === null || $key === '') {
                continue;
            }

            // AC4: a parity slot whose implementing method does not yet exist renders no dead link.
            if (!empty($descriptor['parity']) && (!is_callable($has_method) || !$has_method($key))) {
                continue;
            }

            $visible = $descriptor['visible'];
            if (!$visible($ctx)) {
                continue;
            }

            $label = \Language::_($descriptor['label_key'], true);
            if ($side === 'client' && !empty($descriptor['icon'])) {
                $tabs[$key] = ['name' => $label, 'icon' => $descriptor['icon']];
            } else {
                $tabs[$key] = $label;
            }
        }

        return $tabs;
    }

    /**
     * Builds a parity-slot descriptor (both sides, method-gated, icon-bearing).
     *
     * @param string $id Stable identifier
     * @param string $admin_key Admin tab method name
     * @param string $client_key Client tab method name
     * @param string $label_key Language key for the title
     * @param string $icon Client-side Font Awesome icon
     * @param callable $visible fn(array $ctx): bool
     * @return array The descriptor
     */
    private static function parity(string $id, string $admin_key, string $client_key, string $label_key, string $icon, callable $visible): array
    {
        return [
            'id' => $id,
            'sides' => 'both',
            'admin_key' => $admin_key,
            'client_key' => $client_key,
            'label_key' => $label_key,
            'icon' => $icon,
            'parity' => true,
            'visible' => $visible,
        ];
    }

    /**
     * Whether a descriptor's `sides` value applies to the requested surface.
     *
     * @param string $sides 'admin' | 'client' | 'both'
     * @param string $side The requested surface
     * @return bool
     */
    private static function sideMatches(string $sides, string $side): bool
    {
        return $sides === 'both' || $sides === $side;
    }
}

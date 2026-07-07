<?php
/**
 * WebNIC lifecycle status lexicon.
 *
 * @package blesta
 * @subpackage blesta.components.modules.webnic
 * @copyright Copyright (c) 2026, HOSTERPK
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */

/**
 * Pure status vocabulary and registry-status mapper for WebNIC lifecycle badges.
 *
 * DESIGN.md names Font Awesome glyphs as nominal intent, but Blesta's shipped
 * Paradigm theme uses Bootstrap Icons. The presentation map therefore uses
 * `bi-*` glyphs that preserve the same meaning, especially pending as a clock
 * and never a check.
 *
 * The live `suspended` flag is handled before the registry status string because
 * it is a present-tense FR25 signal and should override any mapped bucket.
 * Unknown or invalid registry values fall back to pending and never active, so a
 * provider-side state drift cannot masquerade as a working registration.
 *
 * HEURISTIC - read before tightening. The `expired`/`redemption_grace` ->
 * suspended and `pending_delete` -> cancelled cells are reasoned from the Gate-1
 * proposal, not from an observed Blesta render contract. The current five-alias
 * vocabulary has no dedicated expired or redemption bucket; refine the visible
 * label later if Epic 5 captures a more specific service-info contract.
 */
class WebnicStatus
{
    /**
     * @var string Normal, working lifecycle state.
     */
    public const ACTIVE = 'active';

    /**
     * @var string In-progress lifecycle state.
     */
    public const PENDING = 'pending';

    /**
     * @var string Failed lifecycle state.
     */
    public const FAILED = 'failed';

    /**
     * @var string Cancelled or terminal lifecycle state.
     */
    public const CANCELLED = 'cancelled';

    /**
     * @var string Suspended or lapsed-but-restorable lifecycle state.
     */
    public const SUSPENDED = 'suspended';

    /**
     * @var string Language key for the default in-progress pending banner copy.
     */
    public const BANNER_KEY_DEFAULT = 'Webnic.service_info.pending_banner';

    /**
     * @var string Language key for the age-softened pending banner copy.
     */
    public const BANNER_KEY_SOFTENED = 'Webnic.service_info.pending_banner_softened';

    /**
     * @var array Canonical lifecycle aliases in stable presentation order.
     */
    public const ALIASES = [
        self::ACTIVE,
        self::PENDING,
        self::FAILED,
        self::CANCELLED,
        self::SUSPENDED,
    ];

    /**
     * @var array Badge presentation for each lifecycle alias.
     */
    public const PRESENTATION = [
        self::ACTIVE => [
            'class' => 'text-bg-success',
            'icon' => 'bi-check-circle-fill',
            'label_key' => 'Webnic.status.active',
        ],
        self::PENDING => [
            'class' => 'text-bg-warning',
            'icon' => 'bi-clock-history',
            'label_key' => 'Webnic.status.pending',
        ],
        self::FAILED => [
            'class' => 'text-bg-danger',
            'icon' => 'bi-x-circle-fill',
            'label_key' => 'Webnic.status.failed',
        ],
        self::CANCELLED => [
            'class' => 'text-bg-secondary',
            'icon' => 'bi-ban',
            'label_key' => 'Webnic.status.cancelled',
        ],
        self::SUSPENDED => [
            'class' => 'border border-danger text-danger',
            'icon' => 'bi-pause-circle',
            'label_key' => 'Webnic.status.suspended',
        ],
    ];

    /**
     * Maps a WebNIC registry status string to the canonical lifecycle alias.
     *
     * Decision order:
     *  (a) suspended flag                      -> suspended
     *  (b) configured Webnic.status_map alias  -> configured alias
     *  (c) unknown / invalid mapped alias      -> pending, never active
     *
     * @param string $status WebNIC `info.status` enum value
     * @param bool $suspended WebNIC `info.suspended` flag
     * @return string One of self::ALIASES
     */
    public static function fromRegistryStatus(string $status, bool $suspended = false): string
    {
        if ($suspended) {
            return self::SUSPENDED;
        }

        $map = Configure::get('Webnic.status_map');
        $alias = is_array($map) ? ($map[$status] ?? null) : null;

        return in_array($alias, self::ALIASES, true) ? $alias : self::PENDING;
    }

    /**
     * Maps a `webnic_orders.state` saga state to the rendered lifecycle alias (3.4a).
     *
     * The saga state machine (Webnic\Orders::STATE_*) has 9 in-flight/terminal values;
     * the rendered badge lexicon has 5 aliases. This is the bridge the service-info UI
     * uses so an order row drives the badge. The mapping (by Orders::STATE_* literal):
     *  - intent/contacts_done/registrant_done/hosts_done/submitted/registrar_pending
     *                       -> PENDING (every in-flight state reads as "in progress")
     *  - active             -> ACTIVE
     *  - failed             -> FAILED
     *  - cancelled          -> CANCELLED
     *  - anything else      -> PENDING (same fail-safe as fromRegistryStatus: a drifted
     *                          or corrupt state NEVER masquerades as a working domain).
     *
     * Pure by construction: literal state strings (mirroring Webnic\Orders::STATE_*,
     * NOT imported, to avoid an apis<->lib cycle), no Configure, no time(). The 5-alias
     * SUSPENDED bucket is registry-derived (fromRegistryStatus); no saga state yields it.
     *
     * @param string $state One of Webnic\Orders::STATE_* values
     * @return string One of self::ALIASES
     */
    public static function fromOrderState(string $state): string
    {
        switch ($state) {
            case 'active':
                return self::ACTIVE;
            case 'failed':
                return self::FAILED;
            case 'cancelled':
                return self::CANCELLED;
            case 'intent':
            case 'contacts_done':
            case 'registrant_done':
            case 'hosts_done':
            case 'submitted':
            case 'registrar_pending':
                return self::PENDING;
            default:
                // Unknown/drifted state: fail safe to in-progress, never active.
                return self::PENDING;
        }
    }

    /**
     * Pure pending-gate predicate (AC2/UX-DR11.2, Dev Notes §E) — the forward-binding
     * contract Epic 4/5 lifecycle tabs MUST consume to decide whether to BLOCK their
     * controls (block, not queue) while a register order is still in flight.
     *
     * True iff the order state renders as PENDING. A blocked tab renders
     * `tab_unavailable.pdt` with the `Webnic.service_info.lifecycle_blocked` reason
     * instead of its controls. Pure: delegates to fromOrderState(), no I/O.
     *
     * @param string $order_state One of Webnic\Orders::STATE_* values
     * @return bool True iff lifecycle controls must be blocked for this state
     */
    public static function blocksLifecycle(string $order_state): bool
    {
        return self::fromOrderState($order_state) === self::PENDING;
    }

    /**
     * Selects the pending-banner copy key by the order's age (AC2/UX-DR4, Dev Notes §F).
     *
     * A *reassurance window*, not a countdown: while a registration is in flight the
     * banner reads "this usually completes within a few minutes"; once the order has
     * been pending at least $soften_after_seconds it softens to "taking longer than
     * usual — still queued, no action needed." Neither copy implies failure or an ETA.
     *
     * Pure with an INJECTED `$now` (no time() — WN-3-3 §K precedent), so the impure
     * service-info caller passes gmdate('Y-m-d H:i:s') + the configured threshold. An
     * unreadable created/now or clock-skew (now before created) fails safe to the
     * gentler default copy rather than prematurely claiming a delay.
     *
     * @param string $created_utc The order `created` UTC `Y-m-d H:i:s`
     * @param string $now_utc The current UTC `Y-m-d H:i:s` (injected)
     * @param int $soften_after_seconds The reassurance-window threshold in seconds
     * @return string self::BANNER_KEY_DEFAULT before the threshold, else BANNER_KEY_SOFTENED
     */
    public static function pendingBannerKey(string $created_utc, string $now_utc, int $soften_after_seconds): string
    {
        $created_ts = self::epoch($created_utc);
        $now_ts = self::epoch($now_utc);
        if ($created_ts === null || $now_ts === null) {
            return self::BANNER_KEY_DEFAULT;
        }

        $age = $now_ts - $created_ts;
        if ($age >= $soften_after_seconds && $soften_after_seconds > 0) {
            return self::BANNER_KEY_SOFTENED;
        }

        return self::BANNER_KEY_DEFAULT;
    }

    /**
     * Returns badge presentation metadata for a lifecycle alias.
     *
     * @param string $alias One of self::ALIASES
     * @return array Badge class, Bootstrap Icon glyph, and language key
     */
    public static function presentation(string $alias): array
    {
        return self::PRESENTATION[$alias] ?? self::PRESENTATION[self::PENDING];
    }

    /**
     * Parses a UTC `Y-m-d H:i:s` (no embedded zone) into a unix timestamp (pure).
     *
     * @param string $utc The UTC datetime (zone-free)
     * @return int|null The unix timestamp, or null when unparseable/blank
     */
    private static function epoch(string $utc): ?int
    {
        $utc = trim($utc);
        if ($utc === '') {
            return null;
        }

        $ts = strtotime($utc . ' UTC');

        return $ts === false ? null : $ts;
    }
}

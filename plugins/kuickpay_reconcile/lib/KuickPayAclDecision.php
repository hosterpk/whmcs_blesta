<?php
/**
 * Pure ACL decision for the KuickPay admin controllers.
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile.lib
 */
class KuickPayAclDecision
{
    /**
     * Decides whether a staff group's access list explicitly allows an action.
     *
     * MinPHP ACL treats the `*` action as a wildcard for arbitrary actions, so a
     * controller cannot rely on Acl::check() to distinguish a page-level grant
     * from a fine-grained one. This inspects the RAW access-list entries and
     * requires an EXACT action match set to 'allow' — so a `*`-only staff group
     * is denied a fine-grained action (recheck/review/cancel/diagnostics) it was
     * never explicitly granted, while still confirming the page-level `*` grant.
     *
     * @param array $accessList Raw access-list rows (stdClass with action/permission)
     * @param string $action The exact ACL action token to require
     * @return bool True only when an exact-action row is set to 'allow'
     */
    public static function allows(array $accessList, string $action): bool
    {
        foreach ($accessList as $access) {
            if ((($access->action ?? null)) !== $action) {
                continue;
            }

            return ($access->permission ?? null) === 'allow';
        }

        return false;
    }
}

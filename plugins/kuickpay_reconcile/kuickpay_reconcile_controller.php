<?php
/**
 * KuickPay Reconcile parent controller
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile
 */
class KuickpayReconcileController extends AppController
{
    /**
     * Setup
     */
    public function preAction()
    {
        $this->structure->setDefaultView(APPDIR);
        parent::preAction();

        Language::loadLang([Loader::fromCamelCase(get_class($this))], null, dirname(__FILE__) . DS . 'language' . DS);

        $this->view->view = 'default';
        $this->orig_structure_view = $this->structure->view;
        $this->structure->view = 'default';
    }

    /**
     * Checks the dedicated diagnostics permission without wildcard fallback.
     *
     * The plugin intentionally has both a general admin_vouchers `*` permission
     * for the page and a separate admin_vouchers `diagnostics` permission for
     * the sensitive section. MinPHP ACL treats `*` as a wildcard for arbitrary
     * actions, so this check inspects the current staff group's ACL entries and
     * requires an explicit diagnostics allow.
     *
     * @return bool True when the current staff group explicitly allows diagnostics
     */
    protected function canViewDiagnostics(): bool
    {
        return $this->staffGroupAllows('diagnostics');
    }

    /**
     * Checks a dedicated admin_vouchers permission without wildcard fallback.
     *
     * Shared by AdminVouchers (which owns the action endpoints) and the
     * read-only AdminManualReview/AdminReconciliation queues, which link to
     * those endpoints and must show the same gated "next allowed action".
     *
     * @param string $action The exact ACL action token
     * @return bool True when the current staff group explicitly allows the action
     */
    protected function staffGroupAllows(string $action): bool
    {
        return $this->staffGroupAllowsOn('kuickpay_reconcile.admin_vouchers', $action);
    }

    /**
     * Checks an explicit ACL action on a given plugin permission alias.
     *
     * Generalises staffGroupAllows() so a controller can assert its OWN
     * registered permission (e.g. admin_main's bulk_reconcile `*`) rather than
     * relying solely on the framework route gate (NFR14). The page-level grant
     * is the `*` action; fine-grained grants use their own action token. The
     * raw-access-list decision is delegated to KuickPayAclDecision so it is unit
     * testable without the live framework.
     *
     * @param string $alias The plugin permission alias (e.g. kuickpay_reconcile.admin_main)
     * @param string $action The exact ACL action token ('*' for the page grant)
     * @return bool True when the current staff group explicitly allows the action
     */
    protected function staffGroupAllowsOn(string $alias, string $action): bool
    {
        Loader::loadComponents($this, ['Acl']);
        Loader::loadModels($this, ['StaffGroups']);
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'KuickPayAclDecision.php');

        $staff_group = $this->StaffGroups->getStaffGroupByStaff(
            $this->Session->read('blesta_staff_id'),
            $this->company_id
        );
        if (!$staff_group) {
            return false;
        }

        $access_list = $this->Acl->getAccessList('staff_group_' . $staff_group->id, $alias);

        return KuickPayAclDecision::allows($access_list, $action);
    }

    /**
     * Enforces a controller's registered page-level permission explicitly.
     *
     * Defense in depth over the framework route gate (NFR14): a staff group
     * without the controller's `*` grant is bounced to the admin home rather
     * than relying on the route being gated upstream.
     *
     * @param string $alias The controller's plugin permission alias
     * @param string $denied_message_key The localized "access denied" key
     * @return bool True when the current staff group may render the page
     */
    protected function requirePagePermission(string $alias, string $denied_message_key): bool
    {
        if ($this->staffGroupAllowsOn($alias, '*')) {
            return true;
        }

        $this->flashMessage('error', Language::_($denied_message_key, true), null, false);
        $this->redirect($this->base_uri);
        return false;
    }

    /**
     * Enforces a read-only route surface.
     *
     * @param string $redirect_url URL to return to when a non-GET request arrives
     * @return bool True when the current request may render the page
     */
    protected function requireGetOnly(string $redirect_url): bool
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'GET') {
            return true;
        }

        $this->redirect($redirect_url);
        return false;
    }

    /**
     * Parses a positive integer route segment without accepting floats/scientific notation.
     *
     * @param mixed $value Route value
     * @param int $default Fallback value
     * @return int Positive integer route value, or the fallback
     */
    protected function positiveRouteInt($value, int $default = 0): int
    {
        $raw = (string) $value;
        if (!ctype_digit($raw) || $raw === '') {
            return $default;
        }

        $trimmed = ltrim($raw, '0');
        if ($trimmed === '') {
            return $default;
        }

        if (
            strlen($trimmed) > strlen((string) PHP_INT_MAX)
            || (
                strlen($trimmed) === strlen((string) PHP_INT_MAX)
                && strcmp($trimmed, (string) PHP_INT_MAX) > 0
            )
        ) {
            return $default;
        }

        $int = (int) $trimmed;

        return $int > 0 ? $int : $default;
    }
}

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
        Loader::loadComponents($this, ['Acl']);
        Loader::loadModels($this, ['StaffGroups']);

        $staff_group = $this->StaffGroups->getStaffGroupByStaff(
            $this->Session->read('blesta_staff_id'),
            $this->company_id
        );
        if (!$staff_group) {
            return false;
        }

        // ACL alias is always admin_vouchers — the recheck/review/cancel
        // permissions belong to admin_vouchers, not the calling controller.
        $access_list = $this->Acl->getAccessList(
            'staff_group_' . $staff_group->id,
            'kuickpay_reconcile.admin_vouchers'
        );
        foreach ($access_list as $access) {
            if (($access->action ?? null) !== $action) {
                continue;
            }

            return ($access->permission ?? null) === 'allow';
        }

        return false;
    }
}

<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/KuickPayAclDecision.php';

/**
 * AC3a (Story 5.5): the pure ACL decision the admin controllers enforce.
 *
 * Models the staff-group access list faithfully (the live framework Acl/Session
 * are not available under the harness, per NFR12) so the enforcement logic is
 * unit-tested: a `*`-only group is denied the fine-grained actions it was never
 * explicitly granted, and a missing permission denies the page-level `*` grant
 * (e.g. admin_main's bulk_reconcile).
 */
class KuickPayAclDecisionTest extends TestCase
{
    private function access(string $action, string $permission): stdClass
    {
        return (object) ['action' => $action, 'permission' => $permission];
    }

    public function testStarOnlyGroupIsDeniedFineGrainedActions()
    {
        // A staff group granted only the page-level `*` (view vouchers / run
        // bulk reconcile) must NOT inherit recheck/review/cancel/diagnostics.
        $starOnly = [$this->access('*', 'allow')];

        $this->assertTrue(KuickPayAclDecision::allows($starOnly, '*'));
        foreach (['recheck', 'review', 'cancel', 'diagnostics'] as $action) {
            $this->assertFalse(
                KuickPayAclDecision::allows($starOnly, $action),
                "`*`-only group must be denied $action"
            );
        }
    }

    public function testExplicitGrantAllowsThatActionOnly()
    {
        $list = [$this->access('*', 'allow'), $this->access('recheck', 'allow')];

        $this->assertTrue(KuickPayAclDecision::allows($list, 'recheck'));
        $this->assertFalse(KuickPayAclDecision::allows($list, 'cancel'));
    }

    public function testMissingPermissionDeniesThePageGrant()
    {
        // A group with NO admin_main grant has an empty access list for that
        // alias, so the bulk_reconcile page grant (`*`) is denied.
        $this->assertFalse(KuickPayAclDecision::allows([], '*'));
    }

    public function testExplicitDenyIsNotAnAllow()
    {
        $list = [$this->access('*', 'deny')];

        $this->assertFalse(KuickPayAclDecision::allows($list, '*'));
    }

    public function testGrantedPageActionAllowsBulkReconcile()
    {
        // admin_main's bulk_reconcile is the `*` page grant; a group that has it
        // is allowed to run().
        $list = [$this->access('*', 'allow')];

        $this->assertTrue(KuickPayAclDecision::allows($list, '*'));
    }
}

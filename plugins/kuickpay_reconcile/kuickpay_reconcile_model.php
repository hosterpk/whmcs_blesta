<?php
/**
 * KuickPay Reconcile parent model
 *
 * @package blesta
 * @subpackage blesta.plugins.kuickpay_reconcile
 * @copyright Copyright (c) 2010, Phillips Data, Inc.
 * @license http://www.blesta.com/license/ The Blesta License Agreement
 * @link http://www.blesta.com/ Blesta
 */
class KuickpayReconcileModel extends AppModel
{
    public function __construct()
    {
        parent::__construct();

        Loader::loadHelpers($this, ['Form']);

        // Auto load language for plugin models.
        Language::loadLang([Loader::fromCamelCase(get_class($this))], null, dirname(__FILE__) . DS . 'language' . DS);
    }

    /*
     * ------------------------------------------------------------------
     * Tenant-scoping convention (Story 5.5 / Epic 4 retro AI-8)
     * ------------------------------------------------------------------
     * Every kuickpay_reconcile query MUST carry the company scope. These
     * primitives make that scope UN-OMITTABLE: each takes a required
     * `int $companyId`, so a query that forgets the tenant is a PHP type
     * error at the call site, not a leak a reviewer has to catch.
     *
     * There are TWO classes of table, and they scope DIFFERENTLY:
     *
     *  1. DIRECTLY-SCOPED tables own a `company_id` column —
     *     `kuickpay_vouchers`, `kuickpay_reconciliation_runs`,
     *     `kuickpay_audit_events`, `kuickpay_reconcile_locks`. Use
     *     scopedSelect/scopedUpdate/scopedDelete/scopedInsert; they inject
     *     the `company_id = ?` predicate (or column) directly.
     *
     *  2. PARENT-SCOPED child tables have NO `company_id` column —
     *     `kuickpay_voucher_invoices` (parent `kuickpay_vouchers`, fk
     *     `voucher_id`) and `kuickpay_reconciliation_items` (parent
     *     `kuickpay_reconciliation_runs`, fk `run_id`). Use
     *     scopedChildSelect; it joins the child to its owning parent and
     *     filters on the PARENT's `company_id`. NEVER route these through
     *     scopedSelect/scopedInsert — `child.company_id` is a fatal SQL
     *     error, not a leak fix. Child INSERTs carry no company_id at all
     *     (the parent row owns the tenant); they stay direct inserts and
     *     are protected by the parent-scoped read/write around them.
     * ------------------------------------------------------------------
     */

    /**
     * Begins a company-scoped SELECT against a directly-scoped table.
     *
     * Returns the shared Record builder already carrying the mandatory
     * `company_id = ?` predicate; the caller chains further ->where()/->order()
     * and a terminal ->fetch()/->fetchAll(). Do NOT call for a parent-scoped
     * child table (use scopedChildSelect).
     *
     * @param string $table The directly-scoped table name
     * @param int $companyId The mandatory company scope
     * @return \Record The Record builder, scoped and ready to chain
     */
    protected function scopedSelect(string $table, int $companyId)
    {
        return $this->Record->select()
            ->from($table)
            ->where($table . '.company_id', '=', $companyId);
    }

    /**
     * Begins a company-scoped SELECT against a parent-scoped child table.
     *
     * The child table has no company_id of its own, so the scope is enforced
     * through an INNER JOIN to its owning parent and a predicate on the
     * PARENT's company_id. Selects `child.*` so the join columns never collide
     * with the child's own. The caller chains the child-side filter (e.g.
     * ->where("$childTable.voucher_id", '=', $id)) and a terminal fetch.
     *
     * @param string $childTable The parent-scoped child table
     * @param string $parentTable The owning parent table (carries company_id)
     * @param string $fkColumn The child column referencing $parentTable.id
     * @param int $companyId The mandatory company scope
     * @return \Record The Record builder, scoped and ready to chain
     */
    protected function scopedChildSelect(string $childTable, string $parentTable, string $fkColumn, int $companyId)
    {
        return $this->Record->select([$childTable . '.*'])
            ->from($childTable)
            ->innerJoin(
                $parentTable,
                $parentTable . '.id',
                '=',
                $childTable . '.' . $fkColumn,
                false
            )
            ->where($parentTable . '.company_id', '=', $companyId);
    }

    /**
     * Runs a company-scoped UPDATE against a directly-scoped table.
     *
     * The `company_id = ?` predicate is ALWAYS appended, so an UPDATE can never
     * cross tenants. Additional WHERE clauses are passed as ordered tuples
     * matching Record::where()'s signature, e.g. ['id', '=', $id] or
     * ['status', 'in', ['pending', 'retry']].
     *
     * @param string $table The directly-scoped table name
     * @param int $companyId The mandatory company scope
     * @param array $vars The field/value pairs to set
     * @param array $fields The allowlist of fields to update
     * @param array $where Extra WHERE clauses as Record::where() argument tuples
     * @return \PDOStatement The executed statement (use rowCount() for affected rows)
     */
    protected function scopedUpdate(string $table, int $companyId, array $vars, array $fields, array $where = [])
    {
        foreach ($where as $clause) {
            $this->Record->where(...$clause);
        }

        $this->Record->where('company_id', '=', $companyId);

        return $this->Record->update($table, $vars, $fields);
    }

    /**
     * Runs a company-scoped DELETE against a directly-scoped table.
     *
     * The `company_id = ?` predicate is ALWAYS appended. Additional WHERE
     * clauses are passed as ordered tuples matching Record::where()'s signature.
     *
     * @param string $table The directly-scoped table name
     * @param int $companyId The mandatory company scope
     * @param array $where Extra WHERE clauses as Record::where() argument tuples
     * @return \PDOStatement The executed statement (use rowCount() for affected rows)
     */
    protected function scopedDelete(string $table, int $companyId, array $where = [])
    {
        $this->Record->from($table);

        foreach ($where as $clause) {
            $this->Record->where(...$clause);
        }

        $this->Record->where('company_id', '=', $companyId);

        return $this->Record->delete();
    }

    /**
     * Runs a company-scoped INSERT into a directly-scoped table.
     *
     * `company_id` is INJECTED into the written row (and its field allowlist),
     * so an INSERT that omits the tenant is impossible to express. A caller that
     * smuggles a conflicting `company_id` in $vars is rejected outright rather
     * than silently overridden.
     *
     * @param string $table The directly-scoped table name
     * @param int $companyId The mandatory company scope
     * @param array $vars The field/value pairs to insert
     * @param array $fields The allowlist of fields to insert
     * @return \PDOStatement The executed statement (use lastInsertId() for the id)
     * @throws InvalidArgumentException When $vars carries a conflicting company_id
     */
    protected function scopedInsert(string $table, int $companyId, array $vars, array $fields)
    {
        if ($companyId < 1) {
            throw new InvalidArgumentException('scopedInsert requires a positive company_id for ' . $table);
        }

        if (array_key_exists('company_id', $vars) && (int) $vars['company_id'] !== $companyId) {
            throw new InvalidArgumentException('scopedInsert company_id mismatch for ' . $table);
        }

        $vars['company_id'] = $companyId;
        if (!in_array('company_id', $fields, true)) {
            $fields[] = 'company_id';
        }

        return $this->Record->insert($table, $vars, $fields);
    }
}

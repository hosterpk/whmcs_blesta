---
baseline_commit: f851a0e8
---

# Story 2.1: Create Durable Customer Voucher Records

Status: in-progress

## Story

As a customer paying an eligible invoice,
I want my KuickPay payment attempt stored durably,
so that page refreshes, retries, support checks, and reconciliation use the same payment reference.

> **What this story IS:** the durable persistence layer for KuickPay Vouchers — database schema, plugin models, repository and reference services, and gateway integration that creates or reuses a local Voucher record when a customer reaches the KuickPay payment page. The customer sees their Voucher reference (Consumer Number, amount, dates) for the first time.
>
> **What this story is NOT:** no SOAP call to KuickPay (Story 2.3 / 3.1), no parser consumption for payment decisions (Story 3.2), no configurable or customer-validated reference patterns (Story 2.2 — 2.1 ships only a minimal non-configurable deterministic reference plus the database-level uniqueness invariant), no amount-change gating or multi-invoice blocking (Story 2.4), no styled customer reference panel or copy action (Story 2.5), no instruction groups or status expectations (Story 2.6), no reconciliation or posting (Epic 3), no admin workbench (Epic 4). **Zero live payment mutation. No Blesta transaction creation.**

## Acceptance Criteria

_Reproduced verbatim from [Source: epics.md#Story 2.1, lines 421–437]._

**AC1 — Voucher persistence stores all required fields.**
**Given** an eligible invoice payment attempt starts
**When** the system prepares a KuickPay Voucher record
**Then** it stores company, gateway, client, invoice mapping, currency, amount, dates, status, Registration Number, Consumer Number, and diagnostic placeholders
**And** it creates only the Voucher and invoice-link persistence needed for customer payment attempts.

**AC2 — Reuse existing Pending Voucher, no duplicates.**
**Given** a Voucher record already exists for the same active invoice context
**When** the customer reloads or returns to the payment page
**Then** the system reuses the existing Pending Voucher
**And** it does not create a duplicate active Voucher.

## Non-Negotiables (read before any task)

1. **No live payment mutation, no Blesta transaction creation.** [Source: architecture.md Anti-Patterns lines ~650–662] This story creates local Voucher records only. No `Transactions->add`, `recordPayment`, `markPaid`, invoice status update, or any action that marks an invoice paid. The Voucher status stays `pending`.

2. **Gateway calls plugin service; plugin owns durable state.** [Source: architecture.md lines 519–526, 665–669] The gateway may call the plugin's reference service for Voucher create/reuse, but the gateway must NOT create or mutate reconciliation/posting state directly. All INSERT/UPDATE to `kuickpay_*` tables lives in plugin models/services.

3. **Schema-level uniqueness on company-scoped Registration Number and Consumer Number.** [Source: architecture.md lines 333–351, 529–539] The `kuickpay_vouchers` table MUST have unique keys on (`company_id`, `registration_number`) and (`company_id`, `consumer_number`). Duplicates must fail at the database layer. Do not rely solely on application-level checks. See Dev Notes → "AC2 idempotency strategy" for how deterministic references turn these keys into the active-context race guard.

4. **Amounts as normalized decimal strings, never PHP floats.** [Source: architecture.md Posting Contract lines 591–593; NFR13] Store amounts as `varchar(20)` or comparable string-safe type. All amount reads, writes, comparisons, and concatenations must treat amounts as strings. Do not use PHP float arithmetic (`+`, `-`, `*`, `/`, `==`, `<`, `>`) on amounts. Use `bccomp()` or string comparison of normalized forms if comparison is needed.

5. **Preserve Blesta extension boundaries: no core edits, no root `composer.json` changes, PHP 8.2 only.** [Source: project-context.md; 1.1 Non-Negotiable #3] Touch only `components/gateways/nonmerchant/kuickpay/*` and `plugins/kuickpay_reconcile/*`. Do not edit `app/`, `config/`, `components/gateways/lib/`, `components/plugins/lib/`, `.htaccess`, or root files.

6. **Uninstall must preserve evidence tables.** [Source: architecture.md Rollback lines 470–475] `uninstall()` does NOT drop `kuickpay_vouchers` or `kuickpay_voucher_invoices`. Schema creation in `install()` must be idempotent (`create('table', true)`). This is a deliberate data-preservation decision, not an omission.

7. **All customer/admin strings come from language files.** [Source: project-context.md; UX-DR28] No hard-coded strings in PHP or `.pdt`. No raw SOAP, parser fields, credentials, or exception classes in customer views.

8. **Class names must round-trip correctly.** [Source: 1.1 Dev Notes "Class-name derivation"] Framework-instantiated classes remain `Kuickpay` (gateway) and `KuickpayReconcilePlugin` (plugin handler). Plugin model classes use `KuickpayVouchers`, `KuickpayVoucherInvoices`. Plugin base model is `KuickpayReconcileModel`. Lib service classes use `KuickPay*` prefix (capital `P`) because they are NOT framework-instantiated by directory name.

## Tasks / Subtasks

- [x] **Task 1 — Create plugin base model and schema in `install()`** (AC: #1)
  - [x] 1.1 Create `plugins/kuickpay_reconcile/kuickpay_reconcile_model.php` with `class KuickpayReconcileModel extends AppModel`. Follow the `webhooks_model.php` pattern: `parent::__construct()`, `Loader::loadHelpers($this, ['Form'])`, and auto-load language for the calling model. This base gives all plugin models access to `Record`, `Input`, `Form`, and language helpers.
  - [x] 1.2 Update `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php` `install($plugin_id)` to create the two plugin-owned tables idempotently using `$this->Record->setField(...)->create('table', true)`. Wrap in `try/catch`; on exception, set `$this->Input->setErrors(['db'=> ['create'=>$e->getMessage()]])` and return. Follow `cms_plugin.php` and `support_manager_plugin.php` conventions. Load `Record` component if not set: `if (!isset($this->Record)) { Loader::loadComponents($this, ['Input', 'Record']); }`
  - [x] 1.3 Create `kuickpay_vouchers` table schema (exact fields below in Dev Notes → Database Schema). Use `setField` for every column, `setKey` for primary/unique/index keys, then `create('kuickpay_vouchers', true)`. Key constraints: PRIMARY KEY (`id`), UNIQUE KEY `uniq_kuickpay_vouchers_consumer` (`company_id`, `consumer_number`), UNIQUE KEY `uniq_kuickpay_vouchers_reg` (`company_id`, `registration_number`), KEY `idx_kuickpay_vouchers_status` (`status`), KEY `idx_kuickpay_vouchers_client` (`client_id`), KEY `idx_kuickpay_vouchers_txn` (`blesta_transaction_id`).
  - [x] 1.4 Create `kuickpay_voucher_invoices` table schema. PRIMARY KEY (`id`), UNIQUE KEY `uniq_kuickpay_voucher_invoices_link` (`voucher_id`, `invoice_id`), KEY `idx_kuickpay_voucher_invoices_inv` (`invoice_id`).
  - [x] 1.5 Update `upgrade($current_version, $plugin_id)` as a safe no-op placeholder with docblock. No versioned migrations needed yet (first schema version).
  - [x] 1.6 Keep `uninstall($plugin_id, $last_instance)` as a safe no-op that drops **nothing** (NN#6). Add a docblock explaining that Voucher evidence tables are preserved per architecture rollback policy. Do NOT drop tables even when `$last_instance === true`.

- [x] **Task 2 — Create plugin models** (AC: #1)
  - [x] 2.1 Create `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php` with `class KuickpayVouchers extends KuickpayReconcileModel`. Minimum methods:
    - `add(array $vars)` — validate with `Input->setRules()`/`validates()`, then `$this->Record->insert('kuickpay_vouchers', $vars, $fields)`. **`Record->insert()` does not return the new id** — after a successful insert (guard with `if (!$this->Input->errors())`) `return $this->Record->lastInsertId();`; return void/null on validation error. Set `date_created` and `date_updated` to current time if not provided.
    - `edit(int $voucher_id, array $vars)` — update allowed fields by `$voucher_id`, set `date_updated` automatically.
    - `get(int $voucher_id)` — select from `kuickpay_vouchers` where `id = $voucher_id`, `fetch()` single row.
    - `getByConsumerNumber(string $consumer_number, int $company_id)` — `where('consumer_number', '=', $consumer_number)->where('company_id', '=', $company_id)->fetch()`.
    - `getByRegistrationNumber(string $registration_number, int $company_id)` — similar.
    - `getPendingByInvoiceId(int $invoice_id, int $company_id)` — inner join `kuickpay_voucher_invoices` on `voucher_id`, where `invoice_id = $invoice_id` AND `company_id = $company_id` AND `status = 'pending'`, return single row (the voucher). This is the AC2 reuse lookup.
    - `getList(array $filters, int $page = 1, array $order_by = ['date_created' => 'DESC'])` — select with optional filters (status, client_id, company_id), paginate with `limit()`/`offset()`, return array of rows.
  - [x] 2.2 Create `plugins/kuickpay_reconcile/models/kuickpay_voucher_invoices.php` with `class KuickpayVoucherInvoices extends KuickpayReconcileModel`. Minimum methods:
    - `add(array $vars)` — validate required fields with `Input->setRules()`/`validates()` (`voucher_id` present + numeric, `invoice_id` present + numeric, `amount` matching the same decimal-string pattern as the voucher amount), set `date_created` if not provided, then insert into `kuickpay_voucher_invoices`. After a successful insert return `$this->Record->lastInsertId()` (not the `Record` object); return void/null on error.
    - `getByVoucherId(int $voucher_id)` — select all where `voucher_id = $voucher_id`, `fetchAll()`.
    - `getByInvoiceId(int $invoice_id)` — select all where `invoice_id = $invoice_id`, `fetchAll()`.
  - [x] 2.3 Add validation rules to `KuickpayVouchers::add()` ensuring required fields: `company_id`, `client_id`, `gateway_id`, `currency`, `amount`, `status`, `registration_number`, `consumer_number`. Status must be `in_array` of the exact 8 allowed states (`pending`, `retry`, `confirmed_unposted`, `posted`, `failed`, `expired`, `manual_review`, `cancelled`) — the same set as the schema `enum`, so the validation allowlist and the column definition cannot drift. Currency must be `maxLength` 3. Amount must match a safe decimal string pattern (e.g., `/^\d+(?:\.\d{1,2})?$/`). Use `$this->_('KuickpayVouchers.!error.*')` language keys.

- [x] **Task 3 — Create plugin lib services** (AC: #1, #2)
  - [x] 3.1 Create `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php` — plain PHP class (NOT framework-instantiated, no namespace, legacy global). Constructor loads plugin models via `Loader::loadModels($this, ['KuickpayReconcile.KuickpayVouchers', 'KuickpayReconcile.KuickpayVoucherInvoices']);`. Methods:
    - `create(array $voucherData, array $invoiceLinks): ?int` — **atomically** create voucher + invoice links. Blesta `Record` supports transactions, so wrap the writes: `$this->KuickpayVouchers->Record->begin();` → `$this->KuickpayVouchers->add($voucherData)` → loop `$invoiceLinks` calling `$this->KuickpayVoucherInvoices->add()` → on any model error or thrown exception `$this->KuickpayVouchers->Record->rollBack()` and return null, otherwise `commit()` and return the voucher ID. There is **no** "sequential / non-transactional" fallback — atomic create of voucher + links is an architecture rule (no orphan voucher without its invoice link). A unique-key violation (a concurrent request won the race) is one of the failure paths that rolls back and returns null; the reference service recovers from null by re-running the reuse lookup (Task 3.2).
    - `getPendingByInvoiceId(int $invoice_id, int $company_id): ?stdClass` — delegate to `$this->KuickpayVouchers->getPendingByInvoiceId(...)`.
    - `getWithInvoices(int $voucher_id): ?array` — fetch voucher via `get()`, then its invoice links via `getByVoucherId()`, return the repository-level nested shape `['voucher' => $voucher (stdClass), 'invoices' => $invoices]`. **The reference service (Task 3.2), not this method, owns flattening into the view-facing contract** — callers above the repository must not assume this nested shape.
  - [x] 3.2 Create `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php` — plain PHP class. Constructor receives or creates a `KuickPayVoucherRepository` instance. The main entry point is the AC2 idempotency gate:
    - `getOrCreateForInvoiceContext(array $context): ?array` where `$context` contains: `company_id`, `gateway_id`, `client_id`, `currency`, `amount`, `invoice_amounts` (array of `['id' => invoice_id, 'amount' => amount]`), `institution_id`, `due_date_offset_days`, `expiry_date_offset_days`.
    - **Return shape (canonical — see "Voucher data contract" in Dev Notes):** on success return a **flat associative array** the service builds from the repository's `stdClass` row: `['id', 'company_id', 'client_id', 'gateway_id', 'currency', 'amount', 'status', 'registration_number', 'consumer_number', 'date_due', 'date_expires', 'invoices' => [['invoice_id'=>…, 'amount'=>…], …]]`. On any failure return `null`. The view and gateway consume only this flat array — never the raw `stdClass` and never the repository's nested `['voucher'=>…, 'invoices'=>…]` shape.
    - **Reuse path (AC2):** Check repository for a pending voucher by `invoice_id` = first invoice in `invoice_amounts`. If found, flatten and return it (with invoices) without creating anything.
    - **Create path (AC1):** If no pending voucher, generate `registration_number` and `consumer_number` (Task 4 — deterministic), compute `date_due` = today + `due_date_offset_days`, `date_expires` = today + `expiry_date_offset_days`, build `$voucherData` and `$invoiceLinks`, call `repository->create()`. On success, fetch the created voucher (via `getWithInvoices()`), flatten, and return it.
    - **Race-recovery path (AC2 hardening):** If `repository->create()` returns `null`, a concurrent request may have created the voucher first and tripped the company-scoped unique key. Re-run the reuse lookup **once**; if a pending voucher now exists, flatten and return it. Only if it is still absent return `null`. This — together with deterministic references (Task 4) and the schema unique keys — is how 2.1 satisfies AC2 "does not create a duplicate active Voucher" without relying on the application check alone (NN#3).
    - **Failure path:** Never throw raw exceptions to the gateway; return `null`. The gateway treats `null` as "voucher unavailable" and renders the fallback.
  - [x] 3.3 Keep `KuickPayVoucherReferenceService` decoupled from gateway specifics. It must not know about `$this->meta`, `NonmerchantGateway`, or view rendering. It receives scalar context only.

- [x] **Task 4 — Basic reference generation** (AC: #1)
  - [x] 4.1 In `KuickPayVoucherReferenceService`, add a private `generateReferences(array $context): array` method that returns `['registration_number' => ..., 'consumer_number' => ...]`.
  - [x] 4.2 **Registration Number (deterministic):** `$prefix = '0000'; $registration_number = $prefix . (string) $context['invoice_amounts'][0]['id'];`. This matches the confirmed KuickPay shape (`<4-digit prefix> + invoice_id`) and is **deterministic per invoice** — a concurrent reload computes the identical value, so the `(company_id, registration_number)` unique key blocks a duplicate at the schema layer. Do **NOT** use `uniqid()`/random/time-based components: randomness would let two concurrent inserts each pass the unique keys and create duplicate active vouchers (defeating AC2). The `'0000'` prefix is a documented placeholder; Story 2.2 replaces it with the configurable biller prefix. The DB unique constraint still backstops any collision.
  - [x] 4.3 **Consumer Number (deterministic):** concatenate `institution_id` + `registration_number` (yielding the confirmed `institution_id + <4-digit prefix> + invoice_id` shape). If `institution_id` is empty, fall back to `registration_number` alone so the value is never null/empty (the DB column is NOT NULL). This is likewise deterministic per invoice, so the `(company_id, consumer_number)` unique key is a second schema-level race guard.
  - [x] 4.4 Total length of both references must not exceed the `varchar(64)` column size. Truncate or error if needed. Document that Story 2.2 replaces this algorithm with configurable patterns.

- [x] **Task 5 — Wire gateway `buildProcess()` to plugin reference service** (AC: #1, #2)
  - [x] 5.1 In `components/gateways/nonmerchant/kuickpay/kuickpay.php`, add a `protected $kuickpay_gateway_id;` property and override `setGatewayId($id)`:
    ```php
    public function setGatewayId($id)
    {
        parent::setGatewayId($id);
        $this->kuickpay_gateway_id = $id;
    }
    ```
    This is necessary because the base `Gateway::$gateway_id` is private with no getter (see Dev Notes).
  - [x] 5.2 Load the plugin reference service in `buildProcess()` **only when the currency and companion guards produced no errors**. The current guard (`kuickpay.php:558-564`) is an `if/elseif` that *sets* errors but does NOT early-return — it always falls through to `return $this->view->fetch()`. So the voucher create/reuse must be explicitly gated (e.g. `if (!$this->Input->errors()) { … }` immediately after the guard block) so an ineligible currency or missing companion can never create a Voucher (NN#1):
    ```php
    if (!$this->Input->errors()) {
        Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayVoucherReferenceService.php');
        $service = new KuickPayVoucherReferenceService();
        // … build context, call getOrCreateForInvoiceContext(), set view var (Task 5.3-5.4)
    }
    ```
    Alternatively, lazy-load via a private `getVoucherReferenceService()` helper if preferred — but keep the no-errors gate.
  - [x] 5.3 Build the context array from `buildProcess()` parameters and gateway state:
    ```php
    $context = [
        'company_id' => Configure::get('Blesta.company_id'),
        'gateway_id' => $this->kuickpay_gateway_id,
        'client_id' => $contact_info['client_id'] ?? null,
        'currency' => $this->currency,
        'amount' => $this->normalizeAmount((string) $amount),
        'invoice_amounts' => $this->normalizeInvoiceAmounts($invoice_amounts),
        'institution_id' => $this->meta['institution_id'] ?? '',
        'due_date_offset_days' => (int) ($this->meta['due_date_offset_days'] ?? 0),
        'expiry_date_offset_days' => (int) ($this->meta['expiry_date_offset_days'] ?? 0),
    ];
    ```
    Note: `amount` from Blesta may be a float; cast to string and normalize (see Task 5.5). `client_id` is the documented `$contact_info['client_id']` field (see the `buildProcess()` docblock at `kuickpay.php:512`), so it is populated in the normal client pay flow; the `?? null` is only a defensive guard. If it ever resolves null, voucher creation fails its required-field rule and the view falls back to `not_ready` — correct fail-closed behavior, not a silent bad write.
  - [x] 5.4 Call `$voucher = $service->getOrCreateForInvoiceContext($context);`. If `$voucher` is not null, pass it to the view: `$this->view->set('voucher', $voucher);`. If null, do NOT set the voucher variable (the view shows the fallback `not_ready` message).
  - [x] 5.5 Add a `protected function normalizeAmount(string $amount): string` helper in the gateway that returns a normalized decimal string with exactly 2 fractional digits (e.g., `'1500.00'`) using **string operations only — no float math and no `(float)` cast** (a `(float)` cast reintroduces the binary-float representation NN#4 forbids; do not use `number_format((float) …)` here). Algorithm: trim surrounding whitespace and strip thousands separators (commas) only; then **fail closed** — if the remaining value does not match `/^\d+(?:\.\d+)?$/` (no leading sign, no exponent, no other characters), do NOT fabricate an amount: return the trimmed value unchanged so the model's amount rule rejects it (rather than silently coercing garbage into a stored amount). For a valid value: `$parts = explode('.', $amount, 2); $integer = ltrim($parts[0], '0'); if ($integer === '') $integer = '0'; $fraction = substr(str_pad($parts[1] ?? '', 2, '0'), 0, 2); return $integer . '.' . $fraction;`. Negative or signed amounts are invalid input here and must never normalize to a positive value.
  - [x] 5.6 Add a `protected function normalizeInvoiceAmounts(array $invoice_amounts): array` helper that maps each invoice amount through the same normalization.

- [ ] **Task 6 — Update gateway process view** (AC: #1)
  - [ ] 6.1 Update `components/gateways/nonmerchant/kuickpay/views/default/process.pdt` to conditionally show voucher info when `$voucher` is set:
    ```php
    <?php
    if (!empty($voucher)) {
    ?>
    <div class="kuickpay-voucher-info">
        <p><?php echo $this->_('Kuickpay.process.consumer_number_label'); ?>: <strong class="kuickpay-consumer-number"><?php echo $this->Html->safe($voucher['consumer_number']); ?></strong></p>
        <p><?php echo $this->_('Kuickpay.process.amount_label'); ?>: <strong><?php echo $this->Html->safe($voucher['amount']); ?></strong></p>
        <p><?php echo $this->_('Kuickpay.process.status_label'); ?>: <strong><?php echo $this->Html->safe($this->_('Kuickpay.process.status.' . $voucher['status'])); ?></strong></p>
        <?php if (!empty($voucher['date_due'])) { ?>
        <p><?php echo $this->_('Kuickpay.process.due_date_label'); ?>: <?php echo $this->Html->safe($voucher['date_due']); ?></p>
        <?php } ?>
        <?php if (!empty($voucher['date_expires'])) { ?>
        <p><?php echo $this->_('Kuickpay.process.expiry_date_label'); ?>: <?php echo $this->Html->safe($voucher['date_expires']); ?></p>
        <?php } ?>
    </div>
    <?php
    } else {
        echo $this->_('Kuickpay.process.not_ready');
    }
    ?>
    ```
  - [ ] 6.2 Keep the display minimal and honest: show `pending` status with neutral language. No "Payment received", no green checks, no success styling (UX-DR20; NFR9). The full styled reference panel with copy action and instruction groups comes in Stories 2.5/2.6. Note: 2.1 only ever creates `pending` vouchers, so `Kuickpay.process.status.pending` is the only status label needed now; the view's dynamic `status.<status>` lookup must degrade gracefully (fall back to the raw status or a neutral default) rather than print a missing language key if a non-`pending` status is ever passed.

- [ ] **Task 7 — Add language strings** (AC: #1)
  - [ ] 7.1 Append to `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` (preserve existing keys/order/quoting):
    ```php
    // Customer process view — Story 2.1 voucher display
    $lang['Kuickpay.process.consumer_number_label'] = 'Consumer Number';
    $lang['Kuickpay.process.amount_label'] = 'Amount';
    $lang['Kuickpay.process.status_label'] = 'Status';
    $lang['Kuickpay.process.due_date_label'] = 'Due date';
    $lang['Kuickpay.process.expiry_date_label'] = 'Expiry date';
    $lang['Kuickpay.process.status.pending'] = 'Payment reference created — awaiting payment';
    ```
  - [ ] 7.2 **Model error keys go in per-model language files, not in the plugin handler file.** The plugin base model (Task 1.1, mirroring `webhooks_model.php:20`) auto-loads language via `Language::loadLang([Loader::fromCamelCase(get_class($this))], …, dirname(__FILE__) . DS . 'language' . DS)`. For class `KuickpayVouchers` that resolves to `plugins/kuickpay_reconcile/language/en_us/kuickpay_vouchers.php`; for `KuickpayVoucherInvoices` to `kuickpay_voucher_invoices.php`. The plugin-handler file (`kuickpay_reconcile_plugin.php`) is **never constructed during the gateway `buildProcess()` flow**, so keys placed there will NOT be loaded when model validation fires — `$this->_('KuickpayVouchers.!error.*')` would render the raw key (violating NN#7). Therefore:
    - **`plugins/kuickpay_reconcile/language/en_us/kuickpay_reconcile_plugin.php`** — leave `KuickpayReconcilePlugin.name`/`.description` only. These already exist; if you want the richer description ("Durable voucher state, reconciliation, and admin review for KuickPay."), **update the existing line in place — do not append a duplicate `$lang[...]` assignment**.
    - **`plugins/kuickpay_reconcile/language/en_us/kuickpay_vouchers.php`** (NEW) — voucher model validation errors:
    ```php
    <?php
    // KuickpayVouchers model validation errors (Story 2.1)
    $lang['KuickpayVouchers.!error.company_id.empty'] = 'Company ID is required.';
    $lang['KuickpayVouchers.!error.client_id.empty'] = 'Client ID is required.';
    $lang['KuickpayVouchers.!error.gateway_id.empty'] = 'Gateway ID is required.';
    $lang['KuickpayVouchers.!error.currency.empty'] = 'Currency is required.';
    $lang['KuickpayVouchers.!error.currency.length'] = 'Currency must be 3 characters.';
    $lang['KuickpayVouchers.!error.amount.empty'] = 'Amount is required.';
    $lang['KuickpayVouchers.!error.amount.format'] = 'Amount must be a valid decimal value.';
    $lang['KuickpayVouchers.!error.status.valid'] = 'Invalid voucher status.';
    $lang['KuickpayVouchers.!error.registration_number.empty'] = 'Registration number is required.';
    $lang['KuickpayVouchers.!error.consumer_number.empty'] = 'Consumer number is required.';
    ```
    - **`plugins/kuickpay_reconcile/language/en_us/kuickpay_voucher_invoices.php`** (NEW) — invoice-link model validation errors (for the Task 2.2 rules):
    ```php
    <?php
    // KuickpayVoucherInvoices model validation errors (Story 2.1)
    $lang['KuickpayVoucherInvoices.!error.voucher_id.empty'] = 'Voucher ID is required.';
    $lang['KuickpayVoucherInvoices.!error.invoice_id.empty'] = 'Invoice ID is required.';
    $lang['KuickpayVoucherInvoices.!error.amount.format'] = 'Amount must be a valid decimal value.';
    ```

- [ ] **Task 8 — Verification** (AC: #1, #2)
  - [ ] 8.1 `php -l` every new and modified PHP file:
    ```sh
    find plugins/kuickpay_reconcile -name '*.php' -print -exec php -l {} \;
    php -l components/gateways/nonmerchant/kuickpay/kuickpay.php
    php -l components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php
    ```
  - [ ] 8.2 Confirm `config.json` files still parse as JSON (no changes expected to existing configs).
  - [ ] 8.3 Confirm no core files changed: `git status --porcelain` shows only new files under the two extension dirs plus modified gateway files, this story file, and `sprint-status.yaml`.
  - [ ] 8.4 Prove the negative — no Blesta transaction creation, no `markPaid`, no `recordPayment`, no invoice `setStatus`. Do **not** include a bare `->add\(` alternative: it matches this story's own legitimate model `$this->KuickpayVouchers->add(...)` / `$this->KuickpayVoucherInvoices->add(...)` calls, so the check could never print "clean." Scope the grep to payment-mutation surfaces only:
    ```sh
    grep -rinE 'Transactions(->|::)|recordPayment|markPaid|markPaidInvoice|setStatus' \
      components/gateways/nonmerchant/kuickpay plugins/kuickpay_reconcile || echo "clean: no mutation surface"
    ```
    Separately, eyeball that the only `->add(` calls are the expected `KuickpayVouchers`/`KuickpayVoucherInvoices` model inserts.
  - [ ] 8.5 Confirm schema uniqueness: the `install()` code defines `setKey(['company_id', 'consumer_number'], 'unique')` and `setKey(['company_id', 'registration_number'], 'unique')`.
  - [ ] 8.6 If a running Blesta + MySQL stack is available: install/enable the plugin, open a PKR invoice pay page, select KuickPay, confirm a Voucher row appears in `kuickpay_vouchers` with status `pending`, and reloading the page reuses the same row (AC2). If no runtime/DB, state that explicitly and rely on lint + structural checks + the unit tests in 8.7.
  - [ ] 8.7 Add focused unit tests under `components/gateways/nonmerchant/kuickpay/tests/` (gateway-side pure helpers) and/or `plugins/kuickpay_reconcile/tests/`, following the existing `tests/bootstrap.php` + PHPUnit 8.5 pattern used by the current gateway test classes. Cover the pure, DB-free logic: `normalizeAmount()` / `normalizeInvoiceAmounts()` (string-safety, malformed/negative rejected, no float drift), `generateReferences()` (deterministic per invoice — same context yields identical reg/consumer; matches the `<prefix>+invoice_id` shape; ≤64 chars), and the reference service's reuse-vs-create decision against a fake/in-memory repository (reuse returns the existing voucher with no second create; `create()` returning null → race-recovery re-query path). DB-bound paths stay as the runtime check in 8.6. This realizes the `test(kuickpay_reconcile): cover voucher create and reuse paths` commit already listed under Git intelligence.
  - [ ] 8.8 Confirm framework ordering: `setGatewayId()` is invoked before `buildProcess()` in the client pay flow (so `$this->kuickpay_gateway_id` is populated when the context is built). If a runtime is unavailable, at minimum assert the override exists and that a null `gateway_id` fails the voucher required-field rule (fail-closed → `not_ready`) rather than persisting a voucher with a null gateway.

## Dev Notes

### Critical context — read before starting

This is the **first story in Epic 2** and the first story that introduces durable database state under the plugin. It builds directly on the completed Epic 1 scaffold (gateway settings, PKR eligibility, credential encryption, connection test) and the completed 3.1/3.2 SOAP client/parser scaffold.

**Current as-built state (read each file in full before editing):**

- `components/gateways/nonmerchant/kuickpay/kuickpay.php` (628 lines) — `class Kuickpay extends NonmerchantGateway` (legacy global, no namespace). Key members relevant to 2.1:
  - `setCurrency($currency)` (51–54): sets dynamic `$this->currency` — read by `currencyEligible()`.
  - `getSettings()` (62–86): renders settings form with `currency_policy`, `fee_policy`, password booleans.
  - `editSettings()` (94–276): validation rules, connection test, returns `$meta`.
  - `encryptableFields()` (283–286): returns `['voucher_password', 'inquiry_password']`.
  - `setMeta()` (463–466): stores private `$this->meta`.
  - `getSoapClient()` (473–491): builds `KuickPaySoapClient` from meta — **not used in 2.1**.
  - `currencyEligible()` (502–505): PKR-only guard — **preserve as-is**.
  - `buildProcess()` (548–567): makes process view, loads `Html`, runs `currencyEligible()` then `companionInstalled()`, returns `$this->view->fetch()`. **This is where 2.1 adds voucher create/reuse** (Task 5).
  - `validate()` (588–592) and `success()` (611–615): fail-closed — **do not touch**.
  - `companionInstalled()` (622–627): private, uses `PluginManager::isInstalled()` — **preserve as-is**.
- `components/gateways/nonmerchant/kuickpay/views/default/process.pdt` (2 lines) — echoes `Kuickpay.process.not_ready` only. **This is where 2.1 adds conditional voucher display** (Task 6).
- `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` (91 lines) — has settings keys, error keys, `process.not_ready`, `currency_policy_note`. **Append new process-view keys** (Task 7.1); preserve all existing keys/order/quoting.
- `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php` (60 lines) — `class KuickpayReconcilePlugin extends Plugin`. `install()`/`upgrade()`/`uninstall()` are safe no-ops. **This is where 2.1 adds schema creation** (Task 1.2).
- `plugins/kuickpay_reconcile/language/en_us/kuickpay_reconcile_plugin.php` — currently only name/description. **Add model validation keys** (Task 7.2).
- **No plugin `models/`, `lib/`, `controllers/`, or `views/` exist yet.** These are all net-new in this story.

### Database schema (exact shape for Task 1)

**`kuickpay_vouchers`**
```php
$this->Record
    ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
    ->setField('company_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
    ->setField('gateway_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
    ->setField('client_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
    ->setField('currency', ['type'=>'varchar', 'size'=>3])
    ->setField('amount', ['type'=>'varchar', 'size'=>20]) // normalized decimal string, never PHP float
    ->setField('status', [
        'type'=>'enum',
        'size'=>"'pending','retry','confirmed_unposted','posted','failed','expired','manual_review','cancelled'",
        'default'=>'pending'
    ])
    ->setField('registration_number', ['type'=>'varchar', 'size'=>64])
    ->setField('consumer_number', ['type'=>'varchar', 'size'=>64])
    ->setField('date_due', ['type'=>'date', 'is_null'=>true, 'default'=>null])
    ->setField('date_expires', ['type'=>'date', 'is_null'=>true, 'default'=>null])
    ->setField('date_created', ['type'=>'datetime'])
    ->setField('date_updated', ['type'=>'datetime'])
    ->setField('date_posted', ['type'=>'datetime', 'is_null'=>true, 'default'=>null])
    ->setField('date_last_checked', ['type'=>'datetime', 'is_null'=>true, 'default'=>null])
    ->setField('error_class', ['type'=>'varchar', 'size'=>32, 'is_null'=>true, 'default'=>null])
    ->setField('raw_status', ['type'=>'varchar', 'size'=>8, 'is_null'=>true, 'default'=>null])
    ->setField('evidence_hash', ['type'=>'varchar', 'size'=>24, 'is_null'=>true, 'default'=>null])
    ->setField('kuickpay_reference', ['type'=>'varchar', 'size'=>128, 'is_null'=>true, 'default'=>null])
    ->setField('blesta_transaction_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'is_null'=>true, 'default'=>null])
    ->setField('diagnostic_summary', ['type'=>'text', 'is_null'=>true, 'default'=>null])
    ->setField('admin_notes', ['type'=>'text', 'is_null'=>true, 'default'=>null])
    ->setKey(['id'], 'primary')
    ->setKey(['company_id', 'consumer_number'], 'unique')
    ->setKey(['company_id', 'registration_number'], 'unique')
    ->setKey(['status'], 'index')
    ->setKey(['client_id'], 'index')
    ->setKey(['blesta_transaction_id'], 'index')
    ->create('kuickpay_vouchers', true);
```

**`kuickpay_voucher_invoices`**
```php
$this->Record
    ->setField('id', ['type'=>'int', 'size'=>10, 'unsigned'=>true, 'auto_increment'=>true])
    ->setField('voucher_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
    ->setField('invoice_id', ['type'=>'int', 'size'=>10, 'unsigned'=>true])
    ->setField('amount', ['type'=>'varchar', 'size'=>20]) // per-invoice allocation string
    ->setField('date_created', ['type'=>'datetime'])
    ->setKey(['id'], 'primary')
    ->setKey(['voucher_id', 'invoice_id'], 'unique')
    ->setKey(['invoice_id'], 'index')
    ->create('kuickpay_voucher_invoices', true);
```

Notes:
- Amount columns are `varchar(20)` to strictly follow the architecture's "normalized decimal strings, never PHP floats" rule (NFR13). PDO returns `decimal` as string too, but `varchar` is maximally explicit and safe.
- `date_created` on `kuickpay_voucher_invoices` is set at insert time; there is no `date_updated` because invoice links are immutable in 2.1.
- `admin_notes` and `diagnostic_summary` are nullable text — placeholders for future stories (Epic 4 diagnostics, admin review notes).
- `blesta_transaction_id` is nullable and has an index; it remains null until Story 3.5 posting.
- `error_class`, `raw_status`, `evidence_hash`, `kuickpay_reference` are nullable because they are populated only after KuickPay interaction (Story 2.3+).

### Ownership boundaries and service design

The architecture splits ownership between gateway and plugin:

| Concern | Owner | File(s) |
|---|---|---|
| Checkout entry point, currency guard, view render | Gateway | `kuickpay.php` `buildProcess()` |
| Voucher create/reuse/idempotency decision | Plugin service | `lib/KuickPayVoucherReferenceService.php` |
| Voucher persistence reads/writes | Plugin model/repo | `models/kuickpay_vouchers.php`, `models/kuickpay_voucher_invoices.php`, `lib/KuickPayVoucherRepository.php` |
| Customer reference display | Gateway view | `views/default/process.pdt` |
| Schema lifecycle | Plugin | `kuickpay_reconcile_plugin.php` `install()` |

**Gateway → Plugin call pattern:**
The gateway loads the plugin lib file directly and instantiates the service:
```php
Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayVoucherReferenceService.php');
$service = new KuickPayVoucherReferenceService();
```
The service internally loads plugin models via `Loader::loadModels($this, ['KuickpayReconcile.KuickpayVouchers', ...])`. This is the cleanest decoupling: the gateway knows only the service class name and context array; the service knows the models and DB schema.

**Anti-pattern to avoid:** Do NOT have the gateway directly call `$this->Record->insert('kuickpay_vouchers', ...)` or directly instantiate plugin models. That violates the architecture's ownership boundary.

### How to get `gateway_id` in the gateway (load-bearing detail)

The base `Gateway` class declares `private $gateway_id` with a public setter `setGatewayId($id)` but **no getter** (`components/gateways/lib/gateway.php:33, 229–232`). Subclasses cannot read the private property. The standard Blesta workaround is to override the setter and shadow the value in a protected property:

```php
protected $kuickpay_gateway_id;

public function setGatewayId($id)
{
    parent::setGatewayId($id);
    $this->kuickpay_gateway_id = $id;
}
```

This is safe, idempotent, and matches brownfield patterns. Do NOT use reflection to read the private property.

### Model conventions (Blesta brownfield)

- Plugin models extend the plugin base model (`KuickpayReconcileModel`), which extends `AppModel`.
- The base model file is `plugins/kuickpay_reconcile/kuickpay_reconcile_model.php` (class `KuickpayReconcileModel`).
- Blesta's `Loader::loadModels()` auto-resolves the base model when loading `KuickpayReconcile.KuickpayVouchers` — no explicit `require` of the base model is needed.
- Use `$this->Record->insert('table', $vars, $fields)`, `$this->Record->select()->from('table')->where(...)->fetch()`, and `$this->Input->setRules()` / `$this->Input->validates()`.
- Language keys for model errors use `$this->_('KuickpayVouchers.!error.field.rule')`.
- Date fields: use `date('Y-m-d H:i:s')` for `datetime`, `date('Y-m-d')` for `date`.

### Voucher data contract (service ↔ view)

Pin one shape end-to-end so the customer view can't break on a type mismatch (`Record->fetch()` returns a **`stdClass`** — object access `$row->field`, **not** an array; confirmed by the webhooks model pattern):

| Layer | Shape |
|---|---|
| `KuickpayVouchers::get()` / repo reads | `stdClass` row (`$v->consumer_number`) |
| `KuickPayVoucherRepository::getWithInvoices()` | nested `['voucher' => stdClass, 'invoices' => array]` — repository-internal only |
| `KuickPayVoucherReferenceService::getOrCreateForInvoiceContext()` | **flat associative array** `['id','company_id','client_id','gateway_id','currency','amount','status','registration_number','consumer_number','date_due','date_expires','invoices'=>[…]]`, or `null` |
| Gateway `buildProcess()` → view var `$voucher` | the service's flat array, passed through unchanged |
| `process.pdt` | flat array access: `$voucher['consumer_number']`, `$voucher['amount']`, `$voucher['status']`, `$voucher['date_due']`, `$voucher['date_expires']` |

The reference service is the single place that converts the `stdClass` row into the flat array (explicit field mapping, or `(array) $row` plus the `invoices` key). The view never receives a `stdClass` and never the nested `['voucher'=>…]` shape — otherwise `$voucher['consumer_number']` either fatals ("Cannot use object of type stdClass as array") or silently renders empty while `!empty($voucher)` suppresses the `not_ready` fallback, defeating AC1's "the customer sees their Voucher reference."

### AC2 idempotency strategy (race safety)

AC2 ("does not create a duplicate active Voucher") must hold even under concurrent double-submit, and NN#3 forbids relying on the application check alone. The 2.1 strategy layers three mechanisms:

1. **Deterministic references (Task 4).** The same invoice context always computes the same Registration Number and Consumer Number, so a concurrent second insert attempts a value that already exists.
2. **Company-scoped unique keys (schema).** `(company_id, registration_number)` and `(company_id, consumer_number)` then reject that second insert at the database layer — the schema-level idempotency the architecture requires for the active payment context.
3. **Transactional create + race-recovery (Tasks 3.1/3.2).** Voucher + links are created atomically in a transaction; if the insert loses the race and trips a unique key, `create()` rolls back and returns null, and the service re-runs the reuse lookup once and returns the winner's voucher.

The application-level `getPendingByInvoiceId()` check stays the fast path; the schema keys + recovery are the correctness backstop. **Deferred (out of 2.1 scope):** hardened active-context guards tied to amount changes and multi-invoice contexts (Story 2.4), reference regeneration after a failed/expired voucher, and the configurable pattern (Story 2.2). Within 2.1 — where vouchers only ever stay `pending` — one reference per invoice is the correct, intended behavior.

### Reference generation scope

Story 2.2 will replace this generation with configurable patterns (`registration_number_pattern`, `consumer_number_pattern` from gateway settings), customer-facing validation, and the final generation policy. For 2.1, the algorithm must:
1. Produce non-empty strings under 64 characters.
2. Be **deterministic per invoice** — the same invoice context always yields the same Registration Number and Consumer Number. Do NOT use random/time-based components (`uniqid()`, `mt_rand()`, timestamps). Determinism is load-bearing for AC2 (see "AC2 idempotency strategy" above): it makes the company-scoped unique keys the actual schema-level race guard.
3. Match the **confirmed KuickPay shape** so the customer is never shown a reference that cannot later become payable: Registration Number = `<4-digit prefix> + invoice_id`; Consumer Number = `institution_id + Registration Number` (i.e. `institution_id + <4-digit prefix> + invoice_id`). [Source: 3.1 contract — confirmed live formula.] For 2.1 the 4-digit prefix is a fixed documented placeholder (`'0000'`); Story 2.2 supplies the real configurable biller prefix.
4. Be safe for the DB unique constraints (length and charset).

Because the placeholder is deterministic, there is exactly one reference per invoice in 2.1. Retry/regeneration semantics (a new reference after a failed/expired voucher, amount-change gating) belong to Stories 2.2/2.4/Epic 3 and are out of scope here.

### Failure handling in `buildProcess()`

If `getOrCreateForInvoiceContext()` returns null (DB error, validation failure, etc.), the gateway must NOT crash or expose raw errors. It should:
1. Not set the `$voucher` view variable.
2. The process view falls back to `Kuickpay.process.not_ready`.
3. Optionally set a generic gateway error: `$this->Input->setErrors($this->getCommonError('unsupported'));` — this mirrors the existing companion-missing pattern and keeps the customer experience safe.

### What must NOT happen in this story (regression / scope guardrails)

- **No SOAP call:** Do not call `getSoapClient()`, `insertVoucher()`, or any SOAP operation. The voucher is a local record only until Story 2.3.
- **No parser consumption:** Do not instantiate `KuickPayResponseParser` or branch on parsed evidence. Parser is for Epic 3.
- **No payment posting:** No `KuickPayPostingService`, no Blesta transaction creation, no invoice paid state change.
- **No admin workbench:** No controllers, admin views, or admin `.pdt` files. Epic 4 owns those.
- **No cron:** No `cron()` override, no reconciliation scheduling.
- **No events/actions/permissions:** No `getEvents()`, `getActions()`, `getPermissions()` overrides.
- **No hard-coded production values:** Institution ID, endpoint, credentials, fallback phone all come from `$this->meta`.
- **No float math on amounts:** Use string normalization only.

### Scope: what 2.1 owns vs later stories

| Surface | Owned by 2.1? | Where it lives |
|---|---|---|
| Schema + models | ✅ Yes | `kuickpay_reconcile_plugin.php` `install()`, `models/*.php` |
| Basic reference generation | ✅ Yes (placeholder) | `lib/KuickPayVoucherReferenceService.php` |
| Voucher create/reuse/idempotency | ✅ Yes | `lib/KuickPayVoucherReferenceService.php` |
| Gateway calls plugin service | ✅ Yes | `kuickpay.php` `buildProcess()` |
| Basic customer voucher display | ✅ Yes | `views/default/process.pdt` |
| Configurable reference patterns | ❌ No | **Story 2.2** |
| Reference validation UX | ❌ No | **Story 2.2** |
| Database uniqueness invariant (company-scoped reg/consumer keys) | ✅ Yes (this story) | schema + deterministic refs |
| Invoice data mapping to KuickPay format | ❌ No | **Story 2.3** |
| InsertVoucher SOAP call | ❌ No | **Story 2.3** / **3.1** |
| Parser consumption for voucher creation response | ❌ No | **Story 2.3** / **3.2** |
| Amount-change gating | ❌ No | **Story 2.4** |
| Multi-invoice blocking | ❌ No | **Story 2.4** |
| Styled reference panel + copy action | ❌ No | **Story 2.5** |
| Instruction groups + status expectations | ❌ No | **Story 2.6** |
| Reconciliation + posting | ❌ No | **Epic 3** |
| Admin workbench | ❌ No | **Epic 4** |

### Previous story intelligence (1.1–1.5, 3.1, 3.2)

- **1.1 (done):** Scaffold established exact paths, class names (`Kuickpay`, `KuickpayReconcilePlugin`), language-file patterns, companion-missing guard, fail-closed `validate`/`success`. Do NOT deviate from these patterns.
- **1.2 (done):** Settings form includes `institution_id`, `due_date_offset_days`, `expiry_date_offset_days`, `registration_number_pattern`, `consumer_number_pattern`. The gateway's `$this->meta` carries these values. 2.1 reads them in `buildProcess()` context.
- **1.3 (done):** Credential encryption/masking — untouched here.
- **1.4 (done):** Connection test — untouched here.
- **1.5 (done):** `currencyEligible()` guard in `buildProcess()` sources PKR from `config.json`. 2.1's voucher logic must sit **behind** this guard (after it passes). The guard order must remain: currency-first, then companion, then voucher create/reuse.
- **3.1 (done):** `KuickPaySoapClient` exists with `insertVoucher()`, `billPaymentInquiry()`, etc. **Not used in 2.1.**
- **3.2 (done):** `KuickPayResponseParser` exists with normalized evidence contract. **Not used in 2.1.**

### Git intelligence

Recent work (HEAD `f851a0e8`) marks Story 3.2 for review. The parser and SOAP client are stable. Epic 1 (1.1–1.5) is fully done. This story is the first to touch the plugin's `install()` method and to add plugin `models/` / `lib/`.

Commit convention per AGENTS.md and brownfield repo: `<type>(<scope>): <summary>`, imperative, lowercase, ≤72 chars. Suggested commits:
- `feat(kuickpay_reconcile): add voucher schema and plugin models`
- `feat(kuickpay_reconcile): add voucher repository and reference service`
- `feat(kuickpay): integrate voucher create/reuse into buildProcess`
- `feat(kuickpay): display basic voucher info on process view`
- `test(kuickpay_reconcile): cover voucher create and reuse paths`

### Verification commands

```sh
# 1. Syntax — all new/modified PHP files
find plugins/kuickpay_reconcile -name '*.php' -print -exec php -l {} \;
php -l components/gateways/nonmerchant/kuickpay/kuickpay.php
php -l components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php

# 2. Prove no mutation surface (scoped to payment mutation; bare ->add( excluded — it matches our own model inserts)
grep -rinE 'Transactions(->|::)|recordPayment|markPaid|markPaidInvoice|setStatus' \
  components/gateways/nonmerchant/kuickpay plugins/kuickpay_reconcile || echo "clean"

# 3. Prove schema has required unique keys
grep -n "unique" plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php

# 4. No core edits
git status --porcelain

# 5. If running Blesta is available: enable plugin, open PKR invoice pay page,
#    confirm Voucher row created with status=pending, reload confirms same row reused.
```

## Project Structure Notes

- **Alignment with architecture:** exact paths match `plugins/kuickpay_reconcile/models/`, `plugins/kuickpay_reconcile/lib/`, and `components/gateways/nonmerchant/kuickpay/views/default/process.pdt` [Source: architecture.md lines 696–753].
- **Intentional scope reduction vs. architecture's full tree:** the architecture lists `controllers/`, admin `views/`, `lib/KuickPaySchema.php`, `lib/KuickPayVoucherNormalizer.php`, `lib/KuickPayReconcileService.php`, `lib/KuickPayPostingService.php`, etc. Those belong to later stories (Epics 2–4). This story deliberately ships only the minimum durable state layer.
- **Files created (NEW):**
  - `plugins/kuickpay_reconcile/kuickpay_reconcile_model.php`
  - `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php`
  - `plugins/kuickpay_reconcile/models/kuickpay_voucher_invoices.php`
  - `plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php`
  - `plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php`
  - `plugins/kuickpay_reconcile/language/en_us/kuickpay_vouchers.php` — voucher model error keys (auto-loaded by the base model; see Task 7.2)
  - `plugins/kuickpay_reconcile/language/en_us/kuickpay_voucher_invoices.php` — invoice-link model error keys
  - Test file(s) under `components/gateways/nonmerchant/kuickpay/tests/` and/or `plugins/kuickpay_reconcile/tests/` (Task 8.7)
- **Files modified (UPDATE):**
  - `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php` — add schema to `install()`
  - `components/gateways/nonmerchant/kuickpay/kuickpay.php` — override `setGatewayId()`, add `normalizeAmount()`, `normalizeInvoiceAmounts()`, wire `buildProcess()` to reference service
  - `components/gateways/nonmerchant/kuickpay/views/default/process.pdt` — conditional voucher display
  - `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php` — append process-view keys
  - `plugins/kuickpay_reconcile/language/en_us/kuickpay_reconcile_plugin.php` — keep name/description only; update the description in place (do NOT append model error keys here — they go in the per-model files above)

## References

- [Source: epics.md#Epic 2: Customer Voucher Payment Reference, lines 417–419] — epic goal, FR6–FR14 coverage.
- [Source: epics.md#Story 2.1, lines 421–437] — user story + AC1/AC2 (reproduced verbatim above).
- [Source: epics.md#Story 2.2, lines 439–461] — reference generation algorithm (not 2.1's scope, but informs placeholder shape).
- [Source: epics.md#Story 2.3, lines 463–497] — InsertVoucher / invoice mapping (not 2.1's scope, but defines what fields the voucher table must store).
- [Source: architecture.md lines 333–351] — data architecture: tables, states, idempotency, atomic creation.
- [Source: architecture.md lines 519–526, 581–590, 665–669] — gateway/plugin ownership boundaries, posting monopoly, service boundaries.
- [Source: architecture.md lines 529–539] — naming conventions: `kuickpay_` prefix, `id` primary key, `date_*` columns, explicit index names.
- [Source: architecture.md lines 596–607] — UI Display-State Matrix (customer/admin labels per state).
- [Source: architecture.md lines 648–659] — anti-patterns (no transaction in buildProcess, no markPaid, etc.).
- [Source: architecture.md lines 678–753] — complete directory structure (end-state; 2.1 delivers subset).
- [Source: components/gateways/lib/gateway.php:33, 229–232] — private `$gateway_id`, setter without getter.
- [Source: plugins/webhooks/webhooks_model.php] — plugin base model pattern.
- [Source: plugins/webhooks/models/webhooks_webhooks.php] — plugin model CRUD pattern.
- [Source: plugins/cms/cms_plugin.php:25–67] — plugin `install()` schema creation pattern.
- [Source: plugins/support_manager/support_manager_plugin.php:42–106] — multi-table `install()` pattern.
- [Source: project-context.md] — PHP 8.2, legacy global classes, Loader/Input/Record conventions, language-file rule, no core edits.
- [Source: sprint-status.yaml#BUILD ORDER] — 2.1 follows 3.1/3.2(creation parser) in recommended track, but 2.1 itself is unblocked by scaffold completion.
- [Source: 1-1-install-kuickpay-gateway-and-companion-plugin-scaffold.md] — scaffold patterns, class-name derivation, AC3 guard.
- [Source: 1-5-enforce-pkr-only-gateway-eligibility.md] — `buildProcess()` guard shape, `currencyEligible()` helper, `setCurrency()` dynamic property.

## Dev Agent Record

### Agent Model Used

GPT-5 Codex

### Debug Log References

- 2026-06-10: Task 1 syntax checks passed for `kuickpay_reconcile_model.php` and `kuickpay_reconcile_plugin.php`; structural grep confirmed required voucher unique keys and idempotent table creation calls.
- 2026-06-10: Task 2 syntax checks passed for both plugin model files; method-surface grep confirmed the required voucher and invoice-link APIs exist.
- 2026-06-10: Tasks 3-4 red/green unit coverage added and passed: `KuickPayVoucherReferenceServiceTest` covers reuse without create, deterministic placeholder references, and create-null race recovery.
- 2026-06-10: Task 5 syntax check passed for `kuickpay.php`; voucher service call is gated behind `!$this->Input->errors()`.

### Completion Notes List

- Task 1 complete: added the plugin base model, idempotent voucher/invoice-link schema creation in `install()`, safe no-op upgrade, and non-destructive uninstall documentation preserving evidence tables.
- Task 2 complete: added voucher and voucher-invoice models with required CRUD/query APIs, required-field/status/currency/amount validation, automatic timestamps, and `lastInsertId()` returns after successful inserts.
- Tasks 3-4 complete: added the repository transaction boundary, reference service reuse/create/race-recovery gate, flat view-facing voucher contract, and deterministic Story 2.1 placeholder references.
- Task 5 complete: wired gateway `buildProcess()` to the plugin reference service behind the existing currency/companion guards, captured gateway ID via setter shadowing, and added string-only amount normalization helpers.

### File List

- plugins/kuickpay_reconcile/kuickpay_reconcile_model.php
- plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php
- plugins/kuickpay_reconcile/models/kuickpay_vouchers.php
- plugins/kuickpay_reconcile/models/kuickpay_voucher_invoices.php
- plugins/kuickpay_reconcile/lib/KuickPayVoucherRepository.php
- plugins/kuickpay_reconcile/lib/KuickPayVoucherReferenceService.php
- components/gateways/nonmerchant/kuickpay/tests/KuickPayVoucherReferenceServiceTest.php
- components/gateways/nonmerchant/kuickpay/kuickpay.php

## Change Log

- 2026-06-09: Story created (ready-for-dev) via bmad-create-story. Comprehensive context engine analysis completed — comprehensive developer guide created.
- 2026-06-10: Validation triage applied (story remains ready-for-dev). Pinned the voucher data contract (service returns a flat array; view stops mis-accessing a `stdClass`); relocated model error language keys into the per-model files the base model actually auto-loads; made reference generation deterministic and aligned to the confirmed KuickPay shape so the company-scoped unique keys become the schema-level AC2 race guard; mandated atomic transactional create with rollback plus a race-recovery re-query; added invoice-link validation, `lastInsertId()` returns, an inline status allowlist, and string-only fail-closed amount normalization; gated voucher create/reuse behind the no-errors branch (the guard has no early-return); corrected the "prove no mutation" grep (dropped the self-matching `->add(`); added a unit-test task and a `setGatewayId()` ordering check; and added Dev Notes for the data contract and the AC2 idempotency strategy. Verified against source: `buildProcess()` control flow, base-model language auto-load, `Record->insert()`/`lastInsertId()`, the 3.1 confirmed reference formula, and the existing test harness.
- 2026-06-10: Implemented Task 1 plugin base model and durable voucher schema.
- 2026-06-10: Implemented Task 2 plugin voucher models.
- 2026-06-10: Implemented Tasks 3-4 voucher repository, reference service, deterministic references, and focused unit tests.
- 2026-06-10: Implemented Task 5 gateway voucher service integration.

---
baseline_commit: 12b7190525d22083f1c0eba25a37814f5fbe5acf
---

# Story 4.1: Search and Filter KuickPay Vouchers

Status: done

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As a support agent,
I want to search and filter KuickPay Vouchers,
so that I can quickly find a customer's payment attempt.

This is the **first story of Epic 4 (Admin Support and Manual Review Operations)** and delivers the
admin **Voucher List / search-and-filter surface** that every later Epic-4 story builds on
(4.2 detail, 4.3 manual actions, 4.4 manual-review queue, 4.5 logs). It is a **read-only / idempotent admin
screen** (Blesta Widget POST filters allowed; no state mutation) over the durable `kuickpay_vouchers` table
created in Epic 2/3. It introduces **no schema change** and **no payment-state mutation**.

## Acceptance Criteria

Canonical ACs from `epics.md` Story 4.1 (lines 728–748):

1. **AC1 — Filters available.** Given the admin opens the KuickPay Voucher List, when filters are available,
   then staff can filter by **status, client, invoice ID, Consumer Number, date range, amount,
   KuickPay transaction/auth fields, and Blesta transaction link**. *(FR24, UX-DR11)*

2. **AC2 — Results render.** Given filters are applied, when results render, then list rows show
   **created date, client, invoice mapping, amount, Consumer Number, status, last inquiry time, and
   transaction link when paid**, AND **filters remain visible and selected after returning from detail or actions**. *(FR24, UX-DR12)*

3. **AC3 — No matches.** Given no records match, when the list renders, then it shows a **localized
   no-results message** AND **keeps filters visible**. *(UX-DR27 — "No Vouchers match these filters.")*

Derived must-hold invariants (implicit requirements — the feature is not correct in the existing system
unless ALL of these hold; treat as ACs):

4. **AC4 — Company scoping.** Every query is scoped to the authenticated staff company
   (`company_id = $this->company_id`). The list must NEVER read a `company_id` from request input, and must
   never show vouchers from another company. *(carry-forward from 3-3 company-scope hardening; NFR)*

5. **AC5 — Read-only / idempotent, no mutation.** The list/search/sort/paginate flow performs no writes,
   no SOAP, no posting, and no state transition — it is fully idempotent. The Blesta filter widget submits via
   **POST** (Widget convention); that is acceptable because the request is read-only. The forbidden thing is a
   *mutating* request, not a POST: do not add any route that changes Voucher state, and no "Force Paid" or any
   paid-state action exists on this screen. *(NFR14; architecture "GET is read-only" / "no GET admin route that mutates Voucher state"; additional-req "No force paid action in MVP")*

6. **AC6 — Safe status rendering.** Status is rendered through a **closed allowlist** mapping each of the 8
   canonical states to a language-keyed label and a badge class. A DB value must never be concatenated into a
   language key or echoed unescaped. Unknown/empty status falls back to a safe generic label. **Success / "paid" /
   green treatment and the Blesta transaction link appear only for the `posted` state.** *(UX-DR19, UX-DR20; 2-5 review gotcha)*

7. **AC7 — No secret/leakage in the list.** The list shows only safe summary fields. It must NOT render
   `diagnostic_summary`, raw SOAP/XML, credentials, SOAP operation names, parser-internal field names,
   stack traces, or internal exception classes. Amounts render as decimal strings (never PHP float math). *(NFR8, NFR13, UX-DR28)*

8. **AC8 — Allowlisted sort/filter inputs.** Request-controlled `sort`, `order`, and filter keys are validated
   against fixed allowlists before reaching the `Record` query builder. No request value is interpolated into
   `order()`, `where()` field names, or SQL. *(project-context Record rule; security)*

## Tasks / Subtasks

### Review Findings

- [x] [Review][Patch] AJAX filter requests render the full page instead of the widget response [plugins/kuickpay_reconcile/controllers/admin_vouchers.php:125]
- [x] [Review][Patch] Fresh POST filters keep the old page number and can show false no-results [plugins/kuickpay_reconcile/controllers/admin_vouchers.php:60]
- [x] [Review][Patch] Filter URLs are manually encoded and can produce malformed persisted filter query strings [plugins/kuickpay_reconcile/controllers/admin_vouchers.php:103]
- [x] [Review][Patch] Amount filter truncates non-zero extra decimal places instead of treating them as no-match input [plugins/kuickpay_reconcile/models/kuickpay_vouchers.php:439]

- [x] **Task 1 — Create the `admin_vouchers` controller** (AC: 1,2,3,4,5,8)
  - [x] Create `plugins/kuickpay_reconcile/controllers/admin_vouchers.php`, class `AdminVouchers extends KuickpayReconcileController`, mirroring the `AdminMain` preAction wiring (`parent::preAction()`, `$this->requireLogin()`, restore `structure->setView(null, $this->orig_structure_view)`). See `controllers/admin_main.php:8-21`.
  - [x] In `preAction()` (after `parent::preAction()`), **explicitly load the models and presenter** — they are NOT auto-loaded (the existing `admin_main` controller loads none, so do not assume it): `Loader::loadModels($this, ['KuickpayReconcile.KuickpayVouchers', 'KuickpayReconcile.KuickpayVoucherInvoices'])` and `Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayVoucherListPresenter.php')`. Calling an unloaded model (e.g. `$this->KuickpayVouchers->getList()`) is a fatal error. Loader pattern reference: `lib/KuickPayVoucherRepository.php:18-21`.
  - [x] Implement `index()` = the searchable list action. Resolve `sort`/`order`/`page` from `$this->get`, validate `sort`/`order` against the presenter allowlist (default `date_created` / `desc`). **Company scope is passed as a dedicated argument, NOT smuggled inside `$filters`** (see Task 3): never read `company_id` from request.
  - [x] Read filter values from `$this->post['filters']` **and** `$this->get['filters']` (Blesta filter convention; strip empties), sanitize via the presenter allowlist (Task 4), and set `filter_vars` back to the view so selections persist (AC2/UX-DR12). Reading from GET as well as POST is what lets filters survive pagination and the browser Back button (see Dev Notes "Filter persistence (AC2)").
  - [x] Call `KuickpayVouchers::getList($this->company_id, $filters, $page, [$sort => $order])` and `KuickpayVouchers::getListCount($this->company_id, $filters)` (Task 3) — `company_id` is the first, mandatory argument.
  - [x] Batch-resolve invoice mappings for the page's voucher IDs via `KuickpayVoucherInvoices::getByVoucherIds($voucher_ids, $this->company_id)` (Task 3) — do NOT loop per row (avoid N+1).
  - [x] Batch-resolve the human-readable client code for the page's **unique** `client_id`s into a `clients_by_id` lookup via `KuickpayVouchers::getClientCodes($unique_client_ids, $this->company_id)` (Task 3) — ONE company-scoped query that selects only the computed `id_code`, never the full client object (AC7: no contact PII in the list). Do NOT call `Clients::get()` per id (it runs multiple queries + dispatches a `Clients.get` event + pulls contact PII) and do NOT use `Clients::getList()` (it cannot filter by an id set and paginates). Do NOT join `clients.id_code` in the voucher query — `id_code` is a computed `REPLACE(clients.id_format, ?, clients.id_value)` expression (`app/models/clients.php:1973`), NOT a selectable column.
  - [x] Configure pagination with `$this->setPagination($this->get, $settings)` (uri `plugin/kuickpay_reconcile/admin_vouchers/index/[p]/`, **`params` must include `sort`/`order` AND every active filter value** so selections persist across pages and Back — see Dev Notes "Filter persistence (AC2)") and `return $this->renderAjaxWidgetIfAsync(...)`. Copy the exact wiring from `app/controllers/admin_clients.php:115-216`, but note `admin_clients` is POST-only and does NOT carry filters in `params` — you MUST add that.
  - [x] `set()` to the view: `vouchers`, `invoices_by_voucher`, `clients_by_id`, `filter_vars`, `filters` (filter UI definition built in the controller — see Dev Notes "Filter widget definition"), `sort`, `order`, `negate_order`, and the presenter for label/badge lookups.

- [x] **Task 2 — Register nav entry, ACL permission, and bump version** (AC: 1,4,5)
  - [x] In `kuickpay_reconcile_plugin.php::getActions()` ADD a second nav entry for the voucher list (uri `plugin/kuickpay_reconcile/admin_vouchers/index/`, parent `billing/`) — KEEP the existing `bulk_reconcile` entry (lines 189-197).
  - [x] In `getPermissions()` ADD a permission entry `alias => 'kuickpay_reconcile.admin_vouchers'`, `group_alias => 'admin_billing'`, `action => '*'` — KEEP the existing `kuickpay_reconcile.admin_main` entry (lines 204-214). **Both must be returned** (upgrade deletes+re-adds the whole set; see Dev Notes "Plugin upgrade re-sync"). `action => '*'` is acceptable here **only because `admin_vouchers` is a read-only "view records" controller** (this story's `index()`; 4.2 adds `detail()` — the same view-records capability). **Forward constraint:** diagnostics visibility (4.2) and any mutating/posting-capable actions (4.3/4.4) MUST be gated by their own separate permissions per `architecture.md:361-367` and MUST NOT be added under this blanket grant — `admin_vouchers` stays view-only. See Dev Notes "ACL scope".
  - [x] Add the two new language keys (`KuickpayReconcilePlugin.nav_secondary_staff.vouchers`, `KuickpayReconcilePlugin.permission.vouchers`) to `language/en_us/kuickpay_reconcile_plugin.php`.
  - [x] Bump `config.json` `version` `1.4.0` → `1.5.0`, and add a `version_compare($current_version, '1.5.0', '<')` branch to `upgrade()` documenting that this version only re-registers nav/permission (no schema change).

- [x] **Task 3 — Extend the model with rich filtering + count** (AC: 1,4,8)
  - [x] **Make `company_id` a mandatory, separate parameter — not an optional filter.** The current `getList(array $filters, …)` applies `company_id` inside the same optional loop as `status`/`client_id` (`kuickpay_vouchers.php:293-296`); if a caller ever omits it, the screen leaks **every company's** vouchers. Every other method in this model already takes `int $company_id` as a required first arg (`getByConsumerNumber`, `getPostable`, `getExpirable`, `getForUpdate`, …). Change the signatures to `getList(int $company_id, array $filters, int $page = 1, array $order_by = …)` and have `applyListFilters()` apply `where('company_id', '=', $company_id)` **unconditionally** (not from `$filters`). This closes the 3-3 hardening gap at the model layer, not just the call site.
  - [x] Refactor the per-field filter loop into a shared `private function applyListFilters(int $company_id, array $filters): void` so `getList` and the new `getListCount` cannot drift. It applies the mandatory `company_id` first, then the allowlisted `$filters`.
  - [x] Expand the allowlisted filters to the FR24 set per the Filter→Column map in Dev Notes: `status` (exact, validated against the model's own `self::STATUSES`), `client_id` (exact), `consumer_number` (LIKE), `registration_number` (LIKE), `kuickpay_reference` (LIKE → "transaction/auth fields"), `amount` (**exact, normalized decimal string — NOT a range**; see Dev Notes "Amount filter"), `date_from`/`date_to` (`date_created` BETWEEN), `blesta_transaction_id` (**boolean "has Blesta transaction" → `IS NOT NULL`**; no free-text id), and `invoice_id` (EXISTS subquery on `kuickpay_voucher_invoices` to avoid row multiplication).
  - [x] `STATUSES` is `private` — validate the `status` filter against `self::STATUSES` **inside the model** (private access is fine in-class); do NOT read `KuickpayVouchers::STATUSES` from the controller/presenter. Add `public static function getStatuses(): array { return self::STATUSES; }` for the **controller** to source the status-select options. (The pure-seam presenter must NOT call this — see Task 4 note.)
  - [x] Add `public function getListCount(int $company_id, array $filters): int` that applies the same conditions via `applyListFilters()` and returns `->numResults()`.
  - [x] Add `getClientCodes(array $client_ids, int $company_id): array` to `models/kuickpay_vouchers.php` returning `[client_id => id_code]` in **one** company-scoped query: `select(['clients.id', 'REPLACE(clients.id_format, ?, clients.id_value)' => 'id_code'])->appendValues(['{num}'])->from('clients')->innerJoin('client_groups', 'client_groups.id', '=', 'clients.client_group_id', false)->where('client_groups.company_id', '=', $company_id)->where('clients.id', 'in', $client_ids)->fetchAll()`, then key by `->id`. **Guard the empty set** (`if (!$client_ids) { return []; }`) — `WHERE … IN ()` is a MySQL syntax error. Mirror the `id_code` field/`appendValues` idiom from `Clients::getAClient()` (`app/models/clients.php:1971-1987`).
  - [x] Add `getByVoucherIds(array $voucher_ids, int $company_id): array` to `models/kuickpay_voucher_invoices.php` returning `[voucher_id => [{invoice_id, amount}, …]]` in a single query: `WHERE voucher_id IN (...)` joined to `kuickpay_vouchers` for company scope (`innerJoin('kuickpay_vouchers', 'kuickpay_vouchers.id', '=', 'kuickpay_voucher_invoices.voucher_id', false)->where('kuickpay_vouchers.company_id', '=', $company_id)`) — the links table has **no `company_id` column** of its own (`plugin:75-84`). **Guard the empty `$voucher_ids` set** (`return []`) to avoid `IN ()`. Group the flat `fetchAll()` (stdClass rows — use `$r->voucher_id`) in PHP.
  - [x] Do NOT join `contacts` anywhere (no client-name search this story — MVP filters by `client_id`).

- [x] **Task 4 — Pure presenter helper (testable seam)** (AC: 6,8)
  - [x] Create `plugins/kuickpay_reconcile/lib/KuickPayVoucherListPresenter.php` (pure PHP, NO DB, loaded via `Loader::load(PLUGINDIR . 'kuickpay_reconcile' . DS . 'lib' . DS . 'KuickPayVoucherListPresenter.php')`) holding the closed allowlists and lookup methods: `STATUS_LABEL_KEYS`, `STATUS_BADGE_CLASSES`, `SORTABLE_FIELDS`, `labelKeyFor(string $status): string`, `badgeClassFor(string $status): string`, `allowedSort(?string $field, string $default): string`, `allowedOrder(?string $order, string $default = 'desc'): string` (accepts only `asc`/`desc` case-insensitive, else returns `$default`), and `sanitizeFilters(array $raw): array`.
  - [x] Enumerate `SORTABLE_FIELDS` explicitly: `date_created`, `client_id`, `consumer_number`, `status`, `date_last_checked` (default `date_created`). **Exclude `amount`** — it is `varchar(20)`, so a SQL sort is lexicographic (`"100" < "9"`), which is misleading; amount is display/filter-only in 4.1. **Exclude `blesta_transaction_id`** — it is filter-only (the "has transaction" toggle); the Transaction column is non-sortable. **Exclude** `invoice mapping` (a batched EXISTS/subquery result, not a single column). Note: only `status`, `client_id` are indexed among these — `consumer_number`/`date_*` sorts are full scans, acceptable for this read-only list.
  - [x] The presenter is **pure PHP / no-DB** and unit-tested without the framework, so it CANNOT call `KuickpayVouchers::getStatuses()` (loading the model pulls in `AppModel`/DB). The presenter therefore keeps its **own** `STATUS_LABEL_KEYS`/`STATUS_BADGE_CLASSES` enumerations as the closed allowlist; the single-source-of-truth is enforced at the **controller** layer (controller sources status-select options from `getStatuses()`) and **verified by a test** asserting the presenter's status-map keys equal the canonical 8 (Task 7).
  - [x] `badgeClassFor()` returns a success/paid class ONLY for `posted`; all non-posted states return info/secondary/warning/danger per the map in Dev Notes (never success). Unknown status → safe default label + neutral badge.

- [x] **Task 5 — Build the list view** (AC: 1,2,3,6,7)
  - [x] Create `plugins/kuickpay_reconcile/views/default/admin_vouchers.pdt`. Use `$this->Widget` with `setFilters($filters, $uri, !empty($filter_vars))` + `setAjaxFiltering()` (copy `app/views/admin/paradigm/admin_clients.pdt:35-43`) so filters render above and persist. **`$filters` MUST be the `InputFields` object the controller built (Dev Notes "Filter widget definition"), NOT a plain array** — `Widget::setFilters()` is type-hinted `setFilters(InputFields $filters, …)` and a plain array throws a fatal `TypeError`.
  - [x] Render a Blesta responsive table with semantic `<th>` headers. **Sortable** header links (allowlisted sort only, via `allowedSort()`/`allowedOrder()`) for: Created date, Client, Consumer Number, Status, Last inquiry. Render **Amount**, **Invoice mapping**, and **Transaction** as plain (non-sortable) headers (Amount is varchar → no meaningful SQL sort; the other two are not single columns).
  - [x] Per row: `date_created` (Date helper); client cell = `clients_by_id[client_id]` (human-readable code) linked to `clients/view/{client_id}`, falling back to the raw `client_id` if the lookup misses; **invoice id(s)** — loop `invoices_by_voucher[voucher_id]` (a voucher can map to MULTIPLE invoices), each linked to `billing/invoices/edit/{invoice_id}`; `amount` + `currency`; `consumer_number` in `font-monospace`; status badge via presenter (text + class); `date_last_checked` (last inquiry time; "—" when null); and the Blesta transaction link ONLY when `status === 'posted'` and `blesta_transaction_id` set.
  - [x] Escape every dynamic value with `$this->Html->safe(...)`. Put `consumer_number`, `registration_number`, and `kuickpay_reference` in `font-monospace`; everything else inherited typography (UX-DR26).
  - [x] No-results branch: render the localized "No Vouchers match these filters." message while the filter widget stays visible (AC3/UX-DR27).
  - [x] Pagination footer: `if ($this->Pagination->hasPages()) { $this->Widget->footer(); $this->Pagination->build(); }` (copy `admin_clients.pdt:195-200`).

- [x] **Task 6 — Language file for the new controller** (AC: 1,2,3,6)
  - [x] Create `plugins/kuickpay_reconcile/language/en_us/admin_vouchers.php` (auto-loaded by the base controller via `Loader::fromCamelCase('AdminVouchers')` → `admin_vouchers`). Add keys: box title, every column heading, every filter label, each status label (`AdminVouchers.status.pending` … `.cancelled`), the no-results message, and the empty-cell placeholder. The **client filter label must read "Client ID"** (not "Client") with a short helper/placeholder so the agent knows to enter the numeric id (decided 2026-06-12). NO hard-coded UI strings anywhere in controller/view.

- [x] **Task 7 — Tests + verification** (AC: 6,8)
  - [x] Add `plugins/kuickpay_reconcile/tests/KuickPayVoucherListPresenterTest.php` covering: every status → correct label key + badge class; `posted` is the only success/paid badge; unknown/empty status → safe default; `allowedSort()` rejects arbitrary input, falls back to default, and only ever returns a member of `SORTABLE_FIELDS` (which excludes `amount`/`blesta_transaction_id`/invoice mapping); `allowedOrder()` accepts only `asc`/`desc` (case-insensitive) and falls back to default otherwise; `sanitizeFilters()` drops unknown keys and empty values, keeps the allowlisted set, and rejects an out-of-range `status`; and a **source-of-truth cross-check** asserting the presenter's status-map keys equal exactly the canonical 8 states (hard-coded here, since the pure-seam presenter cannot load `KuickpayVouchers::getStatuses()` — this test is what catches drift if the model's `STATUSES` changes).
  - [x] Run the plugin suite with the external runner: `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` (NOT `-c build/phpunit.xml`). Register the new test's class require in `tests/bootstrap.php` if it uses manual requires.
  - [x] `php -l` on every changed/added PHP file (controller, model, voucher_invoices model, presenter, plugin handler).
  - [x] State exactly what ran and what could not (DB-backed install/upgrade smoke, live admin render) per NFR12 — do not claim root/`../tests` coverage.

## Dev Notes

### CRITICAL — Where this screen lives (architecture vs. current code; resolved)

There is a **deliberate variance to resolve**: the architecture prescribes a dedicated
`plugins/kuickpay_reconcile/controllers/admin_vouchers.php` for "search, list, and detail only"
(`architecture.md` §FR-24..FR-27, lines 791-792, 842-849), but Epic 3 only shipped a **minimal**
`admin_main` bulk-reconcile trigger (`controllers/admin_main.php`) and explicitly **deferred the admin
workbench to Epic 4** (3-7 scope decision; epic-3 retro).

**Decision (follow the architecture):** Build the Voucher List as a **new `admin_vouchers` controller +
`admin_vouchers.pdt` view + `admin_vouchers.php` language file**, registering its own nav entry and ACL
permission. **Do NOT extend `admin_main`** — `admin_main` stays the bulk-reconcile trigger (its eventual
migration to `admin_reconciliation.php` belongs to Story 4.4, not here). This sets Epic 4 up correctly:
4.2 adds a `detail()` action to the SAME `admin_vouchers` controller. (Decision recorded in the Decisions section.)

### What already exists (read before writing — exact anchors)

- **Voucher table** `kuickpay_vouchers` (`kuickpay_reconcile_plugin.php:38-73`) — no change needed. Columns
  available to this story: `id, company_id, gateway_id, client_id, currency, amount(varchar 20),
  status(enum 8), registration_number, consumer_number, date_due, date_expires, date_created, date_updated,
  date_posted, date_paid, date_last_checked, retry_count, error_class, raw_status, evidence_hash,
  kuickpay_reference(varchar 128), blesta_transaction_id(nullable, indexed), diagnostic_summary(text),
  admin_notes(text)`. Indexes already present on `status`, `client_id`, `blesta_transaction_id`, plus the two
  company-scoped unique keys.
- **Status enum** (single source of truth): `KuickpayVouchers::STATUSES` (`models/kuickpay_vouchers.php:13-22`)
  = `pending, retry, confirmed_unposted, posted, failed, expired, manual_review, cancelled`.
- **Existing list method**: `KuickpayVouchers::getList()` (`models/kuickpay_vouchers.php:289-302`) — currently
  allowlists only `status, client_id, company_id`, uses `getPerPage()` for limit/offset, default order
  `date_created DESC`. **No current caller** (grep clean) → safe to extend. **No count method exists** → add one.
- **Invoice links** `kuickpay_voucher_invoices` (`plugin:75-84`): `voucher_id, invoice_id, amount, date_created`.
  Model `models/kuickpay_voucher_invoices.php` has `getByVoucherId()` / `getByInvoiceId()` — add a batched
  `getByVoucherIds()`.
- **Admin controller pattern**: `controllers/admin_main.php:8-21` (preAction) + base
  `kuickpay_reconcile_controller.php:13-23` (loads `language/en_us/<controller>.php` via `fromCamelCase`,
  sets default view). A new `AdminVouchers` controller auto-loads `admin_vouchers.php` language by the same mechanism.
- **Current admin view**: `views/default/admin_main.pdt` uses `$this->Widget` + `$this->Form` helpers — thin,
  variable-driven, language-keyed. Follow the same helper style.
- **Nav + permission**: `getActions()` (`plugin:187-197`) and `getPermissions()` (`plugin:204-214`).
- **Plugin version**: `config.json` = `1.4.0`.

### Filter → column mapping (AC1)

| Filter (FR24) | Source | Match | Notes |
|---|---|---|---|
| status | `kuickpay_vouchers.status` | exact, validated vs `STATUSES` | reject unknown values |
| client | `kuickpay_vouchers.client_id` | exact id | MVP filters by `client_id`; client code resolved in controller via `Clients` (no `id_code` join); no name search |
| invoice ID | `kuickpay_voucher_invoices.invoice_id` | `EXISTS` subquery | avoids voucher-row multiplication |
| Consumer Number | `kuickpay_vouchers.consumer_number` | LIKE (partial) | monospace field |
| date range | `kuickpay_vouchers.date_created` | `>= date_from`, `<= date_to` | date inputs |
| amount | `kuickpay_vouchers.amount` (varchar 20) | **exact, normalized decimal string** | **string compare, never float; NO range** — varchar sorts lexicographically; see "Amount filter" |
| KuickPay transaction/auth fields | `kuickpay_vouchers.kuickpay_reference` | LIKE | there is **no separate auth column**; this is the stored KuickPay reference |
| Blesta transaction link | `kuickpay_vouchers.blesta_transaction_id` | `IS NOT NULL` | **MVP = boolean "has Blesta transaction" toggle**; no free-text id match |
| (optional) Registration Number | `kuickpay_vouchers.registration_number` | LIKE | durable identity; monospace |

### Column → source mapping (AC2)

| Column | Source | Format |
|---|---|---|
| Created date | `date_created` | Date helper |
| Client | `client_id` → `clients_by_id[client_id]` (code resolved in controller) | link → `clients/view/{client_id}` |
| Invoice mapping | `invoices_by_voucher[voucher_id]` = `[{invoice_id, amount}, …]` (may be MULTIPLE) | link(s) → `billing/invoices/edit/{invoice_id}` |
| Amount | `amount` + `currency` | decimal string |
| Consumer Number | `consumer_number` | **`font-monospace`** |
| Status | `status` | presenter label + badge (text + class) |
| Last inquiry time | **`date_last_checked`** | Date helper; "—" when null. **Do not join reconciliation_runs** — this column is the per-voucher last-inquiry timestamp, updated by reconcile (3-3). |
| Transaction | `blesta_transaction_id` | link **only when `status === 'posted'`** |

### Resolved scope & filter semantics (the four open questions are decided)

The questions at the end of this file are **decided and folded into the ACs/Tasks above** — do not re-litigate:

- **Client filter:** MVP filters by **`client_id` exact**, rendered as a text input **labelled "Client ID"** (not "Client") with helper text (decided 2026-06-12). NO free-text client-name search, NO dropdown, NO `contacts` join this story (avoids row multiplication / N+1). The human-readable client code is shown in result rows, resolved via the dedicated **`KuickpayVouchers::getClientCodes()`** model method (Task 3) — one company-scoped `IN(...)` query returning only `id_code` — NOT a `clients.id_code` column join (it is a computed `REPLACE(...)` expression, `app/models/clients.php:1973`) and NOT `Clients::get()`/`getList()` (per-id events + PII / no id-set filter). A name-search is a later enhancement.
- **Amount filter:** MVP is a **single exact match on a normalized decimal string** — NO min/max range. See "Amount filter" below.
- **Blesta transaction filter:** MVP is a **boolean "has Blesta transaction" toggle** → `blesta_transaction_id IS NOT NULL`. No free-text id match.
- **Controller location & ACL granularity:** decided in "CRITICAL — Where this screen lives" and "ACL scope" — new `admin_vouchers` controller; one view-records permission for now.

### Amount filter (varchar exact-match, normalized — no range)

`amount` is `varchar(20)` (e.g. `"100.00"`). A **range** filter is forbidden: `Record->where('amount','>=',$min)` triggers MySQL **lexicographic** comparison (`"9" > "100"`, `"1000" >= "100"` matches), silently returning wrong rows. Exact match on the raw string is also brittle (`100` won't match `"100.00"`). So: normalize the user input to a canonical decimal string (trim, single `.`, pad to 2 dp) and compare `where('amount','=',$normalized)` — still string-based, never float, never `>=`/`<=`. Put the normalization in the model's `applyListFilters()` so `getList`/`getListCount` agree. (The epic-2/3 `bill-payment-inquiry-paid-trailing-zero.xml` fixture is the cautionary precedent.) If a true numeric range is ever needed it must `CAST(amount AS DECIMAL(12,4))` or compare integer minor units — out of scope here.

### Filter widget definition (`$filters` is an `InputFields` object, NOT a plain array)

**Critical contract:** `Widget::setFilters()` is type-hinted `setFilters(InputFields $filters, $uri, $show_filters = false)` (`core/Util/Widgets/AbstractWidget.php:149`). Passing a plain array throws a fatal `TypeError` and the whole page 500s. So the controller MUST build a `Blesta\Core\Util\Input\Fields\InputFields` object — exactly how `admin_clients` does it via its `ClientFilters` helper (`core/Util/Filters/ClientFilters.php`).

Build it inline in the controller (or, cleaner, a small `Blesta\Core\Util\Filters\ClientFilters`-style helper — your call; no `contacts`/Clients dependency needed here). The proven pattern, per field:

```php
$fields = new InputFields();                         // namespace Blesta\Core\Util\Input\Fields\InputFields
$status = $fields->label(Language::_('AdminVouchers.filter.status', true), 'status');
$status->attach($fields->fieldSelect(
    'filters[status]',                               // HTML name MUST be filters[<key>]
    ['' => Language::_('AdminVouchers.filter.any', true)] + $status_options,
    $filter_vars['status'] ?? null,
    ['id' => 'status', 'class' => 'form-control']
));
$fields->setField($status);
// …repeat fieldText() for client_id, consumer_number, registration_number, kuickpay_reference,
//   amount, invoice_id, date_from, date_to; fieldCheckbox() for has_blesta_transaction…
$this->set('filters', $fields);                      // pass the InputFields object to the view
```

Notes: every field's HTML `name` MUST be `filters[<key>]` (that is how the controller reads `$this->post['filters']`). `status` options come from `KuickpayVouchers::getStatuses()` mapped through the presenter labels. `date_from`/`date_to` use `fieldText()` with a date placeholder (Blesta's own `last_seen` filter uses `fieldText`, not an HTML5 date input — match it). `has_blesta_transaction` is a `fieldCheckbox('filters[has_blesta_transaction]', '1', …)` the model translates to `IS NOT NULL`. Keep all labels in `admin_vouchers.php` (Task 6).

### Filter persistence across pagination + Back button (AC2)

AC2 requires filters to stay **visible AND selected** after paginating or returning from detail. The stock `admin_clients` pattern does NOT deliver this: it reads filters from **POST only** and its pagination `params` carry just `sort`/`order` — so clicking page 2 (a GET) or pressing Back re-runs the list **unfiltered with empty filter fields**. To meet AC2 you must, additionally:
1. Read filters from `$this->post['filters']` **and** `$this->get['filters']` (POST on first apply; GET on pagination/Back).
2. Put every active filter value into the pagination `params` so they are encoded into the page links and the list URL (this is what makes the Back button restore state — no session needed).
3. Re-seed `filter_vars` from the merged filters so the `InputFields` re-render with their values selected.
This is stateless (URL-driven). Do not rely on session storage.

### ACL scope (read-only controller)

`admin_vouchers` carries ONE permission, `kuickpay_reconcile.admin_vouchers` (`action => '*'`), and is a **read-only "view records" controller** — `index()` now, `detail()` in 4.2, both the same "view records" capability (`architecture.md:361-367`). The blanket `*` is acceptable ONLY because this controller never hosts a state-changing action. **Diagnostics visibility (4.2) and all mutating/posting-capable actions (4.3 manual actions, 4.4 manual-review) MUST live behind their own separate permissions and MUST NOT be added to this controller under this grant.** This matches the existing convention (the `admin_main` bulk-reconcile permission is also `*`, scoped by being its own controller).

### Status → label/badge allowlist (AC6) — presenter map

`posted` is the **only** state that may use a success/paid (green) badge or expose the transaction link.

| Status | Label key | Badge intent |
|---|---|---|
| pending | `AdminVouchers.status.pending` | info |
| retry | `AdminVouchers.status.retry` | info |
| confirmed_unposted | `AdminVouchers.status.confirmed_unposted` | info (NOT success — validated evidence ≠ paid) |
| posted | `AdminVouchers.status.posted` | **success** |
| failed | `AdminVouchers.status.failed` | danger |
| expired | `AdminVouchers.status.expired` | secondary/muted |
| manual_review | `AdminVouchers.status.manual_review` | warning |
| cancelled | `AdminVouchers.status.cancelled` | secondary/muted |
| (unknown/empty) | safe generic key | neutral |

### Plugin upgrade re-sync (non-obvious — prevents wasted manual SQL)

`PluginManager::upgrade()` only runs when `config.json` version ≠ installed version
(`app/models/plugin_manager.php:354`). On a version change it **deletes and re-adds the plugin's entire
permission set** (`:392-395`) and **entire action set** (`:459-467`) from the live `getPermissions()` /
`getActions()` return values. Therefore:
- To surface the new nav + permission, **bump the version** and edit `getActions()`/`getPermissions()` — **no
  manual INSERT SQL**.
- Because the whole set is replaced, **getActions()/getPermissions() must KEEP the existing `bulk_reconcile`
  nav and `kuickpay_reconcile.admin_main` permission** alongside the new ones, or they will be dropped.
- Staff-group ACL grants for the plugin may need re-granting after upgrade (standard Blesta behavior for any
  plugin upgrade; not specific to this story).

### Controller / pagination / AJAX (copy the proven pattern)

Canonical reference (verified present): `app/controllers/admin_clients.php` + `app/views/admin/paradigm/admin_clients.pdt`.
Reuse exactly:
- Param parsing + empty-filter stripping: `admin_clients.php:115-135`.
- `getList` + count + view `set()`: `:144-173`.
- `setPagination` settings (`Configure::get('Blesta.pagination')`, `uri`, `params`): `:202-213`.
- `renderAjaxWidgetIfAsync(...)`: `:216`.
- View filter wiring (`setFilters` + `setAjaxFiltering`), sortable headers, no-results, pagination footer:
  `admin_clients.pdt:35-43, 59-62, 187-200`.

`setPagination`, `renderAjaxWidgetIfAsync`, and the `Pagination` helper are standard Blesta `Controller`
methods (framework core) — available to the plugin controller via `AppController`.

### Technical requirements / guardrails

- **Read-only / idempotent** (AC5/NFR14): no writes, no SOAP, no posting, no state change on this screen. The
  filter widget submits via **POST** (Blesta Widget convention) — that is fine; only a **state-mutating** request
  is forbidden (`architecture.md:657` "no GET admin route that mutates Voucher state"). Do NOT equate "read-only"
  with "no POST".
- **Company scoping everywhere** (AC4): pass `$this->company_id` as the dedicated, mandatory argument to every model query (`getList`/`getListCount`/`getClientCodes`/`getByVoucherIds`); never read `company_id` from request, never bury it in `$filters`.
- **Allowlist all request-controlled query inputs** (AC8): sort field, order direction, and filter keys
  validated against fixed lists before touching `Record`. Never interpolate request values into `order()` /
  `where()` field names (project-context "use allowlists before request-controlled field/sort/operator names").
- **Amounts as strings** (NFR13): display/compare `amount` as the stored decimal string; no float math.
- **No leakage** (AC7/NFR8/UX-DR28): never render `diagnostic_summary`, raw SOAP/XML, credentials, SOAP op
  names, parser-internal field names, or exception classes. `$this->Html->safe()` on every dynamic value.
- **Status via allowlist** (AC6): never build a language key by concatenating a DB status value; map through the
  presenter (repeat of the 2-5 review finding "the view must not concatenate the status into a key").
- **PHP 8.2 target**; match each file's existing namespace/type-hint style (plugin files are legacy global
  classes, no `declare(strict_types=1)`). Use Blesta `Loader`, `Record`, `Input`, `Language` APIs only.

### Library / framework requirements

- **Bootstrap 5.3.8** — the admin **paradigm** theme (where `admin_vouchers.pdt` actually renders) is Bootstrap
  v5.3.8 (version marker in `app/views/admin/paradigm/javascript/app.js`; across that theme `font-monospace`/`data-bs-toggle`
  are used and `text-monospace`/`data-toggle` appear **zero** times). Use **`font-monospace`** for monospace cells —
  `.text-monospace` was removed in BS5 and is a **dead no-op** in this theme. NOTE: `process.pdt:43` does use BS4
  `text-monospace`, but that is a **customer-facing gateway view in a different theme context** — NOT a precedent for
  admin views; copy `admin_clients.pdt` (BS5) instead. Use Blesta `Widget`/`Form`/`Html`/`Pagination`/`Date` helpers
  and Bootstrap Icons (`bi bi-*`). No new CSS/JS assets, no new JS framework.
- `.pdt` views only (no Twig/Blade). Thin views: render assigned variables; no SQL or business logic in the view.

### Testing requirements

- This checkout has **no root `../tests`** and **no live DB**. Controllers/models that hit `Record` cannot be
  unit-tested here. Follow the established **pure-seam** convention: put all testable logic
  (status→label/badge, sort/filter allowlisting, filter sanitization) in `KuickPayVoucherListPresenter`
  (no DB) and unit-test it.
- Runner (from `project-context.md:73-74`): `cd plugins/kuickpay_reconcile &&
  /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`. **Never** use
  `-c build/phpunit.xml` (broken bootstrap path). Wire any manual `require_once` for the new test/class in
  `tests/bootstrap.php` per the existing fixture-DI pattern.
- `php -l` on every changed PHP file. Report exactly what ran and what was unavailable (DB-backed
  install/upgrade smoke, live admin render) per NFR12. Do not overstate coverage.

### Project Structure Notes

- **Aligned**: new files land under the plugin per the additional-requirements boundary
  (`epics.md:118-124`): `controllers/admin_vouchers.php`, `views/default/admin_vouchers.pdt`,
  `language/en_us/admin_vouchers.php`, `lib/KuickPayVoucherListPresenter.php`, model edits to
  `models/kuickpay_vouchers.php` and `models/kuickpay_voucher_invoices.php`, handler/nav/permission edits to
  `kuickpay_reconcile_plugin.php`, version bump in `config.json`, test in `tests/`.
- **Variance (resolved, documented above)**: architecture names `admin_vouchers.php`; current code only has
  `admin_main.php`. We follow the architecture and add `admin_vouchers` rather than overloading `admin_main`.
- **No schema change**: read-only over existing tables; the only DB-facing change is the model query
  extension. The version bump exists solely to trigger nav/permission re-sync.

### References

- `epics.md` Story 4.1 ACs (lines 728-748); FR24 (line 71); UX-DR11/12/19/20/23/24/25/26/27/28 (lines 172-206);
  additional requirements / file-location & ACL rules (lines 117-148).
- `architecture.md` admin controller ownership & FR-24..27 file map (lines 791-792, 842-849); UI display-state
  matrix & "no success styling until posted" (lines 424, 597-606, 659); separate ACL permissions (lines 361-366);
  redaction boundary / no-secrets-in-views (lines 373-374, 608, 656); decimal-not-float (line 658);
  DB naming/date columns (lines 530-538).
- Code: `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php:38-84, 187-214`;
  `models/kuickpay_vouchers.php:13-22, 289-302`; `controllers/admin_main.php:8-21`;
  `kuickpay_reconcile_controller.php:13-23`; `views/default/admin_main.pdt`;
  `language/en_us/admin_main.php`; gateway monospace `components/gateways/nonmerchant/kuickpay/views/default/process.pdt:43`.
- Pagination/filter reference: `app/controllers/admin_clients.php:115-216`,
  `app/views/admin/paradigm/admin_clients.pdt:35-200`.
- Upgrade re-sync: `app/models/plugin_manager.php:341-467`.
- `project-context.md` (PHP 8.2, Blesta loader/Record/Input rules, external PHPUnit 8.5 runner).

### Previous Story Intelligence (Epics 1–3 — apply these or repeat past review cycles)

- **Class casing** (2-1): framework instantiates plugin classes by name — `AdminVouchers`, `KuickpayVouchers`,
  `KuickpayReconcilePlugin`; lib services are `KuickPay*` (capital P) loaded via `Loader::load`. Match exactly.
- **Company-scope every query** (3-3 hardening, deferred-work:52): the single most repeated finding — scope
  list AND count by `company_id`.
- **Status as closed allowlist** (2-5 review): never key language lookups on a DB value; map through a helper
  with a safe default.
- **Amount as string, never float** (Epic 2 retro, 3-5/3-8): applies to display and to any amount filter.
- **No raw SOAP/credentials/PII** anywhere visible (3-3 AC10; 3-8 leak scan `KuickPaySecretLeakageTest`).
- **Blesta footguns** (cumulative): `Record->fetch()` returns `stdClass` (use `->field`, not `['field']`);
  `Record->insert()` needs `lastInsertId()`; `Pagination` needs a separate total count; checkbox/button render
  quirks (not relevant to a read-only list but watch any future filter toggles).
- **Verification honesty** (all retros, NFR12): disclose PHP version actually used and exactly which suites ran.
- **Deferred items relevant here** (deferred-work.md): run-summary count partitioning and the bulk `run_date`
  upper bound are **Story 4.4's** problem — do NOT re-touch them in 4.1. A rare `confirmed_unposted` voucher with
  null `date_paid` is a valid latent state — the list should simply display it as-is (the posting cron filters it).

### Git Intelligence Summary

Epic 3 (reconciliation + posting + safety contracts) is fully `done`, followed by a round of KuickPay
**live-contract fixes** (aligning the InsertVoucher / single-inquiry payloads and the reconcile lock release
with KuickPay's real response shape). The voucher schema and reconcile pipeline are stable. This is the
**first Epic-4 admin story** — establish the admin-list controller/view/presenter patterns cleanly here because
4.2–4.5 will extend them.

### Project Context Reference

Follow `_bmad-output/project-context.md` verbatim — especially: PHP 8.2 only; Blesta loader/Record/Input/
Language APIs (no ad-hoc SQL beyond allowlisted `Record` queries); keep extension code inside
`plugins/kuickpay_reconcile/`; language-file-driven strings; do not edit ionCube/minified/vendored files; commit
style `<type>(<scope>): <summary>`; verify with `php -l` + component PHPUnit 8.5, never claim root `../tests`.

## Change Log

| Date | Change | Rationale |
|---|---|---|
| 2026-06-12 | Multi-agent validation triage (round 1) applied before dev. | Several findings were verified against live code and folded in. |
| 2026-06-12 | Multi-agent validation triage (round 2 — targeted lanes) applied. | Round-2 verification surfaced two page-breakers (filter widget type, company-scope leak) round 1 missed. |
| 2026-06-12 | Implemented Story 4.1 (all 7 tasks): models, presenter, controller, view, language, nav/ACL/version, tests. | Dev-story execution. Status → review. |
| 2026-06-12 | Invoice/transaction deep links corrected to `clients/editinvoice/{client_id}/{invoice_id}/` and `clients/edittransaction/{client_id}/{transaction_id}/`. | The spec's `billing/invoices/edit/{invoice_id}` route does not exist in this build (verified against live views/routes). |
| 2026-06-12 | Pagination hardened: `merge_get => false` + nested `filters` stripped from the GET passed to `setPagination`. | The minphp pagination URL builder concatenates param values naively and cannot stringify a nested filters array; flat `filters[<key>]` params are supplied explicitly instead. |

Validation-driven fixes (verified against the codebase, not taken on faith):
- **Monospace class corrected `text-monospace` → `font-monospace`** and the Bootstrap claim corrected to **5.3.8**. The admin paradigm theme is BS5.3.8 (`app/views/admin/paradigm/javascript/app.js`); `text-monospace` appears 0× there and is a dead no-op — the old guardrail would have silently defeated UX-DR26.
- **AC5 reworded from "GET only" to "read-only / idempotent."** The architecture forbids a *mutating* request, not a POST; the Blesta filter widget legitimately submits via POST. Removed the self-contradiction with Task 1.
- **`clients.id_code` join removed.** It is a computed `REPLACE(...)` expression (`app/models/clients.php:1973`), not a column; the client code is resolved out-of-band (see the Round-2 `getClientCodes()` note below). Client cell is no longer "optional" (AC2 guaranteed).
- **Amount filter pinned to exact normalized-decimal-string, range removed.** `amount` is `varchar(20)`; a range would compare lexicographically and return wrong rows.
- **Explicit model/presenter loading added to the controller** (`Loader::loadModels` + `Loader::load`) — the new controller is not auto-wired and `admin_main` loads none.
- **`allowedOrder()` added to the presenter + tests; `SORTABLE_FIELDS` enumerated** (DB columns only; invoice mapping / transaction are non-sortable).
- **`KuickpayVouchers::getStatuses()` accessor added** (the `STATUSES` const is `private`) so the filter options and presenter share one source of truth.
- **ACL `action => '*'` kept** (correct for a read-only view-records controller, consistent with `admin_main`) **with a forward constraint** that diagnostics (4.2) and mutating actions (4.3/4.4) use their own permissions.
- **The four open questions resolved** and promoted into the spec; **Git Intelligence Summary refreshed**; multi-invoice `invoices_by_voucher` shape and the filter-widget definition pinned.

Round-2 fixes (each verified against live source):
- **Filter widget is an `InputFields` object, not a plain array.** `Widget::setFilters()` is type-hinted `setFilters(InputFields $filters, …)` (`core/Util/Widgets/AbstractWidget.php:149`); a plain array 500s the page. Rewrote the "Filter widget definition" note with the real `InputFields`/`fieldText`/`fieldSelect`/`setField` recipe and `filters[<key>]` naming.
- **`company_id` made a mandatory separate parameter** on `getList`/`getListCount`/`applyListFilters` (applied unconditionally), matching every sibling method in the model. Closes a multi-tenant leak the old optional-filter pattern allowed if a caller omitted the key.
- **Client-code resolution replaced** with a dedicated `KuickpayVouchers::getClientCodes()` (one company-scoped `IN(...)` query, `id_code` only). The round-1 `Clients::get()`/`getList()` guidance was wrong — `getList()` can't filter by an id set and paginates; `get()` runs per-id queries + fires a `Clients.get` event + pulls contact PII.
- **`getByVoucherIds()` company-scoped** via JOIN to `kuickpay_vouchers` (the links table has no `company_id`); empty-array guards added to both batch lookups (`IN ()` is a MySQL syntax error).
- **`SORTABLE_FIELDS` trimmed** to `date_created, client_id, consumer_number, status, date_last_checked` — dropped `amount` (varchar → lexicographic sort) and `blesta_transaction_id` (filter-only); Amount column is now non-sortable. Resolves the sort-vs-non-sortable contradiction.
- **Presenter source-of-truth boundary clarified:** the pure-seam presenter keeps its own status allowlist (can't load the model at unit-test time); the controller sources options from `getStatuses()`; a test asserts the presenter's keys equal the canonical 8.
- **AC2 persistence made explicit:** read filters from POST *and* GET, encode active filters into pagination `params` (stateless/URL-driven) so they survive pagination and the Back button — the stock `admin_clients` pattern does not.
- Intro "read-only GET screen" → "read-only / idempotent"; stale "Open Question 1" pointer removed.

## Dev Agent Record

### Agent Model Used

claude-opus-4-8 (1M context) — BMad dev-story workflow.

### Debug Log References

- Full plugin suite (external PHPUnit 8.5): `cd plugins/kuickpay_reconcile && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests` → **105 tests, 572 assertions, 1 failure**. The single failure (`KuickPaySecretLeakageTest::testPersistedEvidenceAndAuditPayloadsContainNoSecretsOrRawEnvelopes`, `confirmed_unposted` vs `manual_review`) is **pre-existing**: reproduced on a clean `git worktree` of the baseline commit `12b71905` (2 tests, 1 failure) before any 4.1 change. It exercises reconcile-evidence logic untouched by this story.
- New `KuickPayVoucherListPresenterTest`: **18 tests, 89 assertions, all green**.
- `php -l`: clean on all 10 changed/added PHP files (controller, two models, presenter, two language files, plugin handler, view `.pdt`, test, bootstrap).
- `config.json` validated as JSON; version `1.5.0`.

### Completion Notes List

- **AC1 (filters):** Model `applyListFilters()` implements the full FR24 set — status (validated vs `self::STATUSES`), client_id (exact int), consumer_number/registration_number/kuickpay_reference (LIKE), amount (normalized exact decimal string, no range), date_from/date_to (created-date range, date_to padded to end-of-day), has_blesta_transaction (`IS NOT NULL`), invoice_id (`id IN (subquery)`, integer-cast inline to avoid the bound-value ordering hazard). Filter widget built as a real `InputFields` object.
- **AC2 (results render + persistence):** All required columns render. Filters persist visible (widget always rendered) and selected (`filter_vars` re-seeds the InputFields, and active filters are appended to sort-header hrefs and the pagination params). Pagination uses `merge_get => false` and strips the nested `filters` key from the GET handed to `setPagination` so the naive URL builder never stringifies an array.
- **AC3 (no matches):** Localized `AdminVouchers.no_results` ("No Vouchers match these filters.") renders with the filter widget still visible.
- **AC4 (company scope):** `company_id` is a mandatory first argument on `getList`/`getListCount`/`getClientCodes`/`getByVoucherIds`, applied unconditionally in `applyListFilters()` and never sourced from request input. The old optional-filter leak is closed at the model layer.
- **AC5 (read-only/idempotent):** `index()` performs no writes, SOAP, posting, or state transition. POST is used only by the filter widget. `admin_main` is left intact as the bulk-reconcile trigger.
- **AC6 (safe status rendering):** Status is mapped through the presenter's closed allowlist (label key + badge class); DB values are never concatenated into language keys. Only `posted` yields a success badge and exposes the transaction link; unknown/empty → safe default + neutral badge.
- **AC7 (no leakage):** The list renders only safe summary fields. No `diagnostic_summary`, raw SOAP/XML, credentials, op names, or exception classes. Amount renders as the stored decimal string (no float math). Client codes resolved via a dedicated id_code-only query (no contact PII).
- **AC8 (allowlisted sort/filter inputs):** `allowedSort()`/`allowedOrder()` constrain sort/order to fixed allowlists (array-injection guarded before the typed call); `sanitizeFilters()` drops unknown/empty/array values and out-of-range status before any value reaches `Record`.
- **Deviation (verified against live code):** the spec's invoice deep-link `billing/invoices/edit/{invoice_id}` route does not exist in this build; the view uses the verified `clients/editinvoice/{client_id}/{invoice_id}/` (and `clients/edittransaction/{client_id}/{transaction_id}/` for the transaction link). Recorded in the Change Log.
- **Not run (NFR12):** no live DB or web stack in this checkout, so DB-backed install/upgrade smoke and live admin render of the controller/view/widget were not executed. Controllers/models that hit `Record` cannot be unit-tested here; they are verified via `php -l` plus review against the confirmed `Record`/`InputFields`/`Widget`/`Pagination` APIs and route checks. No claim of root `../tests` coverage.

### File List

- `plugins/kuickpay_reconcile/models/kuickpay_vouchers.php` (modified — getList signature + applyListFilters/getListCount/getStatuses/getClientCodes/normalizeAmountFilter)
- `plugins/kuickpay_reconcile/models/kuickpay_voucher_invoices.php` (modified — getByVoucherIds)
- `plugins/kuickpay_reconcile/lib/KuickPayVoucherListPresenter.php` (added)
- `plugins/kuickpay_reconcile/controllers/admin_vouchers.php` (added)
- `plugins/kuickpay_reconcile/views/default/admin_vouchers.pdt` (added)
- `plugins/kuickpay_reconcile/language/en_us/admin_vouchers.php` (added)
- `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php` (modified — getActions/getPermissions/upgrade)
- `plugins/kuickpay_reconcile/language/en_us/kuickpay_reconcile_plugin.php` (modified — nav/permission keys)
- `plugins/kuickpay_reconcile/config.json` (modified — version 1.4.0 → 1.5.0)
- `plugins/kuickpay_reconcile/tests/KuickPayVoucherListPresenterTest.php` (added)
- `plugins/kuickpay_reconcile/tests/bootstrap.php` (modified — require presenter lib)

---

## Decisions (resolved during context engineering — folded into the spec above)

These were open questions; they are now **decided**, and the ACs/Tasks/Dev Notes reflect them. Listed here only
for traceability — they are NOT blockers and should not be re-opened by the dev agent.

1. **Controller location — DECIDED: new `admin_vouchers` controller** per the architecture (`admin_vouchers.php`
   = "search, list, and detail"), leaving the Epic-3 `admin_main` bulk-reconcile trigger intact; not overloading
   `admin_main`. (See "CRITICAL — Where this screen lives".)
2. **ACL granularity — DECIDED: one `kuickpay_reconcile.admin_vouchers` permission (`action => '*'`) for this
   read-only view-records controller.** Diagnostics (4.2) and mutating actions (4.3/4.4) get their own separate
   permissions and must not piggyback this grant. (See "ACL scope".)
3. **Client filter depth — DECIDED: `client_id` exact only for MVP**, and the field is **labelled "Client ID"**
   (not "Client") with helper text so the agent knows the expected input. Human-readable client code is shown in
   result rows (resolved via the dedicated `KuickpayVouchers::getClientCodes()` query, not an `id_code` column
   join). No client-name search / no dropdown / no `contacts` join this story — name search is a later enhancement
   (Israr's call, 2026-06-12).
4. **Amount filter — DECIDED: single exact normalized-decimal-string match, no range** (varchar lexicographic
   hazard). (See "Amount filter".)

> Environment note (not a story-content change): this checkout's BMad config (`_bmad/bmm/config.yaml`) points at
> root `_bmad-output/...`, but KuickPay artifacts live under `_bmad-output/kuickpay/...`. Automated discovery
> should use explicit KuickPay paths (or the config be pointed at the KuickPay lane).


### Review Findings

Generated by code review on 2026-06-12.

- [x] [Review][Patch] View `_()` calls missing return flag / HTML-safe wrapping (`views/default/admin_vouchers.pdt:106,125`)
- [x] [Review][Patch] LIKE partial-match filters do not escape SQL wildcards (`models/kuickpay_vouchers.php:~367`)
- [x] [Review][Patch] `client_id` and `invoice_id` filters silently accept non-digit trailing characters (`models/kuickpay_vouchers.php:~360-361,~397-398`)
- [x] [Review][Patch] `date_from` / `date_to` filters are not validated as dates (`lib/KuickPayVoucherListPresenter.php:~184`)
- [x] [Review][Patch] Amount filter normalization fails for values starting with a decimal point (`models/kuickpay_vouchers.php:~425`)
- [x] [Review][Defer] Admin permission is not explicitly enforced in the controller (`controllers/admin_vouchers.php:46`) — deferred, pre-existing; `admin_main` uses the same pattern and framework route-level enforcement is assumed.

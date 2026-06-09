---
baseline_commit: 25d1ca3901ebf37fb050439bfbba42237c30b3fa
---

# Story 1.1: Install KuickPay Gateway and Companion Plugin Scaffold

Status: in-progress

<!-- Note: Validation is optional. Run validate-create-story for quality check before dev-story. -->

## Story

As an admin operator,
I want KuickPay delivered as a Blesta-native non-merchant gateway plus a companion plugin scaffold,
so that the integration can be installed, upgraded, disabled, and uninstalled safely without modifying Blesta core and without enabling any payment mutation before the rest of the integration is built.

> **What this story IS:** the structural foundation — two **installable, detectable** Blesta extensions:
> 1. `components/gateways/nonmerchant/kuickpay/` — a non-merchant gateway that Blesta lists, is PKR-only by declaration, renders a settings screen and a customer process view, and **fails closed** on every customer-facing path.
> 2. `plugins/kuickpay_reconcile/` — a companion plugin with a clean, lifecycle-safe `install` / `upgrade` / `uninstall` that creates no schema and registers no events/cron yet.
>
> Plus the dependency guard: if the companion plugin is **not installed**, the gateway shows a clear admin setup error and enables **no** voucher/payment-mutation path.
>
> **What this story is NOT:** no settings fields (Story 1.2), no credential encryption/masking (Story 1.3), no connection test (Story 1.4), no customer-facing PKR eligibility/messaging logic (Story 1.5), no SOAP client (Story 3.1), no parser (Story 3.2), no voucher schema (Story 2.1), no reconciliation/posting (Epic 3), no admin workbench (Epic 4). **Zero live payment mutation. No `markPaid` / `recordPayment` / transaction creation anywhere.**

## Acceptance Criteria

_Reproduced verbatim from [Source: epics.md#Story 1.1] (lines 311–326)._

**AC1 — Both extensions are detectable.**
**Given** the KuickPay extension files are deployed
**When** Blesta scans extensions
**Then** KuickPay is detectable as a non-merchant gateway
**And** `kuickpay_reconcile` is detectable as the companion plugin.

**AC2 — Lifecycle is non-destructive.**
**Given** the scaffold is installed, upgraded, disabled, or uninstalled
**When** the extension lifecycle runs
**Then** it does not modify Blesta core files
**And** it does not remove unrelated Blesta or extension data.

**AC3 — Fail closed when the companion plugin is missing.**
**Given** the companion plugin is missing or not installed
**When** an admin attempts to configure or use the gateway
**Then** the gateway shows a clear admin setup error
**And** no customer Voucher or payment mutation path is enabled.

## Non-Negotiables (read before any task)

1. **No live payment mutation, full stop.** No `Transactions->add`, no `recordPayment`, no `markPaid`, no invoice status change, no voucher creation — anywhere in this story. `validate()` and `success()` must never return a paid/approved transaction. [Source: architecture.md#Anti-Patterns, lines ~650–662]
2. **Exact class names are mandatory.** The gateway class is `Kuickpay` (single capital) and the plugin handler class is `KuickpayReconcilePlugin` (single capital after the first). Blesta derives these from the folder names with `Loader::toCamelCase()` **and round-trips them back to directory/asset paths with `Loader::fromCamelCase()`** — `KuickPay` would resolve to a `kuick_pay` path and break asset/logo resolution. See Dev Notes "Class-name derivation — get this exactly right." [Source: components/gateways/gateways.php:40-67, app/models/gateway_manager.php:902-908, components/plugins/plugins.php:25-43]
3. **No core edits.** Touch only the two new extension directories (plus this story file + `sprint-status.yaml`). Do not edit `index.php`, `config/*`, `app/*`, `components/gateways/lib/*`, `components/plugins/lib/*`, `.htaccess`, or the root `composer.json`. [Source: project-context.md#Critical Don't-Miss Rules; AC2]
4. **No schema, no events, no cron in the plugin yet.** `install()` creates no tables; `uninstall()` drops nothing; `getEvents()`/`getActions()`/`cron()` stay as inherited no-ops. Voucher schema is Story 2.1; reconciliation cron is Epic 3. This is what makes AC2 trivially safe. [Source: architecture.md "first story" constraint, lines 289–295]
5. **No secrets, no hard-coded production values.** No real endpoint/WSDL, Institution ID, credentials, or fallback values in any file. The scaffold declares no settings, so there is nothing to hard-code — keep it that way. [Source: NFR10; phase-0-contract.md "No hard-coding"]
6. **No static data files under the web-served plugin tree.** Do **not** create `plugins/kuickpay_reconcile/tests/fixtures/*.xml` in this story. `plugins/` is web-served and `.xml` is not in the extension deny-list; fixtures stay in web-blocked `docs/kuickpay/` until Story 3.2 relocates them with protection. [Source: 0-1 story Dev Notes "Why docs/kuickpay/"; commit 0b4b18bf]
7. **Match brownfield conventions, don't invent.** Mirror the `offline` gateway and the `shared_login` plugin exactly: legacy global classes (no namespace), `loadConfig(config.json)`, `Language::loadLang`, `.pdt` views, short arrays, single quotes, LF endings. [Source: project-context.md#Framework/Language Rules]

## Tasks / Subtasks

- [x] **Task 1 — Create the gateway scaffold** under `components/gateways/nonmerchant/kuickpay/` (AC: #1, #2, #3)
  - [x] 1.1 Create `config.json` mirroring `components/gateways/nonmerchant/offline/config.json`: `version` `"1.0.0"`, `name` `"Kuickpay.name"`, `description` `"Kuickpay.description"`, an `authors` array, and **`"currencies": ["PKR"]`** (PKR-only by declaration — see Dev Notes "PKR-only at scaffold stage"). Keys `name`/`description` are language keys, not literal strings.
  - [x] 1.2 Create `composer.json` mirroring `offline/composer.json`: `"name": "blesta/kuickpay"`, `"description": "KuickPay"`, `"type": "blesta-gateway-nonmerchant"`, `"license": "proprietary"`, `"require": {"blesta/composer-installer": "~1.0"}`. (`offline`/`shared_login` both carry a `description`; it is a cosmetic Composer label, **not** a path/class-derivation source, so brand casing is fine here.) No `autoload` block (no `lib/` in this story).
  - [x] 1.3 Create `kuickpay.php` with `class Kuickpay extends NonmerchantGateway` (legacy global class, no namespace). Implement the minimal non-merchant contract, modeled on `offline.php`:
    - `__construct()` → `loadConfig(dirname(__FILE__) . DS . 'config.json')`; `Loader::loadComponents($this, ['Input'])`; `Language::loadLang('kuickpay', null, dirname(__FILE__) . DS . 'language' . DS)`.
    - `setCurrency($currency)` → `$this->currency = $currency;`.
    - `setMeta(array $meta = null)` → store in a private `$meta`.
    - `encryptableFields()` → `return [];` (credential encryption is Story 1.3; nothing to encrypt yet).
    - `getSettings(array $meta = null)` → load helpers, then render the `settings` view, passing `meta` **and** a `companion_installed` boolean computed via the AC3 guard (Subtask 1.6). Use the exact Blesta view idiom from `offline.php`: `$this->view = $this->makeView('settings', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)); Loader::loadHelpers($this, ['Form', 'Html']); $this->view->set('meta', $meta); $this->view->set('companion_installed', $companion_installed); return $this->view->fetch();`. The `makeView` third argument (a web-relative path) is **not** optional — omitting it makes the view silently fail to resolve. [Source: offline.php:50-60]
    - `editSettings(array $meta)` → return `$meta` unchanged (no fields to validate yet; Story 1.2 adds rules). Do not invent settings.
    - `buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)` (the exact parent signature — see `offline.php:150`) → **fail-closed placeholder**. Build the view with `$this->view = $this->makeView('process', 'default', str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)); Loader::loadHelpers($this, ['Html']);` (the placeholder renders plain text, so `Html` alone suffices — only add `TextParser` if you actually render markdown, which this scaffold does not). If the companion plugin is missing, set `$this->Input->setErrors($this->getCommonError('unsupported'))` (or a kuickpay-specific setup error) **and still return the rendered `process` view** showing a neutral, customer-safe message; otherwise render the same `process` view with the neutral "payment reference is not yet available" placeholder. **Create no voucher, mutate nothing.**
    - `validate(array $get, array $post)` → `$this->Input->setErrors($this->getCommonError('unsupported')); return null;`.
    - `success(array $get, array $post)` → `$this->Input->setErrors($this->getCommonError('unsupported')); return null;`.
    - ⚠️ **Wrap `getCommonError` in `setErrors` — do not call it bare.** `offline.php` does `$this->getCommonError('unsupported'); return null;` and discards the return value, so the error is never registered. It is the **lone outlier**: every other nonmerchant gateway (`btcpay_server`, `paypal_checkout`, `square`, `bitpay`, `gocardless`, `pagseguro`, `alipay`, …) uses `$this->Input->setErrors($this->getCommonError('unsupported'))`. The wrapped form is what actually surfaces the failure — i.e. the fail-closed behavior this story requires — and matches the base-class contract. [Source: components/gateways/lib/nonmerchant_gateway.php:210-220 — getCommonError return is "to be set using Input::setErrors()"]
  - [x] 1.4 Create `views/default/settings.pdt`: a thin template that, when `companion_installed` is false, renders the clear admin setup-error banner (AC3) using `$this->_('Kuickpay.!error.companion_missing')`; otherwise renders the `$this->_('Kuickpay.settings.scaffold_note')` note. Mirror `offline/views/default/settings.pdt` helper usage (`$this->Form`, `$this->Html`, `$this->_()`). No real setting fields.
  - [x] 1.5 Create `views/default/process.pdt`: a thin placeholder that displays the neutral `$this->_('Kuickpay.process.not_ready')` message (no voucher, no amount, no "paid" language, no success styling). Mirror `offline/views/default/process.pdt` structure.
  - [x] 1.6 Implement the **AC3 companion-plugin guard** inside the gateway: load `PluginManager` (`Loader::loadModels($this, ['PluginManager'])`) and check `$this->PluginManager->isInstalled('kuickpay_reconcile', Configure::get('Blesta.company_id'))`. Use the result to (a) drive the `settings.pdt` admin error and (b) fail-close `buildProcess()`. See Dev Notes "AC3 guard — the exact API."
  - [x] 1.7 Create `language/en_us/kuickpay.php` using the **locked en_us copy below — this is the final, approved wording; use it verbatim, do not paraphrase.** Keep the two audiences separate: the admin "install the plugin" instruction must **never** render on the customer process path. All admin/customer text lives here — no hard-coded strings in PHP or `.pdt`. [Source: project-context.md "Keep user-facing text in language files"]
    ```php
    $lang['Kuickpay.name'] = 'KuickPay';
    $lang['Kuickpay.description'] = 'Accept invoice payments in PKR using KuickPay payment references.';
    // Admin-facing — settings screen when the companion plugin is missing (AC3)
    $lang['Kuickpay.!error.companion_missing'] = 'KuickPay requires its companion plugin. Install and activate the KuickPay Reconcile plugin, then reload this page to configure the gateway.';
    // Customer-facing — neutral process-view placeholder (no voucher, no amount, no paid/success language)
    $lang['Kuickpay.process.not_ready'] = 'A KuickPay payment reference is not available for this invoice yet. Please choose another payment method or contact support for assistance.';
    // Admin-facing — settings screen when the companion IS installed (scaffold has no fields yet)
    $lang['Kuickpay.settings.scaffold_note'] = 'KuickPay is installed. Payment settings will become available in a later update.';
    ```

- [x] **Task 2 — Create the companion plugin scaffold** under `plugins/kuickpay_reconcile/` (AC: #1, #2)
  - [x] 2.1 Create `config.json` mirroring `plugins/shared_login/config.json`: `version` `"1.0.0"`, `name` `"KuickpayReconcilePlugin.name"`, `description` `"KuickpayReconcilePlugin.description"`, an `authors` array. (Optional `"icon"` like `auto_cancel`'s `"bi bi-..."`.)
  - [x] 2.2 Create `composer.json` mirroring `shared_login/composer.json`: `"name": "blesta/kuickpay_reconcile"`, `"type": "blesta-plugin"`, `"license": "proprietary"`, `"require": {"blesta/composer-installer": "~1.0"}`.
  - [x] 2.3 Create `kuickpay_reconcile_plugin.php` with `class KuickpayReconcilePlugin extends Plugin` (legacy global class). Model on `shared_login_plugin.php`:
    - `__construct()` → `Language::loadLang('kuickpay_reconcile_plugin', null, dirname(__FILE__) . DS . 'language' . DS)`; `loadConfig(dirname(__FILE__) . DS . 'config.json')`.
    - `install($plugin_id)` → **safe no-op** at scaffold stage: create **no** tables. Add a docblock noting that voucher schema is owned by Story 2.1 and reconciliation cron by Epic 3. (Defining it explicitly gives later stories an insertion point and documents intent; the inherited base method is already an empty no-op.)
    - `upgrade($current_version, $plugin_id)` → safe no-op placeholder with a docblock for future versioned migrations. Note: `shared_login_plugin.php` does **not** define `upgrade()`, so take this exact signature from the `Plugin` base class (`components/plugins/lib/plugin.php` → `upgrade($current_version, $plugin_id)`), not from the reference plugin.
    - `uninstall($plugin_id, $last_instance)` → **safe no-op**: drop nothing, remove nothing. Add a docblock noting it must only ever remove plugin-owned data (none yet) and must honor `$last_instance` when schema is added in Story 2.1. This is the line AC2 hinges on.
    - Do **not** override `getEvents()`, `getActions()`, `getPermissions()`, `cron()` — inherit the empty defaults. No events/cron at scaffold stage.
    - **The override rule (so the asymmetry above isn't confusing):** define `install`/`upgrade`/`uninstall` as explicit no-ops because later stories (2.1+) will fill real bodies there — having the method present documents intent and gives a clean insertion point. Leave `getEvents`/`getActions`/`getPermissions`/`cron` **un-overridden** because they gain bodies only when the matching feature ships (Epic 3+); an empty override of those would just be dead code now.
  - [x] 2.4 Create `language/en_us/kuickpay_reconcile_plugin.php` with `$lang['KuickpayReconcilePlugin.name']` and `$lang['KuickpayReconcilePlugin.description']`.
  - [x] 2.5 Do **not** create `controllers/`, `models/`, `lib/`, admin `views/`, or `tests/fixtures/` in this story — those belong to Epics 2–4 with their owning stories. Keep the scaffold to the four files above. (See Non-Negotiable #4, #6.)

- [ ] **Task 3 — Verify detectability, lifecycle safety, and the AC3 guard** (AC: #1, #2, #3)
  - [ ] 3.1 Lint every new PHP file (architecture's exact baseline):
    ```sh
    php -l components/gateways/nonmerchant/kuickpay/kuickpay.php
    php -l plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php
    find components/gateways/nonmerchant/kuickpay plugins/kuickpay_reconcile -name '*.php' -print -exec php -l {} \;
    ```
  - [ ] 3.2 Validate both `config.json` files parse as JSON (e.g. `php -r 'json_decode(file_get_contents($argv[1]), false) ?: exit(1);' <file>` or `python3 -m json.tool <file>`).
  - [ ] 3.3 Confirm class-name round-trip correctness by inspection: gateway file `kuickpay.php` → class `Kuickpay`; plugin file `kuickpay_reconcile_plugin.php` → handler class `KuickpayReconcilePlugin`. Cross-check against `coin_payments.php`→`CoinPayments` and `shared_login_plugin.php`→`SharedLoginPlugin`.
  - [ ] 3.4 Confirm **no core files changed**: `git status --porcelain` shows only new files under the two extension dirs plus this story file and `sprint-status.yaml`. Any other path = stop and fix (AC2).
  - [ ] 3.5 **Live verification (preferred, if a Blesta dev instance + DB is available):** in Admin → Settings → Payment Gateways, confirm "KuickPay" appears in the Available (non-merchant) list (AC1); install/uninstall the gateway and the `kuickpay_reconcile` plugin and confirm no errors and no unrelated data change (AC2); with the plugin **not** installed, open the gateway's Manage/settings screen and confirm the clear setup-error banner appears (AC3). **Fallback if no running stack:** state that explicitly per NFR12 and rely on lint + JSON validation + structural parity with `offline`/`shared_login`; do not claim install/runtime coverage that wasn't run.
  - [ ] 3.6 Confirm the customer-facing fail-closed paths by reading the code: `validate()`/`success()` return `null` after `$this->Input->setErrors($this->getCommonError('unsupported'))` (wrapped, not a bare call); `buildProcess()` creates no voucher and mutates nothing. Grep the new tree to prove the negative: `grep -rinE 'recordPayment|markPaid|->add\(|Transactions|setStatus' components/gateways/nonmerchant/kuickpay plugins/kuickpay_reconcile` returns nothing.

## Dev Notes

### Critical context — read before starting

This is the **scaffold-first story** the architecture explicitly mandates as the first implementation step: _"The first implementation story must create the gateway-plus-plugin scaffold with safe placeholder behavior and no live payment mutation."_ [Source: epics.md lines 122–123], [Source: architecture.md lines 289–295]. The readiness review called it "textbook compliance" with the "scaffold-first, no live mutation" constraint [Source: implementation-readiness-report-2026-06-09.md lines 280–283].

- **Fully unblocked, runs in parallel with Phase 0.** Epic 1 (1-1..1-5) does **not** wait on Story 0.1's fixture gate. [Source: sprint-status.yaml#BUILD ORDER, item 2: "Epic 1 ... is fully unblocked today."]
- **Payment posting stays DISABLED globally until 0-1 is approved** — and this scaffold wires none anyway. The scaffold must not introduce any posting/transaction path that a later story would have to "turn off." [Source: sprint-status.yaml#BUILD ORDER item 1; phase-0-contract gate posture]
- **The gateway-plus-plugin split is the load-bearing design decision** this scaffold establishes physically: the gateway owns checkout initiation + customer reference display only; the plugin owns durable state, reconciliation, schema lifecycle, and posting. Only `KuickPayPostingService` (Epic 3 / Story 3.5) may ever create/apply a Blesta transaction. The scaffold creates the two homes and nothing that violates the split. [Source: architecture.md lines 519–526, 581–590; epics.md lines 117–132]

### Class-name derivation — get this exactly right (Non-Negotiable #2)

Blesta instantiates extensions by deriving a class name from the **directory name**, then **round-trips that class name back to a path**:

- Gateway: `Gateways::create('kuickpay', 'nonmerchant')` runs `Loader::toCamelCase('kuickpay')` → `Kuickpay`, then `new Kuickpay()`. [Source: components/gateways/gateways.php:40-67]
- `GatewayManager::getGatewayInfo()` then does `$class = Loader::fromCamelCase($reflect->getName())` and builds the **logo/asset path** from `$class`. If the class were `KuickPay`, `fromCamelCase` yields `kuick_pay`, producing `components/gateways/nonmerchant/kuick_pay/...` — a path that does not exist. [Source: app/models/gateway_manager.php:902-908]
- Plugin: `Plugins::create('kuickpay_reconcile')` runs `toCamelCase` → `KuickpayReconcile`, appends `Plugin` → handler `KuickpayReconcilePlugin`, loads file `kuickpay_reconcile_plugin.php`, and `new KuickpayReconcilePlugin()`. [Source: components/plugins/plugins.php:25-43]

**Therefore the framework-instantiated classes MUST be `Kuickpay` and `KuickpayReconcilePlugin` (single internal capital).** This is confirmed by existing multi-word extensions: `coin_payments.php`→`class CoinPayments`, `paypal_checkout.php`→`class PaypalCheckout`, `shared_login_plugin.php`→`class SharedLoginPlugin`, `auto_cancel`→`class AutoCancelPlugin`.

> ⚠️ The architecture's directory listing uses a `KuickPay*` prefix for **internal `lib/` service classes** (`KuickPaySoapClient`, `KuickPayPostingService`, etc.). Those are NOT framework-instantiated by directory name and are **out of scope for this story** (Epics 2–3). Do not let that prefix tempt you into naming the gateway/plugin handler `KuickPay…`. Folder names, language namespaces, template paths, and config keys all use lowercase `kuickpay` consistently. [Source: architecture.md line 212]

### Reference implementations to mirror (brownfield — copy the shape)

- **Gateway:** `components/gateways/nonmerchant/offline/` is the closest analog (offline, no live API, safe `validate()`/`success()` via `getCommonError('unsupported')`). Read `offline.php`, `config.json`, `composer.json`, `views/default/{settings,process}.pdt`. KuickPay is an offline-voucher-reference gateway, so `offline` is the right template — not an API gateway like `coingate`. [Source: components/gateways/nonmerchant/offline/offline.php]
- **Plugin:** `plugins/shared_login/` is a minimal plugin (main class + config.json + composer.json + language). Read `shared_login_plugin.php` and its `config.json`. [Source: plugins/shared_login/]
- **PHPCS style (optional, recommended):** `components/gateways/nonmerchant/paystack/phpcs.xml.dist` is a "PSR2 Transitional" config (short arrays, LF, single quotes, operator spacing, templates/language exempt from line-length). There is **no root `phpcs.xml`**; do not infer repo-wide enforcement. Adding a matching `phpcs.xml.dist` to each extension is consistent with conventions but not required by the ACs. [Source: project-context.md#Code Quality; paystack/phpcs.xml.dist]

### Required non-merchant gateway contract

`Kuickpay extends NonmerchantGateway` (`components/gateways/lib/nonmerchant_gateway.php`). Minimum methods to implement (signatures must match the parent; do not add return/param types the parent doesn't declare — [Source: project-context.md "Preserve inherited Blesta method signatures"]):

| Method | Scaffold behavior |
|---|---|
| `__construct()` | loadConfig + load `Input` component + `Language::loadLang('kuickpay', ...)` |
| `setCurrency($currency)` | `$this->currency = $currency;` |
| `setMeta(array $meta = null)` | store private `$meta` |
| `getSettings(array $meta = null)` | `makeView('settings','default', str_replace(ROOTWEBDIR,'',dirname(__FILE__).DS))`; `loadHelpers(['Form','Html'])`; render `settings.pdt`; pass `meta` + `companion_installed` |
| `editSettings(array $meta)` | `return $meta;` (no rules yet — Story 1.2) |
| `encryptableFields()` | `return [];` (Story 1.3 adds password fields) |
| `buildProcess(array $contact_info, $amount, array $invoice_amounts = null, array $options = null)` | `makeView('process',…)`; `loadHelpers(['Html'])`; fail-closed placeholder view; **no voucher, no mutation** |
| `validate(array $get, array $post)` | `$this->Input->setErrors($this->getCommonError('unsupported')); return null;` |
| `success(array $get, array $post)` | `$this->Input->setErrors($this->getCommonError('unsupported')); return null;` |

`getCommonError('unsupported')` is provided by the base class and **returns** a language-backed error array — you must pass it to `$this->Input->setErrors(...)` for it to surface (see the ⚠️ note in Task 1.3; `offline.php`'s bare call is an anomaly). Valid types: `invalid`, `transaction_not_found`, `unsupported`, `general`. [Source: components/gateways/lib/nonmerchant_gateway.php:221-251]

### AC3 guard — the exact API

The gateway detects the companion plugin via the `PluginManager` model:

```php
Loader::loadModels($this, ['PluginManager']);
$company_id = Configure::get('Blesta.company_id');
$companion_installed = $this->PluginManager->isInstalled('kuickpay_reconcile', $company_id);
```

`PluginManager::isInstalled($dir, $company_id = null)` returns a bool by querying the `plugins` table on `dir` (the plugin's folder name), scoped to the company when provided. [Source: app/models/plugin_manager.php:209-220]

- In `getSettings()`: when `$companion_installed` is false, the `settings.pdt` renders the clear admin **setup-error banner** (AC3 first clause), using `$lang['Kuickpay.!error.companion_missing']`.
- In `buildProcess()`: when false, set the gateway error via `$this->Input->setErrors($this->getCommonError('unsupported'))` (or a kuickpay setup error) and render a safe view that **creates no Voucher and enables no mutation path** (AC3 second clause). Since the scaffold never creates a voucher in any branch, this is automatically satisfied — the guard just makes the admin-facing failure explicit and prevents the customer path from looking "ready."
- **`isInstalled` semantics (scaffold scope, decide consciously):** `PluginManager::isInstalled()` matches only a `plugins` row by `dir` — it does **not** check the `enabled` flag, so a *disabled-but-installed* companion reads as present. And when `Configure::get('Blesta.company_id')` is `null` (CLI / early bootstrap), the check falls back to "installed under any company." Both are acceptable here because the scaffold enables no live path in any branch; the guard's only job is to make the admin failure explicit and stop the customer view from looking "ready." Enforcing *enabled* state (e.g. `PluginManager::getByDir(...)` requiring `enabled == '1'`) is deferred to the story that first exposes a real plugin service/action. [Source: app/models/plugin_manager.php:209-220]

> Design note (recommended, not mandated): keep the detection in a single small `private function companionInstalled()` helper on the gateway so Stories 1.2/1.4/2.x reuse the same check. Don't over-engineer it into the plugin yet — the plugin exposes no services in this story.

### PKR-only at scaffold stage

Declare `"currencies": ["PKR"]` in the gateway `config.json`. Blesta's gateway selection only offers a gateway for currencies in this list (the base class exposes `getCurrencies()` from config), so this is the correct, **declarative** first step toward FR5 ("MVP supports PKR only"). [Source: epics.md FR5 line 33; architecture.md line 81 "PKR-first MVP"]

> Scope boundary: the **dynamic** customer-facing eligibility behavior — hiding/blocking non-PKR invoices *with clear copy* and guaranteeing no voucher creation for them — is **Story 1.5** ([Source: epics.md Story 1.5; UX-DR2 line 154]). This story only sets the static currency declaration. Do not build eligibility messaging/logic here.

### Why the plugin creates no schema in this story

The architecture assigns schema lifecycle to the plugin (`kuickpay_vouchers`, `kuickpay_voucher_invoices`, `kuickpay_reconciliation_runs`, `kuickpay_reconciliation_items`, `kuickpay_reconcile_locks`, `kuickpay_audit_events`) [Source: architecture.md lines 333–351, 712–713]. But the **first-story constraint** is "scaffold + safe placeholder, no live mutation" [Source: architecture.md lines 289–295], and durable customer Voucher records are explicitly **Story 2.1** ([Source: sprint-status.yaml] `2-1-create-durable-customer-voucher-records`). Creating tables now would:
1. add a destructive surface that `uninstall()` must manage — directly increasing AC2 risk, and
2. pre-empt Story 2.1's schema design.

So `install()`/`uninstall()` are documented safe no-ops here. Story 2.1 will add the schema (and the matching idempotent `uninstall` honoring `$last_instance`). This keeps AC2 ("does not remove unrelated data") trivially true: the plugin owns no data yet.

### What must NOT happen in this story (regression / scope guardrails)

- **No payment mutation, no posting, no voucher creation.** No `Transactions->add`, `recordPayment`, `markPaid`, invoice status update, or transaction creation in any file. [Source: architecture.md Anti-Patterns lines ~650–662]
- **No settings fields, no credentials, no encryption, no connection test, no eligibility logic** — those are Stories 1.2–1.5. `editSettings` returns meta unchanged; `encryptableFields` returns `[]`.
- **No SOAP client, no parser, no `lib/` classes.** Epic 3 (Stories 3.1/3.2) owns those. Do not pre-create empty stubs.
- **No DB schema, no events, no cron, no admin controllers/views/models** in the plugin. Epics 2–4 own those.
- **No static fixtures under `plugins/`** (web-exposure trap — Non-Negotiable #6).
- **No core edits, no `.htaccess` edits, no root `composer.json` edits.** [Source: project-context.md#Development Workflow Rules; AC2]
- **No PHP 8.3+ syntax.** Target PHP 8.2. [Source: project-context.md; architecture.md line 216]

### Verification (this story)

```sh
# 1. Syntax — every new PHP file (architecture's exact baseline, lines 268–271)
php -l components/gateways/nonmerchant/kuickpay/kuickpay.php
php -l plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php
find components/gateways/nonmerchant/kuickpay plugins/kuickpay_reconcile -name '*.php' -print -exec php -l {} \;

# 2. config.json parses as JSON
php -r 'json_decode(file_get_contents($argv[1])) === null && exit(1); echo "OK\n";' components/gateways/nonmerchant/kuickpay/config.json
php -r 'json_decode(file_get_contents($argv[1])) === null && exit(1); echo "OK\n";' plugins/kuickpay_reconcile/config.json

# 3. Prove the negative: no mutation/posting/voucher API used anywhere in the new tree
grep -rinE 'recordPayment|markPaid|->add\(|Transactions|setStatus|InsertVoucher|markPaidInvoice' \
  components/gateways/nonmerchant/kuickpay plugins/kuickpay_reconcile || echo "clean: no mutation surface"

# 4. No core files touched (AC2)
git status --porcelain   # expect only the two new extension dirs + this story file + sprint-status.yaml
```

If a running Blesta + MySQL dev instance is **not** available, root PHPUnit / install-time runtime checks are N/A — state this explicitly and rely on the lint + JSON + structural-parity checks above. Do not present lint-only coverage as full install/runtime verification. [Source: project-context.md#Testing Rules; NFR12]

### Project Structure Notes

- **Alignment with architecture:** exact paths match `components/gateways/nonmerchant/kuickpay/` and `plugins/kuickpay_reconcile/` [Source: architecture.md lines 191–198, 678–753; epics.md lines 118–120]. Composer installer-paths already map `type:blesta-gateway-nonmerchant` → `components/gateways/nonmerchant/{$name}` and `type:blesta-plugin` → `plugins/{$name}` [Source: composer.json:206-216], so the `composer.json` `type`/`name` fields are what make the extensions land in the right place.
- **Intentional scope reduction vs. the architecture's full tree:** the architecture lists many `lib/`, `models/`, `controllers/`, `views/`, and `tests/fixtures/` files under both extensions. Those are the **end-state** layout across Epics 2–4. This story deliberately ships only the minimum installable surface (gateway: `config.json`, `composer.json`, `kuickpay.php`, `language/en_us/kuickpay.php`, `views/default/{settings,process}.pdt`; plugin: `config.json`, `composer.json`, `kuickpay_reconcile_plugin.php`, `language/en_us/kuickpay_reconcile_plugin.php`). This is a sequencing decision, not a design change — each later file arrives with its owning story.
- **Gateway logo (cosmetic — make a conscious call):** `Gateway::getLogo()` defaults to `views/default/images/logo.png`, and `GatewayManager::getGatewayInfo()` **always** builds a logo `<img>` URL from `{class}/{getLogo()}` regardless of whether the file exists [Source: components/gateways/lib/gateway.php:178-184; app/models/gateway_manager.php:917-919]. With no file present, the gateway is still fully **detectable** (AC1 unaffected), but the admin extension card shows a broken image. The `offline` gateway ships a 150×69 PNG. Either drop a simple placeholder `views/default/images/logo.png` now, or accept the broken image for the scaffold and supply real branding in a later pass — do not fabricate a misleading logo, a plain placeholder is fine. (Listed as optional in the file manifest below.)
- **Files created (NEW; none are UPDATEs to existing code):**
  - `components/gateways/nonmerchant/kuickpay/config.json`
  - `components/gateways/nonmerchant/kuickpay/composer.json`
  - `components/gateways/nonmerchant/kuickpay/kuickpay.php`
  - `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php`
  - `components/gateways/nonmerchant/kuickpay/views/default/settings.pdt`
  - `components/gateways/nonmerchant/kuickpay/views/default/process.pdt`
  - `components/gateways/nonmerchant/kuickpay/views/default/images/logo.png` *(optional placeholder — see "Gateway logo" above; omit and accept a broken admin image, or add a simple placeholder)*
  - `plugins/kuickpay_reconcile/config.json`
  - `plugins/kuickpay_reconcile/composer.json`
  - `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php`
  - `plugins/kuickpay_reconcile/language/en_us/kuickpay_reconcile_plugin.php`

### References

- [Source: epics.md#Epic 1: Safe KuickPay Gateway Enablement, lines 247–249] — epic goal, FR1–FR5 coverage.
- [Source: epics.md#Story 1.1, lines 307–326] — user story + the three Given/When/Then ACs (reproduced verbatim above).
- [Source: epics.md lines 117–132] — gateway-plus-plugin placement; "first story = scaffold, no live mutation"; `KuickPayPostingService` posting monopoly.
- [Source: epics.md FR1 line 25, FR3 line 29, FR5 line 33] — install/lifecycle without core edits; encryption (later); PKR-only.
- [Source: architecture.md lines 289–295] — explicit first-story scaffold constraint.
- [Source: architecture.md lines 519–526, 581–590] — gateway vs plugin ownership split; posting-service monopoly.
- [Source: architecture.md lines 191–198, 678–753] — prescribed extension file/folder layout.
- [Source: architecture.md line 212] — consistent lowercase `kuickpay` naming for folders/keys/templates.
- [Source: architecture.md lines 333–351] — plugin-owned schema/states (Story 2.1+, not this story).
- [Source: architecture.md lines 268–271] — `php -l` verification baseline for scaffold files.
- [Source: architecture.md anti-patterns ~650–662] — the prohibited mutation/posting/paid-styling surfaces.
- [Source: components/gateways/nonmerchant/offline/offline.php] — gateway reference (closest analog).
- [Source: plugins/shared_login/shared_login_plugin.php; plugins/shared_login/config.json] — plugin reference.
- [Source: components/plugins/lib/plugin.php:99-123] — `Plugin` base `install`/`upgrade`/`uninstall` signatures (empty no-op defaults).
- [Source: components/gateways/lib/nonmerchant_gateway.php:221-251] — `getCommonError()` types.
- [Source: components/gateways/gateways.php:40-67; components/plugins/plugins.php:25-43; app/models/gateway_manager.php:902-908] — class-name `toCamelCase`/`fromCamelCase` round-trip (Non-Negotiable #2).
- [Source: app/models/plugin_manager.php:209-220] — `PluginManager::isInstalled()` for the AC3 guard.
- [Source: composer.json:206-216] — installer-paths for gateway/plugin types.
- [Source: sprint-status.yaml#BUILD ORDER] — Epic 1 unblocked, parallel with Phase 0; posting disabled until 0-1 approved.
- [Source: implementation-readiness-report-2026-06-09.md lines 280–283, 316–347] — Epic 1 ready now; Story 1.1 "textbook" scaffold compliance.
- [Source: _bmad-output/implementation-artifacts/0-1-confirm-kuickpay-contract-and-capture-sanitized-fixtures.md] — sibling story; `docs/kuickpay/` web-blocked fixture home; web-exposure learning (Non-Negotiable #6).
- [Source: project-context.md] — Blesta loader/Input/Record conventions, PHP 8.2, language-file rule, no-core-edit, secret-safety, PHPCS style.

## Dev Agent Record

### Agent Model Used

GPT-5 Codex

### Debug Log References

- 2026-06-09: Gateway scaffold JSON parsed with `python3.12 -m json.tool`; PHP lint could not run because no `php` binary is installed or on PATH.
- 2026-06-09: Companion plugin scaffold JSON parsed with `python3.12 -m json.tool`; confirmed plugin tree contains only the four scaffold files.

### Implementation Plan

- Implement the scaffold in two runtime units: gateway first, companion plugin second; then run story-level validation and complete BMAD tracking.

### Completion Notes List

- Gateway scaffold added under `components/gateways/nonmerchant/kuickpay/` with PKR-only metadata, Blesta installer metadata, locked en_us language copy, admin settings banner, neutral customer process placeholder, companion-plugin guard, and fail-closed `validate()`/`success()` paths.
- Companion plugin scaffold added under `plugins/kuickpay_reconcile/` with Blesta plugin metadata, installer metadata, language copy, and explicit no-op `install()`, `upgrade()`, and `uninstall()` lifecycle hooks. No schema, events, actions, permissions, cron, controllers, models, lib, views, or fixtures were added.

### File List

- `components/gateways/nonmerchant/kuickpay/composer.json`
- `components/gateways/nonmerchant/kuickpay/config.json`
- `components/gateways/nonmerchant/kuickpay/kuickpay.php`
- `components/gateways/nonmerchant/kuickpay/language/en_us/kuickpay.php`
- `components/gateways/nonmerchant/kuickpay/views/default/process.pdt`
- `components/gateways/nonmerchant/kuickpay/views/default/settings.pdt`
- `plugins/kuickpay_reconcile/composer.json`
- `plugins/kuickpay_reconcile/config.json`
- `plugins/kuickpay_reconcile/kuickpay_reconcile_plugin.php`
- `plugins/kuickpay_reconcile/language/en_us/kuickpay_reconcile_plugin.php`

## Change Log

- 2026-06-09: Story drafted (ready-for-dev) via bmad-create-story. Comprehensive context engine analysis completed — comprehensive developer guide created.
- 2026-06-09: Multi-agent validation triage applied; story remains **ready-for-dev**. Each change was re-verified against live Blesta source before inclusion. Folded in: `validate()`/`success()` now wrap the error in `$this->Input->setErrors($this->getCommonError('unsupported'))` (offline.php's bare call is the lone outlier among nonmerchant gateways and suppresses the error — defeating fail-closed intent); added the exact `makeView()` view-resolution idiom (`str_replace(ROOTWEBDIR, '', dirname(__FILE__) . DS)`), the explicit `loadHelpers` calls, and the full `buildProcess()` signature; added `description` to both `composer.json` specs; separated admin vs customer companion-missing copy (admin "install the plugin" text must never reach the customer process path); sourced `upgrade()`'s signature from the `Plugin` base class (shared_login omits it); documented `isInstalled` disabled/null-company semantics as a conscious scaffold-scope decision; flagged the cosmetic gateway-logo gap (broken admin image, AC1 unaffected) with an optional placeholder; and stated the install/upgrade/uninstall override-vs-inherit rule. Citation/line-number drift and Dev-Notes verbosity flagged by validation were reviewed and intentionally left unchanged (no effect on generated code; redundancy is deliberate defense-in-depth for a safety-critical scaffold).
- 2026-06-09: Locked final en_us microcopy (Open Question #3 resolved). Task 1.7 now carries verbatim approved strings for `Kuickpay.name`, `Kuickpay.description`, `Kuickpay.!error.companion_missing` (admin), `Kuickpay.process.not_ready` (customer), and `Kuickpay.settings.scaffold_note`; Tasks 1.4/1.5 reference the exact keys. Admin instruction kept off the customer process path.
- 2026-06-09: Implemented Task 1 gateway scaffold.
- 2026-06-09: Implemented Task 2 companion plugin scaffold.

## Open Questions / Clarifications (for the team — non-blocking for dev start)

1. **PKR-only via `config.json` currencies vs Story 1.5:** This story declares `"currencies": ["PKR"]` so Blesta only offers the gateway for PKR (declarative FR5 support). Story 1.5 then owns the dynamic customer-facing eligibility/messaging. Confirm this split is acceptable, or whether you'd prefer 1.1 to allow all currencies and defer the entire PKR restriction to 1.5. (Recommended: keep `["PKR"]` now — it's static metadata, fail-safe, and matches FR5.)
2. **Optional `phpcs.xml.dist` per extension:** add one to each new extension (mirroring `paystack/phpcs.xml.dist`) for local style enforcement, or keep the scaffold to functional files only? Not required by any AC.
3. **Companion-plugin "missing" UX wording — RESOLVED (2026-06-09).** Final approved en_us copy is locked verbatim in Task 1.7: admin setup error (`Kuickpay.!error.companion_missing`), neutral customer process message (`Kuickpay.process.not_ready`), gateway `name`/`description`, and the scaffold settings note (`Kuickpay.settings.scaffold_note`). Admin "install the plugin" instruction is kept off the customer path. No further copy decision needed for this story; Story 1.2 may add settings-field labels as it introduces fields.

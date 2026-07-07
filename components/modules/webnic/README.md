# WebNIC Module

A Blesta registrar module for reselling domains through [WebNIC](https://www.webnic.cc/).

**Current version:** 1.9.1

Install, upgrade, and uninstall it through Blesta's **Settings → Company → Modules**
flows. Each WebNIC reseller account is configured as a module row with its own
encrypted credentials; the module owns its schema (created and dropped via the
module lifecycle hooks, never `components/upgrades/`).

## Requirements

- Blesta 6.0+
- PHP 8.2

## Domain Manager integration

WebNIC is consumed by Blesta's **Domain Manager** plugin (`plugins/domains`). Verified
against **Domain Manager v2.0.0** (`plugins/domains/config.json:2`) — the version the
WebNIC↔DM contract below was confirmed against (NFR7). The pin is documentary: Blesta
plugins are not module Composer dependencies, so **no hard dependency** on the plugin is
declared.

### Recognition is type-based, integration is method-driven

The DM does not "register" a registrar. It **discovers** registrar modules by type and
then **pulls** data by calling methods on the module instance:

- `config.json` declares `"type": "registrar"`, which `RegistrarModule::getType()`
  resolves to `modules.type_id` at install. WebNIC extends `RegistrarModule`, so
  `ModuleManager->getAll($company_id, 'name', 'asc', ['type' => 'registrar'])` lists it
  and `ModuleManager->initModule($module_id)` returns an `instanceof RegistrarModule`
  (the gate at `admin_main.php:645`; `getConfiguredRegistrarModules()` at
  `admin_main.php:1203` additionally requires ≥1 configured row).

### Methods the Domain Manager pulls

| Module method | Driven by (DM path) |
|---|---|
| `getTlds()` | TLD import (`domains_tlds.php:2099`); membership gate (`:2108`); per-TLD "supported" validation rule (`:2200-2215`); admin AJAX TLD picker (`admin_domains.php:397`, via `importTlds()` `:294` / `getModuleTlds()` `:385`); change-package check (`domains_domains.php:246`); storefront order form TLD list (`order_type_domain.php:93`) |
| `getFilteredTldPricing()` | Pricing sync — `TldSync::synchronizePrices()` (`plugins/domains/lib/tld_sync.php:71`), called with `['tlds' => …, 'currencies' => …]` and **no `terms`** filter |
| `getTldPricing()` | Legacy/direct pricing entry point; delegates to `getFilteredTldPricing()` so both pricing methods share the same converted return shape |
| `checkAvailability()` | Single-domain availability — admin `DomainsDomains->checkAvailability()` (`domains_domains.php:758`) and the storefront order path (`order_type_domain.php:472-531`, which falls back to per-domain `checkAvailability` for the register flow). Overridden in WN-2-4 (see boundary note below) |
| `bulkCheckAvailability()` | Storefront register availability — `order_type_domain.php:472-531` groups domains by module row and consumes a plain `domain => bool` map. Overridden in WN-2-5 as a per-domain loop over `GET /domain/v2/query` using one durable WebNIC client |
| `checkTransferAvailability()` | Storefront transfer eligibility — `order_type_domain.php:472-503` ultimately loops per domain. Overridden in WN-2-6 as a single-domain Query Transfer Type read; no bulk transfer method is implemented |
| `isValidTerm()` | Order term validation — `order_type_domain.php:282-294` calls this once per configured domain term. Overridden in WN-2-6 as a cache-first Get Extensions Rule read with the base 1-10 fallback when rules are unavailable |

**Price-sync reflection gate.** `admin_domains.php:417-418` does
`(new ReflectionClass($class))->getMethod('getFilteredTldPricing')->class !== $class`.
WebNIC **declares** `getFilteredTldPricing` directly (not merely inherited), so the DM
reports price-sync **supported** and does not raise `price_sync_unsupported`.

**Currency conversion is owned by the module, not the plugin.** `getFilteredTldPricing()`
returns prices already converted into the operator's configured currencies (via
`Currencies->convert`). `TldSync` only applies markup/rounding downstream
(`formatPricing()`), then writes the `pricings` rows. A registrar that returned
source-currency prices would land them in the wrong currency.

### `synchronizeTldDomains()` — contract correction (AR19)

Earlier planning wording said TLD + pricing "ride `synchronizeTldDomains()`". Verified
against v2.0.0: **`synchronizeTldDomains()` is a private Domains-plugin cron method**
(`domains_plugin.php:1630`, dispatched from `DomainsPlugin::cron('domain_tld_synchronization')`
at `:1450`) — **not** a registrar-module method. The module is reached **indirectly**:
`getTlds()` via the import/validation/order paths, and `getFilteredTldPricing()` via
`TldSync::synchronizePrices()`. WebNIC therefore does **not** implement
`synchronizeTldDomains()` (and must not) — its only module-owned cron key is
`reconcile_orders` (Epic 3), which is independent of the plugin-owned
`domain_tld_synchronization` key (no collision, no cross-dispatch).

### Knowingly-partial boundary (interim state, documented not closed)

Epic 2 delivers the **TLD catalogue + operator-currency pricing + availability
routing**. The following are out of scope here and remain in their inherited /
not-yet-wired state:

- **Availability accuracy.** Single-domain `checkAvailability()` is accurate (WN-2-4):
  it overrides the inherited always-`true` base with a real WebNIC Get Domain read
  (`GET /domain/v2/query`) through the `WebnicDomains` command group, returning a plain
  `bool` (`true` = registerable, `false` = taken). The **only** `false`-with-no-error is a
  success envelope carrying `data.available === false`; any transient/transport failure,
  5xx, 401, or unknown business code returns `false` **with** a surfaced module error
  (`temporarily_unavailable`) so the storefront shows "try again" rather than a false
  "taken" (FR41), and a terminal `DOM2400` surfaces the invalid-domain message. The method
  never throws (a `Throwable` would trigger a lossy WHOIS fallback,
  `domains_domains.php:764-767`).
- **Bulk availability is a deliberate per-domain loop.** WebNIC has no verified batch
  availability endpoint, so `bulkCheckAvailability()` (WN-2-5) uses `batch = 1`: it loops
  over `GET /domain/v2/query`, but resolves the module row once and reuses one durable
  `WebnicApi`/`TokenStore`/`WebnicDomains` client for every input domain. The method always
  returns the Domain Manager's plain `domain => bool` map, isolates per-domain failures,
  and calls `Input->setErrors()` once after the loop with domain-attributed retry/error
  messages. Live-region streaming/ARIA announcements are core storefront/Epic-6 behavior,
  not module-rendered UI.
- **Transfer eligibility is single-domain only.** `checkTransferAvailability()` (WN-2-6)
  reads WebNIC Query Transfer Type (`GET /domain/v2/query-transfer-type`):
  `registrar_transfer` and `reseller_transfer` return `true`; `domain_owner` returns
  `false` as a clean not-transferable result. Transient/transport failures, malformed
  success envelopes, terminal business errors, and closed/missing module rows return
  `false` with a retry-framed `transfer` error. There is intentionally no
  `webnic_transfers.php` read file and no bulk transfer method here; Epic 4 owns transfer
  write operations.
- **Term validity uses extension rules, then defaults open.** `isValidTerm()` (WN-2-6)
  normalizes Blesta's dotted TLD to WebNIC's dotless extension key and reads Get
  Extensions Rule RG/TF (`GET /domain/v2/ext-rules`). Registration rules use
  `data.rules.terms` when present. Transfer TF rules carry no term list by contract, and
  any unavailable rule/error/missing row defaults to Blesta's 1-10 year bound instead of
  throwing or surfacing `Input` errors.
- **INV-8 is seeded, not rendered.** `WebnicPricing::mapExtensionRuleFields()` and
  `config/webnic.php` now provide the single rule-key to field-type transform, and
  unknown WebNIC rule keys survive as required text fields rather than being dropped.
  Per-TLD field rendering (`getPackageFields`, admin/client fieldsets, `.pdt` views) is
  still Epic 3 / FR19.
- **Storefront price-cell is core-rendered (UX-DR7).** WN-2-4 authors **no** price-cell
  view. The register-prominent "renews at Y/yr" cell is rendered inline by the core Domain
  Manager templates (`plugins/order/views/templates/standard/types/domain/lookup.pdt:193-208`,
  via `Domain.lookup.term_recurring`), fed by the already-shipped WN-2-2
  `getTldPricing()`/`getFilteredTldPricing()` output in operator currency.
  `checkAvailability()` returns **availability only** — never price. Local storefront visual
  confirmation is license-gated (the admin/storefront UI needs a valid Blesta license,
  see project docs), so this is verified by the data contract (accurate `bool` + unchanged
  WN-2-2 pricing flow), not a click-test.
- **Order provisioning & order/package fields** (`addService`, `getPackageFields`,
  pending/failed/in-progress order UI) are **Epic 3**.
- The **status lexicon** primitive (`WebnicStatus` + `views/default/status_badge.pdt`,
  WN-1-6) exists but is **intentionally not yet wired** into any DM render path.

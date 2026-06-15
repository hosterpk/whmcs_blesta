# Structural Company-Scoping & Test-Fidelity Verification (Story 5.5)

Date: 2026-06-15

This record is sanitized. It contains NO `config/blesta.php` values, DB
credentials, host names, KuickPay credentials, Institution ID values, raw SOAP
payloads, or customer PII (NFR8). It records exactly what ran versus what was
stubbed (NFR12).

## Scope

Story 5.5 hardens recurring review classes into structural guarantees:

- AC1 — un-omittable `company_id` scoping convention on the base model + the 8
  identified gap closures.
- AC2 — test doubles model the real schema constraints (NOT-NULL + company-scoped
  UNIQUE keys), Blesta `decimal(12,4)` 4-dp amounts, diversified fixtures, and a
  written fake-fidelity checklist.
- AC3a–AC3e — explicit admin-permission enforcement, posting call-count
  assertions, real-framework currency wiring, `normalizeAmount()` rounding, and
  `retireVoucher()` affected-row handling.

No schema change and no plugin version bump (the plugin stays at `1.10.0`).

## Runtime & tooling

- Production runtime: `ea-php83` (PHP 8.3). Source-floor lint also under
  `ea-php82` (PHP 8.2). All 37 changed PHP files pass `php -l` under **both**.
- External PHPUnit 8.5 runner (outside the web root):
  `/root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`.

## Automated test results

| Suite | Baseline (Story 5.4) | Story 5.5 | Notes |
|---|---|---|---|
| Plugin `plugins/kuickpay_reconcile` | 189/189 | **214/214 green** | +25 tests (scoping isolation, fake fidelity, ACL decision, rounding, retire row-count, posting call-count) |
| Gateway `components/gateways/nonmerchant/kuickpay` | 239 + 1 red | **250, 1 red** | +11 tests; the single red is the pre-existing disclosed baseline (below) |

### Disclosed pre-existing baseline red (NOT introduced by this story)

`KuickPayFailClosedContractTest::testUnsafeXmlFixturesNeverProducePaidOrPostedEvidence`
with `ambiguous/bill-payment-inquiry-empty-currency.xml` fails at HEAD and was
already failing before Story 5.5. It is unrelated to the 5.5 changes and is
carried forward as a known baseline item.

## What ran against the real framework vs. what was stubbed (NFR12)

- **AC3c currency wiring — real framework base, exercised.**
  `KuickPayCurrencyWiringTest` requires the REAL framework base gateway
  (`components/gateways/lib/gateway.php`) plus the `Container` trait it `use`s,
  and exercises the inherited `Gateway::loadConfig()` → `$this->config->currencies`
  → `Gateway::getCurrencies()` path reading the real
  `components/gateways/nonmerchant/kuickpay/config.json`, asserting `['PKR']`.
  This is real base-class code, not the empty-`NonmerchantGateway` stub the
  eligibility-guard suite uses.
  - **Stubbed / not loaded:** the real `NonmerchantGateway` subclass and a real
    `Kuickpay` instance are NOT loaded against the real base — the rest of the
    gateway suite still stubs `NonmerchantGateway` as an empty class. The wiring
    is exercised through a minimal concrete subclass of the real `Gateway`
    (implementing only the base's abstract methods). `Kuickpay` inherits
    `getCurrencies()` with no override (asserted from source), so the same
    inherited wiring governs the production gateway.
  - `loadConfig()`/`getCurrencies()` are pure (file read + property access) and
    do not touch `Loader`/`Configure`, which is why the real base loads in this
    component-local harness.

- **AC1 cross-company isolation — fakes + reflection.**
  `KuickPayCompanyScopeIsolationTest` proves company-A reads/edits never touch
  company-B rows using the AC2 company-aware fakes, and reflects on
  `KuickpayReconcileModel`'s scoped primitives to prove each declares a required
  `int $companyId` (omitting the tenant is a type error, not a leak).

- **No live DB / KuickPay SOAP in this story.** All 5.5 verification is unit /
  fake-level. The real reconcile → post round-trip and admin mutations against
  the live Blesta/MariaDB stack remain covered by Story 5.1's evidence
  (`docs/kuickpay/live-verification-evidence.md`); KuickPay has no sandbox, so no
  live provider call was made here.

## Fake-fidelity checklist

The written checklist (the Epic 3 retro AI-2 deliverable) is landed at
`plugins/kuickpay_reconcile/tests/README.md`, backed by the shared
`tests/fakes/KuickPayFakeVoucherConstraints.php` trait reused by the
voucher-repository fakes.

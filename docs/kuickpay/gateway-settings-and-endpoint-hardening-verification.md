# Gateway Settings & Endpoint Hardening Verification (Story 5.6)

Date: 2026-06-15

This record is sanitized. It contains NO `config/blesta.php` values, DB
credentials, host names, KuickPay credentials, Institution ID values, raw SOAP
payloads, customer PII, or any production WSDL host (NFR8; `phase-0-contract.md`).
It records exactly what ran versus what was stubbed (NFR12).

## Scope

Story 5.6 hardens the gateway settings and outbound endpoint surface (gateway
tree only — no plugin or core changes):

- AC1 — `wsdl_url` save-time SSRF/userinfo hardening, an operator-configurable
  confirmed-endpoint allowlist (`wsdl_allowed_hosts`; no host literal in source),
  and the IPv6-only-DNS close in the probe.
- AC2 — numeric settings bounds + leading-zero rejection + the cross-field
  relation `expiry_date_offset_days >= due_date_offset_days`.
- AC3 — the connection probe's pure-reachability contract documented and
  honestly covered (redirect/4xx/5xx-as-reachable + `connection.unavailable`),
  and a real gateway `logo.png` shipped.

No schema change and no gateway version bump (gateway `config.json` stays at
`1.0.0`; the new allowlist setting is schema-less gateway meta defaulting safe).

## Runtime & tooling

- Production runtime: `ea-php83` (PHP 8.3.31). Source-floor lint also under
  `ea-php82` (PHP 8.2.31). All changed PHP files pass `php -l` under **both**:
  `kuickpay.php`, `lib/KuickPaySoapClient.php`, `language/en_us/kuickpay.php`,
  `tests/KuickPaySettingsValidationTest.php`, `tests/KuickPayConnectionProbeTest.php`.
  The new APIs (`dns_get_record`/`gethostbynamel`/`filter_var`/GD) are all
  ≤8.2-safe.
- External PHPUnit 8.5 runner (outside the web root):
  `cd components/gateways/nonmerchant/kuickpay && /root/tools/phpunit-8.5/vendor/bin/phpunit --bootstrap tests/bootstrap.php tests`
  (NOT `-c build/phpunit.xml`, per project-context.md).

## Automated test results

| Suite | Baseline (Story 5.5) | Story 5.6 | Notes |
|---|---|---|---|
| Gateway `components/gateways/nonmerchant/kuickpay` | 250, 1 red | **313, 1 red** | +63 tests (52 settings-validation, 11 probe). The single red is the pre-existing disclosed baseline (below). |

### Disclosed pre-existing baseline red (NOT introduced by this story)

`KuickPayFailClosedContractTest::testUnsafeXmlFixturesNeverProducePaidOrPostedEvidence`
with `ambiguous/bill-payment-inquiry-empty-currency.xml` fails at HEAD and was
already failing before Story 5.6. It is unrelated to the 5.6 changes and is
carried forward as a known baseline item.

## What ran against the real framework vs. what was stubbed (NFR12)

- **AC1/AC2 save rules — real `Input`, exercised end-to-end.**
  `KuickPaySettingsValidationTest` loads the REAL Blesta `Input`
  (`Minphp\Input\Input` + the bridge `Input`) — pure validation, no DB/Loader —
  and drives `editSettings()` through `setRules()`/`validates()` so the actual
  field rules run (userinfo rejection, private/literal-IP + named-host-resolves-
  private blocking, allowlist match/miss/empty fallback, soap_timeout/offset
  bounds + leading-zero, the `expiry >= due` relation, unset-passes, and the
  reviewed regression that invalid settings do not run the connection probe). The pure
  validators (`wsdlUrlSafety`, `parseAllowedHosts`, `isPlausibleHost`,
  `soapTimeoutInRange`, `offsetDaysInRange`, `expiryNotBeforeDue`) are also unit-
  tested directly. The connection-probe suite's no-op `Input` fake CANNOT
  evaluate field rules, which is why the real `Input` is used here.
  - **Stubbed:** `NonmerchantGateway` (empty base) and `Language::_` (identity)
    remain inline stubs; the gateway is built via
    `ReflectionClass::newInstanceWithoutConstructor()`. DNS is stubbed via a
    `resolveProbeAddresses()` override so the named-host-resolves-private save
    path is deterministic and offline (NFR11 — no live endpoint in tests).

- **AC1 IPv6 + AC3 reachability — fakes via the probe seams.**
  `KuickPayConnectionProbeTest` overrides `executeConnectionProbe()`,
  `resolveProbeAddresses()`, and the new `connectionTransportAvailable()` seam.
  IPv6-only resolution + `CURLOPT_RESOLVE` bracketing, private-IPv6 blocking,
  redirect/4xx/5xx-as-reachable, and the `connection.unavailable` branch are all
  asserted without any real network or DNS call. The curl-missing branch is
  covered honestly through the transport seam (not by removing `curl_init`).

- **No live DB / KuickPay SOAP in this story.** All 5.6 verification is unit /
  fake-level and offline. KuickPay has no sandbox; no production WSDL host is
  committed anywhere (the allowlist is operator-supplied at deployment). The
  live reconcile → post round-trip remains covered by Story 5.1's evidence.

## Logo asset

`components/gateways/nonmerchant/kuickpay/views/default/images/logo.png` —
generated as a real raster PNG (not an SVG renamed `.png`). Verified:

- `file` → `PNG image data, 150 x 69, 8-bit/color RGBA, non-interlaced`
- `identify` → `150x69 PNG depth=8 srgba alpha=True` (~2.1 KB)
- `getimagesize()` → `150x69 mime=image/png bits=8`

It sits at the default `Gateway::getLogo()` path (kuickpay's `config.json` has no
`logo` key, so the default applies). The shared base `getLogo()` was NOT edited.

## Intentional boundary note

The cron-side `KuickPaySoapClient::hasUsableWsdlUrl()` keeps its userinfo/https
checks in step with the shared `Kuickpay::wsdlUrlSafety()` (cross-referenced in
both docblocks) but deliberately does NOT gain the private-range/allowlist guard:
the save-time chokepoint prevents a bad value from ever reaching it, so guarding
again at call time would duplicate the chokepoint, not add coverage.

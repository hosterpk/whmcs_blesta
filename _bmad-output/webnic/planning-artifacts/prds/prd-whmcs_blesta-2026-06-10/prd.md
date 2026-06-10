---
title: "WebNIC Registrar Module for Blesta — PRD"
status: final
created: 2026-06-10
updated: 2026-06-11
author: Israr
project_name: whmcs_blesta
product_name: "WebNIC Registrar Module for Blesta"
parity_baseline: "components/modules/logicboxes"
api_reference: "https://apidoc.webnic.dev/llms.txt"
source_draft: "../research/research-prd-webnic-blesta-registrar.md"
validation_report: "../research/webnic-prd-validation-2026-06-10/validation-report.md"
release_sections:
  - "Section 1 (MVP) — Feature parity with LogicBoxes/ResellerClub (incl. URL/email forwarding + DNSSEC)"
  - "Section 2 — End-user value features (managed DNS zones, registrant panel, advanced zone DNSSEC/forwarding, security)"
  - "Section 3 — Reseller/admin power features (bulk ops, pricing sync, SSL, brokerage)"
change_log:
  - "2026-06-10: Formalized from research draft. Resolved the 4 High validation findings — parity-matrix framing (§6/G1), forwarding+DNSSEC MVP regression (§5/§6/§7/§9/§12), async state machine (§4.2/§7.1/§9/§11), idempotency + lost-response recovery (FR18/FR18a/§11). Medium/Low findings deferred as logged open items in §11. See .decision-log.md."
  - "2026-06-11: Sealed to final after user sign-off. Forwarding+DNSSEC-into-MVP scope decision (C-002) confirmed. Blockers B1–B7 carried into the architecture phase as inputs."
---

# Product Requirements Document — WebNIC Registrar Module for Blesta

**Date:** 2026-06-10
**Author:** Israr
**Status:** Final (4 High validation findings resolved; sealed 2026-06-11)
**Parity baseline:** Blesta `components/modules/logicboxes` (the module that serves LogicBoxes, ResellerClub, NetEarthOne, and related brands)
**Primary API reference:** WebNIC RESTful v2 API — `https://apidoc.webnic.dev/llms.txt`

---

## 1. Document Overview

### 1.1 Purpose

This PRD specifies a new **registrar module** for the Blesta billing system that integrates the **WebNIC RESTful v2 API** for end-to-end domain lifecycle management — availability search, registration, transfer, renewal, restore, contact/registrant management, nameserver and DNS control, WHOIS privacy, registrar locking, EPP/auth-code handling, URL/email forwarding, DNSSEC, and TLD pricing import.

The document is deliberately **divided into release sections**:

- **Section 1 (MVP)** delivers **feature parity** with Blesta's existing LogicBoxes/ResellerClub registrar module — including the URL/email forwarding and DNSSEC management tabs LogicBoxes ships today — so a Blesta operator can switch a domain reseller business to WebNIC with **no loss of capability**.
- **Section 2** adds **end-user-facing value features** that the WebNIC API offers beyond the parity baseline (full managed DNS zones, registrant control panel, advanced/zone-level DNSSEC & forwarding, proxy/privacy controls, IDN, verification documents).
- **Section 3** adds **reseller/administrator power features** (bulk operations, automated pricing/promo synchronisation, SSL certificate provisioning, domain brokerage, account-balance monitoring, white-label nameservers).

Each later section is independently shippable and assumes the MVP is in production.

### 1.2 What this product is

A Blesta extension that lives at `components/modules/webnic` and extends Blesta's abstract `RegistrarModule` class (`components/modules/registrar_module.php`). It must behave like a first-class Blesta registrar: it implements the registrar contract, integrates with the **Domain Manager (`plugins/domains`)** for TLD pricing and storefront availability search, renders admin and client service tabs, and stores all credentials through Blesta's encrypted module-row settings.

### 1.3 What this product is not (this release line)

- Not a replacement for Blesta core, the Domain Manager plugin, or the order plugin.
- Not a generic multi-registrar abstraction layer; it targets WebNIC specifically.
- Not a payment gateway (the existing KuickPay work is a separate workstream).
- SSL provisioning, brokerage, and secondhand-domain features are **explicitly deferred to Section 3** even though the WebNIC API exposes them.

### 1.4 Definitions

| Term | Meaning |
|------|---------|
| **WebNIC** | The domain/SSL reseller registrar whose RESTful v2 API this module integrates. |
| **Parity baseline** | The capability set of Blesta's `logicboxes` module (LogicBoxes = ResellerClub = NetEarthOne family), as *actually implemented* in `logicboxes.php` (see §6 for the mechanism breakdown). |
| **Module row** | A Blesta concept: one configured connection/account for a module (here, one WebNIC reseller credential set + environment). |
| **Registrar contract** | The public methods Blesta's abstract `RegistrarModule` defines (`registerDomain`, `renewDomain`, `checkAvailability`, `getTldPricing`, etc.). Note: a module may satisfy a capability either by overriding one of these methods *or* via the service-lifecycle methods (`addService`/`renewService`) and `tab*` methods — see §6. |
| **Service lifecycle** | Blesta's `addService` / `renewService` / `suspendService` / `unsuspendService` / `cancelService` / `changeServicePackage` — the hooks Blesta calls on order/billing events. LogicBoxes does its real register/transfer/renew work here. |
| **Contact handle** | A WebNIC contact object referenced by ID and reused across domains (registrant/admin/tech/billing). |
| **Registrant account** | A WebNIC end-user account that provides panel access to administer the domains it owns. |
| **Pending order** | An asynchronous WebNIC order that completes after WebNIC-side processing/verification rather than immediately. |
| **Host object / child nameserver / glue** | One concept: a WebNIC "host" object is the registry glue record that Blesta surfaces as a "child nameserver." Used interchangeably in this document and mapped 1:1 in the module. |
| **OT&E / OTE** | WebNIC's test (sandbox) environment. |

---

## 2. Goals & Background

### 2.1 Background

Blesta operators who resell domains today rely on registrar modules such as LogicBoxes/ResellerClub, eNom, OpenSRS, Namecheap, etc. WebNIC is a strong registrar for the Asia-Pacific market (notably `.hk`, `.tw`, `.my`, IDN/CJK TLDs, and registry programmes) and exposes a modern, well-documented RESTful v2 API. There is currently **no WebNIC module shipped with Blesta**, so operators who want to sell through WebNIC cannot automate it.

This product gives those operators a drop-in WebNIC registrar module. To make adoption safe and low-risk, the MVP intentionally mirrors the proven LogicBoxes module's capability surface and UX so existing staff workflows, package configuration habits, and client expectations transfer unchanged.

### 2.2 Goals

| # | Goal | Success signal |
|---|------|----------------|
| G1 | Operators can resell WebNIC domains from Blesta with **no capability regression vs LogicBoxes**. | Every domain operation a LogicBoxes operator/client can perform today — search, register, transfer-in (with EPP), renew, restore, manage WHOIS/contacts, nameservers, child nameservers, registrar lock, EPP/auth code, WHOIS privacy, DNS records, **URL/email forwarding, and DNSSEC** — works **end-to-end** through the WebNIC module from both admin and client areas. (Capability list is the parity matrix §6; this is a behavioural/end-to-end test, not a method-name inventory.) |
| G2 | Domain registration, renewal, transfer, and lifecycle automation work end-to-end with **payment-safe, idempotent** provisioning. | **0 duplicate registrations caused by the module** and no duplicate registry charges; pending orders and transfers resolve to the correct final state or a clean `failed` state. |
| G3 | The module integrates cleanly with the **Domain Manager** so TLD pricing, availability search, and the domain storefront work. | TLD pricing import (in the operator's configured currencies) and availability search succeed via the WebNIC module. |
| G4 | Later sections unlock WebNIC differentiators (managed DNS zones, registrant panel, bulk ops, SSL) **without rework** of the MVP. | Section 2/3 features are additive; no MVP contract changes required. |
| G5 | The module honours all project engineering constraints (Blesta extension boundaries, encrypted credentials, language files, no core edits, PHP 8.2). | Passes `php -l`, PHPCS gates, and review against `project-context.md`. |

### 2.3 Non-goals

- Migrating existing domains from another registrar's Blesta module into WebNIC automatically (manual/operator-driven only).
- Building a generic DNS hosting product separate from domains.
- Reselling WebNIC SSL in the MVP (Section 3).

---

## 3. Personas & Stakeholders

| Persona | Description | Primary needs |
|---------|-------------|---------------|
| **Blesta admin / operator** | Configures the module, sets reseller credentials, defines packages and TLD pricing, handles support. | Easy connection setup, sandbox testing, pricing import, reliable provisioning, clear error/status visibility. |
| **End customer (registrant)** | Buys and manages domains through the Blesta client area. | Search/register, manage nameservers, DNS records, forwarding, DNSSEC, WHOIS privacy, registrar lock, EPP code, renewals, contact details. |
| **Support / billing staff** | Investigates failed orders, pending transfers, expiries. | Order/transfer status visibility, action logs, manual retry/finalisation. |
| **WebNIC reseller account** | The upstream account whose balance, pricing, and IP allowlist govern API access. | Balance awareness, environment separation (OTE vs production), IP allowlist compliance. |

---

## 4. Integration Constraints & Key Technical Findings

These findings come from the WebNIC v2 API documentation and the Blesta registrar contract. They are load-bearing: they drive several functional and non-functional requirements and must be respected by the architecture.

### 4.1 Authentication is token-based (JWT) with short validity

- Auth flow: `POST {base}/reseller/v2/api-user/token` with `username` + `password` returns a JWT `access_token` plus `expires_in`. **Token validity is ~60 minutes.** Subsequent calls send `Authorization: Bearer <token>`.
- Base URLs are environment-specific: **OTE (test)** `https://oteapi.webnic.cc`, **Production** `https://api.webnic.cc`. OTE tokens are not valid in production.
- **The reseller's server IP must be on WebNIC's allowlist.** This is an operational prerequisite the module cannot self-provision.
- **Implication (requirements):** the module must cache the token and transparently refresh it on expiry/401, must expose an environment toggle (sandbox/production) per module row, and must surface clear errors for IP-allowlist and credential failures.

### 4.2 Registration and transfer can be asynchronous ("pending orders")

- `POST /domain/v2/register` returns `data.pendingOrder` (boolean). When `true`, it returns a `pendingOrderId` and the order completes later after WebNIC-side processing/verification; when `false`, registration is immediate and returns `dtexpire`.
- Transfers are inherently asynchronous: `submit-registrar-transfer-in` then poll `get-registrar-transfer-in-status-*`.
- **There are no webhooks/push notifications in the WebNIC API.** All asynchronous completion must be discovered by **polling**.
- **This async pending-order/transfer reconciliation subsystem is net-new relative to LogicBoxes**, which is fully synchronous (it has no `cron()` hook, no pending-order table, and no polling). It is therefore specified as a **first-class MVP feature** in §7.1 (FR17, FR39–FR39d and the state machine), **not** assumed as "parity."
- **Implication (requirements):** Blesta's `addService`/`transferDomain` are synchronous and expect a boolean. The module must (a) provision the Blesta service in a `pending` state when WebNIC returns a pending order, and (b) run a **cron-based reconciliation** task that polls pending orders and transfer status and finalises the local service, expiry date, and status — idempotently, never double-charging or double-registering, with an explicit timeout/give-up policy.

### 4.3 WebNIC uses a contact-handle + registrant-account model

- Registration requires pre-created **contact handle IDs** (`registrantContactId`, `administratorContactId`, `technicalContactId`, `billingContactId`) and a **`registrantUserId`** (registrant account), plus **at least two nameserver hosts pre-registered with the registry**.
- WebNIC exposes `create-contact`, `create-contact-at-registry`, `modify-contact`, `create-registrant-account`, `check-host`, and `create-host-by-extension` to build these prerequisites.
- This mirrors the LogicBoxes model (customer-id + contact-id), so it is a familiar pattern but must be orchestrated before each registration.
- **[Unverified]** These prerequisites are inferred from the WebNIC v2 endpoint inventory and documentation, not from a quoted request/response contract. They must be **confirmed against OTE** (Blocker **B7**) — including whether registration is genuinely blocked without pre-registered hosts and whether contact handles are reusable — before FR14/FR15 are frozen.
- **Implication (requirements):** the module must map Blesta client/contact data into WebNIC contact handles and a registrant account (creating or reusing them), and must ensure nameserver host objects exist before registration. Handle/account reuse must be deterministic to avoid orphan/duplicate handles.

### 4.4 Pricing has a specific shape and is reseller-cost pricing

- `POST /domain/v2/exts/pricing` returns hierarchical pricing: `items[].productKey` (TLD) → `productPricing.price.{register|renewal|transfer|restore|proxy|whois|rereg}.{ascii|idn}.{1..10 years}`, with USD in `price` and local currency (e.g. `localPrice.myr`) alongside.
- These are **reseller cost prices**; Blesta applies the operator's own markup. Promotional pricing is a separate endpoint (`get-extensions-promo-pricing`).
- **Implication (requirements):** `getTldPricing()` must transform this into Blesta's expected `[tld][currency][year#][register|transfer|renew]` structure, map `renewal→renew`, and — critically — feed Blesta's **currency-conversion path** (as LogicBoxes does via `Currencies->convert`) so pricing is available in the operator's configured currencies, not only the currencies WebNIC returns (see FR7 and deferred item **D1**). ASCII vs IDN and `restore`/`proxy`/`whois` are available for richer mapping in later sections.

### 4.5 TLD-specific rules are data-driven

- WebNIC exposes extension rules: `get-extensions-rule-rg` (registration), `-tf` (transfer), `-grace`, and `-doc` (required documents), plus registry programmes (`bundle-hk-domain`, `check-free-tw-domain-eligibility`).
- LogicBoxes hard-codes per-TLD contact requirements (`.ca`, `.uk`, `.de`, `.xxx`, `.tel`, `.coop`, etc.). WebNIC's rule endpoints let the module derive required fields/terms dynamically.
- **Implication (requirements):** the MVP should consult extension rules to validate terms and required extra fields per TLD rather than hard-coding them where feasible. Where a rule endpoint is unavailable, fall back safely (e.g. the 1–10 year term bound) — see FR13.

### 4.6 Blesta extension boundaries (from `project-context.md`)

- Implement against `RegistrarModule`/`Module`; do not edit Blesta core. Use `Loader`, `Input` validation, `Record` query builder, `ModuleFields`, encrypted module-row fields, `.pdt` views, and language files. Target PHP 8.2. Schema needs install/upgrade artifacts. Keep secrets out of logs/docs/fixtures.

---

## 5. Release Sections — Scope Summary

| Section | Theme | Headline capabilities |
|---------|-------|-----------------------|
| **Section 1 — MVP (Parity)** | Match LogicBoxes/ResellerClub | Connection setup + sandbox test; TLD pricing import; availability (single + bulk); register (sync + pending); transfer-in with EPP; renew; restore; contact/WHOIS management; nameserver + child-nameserver (glue) management; registrar lock; EPP/auth-code; WHOIS privacy (ID protection); basic DNS record management; **URL & email forwarding; DNSSEC (DS/DNSKEY) management**; expiry/registration date sync; admin + client service tabs; **pending-order/transfer state machine + cron reconciliation**. |
| **Section 2 — End-user value** | Customer-facing differentiators | Full managed DNS zones (records, templates), **zone-level / advanced DNSSEC & forwarding beyond the parity tabs**, registrant control-panel access & login dispatch, proxy/privacy subscription controls, IDN support, verification document upload & email resend. |
| **Section 3 — Reseller/admin power** | Operator scale & revenue | Bulk register/renew/replace-contact with reports, automated pricing + promo sync scheduling, account-balance monitoring/alerts, white-label nameservers, WebNIC SSL certificate provisioning, domain brokerage, secondhand domains, registry programmes (HK bundle, free TW eligibility), domain statistics dashboards. |

---

## 6. Parity Matrix — LogicBoxes ↔ Blesta surface ↔ WebNIC (MVP)

This matrix is the definition of "feature parity" for Section 1. **Read the mechanism column carefully:** the real LogicBoxes reference implementation lives in the **service-lifecycle methods (`addService`/`renewService`) and the `tab*` methods**, *not* in the domain-centric contract methods (`registerDomain`, `transferDomain`, `getDomainContacts`, `lockDomain`, …). LogicBoxes overrides only a handful of contract methods directly; the rest are abstract-base defaults it never overrides, doing that work inside `addService`/tabs instead.

The WebNIC module **chooses to implement the modern domain-centric contract methods directly** (cleaner, forward-compatible) *and* wire them into the service lifecycle. That is an intentional **upgrade** over LogicBoxes' structure, not a 1:1 copy — so parity is verified by **end-to-end behaviour** (G1), not by matching a method inventory.

**Legend:** ✅ match (LogicBoxes overrides this method) · 🔁 match via lifecycle/tabs (LogicBoxes does this inside `addService`/`renewService`/`tab*`) · ⬆ upgrade (LogicBoxes leaves this an abstract default / surfaces it elsewhere; WebNIC implements the discrete contract method) · 🆕 net-new (no LogicBoxes equivalent).

| Capability (end-to-end) | How LogicBoxes actually implements it | WebNIC module surface | WebNIC v2 endpoint(s) | MVP |
|---|---|---|---|---|
| Single availability | overrides `checkAvailability` | `checkAvailability` | `Query Domain` / `Check Domain Pattern` | ✅ |
| Bulk availability | abstract default (loops `checkAvailability`) | `bulkCheckAvailability` | `Query Domain` (batched) / `Smart Query by TLDs` | ⬆ |
| Transfer eligibility | abstract default | `checkTransferAvailability` | `Query Transfer Type` | ⬆ |
| Term validation | abstract default | `isValidTerm` | `Get Extensions Rule (RG/TF)` | ⬆ |
| Supported TLD list | overrides `getTlds` | `getTlds` | `Get Domain Extensions` | ✅ |
| Cost pricing (+ FX) | overrides `getTldPricing`/`getFilteredTldPricing`, converts via `Currencies->convert` | same + Blesta FX path | `Get Extension Price` (`/domain/v2/exts/pricing`) | ✅ |
| Register | inside `addService()` (synchronous) | `registerDomain` wired into `addService` | `Create Contact`, `Create Registrant Account`, `Create Host By Extension`, `Register Domain` | 🔁⬆ |
| Transfer-in (EPP) | inside `addService()` (transfer path) | `transferDomain` wired into `addService` | `Submit Registrar Transfer In` + status polling | 🔁⬆ |
| Renew | inside `renewService()` | `renewDomain` wired into `renewService` | `Renew Domain` | 🔁⬆ |
| Restore / redemption | abstract default (LogicBoxes: not implemented) | `restoreDomain` | `Restore Domain` (+ `Get Extensions Rule GRACE`) | ⬆ |
| Resend transfer/verification email | abstract default | `resendTransferEmail` | `Resend Domain Verification Email` | ⬆ |
| EPP/auth code (outbound) | **Settings tab** (`manageSettings` reads `epp_code`); `supportsEppCode` is the abstract default (false) — `config.json` does **not** enable `epp_code` | enable `supportsEppCode`; `sendEppEmail`/`updateEppCode` | `Send` / `Reset Authorization Information` | ⬆ |
| Domain info / dates | `getDomainInfo` abstract default; overrides `getRegistrationDate`/`getExpirationDate` | discrete methods | `Get Domain Info` (`dtexpire`) + local sync | ✅/⬆ |
| Contacts / WHOIS | `tabWhois` / `manageWhois` (tab) | `getDomainContacts`/`setDomainContacts` + WHOIS tab | `Query Contact Info`, `Modify Contact (local/registry)` | 🔁⬆ |
| Nameservers | overrides `getDomainNameServers`/`setDomainNameservers` (+ `tabNameservers`) | same | `Get/Update Domain Nameserver`, `Get WebNIC Default Nameservers` | ✅ |
| Child nameservers / glue | `tabChildNameservers` / `manageChildNameservers` | `setNameserverIps` + child-NS tab | `Create/Modify/Delete Host By Extension`, `Check Host`, `Get Host Info` | 🔁⬆ |
| Registrar lock | **Settings tab** (`manageSettings`) | `getDomainIsLocked`/`lockDomain`/`unlockDomain` | `Get Domain Info` + `Update Domain Status` | 🔁⬆ |
| WHOIS privacy (ID protection) | `config.json` `id_protection` flag + Settings/WHOIS tab | enable `supportsIdProtection` + toggle | `Toggle WHOIS Privacy`, `Get Universal WHOIS Information` | ✅ |
| Basic DNS records | `config.json` `dns_management` flag + `tabDnsRecords`/`manageDnsRecords` | enable `supportsDnsManagement` + DNS tab | `Get/Save/Delete Zone Record`, `Get Supported Record Types` | ✅ (basic) |
| **URL & email forwarding** | **`tabForwarder`/`tabClientForwarder` shipped unconditionally** + `manageForwarder` | forwarding tab (FR35a) | `Get/Add/Remove Zone URL Forwarding`, `Get/Add/Remove Zone Email Forwarding` | ✅ |
| **DNSSEC (DS/DNSKEY)** | **`tabDnssec`/`tabClientDnssec` shown when `dns_management` enabled** + `manageDnssec` | DNSSEC tab (FR35b) | `Check DNSSEC Supported`, `Get/Update/Delete DNSSEC` | ✅ |
| Service lifecycle (`addService`, `renewService`, `suspendService`, `unsuspendService`, `cancelService`, `changeServicePackage`) | **all overridden** — the real provisioning entry points | same, wired to registrar ops | `Register/Renew/Suspend/Delete Domain`, `Get Domain Suspend Status` | ✅ |
| Admin/client service info + tabs | overrides `getAdmin/ClientServiceInfo`, `getAdmin/ClientServiceTabs` | same composition | composition of above | ✅ |
| Pending-order / transfer reconciliation | **none — LogicBoxes is fully synchronous** | net-new state machine + cron (FR17, FR39–FR39d) | `Get Pending Order Info`, transfer-status endpoints, `Get Domain Action Log Info` | 🆕 |

> **Parity note (corrected).** LogicBoxes' shipped `config.json` enables `dns_management` and `id_protection` only; it does **not** enable `epp_code` or an `email_forwarding` flag. However, its client **does** get URL/email forwarding (the forwarder tab is registered unconditionally in `getClientServiceTabs`) and **does** get DNSSEC (shown whenever `dns_management` is on). The WebNIC MVP therefore enables `dns_management`, `id_protection`, `epp_code`, **and** `email_forwarding`, and ships the forwarding + DNSSEC tabs — meeting parity and slightly exceeding the LogicBoxes feature-flag set.

---

## 7. Functional Requirements

> Convention: requirements are numbered continuously (FR1…). Each is tagged with its release section. "The module" means the WebNIC registrar module (plus the companion cron/state surface specified below). New requirements added during the 2026-06-10 formalization use letter suffixes (e.g. FR18a, FR35a, FR39a) so all pre-existing IDs stay stable.

### 7.1 Section 1 — MVP (LogicBoxes/ResellerClub parity)

**Connection, configuration & environment**

- **FR1.** Admin users can install, upgrade, and uninstall the WebNIC module through Blesta's module extension flows without editing Blesta core or removing unrelated data, including idempotent schema install/upgrade artifacts for any module-owned tables.
- **FR2.** Admin users can create one or more **module rows**, each holding WebNIC API username, API secret/password, and an **environment toggle (OTE sandbox / Production)** that selects the correct base URL (`oteapi.webnic.cc` vs `api.webnic.cc`).
- **FR3.** The module stores the WebNIC secret/password using Blesta encrypted module-row fields and masks it in all settings screens, logs, diagnostics, and error paths.
- **FR4.** Admin users can **test connectivity** for a module row (token issuance + a lightweight authenticated call such as account balance or extensions) and receive a clear pass/fail result, including specific messaging for IP-allowlist rejection and invalid credentials, **without registering or charging anything**.
- **FR5.** The module obtains and **caches a bearer token**, transparently refreshing it on expiry or on a `401`, and never logs the token; concurrent operations on the same module row must not cause a token-refresh stampede that corrupts in-flight requests. *(Token-cache backend and single-flight/locking strategy: deferred item D4.)*

**Pricing & TLD catalogue (Domain Manager integration)**

- **FR6.** `getTlds()` returns the list of TLDs/extensions the configured WebNIC account can sell (`Get Domain Extensions`).
- **FR7.** `getTldPricing()` and `getFilteredTldPricing()` return pricing transformed into Blesta's `[tld][currency][year#][register|transfer|renew]` structure from `Get Extension Price`, mapping WebNIC `renewal→renew`, exposing 1–10 year terms, and **feeding Blesta's currency-conversion path (mirroring LogicBoxes' `Currencies->convert`) so pricing is available in the operator's configured currencies**, not only the currencies WebNIC returns. *(Currency strategy specifics: deferred item D1.)*
- **FR8.** Pricing returned to Blesta is WebNIC **reseller cost**; the module must not apply markup itself (markup remains a Blesta/Domain-Manager concern), and must clearly distinguish register/transfer/renew/restore values where the destination supports them.
- **FR9.** The module integrates with the **Domain Manager (`plugins/domains`)** so TLD import, availability search, and the domain storefront operate through the WebNIC module the same way they do for LogicBoxes. *(The exact `plugins/domains` consumption contract, expected pricing shape, sync hooks, and pinned version must be verified against this checkout — deferred item D4.)*

**Availability & validation**

- **FR10.** `checkAvailability($domain)` returns accurate availability via `Query Domain`/`Check Domain Pattern`.
- **FR11.** `bulkCheckAvailability($domains)` checks multiple domains via a batched query (`Smart Query by TLDs`) issuing at most `ceil(N/batch)` API round-trips for N domains, and on any batch failure retries the remaining domains individually, returning partial results with a per-domain status.
- **FR12.** `checkTransferAvailability($domain)` reports transfer eligibility via `Query Transfer Type`.
- **FR13.** `isValidTerm($tld,$term,$transfer)` validates the requested term against WebNIC extension rules (`Get Extensions Rule RG/TF`), defaulting to the 1–10 year bound when a rule is unavailable.

**Registration (with contact-handle orchestration)**

- **FR14.** On `addService`/`registerDomain`, the module maps Blesta client + contact data into the required WebNIC **contact handles** (registrant, admin, tech, billing), creating or deterministically reusing handles to avoid duplicates, and creating/reusing a **registrant account** (`registrantUserId`). *(Reuse policy and registrant-account provisioning are Blockers B3/B4; the underlying order model is Blocker B7.)*
- **FR15.** Before registration, the module ensures the requested **nameservers exist as host objects** (minimum two), using `Check Host`/`Create Host By Extension`/`Get WebNIC Default Nameservers`, falling back to WebNIC default nameservers when the package/order does not specify any.
- **FR16.** `registerDomain` submits `Register Domain` with domain name, term (from the selected package pricing term), nameservers, contact handles, registrant user, and optional `addons.whoisPrivacy`/`addons.proxy` per the package's ID-protection setting and the client's selection.
- **FR17.** When `Register Domain` returns `pendingOrder=true`, the module provisions the Blesta service in a **`pending`** state (per the state machine, FR39a), persists the `pendingOrderId`, and **does not** treat the domain as active until reconciliation confirms completion; when `pendingOrder=false`, it records the returned `dtexpire` and marks the service active.
- **FR18.** Registration, transfer, and renewal are **idempotent and payment-safe**: a retried or duplicated provisioning attempt for the same Blesta service/domain must not create a second WebNIC order or a second registry charge. The module tracks **"duplicate registration caused by the module"** (the module's responsibility) and **"duplicate charge"** (which also involves Blesta invoicing) as distinct outcomes so each is independently attributable. The guard is the durable registration-intent record + by-domain reconciliation defined in FR18a.
- **FR18a.** *(Lost-response recovery — new.)* Before calling `Register Domain` / transfer submit, the module writes a durable **registration-intent record**, committed in its own transaction, keyed uniquely by Blesta service + domain. On any retry where an intent already exists, the module **first queries WebNIC for an existing pending/active order on that domain** and reconciles from it; it **never blind-resubmits**. When an API result is unknown (timeout / lost HTTP response after submit), the module resolves state by query, not by re-issuing the order. *(Depends on a by-domain pending-order lookup — Blocker B2.)*
- **FR19.** TLD-specific required fields (e.g. registry-mandated registrant attributes) are collected via package/service fields and validated using WebNIC extension/document rules (`Get Extensions Rule DOC`) before submission, surfacing actionable validation errors through Blesta `Input`.

**Transfers**

- **FR20.** `transferDomain` submits a transfer-in (`Submit Registrar Transfer In`) including the **EPP/auth code** supplied by the client, provisions the Blesta service as `pending` (FR39a), and stores the transfer identifier.
- **FR21.** The module polls transfer status (`Get Registrar Transfer In Status by Domain/ID`) via cron and finalises the local service (active + expiry date) when the transfer completes, or moves it to `failed` on cancellation/timeout per the give-up policy (FR39b).
- **FR22.** `resendTransferEmail` triggers `Resend Domain Verification Email` for transfers/registrations awaiting verification.

**Renewal, restore, and lifecycle events**

- **FR23.** `renewDomain`/`renewService` submits `Renew Domain` for the selected term and updates the local expiry date from the response. *(Auto-renew interaction with Blesta's renewal automation — Blocker B6.)*
- **FR24.** `restoreDomain` submits `Restore Domain` for domains in redemption/grace, consulting `Get Extensions Rule GRACE` where relevant.
- **FR25.** `suspendService`/`unsuspendService` map to `Suspend Domain` and the appropriate status update, reflecting `Get Domain Suspend Status`. *(Exact registrar-suspend semantics confirmed against the baseline/OTE — deferred item D4.)*
- **FR26.** `cancelService` follows the operator's policy: by default it does **not** delete the domain at the registry (to avoid irreversible loss); any deletion (`Delete Domain`) is gated behind an explicit, clearly-labelled admin choice. *(This is a stated decision; only the UI placement of the deletion control remains open — deferred item D3.)*
- **FR27.** `changeServicePackage` preserves the registered domain and only adjusts billing/term mapping; it must never silently re-register or transfer.

**Contacts / WHOIS**

- **FR28.** `getDomainContacts` returns current registrant/admin/tech/billing contact details (`Query Contact Info` / `Get Universal WHOIS Information`) normalised to Blesta's contact field shape.
- **FR29.** `setDomainContacts` updates contacts via `Modify Contact` / `Modify Contact at Registry`, with validation and clear error reporting; the admin and client WHOIS tabs expose this.

**Nameservers & child nameservers (glue)**

- **FR30.** `getDomainNameServers`/`setDomainNameservers` read and assign a domain's nameservers (`Get/Update Domain Nameserver`).
- **FR31.** `setNameserverIps` manages **child nameservers / glue records / host objects** (one concept, see §1.4) via host endpoints (`Create/Modify/Delete Host By Extension`, `Check Host`, `Get Host Info`).

**Registrar lock, EPP/auth code, WHOIS privacy**

- **FR32.** `getDomainIsLocked`/`lockDomain`/`unlockDomain` read and set the registrar transfer lock (`Get Domain Info` + `Update Domain Status`).
- **FR33.** `supportsEppCode` is enabled; `sendEppEmail` (`Send Authorization Information`) and `updateEppCode` (`Reset Authorization Information`) work for outbound transfers. Auth codes are treated as secrets per NFR1 (never logged, never persisted in cleartext). *(This exceeds the LogicBoxes baseline, which surfaces the outbound auth code via the Settings tab and does not enable `epp_code` — see §6.)*
- **FR34.** `supportsIdProtection` is enabled; clients/admins can toggle WHOIS privacy (`Toggle WHOIS Privacy`) where the TLD allows it, reflecting current state from `Get Universal WHOIS Information`.

**Basic DNS management, forwarding & DNSSEC (parity scope)**

- **FR35.** `supportsDnsManagement` is enabled; the DNS records tab lets clients/admins view and edit basic zone records (`Get Zone Records`, `Get Supported Record Types`, `Save Zone Record`, `Delete Zone Record`) for domains using WebNIC DNS — matching the LogicBoxes basic DNS tab. *(The exact record-type set that constitutes "basic" parity must be enumerated against the LogicBoxes DNS tab — deferred item D4.)* (Advanced/full-zone features are Section 2.)
- **FR35a.** *(URL & email forwarding parity — new, pulled into MVP.)* `supportsEmailForwarding` is enabled and the module provides a **URL & email forwarding tab** (admin + client) matching the LogicBoxes forwarder tab: clients/admins view, add, and remove URL forwards and email forwards for domains using WebNIC DNS (`Get/Add/Remove Zone URL Forwarding`, `Get/Add/Remove Zone Email Forwarding`). LogicBoxes ships this tab unconditionally today, so it is required for zero-regression parity.
- **FR35b.** *(DNSSEC parity — new, pulled into MVP.)* A **DNSSEC tab** (admin + client) matching the LogicBoxes DNSSEC tab lets clients/admins view, add, and remove DS/DNSKEY records and see whether the TLD supports DNSSEC (`Check DNSSEC Supported`, `Get/Update/Delete DNSSEC`). It is shown wherever `dns_management` is enabled, mirroring LogicBoxes' gating. *(Zone-level enable/disable DNSSEC and advanced zone DNSSEC remain Section 2 — FR46.)*

**Admin & client UI**

- **FR36.** `getAdminServiceInfo`/`getClientServiceInfo` render a domain summary (status, registration/expiry dates, lock state, privacy state, nameservers, **and pending/failed provisioning state per FR39c**).
- **FR37.** The module provides admin and client **service tabs** matching the LogicBoxes set: WHOIS/contacts, nameservers, child nameservers, DNS records, **forwarding, DNSSEC**, and settings (lock, EPP, privacy, auto-renew display), each rendered from `.pdt` views with all strings in language files.
- **FR38.** `getPackageFields`/`getAdminAddFields`/`getClientAddFields`/`getAdminEditFields` expose package configuration (TLD/extension selection, term, ID-protection default, nameserver defaults, DNS-management default) using Blesta `ModuleFields`.

**Asynchronous provisioning — pending-order & transfer state machine (net-new; cron)**

- **FR39.** A scheduled task (`cron($key)` or companion cron) polls **pending orders** (`Get Pending Order Info`) and **transfer status**, finalises or fails the corresponding Blesta services, and updates registration/expiry dates — idempotently and without corrupting service state on transient API failures.
- **FR39a.** *(Service state machine — new.)* The module persists each asynchronous order/transfer in a **module-owned table** (required, not conditional) keyed to the Blesta service, tracking an explicit state — `submitted → pending → active`, with terminal `failed` and `cancelled` — plus the `pendingOrderId`/transfer identifier, attempt count, last-polled and created timestamps. Only the defined transitions are legal; a transient API failure never advances or terminates a service.
- **FR39b.** *(Poll & give-up policy — new.)* Reconciliation polls each pending order/transfer on a bounded schedule with backoff and a **hard timeout**: after a configurable maximum age/attempt count, a still-unresolved order transitions to `failed`, raises an operator-visible alert, and stops polling. `[ASSUMPTION: default give-up after 30 days for registrations and transfers, polling once per cron cycle with a capped retry count and exponential-ish backoff on transient errors — exact thresholds confirmed at architecture/OTE; tracked as Blocker B5.]`
- **FR39c.** *(Client-facing pending/failed state — new.)* A pending domain is shown to the client/admin as clearly **in-progress** (not active, not failed); a `failed` domain shows an actionable support state. Blesta must never present a pending domain as a working/active registration.
- **FR39d.** *(Lifecycle while pending — new.)* Renewal, cancellation, and contact/nameserver edits on a service still in `pending` follow a defined policy — by default **blocked or queued** until the order reaches `active` — and are never silently applied against an incomplete registration.

**Dates, errors & resilience**

- **FR40.** `getRegistrationDate`/`getExpirationDate` return registry-accurate dates, syncing from `Get Domain Info` and reconciling with locally stored Domain Manager dates per the existing `RegistrarModule` date logic.
- **FR41.** All WebNIC API failures are normalised into a stable internal result and surfaced through Blesta `Input` errors / messages with localized, non-leaking messages; the module distinguishes **retryable transport errors** (timeouts, 5xx, connection failures — safe to retry/reconcile) from **terminal business errors** (validation, ineligibility, insufficient balance — surfaced to the user, not retried).

**Internationalisation & emails**

- **FR42.** All user-facing strings are in language files (`en_us` mandatory) and retrieved via `Language::_`, following the LogicBoxes multi-locale convention; `getEmailTags` exposes at least the `domain` service tag for email templates.

### 7.2 Section 2 — End-user value features

- **FR43.** **Full managed DNS zones:** clients/admins manage complete DNS zones beyond basic records — zone listing/search/statistics, all supported record types, and zone subscriptions (`Get/Search/Delete Domain Zone`, `Subscribe/Unsubscribe Domain Subscription`, `Get/Save/Delete Zone Record`, record nameservers).
- **FR44.** **Zone record templates:** create and apply reusable record templates (`Get/Create/Update/Delete Zone Subscription Record Template`, add/remove template records).
- **FR45.** **Advanced / zone-level forwarding:** forwarding capabilities beyond the parity tab (FR35a) — e.g. zone-subscription-scoped forwarding management and bulk forwarding operations. *(Basic URL & email forwarding parity now ships in the MVP — FR35a.)*
- **FR46.** **Advanced / zone-level DNSSEC:** zone-level enable/disable and advanced DNSSEC management (`Enable/Disable Domain Zone DNSSEC`, zone-level DS/DNSKEY record management). *(Domain-level DS/DNSKEY DNSSEC parity now ships in the MVP — FR35b.)*
- **FR47.** **Registrant control panel:** clients can be issued WebNIC registrant-account panel access and login info (`Create/Modify Registrant Account`, `Send Login Info`, `Get Registrant Account List`, `Update Registrant User by Domain List`), giving end users direct domain administration.
- **FR48.** **Proxy/privacy subscription controls:** manage proxy subscription separately from WHOIS privacy (`Toggle Proxy Subscription`) with clear client-facing cost/impact messaging.
- **FR49.** **IDN support:** support Internationalised Domain Names end-to-end (the `lang` parameter on registration and ASCII/IDN pricing variants), targeting WebNIC's CJK/APAC strength.
- **FR50.** **Verification documents & email:** clients/admins can upload registry verification documents and resend verification emails (`Upload Verification Document`, `Resend Domain Verification Email`), with status visibility for registrant verification requirements.

### 7.3 Section 3 — Reseller/administrator power features

- **FR51.** **Bulk operations:** admins can run bulk register, bulk renew, and bulk replace-contact with progress/report views (`Bulk Domain Registration/Renewal`, `Bulk Replace Contact`, `Get Bulk Report Overview/Details`).
- **FR52.** **Automated pricing & promo sync:** scheduled synchronisation of cost pricing and promotional pricing into Blesta/Domain-Manager TLD pricing, with diff/preview before apply (`Get Extension Price`, `Get Extensions Promo Pricing`, `Smart Query by TLDs`).
- **FR53.** **Account-balance monitoring:** display WebNIC reseller balance to admins and optionally alert below a configurable threshold or before bulk operations that would overdraw (`Get Account Balance`).
- **FR54.** **White-label nameservers:** manage white-label/vanity nameservers for the reseller brand (`Get/Save/Remove Whitelabel Nameserver`).
- **FR55.** **WebNIC SSL provisioning:** offer SSL certificate products as Blesta services (`Get Product List/Info/Price`, `Generate/Decode CSR`, `Place/Renew/Reissue/Cancel Order`, DCV via `Check DCV Status`/email approver, organizations & contacts). *(Large sub-product; may warrant its own PRD.)*
- **FR56.** **Domain brokerage & secondhand:** initiate domain broker requests and list secondhand domains (`Initiate Domain Broker`, `Insert Secondhand Domain`).
- **FR57.** **Registry programmes:** support special registry programmes such as HK bundles and free TW eligibility (`Bundle HK Domain`, `Check Free TW Domain Eligibility`).
- **FR58.** **Statistics & dashboards:** surface domain and order statistics to admins (`Get Domain Statistics`, `Count Total Domains`, `Get Top Domain Available List`, `Order Statistics/Graph`, `Get Premium Subscription Statistics`).
- **FR59.** **Action-log visibility:** expose `Get Domain Action Log Info` in admin views for support/audit troubleshooting.

---

## 8. Non-Functional Requirements

| # | Category | Requirement |
|---|----------|-------------|
| **NFR1** | **Security — credentials & secrets** | API secret/password stored only via Blesta encrypted module-row fields; never written to logs, diagnostics, fixtures, docs, or error output. Tokens held in memory/cache only, never persisted in cleartext or logged. **EPP/auth codes and WebNIC order identifiers are likewise treated as secrets — never logged and never persisted in cleartext.** |
| **NFR2** | **Security — transport** | All calls over HTTPS with TLS certificate validation enabled; no downgrade or verification bypass. |
| **NFR3** | **Security — input/authz** | All client/admin actions respect Blesta authorization, company scoping, and parent-controller flow; all inputs validated through Blesta `Input` before reaching the API. |
| **NFR4** | **Reliability — idempotency** | Registration, transfer, and renewal are idempotent against retries; the durable registration-intent record + by-domain reconciliation (FR18/FR18a) prevents duplicate registry orders. **"Duplicate registration caused by the module" and "duplicate charge" are tracked distinctly** so module responsibility is attributable. |
| **NFR5** | **Reliability — async handling** | Pending orders/transfers reconcile via cron with bounded retries/backoff and a hard give-up timeout (FR39b); transient failures never flip a service to an incorrect terminal state. |
| **NFR6** | **Performance** | Token caching avoids re-auth on every call; bulk availability and pricing import are batched and time-bounded so cron and storefront searches stay within Blesta's expected latency. *(Concrete latency/round-trip budget: deferred item D4.)* |
| **NFR7** | **Compatibility** | Targets PHP 8.2 (no 8.3+ syntax/APIs); no new ORM/router/view engine; dependencies (if any) under the project's `vendors/` convention; integrates with Domain Manager `plugins/domains` (version to be pinned — D4). |
| **NFR8** | **Extension boundaries** | No Blesta core edits; all behaviour inside `components/modules/webnic` (plus a clearly-scoped companion cron/plugin if architecture requires). |
| **NFR9** | **Observability** | API interactions logged through Blesta module logging with secrets redacted (per NFR1); failed orders/transfers and reconciliation runs are inspectable for support. |
| **NFR10** | **Internationalisation** | All UI text in language files; `en_us` complete; structure ready for the additional locales LogicBoxes ships. |
| **NFR11** | **Schema lifecycle** | Module-owned tables (including the async state-machine and registration-intent tables, FR39a/FR18a) ship with idempotent install + versioned upgrade artifacts and verify both fresh-install and upgrade paths. |
| **NFR12** | **Maintainability** | Follow `project-context.md` rules and the target file's local style; isolate the WebNIC HTTP/JSON client behind a thin API class (mirroring LogicBoxes' `apis/` layout) so endpoints and response normalisation are testable. |
| **NFR13** | **Testability** | API client and response normalisation are unit-testable without live calls; no live external API calls baked into tests unless a controlled OTE sandbox pattern is used; sandbox (OTE) is the default for any live verification. |
| **NFR14** | **Operational prerequisites** | Document that the reseller server IP must be allowlisted by WebNIC and that OTE vs production credentials differ; surface these prerequisites in setup UI/errors. |

---

## 9. Proposed Architecture Shape (non-binding guidance)

To be confirmed in the architecture phase, but the parity baseline strongly suggests:

- **`components/modules/webnic/webnic.php`** — extends `RegistrarModule`; implements the registrar + module lifecycle contract; owns package/service fields, admin/client tabs, and cron.
- **`components/modules/webnic/apis/`** — a `WebnicApi` HTTP/JSON client (token lifecycle, base-URL/env selection, request signing, retries) plus a `WebnicResponse` normaliser and per-domain command groups, mirroring LogicBoxes' `apis/commands/` organisation.
- **`components/modules/webnic/config/webnic.php`** — field maps (contact fields, per-TLD extra fields driven by extension rules), supported terms, defaults.
- **`components/modules/webnic/config.json`** — `type: registrar`; **`features.dns_management`, `features.id_protection`, `features.epp_code`, and `features.email_forwarding` all enabled in MVP**; `package.name_key`/`service.name_key` = `domain`.
- **`components/modules/webnic/views/default/*.pdt`** — service tabs and management screens (incl. forwarding and DNSSEC tabs).
- **`components/modules/webnic/language/en_us/*`** — all strings.
- **Reconciliation cron** — via the module `cron()` hook (and/or coordinated with the Domain Manager's domain synchronisation) for pending orders, transfer status, and expiry/date sync. *(Cron ownership — own hook vs Domain-Manager sync — finalised in architecture; deferred item D4.)*
- **Module-owned tables (required)** — (a) the async **state-machine table** mapping Blesta services ↔ WebNIC order/transfer IDs with state/attempts/timestamps (FR39a); (b) the **registration-intent table** for lost-response recovery (FR18a); (c) contact-handle/registrant-account reuse mapping; all with install/upgrade artifacts.

> The mechanism-level "how" (exact table schemas, poll cadence numbers, token-cache backend, retry/backoff curves) is intentionally left to the architecture phase and captured in `addendum.md`; this PRD fixes the **WHAT** (states, invariants, give-up behaviour, idempotency guarantees).

---

## 10. Out of Scope (this PRD line)

- Automatic bulk migration of existing domains from other registrar modules into WebNIC.
- A standalone DNS hosting product decoupled from domain services.
- Non-WebNIC registrar abstraction or multi-registrar failover.
- Payment gateway behaviour (separate KuickPay workstream).
- Anything requiring Blesta core modification.

---

## 11. Assumptions, Blockers & Open Questions

**Assumptions**

1. The operator holds an active **WebNIC reseller account** with API access and can have the Blesta server IP allowlisted.
2. The **Domain Manager (`plugins/domains`)** is installed/available, as it is the modern Blesta path for TLD pricing and domain storefronts (the parity baseline integrates with it).
3. WebNIC v2 endpoints behave per the published documentation at `apidoc.webnic.dev`; exact request/response field details will be confirmed against OTE during architecture/implementation.
4. PHP 8.2 + Blesta 6.x extension conventions hold per `project-context.md`.

**Blockers — must be resolved before / at architecture kickoff** (each gates a frozen FR):

- **B1** *(was OQ1).* Exact response schemas for `Query Domain`, transfer-status, and `Get Domain Info` (field names for status, lock, privacy, `dtexpire`) — confirm against OTE.
- **B2** *(new — validation H3).* Confirm WebNIC supports a **by-domain lookup of in-flight/pending orders** (`Get Pending Order Info` or an order search keyed by domain, not only by a `pendingOrderId` that may have been lost). **FR18a's lost-response recovery depends on this**; if it does not exist, escalate as a critical design risk and rework the idempotency approach.
- **B3** *(was OQ4).* Contact-handle reuse policy — one shared reseller contact vs per-client vs per-domain handles. Determines the data model, cleanup, and privacy exposure. Gates **FR14/FR15**.
- **B4** *(was OQ5).* Registrant-account provisioning in MVP — create per client, or use a default reseller registrant account (deferring per-client panels to Section 2). Gates **FR14**.
- **B5** *(was OQ6).* Pending-order/transfer **SLAs/timeouts** governing reconciliation give-up and alerting — the concrete thresholds behind **FR39b**.
- **B6** *(was OQ2).* Auto-renew model — registration has no auto-renew flag; confirm WebNIC auto-renew is off or explicitly mapped so renewals are not double-fired against Blesta's automation. Gates **FR23/FR40**.
- **B7** *(new — validation, §4.3).* Confirm the **contact-handle + ≥2 pre-registered hosts** order model against OTE (the §4.3 prerequisites are inferred from endpoint names, not a quoted contract). Gates **FR14/FR15**.

**Deferrable — resolve during architecture/implementation:**

- **D1** *(was OQ3).* Currency strategy: which currency the operator prices in, and how USD/local pricing maps into Domain-Manager currencies and rounding — including the Blesta `Currencies->convert` path required by **FR7**.
- **D2** *(was OQ7).* TLD coverage for MVP launch: full WebNIC catalogue vs a curated launch set (e.g. APAC TLDs first).
- **D3** *(was OQ8 — mostly decided).* Deletion policy on cancel is decided ("do not delete at registry" by default, FR26); residual = where the explicit deletion control lives in the UI.
- **D4** *(consolidated deferred validation Mediums/Lows).* (a) Verify the `plugins/domains` consumption contract, expected pricing shape, sync hooks, and pin its version (FR9/NFR7). (b) Enumerate the exact "basic" DNS record-type set for FR35 parity. (c) Confirm LogicBoxes `cancelService`/suspend semantics before claiming parity (FR25/FR26). (d) Specify the token-cache backend + single-flight/locking strategy for FR5. (e) Add orphan-cleanup/compensation for the multi-step registration saga (FR14–FR16, FR18a). (f) Define a concrete latency/round-trip budget for NFR6. (g) Note OTE-vs-production catalogue divergence: OTE proves the integration; catalogue/pricing/rule behaviour should be smoke-verified (read-only) against production before go-live.

---

## 12. Success Metrics & Acceptance

### MVP exit criteria (binding — Section 1 is "done" when all hold)

- Every capability in the parity matrix (§6) — **including URL/email forwarding and DNSSEC** — has a working implementation verified end-to-end against WebNIC OTE.
- A domain can be searched, registered (both immediate and pending-order paths), renewed, transferred-in (with EPP), restored, locked/unlocked, privacy-toggled, and have contacts/nameservers/child-nameservers/basic-DNS/forwarding/DNSSEC managed — from both admin and client areas.
- TLD pricing imports into the Domain Manager **in the operator's configured currencies** and availability search works through the WebNIC module.
- Pending orders and transfers reconcile automatically via cron to the correct final state (or a clean `failed` state with operator alert), with the state machine (FR39a) and give-up policy (FR39b) in effect, and **0 duplicate registrations caused by the module**.
- Credentials/auth-codes are encrypted/masked; no secrets appear in logs/errors; sandbox/production toggle works; IP-allowlist and credential errors are clearly reported.
- **0 capability regressions vs LogicBoxes** (the forwarding + DNSSEC gap is closed by FR35a/FR35b).
- All strings are in language files; `php -l` passes; the module respects all `project-context.md` engineering rules.

### Post-launch KPIs (monitored — not MVP gates)

| Metric | Target |
|--------|--------|
| Provisioning success rate (excl. legitimate registry rejections) | ≥ 99% |
| Duplicate registrations/charges caused by the module | 0 |
| Pending-order/transfer reconciliation lag | within one cron cycle of WebNIC-side completion |
| Services flipped to a wrong terminal state on a transient failure (counter-metric for the ≥99%/reconciliation push) | 0 |
| Reconciliation cron API-call volume per cycle | within a bounded budget (set in architecture) |

---

## Appendix A — WebNIC v2 Endpoint Inventory (grouped, source: apidoc.webnic.dev/llms.txt)

- **Auth / account:** Generate WebNIC Token v2; Get Account Balance.
- **Domain core:** Check Domain Pattern; Query Domain; Get Domain Info; Register Domain; Renew Domain; Restore Domain; Delete Domain; Suspend Domain; Get Domain Suspend Status; Count Total Domains; Get Domain Statistics; Get Top Domain Available List.
- **Status & security:** Update Domain Status; Toggle WHOIS Privacy; Get Universal WHOIS Information; Reset/Send Authorization Information; Resend Domain Verification Email; Upload Verification Document; Toggle Proxy Subscription.
- **Transfers:** Query Transfer Type; Submit Registrar Transfer In; Get Registrar Transfer In/Away Status (by domain/ID); Update Transfer Away Status; Submit/Get/Update Reseller Transfer.
- **Contacts:** Create Contact; Create Contact at Registry; Query/Modify/Delete Contact; Modify Contact (local/registry); Bulk Replace Contact.
- **Registrant accounts:** Create Registrant Account; Get Registrant Account List; Send Login Info; Update Registrant User by Domain List; Modify Registrant Account.
- **Nameservers / hosts:** Get WebNIC Default Nameservers; Update Domain Nameserver; Check Host; Get Host Info; Modify Host; Create/Delete Host By Extension; Get Host List/Registered Registries/Linked Domain List.
- **DNSSEC (domain):** Check DNSSEC Supported; Get/Update/Delete DNSSEC.
- **Pricing & products:** Get Extension Price; Get Extensions Promo Pricing; Get Domain Extensions; Get Extensions Rule (RG/TF/GRACE/DOC); Smart Query by TLDs.
- **DNS zones:** Get/Search/Delete Domain Zone; Domain Zone Statistics; NS subscriptions; Zone Records (+ supported types, save/delete, basic/subscription record nameservers, replace/remove/delete subscription records); URL & Email Forwarding; Zone DNSSEC (enable/disable, DS/DNSKEY); Zone Subscriptions (subscribe/renew/unsubscribe, auto-renew enable/disable, partner subscription add/remove); Zone Record Templates; Whitelabel Nameservers.
- **Bulk:** Bulk Domain Registration; Bulk Domain Renewal; Bulk Replace Contact; Get Bulk Report Overview/Details.
- **SSL:** CSR generate/decode; product list/info/price; order place/cancel/renew/reissue; order info/statistics/search; download cert; DCV (check status, auth type/info, approver list, email approver); organizations; SSL contacts.
- **Programmes & extras:** Bundle HK Domain; Check Free TW Domain Eligibility; Initiate Domain Broker; Insert Secondhand Domain; Get Domain Action Log Info; Get Pending Order Info; Download Certificate; Get Premium Subscription Statistics.

## Appendix B — Source notes

- WebNIC RESTful v2 API documentation index: `https://apidoc.webnic.dev/llms.txt` (and per-endpoint pages linked therein), retrieved 2026-06-10.
- Blesta registrar contract: `components/modules/registrar_module.php` and base `components/modules/module.php` (this checkout).
- Parity baseline implementation: `components/modules/logicboxes/` (this checkout) — `logicboxes.php` (service-lifecycle + `tab*` methods, `getClientServiceTabs`/`getAdminServiceTabs`, `getFilteredTldPricing`'s `Currencies->convert`), `config.json` feature flags, `apis/commands/`, and admin/client service tabs. The §6 mechanism breakdown is derived from this file.
- Domain Manager integration point: `plugins/domains/` (this checkout).
- Engineering constraints: `_bmad-output/project-context.md` (this checkout).
- Validation basis for the 2026-06-10 formalization: `../research/webnic-prd-validation-2026-06-10/validation-report.md`.

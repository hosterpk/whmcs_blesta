---
workflowType: 'prd'
research_type: 'technical'
project_name: 'whmcs_blesta'
product_name: 'WebNIC Registrar Module for Blesta'
user_name: 'Israr'
date: '2026-06-10'
status: 'draft'
web_research_enabled: true
source_verification: true
api_reference: 'https://apidoc.webnic.dev/llms.txt'
parity_baseline: 'components/modules/logicboxes (Blesta LogicBoxes/ResellerClub registrar module)'
release_sections:
  - 'Section 1 (MVP) — Feature parity with LogicBoxes/ResellerClub'
  - 'Section 2 — End-user value features (managed DNS, registrant panel, security)'
  - 'Section 3 — Reseller/admin power features (bulk ops, pricing sync, SSL, brokerage)'
inputDocuments:
  - _bmad-output/project-context.md
  - components/modules/registrar_module.php
  - components/modules/module.php
  - components/modules/logicboxes/
  - plugins/domains/
---

# Product Requirements Document — WebNIC Registrar Module for Blesta

**Date:** 2026-06-10
**Author:** Israr
**Status:** Draft (for review)
**Parity baseline:** Blesta `components/modules/logicboxes` (the module that serves LogicBoxes, ResellerClub, NetEarthOne, and related brands)
**Primary API reference:** WebNIC RESTful v2 API — `https://apidoc.webnic.dev/llms.txt`

---

## 1. Document Overview

### 1.1 Purpose

This PRD specifies a new **registrar module** for the Blesta billing system that integrates the **WebNIC RESTful v2 API** for end-to-end domain lifecycle management — availability search, registration, transfer, renewal, restore, contact/registrant management, nameserver and DNS control, WHOIS privacy, registrar locking, EPP/auth-code handling, and TLD pricing import.

The document is deliberately **divided into release sections**:

- **Section 1 (MVP)** delivers **feature parity** with Blesta's existing LogicBoxes/ResellerClub registrar module, so a Blesta operator can switch a domain reseller business to WebNIC with no loss of capability.
- **Section 2** adds **end-user-facing value features** that the WebNIC API offers beyond the parity baseline (full managed DNS, registrant control panel, DNSSEC, email/URL forwarding, proxy/privacy).
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
| **Parity baseline** | The capability set of Blesta's `logicboxes` module (LogicBoxes = ResellerClub = NetEarthOne family). |
| **Module row** | A Blesta concept: one configured connection/account for a module (here, one WebNIC reseller credential set + environment). |
| **Registrar contract** | The public methods Blesta calls on a registrar module (`registerDomain`, `renewDomain`, `checkAvailability`, `getTldPricing`, etc.). |
| **Contact handle** | A WebNIC contact object referenced by ID and reused across domains (registrant/admin/tech/billing). |
| **Registrant account** | A WebNIC end-user account that provides panel access to administer the domains it owns. |
| **Pending order** | An asynchronous WebNIC order that completes after WebNIC-side processing/verification rather than immediately. |
| **OT&E / OTE** | WebNIC's test (sandbox) environment. |

---

## 2. Goals & Background

### 2.1 Background

Blesta operators who resell domains today rely on registrar modules such as LogicBoxes/ResellerClub, eNom, OpenSRS, Namecheap, etc. WebNIC is a strong registrar for the Asia-Pacific market (notably `.hk`, `.tw`, `.my`, IDN/CJK TLDs, and registry programmes) and exposes a modern, well-documented RESTful v2 API. There is currently **no WebNIC module shipped with Blesta**, so operators who want to sell through WebNIC cannot automate it.

This product gives those operators a drop-in WebNIC registrar module. To make adoption safe and low-risk, the MVP intentionally mirrors the proven LogicBoxes module's capability surface and UX so existing staff workflows, package configuration habits, and client expectations transfer unchanged.

### 2.2 Goals

| # | Goal | Success signal |
|---|------|----------------|
| G1 | Operators can resell WebNIC domains from Blesta with **no capability regression vs LogicBoxes**. | Every LogicBoxes registrar method has a working WebNIC equivalent (see parity matrix §6). |
| G2 | Domain registration, renewal, transfer, and lifecycle automation work end-to-end with **payment-safe, idempotent** provisioning. | No duplicate registrations/charges; pending orders resolve correctly. |
| G3 | The module integrates cleanly with the **Domain Manager** so TLD pricing, availability search, and the domain storefront work. | TLD pricing import and availability search succeed via the WebNIC module. |
| G4 | Later sections unlock WebNIC differentiators (managed DNS, registrant panel, bulk ops, SSL) **without rework** of the MVP. | Section 2/3 features are additive; no MVP contract changes required. |
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
| **End customer (registrant)** | Buys and manages domains through the Blesta client area. | Search/register, manage nameservers, DNS records, WHOIS privacy, registrar lock, EPP code, renewals, contact details. |
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
- **Implication (requirements):** Blesta's `addService`/`transferDomain` are synchronous and expect a boolean. The module must (a) provision the Blesta service in a `pending` state when WebNIC returns a pending order, and (b) run a **cron-based reconciliation** task that polls pending orders and transfer status and finalises the local service, expiry date, and status. This must be idempotent and must never double-charge or double-register.

### 4.3 WebNIC uses a contact-handle + registrant-account model

- Registration requires pre-created **contact handle IDs** (`registrantContactId`, `administratorContactId`, `technicalContactId`, `billingContactId`) and a **`registrantUserId`** (registrant account), plus **at least two nameserver hosts pre-registered with the registry**.
- WebNIC exposes `create-contact`, `create-contact-at-registry`, `modify-contact`, `create-registrant-account`, `check-host`, and `create-host-by-extension` to build these prerequisites.
- This mirrors the LogicBoxes model (customer-id + contact-id), so it is a familiar pattern but must be orchestrated before each registration.
- **Implication (requirements):** the module must map Blesta client/contact data into WebNIC contact handles and a registrant account (creating or reusing them), and must ensure nameserver host objects exist before registration. Handle/account reuse must be deterministic to avoid orphan/duplicate handles.

### 4.4 Pricing has a specific shape and is reseller-cost pricing

- `POST /domain/v2/exts/pricing` returns hierarchical pricing: `items[].productKey` (TLD) → `productPricing.price.{register|renewal|transfer|restore|proxy|whois|rereg}.{ascii|idn}.{1..10 years}`, with USD in `price` and local currency (e.g. `localPrice.myr`) alongside.
- These are **reseller cost prices**; Blesta applies the operator's own markup. Promotional pricing is a separate endpoint (`get-extensions-promo-pricing`).
- **Implication (requirements):** `getTldPricing()` must transform this into Blesta's expected `[tld][currency][year#][register|transfer|renew]` structure and map `renewal→renew`. ASCII vs IDN and `restore`/`proxy`/`whois` are available for richer mapping in later sections.

### 4.5 TLD-specific rules are data-driven

- WebNIC exposes extension rules: `get-extensions-rule-rg` (registration), `-tf` (transfer), `-grace`, and `-doc` (required documents), plus registry programmes (`bundle-hk-domain`, `check-free-tw-domain-eligibility`).
- LogicBoxes hard-codes per-TLD contact requirements (`.ca`, `.uk`, `.de`, `.xxx`, `.tel`, `.coop`, etc.). WebNIC's rule endpoints let the module derive required fields/terms dynamically.
- **Implication (requirements):** the MVP should consult extension rules to validate terms and required extra fields per TLD rather than hard-coding them where feasible.

### 4.6 Blesta extension boundaries (from `project-context.md`)

- Implement against `RegistrarModule`/`Module`; do not edit Blesta core. Use `Loader`, `Input` validation, `Record` query builder, `ModuleFields`, encrypted module-row fields, `.pdt` views, and language files. Target PHP 8.2. Schema needs install/upgrade artifacts. Keep secrets out of logs/docs/fixtures.

---

## 5. Release Sections — Scope Summary

| Section | Theme | Headline capabilities |
|---------|-------|-----------------------|
| **Section 1 — MVP (Parity)** | Match LogicBoxes/ResellerClub | Connection setup + sandbox test; TLD pricing import; availability (single + bulk); register (sync + pending); transfer-in with EPP; renew; restore; contact/WHOIS management; nameserver + child-nameserver (glue) management; registrar lock; EPP/auth-code; WHOIS privacy (ID protection); basic DNS record management; expiry/registration date sync; admin + client service tabs; cron reconciliation. |
| **Section 2 — End-user value** | Customer-facing differentiators | Full managed DNS zones (records, templates, URL & email forwarding), DNSSEC management UI, registrant control-panel access & login dispatch, proxy/privacy subscription controls, IDN support, verification document upload & email resend. |
| **Section 3 — Reseller/admin power** | Operator scale & revenue | Bulk register/renew/replace-contact with reports, automated pricing + promo sync scheduling, account-balance monitoring/alerts, white-label nameservers, WebNIC SSL certificate provisioning, domain brokerage, secondhand domains, registry programmes (HK bundle, free TW eligibility), domain statistics dashboards. |

---

## 6. Parity Matrix — LogicBoxes ↔ Blesta Contract ↔ WebNIC (MVP)

This matrix is the definition of "feature parity" for Section 1. Every row maps a Blesta registrar capability (as implemented by LogicBoxes) to the WebNIC endpoint(s) that satisfy it.

| Blesta capability / method | LogicBoxes behaviour | WebNIC v2 endpoint(s) | MVP |
|---|---|---|---|
| `checkAvailability($domain)` | Single-domain availability | `Query Domain` / `Check Domain Pattern` | ✅ |
| `bulkCheckAvailability($domains)` | Loops availability | `Query Domain` (batched) / `Smart Query by TLDs` | ✅ |
| `checkTransferAvailability($domain)` | Inverse of availability | `Query Transfer Type` | ✅ |
| `isValidTerm($tld,$term,$transfer)` | 1–10 year guard | `Get Extensions Rule (RG/TF)` | ✅ |
| `getTlds()` | Supported TLD list | `Get Domain Extensions` | ✅ |
| `getTldPricing()` / `getFilteredTldPricing()` | Cost pricing by TLD/term/currency | `Get Extension Price` (`/domain/v2/exts/pricing`) | ✅ |
| `registerDomain($domain,…,$vars)` | Create order w/ contacts, NS, term, privacy | `Create Contact`, `Create Registrant Account`, `Create Host By Extension`, `Register Domain` | ✅ |
| `transferDomain($domain,…,$vars)` | Transfer-in w/ auth code | `Submit Registrar Transfer In` + status polling | ✅ |
| `renewDomain($domain,…,$vars)` | Renew by term | `Renew Domain` | ✅ |
| `restoreDomain($domain,…,$vars)` | Redemption/restore | `Restore Domain` | ✅ |
| `resendTransferEmail($domain)` | Resend transfer/verification email | `Resend Domain Verification Email` | ✅ |
| `sendEppEmail($domain)` | Email auth code to admin | `Send Authorization Information` | ✅ |
| `updateEppCode($domain,$epp,…)` | Set/reset auth code | `Reset Authorization Information` | ✅ |
| `getDomainInfo($domain)` | Domain summary | `Get Domain Info` | ✅ |
| `getRegistrationDate()` / `getExpirationDate()` | Dates from registrar/local | `Get Domain Info` (`dtexpire`) + local sync | ✅ |
| `getDomainContacts()` / `setDomainContacts()` | View/edit WHOIS contacts | `Query Contact Info`, `Modify Contact`, `Modify Contact at Registry` | ✅ |
| `getDomainNameServers()` / `setDomainNameservers()` | View/set NS | `Get/Update Domain Nameserver`, `Get WebNIC Default Nameservers` | ✅ |
| `setNameserverIps()` (child/glue) | Register glue records | `Create/Modify/Delete Host By Extension`, `Check Host`, `Get Host Info` | ✅ |
| `getDomainIsLocked()` / `lockDomain()` / `unlockDomain()` | Registrar lock | `Get Domain Info` + `Update Domain Status` | ✅ |
| `supportsIdProtection()` + privacy toggle | WHOIS privacy add-on | `Toggle WHOIS Privacy`, `Get Universal WHOIS Information` | ✅ |
| `supportsDnsManagement()` + DNS records tab | Basic DNS record edits | `Get/Save/Delete Zone Record`, `Get Supported Record Types` | ✅ (basic) |
| `supportsEppCode()` | Auth-code feature flag | `Reset/Send Authorization Information` | ✅ |
| `supportsEmailForwarding()` | Email forwarding | `Get/Add/Remove Zone Email Forwarding` | ➕ Section 2 |
| Service lifecycle: `addService`, `renewService`, `suspendService`, `unsuspendService`, `cancelService`, `changeServicePackage` | Map Blesta service events to registrar ops | `Register/Renew/Suspend/Delete Domain`, `Get Domain Suspend Status` | ✅ |
| Pending-order / transfer reconciliation | Order-status polling | `Get Pending Order Info`, transfer-status endpoints, `Get Domain Action Log Info` | ✅ |
| `getAdminServiceInfo` / `getClientServiceInfo` + tabs (whois, nameservers, child NS, DNS, settings) | Admin/client management UI | composition of above | ✅ |

> Parity note: LogicBoxes' shipped `config.json` enables `dns_management` and `id_protection`. WebNIC can support **all four** Blesta feature flags (`dns_management`, `id_protection`, `epp_code`, and `email_forwarding` in Section 2), so the WebNIC module meets and can exceed the LogicBoxes feature set.

---

## 7. Functional Requirements

> Convention: requirements are numbered continuously (FR1…). Each is tagged with its release section. "The module" means the WebNIC registrar module (plus any companion cron/plugin surface decided in architecture).

### 7.1 Section 1 — MVP (LogicBoxes/ResellerClub parity)

**Connection, configuration & environment**

- **FR1.** Admin users can install, upgrade, and uninstall the WebNIC module through Blesta's module extension flows without editing Blesta core or removing unrelated data, including idempotent schema install/upgrade artifacts for any module-owned tables.
- **FR2.** Admin users can create one or more **module rows**, each holding WebNIC API username, API secret/password, and an **environment toggle (OTE sandbox / Production)** that selects the correct base URL (`oteapi.webnic.cc` vs `api.webnic.cc`).
- **FR3.** The module stores the WebNIC secret/password using Blesta encrypted module-row fields and masks it in all settings screens, logs, diagnostics, and error paths.
- **FR4.** Admin users can **test connectivity** for a module row (token issuance + a lightweight authenticated call such as account balance or extensions) and receive a clear pass/fail result, including specific messaging for IP-allowlist rejection and invalid credentials, **without registering or charging anything**.
- **FR5.** The module obtains and **caches a bearer token**, transparently refreshing it on expiry or on a `401`, and never logs the token; concurrent operations on the same module row must not cause a token-refresh stampede that corrupts in-flight requests.

**Pricing & TLD catalogue (Domain Manager integration)**

- **FR6.** `getTlds()` returns the list of TLDs/extensions the configured WebNIC account can sell (`Get Domain Extensions`).
- **FR7.** `getTldPricing()` and `getFilteredTldPricing()` return pricing transformed into Blesta's `[tld][currency][year#][register|transfer|renew]` structure from `Get Extension Price`, mapping WebNIC `renewal→renew`, exposing 1–10 year terms, and supporting at least the account's primary currency (USD) plus any local currency WebNIC returns.
- **FR8.** Pricing returned to Blesta is WebNIC **reseller cost**; the module must not apply markup itself (markup remains a Blesta/Domain-Manager concern), and must clearly distinguish register/transfer/renew/restore values where the destination supports them.
- **FR9.** The module integrates with the **Domain Manager (`plugins/domains`)** so TLD import, availability search, and the domain storefront operate through the WebNIC module the same way they do for LogicBoxes.

**Availability & validation**

- **FR10.** `checkAvailability($domain)` returns accurate availability via `Query Domain`/`Check Domain Pattern`.
- **FR11.** `bulkCheckAvailability($domains)` checks multiple domains efficiently (batched query / `Smart Query by TLDs`) and degrades gracefully to per-domain checks on partial failure.
- **FR12.** `checkTransferAvailability($domain)` reports transfer eligibility via `Query Transfer Type`.
- **FR13.** `isValidTerm($tld,$term,$transfer)` validates the requested term against WebNIC extension rules (`Get Extensions Rule RG/TF`), defaulting to the 1–10 year bound when a rule is unavailable.

**Registration (with contact-handle orchestration)**

- **FR14.** On `addService`/`registerDomain`, the module maps Blesta client + contact data into the required WebNIC **contact handles** (registrant, admin, tech, billing), creating or deterministically reusing handles to avoid duplicates, and creating/reusing a **registrant account** (`registrantUserId`).
- **FR15.** Before registration, the module ensures the requested **nameservers exist as host objects** (minimum two), using `Check Host`/`Create Host By Extension`/`Get WebNIC Default Nameservers`, falling back to WebNIC default nameservers when the package/order does not specify any.
- **FR16.** `registerDomain` submits `Register Domain` with domain name, term (from the selected package pricing term), nameservers, contact handles, registrant user, and optional `addons.whoisPrivacy`/`addons.proxy` per the package's ID-protection setting and the client's selection.
- **FR17.** When `Register Domain` returns `pendingOrder=true`, the module provisions the Blesta service in a **`pending`** state, persists the `pendingOrderId`, and **does not** treat the domain as active until reconciliation confirms completion; when `pendingOrder=false`, it records the returned `dtexpire` and marks the service active.
- **FR18.** Registration is **idempotent and payment-safe**: a retried or duplicated provisioning attempt for the same service/domain must not create a second WebNIC order or a second charge; the module uses durable local state + uniqueness to guard against duplicates.
- **FR19.** TLD-specific required fields (e.g. registry-mandated registrant attributes) are collected via package/service fields and validated using WebNIC extension/document rules (`Get Extensions Rule DOC`) before submission, surfacing actionable validation errors through Blesta `Input`.

**Transfers**

- **FR20.** `transferDomain` submits a transfer-in (`Submit Registrar Transfer In`) including the **EPP/auth code** supplied by the client, provisions the Blesta service as `pending`, and stores the transfer identifier.
- **FR21.** The module polls transfer status (`Get Registrar Transfer In Status by Domain/ID`) via cron and finalises the local service (active + expiry date) when the transfer completes, or surfaces a failure/cancellation state for support.
- **FR22.** `resendTransferEmail` triggers `Resend Domain Verification Email` for transfers/registrations awaiting verification.

**Renewal, restore, and lifecycle events**

- **FR23.** `renewDomain`/`renewService` submits `Renew Domain` for the selected term and updates the local expiry date from the response.
- **FR24.** `restoreDomain` submits `Restore Domain` for domains in redemption/grace, consulting `Get Extensions Rule GRACE` where relevant.
- **FR25.** `suspendService`/`unsuspendService` map to `Suspend Domain` and the appropriate status update, reflecting `Get Domain Suspend Status`.
- **FR26.** `cancelService` follows the operator's policy: by default it does **not** delete the domain at the registry (to avoid irreversible loss), and any deletion (`Delete Domain`) is gated behind an explicit, clearly-labelled admin choice.
- **FR27.** `changeServicePackage` preserves the registered domain and only adjusts billing/term mapping; it must never silently re-register or transfer.

**Contacts / WHOIS**

- **FR28.** `getDomainContacts` returns current registrant/admin/tech/billing contact details (`Query Contact Info` / `Get Universal WHOIS Information`) normalised to Blesta's contact field shape.
- **FR29.** `setDomainContacts` updates contacts via `Modify Contact` / `Modify Contact at Registry`, with validation and clear error reporting; the admin and client WHOIS tabs expose this.

**Nameservers & child nameservers (glue)**

- **FR30.** `getDomainNameServers`/`setDomainNameservers` read and assign a domain's nameservers (`Get/Update Domain Nameserver`).
- **FR31.** `setNameserverIps` manages **child nameservers / glue records** via host endpoints (`Create/Modify/Delete Host By Extension`, `Check Host`, `Get Host Info`).

**Registrar lock, EPP/auth code, WHOIS privacy**

- **FR32.** `getDomainIsLocked`/`lockDomain`/`unlockDomain` read and set the registrar transfer lock (`Get Domain Info` + `Update Domain Status`).
- **FR33.** `supportsEppCode` is enabled; `sendEppEmail` (`Send Authorization Information`) and `updateEppCode` (`Reset Authorization Information`) work for outbound transfers.
- **FR34.** `supportsIdProtection` is enabled; clients/admins can toggle WHOIS privacy (`Toggle WHOIS Privacy`) where the TLD allows it, reflecting current state from `Get Universal WHOIS Information`.

**Basic DNS management (parity scope)**

- **FR35.** `supportsDnsManagement` is enabled; the DNS records tab lets clients/admins view and edit basic zone records (`Get Zone Records`, `Get Supported Record Types`, `Save Zone Record`, `Delete Zone Record`) for domains using WebNIC DNS — matching the LogicBoxes basic DNS tab. (Advanced zone features are Section 2.)

**Admin & client UI**

- **FR36.** `getAdminServiceInfo`/`getClientServiceInfo` render a domain summary (status, registration/expiry dates, lock state, privacy state, nameservers).
- **FR37.** The module provides admin and client **service tabs** matching the LogicBoxes set: WHOIS/contacts, nameservers, child nameservers, DNS records, and settings (lock, EPP, privacy, auto-renew display), each rendered from `.pdt` views with all strings in language files.
- **FR38.** `getPackageFields`/`getAdminAddFields`/`getClientAddFields`/`getAdminEditFields` expose package configuration (TLD/extension selection, term, ID-protection default, nameserver defaults, DNS-management default) using Blesta `ModuleFields`.

**Reconciliation, dates & resilience (cron)**

- **FR39.** A scheduled task (`cron($key)` or companion cron) polls **pending orders** (`Get Pending Order Info`) and **transfer status**, finalises or fails the corresponding Blesta services, and updates registration/expiry dates — idempotently and without corrupting service state on transient API failures.
- **FR40.** `getRegistrationDate`/`getExpirationDate` return registry-accurate dates, syncing from `Get Domain Info` and reconciling with locally stored Domain Manager dates per the existing `RegistrarModule` date logic.
- **FR41.** All WebNIC API failures are normalised into a stable internal result and surfaced through Blesta `Input` errors / messages with localized, non-leaking messages; the module distinguishes retryable transport errors from terminal business errors.

**Internationalisation & emails**

- **FR42.** All user-facing strings are in language files (`en_us` mandatory) and retrieved via `Language::_`, following the LogicBoxes multi-locale convention; `getEmailTags` exposes at least the `domain` service tag for email templates.

### 7.2 Section 2 — End-user value features

- **FR43.** **Full managed DNS zones:** clients/admins manage complete DNS zones beyond basic records — zone listing/search/statistics, all supported record types, and zone subscriptions (`Get/Search/Delete Domain Zone`, `Subscribe/Unsubscribe Domain Subscription`, `Get/Save/Delete Zone Record`, record nameservers).
- **FR44.** **Zone record templates:** create and apply reusable record templates (`Get/Create/Update/Delete Zone Subscription Record Template`, add/remove template records).
- **FR45.** **URL & email forwarding:** clients manage URL forwarding and email forwarding (`Get/Add/Remove Zone URL Forwarding`, `Get/Add/Remove Zone Email Forwarding`); enable `supportsEmailForwarding` and the email-forwarding feature flag/tab to fully match the registrar contract.
- **FR46.** **DNSSEC management:** clients/admins enable/disable and manage DNSSEC and view DS/DNSKEY records (`Check DNSSEC Supported`, `Get/Update/Delete DNSSEC`, zone-level `Enable/Disable Domain Zone DNSSEC`, `Get … DS/DNS Key Record`).
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
| **NFR1** | **Security — credentials** | API secret/password stored only via Blesta encrypted module-row fields; never written to logs, diagnostics, fixtures, docs, or error output. Tokens held in memory/cache only, never persisted in cleartext or logged. |
| **NFR2** | **Security — transport** | All calls over HTTPS with TLS certificate validation enabled; no downgrade or verification bypass. |
| **NFR3** | **Security — input/authz** | All client/admin actions respect Blesta authorization, company scoping, and parent-controller flow; all inputs validated through Blesta `Input` before reaching the API. |
| **NFR4** | **Reliability — idempotency** | Registration, transfer, and renewal are idempotent against retries; durable local state + uniqueness prevents duplicate registry orders or duplicate charges. |
| **NFR5** | **Reliability — async handling** | Pending orders/transfers reconcile via cron with bounded retries/backoff; transient failures never flip a service to an incorrect terminal state. |
| **NFR6** | **Performance** | Token caching avoids re-auth on every call; bulk availability and pricing import are batched and time-bounded so cron and storefront searches stay within Blesta's expected latency. |
| **NFR7** | **Compatibility** | Targets PHP 8.2 (no 8.3+ syntax/APIs); no new ORM/router/view engine; dependencies (if any) under the project's `vendors/` convention; integrates with Domain Manager `plugins/domains` v2.x. |
| **NFR8** | **Extension boundaries** | No Blesta core edits; all behaviour inside `components/modules/webnic` (plus a clearly-scoped companion cron/plugin if architecture requires). |
| **NFR9** | **Observability** | API interactions logged through Blesta module logging with secrets redacted; failed orders/transfers and reconciliation runs are inspectable for support. |
| **NFR10** | **Internationalisation** | All UI text in language files; `en_us` complete; structure ready for the additional locales LogicBoxes ships. |
| **NFR11** | **Schema lifecycle** | Any module-owned tables ship with idempotent install + versioned upgrade artifacts and verify both fresh-install and upgrade paths. |
| **NFR12** | **Maintainability** | Follow `project-context.md` rules and the target file's local style; isolate the WebNIC HTTP/JSON client behind a thin API class (mirroring LogicBoxes' `apis/` layout) so endpoints and response normalisation are testable. |
| **NFR13** | **Testability** | API client and response normalisation are unit-testable without live calls; no live external API calls baked into tests unless a controlled OTE sandbox pattern is used; sandbox (OTE) is the default for any live verification. |
| **NFR14** | **Operational prerequisites** | Document that the reseller server IP must be allowlisted by WebNIC and that OTE vs production credentials differ; surface these prerequisites in setup UI/errors. |

---

## 9. Proposed Architecture Shape (non-binding guidance)

To be confirmed in the architecture phase, but the parity baseline strongly suggests:

- **`components/modules/webnic/webnic.php`** — extends `RegistrarModule`; implements the registrar + module lifecycle contract; owns package/service fields, admin/client tabs, and cron.
- **`components/modules/webnic/apis/`** — a `WebnicApi` HTTP/JSON client (token lifecycle, base-URL/env selection, request signing, retries) plus a `WebnicResponse` normaliser and per-domain command groups, mirroring LogicBoxes' `apis/commands/` organisation.
- **`components/modules/webnic/config/webnic.php`** — field maps (contact fields, per-TLD extra fields driven by extension rules), supported terms, defaults.
- **`components/modules/webnic/config.json`** — `type: registrar`; `features.dns_management`, `features.id_protection`, `features.epp_code` enabled in MVP (`email_forwarding` added in Section 2); `package.name_key`/`service.name_key` = `domain`.
- **`components/modules/webnic/views/default/*.pdt`** — service tabs and management screens.
- **`components/modules/webnic/language/en_us/*`** — all strings.
- **Reconciliation cron** — via the module `cron()` hook (and/or coordinated with the Domain Manager's domain synchronisation) for pending orders, transfer status, and expiry/date sync.
- **Module-owned tables** (if needed) — to map Blesta services ↔ WebNIC order/transfer IDs, contact-handle/registrant-account reuse, and reconciliation bookkeeping; with install/upgrade artifacts.

---

## 10. Out of Scope (this PRD line)

- Automatic bulk migration of existing domains from other registrar modules into WebNIC.
- A standalone DNS hosting product decoupled from domain services.
- Non-WebNIC registrar abstraction or multi-registrar failover.
- Payment gateway behaviour (separate KuickPay workstream).
- Anything requiring Blesta core modification.

---

## 11. Assumptions & Open Questions

**Assumptions**

1. The operator holds an active **WebNIC reseller account** with API access and can have the Blesta server IP allowlisted.
2. The **Domain Manager (`plugins/domains`)** is installed/available, as it is the modern Blesta path for TLD pricing and domain storefronts (the parity baseline integrates with it).
3. WebNIC v2 endpoints behave per the published documentation at `apidoc.webnic.dev`; exact request/response field details will be confirmed against OTE during architecture/implementation.
4. PHP 8.2 + Blesta 6.x extension conventions hold per `project-context.md`.

**Open questions (to resolve before/at architecture)**

- **OQ1.** Exact response schemas for `Query Domain`, transfer-status, and `Get Domain Info` (field names for status, lock, privacy, `dtexpire`) — confirm against OTE.
- **OQ2.** Auto-renew model: registration has no auto-renew flag; is auto-renew managed via domain subscription endpoints, and how should it map to Blesta's renewal automation?
- **OQ3.** Currency strategy: which currency does the operator price in, and how should USD/local pricing map into Domain Manager currencies and rounding?
- **OQ4.** Contact-handle reuse policy: one shared reseller contact vs per-client handles vs per-domain handles — privacy, registry rules, and cleanup implications.
- **OQ5.** Registrant-account provisioning in MVP: create per client, or use a default reseller registrant account and defer per-client panels to Section 2?
- **OQ6.** Pending-order UX: how should a `pending` domain service appear to the client during WebNIC processing, and what SLAs/timeouts govern reconciliation give-up/alerting?
- **OQ7.** TLD coverage for MVP launch: full WebNIC catalogue vs a curated launch set (e.g. APAC TLDs first)?
- **OQ8.** Deletion policy on cancel: confirm default of "do not delete at registry" and where the explicit deletion control lives.

---

## 12. Success Metrics & Acceptance

**MVP acceptance (Section 1) is met when:**

- Every row in the parity matrix (§6) has a working implementation verified against WebNIC OTE.
- A domain can be searched, registered (both immediate and pending-order paths), renewed, transferred-in (with EPP), restored, locked/unlocked, privacy-toggled, and have contacts/nameservers/child-nameservers/basic-DNS managed — from both admin and client areas.
- TLD pricing imports into the Domain Manager and availability search works through the WebNIC module.
- Pending orders and transfers reconcile automatically via cron with correct final state, no duplicate orders, and no duplicate charges.
- Credentials are encrypted/masked; no secrets appear in logs/errors; sandbox/production toggle works; IP-allowlist and credential errors are clearly reported.
- All strings are in language files; `php -l` passes; the module respects all `project-context.md` engineering rules.

**Quantitative targets (post-launch, illustrative):**

| Metric | Target |
|--------|--------|
| Provisioning success rate (excl. legitimate registry rejections) | ≥ 99% |
| Duplicate registrations/charges caused by the module | 0 |
| Pending-order/transfer reconciliation lag | within one cron cycle of WebNIC-side completion |
| Capability regressions vs LogicBoxes at MVP | 0 |

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
- Parity baseline implementation: `components/modules/logicboxes/` (this checkout) — `config.json` feature flags, `apis/commands/`, and admin/client service tabs.
- Domain Manager integration point: `plugins/domains/` (this checkout).
- Engineering constraints: `_bmad-output/project-context.md` (this checkout).

---

_End of PRD._

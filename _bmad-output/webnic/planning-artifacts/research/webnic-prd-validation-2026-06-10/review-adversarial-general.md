# Adversarial Review — WebNIC Registrar Module PRD

**Document under review:** `_bmad-output/planning-artifacts/research/research-prd-webnic-blesta-registrar.md`
**Reviewer stance:** cynical, adversarial. Working entirely from files on disk.
**Date:** 2026-06-10
**Parity baseline actually inspected:** `components/modules/logicboxes/` (`logicboxes.php`, `config.json`, `apis/`, `views/`) and the abstract contract `components/modules/registrar_module.php`.

---

## Verdict

This PRD reads well and is structurally complete, but it is built on a **false parity premise** and a **large net-new asynchronous subsystem that it disguises as "parity."** The parity matrix (§6) does not correspond to what the LogicBoxes module actually does, and at least two capabilities that LogicBoxes ships today (email/URL forwarding and DNSSEC) are **deferred to Section 2 — making the "no regression" MVP an actual regression** against its own baseline. The async/eventual-consistency story is narrated, not specified: timeouts, give-up, stuck-pending behavior, and the cron-vs-Domain-Manager ownership are all open. Several FRs are untestable verb-soup. Multiple §11 "open questions" are MVP blockers, not deferrable questions.

Fix the parity matrix, pull forwarding+DNSSEC back into MVP (or explicitly own the regression in the goals), and convert the async narrative into hard FRs with acceptance criteria before this goes to architecture.

---

## How the baseline actually behaves (evidence the PRD ignores)

Before the findings, the load-bearing facts from the real module, because nearly every Critical finding traces back to them:

- **LogicBoxes overrides almost none of the registrar-contract methods the matrix lists.** `grep` for `registerDomain|transferDomain|renewDomain|restoreDomain|getDomainInfo|getDomainContacts|setDomainContacts|getDomainIsLocked|lockDomain|unlockDomain|sendEppEmail|updateEppCode|resendTransferEmail|checkTransferAvailability|isValidTerm|bulkCheckAvailability|supportsEppCode|supportsIdProtection|supportsDnsManagement` against `logicboxes.php` returns **nothing**. Those are default implementations on the abstract `RegistrarModule` (`components/modules/registrar_module.php`). LogicBoxes does its real work in `addService()` (register/transfer), `renewService()` (renew), and the tab methods (`tabWhois`, `tabSettings`, `tabNameservers`, `tabChildNameservers`, `tabForwarder`, `tabDnssec`, `tabDnsRecords`). The only contract methods it overrides are `checkAvailability`, `getRegistrationDate`, `getExpirationDate`, `getServiceDomain`, `getTlds`, `getTldPricing`, `getFilteredTldPricing`, `getDomainNameServers`, `setDomainNameservers`.
- **LogicBoxes registration/transfer is synchronous.** `addService()` (logicboxes.php:185) calls `$domains->register(...)` / `$domains->transfer(...)`, reads `entityid` off the response immediately, and returns service meta in the same request. **There is no `cron()` method, no pending-order table, no polling, no reconciliation** anywhere in the module. `grep -n 'function cron'` → nothing.
- **LogicBoxes does no provisioning-time validation and no idempotency guard.** `addService()` literally contains `# TODO: Handle validation checks`. There is no dedupe/uniqueness check before calling `register`.
- **LogicBoxes does not "charge."** Charging is Blesta core/invoicing. The module only provisions. So "double-charge" is mostly a core/orchestration concern, not something the LogicBoxes module itself does or guards.
- **`config.json` enables only `dns_management` and `id_protection`.** There is no `epp_code` and no `email_forwarding` feature flag. Therefore `supportsEppCode()` returns the abstract default **false**, and EPP is surfaced through the **Settings tab** (`manageSettings` reads `domsecret`/`epp_code`), not via `supportsEppCode`/`sendEppEmail`/`updateEppCode`.
- **LogicBoxes ships Forwarder (URL/email) tabs and DNSSEC tabs in its default views and registers them in `getAdminServiceTabs`/`getClientServiceTabs`** (logicboxes.php:1238-1313; `views/default/tab_forwarder.pdt`, `tab_dnssec.pdt`, and client variants). DNSSEC/DNS tabs gate on `dns_management`; forwarder is always present.

Hold those against the PRD and the parity story falls apart.

---

## CRITICAL

### C1. The §6 parity matrix maps to LogicBoxes *methods that don't exist in LogicBoxes*
**Location:** §6 Parity Matrix; Goal G1 ("Every LogicBoxes registrar method has a working WebNIC equivalent").
**Quoted:** *"`registerDomain($domain,…,$vars)` | LogicBoxes behaviour | Create order w/ contacts… | ✅"* and the rows for `transferDomain`, `renewDomain`, `restoreDomain`, `getDomainInfo`, `getDomainContacts/setDomainContacts`, `getDomainIsLocked/lockDomain/unlockDomain`, `sendEppEmail`, `updateEppCode`, `resendTransferEmail`, `checkTransferAvailability`, `isValidTerm`, `bulkCheckAvailability`, `supportsEppCode/IdProtection/DnsManagement`.
**Why it bites:** LogicBoxes overrides *none* of these. They are abstract-base defaults (most of which `return true`/no-op or throw "not supported"). LogicBoxes performs register/transfer/renew inside `addService`/`renewService` and exposes WHOIS/lock/EPP/NS through **tabs**, not through these contract methods. The matrix therefore measures parity against the *abstract contract*, then labels it "LogicBoxes behaviour." A downstream architect who builds WebNIC by implementing these discrete methods will produce a module shaped **unlike** LogicBoxes and may break the Domain-Manager/order flow that expects the `addService`-centric pattern. Goal G1's success signal is literally unverifiable as written, because there is no 1:1 "LogicBoxes registrar method" set to compare against.
**Fix:** Re-derive the matrix from the *actual* overridden surface of `logicboxes.php` (the ~9 contract methods it overrides plus `addService`/`renewService`/`suspendService`/`unsuspendService`/`cancelService`/`changeServicePackage`/the tab methods). State explicitly which behaviors live in `addService` vs discrete contract methods, and which are abstract-base defaults you are choosing to *upgrade* (net-new) vs *match*. Re-word G1's success signal to reference observable end-to-end flows, not a non-existent method inventory.

### C2. MVP is a *regression* against the baseline: email/URL forwarding and DNSSEC are deferred to Section 2
**Location:** §6 (FR45 row marked "➕ Section 2"), §7.2 FR45/FR46, §5 (Section 2 themes), vs Goal G1 and §12 metric "Capability regressions vs LogicBoxes at MVP: 0".
**Quoted:** *"`supportsEmailForwarding()` | Email forwarding | … | ➕ Section 2"* and FR46 *"**DNSSEC management:** … (Section 2)"*; meanwhile §12 promises *"Capability regressions vs LogicBoxes at MVP | 0"*.
**Why it bites:** LogicBoxes **ships** URL+email forwarding (`tabForwarder`/`tabClientForwarder`, `manageForwarder`, `views/default/tab_forwarder.pdt`) and **ships** DNSSEC (`tabDnssec`/`tabClientDnssec`, `manageDnssec`, `tab_dnssec.pdt`) today, in the very module the PRD calls the parity baseline. Deferring both to Section 2 means a Blesta operator switching from LogicBoxes to the "parity" WebNIC MVP **loses** forwarding and DNSSEC UI. That is the exact regression the PRD swears cannot happen. The self-contradiction is in black and white.
**Fix:** Either (a) pull forwarding (URL+email) and DNSSEC into Section 1 to honor parity — the WebNIC endpoints exist (`Get/Add/Remove Zone URL/Email Forwarding`, `Check DNSSEC Supported`, `Get/Update/Delete DNSSEC`), so this is scoping, not capability; or (b) keep them in Section 2 but **delete the "0 regressions" claim** and explicitly list forwarding+DNSSEC as accepted MVP regressions in §2.3/§12 with operator sign-off. Do not ship both the deferral and the zero-regression promise.

### C3. The entire async/pending-order/reconciliation subsystem is net-new but justified as "parity" — and it is under-specified
**Location:** §4.2, FR17, FR21, FR39, NFR4/NFR5, §6 "Pending-order / transfer reconciliation ✅".
**Quoted:** *"Blesta's `addService`/`transferDomain` are synchronous and expect a boolean. The module must … run a **cron-based reconciliation** task that polls pending orders and transfer status …"* and FR39 *"A scheduled task … polls pending orders … finalises or fails … idempotently …"*
**Why it bites:** LogicBoxes has **no cron, no pending table, no polling** — registration is synchronous. So this is a brand-new stateful subsystem (durable order/transfer state, a scheduler, idempotent finalization, failure transitions) being slipped into an MVP under the banner of "parity," with none of the hard questions answered:
  - **No timeout / give-up policy.** FR39 says "finalises or fails" but never defines when a stuck `pending` becomes `failed`, after how long, how many poll attempts, or with what backoff. OQ6 admits this ("what SLAs/timeouts govern reconciliation give-up/alerting?") — i.e. the PRD *knows* it's unspecified and ships it anyway as a checked-off parity item.
  - **No stuck-service behavior.** What does the client see for a service that never resolves? Does Blesta keep invoicing/renewing a `pending` domain? Can the operator cancel it? Undefined.
  - **No ownership decision.** FR39 hedges "`cron($key)` or companion cron"; §9 hedges "module `cron()` hook (and/or coordinated with the Domain Manager's domain synchronisation)." Whether the module owns its own cron or piggybacks Domain Manager sync is an architecture-blocking fork left open.
  - **No state model.** "Durable local state" (FR18/NFR4) is asserted but never modeled. Which states exist (`submitted`, `pending`, `polling`, `active`, `failed`, `cancelled`)? What are the legal transitions? Where does `pendingOrderId`/transfer id live (the §9 "module-owned tables (if needed)" is conditional)?
**Fix:** Promote the async subsystem to a first-class, *named* MVP feature (not "parity"). Add FRs that specify: the service state machine and legal transitions; per-order poll cadence, max attempts, backoff, and hard timeout; the terminal "give-up" state and operator alert; client-facing presentation of a long-pending domain; whether renewals/cancels are allowed while pending; and the concrete persistence (a defined table with install/upgrade artifacts, not "if needed"). Make OQ6 a blocker, not a question.

### C4. Idempotency / "no double-registration" is asserted but the mechanism is hand-waved, and the irreversible-money path is unspecified
**Location:** FR18, NFR4, G2, §12 ("Duplicate registrations/charges caused by the module | 0").
**Quoted:** *"Registration is **idempotent and payment-safe**: … the module uses durable local state + uniqueness to guard against duplicates."*
**Why it bites:** "durable local state + uniqueness" is a slogan, not a design. WebNIC register is asynchronous (`pendingOrder`) and there are **no webhooks** (§4.2) — so the dangerous window is exactly: submit `register`, the HTTP call times out or the worker dies *before* persisting `pendingOrderId`, Blesta retries `addService`, and you submit a **second** registry order. Nothing in FR14–FR18 specifies a pre-submit reservation/dedupe key, an idempotency token sent to WebNIC (does the API even support one? unevidenced — see H3), or a "check pending order by domain before re-submitting" step. The baseline gives no help here (LogicBoxes has no guard at all). For an irreversible, money-spending operation this is the single most expensive gap to get wrong, and the PRD's own success metric (0 duplicates) has no specified enforcement.
**Fix:** Specify the idempotency mechanism concretely: (1) write a local "registration intent" row *before* the API call, keyed uniquely by service+domain, in its own committed transaction; (2) on retry, if an intent exists, **query WebNIC for an existing pending/active order on that domain** before ever calling `register` again; (3) define exactly what happens when the API result is *unknown* (timeout) — reconcile-by-query, never blind-resubmit. Add an FR for "recover from lost-response-after-submit." Also separate "duplicate *registration*" (module's fault) from "duplicate *charge*" (Blesta invoicing) so the metric is attributable.

### C5. EPP/auth-code parity is mis-claimed and the feature flag is wrong relative to the baseline
**Location:** §6 (rows `sendEppEmail`/`updateEppCode`/`supportsEppCode` ✅), FR33, §9 (`features.epp_code` enabled in MVP), §6 parity note ("LogicBoxes' shipped `config.json` enables `dns_management` and `id_protection`. WebNIC can support **all four**…").
**Quoted:** *"`supportsEppCode` is enabled; `sendEppEmail` … and `updateEppCode` … work for outbound transfers."*
**Why it bites:** LogicBoxes `config.json` does **not** enable `epp_code`, so its `supportsEppCode()` is false and it does **not** implement `sendEppEmail`/`updateEppCode`. The auth code for an *outbound* transfer is surfaced to the admin via the **Settings tab** (`manageSettings` exposes `epp_code`/`domsecret`). The PRD both (a) lists these as parity checkmarks (they aren't — they're net-new) and (b) the parity note's "WebNIC can support all four [flags]" is fine as ambition but is dressed as parity. More dangerous: auth-code handling is the highest-risk transfer operation (lose/expose an auth code and a domain can be hijacked or a transfer stalls), and the PRD gives it one breezy FR with no handling/storage/expiry/redaction spec.
**Fix:** Move EPP send/reset to "net-new, exceeds baseline" rather than "parity." Add explicit FRs: auth codes are never logged (NFR1 covers tokens/passwords but not auth codes — add them), are not persisted in cleartext, and the reset/send flows have defined error handling and rate awareness. Keep the *Settings-tab* parity behavior (read current auth code for outbound transfer) as the actual LogicBoxes-equivalent.

---

## HIGH

### H1. Pricing currency/transform parity is overstated; baseline does live FX conversion the PRD doesn't account for
**Location:** FR7, FR8, §4.4, OQ3.
**Quoted:** FR7 *"return pricing transformed into Blesta's `[tld][currency][year#][register|transfer|renew]` structure … supporting at least the account's primary currency (USD) plus any local currency WebNIC returns."*
**Why it bites:** LogicBoxes' `getFilteredTldPricing` (logicboxes.php:2550) uses `$this->Currencies->convert(...)` to convert registrar cost into the operator's configured currencies — it does not just pass through "whatever currency the API returns." The PRD's "USD plus any local currency WebNIC returns" model ignores that Blesta operators price in *their* currencies, which may be neither USD nor WebNIC's `localPrice.myr`. OQ3 flags this as an open question, yet FR7 is written as a decided requirement with a specific (and probably wrong) currency model. This will surface as missing/empty TLD pricing for any operator whose currency WebNIC doesn't return.
**Fix:** Specify that the module returns cost in the currency/currencies WebNIC provides **and** that the module must support Blesta's currency-conversion path (mirroring LogicBoxes' `Currencies->convert`) so Domain-Manager pricing works in the operator's currencies. Resolve OQ3 before FR7 is frozen.

### H2. "Contact-handle + registrant-account model" depends on API capabilities the PRD never evidences
**Location:** §4.3, FR14, FR15, OQ4, OQ5.
**Quoted:** *"Registration requires pre-created contact handle IDs … and a `registrantUserId` … plus at least two nameserver hosts pre-registered with the registry."*
**Why it bites:** This is a strong, load-bearing assertion about WebNIC's order model, but the PRD's only source is `apidoc.webnic.dev/llms.txt` (not fetchable here) and endpoint *names*. Nothing in the cited material is quoted to confirm: that registration is *blocked* without pre-registered hosts; that contact handles are reusable across domains; whether handle reuse is per-reseller, per-client, or per-domain (OQ4 admits this is undecided); whether a registrant account is mandatory at MVP or can be defaulted (OQ5 admits this too). FR14/FR15 are written as firm requirements built on an unverified model. If WebNIC actually accepts inline contact data (like many registrars), the whole "orchestrate handles before each registration" subsystem is over-engineered; if it's stricter than assumed, the design under-delivers. Either way the MVP scope is resting on unconfirmed API shape.
**Fix:** Mark §4.3 assertions as **unverified against a quotable source** and gate FR14/FR15 on OTE confirmation during architecture. Resolve OQ4 (reuse policy) and OQ5 (registrant-account provisioning) *before* committing the contact-orchestration FRs — they directly determine table design, cleanup, and privacy exposure, so they are blockers, not deferrals.

### H3. Async register has no idempotency token evidence; "no webhooks → poll" makes lost-response recovery mandatory but unspecified
**Location:** §4.2, FR17, FR18, FR39.
**Quoted:** *"There are no webhooks/push notifications in the WebNIC API. All asynchronous completion must be discovered by polling."*
**Why it bites:** With no webhooks and an async submit, the only safe recovery from a lost HTTP response is "query WebNIC for an order on this domain." The PRD never asserts (or evidences) that WebNIC offers an idempotency key on `register`, nor that `Get Pending Order Info` can be queried *by domain* (vs only by a `pendingOrderId` you might not have persisted yet). If the only handle to a pending order is the `pendingOrderId` returned in the response you just lost, you cannot reconcile a lost-response registration at all — you're blind. This is the orphaned-registration generator.
**Fix:** Add an FR requiring a domain-keyed lookup path for in-flight/pending orders (confirm `Get Pending Order Info` or an order-search supports query-by-domain in OTE). If WebNIC has no idempotency key and no by-domain pending lookup, escalate as a Critical design risk, not a footnote.

### H4. "Basic DNS" vs "full DNS" parity boundary is fuzzy and probably under-scopes the baseline
**Location:** FR35, §6 row "`supportsDnsManagement` … ✅ (basic)", FR43.
**Quoted:** FR35 *"view and edit basic zone records … — matching the LogicBoxes basic DNS tab. (Advanced zone features are Section 2.)"*
**Why it bites:** LogicBoxes' DNS tab (`manageDnsRecords`, `getDomainRecords`, `tab_dnsrecords.pdt`) supports the record types it supports — there is no documented "basic vs advanced" line in the baseline. The PRD invents that boundary to push work to Section 2 without defining which record types are "basic." An operator expecting parity gets an arbitrary subset. Combined with C2 (forwarding/DNSSEC deferred), the MVP DNS surface is materially thinner than LogicBoxes while claiming parity.
**Fix:** Define "basic" by enumerating the exact record types and operations in FR35, and confirm they cover what LogicBoxes' DNS tab exposes. If they don't, it's a regression (see C2).

### H5. Domain Manager dependency and version are assumed, not verified
**Location:** §1.2, FR9, NFR7 ("integrates with Domain Manager `plugins/domains` v2.x"), Assumption 2.
**Quoted:** NFR7 *"integrates with Domain Manager `plugins/domains` v2.x."*
**Why it bites:** The whole pricing-import/availability/storefront story (FR6–FR9, G3) hangs on the Domain Manager plugin's contract, but the PRD never inspects `plugins/domains` to confirm the integration points (how a registrar module's `getTldPricing`/`getFilteredTldPricing`/`checkAvailability` are consumed, the expected pricing shape, the sync hooks). "v2.x" is asserted without evidence from the checkout. If the installed Domain Manager differs, FR9 silently fails. Assumption 2 even admits it's only "installed/available," not version-pinned-and-verified.
**Fix:** Verify against `plugins/domains` in this checkout: the registrar consumption contract, the exact pricing array shape it expects, and the sync entry points. Pin the real version. Make FR9 reference concrete plugin hooks, not "operate the same way as LogicBoxes."

### H6. Cancel/delete and suspend semantics are asserted without baseline confirmation, risking irreversible loss or no-op suspends
**Location:** FR25, FR26, §6 suspend row.
**Quoted:** FR26 *"`cancelService` … by default it does not delete the domain at the registry … any deletion (`Delete Domain`) is gated behind an explicit … admin choice."* FR25 maps suspend to `Suspend Domain`.
**Why it bites:** Good instinct on FR26 (don't auto-delete), but two gaps: (1) LogicBoxes' `cancelService` (logicboxes.php:596) should be checked for what it *actually* does on cancel — the PRD invents a policy without confirming the baseline's behavior, so "parity" is again unverified. (2) Domain "suspend" at a registrar is frequently a no-op or registry-specific; `Suspend Domain` existing as an endpoint doesn't mean it does what a Blesta suspend implies (block client management vs registry hold). Mapping Blesta suspend→registry suspend blindly can either do nothing useful or, worse, apply a registry hold that surprises the client. The §6 row treats this as a clean ✅.
**Fix:** Inspect `cancelService` in `logicboxes.php` and state the real baseline behavior. For suspend, define what "suspended" means for a domain service in this module (registrar hold vs local-only) and confirm `Suspend Domain` semantics in OTE before claiming parity.

---

## MEDIUM

### M1. Pervasive untestable verbs — FRs without acceptance criteria
**Location:** FR9, FR11, FR19, FR29, FR37, FR39, FR41.
**Quoted (worst offenders):**
  - FR9: *"integrates with the Domain Manager … so TLD import, availability search, and the domain storefront operate through the WebNIC module the same way they do for LogicBoxes."* — "the same way" is not testable; LogicBoxes' way isn't enumerated.
  - FR11: *"checks multiple domains efficiently … and degrades gracefully to per-domain checks on partial failure."* — "efficiently"/"gracefully" have no measurable bound.
  - FR19: *"validated using WebNIC extension/document rules … before submission"* — which rules, which fields, what failure UX?
  - FR39: *"finalises or fails the corresponding Blesta services … idempotently and without corrupting service state on transient API failures."* — "without corrupting" is not a criterion.
  - FR41: *"distinguishes retryable transport errors from terminal business errors."* — no taxonomy given.
**Why it bites:** These cannot be turned into pass/fail acceptance tests, so "MVP acceptance" (§12) is subjective. QA and the dev agent will each interpret differently.
**Fix:** Give each a concrete acceptance criterion (e.g., FR11: "bulk check of N domains issues ≤ ceil(N/batch) calls; on any batch error, the remaining domains are checked individually and partial results are returned with per-domain status"). Define the error taxonomy for FR41 once and reference it.

### M2. Token refresh "stampede" requirement names a hazard but specifies no mechanism, and contradicts NFR1's "memory/cache only"
**Location:** FR5, NFR1, NFR6.
**Quoted:** FR5 *"caches a bearer token, transparently refreshing it on expiry or on a `401` … concurrent operations on the same module row must not cause a token-refresh stampede that corrupts in-flight requests."*
**Why it bites:** Preventing a refresh stampede across concurrent PHP requests/cron workers requires shared, lockable state (a cache with locking, or a row lock) — but NFR1 says tokens are held "in memory/cache only, never persisted." In a classic PHP-FPM/CLI-cron deployment there is no shared in-process memory; "cache" must be defined (APCu? file? DB? Blesta cache?) and it must support locking, which the PRD never specifies. As written, FR5 is unimplementable without a decision NFR1 partially forecloses.
**Fix:** Specify the token cache backend and the locking/single-flight strategy, and reconcile with NFR1 (clarify that an encrypted-at-rest or short-TTL cache is acceptable, or that "no stampede" is best-effort per process). Otherwise drop the stampede clause to avoid an untestable promise.

### M3. Auto-renew model is an open question that breaks Blesta's renewal automation if unresolved
**Location:** OQ2, FR23, FR25, §6 service-lifecycle row.
**Quoted:** OQ2 *"registration has no auto-renew flag; is auto-renew managed via domain subscription endpoints, and how should it map to Blesta's renewal automation?"*
**Why it bites:** Blesta drives renewals via `renewService` on its own schedule. If WebNIC also has its own auto-renew/subscription state, you get double-renewals or conflicting expiry truth. This isn't a "nice to confirm later" — it determines FR23's correctness and the expiry-sync logic (FR40). Listed as a deferrable question, it's actually a renewal-correctness blocker.
**Fix:** Resolve OQ2 before freezing FR23/FR40. Default position: Blesta is the system of record for renewals; ensure WebNIC auto-renew is *off* (or explicitly mapped) so renewals aren't double-fired.

### M4. §11 "open questions" that are actually MVP blockers
**Location:** §11 OQ4, OQ5, OQ6, OQ8 (and OQ1).
**Quoted:** OQ6 *"what SLAs/timeouts govern reconciliation give-up/alerting?"*; OQ4 contact-handle reuse policy; OQ5 registrant-account provisioning in MVP; OQ8 deletion policy.
**Why it bites:** OQ4/OQ5 determine the data model and the entire contact-orchestration subsystem (FR14); OQ6 determines the async state machine (C3); OQ8 governs irreversible deletion (FR26). You cannot build FR14/FR17/FR26 without these. Calling them "open questions to resolve before/at architecture" is fine *only if* the PRD blocks MVP scope-freeze on them — but they're listed alongside genuinely deferrable items (OQ7 TLD coverage), flattening the risk.
**Fix:** Split §11 into "Blockers (must resolve before architecture)" — OQ2, OQ4, OQ5, OQ6, OQ8, OQ1 — and "Deferrable" — OQ3 (mostly), OQ7. Bind the blocking FRs to their resolutions.

### M5. OTE-vs-production data divergence is not addressed for pricing/TLD parity verification
**Location:** §12 acceptance ("verified against WebNIC OTE"), NFR13, Assumption 3.
**Quoted:** §12 *"Every row in the parity matrix (§6) has a working implementation verified against WebNIC OTE."*
**Why it bites:** OTE sandboxes routinely return different TLD catalogues, pricing, and rule data than production. Verifying pricing/availability/eligibility "against OTE" can pass while production behaves differently (different TLDs sellable, different rules, different `localPrice` currencies). The PRD treats OTE verification as sufficient for acceptance.
**Fix:** Add an acceptance note that catalogue/pricing/rule behaviors must be smoke-verified against production (read-only, no registration) before go-live, and that OTE proves the *integration*, not the *catalogue*.

### M6. No spec for partial-failure during multi-step registration orchestration
**Location:** FR14–FR16 (create contacts → create registrant account → ensure hosts → register).
**Why it bites:** Registration is now a multi-call saga: create/lookup contacts, create/lookup registrant account, ensure ≥2 hosts, then `register`. If host creation succeeds but `register` fails, or contacts are created then the order is rejected, you accumulate orphaned WebNIC objects (the very "orphan/duplicate handles" §4.3 warns about) with no compensation/cleanup spec. FR14 says reuse must be "deterministic" but defines no rollback for a half-built prerequisite set.
**Fix:** Add an FR for orchestration failure handling: deterministic lookup-before-create (so a retry reuses, not re-creates), and a defined stance on orphan cleanup (reuse-on-retry is usually safer than delete-on-failure). Tie to the idempotency mechanism in C4.

---

## LOW

### L1. Cron ownership left as "and/or" between module cron and Domain Manager sync
**Location:** §9, FR39. The "module `cron()` hook (and/or coordinated with the Domain Manager's domain synchronisation)" fork should be decided in the PRD's constraints, since it affects whether module-owned tables are even needed. (Architecture-deferrable, but flag it.)

### L2. NFR redaction list omits auth codes and `pendingOrderId`/order ids
**Location:** NFR1, NFR9. Redaction covers "secret/password" and tokens. EPP/auth codes (hijack risk) and arguably order identifiers should be named in the redaction policy. See C5.

### L3. `getEmailTags`/email templates parity is asserted thinly
**Location:** FR42. LogicBoxes' `config.json` `email_tags.service` is `["domain"]`; FR42 matches that, fine — but "following the LogicBoxes multi-locale convention" implies shipping multiple locales while NFR10 says only `en_us` is mandatory. Minor inconsistency; clarify whether non-`en_us` locales ship at MVP.

### L4. §12 quantitative targets are labeled "illustrative" yet appear as acceptance
**Location:** §12. "≥99% provisioning success," "within one cron cycle" reconciliation lag — fine as KPIs, but they sit next to hard acceptance criteria and one ("0 regressions") is already violated by C2. Separate aspirational KPIs from binding acceptance gates.

### L5. Restore/grace and term-validation rely on rule endpoints the baseline hard-codes
**Location:** FR13, FR24, §4.5. The PRD's "derive rules dynamically rather than hard-code" is a net-new *improvement* over LogicBoxes (which hard-codes per-TLD contact requirements in `apis/commands/logicboxes_contacts_dotca.php`, `_de`, `_uk`, `_dotxxx`, `_tel`, `_coop`). Reasonable, but presented as parity; it's actually a different (riskier, data-driven) approach whose failure mode (rule endpoint down → can't validate term) needs a fallback FR. FR13 has a fallback ("default 1–10"); FR19/FR24 don't.

---

## Summary table

| Severity | Count | IDs |
|---|---|---|
| Critical | 5 | C1–C5 |
| High | 6 | H1–H6 |
| Medium | 6 | M1–M6 |
| Low | 5 | L1–L5 |

**Bottom line:** the PRD is well-organized but its central claim — "feature parity with LogicBoxes, zero regression" — is contradicted by the baseline it cites (C1, C2, C5), and the genuinely hard part (async registration/transfer with no webhooks, idempotency, orphan avoidance) is narrated rather than specified (C3, C4, H3, M6) while several true blockers are filed as deferrable questions (M3, M4). Fix the parity matrix against the *actual* `logicboxes.php` surface, decide forwarding/DNSSEC in or out (and stop promising zero regression if they're out), and turn the async story into hard, testable FRs with a state machine, timeouts, and a concrete idempotency mechanism before architecture starts.

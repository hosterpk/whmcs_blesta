# Validation Report — WebNIC Registrar Module for Blesta

- **PRD:** `_bmad-output/webnic/planning-artifacts/research/research-prd-webnic-blesta-registrar.md`
- **Rubric:** `.claude/skills/bmad-prd/assets/prd-validation-checklist.md`
- **Run at:** 2026-06-10
- **Grade:** Fair

## Overall verdict

This is a strong, unusually well-grounded brownfield capability spec. It has a real thesis — de-risk the migration by mirroring the proven LogicBoxes module first, then layer differentiators in Sections 2–3 — and §4's API findings (token/JWT auth + IP allowlist, no webhooks → polling, contact-handle + registrant-account prerequisite model, the specific pricing shape) are load-bearing: each drives a named FR. Structure, glossary, NFRs, and personas are largely earned. The shape is correct for a single-integration registrar module against a fixed framework contract.

**Two reviewers disagreed on the single most important claim, and direct code inspection settles it against the PRD.** The rubric walker certified §6 as "verifiably accurate against the shipped `logicboxes` module"; on inspection that holds only for the *abstract* `RegistrarModule` contract and the `config.json` feature flags — not for what the concrete module actually does. The shipped `logicboxes.php` overrides almost none of the domain-centric methods §6 lists (`registerDomain`, `transferDomain`, `getDomainContacts`, `lockDomain`, `sendEppEmail`…); it does the real work in `addService()` / `renewService()` and the `tab*` methods. And it ships URL/email forwarding and DNSSEC tabs to clients *today* (`getClientServiceTabs` registers `tabClientForwarder` unconditionally and `tabClientDnssec` whenever `dns_management` is on — and it is), which the PRD defers to Section 2 while still promising "0 capability regressions vs LogicBoxes at MVP." That is a black-and-white self-contradiction.

None of this breaks the PRD — the FR substance is mostly sound and every issue is fixable from information the document already contains — but four High-severity items must be resolved before it is safe to drive architecture and epics.

## Dimension verdicts

- Decision-readiness — adequate
- Substance over theater — strong
- Strategic coherence — strong
- Done-ness clarity — thin
- Scope honesty — adequate
- Downstream usability — adequate
- Shape fit — strong

## Findings by severity

### Critical (0)

None. (The adversarial pass filed 5 Criticals; on verification the two factual ones were upheld but down-calibrated to High because the FR substance survives and every fix is self-contained, and the others are WHAT-level High findings rather than fatal flaws.)

### High (4)

**[Decision-readiness] — Parity matrix & Goal G1 measure against the abstract contract, not the shipped module** (§6; G1)
Verified: `logicboxes.php` overrides only `checkAvailability`, `getRegistrationDate`/`getExpirationDate`, `getServiceDomain`, `getTlds`/`getTldPricing`/`getFilteredTldPricing`, `getDomainNameServers`/`setDomainNameservers`, plus `addService`/`renewService`/`suspendService`/`unsuspendService`/`cancelService`/`changeServicePackage` and the `tab*` methods. The matrix rows for `registerDomain`, `transferDomain`, `getDomainContacts`, `lockDomain`, `sendEppEmail`, `updateEppCode`, etc. are abstract-base defaults LogicBoxes never overrides — it performs that work inside `addService`/`renewService` and the tabs. G1's "every LogicBoxes registrar method has a working equivalent" is unverifiable as written.
Fix: Re-derive §6 from the module's actual overridden surface; for each row state whether the behavior lives in `addService`, in a discrete method, or is a net-new upgrade. Reword G1 to reference observable end-to-end flows. (Building WebNIC on the modern domain-centric contract is a fine *improvement* — just stop labeling it "LogicBoxes behaviour.")

**[Strategic coherence] — MVP drops capabilities LogicBoxes ships today, contradicting the "0 regressions" metric** (§7.2 FR45/FR46; §6; §12)
Verified: `getClientServiceTabs` registers `tabClientForwarder` (URL + email forwarding) unconditionally, and `tabClientDnssec` + `tabClientDnsRecords` whenever `dns_management` is enabled — `config.json` sets `dns_management: 1`. So both are in LogicBoxes' default client experience, yet the PRD defers forwarding (FR45) and DNSSEC (FR46) to Section 2 while §12 targets "Capability regressions vs LogicBoxes at MVP: 0." A migrator on the MVP loses both tabs. The WebNIC endpoints exist, so this is scoping, not capability.
Fix: Pull forwarding + DNSSEC into Section 1, OR keep them in Section 2 but delete the "0 regressions" claim and list them as accepted MVP regressions with operator sign-off. Not both.

**[Done-ness] — Net-new async pending-order/reconciliation subsystem lacks failure-path acceptance criteria** (§4.2; FR17/FR21/FR39; OQ6)
Verified: LogicBoxes is fully synchronous — no `cron()`, no pending table, no polling — so this stateful subsystem is net-new, yet §6 checks it off as "parity." FR39's "finalises or fails" never defines the timeout/give-up, the stuck-pending customer-facing state, whether Blesta keeps invoicing a pending domain, or cron ownership. OQ6 admits the timeout is undecided and ships it as a checked parity item anyway.
Fix: Name it net-new; add FRs for the service state machine + transitions, poll cadence/max-attempts/backoff/hard-timeout, terminal give-up + alert, client presentation of a long-pending domain, renew/cancel-while-pending policy, and concrete persistence. Promote OQ6 to a blocker.

**[Done-ness] — Idempotency / lost-response recovery is asserted, not specified — the irreversible-money path** (FR18; NFR4; G2; with H3)
FR18's "durable local state + uniqueness" names no dedup key and no "check pending order by domain before re-submitting" step. With async register and no webhooks, a lost HTTP response after submit → a second irreversible registry order. The PRD also never evidences that `Get Pending Order Info` can be queried by domain (vs by a `pendingOrderId` you may have lost).
Fix: Add a "recover from lost-response-after-submit" FR — write a registration-intent row before the call; on retry query WebNIC by domain before re-calling `register`; reconcile-by-query on unknown results, never blind-resubmit. Confirm by-domain lookup in OTE (escalate to Critical if absent). Separate "duplicate registration" (module) from "duplicate charge" (Blesta).

### Medium (8)

**[Done-ness] — Adjective-bound FRs without measurable acceptance criteria** (FR4/FR9/FR11/FR19/FR29/FR41; NFR6) — "efficiently," "gracefully," "clear," "actionable," "the same way," "expected latency." Fix: replace each with a bound/observable; define the FR41 error taxonomy once.

**[Done-ness] — Auth/EPP codes absent from the redaction policy** (NFR1; NFR9; FR33) — hijack-grade secrets not named alongside passwords/tokens; FR33 send/reset flows lack storage/expiry/redaction spec. Fix: add EPP/auth codes (and order IDs) to redaction; never log, never persist cleartext.

**[Done-ness] — Quantitative success metrics self-labeled "illustrative"** (§12) — undercuts the MVP acceptance bar. Fix: commit them as exit criteria, or move to a clearly separate post-launch monitoring goal.

**[Scope honesty] — Contact-handle / registrant-account model is load-bearing but unevidenced** (§4.3; FR14/FR15; OQ4/OQ5) — asserted from endpoint names only; reuse scope and registrant-account necessity undecided yet FR14/FR15 are firm. Fix: mark §4.3 unverified; gate FR14/FR15 on OTE; resolve OQ4/OQ5 before freezing.

**[Scope honesty] — Missing the BMAD inline assumption/non-goal/note tags** (whole doc; §11) — no inline-to-index roundtrip for tooling. Fix: tag the strongest inferences inline (e.g. `[ASSUMPTION: Domain Manager v2.x present]` at FR9), keep §11 as the index.

**[Downstream] — Domain Manager contract and version assumed, not verified** (FR9; NFR7; Assumption 2) — pricing/availability/storefront hang on `plugins/domains` but it's never inspected; "v2.x" unevidenced. Fix: inspect `plugins/domains`, pin the version, reference concrete hooks + pricing shape.

**[Downstream] — "Child nameserver / glue record / host object" used as undefined synonyms** (FR15/FR31; §6) — Fix: add a glossary entry mapping WebNIC "host" to Blesta child-NS/glue; use one term.

**[Adversarial, verified] — Pricing currency model ignores Blesta's conversion path** (FR7/FR8; §4.4; OQ3) — LogicBoxes' `getFilteredTldPricing` uses `Currencies->convert(...)`; "USD plus whatever WebNIC returns" yields empty pricing for operators in other currencies. Fix: require Blesta's currency-conversion path; resolve OQ3 before freezing FR7.

> Plus, from the adversarial pass (verified or plausible, carried at Medium/Low in the report): token-refresh stampede vs NFR1 "cache only" (FR5/NFR1); multi-step registration saga lacks orphan-cleanup (FR14–FR16); EPP parity mis-claimed as a C1 instance (§6/FR33); "basic vs full DNS" boundary undefined (FR35); suspend/cancel semantics unverified (FR25/FR26); auto-renew conflict risk (OQ2/FR23/FR40).

### Low (3)

**[Strategic coherence] — No counter-metrics paired with success metrics** (§12) — a ≥99% success push could be gamed by retries. Fix: add a guardrail metric (cron runtime/API-call ceiling, or wrong-terminal-state rate).

**[Downstream] — "ID protection" vs "WHOIS privacy" vs "proxy" not disambiguated** (§1.4; FR16/FR34/FR48) — Fix: glossary line distinguishing privacy (redaction) from proxy (substitution) and the `id_protection` mapping.

**[Decision-readiness] — OQ8 duplicates a decision FR26 already makes** (§11 OQ8 vs FR26) — Fix: promote FR26's stance to a stated decision; reduce OQ8 to the residual UI-placement question.

## Mechanical notes

- **Adjudication of the reviewer conflict.** The rubric walker's "§6 verified accurate against the shipped logicboxes module" holds only for the abstract `RegistrarModule` contract and the `config.json` flags. Direct inspection of `components/modules/logicboxes/logicboxes.php` (`getClientServiceTabs` ~1273, `addService` 185, no `cron()` method) is the basis for the C1/C2/C3 High findings.
- **Dual FR/section numbering** — FRs flat (FR1–FR59), sections decimal (§7.1–§7.3); no gaps/dupes in FR1–FR59 or NFR1–NFR14. Key on flat FR IDs.
- **Two overlapping "out of scope" sections** (§1.3 and §10) plus §2.3 — consolidate to one canonical Non-Goals section.
- **§6 endpoint names are descriptive labels, not literal API paths** — OQ1 acknowledges schemas need OTE confirmation; don't treat the label column as canonical endpoint IDs.
- **Cross-references resolve**, and the cited `apidoc.webnic.dev/llms.txt` source + `components/modules/logicboxes/` baseline both exist in this checkout.

## Reviewer files

- `review-rubric.md` — primary rubric walk (graded the structure/substance strong; missed the parity-accuracy facts)
- `review-adversarial-general.md` — adversarial pass (22 findings; its two flagship claims verified and upheld)

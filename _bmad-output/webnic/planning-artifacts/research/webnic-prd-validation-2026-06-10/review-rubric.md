# PRD Quality Review — WebNIC Registrar Module for Blesta

## Overall verdict

This is a strong, unusually well-grounded brownfield capability spec: it has a real thesis (de-risk migration by mirroring the proven LogicBoxes module first, then layer differentiators), and §4's API findings and §6's parity matrix are load-bearing and verifiably accurate against the actual `RegistrarModule` contract and shipped `logicboxes` module. What's at risk is **done-ness**: a meaningful minority of FRs carry adjectives ("gracefully," "efficiently," "clearly," "actionable") that an engineer cannot test against, and the quantitative Success Metrics are self-labelled "illustrative," so the PRD's own acceptance bar is softer than its parity claims. Secondary risk is downstream traceability hygiene — no Glossary-anchored discipline across FRs, a dual FR numbering scheme, and the BMAD `[ASSUMPTION]`/`[NOTE FOR PM]` inline tagging convention is absent — but these are mechanical rather than substantive.

## Decision-readiness — strong

A decision-maker can act on this. The release-section split (§1.1, §5) is stated as a decision, not floated as a consideration, and each section is asserted independently shippable with the MVP in production — a real architectural bet (G4: "Section 2/3 features are additive; no MVP contract changes required"). The Open Questions (§11, OQ1–OQ8) are genuinely open and consequential, not rhetorical: OQ4 (contact-handle reuse: shared reseller vs per-client vs per-domain) and OQ5 (registrant-account provisioning in MVP vs deferred to Section 2) are exactly the kind of unresolved tension that should block architecture, and they are surfaced rather than smoothed. OQ2 (auto-renew: "registration has no auto-renew flag") honestly admits a gap between the WebNIC API and Blesta's renewal automation.

Where it is thinner than strong: trade-offs are mostly named as open questions to be resolved later rather than as decisions-with-costs taken now. FR26 (cancelService does not delete at registry by default) is a good example of a decision *with* its rationale ("to avoid irreversible loss") — but it is one of the few. The PRD would be more decision-ready if a couple of the OQs that are effectively already leaning one way (e.g. OQ8 deletion policy, which FR26 has already half-decided) were promoted to stated decisions with the alternative explicitly rejected.

### Findings
- **low** OQ8 duplicates a decision FR26 already makes (§11 OQ8 vs §7.1 FR26) — FR26 states the default ("does not delete the domain at the registry") and gates deletion behind an explicit admin choice, yet OQ8 re-opens "confirm default of 'do not delete at registry' and where the explicit deletion control lives." *Fix:* promote FR26's stance to a stated decision and reduce OQ8 to the residual UI-placement question only.

## Substance over theater — strong

The content is earned. §4 (Integration Constraints & Key Technical Findings) is the spine of the PRD and is real: token-based JWT auth with ~60-min validity and IP-allowlist prerequisite (§4.1), the absence of webhooks forcing cron polling (§4.2, "There are no webhooks/push notifications in the WebNIC API"), the contact-handle + registrant-account prerequisite model (§4.3), and the specific pricing shape with `renewal→renew` mapping (§4.4) each drive named FRs (FR5, FR39, FR14, FR7 respectively). This is the opposite of furniture — every finding carries an explicit "Implication (requirements)" line.

No innovation theater: the PRD is candid that the MVP is deliberately *un*-novel ("intentionally mirrors the proven LogicBoxes module," §2.1) and reserves differentiation for Sections 2–3, which is honest framing rather than manufactured novelty.

Personas (§3) are lean (four) and each maps to needs that surface in requirements — the "WebNIC reseller account" persona, for instance, is what makes FR53 (balance monitoring) and NFR14 (IP allowlist) legible. No persona is decorative.

NFRs (§8) largely avoid boilerplate: NFR1 ties to a project-specific mechanism ("Blesta encrypted module-row fields"), NFR4/NFR5 give concrete idempotency/async semantics, NFR7 pins PHP 8.2 and "no 8.3+ syntax." A few edge toward generic (NFR6 "stay within Blesta's expected latency" has no number), noted under Done-ness.

## Strategic coherence — strong

The PRD has a clear thesis and the structure follows from it. Thesis: a Blesta operator should be able to switch a domain reseller business to WebNIC "with no loss of capability" (§1.1), so the MVP is scoped as *parity* and differentiation is explicitly sequenced behind it. Prioritization follows the thesis rather than ease — the parity matrix (§6) is literally "the definition of 'feature parity' for Section 1," and Sections 2/3 are ordered by value tier (end-user value, then reseller/operator power), not by implementation convenience. The MVP scope kind is coherently a problem-solving/platform-parity play, and the scope logic matches.

Success Metrics (§12) are well-targeted to the thesis: "Capability regressions vs LogicBoxes at MVP = 0" directly validates the parity bet, and "Duplicate registrations/charges = 0" validates the payment-safety thesis (G2). These are outcome metrics, not activity vanity metrics — there is no DAU/MAU tell here. The one missing piece is counter-metrics: the ≥99% provisioning success and "within one cron cycle" reconciliation targets have no paired guardrail (e.g. a cap on reconciliation cron runtime / API call volume, or a false-active-rate counter).

### Findings
- **low** No counter-metrics paired with SMs (§12 quantitative targets) — e.g. a ≥99% provisioning-success push could be gamed by aggressive retries that inflate API load or risk double-submission; nothing bounds that. *Fix:* add a counter-metric such as "reconciliation cron stays within N minutes / M API calls per cycle" or "0 services flipped to a wrong terminal state on transient failure" (the latter already exists as a quality bar in NFR5 — surface it as a metric).

## Done-ness clarity — thin

This is the weakest dimension and the one story creation will lean on hardest. Many FRs are testable and concrete — FR2 (named env toggle with specific base URLs `oteapi.webnic.cc` vs `api.webnic.cc`), FR7 (exact target structure `[tld][currency][year#][register|transfer|renew]` and `renewal→renew` mapping), FR17 (explicit pending vs immediate branching on `pendingOrder` boolean with `dtexpire` recorded), FR18/FR4 (no second order, no charge, "without registering or charging anything") all have a clear pass/fail. The §12 MVP acceptance checklist also does real done-ness work.

But a recurring set of FRs lean on untestable adjectives:

- FR11 "checks multiple domains **efficiently** … and **degrades gracefully** to per-domain checks on partial failure" — "efficiently" is unbounded; only the fallback behaviour is testable.
- FR4 / FR29 / FR41 "**clear** pass/fail," "**clear** error reporting," "**actionable** validation errors" — no definition of clear/actionable.
- FR5 concurrency clause ("must not cause a token-refresh stampede that corrupts in-flight requests") names the right hazard but gives no observable acceptance condition.
- FR39 / NFR5 "**bounded** retries/backoff" with no bound stated; FR39 "without corrupting service state on transient API failures" is a property without a test.
- NFR6 "**time-bounded** so cron and storefront searches stay within Blesta's **expected** latency" — no number, and "expected" is undefined.

Compounding this, §12's quantitative table is explicitly labelled "(post-launch, **illustrative**)," which tells the engineer the numbers are not binding acceptance criteria — so the PRD's hard acceptance reduces to the qualitative §12 bullet list plus the parity matrix. For a green-light-to-build parity spec, the async/idempotency FRs (FR17, FR18, FR39, NFR4, NFR5) are where bugs cost real money, and those are exactly the FRs most reliant on un-bounded language.

### Findings
- **high** Async/idempotency FRs state properties without testable acceptance conditions (§7.1 FR18, FR39; §8 NFR4, NFR5) — "idempotent," "payment-safe," "bounded retries/backoff," "never corrupts service state on transient failures" are the load-bearing safety guarantees of the whole product but none names a verifiable condition (e.g. what dedup key, what retry ceiling, what observable post-condition after a simulated transient 500). Story creation will have to invent the acceptance bar. *Fix:* add explicit acceptance criteria — e.g. "a duplicate `addService` for the same service_id reuses the stored `pendingOrderId` and issues zero new `Register Domain` calls"; "reconciliation retries at most N times with backoff B before marking the service `error` and alerting."
- **medium** Adjective-bound FRs not pinned to measurable outcomes (§7.1 FR4, FR11, FR29, FR41; §8 NFR6) — "efficiently," "clear," "actionable," "gracefully," "expected latency" recur. Per rubric §4 these should each be flagged. *Fix:* replace with bounds/observables (e.g. FR11: "≤ K API round-trips for N domains; on partial failure, each failed domain retried individually and reported with its own status"; NFR6: state a latency/round-trip budget for storefront availability).
- **medium** Quantitative Success Metrics self-labelled "illustrative" (§12) — marking the only numeric targets as non-binding undercuts the PRD's acceptance bar for an MVP that is otherwise pitched as a precise parity contract. *Fix:* either commit the numbers as MVP exit criteria or move them to a clearly separate "post-launch monitoring" goal so the MVP acceptance set is unambiguous.

## Scope honesty — strong

Omissions are explicit and done openly, not silently. There are two distinct out-of-scope statements — §1.3 ("What this product is not") and §10 ("Out of Scope") — plus §2.3 Non-goals, and deferrals are repeatedly and specifically called out: SSL/brokerage/secondhand "explicitly deferred to Section 3 even though the WebNIC API exposes them" (§1.3), email-forwarding marked "➕ Section 2" in the parity matrix (§6), and the basic-vs-full DNS split flagged inline (FR35 "Advanced zone features are Section 2"). The de-scoping is honest: §6's parity note openly states WebNIC can *exceed* LogicBoxes, and the PRD still holds the MVP to parity rather than scope-creeping.

The §11 Assumptions block names the genuinely load-bearing inferences (active reseller account + IP allowlist; Domain Manager installed; endpoints behave per published docs pending OTE confirmation; PHP 8.2 conventions). Open-items density (8 OQs + 4 assumptions) is appropriate for a draft feeding architecture, not excessive.

The one real gap is convention rather than content: the PRD does not use the BMAD inline `[ASSUMPTION: …]` / `[NON-GOAL for MVP]` / `[NOTE FOR PM]` callout tags that the rubric (§5, §6) and the Assumptions-Index roundtrip check expect. It uses a prose Assumptions section and table-cell markers instead. The information is present; the machine-extractable tagging is not — which matters for downstream tooling more than for a human reader.

### Findings
- **medium** No inline `[ASSUMPTION:]` / `[NON-GOAL for MVP]` / `[NOTE FOR PM]` tags (whole doc; cf. §11) — assumptions live only in a prose list and scope deferrals only in table cells / sentence fragments, so there is no inline-to-index roundtrip and downstream tooling can't source-extract them. The substance is honest; the tagging discipline the rubric expects is missing. *Fix:* tag the strongest inferences inline where they bite (e.g. `[ASSUMPTION: Domain Manager v2.x present]` at FR9; `[NOTE FOR PM]` at FR14's handle-reuse choice, which is OQ4 unresolved) and keep §11 as the index.

## Downstream usability — adequate

This PRD is chain-top (it explicitly feeds architecture — §9 is a "Proposed Architecture Shape" — and story creation), so traceability matters. It does several things well: the parity matrix (§6) gives architecture a clean method-by-method extraction surface, §9 pre-sketches the file layout, FR IDs are unique and the section→FR mapping is legible, and most domain nouns ("module row," "contact handle," "registrant account," "pending order") are defined in §1.4 and used consistently.

Weaknesses are real but mechanical. (1) FR numbering uses two schemes — section headings are decimal (§7.1, §7.2, §7.3) while FRs are flat (FR1…FR59), and §1's purpose list plus §6's matrix reference "see parity matrix §6" / "see §6" cross-refs that resolve but rely on section numbers rather than Glossary terms. (2) There are **no User Journeys** at all. For a capability-spec/single-integration shape this is defensible (see Shape fit), but the rubric's UJ-protagonist checks are simply N/A here — worth stating so a downstream reader doesn't treat it as an omission. (3) A few domain nouns drift slightly outside the Glossary: "child nameservers / glue records / host objects" are used interchangeably (FR15, FR31, §6) without a single canonical Glossary entry, and "ID protection" vs "WHOIS privacy" vs "proxy/privacy" are used as near-synonyms (FR16, FR34, FR48) — defensible because WebNIC distinguishes proxy from privacy, but the distinction isn't pinned in §1.4.

### Findings
- **medium** "Child nameserver / glue record / host object" used as undefined synonyms (§7.1 FR15, FR31; §6 rows) — three terms for overlapping concepts with no Glossary anchor; architecture must guess whether host objects and glue are one mechanism. *Fix:* add a Glossary entry mapping the WebNIC "host" object to Blesta's "child nameserver / glue" concept and use one term consistently.
- **low** "ID protection" vs "WHOIS privacy" vs "proxy" not disambiguated in Glossary (§1.4; used FR16, FR34, FR48) — the PRD treats them as near-synonyms in MVP but FR48 (Section 2) splits "proxy subscription" from "WHOIS privacy"; the relationship isn't defined up front. *Fix:* add a Glossary line stating privacy (WHOIS redaction) vs proxy (registrant substitution) and which maps to Blesta's `id_protection` flag.

## Shape fit — strong

The PRD is correctly shaped. This is a brownfield, single-integration registrar module against a fixed framework contract, so a **capability-spec** shape (parity matrix + FR list + technical-constraint findings) is exactly right, and the absence of User Journeys is appropriate, not a defect — there is no multi-stakeholder UX arc to model; the "journey" is the registrar contract itself. The brownfield obligations the rubric calls out are met and *accurate*: the existing-code references check out — `RegistrarModule` is the right abstract parent and exposes the cited methods (`registerDomain`, `transferDomain`, `setNameserverIps`, `supportsEppCode`, `getRegistrationDate`/`getExpirationDate`), the LogicBoxes `apis/commands/` layout that §9/NFR12 propose mirroring exists, and the §6 parity note's claim that LogicBoxes' shipped `config.json` enables `dns_management` + `id_protection` is literally correct (it does not enable `epp_code` as a flag, and the PRD does not claim it does — `supportsEppCode()` is a code-level override, which the PRD reflects). New vs existing is cleanly distinguished: the WebNIC module is new, the contract and Domain Manager integration are existing, and §9 is explicitly labelled "non-binding guidance" so it doesn't over-formalize architecture decisions the architect should own.

The PRD is neither over-formalized (no gratuitous UJs for a single-operator integration) nor under-formalized (the consumer-facing client area surface is captured via FRs + service-tab requirements rather than forced into journeys). No findings.

## Mechanical notes

- **Dual FR/section numbering.** FRs are flat (`FR1`…`FR59`) but their parent sections are decimal (`§7.1`/`§7.2`/`§7.3`). Harmless but mixed; downstream extractors should key on the flat FR IDs. No gaps or duplicates found in FR1–FR59 or NFR1–NFR14.
- **Assumptions Index roundtrip — N/A by convention.** No inline `[ASSUMPTION]` tags exist, so there is nothing to roundtrip against §11; flagged substantively under Scope honesty. If the BMAD tagging convention is required, this is a structural gap.
- **Two overlapping "out of scope" sections** (§1.3 and §10) plus §2.3 Non-goals — content is consistent across all three but the duplication invites future drift; consider consolidating §10 into a single canonical Non-Goals section and letting §1.3 cross-reference it.
- **Cross-references resolve.** "see parity matrix §6," "§6," "Section 2/3," and the §9↔§6 method mapping all point to real targets. The `apidoc.webnic.dev/llms.txt` source and `components/modules/logicboxes/` baseline cited in Appendix B both exist in this checkout.
- **Glossary present (§1.4) and mostly applied;** drift items (host/glue/child-NS; privacy/proxy/id-protection) noted under Downstream usability.
- **Parity-matrix WebNIC endpoint names are descriptive labels, not literal API paths** (e.g. "Query Domain," "Register Domain") except where a path is given (`/domain/v2/exts/pricing`, `/domain/v2/register`). OQ1 already flags that exact response schemas need OTE confirmation, so this is acknowledged — but architecture should not treat the label column of §6 as canonical endpoint identifiers.

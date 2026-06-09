---
title: Sprint Change Proposal - KuickPay Phase 0 Gate & Voucher-Issuance Sequencing
date: 2026-06-09
project: whmcs_blesta
author: Israr (via BMAD Correct Course)
trigger: implementation-readiness-report-2026-06-09.md (2 Major findings)
scope: Moderate (additive backlog correction; no PRD/architecture/UX conflict)
mode: Batch (YOLO)
status: applied
---

# Sprint Change Proposal: KuickPay Phase 0 Gate & Voucher-Issuance Sequencing

## Section 1 — Issue Summary

The Implementation Readiness check (`implementation-readiness-report-2026-06-09.md`) passed the KuickPay/Blesta planning set as **READY** but surfaced **two Major sequencing conditions** that must be resolved before the team reaches Epic 2 Story 2.3 and Epic 3:

1. **Hidden cross-epic dependency.** Voucher *issuance* (Epic 2, Story 2.3 — FR8/FR10) cannot be implemented without the SOAP client (Epic 3, Story 3.1 — FR15) and the normalized parser + fixtures (Epic 3, Story 3.2 — FR16/FR17). FR10's own text posts a failed/unknown response "according to parser rules." Both the PRD §10 rollout and the architecture implementation sequence place client + parser **before** voucher generation — the opposite of the Epic 2 → Epic 3 numbering. A team executing strict E1→E2→E3 order would stall at Story 2.3.

2. **Phase 0 has no owning story.** The architecture's Phase 0 release gate (confirm production Blesta version, endpoint/WSDL, date formats, Consumer Number formula, credential separation, rate limits; capture sanitized fixtures) and PRD Open Question #2 (exact response formats) are hard prerequisites for trustworthy posting — but the work was only *consumed* (Story 3.2) and *referenced* (Story 5.4), never owned. The external KuickPay dependency was invisible in the backlog.

Discovered during the readiness review; evidence is the FR→story trace plus the PRD/architecture sequencing statements cited above.

## Section 2 — Impact Analysis

- **Epic Impact:** Additive new **Epic 0 (Phase 0 gate)**. Epics 1–5 unchanged in scope/numbering. Epic 1 remains fully unblocked and may run in parallel with Phase 0.
- **Story Impact:** New **Story 0.1**. Annotations added to **Story 2.3** (dependency), **Story 3.1** and **Story 3.2** (sequencing notes). No acceptance criteria of existing stories were altered.
- **Artifact Conflicts:** None. The change *aligns* `epics.md` with the existing `architecture.md` (Phase 0 gate, implementation sequence) and `prd.md` (§10 rollout, Open Q#2). No PRD/architecture/UX edits required.
- **Technical Impact:** Build-order/sequencing only. No code, schema, or infrastructure implications introduced by this proposal itself.

## Section 3 — Recommended Approach

**Direct Adjustment** — modify/add stories within the existing plan. No rollback or MVP-scope reduction needed.

- **Effort:** Low (documentation/backlog edits).
- **Risk:** Low. Reduces execution risk by making the external KuickPay dependency explicit and preventing a mid-sprint stall at Story 2.3.
- **Timeline:** Neutral-to-positive. Phase 0 can overlap Epic 1; surfacing it now prevents later rework.

## Section 4 — Detailed Change Proposals (applied to `epics.md`)

**Change 1 — Epic List: add Epic 0 entry (before Epic 1)**
> NEW: "### Epic 0: Phase 0 - KuickPay Contract Validation and Fixture Gate … **FRs covered:** Prerequisite gate for FR8, FR10, FR16, FR17 (introduces no new FR); supports FR29."
> Rationale: Gives Phase 0 a named home and states it gates Story 2.3 + Epic 3.

**Change 2 — New Epic 0 body + Story 0.1 (before "## Epic 1")**
> NEW: Full "## Epic 0 …" section with sequencing callout and **Story 0.1: Confirm KuickPay Contract and Capture Sanitized Fixtures**, four Given/When/Then ACs covering: (a) contract confirmation (Blesta version, WSDL, date formats, Consumer Number formula, credential separation, no hard-coding), (b) sanitized fixture capture across InsertVoucher/Inquiry/Bulk cases, (c) rate-limit/polling guidance or conservative default, (d) posting stays disabled and unknown ≠ paid until approved.
> Rationale: Owns Major #2; mirrors the architecture's minimum fixture gate.

**Change 3 — Story 2.3: add "Dependencies (sequencing)" note**
> NEW: Note that Story 2.3 must not begin until Story 0.1 approved, Story 3.1 `InsertVoucher` path exists, and Story 3.2 creation-response cases exist; clarifies Epic numbering ≠ build order (cites FR10/FR15).
> Rationale: Owns Major #1 at the point of consumption.

**Change 4 — Story 3.1: add "Sequencing note"**
> NEW: `InsertVoucher` operation is a prerequisite of Story 2.3; deliver it early; inquiry/bulk may follow.

**Change 5 — Story 3.2: add "Sequencing note"**
> NEW: Creation-response parsing + fixtures are a prerequisite of Story 2.3 and depend on Story 0.1; deliver early; inquiry/bulk parsing may follow.

## Section 5 — Implementation Handoff

- **Scope classification:** **Moderate** — backlog reorganization (new gate story + sequencing), no fundamental replan.
- **Routing:** Product Owner / Dev — fold the corrected order into **Sprint Planning** (`bmad-sprint-planning`). Sprint planning must schedule: Phase 0 (Story 0.1) + the `InsertVoucher` slice of Story 3.1 + the creation-response slice of Story 3.2 **before** Story 2.3; Epic 1 in parallel.
- **Success criteria:** Sprint plan shows Story 2.3 sequenced after its three prerequisites; Phase 0 gate appears as an explicit, ownable item; payment posting flagged disabled until Phase 0 approved.
- **Residual minor items (from readiness report, not blocking):** UX badge tokens for `retry`/`confirmed_unposted`/`cancelled` (+ rename `paid`→`posted`); consider splitting Story 2.3 failure-handling; confirm Story 3.6 dual-FR ACs are independently testable; clarify Story 2.1 schema-timing wording vs. plugin-install ownership; track deferred WHMCS reference cutover.

---

*Correct Course workflow — applied 2026-06-09 for Israr. Artifacts modified: `epics.md` (Epic 0 + Story 0.1 added; Stories 2.3/3.1/3.2 annotated). Next: `bmad-sprint-planning`.*

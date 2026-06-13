---
stepsCompleted: [1, 2, 3, 4]
inputDocuments: ['_bmad-output/project-context.md']
workflowType: 'research'
lastStep: 4
research_type: 'technical'
research_topic: 'Blesta Client Template Modernization (dashboard-redesign)'
research_goals: 'De-risk technical feasibility of modernizing the Blesta client-area template UI/UX and select an approach (re-skin vs progressive enhancement vs headless SPA), grounded in the actual codebase and Blesta theming mechanics.'
user_name: 'Israr'
date: '2026-06-13'
web_research_enabled: true
source_verification: true
---

# Research Report: technical

**Date:** 2026-06-13
**Author:** Israr
**Research Type:** technical
**Product/Project:** dashboard-redesign

---

## Research Overview

This report investigates the technical feasibility of modernizing the **Blesta client-area template** (the client dashboard / "My Account" experience) and selects an implementation approach for the **dashboard-redesign** project. It is grounded in the actual codebase at `/home/hosterpk/beta.hosterpk.com` (Blesta `6.0.0-b1`, PHP 8.3 runtime) and cross-checked against current public Blesta documentation and front-end engineering sources.

**Codebase facts treated as ground truth (already gathered):**

- Active client template lives at `app/views/client/bootstrap/` — Blesta's default template directory, **76** `.pdt` server-rendered PHP files; `structure.pdt` is the master `<html>` shell.
- Front-end stack is Bootstrap 3-era: ships `html5shiv.js`, `respond.min.js`, `PIE.htc`, `jquery-migrate` (IE8/9 compatibility shims), jQuery, Font Awesome, `application.min.css`, and dynamic `$theme_css` injection.
- Business logic lives in `app/controllers/client_*.php`; `.pdt` views mostly render controller-provided variables — restyling is viable **only if the view-variable contract is preserved**.
- Core `bootstrap/` is overwritten on Blesta update → any new template must be a **parallel directory** (upgrade-safety constraint).
- `project-context.md` rules in force: *"Use existing `.pdt` template conventions. Do not introduce Twig, Blade, or a second view engine"* and *".pdt views should stay thin."*

**Methodology:** current web sources with multi-source verification for recommendation-driving claims; confidence levels flagged where sources are thin (6.0.0-b1 is a beta line, so some docs may lag).

---

## Technical Research Scope Confirmation

**Research Topic:** Blesta Client Template Modernization (dashboard-redesign)
**Research Goals:** De-risk technical feasibility of modernizing the Blesta client-area template UI/UX and select an approach (re-skin vs progressive enhancement vs headless SPA), grounded in the actual codebase and Blesta theming mechanics.

**Technical Research Scope:**

- Architecture Analysis — `.pdt` view system, `structure.pdt` layout, template selection/registration, upgrade-safety, Blesta 6.x version landscape
- Implementation Approaches — (A) new template dir / Bootstrap 5 re-skin, (B) progressive enhancement (Tailwind + Alpine.js/HTMX islands), (C) headless/SPA on Blesta API
- Technology Stack — current vs. target front-end stacks; what each approach adds to the runtime
- Integration Patterns — controller→view variable contract; plugin/gateway/order-form own-view rendering; theme CSS injection; Blesta API surface
- Performance / Maintainability — upgrade durability, feature-coverage loss, localization/RTL, long-term maintenance cost

**Research Methodology:**

- Current web data with rigorous source verification
- Multi-source validation for critical technical claims
- Confidence level framework for uncertain information
- Comprehensive technical coverage weighted against documented project constraints

**Scope Confirmed:** 2026-06-13

---

## Technology Stack Analysis

> Two findings below revise the project's starting assumptions and should be read first.
>
> **Revision 1 — the current template is Bootstrap 4.6, not Bootstrap 3.** On-disk `app/views/client/bootstrap/config.json` declares `"version":"2.0.0"`, `"description":"Built on Bootstrap 4.6."`, `"require":{"blesta":">=5.0.0"}`. The `html5shiv.js` / `respond.min.js` / `PIE.htc` / `jquery-migrate` files are **leftover IE8/9 shims**, not evidence of a Bootstrap-3 grid. The migration target is therefore **BS4.6 → BS5**, a smaller delta than BS3 → BS5. _(Confidence: High — install `config.json`.)_
>
> **Revision 2 — Blesta 6.0's UI overhaul is the ADMIN area, not the client area.** Blesta 6.0's marquee feature ("Paradigm") is a ground-up **admin** interface on Bootstrap 5; the client area was only *incrementally* touched (a refreshed profile sidebar, a few updated `.pdt` files). Upgrading Blesta will **not** hand you a modern client dashboard — that remains a build-it-yourself effort. _(Confidence: High — [blesta.com/2025/12/11/blesta-6-admin-ui-paradigm-shift](https://www.blesta.com/2025/12/11/blesta-6-admin-ui-paradigm-shift/), [blesta.com/2026/05/21/blesta-6.0-beta-released](https://www.blesta.com/2026/05/21/blesta-6.0-beta-released/).)_

### Current Client-Area Stack (as-is, on disk)

| Layer | What ships today | Note |
|---|---|---|
| View engine | minPHP `View` class rendering `.pdt` files (raw PHP + `include` + `ob_start`/`extract`) | `vendors/minphp/bridge/src/Lib/View.php`; `view_ext='.pdt'` |
| CSS framework | **Bootstrap 4.6** (`application.min.css`) + Font Awesome + `theme.css` + `rtl.css` | template `config.json` v2.0.0 |
| JS | jQuery + `jquery-migrate`; **dead IE8/9 shims** (`html5shiv`, `respond.js`, `PIE.htc`) | shims removable with zero modern-browser impact |
| Theming | dynamic `$theme_css` (+ `$theme_hash` cache-buster) injected in `structure.pdt`, after `application.min.css` | admin-configurable colors/logos, separate from template |
| Master layout | `structure.pdt` (the `<html>` chrome; renders `$nav`, `$content`, `$page_title`) | 75–76 `.pdt` files total |

_Source: on-disk inspection + [docs.blesta.com/guides/templates](https://docs.blesta.com/guides/templates/). Confidence: High._

### Blesta Version Landscape

- **Latest stable: 5.13.9** (Jun 8, 2026); the 5.13 line is current production. _Source: [blesta.com/categories/news](https://www.blesta.com/categories/news/)._
- **This install runs 6.0.0-b1 = Blesta 6.0 Beta 1** (released May 21, 2026; Beta 2 on Jun 11, 2026). It is flagged **"for non-production use only and is unsupported. DO NOT UPGRADE YOUR PRODUCTION."** No GA date; the vendor expects "more betas than typical." _Source: [blesta.com/2026/05/21/blesta-6.0-beta-released](https://www.blesta.com/2026/05/21/blesta-6.0-beta-released/), [.../2026/06/11/blesta-6.0-beta-2-released](https://www.blesta.com/2026/06/11/blesta-6.0-beta-2-released/). Confidence: High._
- ⚠️ **Implication for this project:** the target is a beta whose client `.pdt` paths/structure can still shift between betas, and which has **no dedicated 6.0 stable docs** (docs track 5.13). Template work against b1 is a *moving target* until GA. This is a scheduling/risk input, captured here and revisited in synthesis. _(See [[kuickpay-php82-toolchain-now-available]] for the related runtime-version nuance on this same install.)_

### Candidate Target Front-End Stacks

**CSS framework options**

- **Bootstrap 5** — drops jQuery *as a Bootstrap dependency* (vanilla JS + Popper v2), but **jQuery stays loaded anyway** because Blesta core/plugins/your scripts still use `$`. BS4.6→5 breaking changes: `data-toggle`→`data-bs-toggle` namespace on every trigger, removed components (Jumbotron/Panel/Well/Media/card-deck), form-class overhaul (`.form-group`→grid, `.custom-*`→`.form-*`), directional→logical utilities (`.ml-*`→`.ms-*`, RTL-friendly). Mechanical but pervasive. _Source: [getbootstrap.com/docs/5.3/migration](https://getbootstrap.com/docs/5.3/migration/). Confidence: High._
- **Tailwind CSS** — utility-first; **official standalone CLI runs without a Node app** (self-contained binary; Tailwind explicitly markets it for "PHP, Rails… where Tailwind was the only reason for a package.json"). v4 is CSS-first (`@import "tailwindcss"`, no config file) with `.gitignore`-aware content scanning; declare `@source` explicitly so it scans `.pdt`. Solves styling, **not interactivity** (no JS components). _Source: [tailwindcss.com/blog/standalone-cli](https://tailwindcss.com/blog/standalone-cli), [docs/installation/tailwind-cli](https://tailwindcss.com/docs/installation/tailwind-cli). Confidence: High (Med that `.pdt` is auto-detected without `@source`)._

**Interactivity options**

- **Alpine.js (~15KB)** — client-only UI state (dropdowns, modals, tabs, toggles) declared in markup; directly replaces BS jQuery-plugin behaviors. Coexists with existing jQuery.
- **HTMX (~14KB)** — server-owned interactions: async partial swaps, form posts, pagination ("HTML-over-the-wire"). Pairs with Blesta's existing AJAX-fragment rendering. Coexists with jQuery.
- _Source: [htmx.org/essays/hypermedia-friendly-scripting](https://htmx.org/essays/hypermedia-friendly-scripting/), [blog.openreplay.com/htmx-vs-alpine-when-use](https://blog.openreplay.com/htmx-vs-alpine-when-use/). Confidence: High._

**Headless / SPA option (React/Vue on the Blesta API)**

- The Blesta **REST API exists** (JSON/XML/PHP; header auth `BLESTA-API-USER`/`BLESTA-API-KEY`; broad coverage of core + plugin models). **But docs explicitly say it is for trusted server-to-server use only** — an API key grants full company access and **must never reach a browser**. A headless front-end would require a **server-side BFF or a custom plugin endpoint** plus separate end-user auth. _Source: [docs.blesta.com/developers/api](https://docs.blesta.com/developers/api/). Confidence: High._

### Build Tooling & Platform Constraints

- **No repo-wide Node app** (per `project-context.md`); `plugins/order/package-lock.json` is plugin-local. The Tailwind **standalone CLI** sidesteps this cleanly; a React/Vue SPA would introduce a full Node build pipeline the project does not currently have.
- **`project-context.md` hard rules in force:** *"Use existing `.pdt` template conventions. Do not introduce Twig, Blade, or a second view engine"* and *".pdt views should stay thin."* These do not block a CSS re-skin or PE islands, but they raise the bar on a headless rewrite (which effectively abandons the `.pdt` layer).
- **Editability:** client `.pdt` templates and `client_*` controllers are **plain, editable PHP** — only core libraries are ionCube-encoded. Template work is unobstructed by encoding. _Source: [docs.blesta.com/guides/templates](https://docs.blesta.com/guides/templates/). Confidence: High._

### Precedent (feasibility signal)

Third-party modern client-area templates already exist and are sold/distributed, proving the copy-directory + dropdown workflow is a supported, real integration path: **Allure** ([marketplace.blesta.com/extension/148](https://marketplace.blesta.com/extension/148)), **ClientX**, **VIRTUS** ([marketplace.blesta.com/extension/232](https://marketplace.blesta.com/extension/232)), **Kohost**. _(Confidence: High that precedent exists; Med on each listing's current 6.0/5.13 compatibility — verify per listing.)_

### Confidence & Gaps (Technology Stack)

- **High:** current template = Bootstrap 4.6; minPHP `.pdt` engine mechanics; 5.13.9 stable vs 6.0 beta; 6.0 overhaul is admin-only; BS5 breaking-change surface; Tailwind standalone CLI needs no Node; Alpine/HTMX roles; API is not browser-safe; `.pdt` editable.
- **Gaps:** exact Bootstrap version bundled in the *6.0-b1 client* templates not separately confirmed by docs (read `structure.pdt`'s bundled CSS to be certain); whether Tailwind v4 auto-detects `.pdt` without `@source` (declare it); per-listing theme compatibility; no Blesta-specific (vs generic-PHP) migration write-ups exist — a one-view Tailwind spike is the cheap way to de-risk.

---

## Integration Patterns Analysis

For this project, "integration patterns" are **internal**: how a client template plugs into Blesta's request/render pipeline, and where the template's reach **ends**. There are no microservices or message brokers here — the integration surfaces that matter are the controller→view contract, the plugin/module/gateway/order render boundaries, the dashboard widget system, the theme-CSS channel, and (only for approach C) the REST API. Findings below are verified against the actual install (file:line) and cross-checked with official docs.

### The Controller → View Variable Contract (the template's real "API")

- The minPHP `View` renders `.pdt` via `include` + output buffering, with controller-set variables `extract()`-ed into view scope — so views reference bare `$nav`, `$content`, `$vars`, etc. _(`vendors/minphp/bridge/src/Lib/View.php`; Confidence: High.)_
- **Variable names are owned by the controller/action, not the template.** A custom template is just a different folder of `.pdt` files re-rendering the *same* variables. `View::fetch()` even falls back to the default view when a file is missing. This is what makes a re-skin safe: **you change markup, not data flow.**
- **The structure-level contract a new template MUST honor** (verified on disk):
  - `app/client_controller.php:78` → `$this->structure->set('nav', $nav)` (built from `Navigation->getPrimaryClient(...)`)
  - `app/client_controller.php:40` → `$this->structure->set('page_title', ...)`
  - `structure.pdt:149` iterates `foreach ($nav as ...)`; `structure.pdt:298/303` echo `$content`; `structure.pdt:10` renders `$page_title`.
  - **Rule for the redesign:** keep `$nav`, `$content`, `$page_title` (and per-action partial slot names) intact — rename nothing. Restyle the wrappers around them freely.
- _Source: on-disk + [docs.blesta.com/guides/templates](https://docs.blesta.com/guides/templates/), [docs.blesta.com/developers/plugins/getting-started](https://docs.blesta.com/developers/plugins/getting-started/). Confidence: High._

### The Rendering-Boundary Map — what a client template does NOT restyle ⚠️ (decisive scoping finding)

A custom client template under `app/views/client/<name>/` **only restyles core client controllers' chrome** (`client_main`, `client_services`, `client_invoices`, `client_accounts`, `client_contacts`, `client_pay`, etc.). It does **not** automatically restyle extension output, because every extension resolves its own views via the `PluginName.directory` path rewrite (`View::getViewPath()` → `PLUGINDIR`), physically outside the client-template tree.

**Measured surface on THIS install:**

| Renders its own `views/default/*.pdt` (outside your template) | Count (verified) |
|---|---|
| Plugins with their own views | **24** |
| — of those, plugins shipping their **own `structure.pdt`** (fully independent chrome) | **9** (`order`, `support_manager`, `cms`, `billing_overview`, `download_manager`, `system_overview`, `system_status`, `feed_reader`, `phpids`) |
| Gateways with their own views (payment forms/fields) | **38** |
| Modules with their own views (service mgmt screens) | **41** |
| Order plugin's own template set (separate, runtime-swapped) | `ajax`, `standard`, `wizard` |

- **Order forms are doubly separate:** `plugins/order/order_form_controller.php` swaps its view dir per order form at runtime (`setView(null, 'templates/'.$template)`), driven by the order form's own `template`/`template_style` columns (`wizard`/`standard`/`ajax` × `boxes`/`slider`/`list`) — entirely independent of the client-area template, and reachable at its own `/order/` URLs. _(Verified on disk; [docs.blesta.com/display/user/Order+System](https://docs.blesta.com/display/user/Order+System).)_
- **Implication for the redesign:** a client-template re-skin covers the account/billing/support **dashboard chrome** only. A *visually consistent* modern experience also requires restyling: the order flow (its own templates), plugin widgets/pages (esp. `support_manager`, `domains`, `billing_overview`), and gateway/module fragments. This is the single biggest scope multiplier in the project and the strongest argument **against** approach C and **for** a shared-CSS-class strategy. _(Confidence: High — first-party counts + [docs.blesta.com/developers/plugins/getting-started](https://docs.blesta.com/developers/plugins/getting-started/).)_

### Dashboard Composition & the Widget Integration Points

- Client chrome = `structure.pdt` (header/footer/nav) → renders `$content` from the action view → **plugin dashboard cards injected via the `widget_client_home` plugin action**; nav links injected via `nav_primary_client`. Cards render through the **`WidgetClient`** helper (`clear()`/`create($title,$attrs,$render)`/`end()`, with `$render` = `full`/`content_section`/`common_box_content` for AJAX). _Source: [docs.blesta.com/developers/plugins/widgets](https://docs.blesta.com/developers/plugins/widgets/), [.../plugin-actions](https://docs.blesta.com/developers/plugins/plugin-actions/). Confidence: High._
- **Integration leverage:** because plugin widgets are *injected into* your `structure.pdt`, your template controls the **card frame/grid** even though plugins control card *contents*. Standardizing on shared Bootstrap/utility classes lets one CSS layer style both — the practical workaround to the boundary problem above.

### Theme CSS Channel (colors/logo, decoupled from markup)

- Two complementary systems: **Templates** (markup/structure under `app/views/client/<name>/`) vs **Themes** (admin-configurable color palette + logo, separate Staff/Client tabs, JSON import/export since 3.2). _Source: [docs.blesta.com/getting-started/customizing-the-layout](https://docs.blesta.com/getting-started/customizing-the-layout/)._
- `structure.pdt` injects the generated theme stylesheet via `$theme_css` (+ `$theme_hash` cache-buster) **after** `application.min.css`, with an adjacent inline `$custom_css` hook. Backing model `app/models/themes.php` (`getCurrent($company_id,'client')`, `getAvailableColors`, …). _(Verified on disk. Confidence: High.)_
- **Integration leverage:** simple brand/color refreshes need **zero template edits** (Themes UI alone); structural/UX changes need a custom template. The docs-recommended upgrade-safe seam is an appended **`overrides.css`** in `structure.pdt` (not pre-wired in this install — a manual one-line add).

### REST API as an Integration Boundary (relevant only to approach C)

- Blesta exposes a **RESTful API** (`/api/model/method.format`; JSON/XML/PHP) with header auth `BLESTA-API-USER` + `BLESTA-API-KEY`, credentials per-company under **System > API Access** (HTTPS required; IP allowlisting available). Coverage is broad: "any valid credentials grant access to **every public model method**" across core + plugins (Clients, Invoices, ClientServices, Transactions, Contacts; SupportManager/Order via `Plugin.Model/method`). PHP SDK: `github.com/phillipsdata/blesta_sdk`.
- ⚠️ **Hard constraint for a browser front-end:** docs state the API is for **trusted first-party server-to-server use only** — a key is effectively all-or-nothing company access and **must never be shipped to a browser/SPA**. A headless client UI therefore mandates a **server-side BFF or a custom plugin endpoint** with separate end-user auth/session bridging. _Source: [docs.blesta.com/developers/api](https://docs.blesta.com/developers/api/), [.../extending-the-api](https://docs.blesta.com/developers/plugins/extending-the-api/). Confidence: High._

### Integration Security Patterns (carry into any approach)

- **Output escaping** is template-layer responsibility: views use `$this->Html->safe(...)` pervasively (40× in `structure.pdt` alone). A modern template must preserve `Html->safe()`/`Form` helper usage — utility-class restyles are safe; replacing helper-escaped output with raw echoes is an XSS regression. _(Ties to [[kuickpay-failclosed-empty-currency-red]]-style "don't weaken a safe default" discipline.)_
- **Session/CSRF:** the server-rendered approaches inherit Blesta's existing session + CSRF model for free. Approach C must re-establish auth (token/session bridge) at the BFF — a net-new security surface.

### Confidence & Gaps (Integration Patterns)

- **High (first-party verified):** the controller→view contract and `$nav`/`$content`/`$page_title` slots; the rendering-boundary counts (24 plugins / 9 with own structure / 38 gateways / 41 modules); order-form template independence; `$theme_css` channel; API auth/scope and its not-for-browser constraint; `Html->safe` escaping.
- **Gaps:** exact per-plugin widget/nav-action consumers not exhaustively enumerated (read each plugin's `getActions()` when scoping a specific page); precise helper method signatures should be confirmed against this install's `vendors/` helpers (docs API pages are labeled 3.6); per-resource API method names live in source-docs, not user docs.

## Architectural Patterns and Design

This section evaluates the three candidate approaches **as architectures**, against the constraints established in Steps 2–3: a server-rendered `.pdt` monolith, a large plugin/gateway/module render surface (24/38/41), an upgrade-overwrite of core `bootstrap`, a beta runtime, a documented "no second view engine / keep `.pdt` thin" rule, and a not-browser-safe API.

### System Architecture Patterns (the three options, as architectures)

**(A) Re-skin in a parallel template directory** — *evolve the monolith's view layer in place.*
A new `app/views/client/<name>/` directory, Bootstrap 4.6 → 5, same controller→view contract. Architecturally this is the lowest-surface change: no new runtime layers, no build pipeline beyond CSS, server-render and session/CSRF unchanged. Cost: BS5's `data-bs-*` namespace + removed-component churn touches nearly every `.pdt`, and it still leaves a framework you must theme to look modern. _Source: [getbootstrap.com/docs/5.3/migration](https://getbootstrap.com/docs/5.3/migration/)._

**(B) Progressive-enhancement "islands" on the server-rendered base** — *hypermedia-first, JS as enhancement.*
Keep `.pdt` server-rendering as the resilient baseline; add a modern CSS layer (Tailwind standalone CLI, no Node app) and interactivity islands (Alpine for client UI state, HTMX for server-fragment swaps), retiring jQuery island-by-island. This is the **islands / HTML-over-the-wire** architecture, and it is current best-practice for exactly this situation: GOV.UK *"do not build services as SPAs… use progressive enhancement"*; strangler-fig sources note SSR monoliths are "significantly harder to strangle" so an island layer is the recommended on-ramp. _Source: [gov.uk/service-manual/technology/using-progressive-enhancement](https://www.gov.uk/service-manual/technology/using-progressive-enhancement), [htmx.org/essays/when-to-use-hypermedia](https://htmx.org/essays/when-to-use-hypermedia/), [microservices.io strangler-fig](https://microservices.io/post/refactoring/2023/06/21/strangler-fig-application-pattern-incremental-modernization-to-services.md.html). Confidence: High._

**(C) Headless / SPA on the REST API** — *replace the view layer with a separate front-end app.*
React/Vue consuming Blesta data. Architecturally the heaviest: a second codebase, a mandatory **BFF/plugin endpoint** (the API is not browser-safe), new token/session auth, SSR for first-load/SEO, and — decisively — it **discards every server-rendered plugin/gateway/module/order view** (the 24/38/41 surface), which must be re-exposed and re-implemented. It also collides head-on with the project rule against a second view engine. htmx's "architectural sympathy" essay captures the mismatch: SPA frameworks have little sympathy with a hypermedia monolith. _Source: [htmx.org/essays/architectural-sympathy](https://htmx.org/essays/architectural-sympathy/), [docs.blesta.com/developers/api](https://docs.blesta.com/developers/api/), [maciekpalmowski.dev/blog/is-going-headless-worth-the-fuss](https://maciekpalmowski.dev/blog/is-going-headless-worth-the-fuss/). Confidence: High._

| Architecture | Effort | Risk | Reversibility | Interactivity ceiling | Fit vs. constraints |
|---|---|---|---|---|---|
| **(A) Re-skin** | Med-High | Med (broad mechanical diff) | Low once merged | Medium (BS5 components) | Good; but doesn't cover plugin surface, dated without restyle |
| **(B) PE islands** | **Low-Med, incremental** | **Low** (HTML baseline always works; per-island, reversible) | **High** | High (Alpine+HTMX, can embed a SPA island) | **Best** — designed to enhance SSR; honors "thin .pdt"; no Node app |
| **(C) Headless SPA** | High | High | Low | Highest | **Poor** — loses plugin rendering, needs BFF+auth, breaks "no 2nd view engine" |

### Design Principles & Best Practices (that apply here)

- **Progressive enhancement** — ship working server-rendered HTML; layer JS as enhancement. Resilience + accessibility + matches Blesta's grain. _([nngroup.com/articles/enhancement](https://www.nngroup.com/articles/enhancement/))_
- **Strangler-fig, not big-bang** — modernize page-by-page behind a stable shell; never a flag-day rewrite. The client template's per-controller `.pdt` mapping makes incremental page-by-page restyle natural.
- **Upgrade-safety / don't-fork-core** — parallel template dir + appended `overrides.css`; never edit `bootstrap` in place (it's overwritten on upgrade). Trade-off: your template won't auto-receive upstream `.pdt` fixes → budget periodic diff/re-apply.
- **Shared-CSS-class strategy (the plugin-boundary workaround)** — because plugin widgets render *into* your `structure.pdt` frame, standardize on one utility/Bootstrap class vocabulary so a single CSS layer styles both your chrome and the injected plugin cards/fragments. This is what converts the 24/38/41 boundary from "restyle everything" into "style the shared classes once."
- **Preserve the data/escape contract** — keep `$nav`/`$content`/`$page_title` and `Html->safe()`; restyle wrappers, never rename variables or drop escaping (XSS regression risk).
- **Architectural sympathy** — choose the layer that works *with* the monolith (CSS + hypermedia islands) over one that fights it (SPA).

### Scalability & Performance Patterns

- Server-render keeps **first-load fast and resilient** (no client-render waterfall); islands add interactivity without shipping a framework runtime. A SPA regresses first-load/SEO unless SSR is added (extra infra). _([storyblok SEO/SPA](https://www.storyblok.com/tp/seo-in-times-of-headless-cms-and-spa))_
- **Asset strategy:** Tailwind's purged output is small and cache-busted via the existing `$theme_hash` pattern; minify through the standalone CLI (the project already ships `*.min.css`/`*.min.js`). No CDN/edge or horizontal-scaling concerns — this is a UI-layer change, not a capacity change.

### Security & Deployment Architecture

- **(A)/(B)** inherit Blesta's session, CSRF, and `Html->safe()` escaping unchanged — the secure default is free. **(C)** introduces a new auth/session-bridge surface at the BFF (token handling, CSRF re-establishment) — net-new risk to design and review.
- **Deployment:** (A)/(B) deploy as plain files into `app/views/client/<name>/` (+ a CSS build artifact) — no new services, fits the project's "no Docker/CI invented" posture. (C) adds a Node build pipeline and a deployed front-end app the project doesn't currently have.
- **Beta-runtime caveat** (from Step 2): on `6.0.0-b1`, upstream client `.pdt` paths can shift between betas. Architecturally this **favors a thin, additive layer (B)** — an `overrides.css` + islands diff re-applies far more cheaply across beta bumps than a fully forked, heavily-rewritten template (A) or a parallel SPA that mirrors moving server contracts (C).

### Recommended Target Architecture (preliminary — finalized in Synthesis)

The evidence converges on a **hybrid, B-led architecture**: a parallel modern template directory as the foundation, modernized with a **shared utility/Bootstrap-5 class layer** and **Alpine/HTMX progressive-enhancement islands**, styled so the **same CSS covers injected plugin output**, with brand/color via the **Themes + `overrides.css`** seam — and a **strangler-fig, page-by-page** rollout. This maximizes upgrade-safety and reversibility, honors the "thin `.pdt` / no second view engine" rules, requires no Node app, and turns the plugin-render boundary into a one-time shared-class effort rather than a per-extension rewrite. Approach **C** is reserved for a future, genuinely app-like surface (and even then as an embedded island, per htmx guidance), not the dashboard baseline. _(Final recommendation, sequencing, and risks consolidated in Step 6.)_

### Confidence & Gaps (Architecture)

- **High:** the A/B/C architectural trade-offs; PE/strangler-fig as best-practice for SSR monoliths; the shared-CSS-class workaround following directly from the Step-3 render-boundary facts; security/deployment deltas.
- **Gaps:** no Blesta-specific migration case study exists (all architectural sources are general); the "shared-class covers plugins" assumption should be validated by auditing whether major plugins (`support_manager`, `domains`, `order`) actually use shareable Bootstrap classes vs. bespoke markup — a cheap spike that de-risks the whole B plan.

<!-- Content will be appended sequentially through research workflow steps -->

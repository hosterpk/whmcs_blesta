---
stepsCompleted: [1, 2, 3, 4, 5]
inputDocuments: ['_bmad-output/project-context.md', '_bmad-output/uiux/planning-artifacts/research/technical-blesta-client-template-modernization-research-2026-06-13.md']
workflowType: 'research'
lastStep: 5
research_type: 'technical'
research_topic: 'Blesta Order Form (Checkout) Modernization'
research_goals: 'Given Blesta order-form limitations, identify and de-risk the options for delivering a modern, UX-friendly ordering/checkout experience to clients — grounded in the actual order-plugin codebase, mirroring the client-template study but focused on the order/checkout flow.'
user_name: 'Israr'
date: '2026-06-13'
web_research_enabled: true
source_verification: true
---

# Research Report: technical

**Date:** 2026-06-13
**Author:** Israr
**Research Type:** technical
**Product/Project:** uiux (order-form track)

---

## Research Overview

This report is the **second track** of the `uiux` initiative. The first track ([technical-blesta-client-template-modernization-research-2026-06-13.md](./technical-blesta-client-template-modernization-research-2026-06-13.md)) modernizes the client *dashboard*; this track investigates modernizing the **Blesta order form / checkout** experience — the pre-login (and add-to-cart) flow a prospect uses to buy hosting/services. It is grounded in the actual codebase at `/home/hosterpk/beta.hosterpk.com` (Blesta `6.0.0-b1`, PHP 8.3) and cross-checked against current public Blesta docs and front-end/checkout-UX sources.

**Why a separate study:** the prior track established (first-party) that the order form is a **separate, runtime-swapped template system** living under `plugins/order/views/templates/`, with its own chrome (`order` ships its own `structure.pdt`), entirely outside the client-area template. So the dashboard recommendation does **not** automatically cover ordering — the checkout flow needs its own feasibility/approach analysis.

**Codebase facts treated as ground truth (already gathered):**

- Order forms render from **three template packs**: `ajax` (v1.1.0), `standard` (v1.0.0), `wizard` (v1.0.0), each under `plugins/order/views/templates/<pack>/`.
- Each pack declares **style variants** in its `config.json`: `ajax`/`wizard` → `slider` / `boxes` / `list`; `standard` → `default`.
- Selection is **per order form** (template + template_style columns); `plugins/order/order_form_controller.php` swaps the view dir at runtime (`setView(null, 'templates/'.$template)`). Reachable at `/order/` URLs.
- The checkout flow `.pdt` set: `main` / `main_packages` / `config` / `config_packageoptions` / `cart` / `summary` / `checkout` (+ `_contact_info` / `_cc_info` / `_ach_info` / `_total_info`) / `checkout_complete`, plus `signup` / `signup_otp`.
- Front-end stack is jQuery-based (e.g. `wizard/javascript/jquery.sticky.min.js`); the order plugin owns its own controllers/models/lib (`order_controller.php`, `order_model.php`, `lib/`).
- `project-context.md` rules in force: *"Use existing `.pdt` template conventions. Do not introduce Twig, Blade, or a second view engine"*, *".pdt views should stay thin"*, and the extension-folder contract (order is a plugin; keep its code in `plugins/order/`).

**Methodology:** current web sources with multi-source verification for recommendation-driving claims; confidence levels flagged where sources are thin (6.0.0-b1 is a beta line). Reuses verified cross-cutting findings from the client-template track where they apply (Blesta version landscape, `.pdt` engine, theme channel, API constraints).

---

## Technical Research Scope Confirmation

**Research Topic:** Blesta Order Form (Checkout) Modernization
**Research Goals:** Given Blesta order-form limitations, identify and de-risk the options for delivering a modern, UX-friendly ordering/checkout experience to clients — grounded in the actual order-plugin codebase.

**Technical Research Scope:**

- Architecture — order template-pack system, per-order-form `template`/`template_style` selection & runtime view-swap, custom-pack creation, order plugin's own `structure.pdt`, upgrade-safety
- Implementation Approaches — (A) re-skin a stock pack to Bootstrap 5; (B) new custom pack + progressive-enhancement islands (Alpine/HTMX); (C) headless checkout SPA on the order/API; (D) third-party/commercial order forms (buy-vs-build)
- Technology Stack — current order-form stack (jQuery, bundled Bootstrap, AJAX flow) vs. target stacks
- Integration Patterns — order controller→view variable contract; AJAX cart/checkout endpoints; gateway payment-field rendering inside checkout (38-gateway boundary); config-option/package/coupon flows; cart/session state
- Checkout UX (new dimension) — one-page vs multi-step, progress/stepper, real-time validation, cart UX, mobile, guest checkout, address/domain UX, payment UX, cart-abandonment reduction
- Performance / Conversion — upgrade durability, payment render boundary, localization/RTL, conversion impact

**Research Methodology:**

- Current web data with rigorous source verification
- Multi-source validation for critical technical claims
- Confidence level framework for uncertain information
- Reuses verified cross-cutting findings from the client-template track; weighted against documented project constraints and the higher regression bar of a money/conversion surface

**Scope Confirmed:** 2026-06-13

---

## Technology Stack Analysis

> **The load-bearing finding for this whole study:** Blesta's vendor has **explicitly declined to modernize the stock order UI in core.** The "More Beautiful Order Pages" feature request was marked **DECLINED**, with staff stating the desired design *"can be accomplished with new or custom client area templates."* ([requests.blesta.com/topic/more-beautiful-order-pages](https://requests.blesta.com/topic/more-beautiful-order-pages)). A modern checkout for Blesta is therefore a **deliberate build-it-yourself / buy-a-template story** — there is no "wait for upstream" path. _(Confidence: High.)_

### Current Order-Form Stack (as-is, on disk)

| Aspect | What ships today | Note |
|---|---|---|
| Order plugin | **v3.1.0** (`plugins/order/config.json`) | unchanged shape in 6.0.0-b1 |
| Template packs | **3**: `standard` (v1.0.0), `wizard` (v1.0.0), `ajax` (v1.1.0) | `standard` = code default + per-view fallback; `wizard` = docs-stated default |
| Style variants | `standard` → `default`; `wizard`/`ajax` → `slider` / `boxes` / `list` | surfaced in admin as 7 merged "Template" options |
| View engine | minPHP `.pdt` (same engine as client area) | runtime view-swap per order form (`order_form_controller.php:132`) |
| JS stack | **jQuery-based**: `order.js`, `cart.js`, `config.js`, `config_packageoptions.js`, `signup.js`, `summary.js`, `jquery.sticky.min.js` | AJAX flow already present (esp. `ajax` pack) |
| Chrome | packs ship **no `structure.pdt`** → order form inherits the **active client-area template's** chrome (`app/views/client/`) | a custom pack *may* add its own `structure.pdt` to own the chrome |
| Order types | `general`, `domain`, `registration` (via `types/<type>/`) | `standard` supports all 3; `wizard`/`ajax` = general+domain only |

_Source: on-disk verification + [docs.blesta.com/integrations/plugins/order-system](https://docs.blesta.com/integrations/plugins/order-system/). Confidence: High._

### Version Landscape (reused + order-specific deltas)

- Cross-cutting facts carry over from the client-template track: **5.13.9 stable vs 6.0.0-b1 beta** ("DO NOT UPGRADE PRODUCTION"); Paradigm overhaul is **admin-only**; `.pdt` templates are editable plain PHP; the REST API is **server-to-server only, not browser-safe**. _(See companion study.)_
- **6.0 changes ordering only marginally** (verified against this install — Order plugin still v3.1.0, same 3 packs): b1 added an *optional recurring-billing-consent checkbox*; b2 added *embeddable domain search* (copy domain-search markup to an external site). **Neither is a checkout redesign.** _Source: [blesta.com/2026/05/21/blesta-6.0-beta-released](https://www.blesta.com/2026/05/21/blesta-6.0-beta-released/), [.../2026/06/11/blesta-6.0-beta-2-released](https://www.blesta.com/2026/06/11/blesta-6.0-beta-2-released/). Confidence: High._

### Candidate Target Stacks (checkout-specific)

- **Bootstrap 5 / Tailwind (visual layer)** — same trade-offs as the dashboard track. Checkout-specific needs: a **responsive cart/order table** that reflows to stacked cards on mobile, and a **sticky order-summary** panel. If staying Bootstrap-family, BS5 is the lower-friction visual path; Tailwind (standalone CLI, no Node) gives more control. _Source: prior track + [getbootstrap.com/docs/5.3/migration](https://getbootstrap.com/docs/5.3/migration/)._
- **HTMX + Alpine.js (interactivity) — strong fit for checkout specifically.** HTMX owns server-validated steps, live cart/total recalculation, inline field validation on blur, and domain-availability lookups (server stays the source of truth — exactly what you want where money + validation + payment security live server-side). Alpine owns client-only state: step show/hide, billing-cycle toggle, add-on accordions. Combined **~29 KB** vs **100 KB+** for a SPA, and both coexist with the existing jQuery. _Source: [hypermedia.systems/htmx-patterns](https://hypermedia.systems/htmx-patterns/), [htmx.org/docs](https://htmx.org/docs/). Confidence: High._
- **Headless checkout SPA (React/Vue)** — heaviest and weakest fit: a checkout SPA must duplicate server-side validation, handle payment state client-side, add SSR, and (per the API constraint) route through a server-side broker. Resilience matters most at the point of sale, which argues against a JS-required checkout. _Source: [docs.blesta.com/developers/api](https://docs.blesta.com/developers/api/), [oliverjam.es/articles/progressive-enhancement-htmx](https://oliverjam.es/articles/progressive-enhancement-htmx). Confidence: High._

### Buy-vs-Build Precedent (thin market)

- **Allure** (SwiftModders) is the only concrete precedent — it ships **two custom order forms** matched to the theme, built on **Bootstrap 4**, targeting **Blesta 5.x** (tops out ~5.10.2 in retrievable data; **no stated 6.0 support, not a true single-page checkout**). [marketplace.blesta.com/extension/148](https://marketplace.blesta.com/extension/148), [swiftmodders.com](https://swiftmodders.com/products/blesta-themes/allure-blesta-client-theme/).
- **ClientX** (WHMCS Global Services) ships a `cartx` order template; **VIRTUS** order-form contents unconfirmed (JS-rendered listings). No vendor advertises a **6.0-ready, true one-page checkout** for Blesta. _(Confidence: High that the market is thin; Med on per-product 6.0 compatibility.)_
- **Takeaway:** buying gets you a Bootstrap-4, 5.x-era starting point at best — a modern 6.0-targeted checkout is **largely greenfield**, reinforcing the build path.

### Documented Limitations of the Stock Order Forms (the "why" this project exists)

Community/tracker evidence of exactly what's dated _(Confidence: High on request status; Med where forum bodies returned 403)_:
- **Multi-step / fragmented & "dated"** flow; long-standing **"Powerful Blesta One Page Order-form"** request (under consideration ~6 yrs) — [requests.blesta.com/topic/powerful-blesta-one-page-order-form](https://requests.blesta.com/topic/powerful-blesta-one-page-order-form).
- **No cross-form cart** — "Universal Cart" request; cart resets when switching order forms (blocked by per-form currency/gateway/coupon config) — [requests.blesta.com/topic/universal-cart](https://requests.blesta.com/topic/universal-cart).
- **Awkward domain+hosting ordering** (disjoint steps); order-type redirect bugs (CORE-2493, CORE-1362/1427, CORE-4204).
- Against this, current checkout-UX research sets the bar: **~70.2% average cart abandonment** (Baymard), with top fixable causes being surprise costs (39%), forced account creation (19%), and over-long/complex checkout (18%) — all directly addressable by a rebuilt flow. _Source: [baymard.com/lists/cart-abandonment-rate](https://baymard.com/lists/cart-abandonment-rate)._

### Build Tooling & Platform Constraints

- Same as the dashboard track: **no repo-wide Node app**; Tailwind standalone CLI sidesteps this; a SPA would add a Node pipeline. Order code must stay in `plugins/order/` (extension-folder contract); *no second view engine / keep `.pdt` thin* applies. Editability is unobstructed (`.pdt` not encoded).

### Confidence & Gaps (Technology Stack)

- **High:** core declined to modernize order UI (custom template is the sanctioned path); 3-pack structure + versions; 6.0 leaves ordering ~unchanged; HTMX/Alpine fit for checkout; abandonment baseline; thin buy-vs-build market.
- **Gaps:** exact bundled Bootstrap/jQuery version inside the order packs not grep-able from minified assets (read a pack's `css/order.css` header to confirm); per-product 6.0 compatibility of Allure/ClientX/VIRTUS (JS-rendered/403 listings — verify in a browser); forum-thread bodies (403) corroborated via search snippets only.

---

## Integration Patterns Analysis

For the order form, the integration surfaces that matter are: the per-step **controller pipeline**, the **controller→view variable contract** a custom pack must honor, the **payment-rendering boundary** (where gateways own the DOM), the **server-side cart/session** model, the **interactive sub-flows** (config options / coupons / domain lookup), and the **custom-pack ↔ client-template chrome** relationship. All findings below are verified against actual source (`file:line`) and cross-checked with docs.

### The Checkout Pipeline (per-step controllers → views)

The order flow is **not one controller** — each step is its own controller under `plugins/order/controllers/`, all extending `OrderFormController` (→ `OrderController` → `AppController`). URLs map as `order/{controller}/{action}/{form-label}`.

| Step | Controller::action | Renders | Note |
|---|---|---|---|
| Browse | `Main::index` (`controllers/main.php:30`) | `main.pdt` (+ `main_index`/`main_index_list`) | redirects by group count |
| Package list | `Main::packages` (`:138`) | `main_packages.pdt` | |
| Configure | `Config::index` (`controllers/config.php:33`) | `config.pdt` | module fields + addons + options |
| Domain pre-config | `Config::preConfig` (`:494`) | `types/domain/config_preconfig.pdt` | only when order type `requiresPreConfig()` |
| Cart | `Cart::index` (`controllers/cart.php:17`) | `cart.pdt` | |
| Signup/login | `Signup::index` (`controllers/signup.php:20`) | `signup.pdt` | creates client, logs in |
| Checkout/pay | `Checkout::index` (`controllers/checkout.php:31`) | `checkout.pdt` | creates order, renders payment UI |
| Complete | `Checkout::complete` (`:529`) | `checkout_complete.pdt` | nonmerchant redirect buttons |

`preAction()` (`order_form_controller.php:38-188`) resolves the form, boots `SessionCart`, sets currency, and points the view at `templates/{template}` with **per-view fallback to `standard`** if a pack lacks a `.pdt` (`:177-187`). Generic AJAX dispatch: `renderView()` (`:195-204`) — if `isAjax()`, fetch the per-action view and `outputAsJson()` the HTML. _(Confidence: High — source-verified.)_

### Controller → View Variable Contract (what a custom pack MUST honor)

Because the controllers are fixed, a custom pack re-implements `.pdt` markup but **must consume the same `set()` variables and POST the same fields**. Load-bearing examples (source-verified):

- **`config.pdt`** (`config.php:471-486`): `vars`, `item`, `package`(`->pricing[]`), `addon_groups`, `service_fields`, `fields_html` (a `FieldsHtml` object → call `->generate()`), `currency`, `periods`, bundle/domain eligibility. Must POST `pricing_id`, `group_id`, `configoptions[...]`, optional `addon[...]`, `qty`; must keep a `.package_options` container for AJAX-injected options.
- **`cart.pdt`** (`cart.php:40-43`): `cart`, `totals`, `totals_recurring`, `display_items[]` (`description`/`type`/`qty`/`price`), `periods`, `currency`, `temp_coupon`. Totals keys: `subtotal`/`total`/`discount`/`tax[]` each with `amount`+`amount_formatted`.
- **`checkout.pdt`** (`checkout.php:206-222`): `payment_accounts`, `payment_types`, `nonmerchant_gateways`, `order`, `invoice`, `credits`, `totals_section`, + partials `contact_info`/`cc_info`/`ach_info`. Must POST `checkout=true` + one of `payment_account` / `payment_type`(+fields) / `gateway`.
- **`signup.pdt`** (`signup.php:296-324`): `custom_fields`, `countries`, `states`, `currencies`, `required_contact_fields`, `captcha`, `show_tos`, etc.
- **`summary.pdt`** (`summary.php:86-100`): `summary`, `nonmerchant_gateways`, `merchant_gateway`, `payment_types`, `free_domains`, `temp_coupon`.

**Rule for the redesign:** restyle markup freely, but preserve these variable reads and form field names — the controllers won't change. _Source: source-verified; docs publish no per-view contract. Confidence: High._

### The Payment-Rendering Boundary ⚠️ (decisive restyle limit)

The order form **does not render raw card fields itself — it delegates to the `Payments` model → the gateway.** This bounds how much payment UX a template can own:

- **Merchant (onsite CC/ACH):** `Checkout::setCcView()` (`checkout.php:1262`) calls `Payments->getBuildCcForm()`; `checkout_cc_info.pdt:9-34` shows the rule — **if `$gateway_form` is non-empty, echo gateway HTML verbatim; else fall back to Blesta's native fields.** So SCA gateways (Stripe Elements, etc.) implementing `MerchantCcForm::buildCcForm()` **own the card-field DOM** — a template can style the *container*, not the injected markup. 3DS/SCA adds an AJAX `getPaymentConfirmation` → `#payment_confirmation` injection (`checkout.php:902/958`).
- **Nonmerchant (offsite):** rendered as **radio buttons** of gateways on checkout (`checkout.pdt:113-124`); the offsite "pay" button HTML is gateway-supplied via `getBuildProcess()` on `checkout_complete.pdt`.
- **Restyle takeaway:** a template fully controls payment-method *selection chrome*, layout, TOS/consent checkboxes, and the native fallback fields — but **gateway-injected card fields and offsite buttons are only as restylable as the gateway's own HTML allows.** This is the order-form analogue of Track 1's 38-gateway boundary, now precisely located. _Source: source-verified + [docs.blesta.com/display/dev/Merchant+Gateways](https://docs.blesta.com/display/dev/Merchant+Gateways). Confidence: High._

### Cart / Session State (why one-page checkout needs NO backend changes)

- The `SessionCart` component (`components/session_cart/session_cart.php`) holds **all** ordering state server-side under one key `{company_id}-{label}`: `items[]`, `queue[]`, and `data` (`currency`, `coupon`, `temp_coupon`, `tos_accepted`, `recurring_consent_accepted`, …). **No cart DB table** — the order row is created only at `Checkout::index` (`OrderOrders->add()`), then `emptyCart()`.
- **Implication:** a one-page (or fewer-step) checkout is **feasible without backend changes** — state already lives in one session object, and composable HTML partials already exist via AJAX (`config/packageoptions`, `summary`, `cart`, `checkout/getTotals`). The `ajax` pack already does single-page-ish configuration. Constraint to respect: **currency locks once the cart is non-empty** (`setCurrency()`). _Source: source-verified. Confidence: High._

### Interactive Sub-Flows (the parts most needing UX modernization)

- **Configurable options:** AJAX `Config::packageOptions` (`config.php:525`) returns `config_packageoptions.pdt` with server-generated conditional-option JS (`OptionLogic::getJavascript()`); validation is **server-side** (`config.php:296-372`) — a new UI cannot bypass it (good: keep validation server-authoritative, restyle the inputs).
- **Coupons:** `Cart::applyCoupon` (`cart.php:99`) — AJAX JSON; `?coupon=` stashed as `temp_coupon`.
- **Domain availability:** the `domain` order type's `preConfig` → `types/domain/lookup.pdt` drives search, per-domain availability badges, free-domain eligibility, TLD pricing table, and **AJAX "load more TLDs"** — the richest interactive surface and a prime modernization target. _Source: source-verified. Confidence: High (the registrar availability call itself lives in `lib/order_types/domain/`, not opened — minor gap)._

### Custom-Pack Mechanics & the Chrome Relationship (ties to Track 1)

- **Auto-discovery:** `OrderForms::getTemplates()` (`models/order_forms.php:345`) `opendir()`s `templates/` and reads each `config.json` — **dropping in a new sibling dir auto-registers it**; no DB step. Selected per order form (Packages > Order Forms → "Template").
- **Chrome:** packs ship **no `structure.pdt`** by default → the order form's page shell is rendered by the **active client-area template** (`app/views/client/`). A custom pack **may add its own `structure.pdt`** to fully own the chrome (the long-requested "Full Structure Templates for Order Forms"). **This is the integration seam between the two `uiux` tracks:** a modern order pack can either inherit the modernized dashboard shell or carry its own.
- **Order types** are advertised by `types/<type>/` subfolders (`getSupportedTypes()` `:380`); mirror `standard`'s `types/` for the types you sell (esp. `registration`, which only `standard` supports). _Source: source-verified. Confidence: High (Med on the `getSupportedTypes` default-template re-point nuance — verify on b1)._

### Upgrade-Safety Boundary

- Order packs live **inside** `plugins/order/views/templates/` → a plugin upgrade **overwrites the stock packs**. A **uniquely-named custom pack** (e.g. `templates/hosterpk/`) is the de-facto survival path (community practice; not a contractual Blesta guarantee that upgrades never prune unknown subfolders). This is *weaker* than the client-area template guarantee (those live outside plugins under `app/views/client/` with an officially documented copy-pattern) — a noted risk to manage. _Source: forum practice + [docs.blesta.com/guides/templates](https://docs.blesta.com/guides/templates/). Confidence: High on the hazard; Med on the survival guarantee._

### Integration Security Patterns

- Preserve `Html->safe()`/`Form` helper escaping and the server-side validation in `Config`/`Checkout` — restyle inputs, never move validation client-only or drop escaping (XSS/payment-integrity regression). Server-rendered steps inherit Blesta's session/CSRF for free; a headless checkout would re-establish all of it at a broker. _(Ties to [[kuickpay-failclosed-empty-currency-red]] "don't weaken a safe default" discipline — doubly important on a money path.)_

### Confidence & Gaps (Integration Patterns)

- **High (source-verified):** the per-step controller pipeline + URLs; the variable/field contract per view; the payment delegation boundary (gateway-owned card DOM with native fallback); the server-side `SessionCart` model (and that it enables one-page without backend changes); config-option/coupon/domain wiring; pack auto-discovery + per-view fallback; chrome inheritance from the client template.
- **Gaps:** the domain order-type registrar/whois call (`lib/order_types/domain/`) not opened; `getSupportedTypes` default-template re-point nuance to confirm on b1; docs publish no per-view contract (it's empirical-from-source); some figures rest on 403-blocked forum bodies (search-snippet corroborated).

## Architectural Patterns and Design

This section evaluates the candidate approaches **as architectures** for a checkout — a surface where the regression bar is higher than the dashboard (a broken card is cosmetic; a broken checkout step loses a sale) and where the goal is measurable: reduce the ~70.2% abandonment baseline. Constraints carried in from Steps 2–3: fixed per-step controllers + server-side `SessionCart`, a gateway-owned payment DOM, a custom pack that auto-discovers and falls back per-view, weaker-than-dashboard upgrade-safety, the "no second view engine / keep `.pdt` thin / order code stays in `plugins/order/`" rules, and a vendor that **won't** modernize order UI in core.

### The Options, as Architectures

**(A) Re-skin a stock pack in place** — *edit `wizard`/`ajax`/`standard` to Bootstrap 5.*
Lowest conceptual change, but architecturally the **worst upgrade-safety**: stock packs are overwritten on order-plugin upgrade, so in-place edits are lost. Also still leaves the dated multi-step structure unless you also rework flow. Not recommended except as throwaway prototyping.

**(B) New custom order template pack + progressive-enhancement islands** — *a uniquely-named pack (`templates/hosterpk/`), modern CSS layer, Alpine/HTMX.*
A new pack auto-registers, overrides only the `.pdt` files you change (rest fall back to `standard`), and **can ship its own `structure.pdt`** to own the chrome *or* inherit the Track-1 modernized shell. HTMX drives server-validated steps / live totals / domain lookup; Alpine drives step state and the billing-cycle toggle. Crucially, the **server-side `SessionCart` means a consolidated/one-page flow needs no backend changes**. This is the islands/hypermedia architecture — best-practice for a resilient, server-authoritative checkout, and it honors every project rule. **Recommended baseline.**

**(C) Headless checkout SPA** — *React/Vue ordering against the API.*
Heaviest and weakest fit on a money path: must duplicate server-side validation client-side, handle payment state in JS, add SSR, and route through a **server-side broker** (API is not browser-safe). Resilience matters most at point of sale, and this makes checkout JS-required. Also breaks the "no second view engine" rule. Reserve for, at most, an embedded island inside (B).

**(D) Buy a commercial pack** — *Allure / ClientX as starting point.*
Real but limited: the only concrete precedent (Allure, 2 custom forms) is **Bootstrap 4 / Blesta-5.x, no 6.0 support, not single-page**. Useful as a *reference/accelerator* or interim, but a 6.0-targeted modern checkout is **largely greenfield** — buying doesn't escape the build.

| Architecture | Effort | Risk (money surface) | Upgrade-safety | UX ceiling | Fit vs. constraints |
|---|---|---|---|---|---|
| **(A) Re-skin stock pack** | Med | Med-High | **Poor** (overwritten) | Low (same flow) | Weak — not upgrade-safe |
| **(B) Custom pack + PE islands** | **Med, incremental** | **Low-Med** (HTML baseline; per-step, reversible) | Med (unique-named pack) | **High** (one-page-capable, live UX) | **Best** — honors all rules; no Node app; server-authoritative |
| **(C) Headless SPA** | High | **High** | Low | Highest | Poor — dup validation, broker+auth, breaks "no 2nd engine" |
| **(D) Buy (Allure/ClientX)** | Low-Med upfront | Med (3rd-party, 5.x-era) | Vendor-dependent | Med | Partial — BS4/5.x, not 6.0/single-page; reference at best |

### Design Principles (checkout-specific)

- **Progressive enhancement on a money path** — the flow must work with plain form POSTs even if JS fails; islands enhance, never gate. Resilience > flashiness at the point of sale.
- **Server stays the source of truth** — keep config-option/checkout validation and payment handling server-side (they already are); HTMX swaps server-rendered fragments. Never move validation client-only.
- **Respect the payment boundary** — design around gateway-owned card DOM; restyle selection chrome, containers, and native fallback fields, not the gateway's injected markup.
- **Conversion-driven UX, not just aesthetics** — bake in the documented wins: **guest checkout / defer account creation, transparent totals up-front, minimal fields (~20→~12), inline on-blur validation that never clears data, `autocomplete` attributes (WCAG 1.3.5 AA), labels-above-field + ≥44px touch targets, live domain/option/price updates.** _(Baymard / NN/g.)_
- **Strangler-fig, page-by-page** — the per-step controller mapping makes incremental rollout natural: modernize one step (or the domain-lookup surface) at a time behind the stable pipeline, not a flag-day rewrite.
- **Upgrade-safety via unique pack name + own assets** — never edit stock packs; keep styles in the pack's own CSS; budget periodic diff against upstream pack changes.

### Conversion, Performance & Deployment

- Server-render keeps first paint fast and the flow resilient; ~29 KB of Alpine+HTMX vs 100 KB+ SPA. The win is **measured in conversion** (abandonment reduction), not just look.
- **Deployment:** (A)/(B)/(D) are plain files under `plugins/order/views/templates/<pack>/` (+ a CSS build artifact) — no new services, fits the "no Docker/CI invented" posture. (C) adds a Node pipeline + broker service.
- **Security:** (A)/(B)/(D) inherit Blesta session/CSRF/escaping; (C) re-establishes auth at the broker — net-new surface on a payment path.

### Recommended Target Architecture (preliminary — finalized in Synthesis)

A **new, uniquely-named custom order template pack** (approach B), built on the **same shared Bootstrap-5/utility CSS layer as the Track-1 dashboard** so the order flow and client area look like one product, enhanced with **HTMX (server-validated steps, live totals, domain/option lookup) + Alpine (step state, billing-cycle toggle)**, optionally shipping its **own `structure.pdt`** (or inheriting the modernized client shell), leaving **gateway payment HTML untouched**, keeping **server-side validation/`SessionCart`** intact, deployed **page-by-page (strangler-fig)** — starting with the highest-leverage surfaces (config/options + cart/totals, and domain lookup), folding in the **conversion UX wins**. Buy (Allure) is a *reference/interim* only. Headless is out of scope for the baseline. _(Final recommendation, sequencing, risk register in Step 6.)_

### Confidence & Gaps (Architecture)

- **High:** the A/B/C/D trade-offs; PE/server-authoritative as best-practice for checkout; one-page feasible without backend changes (from the `SessionCart` finding); the conversion-UX principles (Baymard/NN/g); the two-track shared-CSS seam.
- **Gaps:** no Blesta-specific checkout-modernization case study (architecture sources are general + WHMCS-adjacent); the unique-named-pack upgrade-survival is community practice, not a Blesta guarantee — validate against an actual order-plugin upgrade; whether to ship an own `structure.pdt` vs inherit Track-1's shell is a design decision deferred to UX/architecture phases.

## Implementation Approaches and Technology Adoption

This step turns the recommended **(B) custom-pack + PE-islands** architecture into a concrete adoption plan, grounded in the verified mechanics (Steps 2–3) and this project's own workflow/testing rules (`project-context.md`).

### Technology Adoption Strategies (migration plan)

- **Scaffold a uniquely-named pack** — copy `plugins/order/views/templates/wizard/` (richest JS flow) → `plugins/order/views/templates/hosterpk/`, edit `config.json` (`name`/`description`/`styles`), select it per order form in **Packages > Order Forms**. Auto-discovered via `OrderForms::getTemplates()` — no DB step. Override only the `.pdt` you change; the rest fall back to `standard`. _([docs.blesta.com/integrations/plugins/order-system](https://docs.blesta.com/integrations/plugins/order-system/).)_
- **Decide the chrome seam early** — either ship the pack's own `structure.pdt` (full control, decoupled from the client area) **or** inherit the Track-1 modernized client shell (one product feel). Recommend inheriting Track-1's shell if that work lands first; otherwise ship a thin own-`structure.pdt`.
- **Strangler-fig rollout** — modernize one step at a time behind the stable controller pipeline; ship and measure each before the next. Never a flag-day cutover.
- **Mirror `standard`'s `types/` folders** for the order types you sell (esp. `registration`, only `standard` supports it) so type support is advertised correctly.

### Development Workflows and Tooling

- **CSS layer:** Tailwind **standalone CLI** (no Node app needed; declare `@source` for `.pdt`) _or_ Bootstrap 5 if staying Bootstrap-family — align with whatever Track 1 picks so the shared class vocabulary is identical. _([tailwindcss.com/blog/standalone-cli](https://tailwindcss.com/blog/standalone-cli).)_
- **Interactivity:** add Alpine + HTMX as static assets in the pack's `javascript/`; enhance, don't gate. They coexist with the existing jQuery/`order.js` AJAX.
- **Project rules in force** (`project-context.md`): keep `.pdt` thin, no second view engine, order code stays in `plugins/order/`; don't normalize `.pdt` for PHPCS, don't hand-edit `*.min.*`. Run `php -l` on any touched PHP; PHPCS only via existing component configs.

### Testing and Quality Assurance (higher bar — money surface)

- **Reality:** this checkout has no root test suite shipped; root Composer test scripts expect a sibling `../tests`. Treat checkout testing as **manual + targeted + e2e**, not unit-only.
- **E2E is the right tool here** — generate automated end-to-end order/checkout tests (the BMad `qa-generate-e2e-tests` skill) covering: package→config→cart→signup→checkout→complete, guest vs registered, coupon apply, domain lookup, and a merchant + a nonmerchant gateway path.
- **Manual smoke on the real stack** — a real Blesta/MySQL stack runs locally; exercise a live order end-to-end (a sandbox/test gateway) before each rollout step. Verify the **gateway-owned payment DOM** renders untouched (no restyle regression on card fields / 3DS).
- **Regression guardrails:** confirm server-side validation still fires (config options min/max/required, checkout), `Html->safe()` escaping preserved, currency-lock behavior intact, and idempotency of order creation. _(Discipline mirrors [[kuickpay-failclosed-empty-currency-red]] — never weaken a safe default on a money path.)_

### Deployment and Operations Practices

- **Deploy = plain files** under `plugins/order/views/templates/hosterpk/` (+ a built CSS artifact) and an admin order-form selection. No new services; fits the "no Docker/CI invented" posture.
- **Upgrade discipline:** on each order-plugin upgrade, **diff stock packs** (`standard`/`wizard`/`ajax`) for upstream `.pdt`/contract changes and re-apply to the custom pack; confirm the custom pack still resolves (it's inside `plugins/order/`, so verify it survived the upgrade).
- **Rollout safety:** the per-order-form template selector enables **canary** — point one low-traffic order form at the new pack, measure, then promote.

### Team Organization and Skills

- One full-stack PHP/front-end developer can carry this: **Blesta `.pdt`/controller literacy, Bootstrap-5-or-Tailwind, Alpine/HTMX, and checkout-UX fluency**. No new backend/SPA/Node competency required — a deliberate benefit of approach B.

### Cost Optimization and Resource Management

- **Incremental, low fixed cost:** no new infrastructure, licenses, or runtime services; effort scales with how many steps/types you modernize. Buying Allure as a *reference* could shorten visual design but doesn't remove the 6.0 build.

### Risk Assessment and Mitigation

| Risk | Likelihood | Mitigation |
|---|---|---|
| Custom pack overwritten/pruned on order-plugin upgrade | Med | Unique pack name; documented diff-on-upgrade routine; verify post-upgrade |
| Payment regression (gateway DOM restyle / 3DS break) | Med-High impact | Don't touch gateway-injected HTML; e2e + manual smoke on merchant & nonmerchant gateways |
| Beta moving target (6.0.0-b1 `.pdt`/contract drift) | Med | Thin overrides + per-view fallback; re-verify contract on each beta bump |
| Conversion *regression* from redesign | Med | Canary one order form; measure abandonment before promoting |
| Currency-lock / cart-edge behaviors | Low | Preserve `SessionCart` semantics; e2e cover currency switch + cart edits |

## Technical Research Recommendations

### Implementation Roadmap (page-by-page, highest leverage first)

1. **Foundation** — scaffold `hosterpk` pack, wire shared CSS layer + Alpine/HTMX, decide chrome seam, set up a canary order form.
2. **Configure + options** — modern `config.pdt`/`config_packageoptions.pdt`: live price recalculation, clear add-on/billing-cycle UX (Alpine toggle), inline validation. *(High conversion leverage.)*
3. **Cart + totals** — responsive cart, **sticky transparent order summary**, instant coupon apply (HTMX). *(Targets the #1 abandonment cause — surprise costs.)*
4. **Signup/checkout** — **guest checkout / deferred account creation**, minimal fields, `autocomplete`, on-blur validation; leave gateway payment fields as-is. *(Targets #2/#3 causes.)*
5. **Domain lookup** — modernize `types/domain/lookup.pdt`: instant availability, alternatives, cleaner TLD/pricing UX.
6. **Consolidate** — optionally collapse steps toward a one-page flow (feasible without backend changes per `SessionCart`); measure vs multi-step.

### Technology Stack Recommendations

- **Markup:** new custom order pack (`.pdt`), thin. **CSS:** shared Bootstrap-5/utility layer aligned with Track 1 (Tailwind standalone CLI if chosen there). **JS:** Alpine (state/toggles) + HTMX (server-validated steps/totals/lookup), atop existing jQuery; retire jQuery opportunistically. **Payment:** unchanged (gateway-owned). **Validation/state:** unchanged (server-side + `SessionCart`).

### Skill Development Requirements

- Short ramp on **Alpine + HTMX** for any jQuery-only developer; **checkout-UX/Baymard principles** as shared team reference; confirm **Blesta order-pack** mechanics on the live 6.0.0-b1 build (admin Template/Style field layout, `getSupportedTypes` behavior).

### Success Metrics and KPIs

- **Primary:** checkout/cart **abandonment rate** ↓ (baseline ~70.2%); **order completion / conversion** ↑.
- **Funnel:** per-step drop-off (config → cart → signup → checkout → complete); **guest-checkout adoption**; coupon/domain-lookup engagement.
- **Quality:** mobile completion rate, form-error rate, time-to-complete; zero payment-path regressions; accessibility (WCAG 1.3.5/keyboard) pass.

### Confidence & Gaps (Implementation)

- **High:** the scaffold/rollout mechanics; tooling fit (standalone CLI, no Node); the conversion-UX roadmap (Baymard/NN/g); risk register.
- **Gaps:** implementation guidance reuses Steps 2–4 sources + `project-context.md` rather than new web searches; effort is qualitative (no hour estimates — needs a per-`.pdt` count once a target style is chosen); upgrade-survival of a custom pack should be proven against an actual order-plugin upgrade before committing.

<!-- Content will be appended sequentially through research workflow steps -->

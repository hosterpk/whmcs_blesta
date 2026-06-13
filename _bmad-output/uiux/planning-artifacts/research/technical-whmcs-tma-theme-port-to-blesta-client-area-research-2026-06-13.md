---
stepsCompleted: [1, 2, 3, 4, 5]
inputDocuments: ['_bmad-output/project-context.md', '_bmad-output/uiux/planning-artifacts/research/technical-blesta-client-template-modernization-research-2026-06-13.md']
workflowType: 'research'
lastStep: 5
research_type: 'technical'
research_topic: 'WHMCS tma (Twenty-One) Theme Port to Blesta Client Area'
research_goals: 'Assess feasibility and difficulty of achieving consistent HEADER/FOOTER/NAV chrome between a WHMCS-as-CMS public site (hosterpk.com, tma/Twenty-One theme) and a separately-hosted Blesta client area (hosterpk.com/dashboard or dashboard.hosterpk.com), so the cross-app transition is seamless/professional. Per-surface pages are expected to follow via shared CSS and be redesigned per UX; chrome parity is the priority. Evaluated across the three architecture options (re-skin / PE-skin with Alpine+HTMX / headless SPA).'
user_name: 'Israr'
date: '2026-06-13'
web_research_enabled: true
source_verification: true
---

# Research Report: technical

**Date:** 2026-06-13
**Author:** Israr
**Research Type:** technical
**Product/Project:** uiux (tma-port track)

---

## Research Overview

This is **Track 3** of the `uiux` initiative. Tracks 1–2 established how to modernize the Blesta client *dashboard* and *order form*. This track answers a more specific question: **can we reuse the look-and-feel of HosterPK's existing WHMCS Smarty theme ("tma") on the Blesta client area, and how hard is it** — assessed against the three architecture options from the [client-template study](./technical-blesta-client-template-modernization-research-2026-06-13.md): (A) re-skin, (B) progressive-enhancement skin (Alpine + HTMX), (C) headless SPA.

**Source theme facts (verified on disk at `/home/hosterpk/public_html/clientarea/templates/tma/`):**

- `tma` is the **WHMCS "Twenty-One" theme** (`theme.yaml`: name "Twenty-One", "The Default Theme for WHMCS 2021", author WHMCS Limited), **customized** by HosterPK (`custom/`, `tma-pages/`, `store/`, branded assets).
- Declared stack: **Bootstrap 4.5.3, jQuery 1.12.4, FontAwesome 5.10.1**; SCSS build present (72 `.scss`, a `node_modules/`).
- Engine: **WHMCS Smarty** — **887 `.tpl` files** spanning the full client area (home, products, domains, invoices, details, SSL config, affiliates, registration, etc.).

**The pivotal feasibility fact:** Blesta's `bootstrap` client template is **Bootstrap 4.6** (Track-1 finding); `tma` is **Bootstrap 4.5.3** — the *same framework generation*. The visual layer (SCSS/CSS/components/markup classes) is therefore **highly portable**; the cost is concentrated in **engine translation (Smarty `.tpl` → Blesta `.pdt`)** and **data re-binding** to Blesta's controller→view contract — not in fighting framework differences.

**Constraints carried in** (`project-context.md`): keep `.pdt` thin, **no second view engine** (so WHMCS's Smarty cannot be ported as an engine — only the design can), client template lives at `app/views/client/<name>/`, `.pdt` is editable plain PHP.

### Scope refinement (from user, 2026-06-13)

The deployment model is **two separate apps sharing one brand**: WHMCS becomes a **CMS** for the public site (`hosterpk.com`, clients removed, `tma` theme retained), and the **Blesta client area is hosted separately** at `hosterpk.com/dashboard` **or** `dashboard.hosterpk.com`. The **dominant concern is chrome parity** — the **header / footer / nav (logo placement, fonts, spacing)** must look identical across both apps so the cross-app transition feels seamless and professional. **Per-surface pages** (dashboard, products, invoices) are expected to *mostly match automatically via shared CSS* and will be **redesigned per the UX phase** regardless — so exact per-surface 1:1 parity is explicitly **not** the goal, and the 887-`.tpl` full-port effort is **out of scope**. This narrows the study to: (1) achieving pixel-consistent shared chrome across two separately-hosted apps, (2) the cross-origin/asset-sharing implications of the subdomain-vs-subdirectory choice, and (3) eliminating the specific glitch classes (font mismatch/FOUT, logo shift, spacing/box-model, Bootstrap/jQuery version drift).

**Methodology:** current web sources with multi-source verification for recommendation-driving claims; reuses verified cross-cutting findings from Track 1 (Blesta version landscape, `.pdt` engine, theme channel, controller→view contract). Confidence flagged where thin (6.0.0-b1 beta; WHMCS theme licensing).

---

## Technical Research Scope Confirmation

**Research Topic:** WHMCS `tma` (Twenty-One) Theme Port to Blesta Client Area — **chrome-first**
**Research Goals:** Achieve consistent header/footer/nav chrome between a WHMCS-as-CMS public site (`hosterpk.com`, `tma` theme) and a separately-hosted Blesta client area (`hosterpk.com/dashboard` or `dashboard.hosterpk.com`), so the cross-app transition is seamless; per-surface pages follow via shared CSS and are redesigned per UX.

**Technical Research Scope:**

- Chrome anatomy — WHMCS Twenty-One header/footer/nav markup+CSS vs Blesta `structure.pdt`; what lifts cleanly given the BS4.5↔4.6 match
- Cross-app consistency strategy — static replication vs shared CSS/asset bundle vs server-side include; drift management
- Deployment topology — subdirectory (same-origin) vs subdomain (cross-origin: font CORS, cookies)
- Glitch elimination — font/FOUT, logo shift, spacing/box-model, Bootstrap/jQuery version drift
- Architecture options (A/B/C) — how chrome parity lands under each (largely orthogonal)
- Effort/difficulty grading — focused on chrome, not full per-surface port

**Out of scope:** full 887-`.tpl` translation; exact per-surface 1:1 parity.

**Scope Confirmed:** 2026-06-13

---

## Technology Stack Analysis

> **Revision (verified on disk):** the chrome you actually want to match is **not** stock WHMCS Twenty-One. `tma/header.tpl` branches on `{if strstr($templatefile, "tma")}` into a **custom premium chrome** (`tma-pages/tma-head.tpl` + `tma-pages/menu.tpl`) that is a **Spruko Bootstrap *5* admin template** — markup uses BS5 logical utilities (`me-2`, `px-0`), `data-bs-toggle`, **Bootstrap Icons** (`bi bi-*`), an **SVG brand logo** (`{$brand_key}_white.svg`, 180px), a sticky header + topbar + horizontal menu, and multi-brand hreflang (hosterpk / intohost .com/.in/.ae). `theme.yaml`'s "Bootstrap 4.5.3 / jQuery 1.12.4" describes the **Twenty-One fallback base**, not the live chrome. _So the chrome target is Bootstrap **5**, while Blesta is Bootstrap **4.6** — a framework-generation mismatch that is the direct cause of the glitches you described._ _(Confidence: High — on-disk `tma-pages/`.)_

### Chrome Stacks, Side by Side

| Aspect | WHMCS `tma` custom chrome (target) | Blesta `bootstrap` template (current) |
|---|---|---|
| CSS framework | **Bootstrap 5** (Spruko `styles.css` + `bootstrap.min.css`) | **Bootstrap 4.6** |
| Icons | **Bootstrap Icons** (`bi bi-*`) | FontAwesome 5 |
| Logo | **SVG**, brand-keyed, fixed width | image |
| Fonts | **self-hosted** (Spruko webfonts, Bootstrap Icons woff2) | self-hosted (FontAwesome) |
| JS | vanilla BS5 + jQuery 1.12.4 (Twenty-One base) | jQuery + BS4 plugins |
| Chrome pieces | topbar (phone/email/KB/contact), sticky `main-header`, sidemenu toggle, horizontal menu, footer | `structure.pdt` header + `$nav` foreach + footer |

### Why the chrome mismatches glitch (the root cause of your exact concern)

A BS5-authored header dropped into a BS4.6 app misrenders in precisely the ways you named — "logo moved a few px / font not matching" — because of concrete breaking changes _([getbootstrap.com/docs/5.3/migration](https://getbootstrap.com/docs/5.3/migration/), Confidence: High)_:

- **Logical-utility renames** — BS5 `me-*`/`ms-*`/`ps-*`/`pe-*` **do not exist** in BS4.6 (which uses `mr-*`/`ml-*`), so that horizontal spacing collapses to zero → **logo/nav shift**. This is the literal mechanism behind "logo moved slightly."
- **Container gutters 24px (BS5) vs 30px (BS4)** + slightly different `.container` max-widths → **logo x-offset differs**.
- **Reboot differences** — BS5 sets `<body>` `font-weight`/`color`/`line-height` and `:root` variables BS4.6 doesn't → **font weight/spacing drift** (your "font not matching").
- **`data-bs-toggle` vs `data-toggle`** → the BS5 hamburger/menu JS **won't fire** under BS4.6.
- **Navbar now requires an inner container; `.active` moves to `.nav-link`; jQuery dropped.**

**Implication:** to match the chrome cleanly you either (1) **move the Blesta template to BS5** (which is *already* the Track-1 recommendation), or (2) ship the header/footer as a **self-contained, scoped/namespaced BS5 CSS bundle** that doesn't rely on or collide with Blesta's BS4.6 utilities. Hand-translating BS5 classes to BS4.6 inline is fragile and not advised.

### Cross-Origin Asset & Font Facts (decisive for subdomain vs subdirectory)

- **Cross-origin web fonts and icon fonts require CORS.** A font served from another origin without `Access-Control-Allow-Origin` will **200 OK but silently not render** — the element falls back to a system font (and **Bootstrap Icons glyphs render as blank tofu boxes**). The decision uses the *document's* origin, not the stylesheet's. _([developer.mozilla.org/.../CORS](https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS), Confidence: High.)_
- **Best practice: self-host identical font + icon-font files in each app** (sidesteps CORS entirely; one extra copy of a few woff2 is trivial), with **byte-identical `@font-face`** declarations.
- **FOUT/FOIT parity:** use the **same `font-display`** in both apps, **preload** the header/logo font with the **mandatory `crossorigin`** attribute (even same-origin), and prefer the **SVG logo** so the brand mark is immune to font swap. _([web.dev/font-display](https://web.dev/font-display/), Confidence: High.)_
- **Bootstrap Icons ≠ FontAwesome** — not interchangeable (different prefixes/glyphs). Blesta must **also load Bootstrap Icons** to render the chrome's `bi bi-*` icons; it's standalone (no BS CSS dependency) and coexists with FontAwesome. SVG-sprite icons avoid the icon-font CORS issue entirely. _([icons.getbootstrap.com](https://icons.getbootstrap.com/), Confidence: High.)_

### Deployment Topology Stack

| | `hosterpk.com/dashboard` (subdir, **same-origin**) | `dashboard.hosterpk.com` (**subdomain, cross-origin**) |
|---|---|---|
| Shared fonts/CSS | **no CORS** — load freely | **font CORS required** (or self-host in each) |
| Session/SSO | same-origin cookies "just work" | needs `Domain=.hosterpk.com; SameSite=None; Secure` |
| Routing | reverse-proxy `/dashboard` → Blesta (forward `Host`/`X-Forwarded-*`; cookie `Secure` flag care) | DNS record only — simplest |
| SEO/cert | one cert; consolidates (moot — billing is `noindex`) | wildcard/SAN cert |

_Recommendation leans **subdirectory/same-origin** for the seamless feel (kills CORS + cookie friction), accepting reverse-proxy upkeep; subdomain + parent-domain cookie + wildcard cert is the pragmatic fallback. Sources: [DWS](https://www.digitalwebsolutions.com/blog/subdomain-vs-subdirectory-which-is-better-for-seo/), [nginx reverse-proxy cookies](https://mickeyabhi1999.medium.com/using-nginx-reverse-proxy-to-set-cross-site-cookies-for-your-web-app-7c9e5e502091). Confidence: High._

### Candidate Chrome-Sharing Strategies

1. **Static replication + shared CSS bundle (recommended hybrid)** — replicate the (rarely-changing) chrome *markup* into Blesta `structure.pdt`, but have both apps load **one versioned shared CSS bundle + identical logo/fonts/Bootstrap-Icons** from a common URL (ideally same-origin), expressed as design tokens. Kills most drift; keeps apps independently deployable; no runtime coupling. _([martinfowler.com/micro-frontends](https://martinfowler.com/articles/micro-frontends.html), [design tokens](https://www.uxpin.com/studio/blog/what-are-design-tokens/).)_
2. **SSI/edge-include or fetch-inject the header** — single source of truth for the *markup* too, but adds runtime coupling + a failure mode; only worth it if the header markup changes often.
3. **Web component / micro-frontend chrome** — over-engineered for a two-app parity goal; skip unless you scale to many apps.

Both Blesta and WHMCS converge on the same vendor-blessed pattern: **clone the theme, never edit core, put custom styling in an override stylesheet** (Blesta `overrides.css` in `structure.pdt`; WHMCS `custom.css`) — which dovetails with strategy 1.

### jQuery Security Note (orthogonal but worth flagging)

`tma`'s Twenty-One base declares **jQuery 1.12.4 — EOL and unpatched** (CVE-2020-11022/11023 XSS, CVE-2019-11358 prototype pollution). Because the two apps are separate documents, there's **no functional cross-app conflict**, but the marketing site should upgrade jQuery to ≥3.5 independently. Don't load two jQuery versions into the *same* page. _([security.snyk.io/package/npm/jquery/1.12.4](https://security.snyk.io/package/npm/jquery/1.12.4), Confidence: High.)_

### Confidence & Gaps (Technology Stack)

- **High:** chrome is Spruko **BS5** (disk-verified); BS5↔BS4.6 breakage = the exact glitch mechanism; cross-origin font/icon CORS; FOUT/font-display parity; Bootstrap-Icons not interchangeable; topology trade-offs; jQuery 1.12.4 EOL.
- **Gaps:** exact Spruko overridden variables (`$font-size-root`, container max-widths, navbar paddings, `$font-family-base`) must be **read from the compiled `styles.css`** to guarantee per-pixel parity; the dynamic *logged-in* portion of the chrome (account menu/notifications) must map to Blesta's `$nav` (covered in Step 3); **WHMCS/Spruko theme licensing** for reuse on Blesta still to confirm; CSP `font-src` is a separate gate from CORS if either app sets CSP.

## Integration Patterns Analysis

This step maps the two chromes concretely and identifies what ports as static markup, what must re-bind to Blesta data, and how the chrome propagates across surfaces. Verified against both installs (`file:line`).

### Chrome Anatomy — `tma` (source) ↔ Blesta `structure.pdt` (target)

**Blesta `structure.pdt` regions (verified):**

| Region | Location | Note |
|---|---|---|
| Header gate | `:21` `if ($show_header ?? true)` | **header is suppressed on some pages** (login) — the gotcha to handle |
| Logo | `:23–38` `.header > .logo > a[base_uri]` → `$theme_logo` / `$blesta_logo` / fallback `logo-color.svg` | **logo is already theme-configurable** via `$theme_logo` (Themes UI) |
| Top-nav | `:51` `.top-nav` (language switcher `data-toggle="dropdown"`, staff-as-client badge) | BS4 dropdown |
| Primary nav | `:78–101` `.nav-content > nav.navbar.navbar-expand-md > ul.navbar-nav` → `foreach ($nav)` (nav-item/nav-link + dropdowns) | **dynamic** — built from `$nav` |
| Content | `:298/303` `echo $content` | page body slot |
| Footer | `.row.footer` … `</body>` | footer slot |

Nav data is set at `app/client_controller.php:76` → `setNavActive($this->Navigation->getPrimaryClient($this->client_uri))` → `$this->structure->set('nav', $nav)`.

**`tma` chrome pieces (verified):** a **topbar** (phone / email / KB / contact — static links), a sticky **`main-header`** with the **SVG brand logo** + sidemenu toggle, a **horizontal marketing megamenu** (`tma-pages/menu.tpl`, largely *static* links), and a **footer** (`tma-pages/footer.tpl`).

### The Static vs Dynamic Split (this is what makes the port tractable)

| Chrome piece | Port type | How it maps to Blesta |
|---|---|---|
| Topbar (phone/email/KB/contact) | **Static** — direct markup+CSS lift | hardcode or pull from Blesta company/config |
| Brand logo (SVG, 180px) | **Static** | drop the SVG in the template and/or set as `$theme_logo`; keep exact width |
| Marketing menu links | **Static** | lift markup; point links at the public site / dashboard sections |
| Footer | **Static** | lift markup+CSS |
| **Client-area primary nav** | **Dynamic — keep Blesta's `$nav`** | restyle the `ul.navbar-nav` markup to the Spruko look, but **preserve `foreach ($nav)`** — don't hand-author client links |
| Logged-in account menu / notifications | **Dynamic** | map to Blesta client session + `$nav` (secondary nav) |

**Takeaway:** the bulk of the chrome the user wants to match (topbar, logo, marketing menu, footer) is **static** → a markup + shared-CSS lift, which is the *easy* part. The only re-binding work is keeping Blesta's `$nav` data feeding a Spruko-styled `navbar-nav`.

### Smarty → PHP Chrome Translation (the actual edit surface)

The chrome's Smarty variables are dominated by a few cleanly-mappable kinds:

| `tma` (Smarty) | Count | Blesta `.pdt` equivalent |
|---|---|---|
| `{$WEB_ROOT}/templates/{$template}/…` | ~35 + ~31 | `<?php echo $this->view_dir; ?>…` (asset path) |
| `{$LANG.*_key}` (brand/region/contact keys) | ~25 | Blesta language files (`Language::_`) or hardcoded brand strings |
| `{$companyname}` | — | `$system_company->name` |
| SEO/OG/twitter/hreflang/`$pagecanonical` meta | ~20 | **drop** — marketing-only; a billing area is `noindex` |
| `{$token}` (CSRF) | 1 | Blesta CSRF token helper |

So chrome translation is mostly **swap asset-path + brand-key variables** and **delete marketing SEO meta** — not deep logic. _(Confidence: High — variable census from `tma-pages/`.)_

### Two Gotchas to Handle

- **`$show_header` on login pages** — Blesta suppresses the header on login/auth views (`structure.pdt:21`). Decide whether the branded chrome should appear on the dashboard login; force `show_header` (or a slimmed chrome) for parity.
- **`$theme_logo` vs hardcoded** — Blesta can serve the logo via the Themes UI (`$theme_logo`); using it keeps the logo swappable without template edits, but pin `client_logo_height`/width to match the 180px SVG exactly (a logo-height mismatch is a top "logo moved" cause).

### Cross-Track Propagation (a free win)

From the order-form study: **order template packs ship no `structure.pdt` and inherit the active client template's chrome.** Therefore, once the branded chrome lives in the client template's `structure.pdt`, **the order/checkout flow inherits the same header/footer automatically** — chrome consistency propagates from dashboard → checkout with no extra work. This links Track 3 to Tracks 1–2: do the chrome once, in `structure.pdt`, and it covers the dashboard *and* the order form. _(Confidence: High.)_

### Drift Management & Source of Truth

- Declare **one canonical chrome** (the shared CSS bundle + logo/fonts/Bootstrap-Icons) that both apps *consume*; neither forks it.
- Express color/type/spacing as **design tokens → CSS variables**, loaded by both; put per-app deltas in the vendor override files (Blesta `overrides.css`, WHMCS `custom.css`).
- Add a **header/footer visual-regression (pixel-diff) check** and audit periodically. _([overlayqa.com/blog/design-system-drift](https://overlayqa.com/blog/design-system-drift/), [percy.io](https://percy.io/blog/visual-regression-testing/).)_

### Chrome Integration Difficulty (graded)

| Piece | Difficulty | Why |
|---|---|---|
| Topbar / logo / footer / marketing menu (static) | **Easy** | markup + shared CSS lift; variable swaps only |
| BS5 chrome CSS coexisting with Blesta BS4.6 | **Medium** | scope/namespace the bundle, or move template to BS5 (Track-1 aligned) |
| Client-area nav restyle (keep `$nav`) | **Medium** | re-skin `navbar-nav` markup without breaking the data binding |
| Cross-origin fonts / icon parity | **Easy–Medium** | self-host identical fonts + Bootstrap Icons; preload + `crossorigin` |
| Pixel-exact parity (root size, container width, navbar height, line-height) | **Medium** | copy Spruko's overridden variables from compiled `styles.css` |
| Keeping the two in sync over time | **Medium (ongoing)** | shared CSS bundle + visual-diff + documented source of truth |

### Confidence & Gaps (Integration Patterns)

- **High (verified):** the `structure.pdt` region map (`$show_header`, `$theme_logo`, `$nav` foreach, content/footer slots); the static-vs-dynamic split; the dominant Smarty chrome variables and their Blesta equivalents; the order-form chrome inheritance (cross-track win).
- **Gaps:** the precise logged-in account-menu markup in `tma` (the public megamenu was sampled, not the authenticated WHMCS client nav — but on Blesta that surface is `$nav`-driven regardless); exact Spruko CSS variable overrides to copy for pixel parity; whether to render the logo via `$theme_logo` vs hardcode (a UX/architecture decision).

## Architectural Patterns and Design

The headline architectural insight: **chrome parity is largely *orthogonal* to the A/B/C choice.** Matching the header/footer is a CSS/markup-replication + asset-sharing problem solved the same way under re-skin, PE-skin, or SPA. The decisions that actually matter here are (1) **deployment topology**, (2) **chrome-sharing strategy**, and (3) **BS5 reconciliation** — with A/B/C only deciding how the *inner pages* are built.

### How chrome parity lands under each option

| Option | Chrome handling | Verdict |
|---|---|---|
| **(A) Re-skin (BS5)** | Port chrome markup + shared CSS into `structure.pdt`; chrome renders natively on a BS5 template | Works; chrome is the easy part |
| **(B) PE-skin (BS5 + Alpine/HTMX)** | Same chrome port; islands enhance inner pages only | **Best fit** — chrome identical to (A), and it's the Track-1 recommended architecture |
| **(C) Headless SPA** | SPA must *still* replicate the same chrome (header/footer) around the app shell | Chrome no easier; the rest is worse (per Track 1) |

→ The chrome work is **the same** under A and B; C buys nothing for chrome and loses elsewhere. Since Track 1 already recommends **B (PE-skin on BS5)**, that choice also delivers chrome parity natively (BS5 chrome on a BS5 template, no scoping gymnastics).

### Decision 1 — BS5 reconciliation (the pivotal one)

The chrome is **Bootstrap 5**; Blesta is **4.6**. Two viable architectures:

- **(i) Move the Blesta client template to BS5** *(recommended — aligns with Track 1)* — the Spruko chrome renders natively, no namespacing, and the whole template modernizes once. Cost: the BS4.6→5 migration (Track-1 scoped).
- **(ii) Ship the chrome as a self-contained, scoped/namespaced BS5 CSS bundle** on the existing BS4.6 template — lower up-front cost, but you carry two Bootstraps and must prevent class collisions (`.container`/`.row`/`.btn`). A reasonable *interim* if the full BS5 move lags.

Do **not** hand-translate BS5 classes to BS4.6 inline (fragile; many BS5 utilities/vars have no 4.6 analog).

### Decision 2 — Deployment topology

- **Subdirectory `hosterpk.com/dashboard` (same-origin)** — *preferred for seamlessness*: no font-CORS, cookies/session "just work", SEO consolidates. Cost: reverse-proxy config (forward `Host`/`X-Forwarded-*`, careful `Secure` cookie flag).
- **Subdomain `dashboard.hosterpk.com` (cross-origin)** — simpler routing (DNS only), but needs **font CORS** (or self-hosted fonts in each app), a parent-domain cookie (`Domain=.hosterpk.com; SameSite=None; Secure`), and a wildcard/SAN cert.

Either works; same-origin removes the most failure modes. Self-hosting identical fonts in both apps makes the topology choice *less* coupled to chrome correctness.

### Decision 3 — Chrome-sharing strategy

- **Static replication + shared versioned CSS/asset bundle (recommended hybrid)** — replicate the (rarely-changing) chrome *markup* into each app's template (`structure.pdt` / WHMCS `header.tpl`/`footer.tpl`); both load **one shared, versioned CSS bundle + identical logo/fonts/Bootstrap-Icons** (design tokens). Independently deployable, no runtime coupling, kills most drift.
- **SSI/edge-include or fetch-inject** — single source of truth for markup too, but runtime coupling + a failure mode; only if header markup changes often.
- **Web component / micro-frontend** — over-engineered for two apps; skip.

### Design Principles (chrome parity)

- **One chrome source of truth** — both apps *consume* a shared CSS/asset bundle; neither forks it. Per-app deltas only in `overrides.css` / `custom.css`.
- **Self-host identical fonts + Bootstrap Icons** with byte-identical `@font-face`, matched `font-display`, `crossorigin` preload; **SVG logo** pinned to exact dimensions.
- **Pin the pixel-drivers** — copy Spruko's overridden `$font-size-root`, container max-width, navbar padding, `line-height`, `box-sizing` so the logo/nav sit identically.
- **Keep the data contract** — restyle `ul.navbar-nav` but preserve Blesta's `foreach ($nav)` and `Html->safe()`.
- **Do the chrome once, in `structure.pdt`** — it propagates to the order form for free (cross-track).
- **Drift control** — versioned tokens + header/footer visual-regression diff + documented ownership.

### Security & Deployment Architecture

- **Same-origin (subdir)** inherits cookies/session cleanly and avoids CORS; **cross-origin (subdomain)** adds a font-CORS + parent-cookie + (if CSP) `font-src` surface to manage.
- Reverse-proxy correctness (forwarded headers, `Secure` flag) is the main same-origin operational risk; a wildcard cert + DNS is the main subdomain setup.
- Handle the **`$show_header` login-page** suppression so the branded chrome (or a slim variant) appears consistently.

### Recommended Target Architecture (preliminary — finalized in Synthesis)

**Topology:** `hosterpk.com/dashboard` (same-origin via reverse proxy) if proxy upkeep is acceptable; else `dashboard.hosterpk.com` with parent-domain cookie + wildcard cert. **Either way, self-host identical fonts + Bootstrap Icons in both apps.**
**Framework:** move the Blesta client template to **Bootstrap 5** (Track-1 aligned) so the Spruko chrome renders natively; scoped-BS5-bundle is the interim fallback.
**Chrome:** replicate the static chrome (topbar/logo/menu/footer) markup into `structure.pdt`, driven by **one shared, versioned CSS/asset bundle** (design tokens); keep Blesta's `$nav` feeding a Spruko-styled `navbar-nav`; pixel-pin logo + Spruko CSS variables; **build it once in `structure.pdt`** so dashboard + order form inherit it.
**Inner pages:** per **Track-1 (B) PE-skin** — redesigned per UX, not 1:1 ported.
_(Final recommendation, sequencing, risk register in Step 6.)_

### Confidence & Gaps (Architecture)

- **High:** chrome parity is orthogonal to A/B/C; B+BS5 is the cleanest fit; the three cross-app decisions and their trade-offs; the do-chrome-once propagation.
- **Gaps:** the BS5-move vs scoped-bundle choice depends on Track-1 sequencing (when/if Blesta goes BS5); reverse-proxy specifics for this cPanel host not validated; the WHMCS/Spruko **licensing** question for reusing the chrome on Blesta remains open.

## Implementation Approaches and Technology Adoption

This turns the chrome-parity architecture into a concrete plan. It reuses Steps 2–4 sources plus `project-context.md`; the focus is the **header/footer**, not a full per-surface port.

### Technology Adoption Strategy (chrome-port sequence)

1. **Extract the Spruko chrome assets** from `tma/tma-pages/` + `tma/assets/` — the compiled `styles.css` / `bootstrap.min.css`, Bootstrap-Icons font, self-hosted webfonts, and the **SVG brand logo**. Record Spruko's overridden Bootstrap variables (`$font-size-root`, container max-widths, navbar padding, `$font-family-base`, `line-height`) from the compiled CSS — these are the pixel-drivers.
2. **Stand up a shared, versioned CSS/asset bundle** both apps load from one (ideally same-origin) URL; express color/type/spacing as design tokens → CSS variables. Self-host identical fonts + Bootstrap Icons in each app to sidestep CORS.
3. **Resolve BS5** — preferably as part of the Track-1 BS5 template move (chrome renders natively); otherwise ship the chrome as a **scoped/namespaced BS5 bundle** on the BS4.6 template (interim).
4. **Port the static chrome** (topbar/logo/menu/footer) into Blesta `structure.pdt`, translating Smarty → PHP (`{$WEB_ROOT}/templates/{$template}` → `$this->view_dir`; `{$LANG.*_key}` → language/config; `{$companyname}` → `$system_company->name`; drop SEO/OG meta).
5. **Keep `$nav`** feeding a Spruko-styled `navbar-nav`; handle `$show_header` so login shows the branded (or slim) chrome.
6. **Wire topology** — reverse-proxy `/dashboard` (same-origin) or DNS+wildcard-cert subdomain with parent-domain cookie.
7. **Pixel-pin & verify** — match logo width/height, container width, navbar height, root font-size, line-height; overlay/pixel-diff against the live `hosterpk.com` header.

### Development Workflows and Tooling

- **CSS:** build the shared bundle with the Tailwind/Sass toolchain Track 1 chooses; **standalone CLI** (no repo-wide Node app). Keep Blesta custom styling in `overrides.css` (appended in `structure.pdt`), WHMCS in `custom.css`.
- **Project rules** (`project-context.md`): keep `.pdt` thin, no second view engine, client template under `app/views/client/<name>/`, don't hand-edit `*.min.*`, run `php -l` on touched PHP.
- **Smarty→PHP** chrome translation is a variable-swap job (Step 3 census) — mechanical, low-risk.

### Testing and Quality Assurance (the right tools for chrome)

- **Visual-regression / pixel-diff** is the primary QA here — screenshot the Blesta header/footer vs the `hosterpk.com` header at each breakpoint; flag pixel deltas. This directly tests the "logo moved / font not matching" concern.
- **Font-load check** — confirm fonts + Bootstrap-Icons render (no tofu/FOUT) on the chosen topology (esp. cross-origin if subdomain); verify `crossorigin` preload.
- **Cross-app session/cookie smoke** — log in, navigate site↔dashboard, confirm session persists (same-origin) or parent-cookie works (subdomain); reverse-proxy header/`Secure`-flag check.
- **Login-page chrome** — verify `$show_header` handling renders the intended chrome.
- **Order-form inheritance** — confirm the order flow picks up the new chrome (cross-track propagation).

### Deployment and Operations Practices

- **Subdirectory:** reverse-proxy `/dashboard` → Blesta (forward `Host`/`X-Forwarded-*`; set `Secure` cookie only on HTTPS). **Subdomain:** DNS record + wildcard/SAN cert + `Domain=.hosterpk.com; SameSite=None; Secure` cookie; if CSP is set, allowlist `font-src`.
- **Deploy = plain files** (`structure.pdt`, shared CSS bundle, fonts, SVG) into the Blesta template dir — no new services.
- **Drift control in ops:** version the shared bundle; re-run the header/footer visual-diff whenever either app's chrome changes; document the canonical chrome owner.

### Team Organization and Skills

- One front-end-capable dev: **Blesta `.pdt`/`structure.pdt` literacy, BS5 + Bootstrap Icons, CSS scoping/tokens, and basic reverse-proxy/DNS/cert ops.** No SPA/Node-app competency required.

### Cost Optimization and Resource Management

- **Low and bounded** — chrome is mostly static; cost is the BS5 reconciliation (shared with Track 1) + one-time topology setup. No new infra/licenses (aside from confirming theme reuse rights — see risk).

### Risk Assessment and Mitigation

| Risk | Likelihood | Mitigation |
|---|---|---|
| BS5↔BS4.6 class collision (logo/nav glitch) | High if unscoped | Move template to BS5 *or* scope/namespace the chrome bundle |
| Cross-origin font/icon failure (tofu) | Med (subdomain) | Self-host identical fonts in each app; CORS + `crossorigin` preload if shared |
| Logo/font px drift | Med | Pin logo dims + Spruko CSS vars; SVG logo; visual-diff gate |
| Chrome drift over time | Med (ongoing) | One shared versioned bundle; documented owner; periodic visual-diff |
| **WHMCS/Spruko theme licensing on Blesta** | Unknown | **Confirm reuse rights before shipping** — see gaps |
| Login-page header missing | Low | Handle `$show_header` explicitly |
| Reverse-proxy session/cookie loops | Med (subdir) | Correct forwarded headers + `Secure` flag |

## Technical Research Recommendations

### Implementation Roadmap (chrome-first)

1. **Confirm licensing** to reuse the Spruko/Twenty-One chrome on Blesta (gate).
2. **Decide topology** (subdir same-origin preferred) and stand it up.
3. **Extract chrome assets + Spruko CSS variables**; build the shared versioned bundle; self-host fonts + Bootstrap Icons.
4. **BS5**: move the Blesta template to BS5 (with Track 1) or ship the scoped chrome bundle.
5. **Port chrome into `structure.pdt`** (static lift + Smarty→PHP swaps; keep `$nav`; handle `$show_header`).
6. **Pixel-pin + visual-diff** until header/footer match; confirm order-form inheritance.
7. **Lock drift control** (shared bundle ownership + CI visual-diff).

### Technology Stack Recommendations

- **Framework:** Blesta template → **Bootstrap 5** (Track-1 aligned). **Icons:** add **Bootstrap Icons** (coexist with FontAwesome). **Fonts:** self-hosted, identical `@font-face`, `font-display` matched, `crossorigin` preload. **Logo:** SVG, dimension-pinned. **Chrome CSS:** one shared versioned bundle (tokens) consumed by both apps. **Topology:** same-origin subdirectory preferred.

### Skill Development Requirements

- Short ramp on **BS5 + Bootstrap Icons + CSS scoping/tokens**; basic **reverse-proxy / cookie-domain / cert** ops; reading Spruko's compiled CSS to lift exact variable values.

### Success Metrics and KPIs

- **Primary:** header/footer **pixel parity** across `hosterpk.com` ↔ dashboard (visual-diff delta ≈ 0); **zero logo-shift / font-mismatch** at all breakpoints.
- **No FOUT/tofu**; fonts + icons render first paint. **Seamless cross-app session** (no re-login). **Order-form chrome** matches automatically. **Low drift** over releases (visual-diff stays green).

### Confidence & Gaps (Implementation)

- **High:** the chrome-port sequence; visual-regression as primary QA; self-host-fonts + BS5 + Bootstrap-Icons stack; topology setup; risk register.
- **Gaps:** **theme licensing** (the one true blocker to resolve first); exact Spruko CSS variable values to be read from compiled `styles.css`; reverse-proxy specifics for this cPanel host; effort is qualitative (no hour estimates); implementation guidance reuses Steps 2–4 sources rather than new web searches.

<!-- Content will be appended sequentially through research workflow steps -->

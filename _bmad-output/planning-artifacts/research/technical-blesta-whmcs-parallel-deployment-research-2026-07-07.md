---
stepsCompleted: [1, 2, 3, 4, 5, 6]
inputDocuments: []
workflowType: 'research'
lastStep: 6
research_type: 'technical'
research_topic: 'Running Blesta staging (beta.hosterpk.com) in parallel with WHMCS on the same cPanel account, exposing Blesta at www.hosterpk.com/dashboard/ via symlink without breaking WHMCS or Blesta licensing'
research_goals: 'Determine feasibility of serving the Blesta staging install at production URLs (www.hosterpk.com/dashboard/client|admin) alongside the live WHMCS at public_html/clientarea; prefer a symlink approach to avoid Blesta license re-binding; identify .htaccess conflicts, pitfalls, and alternative methods for parallel operation until WHMCS is retired'
user_name: 'Israr'
date: '2026-07-07'
web_research_enabled: true
source_verification: true
---

# Research Report: technical

**Date:** 2026-07-07
**Author:** Israr
**Research Type:** technical

---

## Research Overview

This research assessed whether the Blesta staging install at `/home/hosterpk/beta.hosterpk.com` can be exposed at `https://www.hosterpk.com/dashboard/` (client + admin) alongside the live WHMCS at `public_html/clientarea`, preferably via symlink, until WHMCS is retired. Method: direct inspection and **live empirical testing on the production server** (temporary test symlink, origin-IP probes, LiteSpeed/cPanel/CloudLinux config reads, Blesta source-code reading — the license and routing code in this install is largely unencoded) combined with four parallel web-research passes over official Blesta/WHMCS/LiteSpeed/cPanel/CloudLinux documentation and community sources.

Headline: the symlink mechanism **works and was proven live on this server** — Blesta rendered correctly at a test path under `www.hosterpk.com` with WHMCS unaffected; no `.htaccess` conflict exists. However, the symlink only preserves the license's *path* binding; Blesta's license also validates the **request domain** per hit, so serving `www.hosterpk.com` requires a license-domain decision (reissue at flip — free and self-service — or a second-domain arrangement with Blesta). Two further plan-shaping facts: the WHMCS importer only targets a **fresh** Blesta install (no incremental import at cutover), and **6.0.0-b1 is vendor-designated non-production**. Full details and the recommended phased roadmap are in the Research Synthesis (executive summary) and Implementation sections below.

---

<!-- Content will be appended sequentially through research workflow steps -->

## Technical Research Scope Confirmation

**Research Topic:** Running Blesta staging (beta.hosterpk.com) in parallel with WHMCS on the same cPanel account, exposing Blesta at www.hosterpk.com/dashboard/ via symlink without breaking WHMCS or Blesta licensing

**Research Goals:** Determine feasibility of serving the Blesta staging install at production URLs (www.hosterpk.com/dashboard/client and /dashboard/admin) alongside the live WHMCS at public_html/clientarea; prefer a symlink approach so the Blesta license does not re-bind to a new install path/IP; identify .htaccess conflicts with the existing WHMCS rewrite rules at public_html/.htaccess; enumerate pitfalls and alternative methods for parallel operation until WHMCS is retired.

**Technical Research Scope:**

- Architecture Analysis - cPanel document roots, subdomain vs subdirectory serving, symlink resolution in Apache/LiteSpeed
- Implementation Approaches - symlink into public_html, Apache Alias/ProxyPass, addon-domain docroot remapping, reverse proxy
- Technology Stack - Blesta 6.0.0-b1 (PHP 8.3/ea-php83, ionCube 15), WHMCS, cPanel/CloudLinux (LVE kernel), Apache .htaccess rewrites
- Integration Patterns - URL routing coexistence, session/cookie scoping across two apps on one host, Blesta license validation behavior
- Performance Considerations - open_basedir/SymlinkProtect constraints, PHP handler selection per directory, cron and callback URL implications

**Research Methodology:**

- Direct inspection of the live server environment (.htaccess files, docroots, PHP handlers, cPanel configuration) as primary evidence
- Current web data with rigorous source verification for Blesta licensing and cPanel/Apache symlink behavior
- Multi-source validation for critical technical claims
- Confidence level framework for uncertain information

**Scope Confirmed:** 2026-07-07 (YOLO mode — scope auto-confirmed from user brief)

## Technology Stack Analysis

All facts in this section were verified by direct inspection of the live server on 2026-07-07 (primary evidence, confidence: High).

### Applications

- **Blesta 6.0.0-b1** (staging) at `/home/hosterpk/beta.hosterpk.com` — ionCube-15-encoded core, minPHP MVC routing, front controller via `.htaccess` rewrite to `index.php`. `config/routes.php` sets no explicit `Routes.WebDir`; web directory is auto-detected per request (empirically confirmed below).
- **WHMCS** (production) at `/home/hosterpk/public_html/clientarea` — also serves the marketing site through `.htaccess` rewrites into `/clientarea/tma/*.php`.
- Blesta company 1 hostname is currently **`sec.hosterpk.com`** (queried from the `companies` table), a near-empty subdomain whose own `.htaccess` blanket-301s everything to `https://www.hosterpk.com/` — i.e. the configured canonical hostname is stale and will need to change at cutover.

### Web/Server Stack

- **LiteSpeed Web Server Enterprise** is the live listener on ports 80/443 (confirmed via `ss -tlnp`); it consumes the cPanel/Apache `httpd.conf` vhosts. Apache PHP-FPM vhost config exists but is not the active server.
- **cPanel vhosts:** `hosterpk.com` (+ `www`, `mail` aliases) → docroot `/home/hosterpk/public_html`; `beta.hosterpk.com` → docroot `/home/hosterpk/beta.hosterpk.com` (a sibling of `public_html`, not inside it).
- **PHP:** `ea-php83` handler declared in *both* docroots' `.htaccess` files — identical runtime either way (required by the ionCube 15 Blesta core).
- **CloudLinux** (LVE kernel 4.18.0-...lve...) with CageFS; no `open_basedir` restrictions found in the account's PHP selector config.
- **LiteSpeed symlink policy** (`/usr/local/lsws/conf/httpd_config.xml`): `checkSymbolLink=0` (symlinks are followed without per-request checking) and `forceStrictOwnership=1` (served files must belong to the vhost user — both directories are owned by `hosterpk`, so this passes).
- **Edge:** both `www.hosterpk.com` and `beta.hosterpk.com` resolve to Cloudflare (172.66.x.x); a BitNinja WAF nginx runs on the box and 403s non-Cloudflare loopback probes (origin-IP probes work).

### The Existing Symlink Precedent

`beta.hosterpk.com/dashboard/...` already works via a **self-referencing symlink**: `/home/hosterpk/beta.hosterpk.com/dashboard -> /home/hosterpk/beta.hosterpk.com`. The proposed production mechanism is the identical pattern, one docroot over: `/home/hosterpk/public_html/dashboard -> /home/hosterpk/beta.hosterpk.com`.

### Empirical Proof (temporary test symlink, since removed)

A temporary symlink `/home/hosterpk/public_html/dash-linktest-x7q9 -> /home/hosterpk/beta.hosterpk.com` was created and probed against the origin IP with Host `www.hosterpk.com`:

| Probe | Result |
|---|---|
| `/dash-linktest-x7q9/client/login/` | **200** — Blesta client login rendered; every asset URL correctly auto-prefixed with `/dash-linktest-x7q9/` (webdir auto-detection works) |
| `/dash-linktest-x7q9/admin/login/` | **200** — admin login rendered |
| `/dash-linktest-x7q9/app/views/.../font-awesome.min.css` | **200** — static assets served through the symlink |
| `/dash-linktest-x7q9/config/blesta.php` | **403** — Blesta-side `.htaccess` deny rules apply through the symlink |
| `/clientarea/clientarea.php` (WHMCS) | **302** (normal login redirect) — WHMCS unaffected |
| Blesta forced-hostname redirect | none observed — no redirect away from `www.hosterpk.com` |

The test symlink was removed after the probes. Conclusion: at the web-server layer the symlink method is **proven working on this exact server**, with no WHMCS interference.

### WHMCS `.htaccess` Compatibility Review

Full read of `/home/hosterpk/public_html/.htaccess` (~16 KB):

- **No existing rule references `dashboard`** — no collision with the proposed prefix.
- All WHMCS/marketing rewrites are anchored to specific prefixes (`clientarea/...`, `kb/...`, `promo/...`, marketing slugs) or exact matches — none can capture `/dashboard/...`.
- The WP Fastest Cache catch-all `RewriteRule ^(.*) /wp-content/cache/all/$1/index.html` is guarded by `RewriteCond ... -f` (cached file must exist), so it cannot hijack `/dashboard` paths.
- mod_rewrite per-directory semantics: the WHMCS `.htaccess` does **not** set `RewriteOptions Inherit`, so for `/dashboard/*` requests the deepest `.htaccess` (Blesta's own, reached through the symlink) fully replaces the parent's rewrite ruleset. The `RewriteOptions Inherit` occurrences in `httpd.conf` are only cPanel's "Global DCV Rewrite Exclude" blocks in vhost context (AutoSSL), not `.htaccess`-level inheritance.
- mod_alias directives at the parent (`Redirect`/`RedirectMatch 301 ...`) *do* apply to all URIs regardless of subdirectory rewrites, but every pattern present is anchored to non-`/dashboard` paths (`^/clientarea/$`, `^/dc$`, `/blog/...`, etc.) — no match, no effect.
- Non-rewrite parent directives that will cascade into `/dashboard`: `Options -Indexes`, mod_expires/mod_deflate caching headers, and the `ea-php83` handler — all harmless or identical to Blesta's own settings.

_Sources: direct server inspection — `/home/hosterpk/public_html/.htaccess`, `/home/hosterpk/beta.hosterpk.com/.htaccess`, `/etc/apache2/conf/httpd.conf`, `/var/cpanel/userdata/hosterpk/*`, `/usr/local/lsws/conf/httpd_config.xml`, live curl probes against origin 45.79.180.220._

## Integration Patterns Analysis

This section covers how the two applications, the web server, the license system, and external services (payment gateways, email) interoperate when one Blesta install answers at two base URLs.

### Blesta License Validation — the Critical Integration Point

Verified against the **unencoded source in this install** (`app/models/license_manager.php`) plus official docs — Confidence: High.

- The license validates exactly **three parameters: domain, IP, and install path** (`validate()`, lines 189–218). A mismatch on *any* one yields status `invalid_location` ("The license is invalid for this domain, IP, or directory path. Request reissue"). Each field may hold multiple values or a `'*'` wildcard on the license server side.
- **Domain** = the live request's `$_SERVER['SERVER_NAME']`, lowercased, with only a leading `www.` stripped (`getServerInfo()`, lines 305–347). On cron/CLI it falls back to the **configured company hostname**. Requests arriving at `www.hosterpk.com/dashboard/` therefore present domain `hosterpk.com` — a different value than `beta.hosterpk.com`.
- **Path** = PHP's symlink-resolved `dirname(__FILE__)` — **a symlink does not change the path Blesta reports** (PHP resolves `__FILE__` to the real path). The path leg passes unchanged. The user's premise ("symlink avoids re-binding") is therefore true for the *path* leg but does not address the *domain* leg, which is checked independently and first.
- **IP** = `SERVER_ADDR`; both vhosts share 45.79.180.220 — no issue.
- The install **phones home with whatever location it currently sees** (`requestData()`, lines 250–268), so serving two hostnames can flap the registered binding or park the install in `invalid_location` for the unlicensed hostname. Blesta publicly exposes a per-domain license-verification tool and invites piracy reports for unauthorized domains (https://account.blesta.com/client/plugin/license_verify/).
- **Reissue** is self-service and advertised as unlimited ("Re-issue license anytime", https://www.blesta.com/pricing/): account.blesta.com → Manage License → Re-Issue (https://docs.blesta.com/support/moving-blesta/). Reissue **moves** the binding; it does not add a second domain. The supported multi-hostname mechanism is the multi-company addon ($5/mo or $95 owned per extra company, hostnames registered to the license; https://docs.blesta.com/display/user/Creating+Companies).
- Latent local inconsistency: web requests report `beta.hosterpk.com` but **cron check-ins report the configured company hostname `sec.hosterpk.com`** (stale, blanket-301s to www). Which domain the license is actually issued for should be confirmed at account.blesta.com before any change.

_Sources: `app/models/license_manager.php` (primary, this install), https://docs.blesta.com/support/moving-blesta/, https://www.blesta.com/pricing/, https://docs.blesta.com/developers/resellers/reseller-api/, https://docs.blesta.com/integrations/modules/blesta-license/ — all official; confidence High._

### URL Generation, Webdir, and Multi-Hostname Behavior

Verified against this install's unencoded source — Confidence: High.

- **`WEBDIR` is computed per request, never stored** (`core/ServiceProviders/MinphpBridge.php:124-148`, `WEBDIR = dirname($_SERVER['SCRIPT_NAME'])`). The same install self-adjusts: `/` on beta, `/dashboard/` on www. `Routes.WebDir` in `config/routes.php` is not consulted by the 6.x bridge at all. This is why the empirical test's asset paths were all correctly prefixed.
- **Blesta never force-redirects to the configured company hostname** — no such logic exists in the unencoded dispatch path, and the empirical probe confirmed no redirect. Any Host reaching the docroot serves the (single) company.
- **The stored company hostname is a link-generation value**: email tags `{base_uri}`/`{client_uri}` = configured hostname + *request-time* WEBDIR (`app/models/emails.php:60-62`); **cron-generated links** use configured hostname + a path derived from the `root_web_dir` system setting — cron emails can only ever point at ONE canonical URL. Order-form URLs and data feeds also use the stored hostname.
- **Symlink-specific canonical-link gap:** under the symlink, the install's real filesystem path (`/home/hosterpk/beta.hosterpk.com/`) is not inside `public_html`, so cron's `ROOTWEBDIR − root_web_dir` subtraction cannot produce `/dashboard/` — cron-generated links would come out as `https://<hostname>/client/...` (no `/dashboard` prefix) and 404 into WHMCS territory. Mitigations: a small `.htaccess` redirect set on `public_html` (`/client`, `/admin`, `/order`, `/callback` → `/dashboard/$0`), or physically moving the install at final cutover (see Architecture section).
- **Company hostname cannot contain a path** (validation regex is domain-labels-only, `app/models/companies.php:913-920`) — `www.hosterpk.com/dashboard` is not storable; the canonical hostname can only be a bare host.
- **Gateway callback URLs follow the request, not the setting**: `Blesta.gw_callback_url` is built from `$_SERVER['HTTP_HOST'] . WEBDIR` (`config/blesta.php:241-242`). A customer paying via `www.hosterpk.com/dashboard/` registers callbacks at `www.hosterpk.com/dashboard/callback/gw/...`; via beta, at `beta.hosterpk.com/callback/gw/...`. Gateways (including the local KuickPay integration) must accept the canonical origin — and both origins during any dual-serving period.
- **Subdirectory installs are officially supported** (https://docs.blesta.com/installation/); last known subdirectory bug (CORE-4599, partial view loading with custom themes) was fixed in 5.4.0 (https://docs.blesta.com/support/releases/5/540/). Serving one install at two webdirs simultaneously is structurally sound but **undocumented/unendorsed**.
- **CSRF** tokens key on the form's action URL vs `REQUEST_URI` (`vendors/minphp/form/src/Form.php:145-215`) — self-consistent within each access path; no cross-path breakage in normal use.

### Session and Cookie Coexistence with WHMCS

- Blesta uses `blesta_sid` (session) and `blesta_csid` (remember-me) cookies (`config/blesta.php:187-189`), DB-backed sessions. WHMCS uses `WHMCS<hash>`-style names / `PHPSESSID` — **cookie names are disjoint; no collision** (Confidence: High for Blesta — verified locally; Medium-High for WHMCS — https://docs.whmcs.com/Cookies).
- Blesta sets no `cookie_path`/`cookie_domain` override; with the server's ini defaults the cookie is **host-only at path=/**. Consequences: `beta.hosterpk.com` and `www.hosterpk.com` sessions are fully independent (staff must log in separately per host); on www, Blesta's cookie is sent to WHMCS paths and vice versa — harmless with disjoint names.
- WHMCS offers no supported way to re-scope its session cookie (community threads), but none is needed here. The historical WHMCS "Invalid Token" conflicts arise only when co-tenant apps share `PHPSESSID` — Blesta does not.

### Payment, Email, and Edge Integrations During Parallel Run

- **Webhooks/IPNs are registered per app.** WHMCS: `/modules/gateways/callback/<gw>.php`; Blesta: `/callback/gw/<company_id>/<gateway>/`. Multi-endpoint gateways (e.g. Stripe) can notify both during a transition; single-URL or lifetime-bound notifiers are the trap — **PayPal subscription IPNs post to the notify_url captured at profile creation forever**; the documented fix is a permanent 301 from the WHMCS callback path into Blesta's (`Redirect 301 /modules/gateways/callback/paypal.php /callback/gw/1/paypal_payments_standard/`, https://docs.blesta.com/getting-started/migrating/whmcs-52-82/). Never let both systems record the same IPN (double-posted payments).
- **Email/SPF/DKIM:** fine if both apps relay through the already-authorized SMTP path; if Blesta uses a different relay, SPF/DKIM must cover it or migration-window mail (password resets, first invoices) lands in spam. (Reasoned, standard practice.)
- **Edge/WAF:** both hosts are Cloudflare-proxied; BitNinja WAF rules attach per domain/URL patterns and can false-positive on new admin/login POST paths — watch `/dashboard/admin/*` after go-live (https://doc.bitninja.io/docs/modules/waf2/). Neither layer interacts with the symlink itself (they never see the filesystem).

_Sources: this install's source files as cited inline (primary); docs.blesta.com import & moving guides; developer.paypal.com IPN docs; docs.whmcs.com; doc.bitninja.io. Forum-derived claims (blesta.com/forums, WHT, LET return 403 to fetchers) are marked and held at Medium confidence._

## Architectural Patterns and Design

### Candidate Architectures for Exposing Blesta at `www.hosterpk.com/dashboard/`

| # | Method | Verdict | Notes |
|---|---|---|---|
| 1 | **Symlink** `public_html/dashboard → beta.hosterpk.com` | ✅ **Proven working here** (live test, 2026-07-07) | Zero-config at the web layer; passes every symlink-security layer (LSWS `followSymbolLink=1` "If Owner Match", `forceStrictOwnership=1`, CloudLinux SecureLinks) because link and target share owner `hosterpk`. One `ln -s` command; instantly reversible. Does **not** solve the license-domain question. |
| 2 | **`Alias` via cPanel userdata include** (`/etc/apache2/conf.d/userdata/{std,ssl}/2_4/hosterpk/hosterpk.com/dashboard.conf` + `<Directory>` grant, then `ensure_vhost_includes` + LSWS graceful restart) | ✅ Equal alternative, root-managed | Independent of symlink policies; survives docroot cleanups. Apache docs say `RewriteBase /dashboard/` is required in the Alias case (the shared `.htaccess` would then be wrong for the beta vhost) — the symlink avoids this because it sits inside the docroot walk (empirically confirmed: no RewriteBase needed). LSWS mod_alias parity is Medium-confidence — test before relying. |
| 3 | **ProxyPass / rewrite [P]** to beta vhost | ❌ Worst fit | LSWS 6.0+ supports it, but Host-header forcing, cookie-domain rewriting (LSWS parity for `ProxyPassReverseCookieDomain` undocumented), absolute-URL leakage, an extra BitNinja hop — and Blesta would see the *backend* Host, silently masking the license-domain issue until it isn't masked. |
| 4 | **Docroot remap / addon domain** | ❌ Doesn't satisfy the requirement | cPanel can point a *subdomain's* docroot anywhere, but has no mechanism to map a **path** on the main domain to another docroot. Gives a different hostname, not `/dashboard/`. |
| 5 | **`mount --bind`** | ❌ No gain | Needs root + fstab persistence, CageFS namespace remount churn (`cagefsctl --remount-all`), double-counted backups/quota — strictly more burden than the symlink for identical results. |
| 6 | **Physical move at cutover** (`mv`/copy install into `public_html/dashboard`, reissue license) | ✅ Recommended **end-state** | Reissue is free/self-service/unlimited, so the feared "license error" is a 5-minute account.blesta.com operation, not a blocker. A real directory fixes the cron-link `root_web_dir` gap that the symlink cannot (real path becomes `/home/hosterpk/public_html/dashboard/`), and ends dual-origin serving. |

### mod_rewrite / .htaccess Layering (verified)

- Apache/LSWS merge per-directory config along the URL walk: `public_html/.htaccess` then (through the symlink) Blesta's own `.htaccess`. **mod_rewrite rules are not inherited by subdirectories by default** — Blesta's `RewriteEngine On` + front controller fully replaces the WHMCS ruleset for `/dashboard/*` (https://httpd.apache.org/docs/2.4/en/rewrite/htaccess.html). Neither `.htaccess` sets `RewriteOptions Inherit`; the `httpd.conf` occurrences are vhost-context DCV excludes only (cPanel AutoSSL), which don't push parent `.htaccess` rules down.
- WHMCS's own front-of-file rules are all prefix-anchored away from `dashboard`, and its cache catch-all is `-f`-guarded — no capture possible even in inheritance scenarios.
- mod_alias `Redirect(Match)` directives at the parent apply URI-wide regardless, but every present pattern is anchored elsewhere — verified no `/dashboard` match.
- LSWS honors `.htaccess` changes without restart (caching notwithstanding), but vhost-include/httpd.conf changes need a graceful restart. `[L]` behaves like Apache's `[END]` in LSWS — irrelevant for these single-pass front controllers.

### Security & Data-Protection Architecture

- Blesta's `.htaccess` deny rules (`config/`, `.git`, `_bmad*`, `logs/`, `*.md`, `composer.*`, `.pdt` templates) were **empirically confirmed to apply through the symlink** (`config/blesta.php` → 403 via www). The staging repo's development artifacts stay unexposed at the production URL.
- The symlink will appear as an untracked entry in the `public_html` git repo — add `dashboard` to `/home/hosterpk/public_html/.gitignore`.
- AutoSSL/DCV: untouched — `hosterpk.com` DCV serves from `public_html/.well-known/`, beta DCV from its own docroot; cPanel's global DCV rewrite-excludes are in the vhost config. (Keep any future force-HTTPS rules below the DCV excludes.)
- LSCache: **no** `CacheEnable`/`CacheLookup` in either `.htaccess` (verified) — the parent-cache-inheritance pitfall does not currently apply; re-check if LSCache is ever enabled on the WHMCS side.

### Scalability/Performance Considerations

- Same PHP handler (`ea-php83` → lsphp83), same CloudLinux LVE account limits either way — the symlink adds no per-request overhead (LSWS resolves and caches). PHP opcache/realpath caches key on resolved paths; only relevant if the symlink target is ever *swapped* (then restart detached lsphp workers — not applicable to a permanent link).
- Both apps share one LVE: a traffic spike or runaway cron in either affects both. Acceptable for a transition; the retirement of WHMCS resolves it.

_Sources: live tests + config inspection on this server (primary); httpd.apache.org mod_rewrite/mod_dir docs; litespeedtech.com security config docs & staff forum threads; docs-dev.cloudlinux.com CageFS/SecureLinks docs; docs.litespeedtech.com cPanel/rewrite-proxy docs._

## Implementation Approaches and Technology Adoption

### The Parallel-Run Model That Actually Works

Two findings force a reshape of the naive "run both live, import at the end" plan (Confidence: High, official docs + bug threads):

1. **The WHMCS importer requires a fresh Blesta install/company** (https://docs.blesta.com/integrations/plugins/import-manager/). Incremental/top-up imports are unsupported — repeat imports into a populated DB die on duplicate keys (forum-documented). You **cannot** let Blesta accumulate real client/billing data for months and then merge the WHMCS delta at cutover.
2. **Blesta 6.0.0-b1 is vendor-designated non-production** ("DO NOT UPGRADE YOUR PRODUCTION"; Beta 2 shipped 2026-06-11 — this install is a beta behind; https://www.blesta.com/2026/06/11/blesta-6.0-beta-2-released/). Production cutover should happen on stable 5.13.x or wait for 6.0 stable.

The viable pattern is therefore **"parallel portal availability, single source of truth"**: WHMCS remains the authoritative biller for everyone; the Blesta portal at `/dashboard/` operates as a public-beta/UAT surface (rehearsal-imported or throwaway data, invoicing/dunning automation tasks disabled in Settings > System > Automation), followed by ONE freeze → clone-import → cutover maintenance window.

### Recommended Roadmap

**Phase 0 — Now (staging as-is):**
- Confirm at account.blesta.com which domain the license is issued for (web requests report `beta.hosterpk.com`; cron check-ins report the stale company hostname `sec.hosterpk.com` — an existing inconsistency).
- Fix the company hostname to the current canonical (`beta.hosterpk.com`) and consider a **free development license** (official mechanism for staging installs; request via ticket) so the paid license stays clean for production.
- Upgrade staging to 6.0 Beta 2+ if continuing the 6.0 track.

**Phase 1 — Expose at production URL (when intentionally going semi-public):**
- `ln -s /home/hosterpk/beta.hosterpk.com /home/hosterpk/public_html/dashboard` (one command; proven). Add `dashboard` to `public_html/.gitignore`.
- **License:** decide the canonical domain first. Either reissue to `hosterpk.com` and 301 `beta.hosterpk.com` → `www.hosterpk.com/dashboard/` (so Blesta never again sees the beta Host), or ask Blesta support about adding a second domain (the validator accepts arrays/wildcards; no public evidence standard licenses get two domains — Low confidence, ask). Do not run both hostnames live against one single-domain license.
- Update company hostname to `www.hosterpk.com`; add `public_html/.htaccess` redirects `/client|/admin|/order|/callback → /dashboard/...` to cover the cron-link webdir gap.
- `noindex` the portal until cutover (SEO duplicate content: order forms/KB are crawlable).
- Watch BitNinja/Cloudflare for false positives on `/dashboard/admin/*` POSTs.

**Phase 2 — Cutover (single maintenance window, on a stable Blesta release):**
- Freeze WHMCS: stop its cron, enable Maintenance Mode (client lockout; admin/API stay up).
- Clone WHMCS DB (never live; convert to InnoDB + utf8mb4_unicode_ci), run the importer into a **fresh production Blesta** (rehearsed N times on snapshots beforehand; verify next-due dates, module/server credentials remapping, ticket-department visibility, password-hash carryover).
- Prefer the **physical install** at `public_html/dashboard/` as the end-state (fixes cron links; reissue license — self-service).
- Flip gateway webhooks; add the permanent PayPal-callback 301 if applicable; set WHMCS Maintenance Mode Redirect URL → `https://www.hosterpk.com/dashboard/`; 301 old deep links (`clientarea.php`, invoice URLs).
- Enable Blesta cron (5-min cadence), monitor activity log 24h.

**Phase 3 — Retire WHMCS:**
- Keep WHMCS read-only ~30 days; final DB+files backup and invoice export (7+ year retention); then take it fully offline or IP-restrict — an unlicensed WHMCS keeps its **client area** publicly serving while the admin area dies, making it a liability, not an archive.
- Keep the callback 301s indefinitely (lifetime-bound PayPal subscriptions).

### Risk Register

| Risk | Severity | Mitigation |
|---|---|---|
| License `invalid_location` when www traffic starts (domain leg fails) | **High** | Decide canonical domain; reissue (free, self-service) at the flip; never dual-serve two hostnames on a single-domain license |
| 6.0.0-b1 in production | **High** | Cut over on 5.13.x stable or 6.0 stable; keep b1/b2 staging-only |
| No incremental import at cutover | **High** (plan-shaping) | Blesta stays non-authoritative until one freeze-import-cutover window; rehearse imports on snapshots |
| Cron email links lack `/dashboard` prefix under symlink | Medium | `.htaccess` redirects for `/client|/admin|/order|/callback`; physical move at cutover removes the cause |
| Stale company hostname (`sec.hosterpk.com`) | Medium | Fix now; it currently 301s everything to www where Blesta paths 404 |
| Double-billing during transition | Medium | Exactly one authoritative biller; disable Blesta invoicing automation until cutover; audit gateway webhooks point at one system |
| WAF (BitNinja/Cloudflare) false positives on new admin paths | Low-Medium | Monitor; whitelist patterns per BitNinja WAF docs |
| SEO duplicate content (two portals, imported KB) | Low | `noindex` non-canonical portal; 301s at cutover |
| Session confusion (independent logins per host) | Low | Cosmetic; ends when one canonical host remains |

### Cost Notes

- License reissue: free, unlimited (advertised), self-service. Dev license: free with owned/monthly licenses (ticket request). Multi-company addon (only if two live hostnames are truly required): $5/mo or $95 one-time. Parallel-run overlap otherwise costs only the existing WHMCS license until retirement.

_Sources: docs.blesta.com (import manager, WHMCS 5.2–8.2 import, moving, automation, releases), blesta.com blog & pricing, docs.whmcs.com (maintenance mode, licensing), developer.paypal.com (IPN), payrequest.io migration checklist (third-party), community threads (Medium confidence, search-indexed)._

# One Server, Two Billing Systems: Serving Blesta at `www.hosterpk.com/dashboard/` Beside WHMCS — Research Synthesis

## Executive Summary

The question was whether the Blesta staging install can be surfaced at the production URLs `www.hosterpk.com/dashboard/client/` and `/dashboard/admin/` via symlink — without disturbing WHMCS and without tripping Blesta's license binding — while both systems run in parallel until WHMCS retires.

**At the web-server layer the answer is an emphatic yes, and it is already proven**: a temporary symlink test on this exact server served Blesta's client and admin logins (HTTP 200) through `www.hosterpk.com` with correct asset paths, working static files, intact `.htaccess` protections, and zero WHMCS impact. Every symlink-security layer on this stack (LiteSpeed `followSymbolLink=1` owner-match, `forceStrictOwnership`, CloudLinux SecureLinks) passes because link and target share one owner, and the WHMCS `.htaccess` — reviewed line by line — contains nothing that can capture `/dashboard/*`. Blesta computes its web directory per request, so the same install self-adjusts between `/` on beta and `/dashboard/` on www with no configuration.

**At the license layer the plan's core assumption is wrong in a fixable way.** The symlink does preserve the install-path binding (PHP resolves symlinks), but Blesta's license validates **domain, IP, and path** — and domain comes from each live request's `SERVER_NAME`. Traffic arriving as `www.hosterpk.com` presents `hosterpk.com`, which will fail validation (`invalid_location`) against a `beta.hosterpk.com`-bound license, and the install phones home advertising whatever domain it sees. The remedy is procedural, not technical: license reissue is free, unlimited and self-service, so the flip to www is a planned reissue plus a 301 of the beta hostname — not a dual-hostname free-for-all.

**At the migration layer, two vendor facts reshape the parallel-run plan**: the WHMCS importer only runs into a fresh Blesta install (no incremental top-up import at cutover), and Blesta 6.0.0-b1 is explicitly non-production. The workable model is parallel *portal availability* (WHMCS stays the single source of truth; Blesta's billing automation stays off) followed by one freeze → clone-import → cutover window on a stable Blesta release.

**Key Technical Findings**

1. Symlink serving: proven live on this server; equal-quality alternative is a root-managed `Alias` vhost include; ProxyPass/bind-mount/docroot-remap are all worse fits.
2. License binds domain+IP+path per request (verified in this install's unencoded `license_manager.php`); symlink solves only the path leg; reissue moves (never adds) a domain; multi-company addon is the supported multi-hostname route.
3. No `.htaccess` conflict: WHMCS rules can't reach `/dashboard/*`; Blesta's front controller shadows them; mod_alias redirects verified non-matching.
4. Cookies are disjoint (`blesta_sid` vs WHMCS names) — no session conflicts; sessions are per-host.
5. Importer requires fresh install; 6.0.0-b1 (and b2) are non-production; cutover belongs on 5.13.x/6.0-stable.
6. Housekeeping found: company hostname is stale (`sec.hosterpk.com`), cron-generated email links can't carry the `/dashboard` prefix under a symlink (redirect shim or physical move at cutover fixes it), and gateway callbacks follow the request host (KuickPay must accept the canonical origin).

**Strategic Recommendations**

1. Create the symlink whenever you want the production URL live — it is one reversible command and technically safe (Phase 1 of the roadmap).
2. Before www traffic flows, settle the license: confirm the currently-bound domain at account.blesta.com, then either reissue to `hosterpk.com` and 301 `beta.` → `www.../dashboard/`, or ask Blesta support about second-domain/dev-license arrangements.
3. Keep WHMCS the sole biller until a single rehearsed freeze-import-cutover window on a stable release; prefer a physical move to `public_html/dashboard/` (plus routine reissue) as the end-state.
4. Fix now: company hostname, `dashboard` in `public_html/.gitignore`, `noindex` on the non-canonical portal.

## Table of Contents

1. Technical Research Scope Confirmation
2. Technology Stack Analysis — environment inventory, symlink precedent, empirical proof, WHMCS `.htaccess` compatibility review
3. Integration Patterns Analysis — license validation, URL/webdir generation, sessions/cookies, payments/email/edge
4. Architectural Patterns and Design — six candidate architectures compared, rewrite layering, security, performance
5. Implementation Approaches and Technology Adoption — parallel-run model, phased roadmap (0–3), risk register, costs
6. Research Synthesis — this section: executive summary, methodology, conclusion

## Research Methodology and Source Verification

- **Primary evidence (highest authority):** live tests and configuration reads on the production server itself — temporary symlink probe against origin 45.79.180.220, full `.htaccess` reads, LiteSpeed/cPanel/CloudLinux config inspection, and direct reading of this install's unencoded Blesta source (`license_manager.php`, `MinphpBridge.php`, `emails.php`, `blesta.php`, minphp `Form`/`Session`). Version-exact, environment-exact; Confidence: High.
- **Secondary:** official documentation (docs.blesta.com, blesta.com, docs.whmcs.com, httpd.apache.org, litespeedtech.com, cloudlinux.com, developer.paypal.com, doc.bitninja.io) — Confidence: High; four parallel research agents cross-verified claims.
- **Tertiary:** community sources (Blesta forums, WebHostingTalk, LowEndTalk, third-party KBs). These sites returned HTTP 403 to fetchers; claims rest on search-indexed excerpts and are capped at Medium confidence and marked inline.
- **Known gaps:** the encoded `app/app_controller.php` company-selection fallback (inferred, Medium); whether Blesta issues multi-domain data for standard licenses (unknown — ask support); importer compatibility with WHMCS newer than 8.2 (verify on a DB clone); no cooldown documented for license reissue (Low confidence that none exists).

## Technical Research Conclusion

The symlink approach is **easily implementable — it is already demonstrated working on this server** — and it coexists cleanly with WHMCS at the web, rewrite, session, and filesystem layers. The pitfalls are not where the plan expected them: the license's per-request **domain** check (not path), the **fresh-install-only** importer, and the **beta-version** production ban are the three constraints that should drive sequencing. Handled in that order — license decision, staged portal exposure with one authoritative biller, single rehearsed cutover on a stable release, physical move as end-state — the parallel run until WHMCS retirement is low-risk and fully reversible at every step before the final import.

---

**Technical Research Completion Date:** 2026-07-07
**Research Period:** point-in-time (2026-07-07), environment-exact
**Source Verification:** primary (live server + install source) > official docs > community (marked)
**Technical Confidence Level:** High for all load-bearing conclusions; Medium items flagged inline

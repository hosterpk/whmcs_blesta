# whmcs_blesta API and Route Contracts

**Date:** 2026-06-09  
**Scan Level:** Quick scan

## Scope

This document summarizes route-level contracts from `config/routes.php` and file-pattern evidence. It does not enumerate method-level request/response schemas because the quick scan did not read controller implementation bodies.

## Route Configuration Source

Primary route source:

- `config/routes.php`

Related controller surfaces:

- `app/controllers/api.php`
- `app/controllers/admin_*.php`
- `app/controllers/client_*.php`
- `app/controllers/feed.php`
- `app/controllers/cron.php`
- `app/controllers/callback.php`
- `app/controllers/uploads.php`
- Plugin controllers under `plugins/*/controllers`

## Route Families

| Route Family | Pattern | Target | Notes |
| --- | --- | --- | --- |
| Callback compatibility | `^callback.php` | `callback` | Backward-compatible non-merchant gateway callback URL |
| CMS catch-all | Public paths excluding admin/API/feed/callback/cron/dialog/errors/uploads/download/client/install/order/plugin/widget/app | `/cms/main/index/$1` | Active only if `plugins/cms` exists |
| Download manager | `^download/(.+)` | `download_manager/client_main/static/$1` | Active only if `plugins/download_manager` exists |
| Admin widget/plugin | `^admin/(widget|plugin)/(.+)` | `$2` | Prefix is configurable through `Route.admin` |
| Admin company settings | `^admin/settings/company/(.+)` | `admin_company_$1` | Convention maps to `admin_company_*` controllers |
| Admin system settings | `^admin/settings/system/(.+)` | `admin_system_$1` | Convention maps to `admin_system_*` controllers |
| Admin tool logs | `^admin/tools/logs/(.+)` | `admin_tools/log$1` | Log route specialization |
| Admin theme | `^admin/theme/(.+)$` | `admin_theme` | Theme CSS controller |
| Admin generic | `^admin/(.+)` | `admin_$1` | Generic admin convention |
| Admin default | `^admin/?$` | `admin_main` | Default admin landing controller |
| Client widget/plugin | `^client/(widget|plugin)/(.+)` | `$2` | Prefix is configurable through `Route.client` |
| Client theme | `^client/theme/(.+)$` | `client_theme` | Theme CSS controller |
| Client generic | `^client/(.+)` | `client_$1` | Generic client convention |
| Client default | `^client/?$` | `client_main` | Default client landing controller |
| API notifications | `^api/notifications` and `^api/notifications/(.+)` | `api/notifications`, `api/notifications/$1` | Notification-specific API routes |
| API generic | `^api/(.+)` | `api/index/$1` | Main API dispatch |
| Data feed | `^feed/(.+)` | `feed/index/$1` | Data feed dispatch |
| Direct widget/plugin | `^(widget|plugin)/(.+)` | `$2` | Direct extension dispatch |

The default admin prefix is `admin`, and the default client prefix is `client`.

## API Surface

The API route family is concentrated through `app/controllers/api.php`:

- `api/notifications`
- `api/notifications/<path>`
- `api/<path>` routed to `api/index/<path>`

Because this scan did not read `api.php`, request methods, authentication requirements, and response schemas are not asserted here. Treat this as a route-dispatch contract only.

## Admin Surface

Admin routes are convention-driven:

- `/admin/settings/company/<section>` routes to `admin_company_<section>`
- `/admin/settings/system/<section>` routes to `admin_system_<section>`
- `/admin/<path>` routes to `admin_<path>`
- `/admin` routes to `admin_main`

The quick scan found many corresponding `admin_*` controllers, including billing, clients, company settings, system settings, reports, packages, tools, automation, backup, help, marketplace, and AI-related surfaces.

## Client Surface

Client routes are convention-driven:

- `/client/<path>` routes to `client_<path>`
- `/client` routes to `client_main`

Detected client controllers include accounts, contacts, emails, invoices, login/logout, maintenance, managers, pay, quotations, services, theme, transactions, and verification.

## Extension Dispatch

Plugin and widget routes are directly mapped:

- Admin extension dispatch: `/admin/widget/...` and `/admin/plugin/...`
- Client extension dispatch: `/client/widget/...` and `/client/plugin/...`
- Direct extension dispatch: `/widget/...` and `/plugin/...`

This makes extension folder structure and controller naming important for route behavior.

## Feed, Cron, Callback, and Upload Surfaces

Detected direct controller surfaces:

- `app/controllers/feed.php`: data feed dispatch from `feed/(.+)`
- `app/controllers/cron.php`: scheduled task controller surface, not explicitly mapped in the displayed route rules but excluded from CMS catch-all
- `app/controllers/callback.php`: backward-compatible callback route support
- `app/controllers/uploads.php`: upload controller surface, excluded from CMS catch-all

## Authentication and Authorization

Authentication-specific files were found by directory pattern under:

- `components/auth`
- `components/auth/ldap`
- `components/auth/motp`
- `components/auth/oath`
- `app/models/users.php`
- `app/models/staff.php`
- `app/models/permissions.php`
- `app/models/api_keys.php`

Route-level authentication rules require controller implementation review and are not claimed by this quick scan.

## Contract Gaps for Deep Scan

A deep scan should extract:

- HTTP methods used by API endpoints
- API authentication and API key rules
- Request parameter names and validation
- Response shapes and error structures
- Plugin-specific endpoint contracts
- Callback signature and gateway verification behavior
- Feed formats and access rules

---

_Generated using BMAD Method `document-project` workflow_

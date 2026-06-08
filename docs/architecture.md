# whmcs_blesta Architecture

**Date:** 2026-06-09  
**Scope:** Quick-scan architecture documentation for the single application part.

## Executive Summary

The project is a PHP Composer application for Blesta, a billing platform for hosting providers. It uses a monolithic deployment model: one application root contains web entry points, MVC code, configuration, reusable subsystems, extension packages, localization, upgrade tooling, and operational scripts.

Architecturally, the codebase is best understood as a central MVC shell surrounded by extension families. The central shell lives in `app/`, routing/configuration lives in `config/`, shared platform services live in `core/` and `components/`, and installable features live under `plugins/` and component extension directories.

## Classification

- **Repository Type:** Monolith
- **BMAD Project Type Used:** `backend`
- **Application Style:** PHP MVC web application
- **Deployment Unit:** Single web application root
- **Primary Database:** MySQL-compatible database via PDO
- **Version Marker:** `manifest.json` reports `6.0.0-b1`

The BMAD detection catalog does not include a PHP web-app type, so this documentation uses `backend` requirements as the closest fit while explicitly documenting web views, plugins, and Blesta extension patterns.

## Runtime Architecture

```text
Web request
  |
  v
index.php
  |
  v
lib/init.php and framework bootstrap
  |
  v
config/services.php service providers
  |
  v
config/routes.php route mapping
  |
  v
app/controllers/* or plugin/component controller
  |
  v
app/models/*, components/*, core/*
  |
  v
MySQL, external gateways/modules, mailers, files, cache
  |
  v
app/views/* or plugin views
```

## Technology Stack

| Layer | Technology | Evidence |
| --- | --- | --- |
| Runtime | PHP 8.2+ | `composer.json` `require.php` |
| Dependency Management | Composer | `composer.json`, `composer.lock` |
| Autoloading | PSR-4 plus classmap | `composer.json` |
| Framework Bridge | minPHP bridge | `minphp/bridge` dependency, `MinphpBridge` service provider |
| Database | MySQL via PDO | `ext-pdo`, `ext-pdo_mysql`, `config/database.php` |
| Routing | Router config | `config/routes.php` |
| Services | Blesta service providers | `config/services.php` |
| Logging | Monolog | Composer dependency |
| Mail | Symfony Mailer | Composer dependency |
| HTTP Foundation | Symfony HTTP Foundation | Composer dependency |
| AI Integration | Blesta AI client and component | `blesta/ai-client`, `components/blesta_ai` |
| Testing | PHPUnit 8.5 | Composer dev dependency and scripts |

## Service Provider Bootstrap

`config/services.php` registers application providers in this order:

1. `Blesta\Core\ServiceProviders\Bootstrap`
2. `Blesta\Core\ServiceProviders\Logger`
3. `Blesta\Core\ServiceProviders\MinphpBridge`
4. `Blesta\Core\ServiceProviders\Pagination`
5. `Blesta\Core\ServiceProviders\Pricing`
6. `Blesta\Core\ServiceProviders\Requestor`
7. `Blesta\Core\ServiceProviders\Util`
8. `Blesta\Core\ServiceProviders\App`

The comment in the config states that order matters because some providers depend on earlier services.

## Routing Architecture

`config/routes.php` defines the high-level URL map:

- Admin area prefix comes from `Route.admin`, defaulting to `admin`.
- Client area prefix comes from `Route.client`, defaulting to `client`.
- CMS plugin routes can catch public paths when the `cms` plugin exists.
- `download/(.+)` can route to the download manager plugin when present.
- Admin paths map to `admin_*` controllers.
- Client paths map to `client_*` controllers.
- `api/(.+)` maps through `Api::index`, with notification-specific routes.
- `feed/(.+)` maps through `Feed::index`.
- Direct `widget` and `plugin` paths route into extension targets.

This creates a convention-heavy controller structure. Route names and controller file names are tightly related.

## Application Layers

### Entry and Bootstrap

- `index.php`: main web entry point
- `install.php`: installer entry point
- `upgrader.php`: upgrade entry point
- `lib/init.php`: initialization support
- `.htaccess`: web server routing/access behavior

### MVC Layer

- `app/controllers`: 70 top-level application controller files
- `app/models`: 70 top-level model files
- `app/views`: 454 files under admin, client, default, and error view trees
- `app/app_controller.php`, `app/admin_controller.php`, `app/client_controller.php`: base controller structures
- `app/app_model.php`: base model structure

### Core Services

- `core/Database`: database record/query helpers
- `core/Cache`: cache abstraction with file and Redis adapters
- `core/Automation`: automation task structure
- `core/Pricing`: pricing domain package
- `core/ServiceProviders`: service provider registration classes
- `core/Util`: reusable utility packages

### Components

`components/` contains platform-level subsystems and extension families. Examples:

- Authentication adapters under `components/auth`
- Gateways under `components/gateways`
- Modules under `components/modules`
- Reports under `components/reports`
- Upgrade tasks and schema under `components/upgrades`
- Invoice delivery, templates, and formats under `components/invoice_*`
- Messaging under `components/messengers`

### Plugins

`plugins/` contains installable feature packages. The quick scan found 23 plugin directories, including `cms`, `domains`, `import_manager`, `order`, `support_manager`, `system_status`, and `webhooks`.

Plugins commonly contain their own controllers, models, views, language files, and config folders.

## Data Architecture

The app uses a MySQL database profile loaded by `config/database.php` from `config/blesta.php`. The quick scan found:

- `app/models`: core model layer
- `components/upgrades/db/schema.sql`: primary schema artifact
- `components/upgrades/db/3.0.0/1.sql`: versioned SQL artifact
- `components/upgrades/tasks`: versioned PHP upgrade tasks from 3.x through 6.0.0 beta

See [Data Models](./data-models.md) for the pattern-derived model and schema inventory.

## API and Web Surface

The route config exposes multiple surfaces:

- Admin area
- Client area
- API area
- Feed area
- Cron/controller area
- Callback area
- Plugin and widget direct routes
- Download manager static routes when the plugin exists
- CMS catch-all routing when the CMS plugin exists

See [API Contracts](./api-contracts.md) for route-level details.

## Extension Architecture

Composer `extra.installer-paths` maps package types into runtime folders:

| Package Type | Install Path |
| --- | --- |
| `blesta-plugin` | `plugins/{$name}` |
| `blesta-gateway-merchant` | `components/gateways/merchant/{$name}` |
| `blesta-gateway-nonmerchant` | `components/gateways/nonmerchant/{$name}` |
| `blesta-messenger` | `components/messengers/{$name}` |
| `blesta-module` | `components/modules/{$name}` |
| `blesta-invoice-template` | `components/invoice_templates/{$name}` |
| `blesta-invoice-format` | `components/invoice_formats/{$name}` |
| `blesta-reports` | `components/reports/{$name}` |
| `blesta-language` | `./` |

This is a major architectural constraint: extension package type controls the folder where Composer installs runtime code.

## Configuration Architecture

Important configuration files:

- `config/routes.php`: URL-to-controller mapping
- `config/services.php`: service provider ordering
- `config/database.php`: database profile selection
- `config/blesta.php`: product settings, database values, optional Redis configuration comments, pagination, cron, session settings
- `config/i18.php`: localization behavior
- `config/cache.dir.php`: cache path behavior
- `config/aliases.php`, `config/mapping.php`, `config/mime.php`: supporting runtime maps

Security note: the scanned `config/blesta.php` contains database credential fields. Keep generated documentation credential-free and treat that file as sensitive.

## Testing Strategy

Composer provides the following scripts:

```bash
composer test
composer test-helpers
composer test-coverage
composer test-unit
composer test-integration
```

The scripts target `../tests`, not a test directory inside this repository. No test files were found inside this checkout during quick scan.

## Deployment Architecture

Deployment is a PHP web application deployment:

- PHP 8.2+ runtime
- MySQL-compatible database
- Composer dependencies installed into `vendors/`
- Web server rewrite support, likely using `.htaccess` or equivalent Nginx rules
- Runtime configuration under `config/`
- Installer and upgrader entry points for lifecycle operations
- Cron/controller routing for scheduled tasks

No Dockerfile, Compose file, Kubernetes manifest, Terraform stack, GitHub Actions workflow, GitLab CI file, or Jenkinsfile was found during quick scan.

## Architectural Constraints

- Preserve route naming conventions when adding admin/client controllers.
- Keep extension-specific changes inside the relevant plugin/module/gateway/messenger/report folder.
- Pair schema changes with upgrade artifacts under `components/upgrades`.
- Do not copy secrets from `config/blesta.php` into docs, logs, or review artifacts.
- Treat quick-scan endpoint and table inventories as pattern-derived until a deep scan confirms implementation details.

---

_Generated using BMAD Method `document-project` workflow_

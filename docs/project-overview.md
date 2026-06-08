# whmcs_blesta - Project Overview

**Date:** 2026-06-09  
**Type:** PHP Composer web application  
**Architecture:** MVC monolith with extension/plugin architecture

## Executive Summary

`whmcs_blesta` contains a Blesta billing platform codebase for hosting providers. The repository is organized as one deployable PHP application with a core MVC app, service providers, reusable components, installable modules, payment gateways, plugins, language packs, and upgrade tooling.

The project is Composer-managed and targets PHP 8.2 or newer. Composer metadata describes the application as `blesta/blesta`, a proprietary project with package installation rules that place Blesta extension packages directly into runtime folders such as `plugins/`, `components/modules/`, `components/gateways/`, `components/messengers/`, `components/invoice_templates/`, and `components/invoice_formats/`.

## Project Classification

- **Repository Type:** Monolith
- **Project Type:** Backend-style PHP web application, mapped to BMAD `backend` requirements
- **Primary Language:** PHP
- **Template/View Languages:** PHP and `.pdt` templates
- **Architecture Pattern:** MVC application plus extension packages and service-provider bootstrap
- **Reasoning:** Strong markers include `composer.json`, `index.php`, `install.php`, `upgrader.php`, `app/controllers/`, `app/models/`, `app/views/`, `config/routes.php`, and large extension trees under `components/` and `plugins/`.

## Technology Stack Summary

| Category | Technology | Version or Marker | Evidence |
| --- | --- | --- | --- |
| Runtime | PHP | `>=8.2.0`, Composer platform `8.2` | `composer.json` |
| Package Manager | Composer | Uses custom `vendors/` directory | `composer.json` `config.vendor-dir` |
| Product | Blesta | `6.0.0-b1` | `manifest.json` |
| Database | MySQL through PDO | `ext-pdo`, `ext-pdo_mysql`; config profile uses `mysql` | `composer.json`, `config/database.php`, `config/blesta.php` |
| Web Routing | minPHP-style router | Admin, client, API, feed, callback, plugin/widget route mappings | `config/routes.php` |
| Service Bootstrap | Blesta service providers | Bootstrap, Logger, MinphpBridge, Pagination, Pricing, Requestor, Util, App | `config/services.php` |
| Logging | Monolog | `~2.9.1` | `composer.json` |
| HTTP/Mail Support | Symfony components | `symfony/http-foundation`, `symfony/mailer` `^5.4` | `composer.json` |
| Security/Input Utilities | HTML Purifier, reCAPTCHA, phpseclib, password compat | Composer-managed dependencies | `composer.json` |
| Testing | PHPUnit | `~8.5`, Composer scripts | `composer.json` |
| Code Style | PHP_CodeSniffer and Slevomat standard | dev dependencies | `composer.json` |

## Key Features

- Billing platform application shell with admin and client areas.
- 70 top-level application controllers and 70 top-level application models.
- 454 files under `app/views`, including admin, client, default, and error views.
- 41 hosting/domain modules under `components/modules`.
- 39 payment gateways: 12 merchant gateways and 27 non-merchant gateways.
- 23 plugins under `plugins`.
- 23 language directories under `language`.
- Upgrade system with SQL schema files and versioned upgrade task files.
- Optional Redis cache configuration documented in `config/blesta.php`.
- Composer installer paths for Blesta-specific extension package types.

## Architecture Highlights

- `index.php` is the primary runtime entry point, with `install.php` and `upgrader.php` for lifecycle operations.
- `config/routes.php` maps admin, client, API, feed, callback, widget, and plugin paths to controllers.
- `app/controllers` and `app/models` form the central MVC application layer.
- `app/views` contains theme and panel templates for admin, client, default, and error surfaces.
- `core/ServiceProviders` defines bootstrap registration order for application services.
- `components/` contains reusable subsystems and installable extension families.
- `plugins/` contains installable feature packages, each commonly following controllers/models/views/language/config organization.
- `components/upgrades` contains database schema and versioned upgrade tasks.

## Development Overview

### Prerequisites

- PHP 8.2 or newer
- Composer
- MySQL-compatible database
- Required PHP extensions: `pdo`, `pdo_mysql`, `curl`, `openssl`
- Optional but recommended PHP extensions listed by Composer: `gd`, `gmp`, `imap`, `libxml`, `mailparse`, `mbstring`, `simplexml`, and `zlib`

### Getting Started

1. Install dependencies with Composer.
2. Configure database and runtime values.
3. Serve the project through a PHP-capable web server.
4. Run installer or upgrader flows when needed.
5. Use Composer scripts for tests if the sibling tests directory is available.

### Key Commands

- **Install:** `composer install`
- **Test:** `composer test`
- **Unit Tests:** `composer test-unit`
- **Integration Tests:** `composer test-integration`
- **Coverage:** `composer test-coverage`

No dedicated `dev`, `build`, Docker, or CI command was found during the quick scan.

## Repository Structure

The repository is a single deployable application, not a multi-package monorepo. Runtime concerns are separated by folder:

- `app/` for MVC app controllers, models, and views
- `components/` for reusable platform subsystems and extension families
- `core/` for namespaced service providers, utilities, cache, database, automation, and pricing code
- `config/` for route, service, database, cache, localization, and product settings
- `helpers/` for global helper packages
- `language/` for translation packs
- `plugins/` for installable feature plugins
- `lib/` for initialization and doc-comment helpers
- `scripts/` for import/support scripts

## Documentation Map

For detailed information, see:

- [index.md](./index.md) - Master documentation index
- [architecture.md](./architecture.md) - Detailed architecture
- [source-tree-analysis.md](./source-tree-analysis.md) - Directory structure
- [component-inventory.md](./component-inventory.md) - Component families and extension points
- [development-guide.md](./development-guide.md) - Development workflow
- [api-contracts.md](./api-contracts.md) - Route and API surface summary
- [data-models.md](./data-models.md) - Model and schema summary
- [deployment-guide.md](./deployment-guide.md) - Deployment and operations notes

---

_Generated using BMAD Method `document-project` workflow_

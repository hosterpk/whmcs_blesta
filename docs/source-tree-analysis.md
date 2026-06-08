# whmcs_blesta - Source Tree Analysis

**Date:** 2026-06-09

## Overview

This repository is organized as a single PHP web application. The top-level structure separates runtime entry points, MVC application code, reusable components, core namespaced services, configuration, helpers, language packs, plugins, and BMAD planning artifacts.

## Complete Directory Structure

```text
whmcs_blesta/
|-- .htaccess                 # Web server rewrite/access file
|-- README.md                 # Minimal project README
|-- composer.json             # PHP dependencies, scripts, autoload, extension installer paths
|-- composer.lock             # Locked Composer dependency graph
|-- index.php                 # Main web entry point
|-- install.php               # Installation entry point
|-- upgrader.php              # Upgrade entry point
|-- manifest.json             # Release manifest; reports version 6.0.0-b1
|-- app/
|   |-- app_controller.php    # Base app controller
|   |-- app_model.php         # Base app model
|   |-- admin_controller.php  # Admin controller base
|   |-- client_controller.php # Client controller base
|   |-- controllers/          # Admin, client, API, cron, feed, callback, upload controllers
|   |-- models/               # Core application models
|   `-- views/                # Admin, client, default, and error templates/assets
|-- components/
|   |-- auth/                 # Authentication adapters
|   |-- delivery/             # Delivery methods
|   |-- email/                # Email subsystem
|   |-- exchange_rates/       # Exchange-rate providers
|   |-- gateways/             # Merchant and non-merchant payment gateways
|   |-- invoice_*             # Invoice delivery, templates, and formats
|   |-- messengers/           # Messaging integrations
|   |-- modules/              # Hosting/domain/service provisioning modules
|   |-- reports/              # Reporting framework and reports
|   |-- upgrades/             # Schema and upgrade tasks
|   `-- upload/               # Upload subsystem
|-- config/
|   |-- aliases.php
|   |-- blesta.php            # Product/runtime settings and database profile values
|   |-- cache.dir.php
|   |-- database.php          # Database profile bootstrap
|   |-- i18.php
|   |-- mapping.php
|   |-- mime.php
|   |-- routes.php            # Route mappings
|   `-- services.php          # Service provider registration order
|-- core/
|   |-- Automation/
|   |-- Cache/
|   |-- Database/
|   |-- Pricing/
|   |-- ServiceProviders/
|   `-- Util/
|-- helpers/
|   |-- color/
|   |-- css/
|   |-- currency_format/
|   |-- data_structure/
|   |-- settings_processor/
|   |-- text_parser/
|   |-- widget/
|   `-- widget_client/
|-- language/                 # 23 locale directories
|-- lib/
|   |-- init.php
|   `-- doc_comments.php
|-- plugins/                  # 23 feature plugins
|-- scripts/
|   `-- import_whmcs_large.sh
|-- _bmad/                    # BMAD workflow configuration and scripts
|-- _bmad-output/             # BMAD planning and implementation artifacts
`-- docs/                     # Generated documentation from this workflow
```

## Critical Directories

### `app/`

The central application layer. It contains base controller/model classes, application controllers, application models, and view templates. Quick-scan counts found 70 controller files, 70 model files, and 454 files under `app/views`.

**Purpose:** Core MVC application behavior.  
**Contains:** Admin/client/API/controller surfaces, domain models, and templates.  
**Entry Points:** `app/controllers/api.php`, `app/controllers/cron.php`, `app/controllers/feed.php`, admin/client route targets.

### `components/`

Reusable platform components and extension families. This tree includes authentication, email, gateways, modules, reports, upgrades, invoice systems, messengers, upload handling, and utility components.

**Purpose:** Shared subsystems and installable Blesta extension points.  
**Contains:** 41 modules, 39 gateways, report implementations, invoice templates/formats, upgrade tasks, and component-level READMEs.  
**Integration:** Composer installer paths place package types directly into this tree.

### `components/modules/`

Hosting, domain, and service provisioning modules.

**Purpose:** External service provisioning integrations.  
**Contains:** `cpanel`, `direct_admin`, `enom`, `logicboxes`, `plesk`, `proxmox`, `pterodactyl`, `vultr`, and many others.

### `components/gateways/`

Payment gateway integrations.

**Purpose:** Merchant and non-merchant payment processing.  
**Contains:** Merchant gateways such as `authorize_net`, `braintree`, `stripe_gateway`, and non-merchant gateways such as `paypal_checkout`, `paystack`, `razorpay`, and `square`.

### `components/upgrades/`

Database schema and application upgrade flow.

**Purpose:** Install/upgrade migrations and versioned upgrade tasks.  
**Contains:** `db/schema.sql`, `db/3.0.0/1.sql`, upgrade task classes from 3.x through 6.0.0 beta.

### `config/`

Runtime configuration.

**Purpose:** Route, database, service-provider, cache, localization, MIME, alias, and product settings.  
**Contains:** `routes.php`, `database.php`, `blesta.php`, `services.php`, and related config files.  
**Security Note:** `config/blesta.php` stores database connection details in this checkout; generated docs intentionally do not copy credential values.

### `core/`

Namespaced core services and utilities loaded by Composer PSR-4 autoloading under `Blesta\Core\`.

**Purpose:** Cross-cutting core packages.  
**Contains:** Automation, cache, database, pricing, service providers, utilities, schemas, validation, widgets, tax helpers, transport, and data-feed utilities.

### `helpers/`

Global helper packages.

**Purpose:** Utility code for CSS, text parsing, widgets, currency formatting, color handling, settings processing, and data structures.

### `language/`

Localization packs.

**Purpose:** Language strings for application, plugins, and components.  
**Contains:** 23 locale directories including `en_us`, `de_de`, `es_es`, `fr_fr`, `pt_br`, `tr_tr`, and `zh_cn`.

### `plugins/`

Installable feature plugins.

**Purpose:** Optional application features packaged with their own controllers, models, views, language files, and config.  
**Contains:** `cms`, `domains`, `import_manager`, `order`, `support_manager`, `webhooks`, and other plugins.

### `lib/`

Bootstrap-adjacent library files.

**Purpose:** Initialization and documentation helpers.  
**Contains:** `init.php` and `doc_comments.php`.

### `scripts/`

Operational/helper scripts.

**Purpose:** Project utility scripts.  
**Contains:** `import_whmcs_large.sh`.

## Entry Points

- **Main Web Entry:** `index.php`
- **Installer:** `install.php`
- **Upgrader:** `upgrader.php`
- **Route Configuration:** `config/routes.php`
- **API Controller Surface:** `app/controllers/api.php`
- **Cron Controller Surface:** `app/controllers/cron.php`
- **Feed Controller Surface:** `app/controllers/feed.php`

## File Organization Patterns

- Controllers are named by route family, especially `admin_*`, `client_*`, and direct surfaces like `api`, `cron`, `feed`, `callback`, and `uploads`.
- Models are stored flat under `app/models` for core application domains.
- Views are grouped by surface/theme under `app/views/admin`, `app/views/client`, `app/views/default`, and `app/views/errors`.
- Plugins commonly mirror Blesta extension shape: `controllers`, `models`, `views`, `language`, and optional `config`, `lib`, `components`, or `vendors`.
- Components are grouped by extension family, with modules and gateways using one directory per integration.
- Core services use PSR-4 namespacing under `core/`.
- Upgrade tasks are versioned PHP files under `components/upgrades/tasks`.

## Key File Types

### PHP

- **Pattern:** `*.php`
- **Purpose:** Application code, controllers, models, components, service providers, helpers, config, and upgrade tasks.
- **Quick-scan Count:** 13,422 PHP files excluding generated docs, BMAD agent folders, `.git`, and standard dependency folders.

### PDT

- **Pattern:** `*.pdt`
- **Purpose:** Template/view files.
- **Quick-scan Count:** 1,088 files.

### JSON

- **Pattern:** `*.json`
- **Purpose:** Composer metadata, release manifest, plugin metadata, and other structured config.
- **Quick-scan Count:** 437 files.

### Markdown

- **Pattern:** `*.md`, `*.markdown`
- **Purpose:** README and package documentation.
- **Quick-scan Count:** 124 `.md` files plus 9 `.markdown` files.

### SQL

- **Pattern:** `*.sql`
- **Purpose:** Schema and upgrade scripts.
- **Examples:** `components/upgrades/db/schema.sql`, `components/upgrades/db/3.0.0/1.sql`.

## Asset Locations

- **Theme Assets:** `app/views/default/css`, `app/views/default/images`, `app/views/default/javascript`, `app/views/default/webfonts`
- **Admin/Client View Assets:** under `app/views/admin` and `app/views/client`
- **Plugin Assets:** plugin-specific `views`, `config`, `lib`, or `vendors` folders
- **Static Upload/Download Support:** `components/upload`, `components/download`

## Configuration Files

- `composer.json`: dependencies, scripts, autoload, installer paths
- `manifest.json`: release file manifest and version marker
- `config/routes.php`: application route mapping
- `config/database.php`: active database profile bootstrap
- `config/blesta.php`: product settings, database profile values, Redis cache comments, pagination, cron, session settings
- `config/services.php`: service provider order
- `config/cache.dir.php`: cache directory config
- `config/i18.php`: localization config
- `.htaccess`: web server rewrite/access behavior

## Notes for Development

Use this tree as a routing map before modifying the application. Controller changes usually start in `app/controllers` and `config/routes.php`. Domain behavior usually starts in `app/models` or a specific plugin/module model. Schema changes should be paired with `components/upgrades` artifacts. Extension changes should stay inside the relevant plugin, module, gateway, messenger, invoice, or report folder.

---

_Generated using BMAD Method `document-project` workflow_

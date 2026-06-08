# whmcs_blesta Documentation Index

**Type:** Monolith  
**Primary Language:** PHP  
**Architecture:** Composer-managed MVC web application with extension/plugin architecture  
**Last Updated:** 2026-06-09  
**Scan Level:** Quick scan, based on manifests, configuration files, directory structure, file names, and existing README inventory.

## Project Overview

This repository contains Blesta, "The Billing Platform for Hosting Providers." The codebase is a PHP application organized around a central MVC app, reusable components, installable modules, payment gateways, plugins, language packs, and core service providers.

The BMAD documentation requirements catalog has no dedicated PHP web-application type. This scan therefore classified the project as a single `backend` part and added PHP/MVC-specific context throughout the generated docs.

## Quick Reference

- **Tech Stack:** PHP 8.2+, Composer, MySQL through PDO, minPHP bridge, Symfony components, Monolog, PHPUnit scripts
- **Application Version Marker:** `manifest.json` reports `6.0.0-b1`
- **Entry Points:** `index.php`, `install.php`, `upgrader.php`
- **Routing Config:** `config/routes.php`
- **Database Config:** `config/database.php` loads `config/blesta.php`
- **Package Manager:** Composer, with `vendors/` as the configured vendor directory
- **Architecture Pattern:** MVC core plus extension directories for modules, gateways, messengers, reports, plugins, invoice formats, and invoice templates

## Generated Documentation

### Core Documentation

- [Project Overview](./project-overview.md) - Executive summary, classification, technology table, and development overview
- [Source Tree Analysis](./source-tree-analysis.md) - Annotated directory structure and key file locations
- [Architecture](./architecture.md) - Technical architecture and runtime organization
- [Component Inventory](./component-inventory.md) - Major component families and extension points
- [Development Guide](./development-guide.md) - Local setup, Composer commands, testing, and workflow notes
- [API Contracts](./api-contracts.md) - Route-level API and web surface summary from quick-scan evidence
- [Data Models](./data-models.md) - Model and schema inventory from quick-scan evidence
- [Deployment Guide](./deployment-guide.md) - Deployment prerequisites, configuration, install/upgrade, cron, and operational notes

## Existing Documentation

Existing documentation is distributed across extension and core folders rather than a central docs tree.

- [Root README](../README.md) - Minimal project title
- [Automation README](../core/Automation/README.md) - Core automation package context
- [Pricing README](../core/Pricing/README.md) - Core pricing package context
- [AI Utility README](../core/Util/AI/README.md) - AI utility package context
- [Events Utility README](../core/Util/Events/README.md) - Event utility package context
- [Plugin READMEs](../plugins/) - Documentation for plugins such as `cms`, `domains`, `import_manager`, `order`, `support_manager`, and `webhooks`
- [Module READMEs](../components/modules/) - Documentation for hosting/domain modules such as `cpanel`, `direct_admin`, `enom`, `logicboxes`, `plesk`, `proxmox`, and `vultr`
- [Gateway READMEs](../components/gateways/) - Documentation for merchant and non-merchant payment gateway integrations

## Getting Started

### Prerequisites

- PHP 8.2 or newer
- Composer
- MySQL-compatible database with PDO MySQL enabled
- Required PHP extensions from `composer.json`: `pdo`, `pdo_mysql`, `curl`, and `openssl`
- Web server capable of serving the repository root and honoring the route/rewrite setup

### Setup

```bash
composer install
```

Then configure local database settings in `config/blesta.php` or the environment-specific configuration mechanism used by your deployment. The scanned file contains live-style database connection settings, so treat it as sensitive configuration.

### Run Locally

No dedicated development server script was found. Typical local development should use Apache, Nginx with PHP-FPM, or an equivalent PHP web stack pointing at this project root. For limited routing smoke checks only, a PHP built-in server can be tried:

```bash
php -S 127.0.0.1:8000 index.php
```

### Run Tests

Composer exposes PHPUnit scripts that target a sibling `../tests` directory:

```bash
composer test
composer test-unit
composer test-integration
```

No test files were found inside this repository during the quick scan, so the sibling tests directory may be supplied outside this checkout.

## For AI-Assisted Development

Use this index as the retrieval entry point. For most changes:

- **Controller or route changes:** Start with [Architecture](./architecture.md), [API Contracts](./api-contracts.md), and `config/routes.php`
- **Model or schema changes:** Start with [Data Models](./data-models.md), `app/models/`, and `components/upgrades/`
- **Extension changes:** Start with [Component Inventory](./component-inventory.md) and the relevant folder under `plugins/`, `components/modules/`, `components/gateways/`, or `components/messengers/`
- **Operational changes:** Start with [Deployment Guide](./deployment-guide.md), `config/`, `install.php`, `upgrader.php`, and `app/controllers/cron.php`

## Scan Limitations

This was a quick scan. It intentionally did not read source implementation bodies beyond selected configuration files. Endpoint, table, and component details are therefore pattern-derived and should be deep-scanned before high-risk refactors.

---

_Documentation generated by BMAD Method `document-project` workflow_

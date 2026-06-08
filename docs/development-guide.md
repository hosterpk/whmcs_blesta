# whmcs_blesta Development Guide

**Date:** 2026-06-09  
**Scan Level:** Quick scan

## Prerequisites

- PHP 8.2 or newer
- Composer
- MySQL-compatible database
- Web server with PHP support
- Required PHP extensions:
  - `pdo`
  - `pdo_mysql`
  - `curl`
  - `openssl`
- Recommended PHP extensions from Composer suggestions:
  - `gd`
  - `gmp`
  - `imap`
  - `libxml`
  - `mailparse`
  - `mbstring`
  - `simplexml`
  - `zlib`

## Install Dependencies

```bash
composer install
```

Composer is configured to install dependencies into `vendors/`, not the default `vendor/` directory.

## Configuration

Important config files:

- `config/blesta.php`: product/runtime settings and database profile values
- `config/database.php`: database profile bootstrap
- `config/routes.php`: URL route mapping
- `config/services.php`: service provider registration order
- `config/cache.dir.php`: cache path behavior
- `config/i18.php`: localization behavior

Security note: `config/blesta.php` contains database credential fields in this checkout. Treat it as sensitive configuration and avoid copying values into docs, logs, or review comments.

## Local Runtime

No dedicated local development command was found. Use a normal PHP web stack, typically Apache or Nginx with PHP-FPM, pointed at the application root.

For limited local smoke checks, the PHP built-in server can be tried:

```bash
php -S 127.0.0.1:8000 index.php
```

The built-in server may not fully match production rewrite behavior. Route-sensitive testing should use the same rewrite rules as deployment.

## Entry Points

- `index.php`: main web entry
- `install.php`: install flow
- `upgrader.php`: upgrade flow
- `app/controllers/cron.php`: scheduled task controller surface
- `app/controllers/api.php`: API controller surface
- `app/controllers/feed.php`: data feed controller surface

## Composer Scripts

Available scripts from `composer.json`:

```bash
composer test
composer test-helpers
composer test-coverage
composer test-unit
composer test-integration
```

These scripts invoke PHPUnit against `../tests`. The quick scan did not find a `tests/` folder inside this repository, so test execution depends on a sibling test checkout or another environment-specific test path.

## Code Style and Static Checks

Composer dev dependencies include:

- `squizlabs/php_codesniffer` `~4.0`
- `slevomat/coding-standard` `~8.24.0`
- `phpunit/phpunit` `~8.5`

Only one PHP_CodeSniffer config file was found during quick scan: `plugins/softaculous/phpcs.xml.dist`. No root `phpcs.xml`, GitHub Actions workflow, GitLab CI file, Jenkinsfile, Dockerfile, or Compose file was found.

## Common Development Tasks

### Add or Change an Admin Page

1. Check `config/routes.php` for admin route convention.
2. Locate or add the relevant `app/controllers/admin_*` controller.
3. Update or add model behavior under `app/models` if needed.
4. Add or update templates under `app/views/admin`.
5. Add language strings under each required `language/<locale>` directory.

### Add or Change a Client Page

1. Check `config/routes.php` for client route convention.
2. Locate or add the relevant `app/controllers/client_*` controller.
3. Update models under `app/models`.
4. Add or update templates under `app/views/client`.
5. Add language strings under each required locale.

### Add or Change a Plugin

1. Work inside the specific folder under `plugins/`.
2. Preserve plugin-local controllers, models, views, language, config, and library organization.
3. Check the plugin README when present.
4. Keep plugin-specific behavior out of core app folders unless the integration contract requires it.

### Add or Change a Module

1. Work inside the relevant directory under `components/modules`.
2. Check module README and package metadata when present.
3. Keep external service integration concerns isolated to the module.

### Add or Change a Payment Gateway

1. Use `components/gateways/merchant` for merchant gateways.
2. Use `components/gateways/nonmerchant` for non-merchant gateways.
3. Check gateway README and Composer metadata where present.
4. Avoid mixing gateway-specific behavior into app-level controllers unless routes require it.

### Add or Change Schema

1. Identify impacted model files under `app/models` or plugin model folders.
2. Update schema or migration artifacts under `components/upgrades/db` or `components/upgrades/tasks`.
3. Preserve versioned upgrade-task naming conventions.
4. Exercise install and upgrade flows in a database-backed environment.

## Existing Documentation Sources

Read these first when working in the corresponding areas:

- `core/Automation/README.md`
- `core/Pricing/README.md`
- `core/Util/*/README.md`
- `plugins/*/README.md`
- `components/modules/*/README.md`
- `components/gateways/*/*/README.md`

## Recommended Verification

For a normal code change, run the narrowest available checks:

```bash
composer test-unit
```

For broader changes, run:

```bash
composer test
```

If the sibling `../tests` directory is unavailable, document that limitation in the change notes and use targeted PHP syntax checks or manual route checks appropriate to the changed files.

---

_Generated using BMAD Method `document-project` workflow_

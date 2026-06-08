# whmcs_blesta Deployment Guide

**Date:** 2026-06-09  
**Scan Level:** Quick scan

## Deployment Model

This is a single PHP web application deployment. The repository root contains web entry points, runtime configuration, application code, extensions, language packs, installer/upgrader flows, and Composer metadata.

## Runtime Requirements

- PHP 8.2 or newer
- Composer-installed dependencies in `vendors/`
- MySQL-compatible database
- Required PHP extensions:
  - `pdo`
  - `pdo_mysql`
  - `curl`
  - `openssl`
- Web server with rewrite/routing support
- Writable runtime paths as required by Blesta installation and cache/upload behavior

## Dependency Installation

```bash
composer install --no-dev --optimize-autoloader
```

Use dev dependencies only in development or CI contexts:

```bash
composer install
```

Composer is configured with:

- `vendor-dir`: `vendors`
- `preferred-install`: `dist`
- package installer paths for Blesta plugins, gateways, modules, messengers, invoice templates, invoice formats, reports, and language packages

## Web Server

Detected web artifacts:

- `.htaccess`
- `.htaccess.bak`
- `index.php`

Apache deployments should preserve `.htaccess` behavior if it is part of the target hosting model. Nginx or other web servers should translate equivalent rewrite behavior so routes in `config/routes.php` work as intended.

## Configuration

Important runtime configuration files:

- `config/blesta.php`
- `config/database.php`
- `config/routes.php`
- `config/services.php`
- `config/cache.dir.php`
- `config/i18.php`

Security note: `config/blesta.php` contains database credential fields in this checkout. Keep this file out of public artifacts and avoid logging or copying credential values.

## Database

`config/database.php` sets:

- lazy database connection behavior
- PDO object fetch mode
- connection reuse
- active profile from `Blesta.database_info`

The configured profile in `config/blesta.php` uses a MySQL driver and utf8mb4 charset setup. Use environment-specific values for host, database, user, password, and related settings.

## Install and Upgrade

Lifecycle entry points:

- `install.php`
- `upgrader.php`

Schema and upgrade artifacts:

- `components/upgrades/db/schema.sql`
- `components/upgrades/db/3.0.0/1.sql`
- `components/upgrades/tasks/upgrade*.php`

Deployment changes that alter schema should be verified through both fresh install and upgrade paths where applicable.

## Cron and Scheduled Tasks

Detected cron-related artifacts:

- `app/controllers/cron.php`
- `app/models/cron_tasks.php`
- `config/blesta.php` cron settings
- `core/Automation`

Configure production scheduling according to the application route/controller expectations and operational policy. The quick scan did not extract the exact cron invocation command.

## Cache

Detected cache artifacts:

- `config/cache.dir.php`
- `core/Cache/FileCacheAdapter.php`
- `core/Cache/RedisCacheAdapter.php`
- optional Redis configuration comments in `config/blesta.php`

If Redis is enabled, configure host, port, password, database, prefix, and timeouts through the application config mechanism used by the deployment.

## CI/CD and Infrastructure

The quick scan did not find these deployment automation files:

- `.github/workflows/*`
- `.gitlab-ci.yml`
- `Jenkinsfile`
- `Dockerfile`
- `docker-compose*.yml`
- Kubernetes manifests
- Terraform or Pulumi project files

Deployment is therefore documented as a traditional PHP application deployment rather than a container/IaC workflow.

## Release Manifest

`manifest.json` reports:

- version: `6.0.0-b1`
- generated timestamp: `2026-05-20T21:13:42Z`

The manifest also lists release files and hashes. Use it as a release integrity signal when validating packaged distributions.

## Operational Risks

- Database credentials are stored in a PHP config file in this checkout.
- Route behavior may depend on web server rewrite support.
- Composer installs extension packages directly into runtime folders.
- Test scripts depend on a sibling `../tests` path that was not present in this checkout.
- No CI/CD or container deployment definition was detected.

## Deployment Checklist

1. Install Composer dependencies with production flags.
2. Configure environment-specific `config/blesta.php` values.
3. Confirm MySQL connectivity and required PHP extensions.
4. Confirm web server rewrite behavior for admin, client, API, plugin/widget, feed, and callback routes.
5. Run install or upgrader flow as appropriate.
6. Configure scheduled task invocation for cron behavior.
7. Verify admin login, client routing, API route dispatch, payment callbacks, and plugin/widget dispatch.
8. Confirm logs, cache, upload, and generated-file directories are writable according to deployment needs.

---

_Generated using BMAD Method `document-project` workflow_

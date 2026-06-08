# whmcs_blesta Data Models

**Date:** 2026-06-09  
**Scan Level:** Quick scan

## Scope

This document inventories data-model and schema locations from file names, directory structure, and configuration files. It does not claim table columns, relationships, or constraints beyond the presence of schema/upgrade artifacts because the quick scan did not parse source or SQL bodies.

## Database Configuration

Database bootstrap files:

- `config/database.php`
- `config/blesta.php`

The application uses a MySQL database profile through PDO. `config/database.php` enables lazy connections, sets PDO fetch mode, configures connection reuse, loads `blesta`, and sets `Database.profile` from `Blesta.database_info`.

Security note: `config/blesta.php` contains database credential fields in this checkout. Do not copy credential values into generated documentation, issue comments, or logs.

## Core Model Layer

Location: `app/models`

Quick-scan count: 70 top-level model files.

Detected model domains include:

| Model File | Domain Signal |
| --- | --- |
| `accounts.php` | Account records and billing/account relationships |
| `actions.php` | Action records or action registry |
| `ai_conversations.php` | AI conversation records |
| `ai_messages.php` | AI message records |
| `api_keys.php` | API key records |
| `backup.php` | Backup settings or backup jobs |
| `blacklist.php` | Blacklist records |
| `calendar_events.php` | Calendar event records |
| `client_groups.php` | Client group records |
| `clients.php` | Client records |
| `companies.php` | Company records |
| `contacts.php` | Contact records |
| `coupon_package_options.php` | Coupon/package option relationship records |
| `coupon_terms.php` | Coupon term records |
| `coupons.php` | Coupon records |
| `cron_tasks.php` | Scheduled task records |
| `currencies.php` | Currency records |
| `data_feeds.php` | Data feed records |
| `electronic_invoices.php` | Electronic invoice records |
| `email_groups.php` | Email group records |
| `email_html_templates.php` | HTML email template records |
| `email_snapshots.php` | Email snapshot records |
| `email_verifications.php` | Email verification records |
| `emails.php` | Email records and templates |
| `encryption.php` | Encryption settings or metadata |
| `gateway_manager.php` | Gateway registration/management records |
| `invoices.php` | Invoice records |
| `languages.php` | Language pack records |
| `logs.php` | Log records |
| `managed_accounts.php` | Managed account records |
| `marketplace.php` | Marketplace records or integration state |
| `message_groups.php` | Message group records |
| `messages.php` | Message records |
| `module_client_meta.php` | Module client metadata |
| `module_manager.php` | Module registration/management records |
| `module_types.php` | Module type records |
| `notifications.php` | Notification records |
| `package_groups.php` | Package group records |
| `package_option_condition_sets.php` | Package option condition set records |
| `package_option_conditions.php` | Package option condition records |
| `package_options.php` | Package option records |
| `packages.php` | Package records |
| `password_resets.php` | Password reset records |
| `payments.php` | Payment records |
| `permissions.php` | Permission records |
| `plugin_manager.php` | Plugin registration/management records |
| `pricings.php` | Pricing records |
| `quotations.php` | Quotation records |
| `report_manager.php` | Report registration/management records |
| `service_changes.php` | Service change records |
| `service_invoices.php` | Service/invoice relationship records |
| `services.php` | Service records |
| `settings.php` | Settings records |
| `staff.php` | Staff records |
| `staff_groups.php` | Staff group records |
| `states.php` | State/region records |
| `system_events.php` | System event records |
| `system_upgrade.php` | System upgrade state |
| `tax_providers.php` | Tax provider records |
| `taxes.php` | Tax records |
| `themes.php` | Theme records |
| `transactions.php` | Transaction records |
| `users.php` | User records |

## Plugin Model Layer

Location: `plugins/*/models`

Quick-scan count: 53 plugin model files under plugin model folders. Plugin models should be treated as plugin-owned data/domain logic and kept within plugin boundaries unless a core integration contract requires otherwise.

## Schema and Migration Artifacts

Detected schema SQL:

- `components/upgrades/db/schema.sql`
- `components/upgrades/db/3.0.0/1.sql`

Detected upgrade task location:

- `components/upgrades/tasks`

Upgrade task naming pattern:

- `upgrade<version>.php`
- Examples include `upgrade3_0_0_a4.php`, `upgrade4_12_1.php`, `upgrade5_13_7.php`, and `upgrade6_0_0_b1.php`

This indicates schema evolution is handled through versioned upgrade task files plus SQL schema artifacts.

## Database Access Pattern

The quick scan found core database helpers:

- `core/Database/Record.php`
- `core/Database/QueryLogger.php`

The model base file is:

- `app/app_model.php`

A deep scan should inspect these files to confirm query builder conventions, transaction handling, model loading, and relationship patterns.

## Schema Change Guidance

When changing data behavior:

1. Identify the owning model under `app/models` or `plugins/*/models`.
2. Inspect existing upgrade tasks near the target release version.
3. Add or adjust schema artifacts under `components/upgrades/db` or `components/upgrades/tasks`.
4. Verify install and upgrade paths, not only fresh schema behavior.
5. Confirm language/admin/client UI changes that expose the new data.

## Deep-Scan Targets

Run a deep scan before high-risk database work to extract:

- Table names and fields from SQL files
- Relationships and foreign keys
- Model method signatures
- Transaction boundaries
- Validation rules
- Data retention and deletion behavior
- Upgrade task idempotency and ordering

---

_Generated using BMAD Method `document-project` workflow_

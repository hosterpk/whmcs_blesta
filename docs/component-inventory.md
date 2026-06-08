# whmcs_blesta Component Inventory

**Date:** 2026-06-09  
**Scan Level:** Quick scan

## Summary

The application has a large extension-oriented component surface. Components are split across core platform packages, reusable component subsystems, modules, gateways, plugins, views/themes, helpers, and localization packs.

Quick-scan counts:

- 70 application controllers in `app/controllers`
- 70 application models in `app/models`
- 454 files under `app/views`
- 41 module directories under `components/modules`
- 12 merchant gateway directories under `components/gateways/merchant`
- 27 non-merchant gateway directories under `components/gateways/nonmerchant`
- 23 plugin directories under `plugins`
- 23 language directories under `language`

## Application Components

### Controllers

Location: `app/controllers`

The controller layer includes admin, client, API, cron, feed, callback, upload, install, and theme-related surfaces. Route conventions in `config/routes.php` map admin paths to `admin_*` controllers and client paths to `client_*` controllers.

Important controller surfaces:

- `api.php`
- `cron.php`
- `feed.php`
- `callback.php`
- `uploads.php`
- `admin_*` controllers
- `client_*` controllers

### Models

Location: `app/models`

The model layer includes business domains such as clients, services, packages, invoices, transactions, accounts, coupons, contacts, taxes, currencies, users, permissions, logs, settings, reports, modules, gateways, plugins, email, AI conversations, and cron tasks.

### Views and Themes

Location: `app/views`

Major view groups:

- `app/views/admin`
- `app/views/client`
- `app/views/default`
- `app/views/errors`

The tree includes `.pdt` templates plus static assets such as CSS, JavaScript, images, fonts, and webfonts.

## Core Components

Location: `core`

| Component | Purpose |
| --- | --- |
| `core/Automation` | Automation task factory, task types, and automation docs |
| `core/Cache` | Cache adapters and cache factory, including Redis and file cache adapter classes |
| `core/Database` | Record/query helper layer |
| `core/Pricing` | Pricing domain package and related presenters/modifiers/meta items |
| `core/ServiceProviders` | Bootstrap and service registration classes |
| `core/Util` | Shared utility packages for AI, captcha, components, data feeds, events, filters, FTP, GeoIP, helpers, schemas, validation, widgets, and transport |

## Platform Components

Location: `components`

| Component Family | Location | Purpose |
| --- | --- | --- |
| Auth | `components/auth` | Authentication adapters including LDAP, MOTP, and OATH |
| Delivery | `components/delivery` | Delivery method abstractions and providers |
| Download | `components/download` | Download support |
| Email | `components/email` | Email subsystem |
| Exchange Rates | `components/exchange_rates` | Currency-layer, Fixer, Open Exchange Rates, and X-Rates providers |
| Gateway Payments | `components/gateway_payments` | Gateway payment support |
| Gateways | `components/gateways` | Merchant and non-merchant payment gateway integrations |
| Invoice Delivery | `components/invoice_delivery` | Invoice delivery subsystem |
| Invoice Formats | `components/invoice_formats` | Invoice format implementations |
| Invoice Templates | `components/invoice_templates` | Invoice template implementations |
| Messengers | `components/messengers` | Messaging integrations, including Twilio |
| Modules | `components/modules` | Hosting/domain/service provisioning modules |
| Net | `components/net` | HTTP, Amazon S3, and GeoIP networking helpers |
| Plugins | `components/plugins` | Plugin support library |
| Reports | `components/reports` | Report framework and reports |
| Security | `components/security` | Security component |
| Session Cart | `components/session_cart` | Session cart support |
| Settings Collection | `components/settings_collection` | Settings collection support |
| Upgrades | `components/upgrades` | Database schema and upgrade tasks |
| Upload | `components/upload` | Upload support |
| VCard | `components/vcard` | vCard support |

## Modules

Location: `components/modules`

Detected module directories:

`apnscp`, `blesta_license`, `centoswebpanel`, `centovacast`, `connectreseller`, `cpanel`, `cwatch`, `cyberpanel`, `direct_admin`, `enhance`, `enom`, `generic_domains`, `gogetssl`, `internetbs`, `interworx`, `ispconfig`, `ispmanager`, `logicboxes`, `multicraft`, `namecheap`, `namesilo`, `nominet`, `none`, `openprovider`, `opensrs`, `ovh_domains`, `plesk`, `proxmox`, `pterodactyl`, `realtime_register`, `solusvm`, `tcadmin`, `teamspeak`, `thesslstore_module`, `universal_module`, `vesta`, `virtfusion_direct_provisioning`, `virtualmin`, `vpsdotnet`, `vultr`, `whmsonic`.

## Payment Gateways

### Merchant Gateways

Location: `components/gateways/merchant`

`authorize_net`, `authorize_net_acceptjs`, `blue_pay`, `braintree`, `converge`, `cornerstone`, `eway`, `payflow`, `payjunction`, `quantum_gateway`, `stripe_gateway`, `stripe_payments`.

### Non-Merchant Gateways

Location: `components/gateways/nonmerchant`

`alipay`, `bitpay`, `blockonomics`, `btcpay_server`, `ccavenue`, `checkout2`, `coin_payments`, `coinbase_commerce`, `coingate`, `duitku`, `gocardless`, `hubtel`, `kassacompleet`, `kassacompleetideal`, `offline`, `pagseguro`, `payfast`, `paypal_checkout`, `paypal_payments_standard`, `paysera`, `paystack`, `payumoney`, `perfectmoney`, `razorpay`, `skrill`, `square`, `widepay`.

## Plugins

Location: `plugins`

Detected plugin directories:

`auto_cancel`, `billing_overview`, `client_cards`, `client_documents`, `cms`, `domains`, `download_manager`, `extension_generator`, `feed_reader`, `import_manager`, `ip_unblocker`, `mass_mailer`, `order`, `phpids`, `reassign_pricing`, `shared_login`, `sitebuilder`, `softaculous`, `support_manager`, `system_overview`, `system_status`, `thesslstore`, `webhooks`.

Quick-scan plugin pattern counts:

- 66 plugin controller files under `plugins/*/controllers`
- 53 plugin model files under `plugins/*/models`
- 174 plugin view files under `plugins/*/views`

## Localization Packs

Location: `language`

Detected locale directories:

`ar_xa`, `bg_bg`, `cs_cz`, `da_dk`, `de_de`, `el_gr`, `en_us`, `es_es`, `fr_fr`, `he_il`, `id_id`, `it_it`, `ko_kr`, `nl_nl`, `pl_pl`, `pt_br`, `pt_pt`, `ro_ro`, `ru_ru`, `sv_se`, `tr_tr`, `uk_ua`, `zh_cn`.

## Helpers

Location: `helpers`

Detected helper families:

- `color`
- `css`
- `currency_format`
- `data_structure`
- `settings_processor`
- `text_parser`
- `widget`
- `widget_client`

## Reuse Guidance

- Prefer existing `app/models` domains when adding business behavior to the core app.
- Add admin/client UI behavior through existing route/controller/view conventions.
- Put extension-specific behavior inside the relevant plugin/module/gateway/messenger/report tree.
- Use `core/Util` and `helpers` before introducing new standalone utility locations.
- Follow Composer installer-path conventions for new Blesta package types.

## Quick-Scan Limitation

This inventory is directory and filename based. It does not validate class signatures, public methods, dependency graphs, or component APIs. Use a deep scan for implementation-level reuse planning.

---

_Generated using BMAD Method `document-project` workflow_

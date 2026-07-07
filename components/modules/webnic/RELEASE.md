# WebNIC Release Packaging Checklist

Use this checklist before tagging or distributing a WebNIC module release.
The module ships as `components/modules/webnic/` inside a Blesta installation.

## Release version

- [x] Confirm `config.json` version is the intended release version.
  - Current GA version: **1.9.1**.
  - The 1.x line is a sequence of upgrade-guard milestones (`upgrade()` branches on `< 1.1.0`, `< 1.2.0`, `< 1.9.1`). Only bump if the upgrade matrix from WN-6.3 is re-verified.

## Packaging metadata

- [x] `composer.json` declares:
  - `"type": "blesta-module"`
  - `"name": "blesta/webnic"`
  - `"license": "proprietary"`
  - `"require": { "blesta/composer-installer": "~1.0" }`
  - Shape matches bundled registrar precedents (`components/modules/logicboxes/composer.json`, `components/modules/enom/composer.json`).
- [x] `config.json` declares:
  - `"type": "registrar"`
  - monotonic semantic `version`
  - `name` / `description` language keys
  - `package.name_key: "domain"` and `service.name_key: "domain"`
  - `module` row keys
  - `email_tags.service` contains `"domain"`
  - `features` (`dns_management`, `id_protection`, `epp_code`, `email_forwarding`)
  - `icon`
- [x] `README.md` documents purpose, requirements (Blesta 6.0+, PHP 8.2), install via Blesta module flow, Domain Manager integration contract, and current version.
- [x] No secrets, no `config/blesta.php` values, no internal OTE credentials in any committed file.

## Shippable file set

The module-owned, shippable file set is:

- `webnic.php`
- `apis/`
- `lib/`
- `config/`
- `language/`
- `views/`
- `composer.json`
- `config.json`
- `README.md`
- `phpcs.xml.dist`
- `RELEASE.md` (this file)

### Decision: `tests/` does not ship in the distributable

- `tests/` (including `tests/fixtures/` and `tests/integration/`) is **dev-only** and is excluded from the distributable artifact.
- Rationale: fixtures and integration harnesses are repository verification gates. They replay captured responses through an injectable transport seam and are not needed at runtime. The distributable artifact contains only module runtime code, packaging metadata, and this checklist.
- `tests/` remains committed to the repo and is run through the sanctioned Docker toolchain (`make test`).

## Working-tree hygiene

- [x] `git status --short --untracked-files=all` is empty (all changes committed).
- [x] No unrelated diffs in core `app/`, other modules, gateways, plugins, or `vendors/`.

## Quality gates (Docker toolchain only)

Run all gates through the gitignored Docker stack at `docker/` (PHP 8.2, PHPUnit 8.5, PHPCS 4.0.1).
Never use a host-global `php`, `phpunit`, `phpcs`, or `composer`.

- [x] `cd docker && make lint` — inspect output for `No syntax errors detected` on every PHP file; no parse/fatal errors.
- [x] `cd docker && make test` — green; expected release gate is 802 tests / 3027 assertions / 0 failures / 2 skipped.
  - The 2 skips are intentional OTE-async-capture-pending markers (`WebnicRegisterFixturesTest::testOrderInfoResolvedFixtureStaysFrozen`, `WebnicTransfersTest::testTransferSubmitSuccessFixtureStaysFrozen`). They must stay skipped, never faked or silently dropped.
- [x] `cd docker && make cs` — PHPCS reports **0 ERRORs** against `components/modules/webnic/phpcs.xml.dist`. Warnings (e.g. soft line-length on long legacy lines) are reported but do not fail the gate; the `make cs` target sets `ignore_warnings_on_exit` so only ERRORs cause a non-zero exit.
- [x] Confirm fixture-replay suites make **zero** live WebNIC/OTE/production calls. The injectable transport seam (`WebnicApi` transport) replays `tests/fixtures/webnic/*.json` only.

## Build artifact

- [x] The distributable is the contents of `components/modules/webnic/` minus `tests/` and minus the gitignored `docker/` toolchain.
- [ ] If building a zip/tar, exclude `tests/`, `.git*`, and any local IDE or OS files.

## Post-release

- [ ] Tag or record the release version in the project tracker.
- [x] Keep this checklist with the module; update it if the version policy or shippable file set changes.

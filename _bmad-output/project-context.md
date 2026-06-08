---
project_name: 'whmcs_blesta'
user_name: 'Israr'
date: '2026-06-09'
sections_completed: ['technology_stack', 'language_rules', 'framework_rules', 'testing_rules', 'quality_rules', 'workflow_rules', 'anti_patterns']
status: 'complete'
rule_count: 85
optimized_for_llm: true
existing_patterns_found: 9
existing_context_found: false
---

# Project Context for AI Agents

_This file contains critical rules and patterns that AI agents must follow when implementing code in this project. Focus on unobvious details that agents might otherwise miss._

---

## Technology Stack & Versions

- PHP app: Blesta `6.0.0-b1`, Composer package `blesta/blesta`, proprietary.
- Runtime PHP: PHP `>=8.2.0`; Composer platform is pinned to PHP `8.2`. Do not add PHP 8.3+ syntax, APIs, or package requirements without explicit approval.
- Dependency path: Composer installs to `vendors/`, not `vendor/`; autoload and tooling paths must account for this.
- Architecture: PHP MVC monolith using Blesta/minPHP routing. Request flow is `index.php -> lib/init.php -> config/services.php -> config/routes.php -> app/controllers or extension controllers -> models/components/core -> MySQL/external integrations`.
- Services/routes: `config/services.php` registration order matters; preserve it unless intentionally changing bootstrap behavior. Routes follow Blesta conventions: admin paths map to `admin_*`, client paths to `client_*`, plus API/feed/plugin/widget routes.
- Database: MySQL via PDO. Required extensions include `ext-pdo`, `ext-pdo_mysql`, `ext-curl`, and `ext-openssl`. Do not introduce a new ORM or database abstraction.
- Templates: Use existing `.pdt` template conventions. Do not introduce Twig, Blade, or a second view engine.
- Package layout: Composer installer paths place Blesta extensions directly into runtime folders such as `plugins/`, `components/modules/`, `components/gateways/*/`, `components/messengers/`, `components/reports/`, invoice folders, and language root paths.
- Testing/tooling: Composer scripts call PHPUnit against `../tests`; this checkout does not include that tests directory. Dev tooling includes PHPUnit `~8.5`, PHPCS `~4.0`, and Slevomat `~8.24.0`.
- Frontend tooling: No root Node app. `plugins/order/package-lock.json` is plugin-local and should not be treated as a repo-wide frontend stack.
- Sensitive config: `config/blesta.php` contains database credentials; do not expose, copy, or normalize it into docs, logs, examples, or commits.

## Critical Implementation Rules

### Language-Specific Rules

- Target PHP 8.2 syntax and runtime behavior. Do not introduce PHP 8.3+ syntax, attributes, standard-library APIs, or package assumptions while Composer platform is pinned to PHP 8.2.
- Preserve each file family's namespace style. `core/` uses `Blesta\Core\...`, many `app/models` use `Blesta\App\Models`, and controllers, plugins, modules, gateways, reports, and helpers commonly use legacy global classes. Do not add namespaces to legacy extension files unless that extension already uses one.
- Do not add `declare(strict_types=1)` broadly. The codebase mixes legacy and modern PHP typing; match the target file's existing type-hint style.
- Preserve inherited Blesta method signatures. Do not add parameter or return types to framework override methods unless the parent contract already supports them.
- Load Blesta dependencies through established `Loader` APIs: `Loader::loadModels`, `Loader::loadComponents`, `Loader::loadHelpers`, and `Loader::load(...)`. Do not replace local extension-loading conventions with ad hoc Composer assumptions.
- Keep user-facing text in language files and retrieve it with `Language::_(...)`. Add `$lang[...]` keys under the owning `language/<locale>/` or extension-local language directory instead of hard-coding labels, messages, or validation text. Call `Language::loadLang(...)` where the target file pattern requires explicit language loading.
- Use Blesta `Input` flows for validation: `$this->Input->setRules(...)`, `$this->Input->validates(...)`, `$this->Input->setErrors(...)`, and model `errors()`. Do not invent parallel validation or error formats.
- Use existing transaction patterns for multi-write operations: `begin()`, `commit()`, and `rollBack()` on the owning model or service. Every failure path between `begin()` and `commit()` must roll back before returning errors or rethrowing.
- Use Blesta `Record` query builder patterns already present in models and components. Use allowlists before passing request-controlled field names, sort keys, table names, or operators into `where`, `order`, `insert`, or `update`; avoid raw SQL unless the surrounding file already uses it for that case.
- Preserve event hooks where present, including `executeAndParseEvent(...)` and plugin event observers. Do not skip pre/post event contracts around existing model actions.
- Follow existing PHPDoc block style for public methods that define framework contracts, parameters, return values, or extension APIs. Keep comments useful; do not add boilerplate comments to obvious code.

### Framework-Specific Rules

- Preserve Blesta/minPHP route conventions instead of adding a new router. Admin URLs map to `admin_*` controllers, client URLs map to `client_*`, API routes dispatch through `Api`, feed routes through `Feed`, and plugin/widget routes dispatch directly into extension controllers.
- Controller setup belongs in `preAction()`. Always call `parent::preAction()` first, then load required models/helpers/language and set page state. Use existing controller helpers such as `$this->uses(...)`, `$this->helpers(...)`, `Language::loadLang(...)`, `$this->set(...)`, `$this->setMessage(...)`, `$this->flashMessage(...)`, `$this->redirect(...)`, `$this->setPagination(...)`, and `renderAjaxWidgetIfAsync(...)`.
- Preserve existing admin/client authorization, permission, company, and session checks. Do not add public controller methods, AJAX endpoints, or widget paths that bypass the parent controller flow.
- Keep core app behavior in `app/controllers`, `app/models`, and `app/views`; keep extension-specific behavior inside the owning `plugins/*`, `components/modules/*`, `components/gateways/*`, `components/messengers/*`, or `components/reports/*` tree.
- Persistence, validation, and error collection belong in models or owning extensions. Controllers should call existing model APIs and surface `$model->errors()` through Blesta message patterns, not embed SQL/business rules in `.pdt` views or controller branches.
- Preserve extension folder contracts. Plugins, modules, gateways, and reports commonly own their `config.json`, `config/`, `language/`, `views/default/*.pdt`, `lib/`, and local model/controller files. Do not move common-looking code out of an extension unless a core integration contract requires it.
- Do not cross-load plugin/module/gateway internals unless the target extension already exposes that integration path through config, actions, events, or libs. Prefer the target extension's existing service/client/API helpers and Blesta field/config patterns over direct controller-to-database shortcuts.
- Plugin lifecycle changes must use Blesta plugin hooks such as `install($plugin_id)`, `upgrade($current_version, $plugin_id)`, `uninstall($plugin_id, $last_instance)`, `getActions()`, and event registration methods. Respect multi-company behavior, especially `$last_instance` cleanup.
- Module and gateway changes must preserve Blesta extension APIs and UI field builders. Use `ModuleFields`, gateway `getSettings`/`editSettings`, `encryptableFields()`, and view-building patterns already present in the target extension.
- `.pdt` views should stay thin: render variables from controllers, use Blesta helpers for escaping/HTML/form behavior, and place inline JavaScript through existing Javascript helper patterns where the target view does so.
- Schema/runtime upgrade work belongs in `components/upgrades/db` and versioned `components/upgrades/tasks/upgrade*.php` classes. Upgrade task classes should expose `tasks()`, `process($task)`, idempotent task behavior, and undo-aware rollback behavior consistent with neighboring tasks; install/uninstall hooks are not substitutes for product upgrades.
- When changing service providers or bootstrap behavior, preserve `config/services.php` ordering unless the change intentionally modifies dependency initialization order and documents that risk.
- When adding or changing localized UI across admin/client/extensions, update the relevant Blesta language files for the affected locale surface; do not only update `en_us` if the existing extension maintains parallel locale files for the same key.

### Testing Rules

- Root Composer test scripts assume a sibling Blesta test suite: `composer test`, `composer test-unit`, `composer test-integration`, and `composer test-helpers` all run `phpunit ../tests...`. Verify that `../tests` exists and is the intended Blesta sibling test suite before claiming root PHPUnit coverage.
- Do not create a new root `tests/` layout just because this checkout lacks one. Add or update tests in the existing external/sibling test structure only when it is checked out and in scope, or use the owning component's existing local test layout if one already exists.
- Use PHPUnit `~8.5` for root test expectations. Do not assume PHPUnit 9/10 APIs in root-facing tests.
- Component-local test suites may have their own config and compatibility constraints. Example: `components/gateways/nonmerchant/coingate/build/phpunit.xml` runs tests under that component's `tests/` directory and uses legacy PHPUnit conventions. Follow the local suite before inventing a new runner, and do not modernize legacy PHPUnit fixtures unless the task includes test-runner migration.
- Ignore vendored dependency tests under extension `vendor/` folders unless the task explicitly modifies that vendored dependency. They are third-party package tests, not Blesta application coverage.
- For controller/model changes, test the model validation/error path and the controller message/redirect path separately where the available suite supports it. Do not rely only on view rendering checks for business behavior.
- For schema or upgrade changes, verify both fresh schema/install behavior and versioned upgrade behavior when the environment supports database-backed tests.
- For gateways/modules/plugins, prefer narrow tests around the owning extension's public Blesta API methods and avoid live external API calls unless the existing suite already uses a controlled sandbox pattern.
- Treat PHPCS/Slevomat as style gates, not behavioral tests; run them only through existing project commands/config when available.
- If the sibling test suite or required database/runtime services are unavailable, document the missing prerequisite and run the narrowest safe fallback, such as `php -l` on changed PHP files and targeted local component tests. Do not present lint-only or component-local checks as full root test coverage.

### Code Quality & Style Rules

- Follow the target file's local style before applying global preferences. This codebase mixes legacy Blesta PHP and newer namespaced code; keep diffs small and consistent with the surrounding file.
- There is no root `phpcs.xml`. Use the nearest owning PHPCS config when one exists; its label is `PSR2 Transitional`, not a repo-wide standard. Local configs require short array syntax, LF line endings, single quotes unless interpolation or escaping makes double quotes clearer, and one space around operators. Do not infer repo-wide PHPCS enforcement from component-local configs.
- Preserve Blesta file/class naming conventions. Route/controller files use snake_case names such as `admin_system_api.php` with CamelCase classes such as `AdminSystemApi`; model and extension files follow existing loader-derived names such as `clients.php` -> `Clients` and `order_forms.php` -> `OrderForms`.
- Do not broad-format or mechanically modernize large legacy files. Avoid churn in unrelated methods, language files, generated assets, protected files, and vendored code.
- Do not normalize `.pdt` templates for PHPCS-only reasons; local configs exempt templates from end-file-newline and line-length rules.
- Do not rewrap or mass-edit `language/*.php` files for line length. Preserve translation keys, ordering, quoting style, and placeholders unless the task targets copy changes.
- Treat ionCube-protected files as non-editable unless the task explicitly targets them and an editable source is available. Examples include `app/app_controller.php`, `app/app_model.php`, `app/controllers/admin_chatbot.php`, `app/controllers/admin_system_ai.php`, `app/models/license.php`, and `components/blesta_ai/blesta_ai.php`.
- Do not edit minified assets directly when an unminified source exists. Files such as `*.min.js` and `*.min.css` under `app/views/*` and plugin assets should be regenerated or left unchanged unless the task explicitly targets packaged output.
- Keep generated, dependency, and runtime artifacts out of implementation diffs. Root `vendors/`, `vendor/`, cache, logs, `.env*`, and runtime files are ignored; only mention them when documenting ignore/status rules.
- Use existing README/config/composer metadata inside the owning plugin/module/gateway before adding new conventions. Extension-local `composer.json`, `README.md`, `config.json`, and `phpcs.xml.dist` files are the first source of style truth for that extension.
- Keep comments and PHPDoc purposeful. Preserve docblocks for framework contracts and public extension APIs, but avoid adding narration comments that restate obvious code.

### Development Workflow Rules

- Do not invent CI/CD, Docker, Makefile, or deployment workflows. This checkout has no `.github/workflows`, `.gitlab-ci.yml`, `Jenkinsfile`, `Dockerfile`, Compose file, Makefile, or task runner; use Composer/PHP commands and documented Blesta runtime flows unless new automation is explicitly requested.
- Follow the repo's lightweight git style: branch from `main`/`origin/main` as needed and use concise descriptive commits.
- Commit messages must follow `<type>(<scope>): <summary>`.
- Allowed commit types are `feat`, `fix`, `docs`, `test`, `refactor`, and `chore`.
- Keep commit summaries imperative, lowercase, and under 72 characters.
- Treat `docs/` and `_bmad-output/` as generated/project-planning artifacts unless the task explicitly targets them. Do not mix BMAD/generated docs changes with runtime implementation changes unless the task explicitly asks for both.
- Before changing an area, check the nearest available project documentation and metadata: `docs/development-guide.md`, `docs/architecture.md`, the owning `README.md`, `composer.json`, `config.json`, and local configs.
- Use a normal PHP web stack for route-sensitive manual verification. The PHP built-in server may be useful for limited smoke checks, but it may not match `.htaccess` or production rewrite behavior.
- Preserve traditional PHP app runtime expectations: Composer dependencies in `vendors/`; web server rewrite support; writable cache/upload/log paths; configured `config/blesta.php`; MySQL connectivity; and scheduled task routing through Blesta cron/controller surfaces.
- Preserve the documented Composer vendor layout in `vendors/`. Do not rename it to `vendor/` or introduce alternate dependency paths without an explicit migration task.
- Treat `config/blesta.php` as local runtime configuration. Do not reformat, normalize, relocate, copy secrets from, or commit environment-specific config changes unless the task explicitly targets configuration.
- For schema-affecting work, plan both fresh-install and upgrade verification for the owning Blesta surface. Data-shape changes should include the appropriate install/upgrade artifacts or documented project mechanism, not only ad hoc SQL, runtime side effects, or uninstall/reinstall assumptions.
- For PHP changes, run syntax checks on touched PHP files with `php -l`. Run Composer checks only where applicable, such as `composer validate` after `composer.json` or `composer.lock` changes. Run test suites only when the repo provides them; do not invent `../tests` as required infrastructure.
- Do not copy secrets or environment-specific values from `config/blesta.php`, `.env*`, logs, cache, or runtime files into docs, commits, examples, reports, or issue comments.
- When verification cannot run because `../tests`, a database, Composer dependencies, or a PHP web stack is unavailable, state the missing prerequisite and list the fallback commands actually run.
- Preserve current git/user changes outside the task scope. The repo may contain generated docs or BMAD artifacts in progress; do not clean, revert, or reorganize them unless explicitly asked.

### Critical Don't-Miss Rules

- Do not treat this as a greenfield PHP app. Preserve Blesta/minPHP routing, loader behavior, extension folder contracts, `.pdt` views, and language files.
- Do not move extension code into core app folders. Plugins, modules, gateways, messengers, reports, invoice formats/templates, and language packages are discovered by location.
- Do not assume dependencies, tests, or autoloaders live in default PHP project paths. Runtime dependencies use `vendors/`, Composer installer paths write into Blesta runtime folders, root tests expect the intended sibling `../tests`, and extensions may contain local `vendor/` or `vendors/` trees that are not repo-wide dependencies.
- Do not run broad dependency or installer commands casually. `composer install/update` may affect `vendors/` and extension install paths under `plugins/` or `components/*`; inspect expected file impact before committing dependency churn.
- Do not bypass Blesta authorization, company scoping, session state, or parent controller setup for admin/client routes, AJAX handlers, widgets, plugin endpoints, APIs, or feeds.
- Do not bypass established Blesta/minPHP data and validation patterns. Use existing loaders, models, `Input`, `Record`, transactions, language files/errors, events, and model/service boundaries rather than direct includes, ad hoc globals, raw SQL, standalone scripts, request parsing, or validation flows.
- Do not trust webhook, callback, AJAX, cron, API, or feed payloads by default. Validate request shape, authorization/signature semantics where applicable, company context, and idempotency before mutating invoices, services, payments, domains, or client state.
- Do not expose secrets. Keep `config/blesta.php`, `.env*`, logs, cache, runtime files, gateway credentials, API keys, and payment metadata out of docs, reports, commits, and fixtures.
- Do not hand-edit ionCube-protected files, minified assets, vendored dependencies, generated folders, cache/log output, or release artifacts unless explicitly targeted and an editable source exists.
- Do not add schema-impacting runtime code without install/upgrade planning. Data-shape changes need matching schema or versioned upgrade artifacts.
- Do not introduce live external API dependencies into tests unless an existing controlled sandbox pattern is available.
- Do not make broad modernization changes for narrow defects. Avoid repo-wide namespace changes, strict-types sweeps, PHPDoc rewrites, formatting churn, translation rewraps, or framework replacement.
- Do not overstate verification. If only syntax checks, local component tests, or lint-style checks ran, say exactly that and list missing prerequisites.

---

## Usage Guidelines

**For AI Agents:**

- Read this file before implementing any code.
- Follow all rules exactly as documented.
- When in doubt, prefer the more restrictive option.
- Update this file if new project patterns emerge.

**For Humans:**

- Keep this file lean and focused on agent needs.
- Update it when technology stack or project conventions change.
- Review periodically for outdated rules.
- Remove rules that become obvious over time.

Last Updated: 2026-06-09

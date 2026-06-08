---
name: KuickPay Blesta Gateway
description: Visual contract for KuickPay payment, reconciliation, and support surfaces inside Blesta.
status: partial
sources:
  - "{planning_artifacts}/prds/prd-whmcs_blesta-2026-06-09/prd.md"
  - "{planning_artifacts}/prds/prd-whmcs_blesta-2026-06-09/addendum.md"
updated: 2026-06-09
colors:
  surface: "#FFFFFF"
  surface-muted: "#F8F9FA"
  surface-subtle: "#E9ECEF"
  text: "#212529"
  text-muted: "#6C757D"
  border: "#DEE2E6"
  primary: "#007BFF"
  primary-foreground: "#FFFFFF"
  success: "#28A745"
  success-foreground: "#FFFFFF"
  info: "#17A2B8"
  info-foreground: "#FFFFFF"
  warning: "#FFC107"
  warning-foreground: "#212529"
  danger: "#DC3545"
  danger-foreground: "#FFFFFF"
typography:
  page-title:
    note: "Inherited from Blesta admin/client heading styles."
  section-title:
    note: "Inherited from Blesta form section headers and card headers."
  body:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif"
    fontSize: "1rem"
    fontWeight: "400"
    lineHeight: "1.5"
    letterSpacing: "0"
  small:
    fontFamily: "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif"
    fontSize: "0.875rem"
    fontWeight: "400"
    lineHeight: "1.4"
    letterSpacing: "0"
  mono:
    fontFamily: "SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace"
    fontSize: "1rem"
    fontWeight: "600"
    lineHeight: "1.5"
    letterSpacing: "0"
rounded:
  sm: "0.2rem"
  md: "0.25rem"
  lg: "0.3rem"
spacing:
  "1": "0.25rem"
  "2": "0.5rem"
  "3": "1rem"
  "4": "1.5rem"
  "5": "3rem"
components:
  customer-reference-panel:
    background: "{colors.surface-muted}"
    border: "1px solid {colors.border}"
    radius: "{rounded.md}"
    text: "{colors.text}"
  consumer-number-block:
    background: "{colors.surface}"
    border: "1px solid {colors.border}"
    radius: "{rounded.md}"
    label: "{typography.small}"
    value: "{typography.mono}"
  instruction-group:
    background: "{colors.surface}"
    border: "1px solid {colors.border}"
    radius: "{rounded.md}"
    heading: "{typography.section-title}"
  status-badge-pending:
    background: "{colors.info}"
    foreground: "{colors.info-foreground}"
    radius: "{rounded.sm}"
  status-badge-paid:
    background: "{colors.success}"
    foreground: "{colors.success-foreground}"
    radius: "{rounded.sm}"
  status-badge-failed:
    background: "{colors.danger}"
    foreground: "{colors.danger-foreground}"
    radius: "{rounded.sm}"
  status-badge-expired:
    background: "{colors.text-muted}"
    foreground: "{colors.primary-foreground}"
    radius: "{rounded.sm}"
  status-badge-manual-review:
    background: "{colors.warning}"
    foreground: "{colors.warning-foreground}"
    radius: "{rounded.sm}"
  admin-data-table:
    background: "{colors.surface}"
    border: "1px solid {colors.border}"
    text: "{colors.text}"
  raw-diagnostic-block:
    background: "{colors.surface-muted}"
    border: "1px solid {colors.border}"
    radius: "{rounded.md}"
    text: "{typography.mono}"
  settings-section:
    background: "{colors.surface}"
    border: "none"
    heading: "{typography.section-title}"
  manual-review-callout:
    background: "{colors.warning}"
    foreground: "{colors.warning-foreground}"
    border: "1px solid {colors.warning}"
    radius: "{rounded.md}"
---

# KuickPay Blesta Gateway - Design Spine

## Brand & Style

KuickPay Blesta Gateway inherits the active Blesta client and admin visual systems. It should feel like a native payment and operations surface in Blesta, not a standalone campaign page or a new HosterPK microsite.

The visual posture is operational trust: clear references, legible amounts, restrained status signals, and no celebratory treatment around payment. The customer has not paid when a Voucher is generated; the interface must not make the generated state feel complete. Admin surfaces prioritize fast scanning, evidence, and safe action over decoration.

[ASSUMPTION] HosterPK brand tokens are not provided in the source PRD. Until they are supplied, the gateway uses Blesta theme inheritance and the semantic aliases in this file.

## Colors

The palette is a semantic alias layer over Blesta's Bootstrap-derived theme. The active company theme may override primary buttons and alert colors at runtime; implementation should prefer existing Blesta classes where possible.

- **Surface** `{colors.surface}` and **Surface Muted** `{colors.surface-muted}` provide ordinary card, panel, and reference-block backgrounds.
- **Primary** `{colors.primary}` is used only where Blesta would already use a primary action, such as Save Settings or Copy Consumer Number.
- **Success** `{colors.success}` means KuickPay evidence has been confirmed and posted or is safe to present as paid.
- **Info** `{colors.info}` means pending, informational, or retry-safe processing.
- **Warning** `{colors.warning}` means Manual Review, policy attention, late payment, underpayment, overpayment, or ambiguous evidence.
- **Danger** `{colors.danger}` means failed action, invalid configuration, blocked currency, or unrecoverable error.

Do not use success styling for Voucher generation, customer-side return, or unvalidated response text.

## Typography

Typography inherits from Blesta admin Paradigm and client Bootstrap surfaces. New KuickPay templates should use existing heading, form-label, table, alert, badge, and button classes instead of defining a custom type ramp.

Use `{typography.mono}` for Consumer Number, Registration Number, KuickPay transaction/auth/reference fields, and sanitized diagnostic blocks. Keep labels in normal inherited UI text; do not render the whole payment page in monospace.

## Layout & Spacing

Customer payment layout is single-column first. The Consumer Number, amount, due date, expiry date, and KuickPay identity must appear before instruction groups. Instruction groups stack vertically on small screens and may use a two-column grid only when all headings and instruction text remain readable.

Admin operations use Blesta's dense table and form layout patterns. Voucher list, run summary, and gateway logs should prioritize scan columns, filters, and action grouping over card-heavy presentation.

Spacing uses the inherited Bootstrap scale through `{spacing.1}` to `{spacing.5}`. Use `{spacing.3}` for normal field and row separation, `{spacing.4}` between major sections, and `{spacing.2}` inside compact reference blocks.

## Elevation & Depth

No custom elevation language is introduced. Use Blesta `Widget`, `WidgetClient`, `card`, `table`, `alert`, and modal/dialog conventions. Do not add marketing shadows, floating panels, gradient banners, or custom depth to make KuickPay feel separate from Blesta.

## Shapes

Corner radius follows Bootstrap defaults: `{rounded.sm}`, `{rounded.md}`, and `{rounded.lg}`. Badges may use `{rounded.sm}`. Avoid fully rounded pills unless the existing Blesta status badge pattern for that surface already uses them.

## Components

- **Customer reference panel** - Uses `{components.customer-reference-panel}`. Contains KuickPay name, amount, due date, expiry date, Consumer Number, and payment-status expectation. The panel is informational until reconciliation confirms payment.
- **Consumer Number block** - Uses `{components.consumer-number-block}`. Shows the Consumer Number in `{typography.mono}` with a nearby Copy action. The value must be visually grouped with the payable amount.
- **Instruction group** - Uses `{components.instruction-group}`. One group per enabled payment channel, such as online banking, bank deposit, agent/franchise, or mobile app. Disabled groups are not rendered.
- **Status badges** - Use status-specific tokens. Every badge must have adjacent text or table context that names the state; color alone is not the status contract.
- **Admin data table** - Uses `{components.admin-data-table}`. Voucher list and reconciliation results should use compact sortable/filterable table patterns with visible date, amount, Consumer Number, status, and transaction linkage.
- **Raw diagnostic block** - Uses `{components.raw-diagnostic-block}`. Admin-only, sanitized, scrollable when long, and never customer-visible.
- **Settings section** - Uses `{components.settings-section}`. Groups credentials, endpoint URLs, reference patterns, date policies, phone policy, instruction groups, logging, and reconciliation.
- **Manual Review callout** - Uses `{components.manual-review-callout}`. Appears on Voucher detail and reconciliation results when staff action is required. It must include reason and next action, not only the words "Manual Review."

## Do's and Don'ts

| Do | Don't |
|---|---|
| Inherit Blesta theme, widgets, cards, alerts, forms, badges, and tables | Create a custom standalone KuickPay visual system |
| Put Consumer Number and payable amount in the first visible customer block | Hide the Consumer Number inside instructions or a collapsed section |
| Use warning treatment for Manual Review and ambiguous evidence | Auto-style ambiguous states as success to reduce concern |
| Use monospace only for references and diagnostic evidence | Render general customer instructions as code-like text |
| Keep diagnostics admin-only and sanitized | Show raw SOAP responses or credentials in customer views |
| Pair every status color with readable status text | Rely on color alone to communicate payment truth |
| Use existing Blesta language files for copy | Hard-code customer/admin messages in templates |

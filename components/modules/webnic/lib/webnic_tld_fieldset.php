<?php

namespace Webnic;

/**
 * Pure presenter for the per-TLD "Registry requirements" fieldset (WN-3-4b, FR19/INV-8).
 *
 * Takes the output of WebnicPricing::mapExtensionRuleFields() (the raw WebNIC
 * `data.rules` map already classified into field types, with unknown keys flagged
 * required text) and returns a render-ready descriptor list for the four `get*Fields`
 * builders to turn into ModuleFields.
 *
 * The partition (Dev Notes §C) is the load-bearing call: a rule key is SURFACED iff it
 * is NOT in the suppress set. The default suppress set is every currently-mapped
 * constraint key (terms/NS/ID-protection/availability/transfer flags are consumed
 * elsewhere), so a well-known TLD surfaces nothing (AC3) while an UNKNOWN provider rule
 * — never in the suppress set, and flagged required text by the transform — surfaces as
 * a required text field labeled from the rule key (AC4/INV-8). The forward-compat spine:
 * a new per-TLD requirement WebNIC adds cannot let an incomplete registration through.
 *
 * Keeps the god-class lean (retro T4: collaborators live in lib/). No Blesta deps and no
 * clock — labels/help are injected as callables — so this mirrors the
 * WebnicStatus/ContactsMap pure-class precedent and is exhaustively unit-testable.
 */
class TldFieldset
{
    /**
     * Builds the ordered list of surfaced per-TLD requirement descriptors.
     *
     * @param array $rule_fields Output of WebnicPricing::mapExtensionRuleFields():
     *  rule key => ['field_type' => string, 'required' => bool, 'value' => mixed]
     * @param array $suppress Rule keys NOT surfaced as fieldset inputs (the partition)
     * @param callable $label fn(string $ruleKey): string — the human field label
     * @param callable $help  fn(string $ruleKey): string — inline help ('' = no tooltip)
     * @return array Ordered descriptors, each:
     *  ['name','field_type','required','value','label','help']. Empty array => the
     *  builder MUST emit no fieldset at all (AC3: render nothing, not an empty box).
     */
    public static function build(array $rule_fields, array $suppress, callable $label, callable $help): array
    {
        $suppressed = array_flip(array_values($suppress));

        $descriptors = [];
        foreach ($rule_fields as $name => $spec) {
            // A blank/non-string key can never be a real registry requirement input.
            if (!is_string($name) || $name === '') {
                continue;
            }

            // Suppressed keys are consumed by term/NS/ID-protection/availability logic
            // or are transfer/Epic-4 concerns — never rendered as fieldset inputs (§C).
            if (isset($suppressed[$name])) {
                continue;
            }

            $descriptors[] = [
                'name' => $name,
                'field_type' => is_array($spec) && isset($spec['field_type']) ? (string) $spec['field_type'] : 'text',
                // Default required=true: an unmapped requirement is never silently optional.
                'required' => is_array($spec) ? (bool) ($spec['required'] ?? true) : true,
                'value' => is_array($spec) ? ($spec['value'] ?? null) : null,
                'label' => (string) $label($name),
                'help' => (string) $help($name),
            ];
        }

        return $descriptors;
    }
}

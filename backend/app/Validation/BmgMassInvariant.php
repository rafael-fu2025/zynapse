<?php

declare(strict_types=1);

namespace App\Validation;

/**
 * Custom CI4 validation rule: `bmg_mass_invariant`.
 *
 * Verifies that the supplied value (e.g. `output_weight_kg`) does not
 * exceed the rule parameter — the batch's `total_input_weight_kg`.
 *
 * The mass invariant is the canonical safeguard for the BMG state
 * machine. The application service and the database trigger are the
 * second and third guards; failing this rule surfaces a clean 422
 * instead of a SQL error or a 409 from the state machine.
 *
 * Usage:
 *   'output_weight_kg' => 'required|decimal|greater_than[0]|bmg_mass_invariant[12.50]'
 *
 * @see docs/PROMPT.md §3 — custom rules for complex domain invariants
 */
final class BmgMassInvariant
{
    /**
     * CI4 calls custom rules with the field value first, then the
     * parameter string from `rule_name[param]`, then the full data
     * array, an error out-reference, and the field name — the framework
     * signature is `($value, $param, array $data, &$error, $field)`.
     * Declaring `$data` in any other position raises a TypeError at
     * validation time (surfaces as an opaque 500).
     *
     * @param mixed  $value  The field value being validated.
     * @param string $params The max kg (the batch's `total_input_weight_kg`).
     * @param array<string, mixed> $data Full validation payload (unused).
     */
    public function bmg_mass_invariant($value, string $params = '', array $data = [], ?string &$error = null, string $field = ''): bool
    {
        if (! is_numeric($value) || ! is_numeric($params)) {
            return false;
        }
        return (float) $value <= (float) $params;
    }
}
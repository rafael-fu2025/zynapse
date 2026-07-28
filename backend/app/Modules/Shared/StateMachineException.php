<?php

declare(strict_types=1);

namespace App\Modules\Shared;

use App\Exceptions\ApiException;

/**
 * StateMachineException — thrown when a Facilities entity refuses a
 * transition (e.g., starting a batch on a unit that already has one).
 *
 * Map to HTTP 409 via the standard pattern below.
 */
final class StateMachineException extends ApiException
{
    public static function invalidTransition(string $from, string $to, string $entity = 'bmg'): self
    {
        return new self(
            "statemachine.{$entity}.invalid_transition",
            409,
            [['code' => 'statemachine.invalid_transition', 'message' => "Cannot transition from {$from} to {$to}."]],
        );
    }

    public static function unitBusy(int $unitId): self
    {
        return new self(
            'statemachine.bmg.unit_busy',
            409,
            [['code' => 'statemachine.bmg.unit_busy', 'message' => "BMG unit #{$unitId} already has an unfinished batch."]],
        );
    }

    public static function massInvariant(): self
    {
        return new self(
            'statemachine.bmg.mass_invariant',
            422,
            [['code' => 'statemachine.bmg.mass_invariant', 'message' => 'output_weight_kg exceeds total_input_weight_kg.']],
        );
    }
}
<?php

declare(strict_types=1);

namespace App\Modules\Shared;

/**
 * Base DTO helper. DTOs are dumb value objects — no logic.
 *
 * Subclasses MUST define `public array $data` (or properties) populated
 * via `fromArray()`, and MUST NOT pull from a Model directly.
 *
 * Implementing `\JsonSerializable` here is what makes `json_encode($dto)`
 * and CI4's `Response::setJSON($dto)` return the DTO's array shape
 * instead of `{}` (PHP only invokes `jsonSerialize()` on objects that
 * declare they implement the interface). Keep the implementation here
 * so every module DTO is serialized uniformly.
 */
abstract class BaseDTO implements \JsonSerializable
{
    /**
     * Whitelist keys that may be exposed via JSON serialization.
     */
    abstract public function jsonSerialize(): array;

    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
}
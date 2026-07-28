<?php

declare(strict_types=1);

namespace Modules\Counselling\DTOs;

use App\Modules\Shared\BaseDTO;

final class NoteDto extends BaseDTO
{
    public function __construct(
        private readonly int $sessionId,
        private readonly string $plaintext,
        private readonly int $keyVersion,
        private readonly string $createdAt,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'session_id'   => $this->sessionId,
            'plaintext'    => $this->plaintext,
            'key_version'  => $this->keyVersion,
            'created_at'   => $this->createdAt,
        ];
    }
}
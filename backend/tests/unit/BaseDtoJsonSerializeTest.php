<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Shared\BaseDTO;
use JsonSerializable;
use PHPUnit\Framework\TestCase;

/**
 * Pin `BaseDTO` to `\JsonSerializable`.
 *
 * The CI4 `Response::setJSON()` ultimately calls `json_encode($data)`,
 * and PHP only invokes an object's `jsonSerialize()` method when that
 * object implements the `\JsonSerializable` interface. Without the
 * interface declaration, every controller that returned a DTO directly
 * (e.g. `$this->ok($this->service->createUnit($input), null, 201)`)
 * was being serialized as `{}` — the SPA received an empty object,
 * the schema parse failed, and the user had to reload.
 *
 * If a future refactor drops the `implements`, this test fails.
 */
final class BaseDtoJsonSerializeTest extends TestCase
{
    public function testBaseDtoImplementsJsonSerializable(): void
    {
        $rc = new \ReflectionClass(BaseDTO::class);
        $this->assertTrue(
            $rc->implementsInterface(JsonSerializable::class),
            'BaseDTO must implement \\JsonSerializable so Response::setJSON(dto) yields the DTO array, not {}',
        );
    }

    public function testConcreteDtoSerializesToArrayShape(): void
    {
        // Use one of the concrete DTOs as a stand-in. The point is
        // `json_encode($dto)` returns the array, not `{}`.
        $dto = new class extends BaseDTO {
            public function jsonSerialize(): array
            {
                return ['id' => 7, 'code' => 'TST'];
            }
        };

        $this->assertSame('{"id":7,"code":"TST"}', json_encode($dto, JSON_THROW_ON_ERROR));
    }
}

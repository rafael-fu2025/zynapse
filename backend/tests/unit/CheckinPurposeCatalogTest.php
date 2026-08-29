<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\ApiException;
use App\Services\Kiosk\CheckinPurposeCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckinPurposeCatalogTest extends TestCase
{
    public static function validPurposes(): array
    {
        return [
            ['clinic', 'Consultation'],
            ['counselling', 'Initial Consultation'],
        ];
    }

    #[DataProvider('validPurposes')]
    public function testAcceptsOnlyDestinationPurpose(string $destination, string $purpose): void
    {
        $this->assertSame($purpose, CheckinPurposeCatalog::validate($destination, $purpose, false));
    }

    public function testRejectsCrossDestinationPredefinedPurpose(): void
    {
        $this->expectException(ApiException::class);
        CheckinPurposeCatalog::validate('clinic', 'Initial Consultation', false);
    }

    public function testOtherRequiresExplicitCustomFlag(): void
    {
        $this->assertSame('Private concern', CheckinPurposeCatalog::validate('counselling', ' Private concern ', true));
        $this->expectException(ApiException::class);
        CheckinPurposeCatalog::validate('counselling', 'Private concern', false);
    }

    public function testCustomCannotMasqueradeAsPredefinedPurpose(): void
    {
        $this->expectException(ApiException::class);
        CheckinPurposeCatalog::validate('counselling', 'Consultation', true);
    }
}

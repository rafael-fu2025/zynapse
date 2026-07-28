<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Validation\BmgMassInvariant;
use PHPUnit\Framework\TestCase;

final class BmgMassInvariantTest extends TestCase
{
    private BmgMassInvariant $rule;

    protected function setUp(): void
    {
        $this->rule = new BmgMassInvariant();
    }

    public function testOutputBelowInputPasses(): void
    {
        $this->assertTrue($this->rule->bmg_mass_invariant('7.25', '10.0'));
    }

    public function testOutputEqualToInputPasses(): void
    {
        $this->assertTrue($this->rule->bmg_mass_invariant('10.0', '10.0'));
    }

    public function testOutputAboveInputFails(): void
    {
        $this->assertFalse($this->rule->bmg_mass_invariant('10.01', '10.0'));
    }

    public function testNonNumericValueFails(): void
    {
        $this->assertFalse($this->rule->bmg_mass_invariant('ten', '10.0'));
        $this->assertFalse($this->rule->bmg_mass_invariant(null, '10.0'));
        $this->assertFalse($this->rule->bmg_mass_invariant('5.0', ''));
    }
}

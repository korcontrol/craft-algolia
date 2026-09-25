<?php
declare(strict_types=1);

namespace korcontrol\craftalgolia\tests\Unit;

use Codeception\Test\Unit;
use Tests\Support\UnitTester;

final class SmokeTest extends Unit
{
    protected UnitTester $tester;

    public function test_codeception_is_working(): void
    {
        $this->assertTrue(true, 'Codeception is set up and running.');
    }
}
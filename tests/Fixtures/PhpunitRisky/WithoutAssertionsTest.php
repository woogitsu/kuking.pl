<?php

declare(strict_types=1);

namespace Tests\Fixtures\PhpunitRisky;

use PHPUnit\Framework\TestCase;

class WithoutAssertionsTest extends TestCase
{
    public function test_without_assertions(): void
    {
        // Celowo pusto: przyrząd mierzy rzeczywiste zgłoszenie risky przez PHPUnit.
    }
}

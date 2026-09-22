<?php

declare(strict_types=1);

namespace Tests\Fixtures\PhpunitRisky;

use PHPUnit\Framework\TestCase;

class WithAssertionTest extends TestCase
{
    public function test_with_assertion(): void
    {
        $this->assertSame(['portion' => 2], json_decode('{"portion":2}', true, flags: JSON_THROW_ON_ERROR));
    }
}

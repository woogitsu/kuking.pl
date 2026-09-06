<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Odmiana;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OdmianaTest extends TestCase
{
    /** @return list<array{0: int, 1: string}> */
    public static function liczby(): array
    {
        return [
            [0, 'przepisów'],
            [1, 'przepis'],
            [2, 'przepisy'],
            [3, 'przepisy'],
            [4, 'przepisy'],
            [5, 'przepisów'],
            // Nastki są wyjątkiem: kończą się cyfrą 2-4, ale biorą „wiele".
            // Właśnie ich brakowało w dwustanowej wersji z wyszukiwarki.
            [12, 'przepisów'],
            [13, 'przepisów'],
            [14, 'przepisów'],
            [21, 'przepisów'],
            [22, 'przepisy'],
            [25, 'przepisów'],
            [102, 'przepisy'],
            [112, 'przepisów'],
            [200, 'przepisów'],
        ];
    }

    #[DataProvider('liczby')]
    public function test_odmiana_rzeczownika(int $ile, string $oczekiwana): void
    {
        $this->assertSame(
            $oczekiwana,
            Odmiana::rzeczownik($ile, 'przepis', 'przepisy', 'przepisów'),
            "Zła odmiana dla liczby {$ile}.",
        );
    }
}

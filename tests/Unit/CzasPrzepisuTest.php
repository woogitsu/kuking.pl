<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Czas;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Czas przepisu po ludzku (decyzja właściciela z 1.10.2026, D-333):
 * poniżej 90 minut „45 min", od 90 godziny — „1 godz. 30 min", „2 godz.".
 */
class CzasPrzepisuTest extends TestCase
{
    /** @return array<string, array{int, string}> */
    public static function przypadki(): array
    {
        return [
            '45 min' => [45, '45 min'],
            '89 min zostaje w minutach (zgodnie z filtrem)' => [89, '89 min'],
            '31 min zostaje dokładnie (filtr „Do 30 minut” go pomija)' => [31, '31 min'],
            '91 min autora to około 1 godz. 30 min' => [91, '1 godz. 30 min'],
            '93 min zaokrągla się do 95' => [93, '1 godz. 35 min'],
            '122 min zaokrągla się do 2 godz.' => [122, '2 godz.'],
            '90 min to pierwsza godzina z połową' => [90, '1 godz. 30 min'],
            '95 min' => [95, '1 godz. 35 min'],
            '120 min to równe godziny, bez minut' => [120, '2 godz.'],
            '125 min' => [125, '2 godz. 5 min'],
            'doba' => [1440, '24 godz.'],
        ];
    }

    #[DataProvider('przypadki')]
    public function test_format_czasu(int $minuty, string $oczekiwany): void
    {
        $this->assertSame($oczekiwany, Czas::czasPrzepisu($minuty));
    }
}

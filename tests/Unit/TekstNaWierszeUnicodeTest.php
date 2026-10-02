<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Recipes\TekstNaWiersze;
use PHPUnit\Framework\TestCase;

class TekstNaWierszeUnicodeTest extends TestCase
{
    public function test_niewidoczne_spacje_na_pustych_liniach_nie_tworza_skladnika_i_rozdzielaja_kroki(): void
    {
        $skladniki = "1\u{00A0}000 g mąki\r\n\u{00A0}\r\n\t\u{202F} \r\njajka\r\n";
        $kroki = "Wymieszaj.\r\n\u{00A0}\r\n\t\u{202F} \r\nUpiecz.\r\nPodaj.";

        self::assertSame(
            [['text' => "1\u{00A0}000 g mąki"], ['text' => 'jajka']],
            TekstNaWiersze::skladniki($skladniki),
            'PUSTE_LINIE_2621_UNICODE: niewidoczne spacje nie mogą tworzyć składnika.',
        );
        self::assertSame(
            [['instruction' => 'Wymieszaj.'], ['instruction' => "Upiecz.\nPodaj."]],
            TekstNaWiersze::kroki($kroki),
            'PUSTE_LINIE_2621_UNICODE: wizualnie pusta linia musi rozdzielić kroki.',
        );
    }

    public function test_pojedynczy_enter_i_pusty_tekst_zachowuja_znaczenie(): void
    {
        self::assertSame([['instruction' => "Wymieszaj.\nUpiecz."]], TekstNaWiersze::kroki("Wymieszaj.\nUpiecz."));
        self::assertSame([], TekstNaWiersze::skladniki("\u{00A0}\n\u{202F}"));
        self::assertSame([], TekstNaWiersze::kroki("\u{00A0}\n\u{202F}"));
        self::assertSame([], TekstNaWiersze::skladniki(null));
        self::assertSame([], TekstNaWiersze::kroki(null));
    }
}

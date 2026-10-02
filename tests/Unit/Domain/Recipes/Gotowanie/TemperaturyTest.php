<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Recipes\Gotowanie;

use App\Domain\Recipes\Gotowanie\Temperatury;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Przeliczanie °F ↔ °C z tekstu kroku (#2585). */
class TemperaturyTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function przeliczenia(): array
    {
        return [
            'F na C' => ['Piecz w 350°F przez godzinę.', '350°F to około 175°C'],
            'spacja przed znakiem' => ['Rozgrzej do 350 °F.', '350 °F to około 175°C'],
            'twarda spacja' => ["Rozgrzej do 350\u{00A0}°F.", "350\u{00A0}°F to około 175°C"],
            'C na F, okrągłe' => ['Piecz w 180°C.', '180°C to około 355°F'],
            'C na F, 200' => ['Piecz w 200°C.', '200°C to około 390°F'],
            'punkt wrzenia' => ['Zagotuj do 212°F.', '212°F to około 100°C'],
            'zamarzanie' => ['Woda ma 32°F.', '32°F to około 0°C'],
            '356F to 180C' => ['356°F', '356°F to około 180°C'],
            'przecinek dziesiętny' => ['Ustaw 177,5°C.', '177,5°C to około 350°F'],
            'kropka dziesiętna' => ['Ustaw 177.5°C.', '177.5°C to około 350°F'],
            'ujemna C' => ['Mrożonka w -18°C.', '-18°C to około 0°F'],
            'ujemna F' => ['Trzymaj w 5 °F.', '5 °F to około −15°C'],
            'małe ujemne F' => ['-40°F', '-40°F to około −40°C'],
            'słownie Celsjusza' => ['Nagrzej do 180 stopni Celsjusza.', '180 stopni Celsjusza to około 355°F'],
            'słownie Fahrenheita' => ['Nagrzej do 350 stopni Fahrenheita.', '350 stopni Fahrenheita to około 175°C'],
            'zakres' => ['Piecz w 170–180°C.', '170–180°C to około 340–355°F'],
            'zakres z do' => ['Piecz w 340 do 350°F.', '340 do 350°F to około 170–175°C'],
            'mały znak stopnia' => ['Piecz w 180º C.', '180º C to około 355°F'],
        ];
    }

    #[DataProvider('przeliczenia')]
    public function test_przelicza_jawna_temperature(string $krok, string $oczekiwane): void
    {
        $wyniki = Temperatury::wTekscie($krok);

        $this->assertCount(1, $wyniki);
        $this->assertSame($oczekiwane, $wyniki[0]['tekst']);
    }

    public function test_wiele_temperatur_w_kroku_zachowuje_kolejnosc_i_nie_dubluje(): void
    {
        $wyniki = Temperatury::wTekscie('Rozgrzej do 350°F, po 20 minutach zmniejsz do 325°F, potem znów 350°F, a wodę zagotuj do 100°C.');

        $this->assertSame(
            ['350°F to około 175°C', '325°F to około 165°C', '100°C to około 210°F'],
            array_column($wyniki, 'tekst'),
        );
    }

    public function test_lista_jest_ograniczona(): void
    {
        $wyniki = Temperatury::wTekscie('100°C, 110°C, 120°C, 130°C, 140°C, 150°C.');

        $this->assertCount(Temperatury::MAKSIMUM, $wyniki);
    }

    /** @return array<string, array{string}> */
    public static function nieTemperatury(): array
    {
        return [
            'sama liczba' => ['Smaż 5 minut.'],
            'stopni bez jednostki' => ['Piecz w 180 stopni.'],
            'F bez znaku stopnia' => ['Dodaj 350 F do kolejki.'],
            'C jako litera' => ['Użyj 5 C wierszy.'],
            'F w słowie' => ['Dodaj 2 Fajne jabłka.'],
            'F w słowie po znaku' => ['Wersja 350°Fryzjer.'],
            'witamina C' => ['Zawiera 50 mg witaminy C.'],
            'sam znak stopnia' => ['Obróć o 90° w prawo.'],
            'litera przy liczbie' => ['Model A350°C jest zbyt dziwny.'],
            'poza zakresem' => ['Piec hutniczy 2000°C.'],
            'pusty tekst' => [''],
        ];
    }

    #[DataProvider('nieTemperatury')]
    public function test_nie_lapie_zwyklego_tekstu(string $krok): void
    {
        $this->assertSame([], Temperatury::wTekscie($krok));
    }

    public function test_dokladny_rachunek_zna_pary_wzorcowe(): void
    {
        $this->assertEqualsWithDelta(0.0, Temperatury::przelicz(32, true), 0.0001);
        $this->assertEqualsWithDelta(100.0, Temperatury::przelicz(212, true), 0.0001);
        $this->assertEqualsWithDelta(180.0, Temperatury::przelicz(356, true), 0.0001);
        $this->assertEqualsWithDelta(176.6667, Temperatury::przelicz(350, true), 0.0001);
        $this->assertEqualsWithDelta(32.0, Temperatury::przelicz(0, false), 0.0001);
        $this->assertEqualsWithDelta(212.0, Temperatury::przelicz(100, false), 0.0001);
        $this->assertEqualsWithDelta(356.0, Temperatury::przelicz(180, false), 0.0001);
        $this->assertEqualsWithDelta(-40.0, Temperatury::przelicz(-40, true), 0.0001);
    }
}

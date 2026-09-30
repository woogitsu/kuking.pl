<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Planer\PlanerTygodnia;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Po „na” nazwa dnia stoi w bierniku (#2246).
 *
 * Komunikaty planera składały „Dodane do planu na ” z `nazwaDnia()`, która
 * zwraca mianownik — wychodziło „na środa”, „na sobota”, „na niedziela”.
 * Nagłówki dni i przyciski wyboru dnia zostają w mianowniku, więc test
 * pilnuje obu form naraz: dla każdego dnia tygodnia biernik z `naDzien()`
 * i niezmieniony mianownik z `nazwaDnia()`.
 */
class PlanerNazwaDniaPoPrzyimkuTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function dni(): array
    {
        // Tydzień od poniedziałku 12 października 2026.
        return [
            'poniedziałek' => ['2026-10-12', 'poniedziałek, 12 października', 'poniedziałek, 12 października'],
            'wtorek' => ['2026-10-13', 'wtorek, 13 października', 'wtorek, 13 października'],
            'środa' => ['2026-10-14', 'środa, 14 października', 'środę, 14 października'],
            'czwartek' => ['2026-10-15', 'czwartek, 15 października', 'czwartek, 15 października'],
            'piątek' => ['2026-10-16', 'piątek, 16 października', 'piątek, 16 października'],
            'sobota' => ['2026-10-17', 'sobota, 17 października', 'sobotę, 17 października'],
            'niedziela' => ['2026-10-18', 'niedziela, 18 października', 'niedzielę, 18 października'],
        ];
    }

    #[DataProvider('dni')]
    public function test_po_na_stoi_biernik_a_naglowek_zostaje_w_mianowniku(string $data, string $mianownik, string $biernik): void
    {
        $dzien = CarbonImmutable::createFromFormat('!Y-m-d', $data);

        $this->assertSame($mianownik, PlanerTygodnia::nazwaDnia($dzien));
        $this->assertSame($biernik, PlanerTygodnia::naDzien($dzien));
    }
}

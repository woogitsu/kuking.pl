<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Pola w jednym rzędzie (`.siatka-pol`) zaczynają się na tej samej wysokości,
 * choć ich podpisy mają różną długość.
 *
 * ZGŁOSZENIE WŁAŚCICIELA ze zrzutu z `/dodaj/przepis/jedna-strona`:
 * „Na ile porcji" wisiało wyżej niż „Przygotowanie (minuty)" i „Gotowanie /
 * pieczenie (minuty)". Powód jest prozaiczny — krótszy podpis mieści się
 * w mniejszej liczbie wierszy, a pole zaczyna się zaraz pod nim.
 *
 * ZMIERZONE w Chromium (ramka 700 px, trzy pola z tego ekranu): górne krawędzie
 * pól stały na 60 / 81 / 81 px, czyli 21 px rozrzutu. Po zmianie 81 / 81 / 81.
 *
 * CZEGO TEN TEST NIE ROBI — I TRZEBA TO POWIEDZIEĆ WPROST. Test HTTP nie ma
 * przeglądarki i nie zmierzy ani jednego piksela. Sprawdza WYŁĄCZNIE, czy
 * reguła, która to wyrównanie robi, nadal stoi w arkuszu — czyli łapie
 * skasowanie jej przy sprzątaniu, a nie każdą możliwą regresję układu.
 * Piksele mierzy się okiem albo skryptem, i tak też powstała liczba wyżej.
 */
class PolaWRzedzieStojaRownoTest extends TestCase
{
    private function css(): string
    {
        return (string) file_get_contents(resource_path('css/app.css'));
    }

    public function test_pole_w_siatce_opada_na_dol_swojej_komorki(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '~\.siatka-pol\s*>\s*\.field\s*\{[^}]*flex-direction:\s*column~s',
            $css,
            'Pole w `.siatka-pol` nie jest kolumną `flex` — bez tego `margin-top: auto` '.
            'na kontrolce nie ma czego zepchnąć na dół komórki.',
        );

        $this->assertMatchesRegularExpression(
            '~\.siatka-pol\s*>\s*\.field\s+\.field-input\s*\{[^}]*margin-top:\s*auto~s',
            $css,
            'Kontrolka w `.siatka-pol` nie opada na dół komórki — pola w jednym rzędzie '.
            'znów zaczną się na różnych wysokościach, zależnie od długości podpisu.',
        );
    }

    /**
     * Wysokość NIE jest narzucona podpisowi ani komórce.
     *
     * Kontrola wąskości: „wyrównanie" zrobione stałą wysokością etykiety
     * rozjechałoby się przy 200% czcionki przeglądarki i przy dłuższym
     * tłumaczeniu podpisu — a to jest dokładnie ta klasa błędu, którą
     * opisują D-082 i D-107.
     */
    public function test_wyrownanie_nie_stoi_na_sztywnej_wysokosci(): void
    {
        $css = $this->css();

        $this->assertSame(
            0,
            preg_match('~\.siatka-pol\s*>\s*\.field[^{]*\{[^}]*(?<!min-)height:\s*\d~s', $css),
            'Pola w rzędzie mają narzuconą wysokość. Przy 200% czcionki i przy dłuższym '.
            'podpisie takie wyrównanie rozjeżdża się bardziej niż brak wyrównania.',
        );
    }
}

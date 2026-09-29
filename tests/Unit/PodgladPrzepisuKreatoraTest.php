<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Support\KreatorPrzepisu\PodgladPrzepisu;
use App\Support\KreatorPrzepisu\WierszePrzepisu;
use Tests\TestCase;

/**
 * Issue #1387, krok 4 — czyste przeliczenia podglądu i pól liczbowych
 * wydzielone z komponentu `recipe-wizard` do `PodgladPrzepisu` oraz operacje
 * na wierszach do `WierszePrzepisu`. Bez renderowania kreatora i bez bazy.
 */
final class PodgladPrzepisuKreatoraTest extends TestCase
{
    public function test_naglowek_zalezy_od_widocznosci_a_nieznana_wartosc_to_publiczny(): void
    {
        $this->assertSame('Podgląd: tak będziesz widzieć ten przepis', PodgladPrzepisu::naglowek('private'));
        $this->assertSame('Podgląd: tak zobaczą to osoby, które Cię obserwują', PodgladPrzepisu::naglowek('followers'));
        $this->assertSame('Podgląd: tak zobaczą to inni', PodgladPrzepisu::naglowek('public'));
        $this->assertSame('Podgląd: tak zobaczą to inni', PodgladPrzepisu::naglowek('cokolwiek'));
    }

    public function test_etykieta_porcji_to_ten_sam_kod_co_strona_przepisu(): void
    {
        foreach (['1', '2', '5', '0,5', '2.5', ' 12 '] as $wpisane) {
            $oczekiwane = (new Recipe(['servings' => (float) str_replace(',', '.', trim($wpisane))]))->servingsLabel();
            $this->assertSame($oczekiwane, PodgladPrzepisu::etykietaPorcji($wpisane), $wpisane);
        }

        $this->assertNull(PodgladPrzepisu::etykietaPorcji(''));
        $this->assertNull(PodgladPrzepisu::etykietaPorcji('kilka'));
    }

    public function test_etykieta_minutnika_to_ten_sam_kod_co_tryb_gotowania_a_bzdura_to_null(): void
    {
        $oczekiwana = (new RecipeStep(['timer_seconds' => 45 * 60]))->timerLabel(afterNa: true);

        $this->assertNotNull($oczekiwana);
        $this->assertSame($oczekiwana, PodgladPrzepisu::etykietaMinutnika('45'));
        $this->assertNull(PodgladPrzepisu::etykietaMinutnika('abc'));
        $this->assertNull(PodgladPrzepisu::etykietaMinutnika('-5'));
        $this->assertNull(PodgladPrzepisu::etykietaMinutnika('999999'));
    }

    public function test_koszt_i_czas_razem(): void
    {
        $this->assertNull(PodgladPrzepisu::etykietaKosztu(''));
        $this->assertNotNull(PodgladPrzepisu::etykietaKosztu('24,50'));

        $this->assertSame(50, PodgladPrzepisu::czasRazem('20', '30'));
        // Czas całkowity jest znany dopiero, gdy znane są OBA czasy (`Recipe::totalMinutes()`).
        $this->assertNull(PodgladPrzepisu::czasRazem('20', ''));
        $this->assertNull(PodgladPrzepisu::czasRazem('', '30'));
        $this->assertNull(PodgladPrzepisu::czasRazem('', ''));
        $this->assertNull(PodgladPrzepisu::czasRazem('x', '30'));
        $this->assertNull(PodgladPrzepisu::czasRazem('0', '0'));
    }

    public function test_parsowanie_pol(): void
    {
        $this->assertNull(PodgladPrzepisu::tekstLubNull("  \n "));
        $this->assertSame('ser', PodgladPrzepisu::tekstLubNull('  ser '));
        $this->assertNull(PodgladPrzepisu::tekstLubNull(null));

        $this->assertSame(0.5, PodgladPrzepisu::liczbaLubNull('0,5'));
        $this->assertSame(2.0, PodgladPrzepisu::liczbaLubNull(' 2 '));
        $this->assertNull(PodgladPrzepisu::liczbaLubNull('dwa'));
        $this->assertNull(PodgladPrzepisu::liczbaLubNull(''));

        $this->assertSame(15, PodgladPrzepisu::calkowitaLubNull(' 15 '));
        $this->assertSame(15, PodgladPrzepisu::calkowitaLubNull('15.9'));
        $this->assertNull(PodgladPrzepisu::calkowitaLubNull('abc'));
        $this->assertNull(PodgladPrzepisu::calkowitaLubNull(''));
    }

    public function test_liczba_na_tekst_wraca_do_pola_bez_zbednych_zer(): void
    {
        $this->assertSame('', PodgladPrzepisu::liczbaNaTekst(null));
        $this->assertSame('2.5', PodgladPrzepisu::liczbaNaTekst(2.5));
        $this->assertSame('2', PodgladPrzepisu::liczbaNaTekst(2.0));
        $this->assertSame('0.33', PodgladPrzepisu::liczbaNaTekst(0.333));
        $this->assertSame('45', PodgladPrzepisu::liczbaNaTekst(45));
        $this->assertSame('1990', PodgladPrzepisu::liczbaNaTekst('1990'));
    }

    public function test_usuniecie_wiersza_zachowuje_ciaglosc_pozycji(): void
    {
        $wiersze = [['_key' => 'a'], ['_key' => 'b'], ['_key' => 'c']];

        $this->assertSame([['_key' => 'a'], ['_key' => 'c']], WierszePrzepisu::bezWiersza($wiersze, 1));
        $this->assertSame($wiersze, WierszePrzepisu::bezWiersza($wiersze, 9));
    }

    public function test_zamiana_wierszy_przenosi_caly_wiersz_i_nie_wychodzi_poza_zakres(): void
    {
        $wiersze = [
            ['_key' => 'a', 'mediaId' => 'm1', 'timer_minutes' => '5'],
            ['_key' => 'b', 'mediaId' => null, 'timer_minutes' => ''],
        ];

        $this->assertSame([$wiersze[1], $wiersze[0]], WierszePrzepisu::zamien($wiersze, 0, 1));
        $this->assertSame($wiersze, WierszePrzepisu::zamien($wiersze, 0, -1));
        $this->assertSame($wiersze, WierszePrzepisu::zamien($wiersze, 1, 2));
    }
}

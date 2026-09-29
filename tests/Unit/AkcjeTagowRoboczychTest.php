<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Posts\AkcjeTagowRoboczych;
use App\Support\LimityTagow;
use Tests\TestCase;

/**
 * Trzy przyciski tagów w formularzu wpisu na liście ROBOCZEJ (#970) — logika
 * wyjęta z `PostController`, więc jej zachowanie przypina ten test.
 *
 * Uses Tests\TestCase tylko dla `config()` (limity tagów).
 */
final class AkcjeTagowRoboczychTest extends TestCase
{
    private function akcje(): AkcjeTagowRoboczych
    {
        return new AkcjeTagowRoboczych;
    }

    public function test_dodaj_dopisuje_nazwe_tak_jak_wpisana_ale_przycieta(): void
    {
        [$lista, $blad] = $this->akcje()->zastosuj(['zupa'], null, '  Bigos  ', false);

        $this->assertSame(['zupa', 'Bigos'], $lista);
        $this->assertNull($blad);
    }

    public function test_dodaj_duplikat_po_normalizacji_nie_zmienia_listy(): void
    {
        [$lista, $blad] = $this->akcje()->zastosuj(['Bigos'], null, ' bigos ', false);

        $this->assertSame(['Bigos'], $lista);
        $this->assertSame(LimityTagow::komunikatTagJuzDodany(), $blad);
    }

    public function test_dodaj_niepoprawna_nazwe_zwraca_komunikat_i_nie_zmienia_listy(): void
    {
        [$lista, $blad] = $this->akcje()->zastosuj(['zupa'], null, 'bigos!!!', false);

        $this->assertSame(['zupa'], $lista);
        $this->assertSame(LimityTagow::komunikatNiepoprawnaNazwa(), $blad);

        [$lista, $blad] = $this->akcje()->zastosuj(['zupa'], null, 'a', false);

        $this->assertSame(['zupa'], $lista);
        $this->assertSame(LimityTagow::komunikatNiepoprawnaNazwa(), $blad);
    }

    public function test_limit_tagow_przy_wpisie(): void
    {
        config(['kuking.tags.max_per_post' => 2]);

        [$lista, $blad] = $this->akcje()->zastosuj(['jeden', 'dwa'], null, 'trzy', false);

        $this->assertSame(['jeden', 'dwa'], $lista);
        $this->assertSame(LimityTagow::komunikatZaDuzoTagow(), $blad);

        [$lista, $blad] = $this->akcje()->zastosuj(['jeden'], null, 'dwa', false);

        $this->assertSame(['jeden', 'dwa'], $lista);
        $this->assertNull($blad);
    }

    public function test_pytanie_ma_limit_trzech_tagow_niezaleznie_od_limitu_wpisu(): void
    {
        config(['kuking.tags.max_per_post' => 10]);

        [$lista, $blad] = $this->akcje()->zastosuj(['jeden', 'dwa', 'trzy'], null, 'cztery', true);

        $this->assertSame(['jeden', 'dwa', 'trzy'], $lista);
        $this->assertSame('Do pytania dodaj najwyżej 3 tagi.', $blad);

        [$lista, $blad] = $this->akcje()->zastosuj(['jeden', 'dwa', 'trzy'], null, 'cztery', false);

        $this->assertSame(['jeden', 'dwa', 'trzy', 'cztery'], $lista);
        $this->assertNull($blad);
    }

    public function test_usun_zdejmuje_wszystkie_wpisy_o_tej_samej_znormalizowanej_nazwie(): void
    {
        [$lista, $blad] = $this->akcje()->zastosuj(['Bigos', 'zupa', ' BIGOS '], ' bigos', null, false);

        $this->assertSame(['zupa'], $lista);
        $this->assertNull($blad);
    }

    public function test_usun_nieobecny_tag_zostawia_liste(): void
    {
        [$lista, $blad] = $this->akcje()->zastosuj(['zupa'], 'bigos', null, false);

        $this->assertSame(['zupa'], $lista);
        $this->assertNull($blad);
    }

    public function test_usun_ma_pierwszenstwo_przed_dodaj(): void
    {
        [$lista, $blad] = $this->akcje()->zastosuj(['zupa'], 'zupa', 'bigos', false);

        $this->assertSame([], $lista);
        $this->assertNull($blad);
    }

    public function test_szukaj_bez_pol_dodaj_i_usun_nie_zmienia_listy(): void
    {
        foreach ([[null, null], ['', ''], [null, '']] as [$usun, $dodaj]) {
            [$lista, $blad] = $this->akcje()->zastosuj(['zupa', 'bigos'], $usun, $dodaj, false);

            $this->assertSame(['zupa', 'bigos'], $lista);
            $this->assertNull($blad);
        }
    }
}

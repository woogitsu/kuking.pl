<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Wersja;
use Tests\TestCase;

/**
 * Strona „Co nowego” pod numerem wersji w stopce (issue #1909).
 *
 * Kryteria akceptacji sprawdzane tu wprost:
 * - strona odpowiada 200 gościowi (bez logowania — decyzja właściciela:
 *   „widoczna dla wszystkich, także dla gości”);
 * - stopka linkuje do niej z kotwicą BIEŻĄCEGO wydania;
 * - kotwica bieżącego wydania naprawdę istnieje na stronie (inaczej odnośnik
 *   trafia w pustkę — przeglądarka po prostu zostaje na górze dokumentu,
 *   więc bez tego testu nikt by tego nie zauważył).
 */
class StronaCoNowegoTest extends TestCase
{
    public function test_strona_co_nowego_odpowiada_200_gosciowi(): void
    {
        $this->get('/co-nowego')->assertOk();
    }

    public function test_strona_co_nowego_ma_tytul_i_nie_jest_indeksowana_jako_prywatna(): void
    {
        $response = $this->get(route('nowosci'));

        $response->assertOk();
        $response->assertSee('Co nowego w Kuking', false);
    }

    public function test_stopka_linkuje_do_co_nowego_z_kotwica_biezacego_wydania(): void
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $oczekiwany = route('nowosci').'#'.Wersja::kotwicaWydania();

        $this->assertStringContainsString(
            'href="'.$oczekiwany.'"',
            $html,
            'Stopka nie linkuje do strony „Co nowego” z kotwicą bieżącego wydania.',
        );
    }

    public function test_kotwica_biezacego_wydania_istnieje_na_stronie_co_nowego(): void
    {
        $html = (string) $this->get(route('nowosci'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'id="'.Wersja::kotwicaWydania().'"',
            $html,
            'Kotwica „'.Wersja::kotwicaWydania().'” (z etykiety „'.Wersja::etykieta().'”) '
            .'nie istnieje w resources/nowosci/tresc.md — odnośnik ze stopki otwiera stronę '
            .'od góry, zamiast przy bieżącym wydaniu.',
        );
    }

    public function test_spis_wydan_prowadzi_do_kotwic_a_nie_tylko_do_gory_strony(): void
    {
        $html = (string) $this->get(route('nowosci'))->assertOk()->getContent();

        $this->assertStringContainsString('href="#najnowsze-zmiany"', $html);
        $this->assertStringContainsString('id="najnowsze-zmiany"', $html);
    }
}

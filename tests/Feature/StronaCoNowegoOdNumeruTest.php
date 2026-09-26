<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SlugGfm;
use App\Support\Wersja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „od Alfa 0.68.NNN” pod nagłówkami funkcji — NA STAŁE, w KAŻDEJ sekcji
 * (issue #1932, D-318, dopisek z 26 września 2026).
 *
 * `kuking:zarejestruj-wdrozenie` zapisuje w `wdrozenia_funkcje`, pod jakim
 * numerem wdrożenia i pod jaką etykietą pojawił się KAŻDY nagłówek `###`
 * sekcji „## Najnowsze zmiany" (patrz
 * `App\Domain\Wydania\Actions\ZarejestrujWdrozenie`) — ten test sprawdza, że
 * strona `/co-nowego` naprawdę czyta tę mapę i dokleja dopisek pod WŁAŚCIWYM
 * nagłówkiem GDZIEKOLWIEK on dziś stoi (także w sekcji nazwanego wydania, po
 * przenosinach z „Najnowsze zmiany"), że dopisek niesie WŁASNĄ etykietę
 * wiersza, nie bieżącą etykietę aplikacji, oraz że brak wiersza (lokalnie,
 * w testach bez wcześniejszego wdrożenia, i dla nagłówków z wydań SPRZED tej
 * funkcji) nie wywala strony i po prostu nic nie dokleja.
 */
class StronaCoNowegoOdNumeruTest extends TestCase
{
    use RefreshDatabase;

    public function test_strona_pokazuje_od_numeru_dla_znanego_naglowka_w_najnowszych_zmianach(): void
    {
        $tresc = (string) file_get_contents(config('kuking.nowosci.tresc'));

        if (preg_match('/^##\s+Najnowsze zmiany\R(.*?)(?=^##\s|\z)/msu', $tresc, $dopasowanie) !== 1
            || preg_match('/^###\s+(.+)$/mu', $dopasowanie[1], $naglowek) !== 1) {
            $this->markTestSkipped('resources/nowosci/tresc.md nie ma obecnie żadnego nagłówka „###” w „## Najnowsze zmiany”.');
        }

        $slug = SlugGfm::z(trim($naglowek[1]));

        DB::table('wdrozenia_funkcje')->insert([
            'etykieta' => Wersja::etykieta(),
            'naglowek_slug' => $slug,
            'naglowek_tekst' => trim($naglowek[1]),
            'numer' => 42,
            'created_at' => now(),
        ]);

        $html = (string) $this->get(route('nowosci'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'od '.Wersja::etykieta().'.042',
            $html,
            'Strona „Co nowego” nie pokazuje „od '.Wersja::etykieta().'.042” przy nagłówku „'.trim($naglowek[1]).'”.',
        );
    }

    public function test_bez_wiersza_w_bazie_strona_dziala_i_nic_nie_dokleja(): void
    {
        $html = (string) $this->get(route('nowosci'))->assertOk()->getContent();

        $this->assertStringNotContainsString('od '.Wersja::etykieta().'.', $html);
    }

    /**
     * NOWY PRZYPADEK (decyzja właściciela z 26 września 2026): nagłówek,
     * który już stoi w sekcji NAZWANEGO WYDANIA (nie „Najnowsze zmiany"),
     * dalej dostaje dopisek — z etykietą i numerem WŁASNEGO wiersza, który
     * może być WCZEŚNIEJSZY niż bieżąca etykieta aplikacji. To odtwarza
     * dokładnie sytuację „nagłówek przeszedł z Najnowsze zmiany do sekcji
     * wydania" — wiersz w bazie jest jedynym śladem, że kiedyś tam stał.
     */
    public function test_naglowek_w_sekcji_nazwanego_wydania_dostaje_dopisek_z_wczesniejszej_etykiety(): void
    {
        [$slug, $naglowekTekst] = $this->pierwszyNaglowekPozaNajnowszymiZmianami();

        DB::table('wdrozenia_funkcje')->insert([
            'etykieta' => 'Alfa 0.11',
            'naglowek_slug' => $slug,
            'naglowek_tekst' => $naglowekTekst,
            'numer' => 7,
            'created_at' => now(),
        ]);

        $html = (string) $this->get(route('nowosci'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'od Alfa 0.11.007',
            $html,
            'Strona „Co nowego” nie pokazuje „od Alfa 0.11.007” przy nagłówku „'.$naglowekTekst.'” '
            .'spoza „Najnowsze zmiany” — dopisek ma przeżyć przenosiny do sekcji nazwanego wydania.',
        );
    }

    /**
     * KONTROLA UJEMNA do testu wyżej: nagłówek z sekcji nazwanego wydania,
     * dla którego NIE MA wiersza w `wdrozenia_funkcje` (bo wydanie, w którym
     * dziś stoi, jest sprzed wprowadzenia tej funkcji, #1932 — nikt mu nie
     * przypisuje numeru wstecznie), nie dostaje żadnego dopisku.
     */
    public function test_naglowek_w_sekcji_nazwanego_wydania_bez_wiersza_nie_dostaje_dopisku(): void
    {
        [, $naglowekTekst] = $this->pierwszyNaglowekPozaNajnowszymiZmianami();

        $html = (string) $this->get(route('nowosci'))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            'od Alfa 0.11.007',
            $html,
            'Strona „Co nowego” dokleiła dopisek do nagłówka „'.$naglowekTekst.'”, mimo braku wiersza w bazie.',
        );
    }

    /**
     * @return array{0: string, 1: string} [slug, pełny tekst] pierwszego
     *                                     nagłówka `###`, który stoi POZA sekcją „## Najnowsze zmiany" —
     *                                     czyli już w którejś sekcji nazwanego wydania (np. „## Alfa 0.68").
     */
    private function pierwszyNaglowekPozaNajnowszymiZmianami(): array
    {
        $tresc = (string) file_get_contents(config('kuking.nowosci.tresc'));

        if (preg_match('/^##\s+Najnowsze zmiany\R.*?(?=^##\s|\z)/msu', $tresc, $dopasowanie, PREG_OFFSET_CAPTURE) !== 1) {
            $this->markTestSkipped('resources/nowosci/tresc.md nie ma sekcji „## Najnowsze zmiany”.');
        }

        $koniecSekcji = $dopasowanie[0][1] + strlen($dopasowanie[0][0]);
        $reszta = substr($tresc, $koniecSekcji);

        if (preg_match('/^###\s+(.+)$/mu', $reszta, $naglowek) !== 1) {
            $this->markTestSkipped('resources/nowosci/tresc.md nie ma żadnego nagłówka „###” poza „## Najnowsze zmiany”.');
        }

        $tekst = trim($naglowek[1]);

        return [SlugGfm::z($tekst), $tekst];
    }
}

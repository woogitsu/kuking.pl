<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SlugGfm;
use App\Support\Wersja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „od Alfa 0.68.NNN” pod nagłówkami funkcji w „## Najnowsze zmiany"
 * (issue #1932, D-318).
 *
 * `kuking:zarejestruj-wdrozenie` zapisuje w `wdrozenia_funkcje`, pod jakim
 * numerem wdrożenia pojawił się KAŻDY nagłówek `###` tej sekcji (patrz
 * `App\Domain\Wydania\Actions\ZarejestrujWdrozenie`) — ten test sprawdza,
 * że strona `/co-nowego` naprawdę czyta tę mapę i dokleja dopisek pod
 * WŁAŚCIWYM nagłówkiem, oraz że brak wiersza (lokalnie, w testach bez
 * wcześniejszego wdrożenia) nie wywala strony i po prostu nic nie dokleja.
 */
class StronaCoNowegoOdNumeruTest extends TestCase
{
    use RefreshDatabase;

    public function test_strona_pokazuje_od_numeru_dla_znanego_naglowka(): void
    {
        $tresc = (string) file_get_contents(config('kuking.nowosci.tresc'));

        if (preg_match('/^##\s+Najnowsze zmiany\R(.*?)(?=^##\s|\z)/ms', $tresc, $dopasowanie) !== 1
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

    public function test_dopisek_trafia_wylacznie_pod_naglowek_z_najnowszych_zmian_nie_gdzie_indziej(): void
    {
        // Nagłówek pod inną etykietą (już WYDANE „## Alfa 0.67 — …") nie ma
        // dostać dopisku, nawet gdyby przypadkiem miał ten sam slug co
        // nagłówek z „Najnowsze zmiany” — bo `WHERE etykieta = ...` filtruje
        // po BIEŻĄCEJ etykiecie, nie po samym slugu.
        DB::table('wdrozenia_funkcje')->insert([
            'etykieta' => 'Alfa 0.67',
            'naglowek_slug' => 'zrobcie-swoja-wersje',
            'naglowek_tekst' => 'Zróbcie swoją wersję',
            'numer' => 999,
            'created_at' => now(),
        ]);

        $html = (string) $this->get(route('nowosci'))->assertOk()->getContent();

        $this->assertStringNotContainsString('od Alfa 0.67.999', $html);
    }
}

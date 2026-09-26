<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Wersja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Numer wersji w stopce.
 *
 * PO CO TO JEST
 * Żeby jednym spojrzeniem na stronę dało się sprawdzić, co dokładnie na niej
 * działa — bez wchodzenia w panel Railway i bez zgadywania, czy ostatnie
 * wdrożenie przeszło.
 *
 * Dlatego wersja ma TRZY części i każda jest potrzebna:
 *
 *   „Alfa 0.1"               — etap produktu, podbijany ręcznie;
 *   „8 września 2026, 12:40" — kiedy to wydanie powstało (znacznik z builda);
 *   „a1b2c3d"                — skrót commita, wstawiany przez Railway.
 *
 * Sama etykieta stałaby tygodniami bez zmian i nie odpowiadałaby na pytanie
 * „czy moja poprawka już weszła". Sam skrót nic nie mówi człowiekowi, który
 * nie zna gita — i to właśnie dlatego doszła data.
 */
class WersjaWStopceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wersja_jest_widoczna_dla_niezalogowanego(): void
    {
        // Gość też ma widzieć wersję — inaczej trzeba by się logować, żeby
        // sprawdzić, czy wdrożenie doszło.
        $this->get(route('landing'))
            ->assertOk()
            ->assertSee(Wersja::etykieta());
    }

    public function test_wersja_jest_widoczna_dla_zalogowanego(): void
    {
        $this->actingAs($this->user('basia'))
            ->get(route('home'))
            ->assertOk()
            ->assertSee(Wersja::etykieta());
    }

    public function test_pokazuje_skrot_wdrozonego_commita(): void
    {
        $this->bezZnacznikaWydania();
        config(['kuking.wersja.commit' => '1a4ab54c4141bccb6cce9e1947cbfb0a227e4894']);

        $this->assertSame('1a4ab54', Wersja::wydanie());
        $this->assertStringContainsString('1a4ab54', Wersja::pelna());
    }

    public function test_bez_wdrozenia_mowi_wprost_ze_to_lokalnie(): void
    {
        // Lokalnie i w testach nie ma zmiennej od Railway. Pusty tekst albo
        // myślnik kazałyby się domyślać; „lokalnie" mówi wprost.
        config(['kuking.wersja.commit' => null]);

        $this->assertSame('lokalnie', Wersja::wydanie());
    }

    public function test_etykieta_i_wydanie_sa_rozdzielone_czytelnie(): void
    {
        $this->bezZnacznikaWydania();
        config(['kuking.wersja.commit' => 'abcdef1234567890']);

        // Dwie różne informacje mają być rozróżnialne wzrokiem, a nie sklejone
        // w jeden ciąg, którego trzeba się domyślać.
        $this->assertSame(Wersja::etykieta().' · abcdef1', Wersja::pelna());
    }

    // -----------------------------------------------------------------
    // Data i godzina wydania
    // -----------------------------------------------------------------

    /**
     * NAJWAŻNIEJSZY TEST W TYM PLIKU: data jest pokazywana w strefie, w której
     * człowiek na nią patrzy, a nie w UTC, w którym ją zapisano.
     *
     * Wybrany moment leży CELOWO po polskiej północy, a przed północą UTC:
     * 8 września 22:40 UTC to w Warszawie już 9 września, 00:40. Gdyby
     * stopka pokazywała surowy UTC, ten test pokazałby to natychmiast —
     * a przy dowolnej godzinie z południa oba warianty wyglądałyby tak samo
     * i błąd przeszedłby niezauważony. Tę samą pułapkę opisuje `App\Support\Czas`.
     */
    public function test_data_wydania_jest_w_polskiej_strefie_a_nie_w_utc(): void
    {
        config([
            'kuking.wersja.wydano' => '2026-09-08T22:40:00Z',
            'kuking.wersja.commit' => 'abcdef1234567890',
        ]);

        $this->assertSame(
            'wydanie 9 września 2026, 00:40 · abcdef1',
            Wersja::opisWydania(),
        );
    }

    public function test_data_wydania_jest_widoczna_w_stopce(): void
    {
        config(['kuking.wersja.wydano' => '2026-09-08T10:40:00Z']);

        $this->get(route('landing'))
            ->assertOk()
            ->assertSee('wydanie 8 września 2026, 12:40');
    }

    /**
     * Znacznik czyta się z pliku zapisanego przez build obrazu — bo Railway
     * nie wstrzykuje czasu wdrożenia żadną zmienną. Test pisze taki plik
     * naprawdę, zamiast ustawiać `kuking.wersja.wydano`: gdyby czytanie pliku
     * przestało działać, sama zmienna dalej dawałaby zielony wynik.
     */
    public function test_znacznik_moze_przyjsc_z_pliku_zapisanego_przez_build(): void
    {
        $plik = tempnam(sys_get_temp_dir(), 'wydanie');
        file_put_contents($plik, "2026-09-08T10:40:00Z\n");

        try {
            config([
                'kuking.wersja.wydano' => null,
                'kuking.wersja.plik_wydania' => $plik,
                'kuking.wersja.commit' => 'abcdef1234567890',
            ]);

            $this->assertSame(
                'wydanie 8 września 2026, 12:40 · abcdef1',
                Wersja::opisWydania(),
            );
        } finally {
            @unlink($plik);
        }
    }

    /**
     * ZMIENNA WYGRYWA Z PLIKIEM — żeby dało się poprawić datę bez przebudowy
     * obrazu. Bez tego testu kolejność sprawdzania mogłaby się odwrócić przy
     * pierwszym porządkowaniu metody i nikt by tego nie zauważył.
     */
    public function test_zmienna_srodowiskowa_wygrywa_z_plikiem(): void
    {
        $plik = tempnam(sys_get_temp_dir(), 'wydanie');
        file_put_contents($plik, '2020-01-01T00:00:00Z');

        try {
            config([
                'kuking.wersja.wydano' => '2026-09-08T10:40:00Z',
                'kuking.wersja.plik_wydania' => $plik,
            ]);

            $this->assertSame(
                '2026-09-08 10:40:00',
                Wersja::dataWydania()?->utc()->format('Y-m-d H:i:s'),
            );
        } finally {
            @unlink($plik);
        }
    }

    /**
     * USZKODZONY ZNACZNIK ZABIERA DATĘ, NIE CAŁY SERWIS.
     *
     * Ten tekst stoi w stopce KAŻDEJ strony. Wyjątek przy parsowaniu wywaliłby
     * też stronę logowania — czyli tę, z której trzeba by wejść, żeby to
     * naprawić. Dlatego `dataWydania()` zwraca `null`, a nie rzuca.
     */
    public function test_uszkodzony_znacznik_nie_wywala_stopki(): void
    {
        config([
            'kuking.wersja.wydano' => 'to-nie-jest-data',
            'kuking.wersja.commit' => 'abcdef1234567890',
        ]);

        $this->assertNull(Wersja::dataWydania());
        $this->assertSame(Wersja::etykieta().' · abcdef1', Wersja::pelna());

        $this->get(route('landing'))->assertOk();
    }

    /**
     * Bez znacznika i bez wdrożenia zostaje samo „lokalnie" — stan, w którym
     * chodzi każdy test i każda maszyna programisty. Asercja kontrolna dla
     * wszystkiego wyżej: gdyby `dataWydania()` zaczęło zwracać „teraz"
     * zamiast `null`, reszta tego pliku dalej byłaby zielona.
     */
    public function test_bez_znacznika_stopka_nie_zmysla_daty(): void
    {
        $this->bezZnacznikaWydania();
        config(['kuking.wersja.commit' => null]);

        $this->assertNull(Wersja::dataWydania());
        $this->assertSame(Wersja::etykieta().' · lokalnie', Wersja::pelna());
        $this->assertStringNotContainsString('wydanie', Wersja::pelna());
    }

    // -----------------------------------------------------------------
    // Numer wdrożenia z końcówką (issue #1932, D-318)
    // -----------------------------------------------------------------

    public function test_numer_wdrozenia_jest_doklejany_do_etykiety_w_pelnym_opisie(): void
    {
        $this->bezZnacznikaWydania();
        $commit = str_repeat('b', 40);
        config(['kuking.wersja.commit' => $commit]);

        DB::table('wdrozenia')->insert([
            'commit' => $commit,
            'etykieta' => Wersja::etykieta(),
            'numer' => 5,
            'created_at' => now(),
        ]);

        $this->assertSame(5, Wersja::numerWdrozenia());
        $this->assertSame(Wersja::etykieta().'.005', Wersja::etykietaZNumerem());
        $this->assertSame(Wersja::etykieta().'.005 · bbbbbbb', Wersja::pelna());

        // `etykieta()` SAMA zostaje bez końcówki — czyta ją dosłownie
        // `PodbicieWersjiWymagaWpisuWChangelogTest`, porównując z nagłówkiem
        // CHANGELOG-a w formacie „Alfa 0.N", bez żadnej końcówki.
        $this->assertMatchesRegularExpression('/^(Alfa|Beta) 0\.\d+$/', Wersja::etykieta());
    }

    public function test_bez_wiersza_w_dzienniku_etykieta_zostaje_bez_koncowki(): void
    {
        $this->bezZnacznikaWydania();
        config(['kuking.wersja.commit' => str_repeat('c', 40)]);

        $this->assertNull(Wersja::numerWdrozenia());
        $this->assertSame(Wersja::etykieta(), Wersja::etykietaZNumerem());
    }

    public function test_bez_commita_numer_wdrozenia_jest_null_bez_zapytania_do_bazy(): void
    {
        config(['kuking.wersja.commit' => null]);

        $this->assertNull(Wersja::numerWdrozenia());
    }

    public function test_numer_wdrozenia_jest_pamietany_w_cache(): void
    {
        $commit = str_repeat('d', 40);
        config(['kuking.wersja.commit' => $commit]);

        DB::table('wdrozenia')->insert([
            'commit' => $commit,
            'etykieta' => Wersja::etykieta(),
            'numer' => 7,
            'created_at' => now(),
        ]);

        $this->assertSame(7, Wersja::numerWdrozenia());

        // Wiersz znika, ale odczyt z cache'u zostaje — bez tego stopka
        // wysyłałaby jedno zapytanie do bazy na KAŻDE żądanie strony.
        DB::table('wdrozenia')->where('commit', $commit)->delete();

        $this->assertSame(7, Wersja::numerWdrozenia());
    }

    public function test_footer_pokazuje_numer_wdrozenia_na_stronie(): void
    {
        $this->bezZnacznikaWydania();
        $commit = str_repeat('e', 40);
        config(['kuking.wersja.commit' => $commit]);

        DB::table('wdrozenia')->insert([
            'commit' => $commit,
            'etykieta' => Wersja::etykieta(),
            'numer' => 12,
            'created_at' => now(),
        ]);

        $this->get(route('landing'))
            ->assertOk()
            ->assertSee(Wersja::etykieta().'.012');
    }

    /** Stan „nie wiadomo, kiedy": ani zmiennej, ani pliku. */
    private function bezZnacznikaWydania(): void
    {
        config([
            'kuking.wersja.wydano' => null,
            'kuking.wersja.plik_wydania' => '/nie/ma/takiego/pliku/wydanie.txt',
        ]);
    }
}

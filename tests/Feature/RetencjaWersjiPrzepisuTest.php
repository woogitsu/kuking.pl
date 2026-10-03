<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWersjePrzepisow;
use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\Report;
use App\Models\User;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Retencja `recipe_versions` (#2024, D-333): wersja starsza niż 24 miesiące
 * (po dacie w Polsce) i spoza 3 najnowszych wersji przepisu znika.
 *
 * Pierwsza wersja NIE jest chroniona — historia jest publiczna, a to w niej
 * leży treść, którą autor później usunął (uzasadnienie w klasie domenowej).
 */
final class RetencjaWersjiPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.przepisy.version_retention_months' => 24,
            'kuking.przepisy.version_keep_latest' => 3,
            'kuking.strefa' => 'Europe/Warsaw',
        ]);
    }

    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    private function wersja(Recipe $przepis, int $numer, string $kiedy): RecipeVersion
    {
        $wersja = RecipeVersion::create([
            'recipe_id' => $przepis->getKey(),
            'editor_id' => $przepis->author_id,
            'version_number' => $numer,
            'change_note' => "Wersja {$numer}",
            'snapshot' => ['title' => $przepis->title, 'summary' => "Opis {$numer}", 'ingredients' => [], 'steps' => []],
        ]);

        // `created_at` przestawiamy zapytaniem (Eloquent nadpisuje je przy zapisie).
        DB::table('recipe_versions')->where('id', $wersja->getKey())->update(['created_at' => Carbon::parse($kiedy, 'UTC')]);

        return $wersja;
    }

    /**
     * Przepis z pięcioma wersjami: pierwsze cztery bardzo stare, piąta świeża.
     * Zostają trzy najnowsze (3, 4, 5); kasowane są 1 i 2, w tym pierwsza.
     */
    private function przepisZPiecioma(): array
    {
        $przepis = Recipe::factory()->create();
        $w = [];
        foreach ([1 => '2020-01-10 10:00:00', 2 => '2021-01-10 10:00:00', 3 => '2022-01-10 10:00:00', 4 => '2023-01-10 10:00:00', 5 => '2026-09-01 10:00:00'] as $n => $kiedy) {
            $w[$n] = $this->wersja($przepis, $n, $kiedy);
        }

        return [$przepis, $w];
    }

    public function test_stare_wersje_poza_trzema_najnowszymi_znikaja_wlacznie_z_pierwsza(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        [$przepis, $w] = $this->przepisZPiecioma();

        $wynik = (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);

        $this->assertSame(2, $wynik['skasowano']);
        $this->assertDatabaseMissing('recipe_versions', ['id' => $w[1]->getKey()]);
        $this->assertDatabaseMissing('recipe_versions', ['id' => $w[2]->getKey()]);
        foreach ([3, 4, 5] as $n) {
            $this->assertDatabaseHas('recipe_versions', ['id' => $w[$n]->getKey()]);
        }
        $this->assertSame([5, 4, 3], RecipeVersion::where('recipe_id', $przepis->getKey())->orderByDesc('version_number')->pluck('version_number')->all());
    }

    public function test_przepis_poprawiany_rzadko_zachowuje_historie_mimo_wieku(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        $przepis = Recipe::factory()->create();
        $pierwsza = $this->wersja($przepis, 1, '2018-01-10 10:00:00');
        $druga = $this->wersja($przepis, 2, '2019-01-10 10:00:00');

        $wynik = (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);

        $this->assertSame(0, $wynik['skasowano']);
        $this->assertDatabaseHas('recipe_versions', ['id' => $pierwsza->getKey()]);
        $this->assertDatabaseHas('recipe_versions', ['id' => $druga->getKey()]);
    }

    public function test_minimum_zachowanych_to_dwie_nawet_gdy_konfiguracja_prosi_o_mniej(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        $przepis = Recipe::factory()->create();
        foreach ([1, 2, 3] as $n) {
            $this->wersja($przepis, $n, '2019-01-10 10:00:00');
        }

        $wynik = (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 0);

        $this->assertSame(1, $wynik['skasowano']);
        $this->assertSame([3, 2], RecipeVersion::where('recipe_id', $przepis->getKey())->orderByDesc('version_number')->pluck('version_number')->all());
    }

    /**
     * Granica dnia po polsku. Teraz: 30.09.2026 01:30 w Warszawie (CEST,
     * 29.09 23:30 UTC). Próg: początek 30.09.2024 w Warszawie = 29.09.2024
     * 22:00 UTC. Próg liczony w UTC (29.09.2024 23:30) dałby inny wynik dla
     * wersji z przedziału 22:00–23:30 UTC.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function granice(): array
    {
        return [
            'lato: minuta przed północą w Polsce — znika' => ['2026-09-29 23:30:00', '2024-09-29 21:59:00', true],
            'lato: północ w Polsce — zostaje' => ['2026-09-29 23:30:00', '2024-09-29 22:00:00', false],
            'lato: 23:00 UTC (1:00 w Polsce następnego dnia) — zostaje' => ['2026-09-29 23:30:00', '2024-09-29 23:00:00', false],
            'zima: minuta przed północą w Polsce — znika' => ['2026-01-15 23:30:00', '2024-01-15 22:59:00', true],
            'zima: północ w Polsce — zostaje' => ['2026-01-15 23:30:00', '2024-01-15 23:00:00', false],
            'zmiana czasu: teraz po przejściu na letni, próg w zimowym' => ['2026-03-29 10:00:00', '2024-03-28 22:59:00', true],
            'zmiana czasu: granica dnia zimowego' => ['2026-03-29 10:00:00', '2024-03-28 23:00:00', false],
            'koniec miesiąca: 31.08.2026 minus 6 mies. to 28.02.2026, nie 3.03' => ['2026-08-31 12:00:00', '2026-02-27 22:59:00', true],
            'koniec miesiąca: 28.02.2026 00:00 w Polsce zostaje' => ['2026-08-31 12:00:00', '2026-02-27 23:00:00', false],
        ];
    }

    #[DataProvider('granice')]
    public function test_granica_dat_w_strefie_warszawskiej(string $teraz, string $wersjaUtc, bool $znika): void
    {
        Date::setTestNow($teraz);
        $miesiace = str_starts_with($teraz, '2026-08-31') ? 6 : 24;

        $przepis = Recipe::factory()->create();
        $badana = $this->wersja($przepis, 1, $wersjaUtc);
        // Trzy nowsze wersje, żeby badana nie była chroniona liczbą.
        foreach ([2, 3, 4] as $n) {
            $this->wersja($przepis, $n, $teraz);
        }

        (new PrzedawnioneWersjePrzepisow)->posprzataj($miesiace, 3);

        $znika
            ? $this->assertDatabaseMissing('recipe_versions', ['id' => $badana->getKey()])
            : $this->assertDatabaseHas('recipe_versions', ['id' => $badana->getKey()]);
    }

    public function test_przepis_ze_zgloszeniem_albo_decyzja_moderacyjna_zachowuje_wersje(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        [$zgloszony, $wz] = $this->przepisZPiecioma();
        [$zwykly, $ww] = $this->przepisZPiecioma();

        Report::create([
            'reporter_id' => User::factory()->create()->getKey(),
            'target_type' => 'recipe',
            'target_id' => $zgloszony->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        $wynik = (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);

        $this->assertSame(2, $wynik['skasowano']);
        $this->assertDatabaseHas('recipe_versions', ['id' => $wz[1]->getKey()]);
        $this->assertDatabaseMissing('recipe_versions', ['id' => $ww[1]->getKey()]);
    }

    public function test_wersja_wskazana_zgloszeniem_zostaje_a_reszta_starych_znika(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        [, $w] = $this->przepisZPiecioma();

        Report::create([
            'reporter_id' => User::factory()->create()->getKey(),
            'target_type' => 'recipe_version',
            'target_id' => $w[1]->getKey(),
            'reason' => 'personal_data',
            'status' => Report::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        $wynik = (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);

        $this->assertSame(1, $wynik['skasowano']);
        $this->assertDatabaseHas('recipe_versions', ['id' => $w[1]->getKey()]);
        $this->assertDatabaseMissing('recipe_versions', ['id' => $w[2]->getKey()]);
    }

    public function test_na_sucho_liczy_to_samo_co_przebieg_prawdziwy_i_niczego_nie_rusza(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        $this->przepisZPiecioma();
        $this->przepisZPiecioma();

        $naSucho = (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3, naSucho: true);
        $this->assertSame(4, $naSucho['skasowano']);
        $this->assertSame(10, RecipeVersion::count());

        $naprawde = (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);
        $this->assertSame($naSucho['skasowano'], $naprawde['skasowano']);
        $this->assertSame(6, RecipeVersion::count());
    }

    public function test_drugi_przebieg_nie_kasuje_juz_nic(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        $this->przepisZPiecioma();

        (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);
        $drugi = (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);

        $this->assertSame(0, $drugi['skasowano']);
        $this->assertSame(3, RecipeVersion::count());
    }

    public function test_partie_i_budzet_przebiegu_reszta_czeka_na_nastepna_noc(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        // Pięć przepisów po dwie kasowalne wersje = 10 kandydatów.
        for ($i = 0; $i < 5; $i++) {
            $this->przepisZPiecioma();
        }

        $pierwszy = (new PrzedawnioneWersjePrzepisow(rozmiarPartii: 3, budzetPrzebiegu: 7))->posprzataj(24, 3);

        $this->assertSame(7, $pierwszy['skasowano']);
        $this->assertSame(3, $pierwszy['zostaje']);
        $this->assertSame(0, $pierwszy['bledy']);

        $drugi = (new PrzedawnioneWersjePrzepisow(rozmiarPartii: 3, budzetPrzebiegu: 7))->posprzataj(24, 3);

        $this->assertSame(3, $drugi['skasowano']);
        $this->assertSame(0, $drugi['zostaje']);
        $this->assertSame(15, RecipeVersion::count());
    }

    public function test_wersje_usunietego_przepisu_nie_sa_ruszane_przez_te_retencje_i_ida_z_przepisem(): void
    {
        Date::setTestNow('2026-10-03 12:00:00');
        $przepis = Recipe::factory()->create();
        $stara = $this->wersja($przepis, 1, '2024-10-03 10:00:00');
        foreach ([2, 3, 4] as $numer) {
            $this->wersja($przepis, $numer, '2026-10-03 10:00:00');
        }
        $this->assertSame(0, (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3, naSucho: true)['skasowano']);

        $przepis->delete();
        Date::setTestNow('2026-10-04 12:00:00');
        $retencja = new PrzedawnioneWersjePrzepisow;

        // Nazajutrz wersja przekracza próg 24 miesięcy, ale w koszu
        // wszystkie wersje czekają na 30-dniowe sprzątanie przepisu.
        $this->assertSame(0, $retencja->posprzataj(24, 3, naSucho: true)['skasowano'], 'KOSZ_2881_WERSJE_CZEKAJA_NA_PRZEPIS');
        $this->assertSame(0, $retencja->posprzataj(24, 3)['skasowano'], 'KOSZ_2881_WERSJE_CZEKAJA_NA_PRZEPIS');
        $this->assertDatabaseHas('recipe_versions', ['id' => $stara->getKey()]);

        $przepis->restore();
        $this->assertSame(1, $retencja->posprzataj(24, 3, naSucho: true)['skasowano']);
        $this->assertSame(1, $retencja->posprzataj(24, 3)['skasowano']);
        $this->assertDatabaseMissing('recipe_versions', ['id' => $stara->getKey()]);

        $this->assertSame(3, RecipeVersion::where('recipe_id', $przepis->getKey())->count());
        $przepis->forceDelete();
        $this->assertSame(0, RecipeVersion::where('recipe_id', $przepis->getKey())->count());
    }

    public function test_historia_dziala_po_retencji_mimo_luk_w_numeracji(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        [$przepis] = $this->przepisZPiecioma();

        (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);

        $this->get(route('recipes.history', $przepis->slug))
            ->assertOk()
            ->assertSee('Wersja 5')
            ->assertSee('Wersja 3')
            ->assertDontSee('Wersja 1')
            ->assertSee('Co się zmieniło względem wersji 4');

        // Najstarsza z pozostałych nie ma poprzednika, ale ekran się otwiera.
        $this->get(route('recipes.history.changes', [$przepis->slug, 3]))->assertOk();
        $this->get(route('recipes.history.version', [$przepis->slug, 1]))->assertNotFound();
        $this->get(route('recipes.history.changes', [$przepis->slug, 4]))->assertOk();
    }

    public function test_ekran_historii_mowi_o_retencji_z_wartosciami_z_konfiguracji(): void
    {
        [$przepis] = $this->przepisZPiecioma();

        $this->get(route('recipes.history', $przepis->slug))
            ->assertOk()
            ->assertSee('wersja zapisana ponad 24 miesiące temu')
            ->assertSee('3 najnowsze wersje przepisu zostają zawsze');
    }

    public function test_nowa_wersja_po_retencji_dostaje_kolejny_numer_bez_kolizji(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        [$przepis] = $this->przepisZPiecioma();
        (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);

        $nowa = app(SnapshotRecipeVersion::class)->handle($przepis->fresh(), $przepis->author, 'Po retencji');

        $this->assertSame(6, $nowa->version_number);
    }

    public function test_eksport_danych_niesie_wersje_ktore_zostaly_bez_zmiany_ksztaltu(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        [$przepis] = $this->przepisZPiecioma();
        $autor = $przepis->author;

        (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);

        $paczka = app(CollectUserExportData::class)->handle($autor, new ExportPhotoPlan($autor), Carbon::parse('2026-09-29 12:00:00', 'UTC'));

        $this->assertSame([3, 4, 5], array_column($paczka['wersje_przepisow'], 'numer_wersji'));
        $this->assertSame(['przepis', 'numer_wersji', 'notatka_o_zmianie', 'zapisano', 'ukryto', 'ukryl', 'tresc_wersji'], array_keys($paczka['wersje_przepisow'][0]));
    }

    // ---------------------------------------------------------------
    // komenda, kod wyjścia, harmonogram
    // ---------------------------------------------------------------

    public function test_komenda_odmienia_liczebniki_i_respektuje_opcje(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        $this->przepisZPiecioma();

        $this->artisan('kuking:sprzataj-wersje-przepisow', ['--na-sucho' => true])
            ->expectsOutputToContain('Do skasowania: 2 wersje przepisów starszych niż 24 miesiące (zostaje 3 najnowsze z każdego przepisu).')
            ->assertSuccessful();
        $this->assertSame(5, RecipeVersion::count());

        // `--ostatnie=4` chroni czwartą wersję: kasowalna zostaje jedna.
        $this->artisan('kuking:sprzataj-wersje-przepisow', ['--ostatnie' => 4])
            ->expectsOutputToContain('Skasowano 1 wersję przepisów starszych niż 24 miesiące')
            ->assertSuccessful();
        $this->assertSame(4, RecipeVersion::count());
    }

    /**
     * Wzorzec usterki #2250 (tam: `PrzedawnioneUsunieteTresci`): limit partii
     * liczony PRZED filtrem ochrony moderacyjnej pozwalał chronionym wierszom
     * zająć cały budżet i zagłodzić resztę. Tu ochrona stoi w samym
     * zapytaniu (`NOT EXISTS`), więc pierwsza pełna partia chronionych
     * wersji nie blokuje niechronionych, które są za nią w kolejności kluczy.
     */
    public function test_chronione_wersje_nie_zajmuja_budzetu_i_nie_glodza_reszty(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        $reporter = User::factory()->create();

        // Najpierw (najmniejsze klucze) dwa przepisy ze zgłoszeniem: 4 wersje,
        // które BEZ ochrony byłyby kandydatami — więcej niż partia i budżet.
        foreach ([1, 2] as $_) {
            [$chroniony] = $this->przepisZPiecioma();
            Report::create([
                'reporter_id' => $reporter->getKey(),
                'target_type' => 'recipe',
                'target_id' => $chroniony->getKey(),
                'reason' => 'spam',
                'status' => Report::STATUS_OPEN,
            ]);
        }

        [, $zwykly] = $this->przepisZPiecioma();

        $wynik = (new PrzedawnioneWersjePrzepisow(rozmiarPartii: 2, budzetPrzebiegu: 2))->posprzataj(24, 3);

        $this->assertSame(2, $wynik['skasowano']);
        $this->assertSame(0, $wynik['zostaje']);
        $this->assertDatabaseMissing('recipe_versions', ['id' => $zwykly[1]->getKey()]);
        $this->assertDatabaseMissing('recipe_versions', ['id' => $zwykly[2]->getKey()]);
        $this->assertSame(13, RecipeVersion::count());
    }

    public function test_przepis_bez_wersji_nie_wywraca_komendy(): void
    {
        $przepis = Recipe::factory()->create();

        $this->artisan('kuking:sprzataj-wersje-przepisow')->assertSuccessful();

        $this->assertDatabaseHas('recipes', ['id' => $przepis->getKey()]);
    }

    private function zablokujKasowanieWersji(RecipeVersion $wersja): void
    {
        $id = $wersja->getKey();

        DB::unprepared(<<<SQL
            CREATE FUNCTION test_awaria_kasowania_wersji() RETURNS trigger AS \$\$
            BEGIN
                IF OLD.id = '{$id}' THEN
                    RAISE EXCEPTION 'wstrzyknieta awaria kasowania';
                END IF;
                RETURN OLD;
            END;
            \$\$ LANGUAGE plpgsql;

            CREATE TRIGGER test_awaria_kasowania_wersji
                BEFORE DELETE ON recipe_versions
                FOR EACH ROW EXECUTE FUNCTION test_awaria_kasowania_wersji();
            SQL);
    }

    public function test_blad_kasowania_daje_kod_wyjscia_1_a_reszta_zostaje_skasowana(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        [, $w] = $this->przepisZPiecioma();
        [, $inne] = $this->przepisZPiecioma();
        $this->zablokujKasowanieWersji($w[1]);

        $this->artisan('kuking:sprzataj-wersje-przepisow')
            ->expectsOutputToContain('Nie udało się skasować')
            ->assertFailed();

        $this->assertDatabaseHas('recipe_versions', ['id' => $w[1]->getKey()]);
    }

    public function test_zadanie_w_harmonogramie_o_bledzie_konczy_sie_wyjatkiem(): void
    {
        Date::setTestNow('2026-09-29 12:00:00');
        [, $w] = $this->przepisZPiecioma();
        $this->zablokujKasowanieWersji($w[1]);

        try {
            $this->zadanie()->run(app());
            $this->fail('Zadanie z porażką zakończyło się jak udane.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(kod wyjścia: 1)', $e->getMessage());
        }
    }

    public function test_zadanie_jest_w_harmonogramie_raz_dziennie(): void
    {
        $this->assertSame('40 6 * * *', $this->zadanie()->expression);
    }

    private function zadanie(): CallbackEvent
    {
        $zadanie = collect(app(Schedule::class)->events())
            ->first(fn ($e): bool => $e->description === 'kuking:sprzataj-wersje-przepisow');
        $this->assertInstanceOf(CallbackEvent::class, $zadanie);

        return $zadanie;
    }
}

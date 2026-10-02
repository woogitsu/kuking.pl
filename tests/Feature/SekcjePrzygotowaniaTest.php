<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\Actions\ZrobWlasnaWersje;
use App\Domain\Recipes\EtapyPrzygotowania;
use App\Domain\Recipes\Gotowanie\PostepGotowania;
use App\Domain\Recipes\Historia\MigawkaWersji;
use App\Domain\Recipes\Historia\PorownanieWersji;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Domain\Users\Exports\WersjaFormatuPaczki;
use App\Domain\Users\Import\PodgladPaczkiEksportu;
use App\Domain\Users\Import\PozycjaPodgladu;
use App\Domain\Users\Import\WczytajPaczke;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\RecipeVersion;
use App\Models\User;
use App\Support\KreatorPrzepisu\WierszePrzepisu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Opcjonalne etapy przygotowania nad krokami (#2652, decyzja właściciela
 * z 2.10.2026): „Dzień 1: farsz”, „Dzień 2: lepienie”.
 *
 * @bez-kontroli-dodatniej Test mierzy zachowanie akcji, widoków i bazy, nie tekst źródeł.
 */
final class SekcjePrzygotowaniaTest extends TestCase
{
    use RefreshDatabase;

    private const KROKI = [
        ['instruction' => 'Zmiel mięso.', 'section_name' => 'Dzień 1: farsz'],
        ['instruction' => 'Dodaj cebulę.'],
        ['instruction' => 'Zagnieć ciasto.', 'section_name' => 'Dzień 2: lepienie', 'timer_minutes' => 30],
    ];

    /** @param list<array<string, mixed>> $kroki */
    private function przepis(User $autor, array $kroki = self::KROKI, ?Recipe $istniejacy = null, string $tytul = 'Pierogi na dwa dni'): Recipe
    {
        return app(PublishRecipe::class)->handle(
            author: $autor,
            attributes: ['title' => $tytul],
            ingredients: [['text' => '500 g mąki']],
            steps: $kroki,
            publish: true,
            existing: $istniejacy,
        );
    }

    public function test_przepis_bez_etapow_wyglada_jak_dawniej(): void
    {
        $przepis = $this->przepis($this->user('autor2652a'), [['instruction' => 'Jeden.'], ['instruction' => 'Dwa.']]);

        $this->assertSame(0, DB::table('recipe_steps')->whereNotNull('section_name')->count());

        $html = $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertSame(1, substr_count($html, 'class="step-list"'), 'Bez etapów jest jedna lista kroków.');

        $this->get(route('cooking.show', [$przepis->slug, 'krok' => 1]))->assertOk()->assertDontSee('Etap:');
    }

    public function test_strona_i_wydruk_pokazuja_etapy_a_numeracja_liczy_instrukcje(): void
    {
        $przepis = $this->przepis($this->user('autor2652b'));

        foreach ([route('recipes.show', $przepis->slug), route('recipes.show', [$przepis->slug, 'druk' => 1])] as $adres) {
            $html = $this->get($adres)->assertOk()
                ->assertSeeInOrder(['Dzień 1: farsz', 'Zmiel mięso.', 'Dodaj cebulę.', 'Dzień 2: lepienie', 'Zagnieć ciasto.'], false)
                ->getContent();
            $this->assertIsString($html);
            $this->assertSame(2, substr_count($html, 'class="step-list"'));
            $this->assertStringContainsString('<span class="visually-hidden">Krok 3.</span>', $html);
        }
    }

    public function test_tryb_gotowania_pokazuje_etap_a_licznik_i_minutnik_liczy_tylko_instrukcje(): void
    {
        $przepis = $this->przepis($this->user('autor2652c'));
        $kucharz = $this->user('kucharz2652c');

        $this->actingAs($kucharz)->get(route('cooking.show', [$przepis->slug, 'krok' => 2]))->assertOk()
            ->assertSee('Etap: Dzień 1: farsz')
            ->assertSee('Krok 2 z 3');

        $html = $this->actingAs($kucharz)->get(route('cooking.show', [$przepis->slug, 'krok' => 3]))->assertOk()
            ->assertSee('Etap: Dzień 2: lepienie')
            ->assertSee('Krok 3 z 3')
            ->getContent();
        $this->assertIsString($html);
        $this->assertStringNotContainsString('Etap: Dzień 1', $html);
        $this->assertSame(1, substr_count($html, 'data-timer-step-id='), 'Nagłówek etapu nie ma własnego minutnika.');
    }

    public function test_zmiana_nazwy_etapu_nie_zmienia_id_odcisku_minutnika_ani_postepu(): void
    {
        $autor = $this->user('autor2652d');
        $kucharz = $this->user('kucharz2652d');
        $przepis = $this->przepis($autor);
        $kroki = $przepis->steps()->orderBy('position')->get();
        $stareId = $kroki->pluck('id')->map(fn ($id): string => (string) $id)->all();
        $staryOdcisk = $kroki[2]->timerFingerprint();

        $postepy = app(PostepGotowania::class);
        $postep = $postepy->wlacz($kucharz, $przepis, $stareId, []);
        $postepy->ustaw($postep, $stareId[0], true, $stareId);

        $przepis = $this->przepis($autor, [
            ['id' => $stareId[0], 'instruction' => 'Zmiel mięso.', 'section_name' => 'Wieczór przed: farsz'],
            ['id' => $stareId[1], 'instruction' => 'Dodaj cebulę.'],
            ['id' => $stareId[2], 'instruction' => 'Zagnieć ciasto.', 'section_name' => 'Rano: lepienie', 'timer_minutes' => 30],
        ], $przepis);

        $po = $przepis->steps()->orderBy('position')->get();
        $this->assertSame($stareId, $po->pluck('id')->map(fn ($id): string => (string) $id)->all(), 'Zmiana nazw nie zmienia UUID kroków.');
        $this->assertSame('Wieczór przed: farsz', $po[0]->section_name);
        $this->assertSame($staryOdcisk, $po[2]->timerFingerprint(), 'Odcisk minutnika nie zależy od nazwy etapu.');

        $aktywny = $postepy->aktywny($kucharz, $przepis);
        $this->assertNotNull($aktywny);
        $this->assertSame([$stareId[0]], $postepy->zrobione($aktywny, $stareId), 'Wykonane odhaczenia zostają po zmianie nazwy etapu.');
    }

    public function test_usuniecie_naglowka_nie_kasuje_tresci_krokow(): void
    {
        $autor = $this->user('autor2652e');
        $przepis = $this->przepis($autor);
        $id = $przepis->steps()->orderBy('position')->pluck('id')->map(fn ($i): string => (string) $i)->all();

        $przepis = $this->przepis($autor, [
            ['id' => $id[0], 'instruction' => 'Zmiel mięso.', 'section_name' => '   '],
            ['id' => $id[1], 'instruction' => 'Dodaj cebulę.'],
            ['id' => $id[2], 'instruction' => 'Zagnieć ciasto.', 'timer_minutes' => 30],
        ], $przepis);

        $this->assertSame(0, $przepis->steps()->whereNotNull('section_name')->count(), 'Puste spacje to brak etapu (NULL), nie pusty tekst.');
        $this->assertSame(['Zmiel mięso.', 'Dodaj cebulę.', 'Zagnieć ciasto.'], $przepis->steps()->orderBy('position')->pluck('instruction')->all());
    }

    public function test_nazwa_etapu_z_pustego_wiersza_przechodzi_na_nastepny_krok(): void
    {
        $przepis = $this->przepis($this->user('autor2652f'), [
            ['instruction' => 'Pierwszy.'],
            ['instruction' => '   ', 'section_name' => 'Dzień 2'],
            ['instruction' => 'Drugi.'],
        ]);

        $this->assertSame([null, 'Dzień 2'], $przepis->steps()->orderBy('position')->pluck('section_name')->all());
    }

    public function test_usuniecie_kroku_z_naglowkiem_w_kreatorze_przenosi_naglowek_na_nastepny(): void
    {
        $wiersze = [
            ['_key' => 'a', 'instruction' => 'A', 'section_name' => 'Dzień 1'],
            ['_key' => 'b', 'instruction' => 'B', 'section_name' => ''],
            ['_key' => 'c', 'instruction' => 'C', 'section_name' => 'Dzień 2'],
        ];

        $po = WierszePrzepisu::bezKroku($wiersze, 0);
        $this->assertSame(['B', 'C'], array_column($po, 'instruction'));
        $this->assertSame('Dzień 1', $po[0]['section_name']);

        $poDrugim = WierszePrzepisu::bezKroku($wiersze, 2);
        $this->assertSame(['A', 'B'], array_column($poDrugim, 'instruction'), 'Ostatni krok nie ma następnego: nagłówek odchodzi z nim.');

        $this->assertSame('Dzień 2', WierszePrzepisu::bezKroku($wiersze, 1)[1]['section_name'], 'Następny krok zachowuje własną nazwę.');
    }

    public function test_przesuniecie_kroku_w_kreatorze_niesie_jego_etap(): void
    {
        $wiersze = [
            ['_key' => 'a', 'instruction' => 'A', 'section_name' => 'Dzień 1'],
            ['_key' => 'b', 'instruction' => 'B', 'section_name' => ''],
        ];

        $po = WierszePrzepisu::zamien($wiersze, 0, 1);

        $this->assertSame(['B', 'A'], array_column($po, 'instruction'));
        $this->assertSame('Dzień 1', $po[1]['section_name']);

        $clean = WierszePrzepisu::kroki($po);
        $this->assertSame([null, 'Dzień 1'], array_column($clean, 'section_name'));
    }

    public function test_dwa_sasiednie_etapy_tworza_dwie_grupy_a_kroki_przed_pierwsza_nazwa_zostaja_bez_naglowka(): void
    {
        $kroki = collect([
            (object) ['section_name' => null],
            (object) ['section_name' => 'Dzień 1'],
            (object) ['section_name' => 'Dzień 2'],
            (object) ['section_name' => null],
        ]);

        $grupy = EtapyPrzygotowania::grupy($kroki);

        $this->assertSame([null, 'Dzień 1', 'Dzień 2'], array_column($grupy, 'nazwa'));
        $this->assertSame([0], array_keys($grupy[0]['kroki']));
        $this->assertSame([2, 3], array_keys($grupy[2]['kroki']));
        $this->assertNull(EtapyPrzygotowania::nazwaDlaKroku($kroki, 0));
        $this->assertSame('Dzień 2', EtapyPrzygotowania::nazwaDlaKroku($kroki, 3));
    }

    public function test_za_dluga_nazwa_etapu_wraca_z_bledem_przy_polu_i_z_zachowanymi_wpisami(): void
    {
        $autor = $this->user('autor2652g');
        $przepis = $this->przepis($autor);
        $id = $przepis->steps()->orderBy('position')->pluck('id')->map(fn ($i): string => (string) $i)->all();
        $za = str_repeat('x', 121);

        $odpowiedz = $this->actingAs($autor)->followingRedirects()->from(route('recipes.edit', $przepis->slug))->put(route('recipes.update', $przepis->slug), [
            'title' => 'Pierogi na dwa dni',
            'visibility' => 'public',
            'content_revision' => (string) $przepis->content_revision,
            'ingredients' => [['text' => '500 g mąki']],
            'steps' => [
                ['id' => $id[0], 'instruction' => 'Wpisany ważny tekst.', 'section_name' => $za],
                ['id' => $id[1], 'instruction' => 'Dodaj cebulę.'],
            ],
        ]);

        $this->assertSame('Dzień 1: farsz', $przepis->steps()->orderBy('position')->first()?->section_name, 'Nieudany zapis niczego nie zmienia.');

        $odpowiedz
            ->assertSee('Nazwa etapu jest za długa. Zostaw najwyżej 120 znaków', false)
            ->assertSee('Wpisany ważny tekst.', false)
            ->assertSee($za, false);
    }

    public function test_formularz_edycji_bez_javascriptu_niesie_nazwy_etapow(): void
    {
        $autor = $this->user('autor2652h');
        $przepis = $this->przepis($autor);

        $this->actingAs($autor)->get(route('recipes.edit', $przepis->slug))->assertOk()
            ->assertSee('name="steps[0][section_name]"', false)
            ->assertSee('value="Dzień 1: farsz"', false)
            ->assertSee('value="Dzień 2: lepienie"', false);
    }

    public function test_historia_i_porownanie_wersji_widza_zmiane_etapu(): void
    {
        $autor = $this->user('autor2652i');
        $przepis = $this->przepis($autor);
        $id = $przepis->steps()->orderBy('position')->pluck('id')->map(fn ($i): string => (string) $i)->all();

        $this->przepis($autor, [
            ['id' => $id[0], 'instruction' => 'Zmiel mięso.', 'section_name' => 'Wieczór: farsz'],
            ['id' => $id[1], 'instruction' => 'Dodaj cebulę.'],
            ['id' => $id[2], 'instruction' => 'Zagnieć ciasto.', 'section_name' => 'Dzień 2: lepienie', 'timer_minutes' => 30],
        ], $przepis);

        $wersje = RecipeVersion::query()->where('recipe_id', $przepis->getKey())->orderBy('version_number')->get();
        $this->assertGreaterThanOrEqual(2, $wersje->count());
        $stara = new MigawkaWersji($wersje->first()->snapshot);
        $nowa = new MigawkaWersji($wersje->last()->snapshot);
        $this->assertSame('Dzień 1: farsz', $stara->kroki()[0]['section_name']);
        $this->assertSame('Wieczór: farsz', $nowa->kroki()[0]['section_name']);

        $roznice = PorownanieWersji::porownaj($wersje->first()->snapshot, $wersje->last()->snapshot);
        $this->assertCount(1, $roznice['kroki']);
        $this->assertSame('[Etap: Dzień 1: farsz] Zmiel mięso.', $roznice['kroki'][0]['przed']);
        $this->assertSame('[Etap: Wieczór: farsz] Zmiel mięso.', $roznice['kroki'][0]['po']);
        $this->assertFalse($roznice['brakZmian']);

        $this->get(route('recipes.history.version', [$przepis->slug, $wersje->last()->version_number]))->assertOk()
            ->assertSee('Wieczór: farsz');
    }

    public function test_migawka_sprzed_funkcji_nie_ma_etapow_i_nie_daje_falszywej_roznicy(): void
    {
        $bez = ['steps' => [['position' => 0, 'instruction' => 'Mieszaj.', 'timer_seconds' => null]]];
        $z = ['steps' => [['position' => 0, 'instruction' => 'Mieszaj.', 'timer_seconds' => null, 'section_name' => null]]];

        $roznice = PorownanieWersji::porownaj($bez, $z);

        $this->assertSame([], $roznice['kroki']);
        $this->assertNull((new MigawkaWersji($bez))->kroki()[0]['section_name']);
    }

    public function test_wlasna_wersja_zachowuje_etapy(): void
    {
        $przepis = $this->przepis($this->user('autor2652j'));
        $kucharz = $this->user('kucharz2652j');

        $kopia = app(ZrobWlasnaWersje::class)->handle($kucharz, $przepis);

        $this->assertSame(
            ['Dzień 1: farsz', null, 'Dzień 2: lepienie'],
            $kopia->steps()->orderBy('position')->pluck('section_name')->all(),
        );
    }

    public function test_eksport_danych_i_html_niosa_etapy_a_reimport_je_odtwarza(): void
    {
        $autor = $this->user('autor2652k');
        $przepis = $this->przepis($autor);

        $paczka = app(CollectUserExportData::class)->handle($autor->fresh(), new ExportPhotoPlan($autor), Carbon::parse('2026-10-02 12:00:00', 'UTC'));
        $kroki = $paczka['przepisy'][0]['kroki'];
        $this->assertSame(['Dzień 1: farsz', null, 'Dzień 2: lepienie'], array_column($kroki, 'etap'));

        $html = view('exports.recipe', [
            'recipe' => $przepis->load('steps.media'),
            'heroPhoto' => null,
            'scanPhoto' => null,
            'stepPhotos' => [],
            'comments' => [],
        ])->render();
        $this->assertStringContainsString('<h3>Dzień 1: farsz</h3>', $html);
        $this->assertStringContainsString('<h3>Dzień 2: lepienie</h3>', $html);
        $this->assertStringContainsString('start="3"', $html, 'Drugi etap zachowuje numer instrukcji w całym przepisie.');

        $this->assertSame(
            ['Dzień 1: farsz', null, 'Dzień 2: lepienie'],
            $this->etapyPoWczytaniu($paczka['przepisy'][0], 'Pierogi po reimporcie'),
        );
    }

    public function test_stara_paczka_bez_etapow_daje_poprawny_przepis_bez_etapow(): void
    {
        $etapy = $this->etapyPoWczytaniu([
            'kroki' => [['numer' => 1, 'opis' => 'Gotuj.'], ['numer' => 2, 'opis' => 'Podaj.']],
        ], 'Rosół ze starej paczki');

        $this->assertSame([null, null], $etapy);
    }

    public function test_migracja_odmawia_cofniecia_gdy_sa_zapisane_etapy_i_cofa_gdy_ich_nie_ma(): void
    {
        $przepis = $this->przepis($this->user('autor2652l'));
        $migracja = require database_path('migrations/2026_10_03_110000_add_section_name_to_recipe_steps.php');

        try {
            $migracja->down();
            $this->fail('Rollback powinien odmówić przy zapisanych nazwach etapów.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nazwy etapów przygotowania', $e->getMessage());
            $this->assertTrue(Schema::hasColumn('recipe_steps', 'section_name'));
        }

        $this->assertSame(3, $przepis->steps()->count());
        DB::table('recipe_steps')->update(['section_name' => null]);

        $migracja->down();
        $this->assertFalse(Schema::hasColumn('recipe_steps', 'section_name'));

        $migracja->up();
        $this->assertTrue(Schema::hasColumn('recipe_steps', 'section_name'));
    }

    public function test_baza_odrzuca_pusta_nazwe_etapu(): void
    {
        $przepis = $this->przepis($this->user('autor2652m'));
        $krok = RecipeStep::query()->where('recipe_id', $przepis->getKey())->firstOrFail();

        $this->expectExceptionMessage('recipe_steps_section_name_check');
        DB::table('recipe_steps')->where('id', $krok->getKey())->update(['section_name' => '  ']);
    }

    public function test_kreator_wczytuje_zapisuje_i_waliduje_nazwy_etapow_bez_gubienia_wpisow(): void
    {
        $autor = $this->user('autor2652n');
        $przepis = $this->przepis($autor);

        $komponent = Livewire::actingAs($autor)->test('recipe-wizard', ['recipeId' => $przepis->id])
            ->assertSet('steps.0.section_name', 'Dzień 1: farsz')
            ->assertSet('steps.1.section_name', '')
            ->assertSet('steps.2.section_name', 'Dzień 2: lepienie')
            ->set('step', 3)
            ->assertSee('Nazwa etapu nad tym krokiem');

        $komponent->set('steps.0.section_name', str_repeat('y', 121))
            ->set('steps.0.instruction', 'Wpisany ważny tekst.')
            ->set('step', 4)
            ->call('publish')
            ->assertHasErrors(['steps.0.section_name'])
            ->assertSet('steps.0.instruction', 'Wpisany ważny tekst.')
            ->assertSet('steps.0.section_name', str_repeat('y', 121));
        $this->assertSame('Dzień 1: farsz', $przepis->steps()->orderBy('position')->first()?->section_name);

        $komponent->set('steps.0.section_name', 'Wieczór: farsz')
            ->set('step', 4)
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertSame(
            ['Wieczór: farsz', null, 'Dzień 2: lepienie'],
            $przepis->fresh()->steps()->orderBy('position')->pluck('section_name')->all(),
        );
    }

    /**
     * Wczytuje przepis z paczki prawdziwym przebiegiem (podgląd + zapis) i zwraca etapy kroków szkicu.
     *
     * @param  array<string, mixed>  $przepisZPaczki
     * @return list<?string>
     */
    private function etapyPoWczytaniu(array $przepisZPaczki, string $tytul): array
    {
        $przepisZPaczki['tytul'] = $tytul;
        $przepisZPaczki['status'] = Recipe::STATUS_DRAFT;
        $przepisZPaczki['widocznosc'] = 'private';
        $przepisZPaczki['skladniki'] ??= [['zapis' => '2 jajka', 'grupa' => null, 'uwaga' => null, 'zamienniki' => null]];

        $sciezka = tempnam(sys_get_temp_dir(), 'kuking-etapy-');
        $this->assertIsString($sciezka);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($sciezka, ZipArchive::OVERWRITE | ZipArchive::CREATE) === true);
        $zip->addFromString('dane.json', json_encode([
            'o_tym_pliku' => ['serwis' => 'Kuking.pl', 'wersja_formatu' => WersjaFormatuPaczki::AKTUALNA, 'wygenerowano' => '2027-03-14T10:00:00+00:00'],
            'przepisy' => [$przepisZPaczki],
            'wpisy' => [],
            'kolekcje' => [],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $zip->close();

        $osoba = $this->user('reimport'.substr(md5($tytul), 0, 8));
        $podglad = (new PodgladPaczkiEksportu)->czytaj($osoba, $sciezka);
        unlink($sciezka);
        app(WczytajPaczke::class)->handle($osoba, $podglad, array_map(fn (PozycjaPodgladu $p): string => $p->odcisk, $podglad->wszystkie()));

        return Recipe::query()->where('author_id', $osoba->getKey())->sole()
            ->steps()->orderBy('position')->pluck('section_name')->all();
    }
}

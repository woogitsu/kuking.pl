<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\Actions\ZapiszSzkicZImportu;
use App\Domain\Import\BramkaPublikacjiOdczytu;
use App\Domain\Import\KlientLuna;
use App\Domain\Import\KomunikatImportu;
use App\Domain\Import\OdczytanyPrzepis;
use App\Domain\Import\OdczytKartki;
use App\Domain\Import\PominieteWImporcie;
use App\Domain\Import\StrazImportu;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\ImportPrzepisu;
use App\Models\PrzepisZImportu;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * #2521: import, który musi pominąć wiersze albo uciąć pola ponad limity
 * (120 składników, 60 kroków, długości pól), NIE robi tego po cichu.
 * Decyzja właściciela z 2.10.2026: szkic z trwałym ostrzeżeniem
 * (`przepisy_z_importu.pominiete`), publikacja wymaga świadomego potwierdzenia.
 *
 * @bez-kontroli-dodatniej Nie asertuje na treści źródeł: ładuje plik migracji tylko po to, by wywołać jej `down()`/`up()` na bazie, a resztę sprawdza zachowaniem (kontrola ujemna zrobiona ręcznie: wyzerowanie zliczania i wyłączenie zapisu w zadaniu oblewa testy).
 */
final class NiepelnyImportTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Granice: OdczytanyPrzepis (adres strony i PDF)
    // ------------------------------------------------------------------

    /** @return array<string, array{int, int}> */
    public static function granicaSkladnikow(): array
    {
        return ['n119' => [119, 0], 'n120' => [120, 0], 'n121' => [121, 1], 'n125' => [125, 5]];
    }

    #[DataProvider('granicaSkladnikow')]
    public function test_skladniki_119_120_121_liczba_pominietych(int $ile, int $pominieto): void
    {
        $przepis = new OdczytanyPrzepis('Bigos', skladniki: self::wiersze('składnik', $ile), kroki: ['Gotuj.']);

        $this->assertCount(min($ile, 120), $przepis->skladniki);
        $this->assertSame($pominieto, $przepis->pominiete->skladniki);
        $this->assertSame(0, $przepis->pominiete->kroki);
        $this->assertSame($pominieto > 0, $przepis->pominiete->niepelny());
    }

    /** @return array<string, array{int, int}> */
    public static function granicaKrokow(): array
    {
        return ['n59' => [59, 0], 'n60' => [60, 0], 'n61' => [61, 1], 'n70' => [70, 10]];
    }

    #[DataProvider('granicaKrokow')]
    public function test_kroki_59_60_61_liczba_pominietych(int $ile, int $pominieto): void
    {
        $przepis = new OdczytanyPrzepis('Bigos', skladniki: ['kapusta'], kroki: self::wiersze('krok', $ile));

        $this->assertCount(min($ile, 60), $przepis->kroki);
        $this->assertSame($pominieto, $przepis->pominiete->kroki);
        $this->assertSame($pominieto > 0, $przepis->pominiete->niepelny());
    }

    public function test_puste_wiersze_nie_wliczaja_sie_do_limitu(): void
    {
        // 120 prawdziwych składników przeplecionych 200 pustymi: nic nie jest pominięte.
        $wiersze = [];
        foreach (self::wiersze('składnik', 120) as $w) {
            $wiersze[] = $w;
            $wiersze[] = '   ';
            $wiersze[] = "\u{00A0}";
        }

        $przepis = new OdczytanyPrzepis('Bigos', skladniki: $wiersze, kroki: ['Gotuj.']);

        $this->assertCount(120, $przepis->skladniki);
        $this->assertFalse($przepis->pominiete->niepelny());
        $this->assertNull($przepis->pominiete->doTablicy());
    }

    public function test_dlugosc_pola_na_granicy_i_ponad_granica_oraz_znaki_wielobajtowe(): void
    {
        $naGranicy = str_repeat('ł', 240);
        $ponad = str_repeat('ł', 240).'KONIEC';

        $przepis = new OdczytanyPrzepis('Sernik', skladniki: [$naGranicy, 'mąka', $ponad], kroki: [str_repeat('ą', 4000), str_repeat('ą', 4001)]);

        $this->assertSame($naGranicy, $przepis->skladniki[0], 'Pole równo na granicy nie jest ucinane.');
        $this->assertSame(240, mb_strlen($przepis->skladniki[2]));
        $this->assertStringEndsWith('…', $przepis->skladniki[2]);
        $this->assertSame(4000, mb_strlen($przepis->kroki[1]));
        $this->assertStringEndsWith('…', $przepis->kroki[1]);
        $this->assertSame(['skladnik:3', 'krok:2'], $przepis->pominiete->obciete);
        $this->assertSame(0, $przepis->pominiete->skladniki);
    }

    public function test_obciete_pola_tytulu_i_opisu_sa_wymienione(): void
    {
        $przepis = new OdczytanyPrzepis(str_repeat('T', 300), str_repeat('o', 5000), skladniki: ['sól']);

        $this->assertContains('tytul', $przepis->pominiete->obciete);
        $this->assertContains('opis', $przepis->pominiete->obciete);
    }

    public function test_numer_obcietego_wiersza_ponad_limitem_nie_trafia_na_liste(): void
    {
        $wiersze = self::wiersze('składnik', 120);
        $wiersze[] = str_repeat('x', 500);

        $przepis = new OdczytanyPrzepis('Zupa', skladniki: $wiersze, kroki: ['Gotuj.']);

        $this->assertSame(1, $przepis->pominiete->skladniki);
        $this->assertSame([], $przepis->pominiete->obciete);
    }

    // ------------------------------------------------------------------
    // Granice: OdczytKartki (zdjęcie)
    // ------------------------------------------------------------------

    public function test_ocr_skladniki_i_kroki_na_granicy_ponad_granica_i_z_pustymi(): void
    {
        $dane = static fn (int $s, int $k): array => [
            'nieczytelne' => false, 'tytul' => 'Rosół', 'porcje' => null, 'uwagi' => null,
            'skladniki' => array_map(static fn (string $t): array => ['tekst' => $t, 'grupa' => null], self::wiersze('składnik', $s)),
            'kroki' => array_map(static fn (string $t): array => ['tekst' => $t], self::wiersze('krok', $k)),
        ];

        $wynik = OdczytKartki::wynik($dane(120, 60));
        $this->assertNull($wynik['pominiete'], '120/60 mieści się w całości.');

        $wynik = OdczytKartki::wynik($dane(121, 61));
        $this->assertCount(120, $wynik['skladniki']);
        $this->assertCount(60, $wynik['kroki']);
        $this->assertSame(['skladniki' => 1, 'kroki' => 1, 'obciete' => []], $wynik['pominiete']);

        $wynik = OdczytKartki::wynik($dane(119, 59));
        $this->assertNull($wynik['pominiete']);

        // Puste wiersze nie liczą się do limitu ani do pominiętych.
        $z_pustymi = $dane(120, 60);
        $z_pustymi['skladniki'][] = ['tekst' => '   ', 'grupa' => null];
        $z_pustymi['kroki'][] = ['tekst' => ''];
        $this->assertNull(OdczytKartki::wynik($z_pustymi)['pominiete']);
    }

    public function test_ocr_ucina_z_wielokropkiem_i_wymienia_pola_takze_wielobajtowe(): void
    {
        $wynik = OdczytKartki::wynik([
            'nieczytelne' => false,
            'tytul' => str_repeat('Ż', 300),
            'porcje' => null,
            'uwagi' => null,
            'skladniki' => [
                ['tekst' => str_repeat('ó', 240), 'grupa' => null],
                ['tekst' => str_repeat('ó', 240).'LOST_END', 'grupa' => str_repeat('g', 200)],
            ],
            'kroki' => [['tekst' => str_repeat('ę', 4000).'LOST_END']],
        ]);

        $this->assertSame(str_repeat('ó', 240), $wynik['skladniki'][0]['text'], 'Na granicy: bez zmian i bez wielokropka.');
        $this->assertSame(240, mb_strlen($wynik['skladniki'][1]['text']));
        $this->assertStringEndsWith('…', $wynik['skladniki'][1]['text']);
        $this->assertStringNotContainsString('LOST_END', $wynik['skladniki'][1]['text']);
        $this->assertStringEndsWith('…', $wynik['kroki'][0]['instruction']);
        $this->assertSame(4000, mb_strlen($wynik['kroki'][0]['instruction']));
        $this->assertStringEndsWith('…', $wynik['tytul']);
        $this->assertSame(['tytul', 'skladnik:2', 'grupa:2', 'krok:1'], $wynik['pominiete']['obciete']);
    }

    // ------------------------------------------------------------------
    // Komunikat po polsku
    // ------------------------------------------------------------------

    public function test_komunikat_mowi_ile_pominieto_co_ucieto_i_co_zrobic(): void
    {
        $tresc = (new PominieteWImporcie(3, 1, ['skladnik:5', 'krok:2', 'tytul']))->komunikat();

        $this->assertStringContainsString('Pominęliśmy 3 składniki i 1 krok', $tresc);
        $this->assertStringContainsString('limit 120 składników i 60 kroków', $tresc);
        $this->assertStringContainsString('5. składnik, 2. krok, nazwa przepisu', $tresc);
        $this->assertStringContainsString('Co zrobić: porównaj szkic ze źródłem, dopisz brakujące pozycje ręcznie albo podziel przepis na dwa.', $tresc);
        $this->assertStringContainsString('Brak takiego ostrzeżenia nie znaczy, że odczyt jest bezbłędny', $tresc);
        $this->assertStringContainsString('Pominęliśmy 5 składników', (new PominieteWImporcie(5))->komunikat());
        $this->assertStringContainsString('Pominęliśmy 12 składników', (new PominieteWImporcie(12))->komunikat());
        $this->assertStringContainsString('Pominęliśmy 22 składniki', (new PominieteWImporcie(22))->komunikat());
    }

    public function test_odtworzenie_z_bazy_odrzuca_smieci_i_kompletny_import_to_null(): void
    {
        $this->assertNull(PominieteWImporcie::zTablicy(null));
        $this->assertNull(PominieteWImporcie::zTablicy(['skladniki' => 0, 'kroki' => 0, 'obciete' => ['zle<script>']]));
        $this->assertSame(['krok:2'], PominieteWImporcie::zTablicy(['skladniki' => 1, 'kroki' => 0, 'obciete' => ['krok:2', 'zle']])?->obciete);
    }

    // ------------------------------------------------------------------
    // Adres strony / PDF: szkic z ostrzeżeniem, po ponownym otwarciu
    // ------------------------------------------------------------------

    public function test_szkic_z_adresu_za_dlugiego_przepisu_zapisuje_ostrzezenie_i_pokazuje_je_przy_kazdym_otwarciu(): void
    {
        $autor = $this->user('autor');
        $szkic = $this->szkicZAdresu($autor, 121, 61);

        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->status);
        $this->assertSame(120, $szkic->ingredients()->count());
        $this->assertSame(60, $szkic->steps()->count());
        $this->assertEqualsCanonicalizing(
            ['skladniki' => 1, 'kroki' => 1, 'obciete' => []],
            PrzepisZImportu::query()->findOrFail($szkic->getKey())->pominiete,
        );

        // „Ponowne otwarcie”: dwa osobne żądania, nic w sesji.
        foreach ([1, 2] as $_) {
            $this->actingAs($autor)->get(route('recipes.edit', $szkic))
                ->assertOk()
                ->assertSee('Ten import jest niepełny.')
                ->assertSee('Pominęliśmy 1 składnik i 1 krok')
                ->assertSee('podziel przepis na dwa');
        }

        Livewire::actingAs($autor)->test('recipe-wizard', ['recipeId' => $szkic->getKey()])
            ->assertSee('Ten import jest niepełny.')
            ->assertSee('Pominęliśmy 1 składnik i 1 krok')
            ->set('step', 2)
            ->assertSee('Ten import jest niepełny.');
    }

    public function test_kompletny_import_nie_ma_ostrzezenia(): void
    {
        $autor = $this->user('autor');
        $szkic = $this->szkicZAdresu($autor, 120, 60);

        $this->assertNull(PrzepisZImportu::query()->findOrFail($szkic->getKey())->pominiete);
        $this->actingAs($autor)->get(route('recipes.edit', $szkic))
            ->assertOk()
            ->assertDontSee('Ten import jest niepełny.');
        Livewire::actingAs($autor)->test('recipe-wizard', ['recipeId' => $szkic->getKey()])
            ->assertDontSee('Ten import jest niepełny.');
    }

    public function test_ekran_postepu_nie_nazywa_niepelnego_importu_zwyklym_gotowym(): void
    {
        $autor = $this->user('autor');
        $szkic = $this->szkicZAdresu($autor, 130, 10);
        $import = $this->importGotowy($autor, $szkic, ImportPrzepisu::ZRODLO_URL);

        $komunikat = KomunikatImportu::dla($import);
        $this->assertSame('Szkic gotowy, ale import jest niepełny', $komunikat['tytul']);
        $this->assertStringContainsString('Pominęliśmy 10 składników', $komunikat['tresc']);

        $this->actingAs($autor)->get(route('import.show', $import))
            ->assertOk()
            ->assertSee('Szkic gotowy, ale import jest niepełny')
            ->assertSee('Pominęliśmy 10 składników');

        // Kontrola dodatnia: kompletny import dalej dostaje zwykły tytuł.
        $kompletny = $this->importGotowy($autor, $this->szkicZAdresu($autor, 5, 5), ImportPrzepisu::ZRODLO_URL);
        $this->assertSame('Szkic gotowy do sprawdzenia', KomunikatImportu::dla($kompletny)['tytul']);
    }

    public function test_publikacja_niepelnego_importu_z_adresu_wymaga_potwierdzenia_i_mowi_dlaczego(): void
    {
        $autor = $this->user('autor');
        $szkic = $this->szkicZAdresu($autor, 121, 1);

        try {
            app(PublishRecipe::class)->handle(
                $autor, ['title' => $szkic->title, 'visibility' => 'public'],
                [['text' => 'sól']], [['instruction' => 'Gotuj.']], true, $szkic,
            );
            $this->fail('Niepełny import opublikował się bez potwierdzenia.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(StrazImportu::KOMUNIKAT_SPRAWDZ_NIEPELNY, $e->getMessage());
        }
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->fresh()->status);

        $this->actingAs($autor)->put(route('recipes.update', $szkic), [
            'title' => $szkic->title, 'visibility' => 'private', 'source_type' => 'external', 'action' => 'publish',
            'content_revision' => $szkic->content_revision,
            'ingredients' => [['text' => 'sól']], 'steps' => [['instruction' => 'Gotuj własnymi słowami.']],
        ])->assertSessionHasErrors(['sprawdzilem_odczyt' => StrazImportu::KOMUNIKAT_SPRAWDZ_NIEPELNY]);
    }

    // ------------------------------------------------------------------
    // Zdjęcie kartki: cały przepływ przez zadanie odczytu
    // ------------------------------------------------------------------

    public function test_ocr_za_dlugiej_kartki_daje_szkic_z_trwalym_ostrzezeniem_bez_publikacji(): void
    {
        $autor = $this->gotowyOcr(121, 61);
        $import = ImportPrzepisu::query()->sole();
        $szkic = $import->recipe()->firstOrFail();

        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $import->status);
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->status);
        $this->assertSame(120, $szkic->ingredients()->count());
        $this->assertSame(60, $szkic->steps()->count());

        $wiersz = PrzepisZImportu::query()->findOrFail($szkic->getKey());
        $this->assertSame(PrzepisZImportu::ZRODLO_ZDJECIE, $wiersz->zrodlo);
        $this->assertEqualsCanonicalizing(['skladniki' => 1, 'kroki' => 1, 'obciete' => []], $wiersz->pominiete);
        // Prywatność: w kolumnie nie ma treści przepisu, tylko liczby i nazwy pól.
        $this->assertStringNotContainsString('składnik-', (string) json_encode($wiersz->pominiete));

        // Ekran postępu i ponowne otwarcie szkicu (formularz i kreator).
        $this->actingAs($autor)->get(route('import.show', $import))
            ->assertOk()
            ->assertSee('Szkic gotowy, ale import jest niepełny');
        $this->actingAs($autor)->get(route('recipes.edit', $szkic))
            ->assertOk()
            ->assertSee('Ten import jest niepełny.')
            ->assertDontSee('Zanim opublikujesz, porównaj odczytany tekst ze źródłem');
        Livewire::actingAs($autor)->test('recipe-wizard', ['recipeId' => $szkic->getKey()])
            ->assertSee('Ten import jest niepełny.')
            ->assertSet('zOdczytu', true)
            ->assertSet('wymagaSprawdzenia', false);
    }

    public function test_ocr_niepelny_odczyt_wymaga_potwierdzenia_w_bramce_i_w_kreatorze(): void
    {
        $autor = $this->gotowyOcr(121, 2);
        $szkic = ImportPrzepisu::query()->sole()->recipe()->firstOrFail();

        $this->actingAs($autor)->put(route('recipes.update', $szkic), [
            'title' => 'Duży przepis', 'visibility' => 'private', 'source_type' => 'own', 'action' => 'publish',
            'content_revision' => $szkic->content_revision,
            'ingredients' => [['text' => 'sól']], 'steps' => [['instruction' => 'Gotuj.']],
        ])->assertSessionHasErrors(['odczyt_sprawdzony' => BramkaPublikacjiOdczytu::KOMUNIKAT_SPRAWDZENIE_NIEPELNY]);
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->fresh()->status);

        Livewire::actingAs($autor)->test('recipe-wizard', ['recipeId' => $szkic->getKey()])
            ->set('step', 4)->call('publish')
            ->assertHasErrors(['odczyt_sprawdzony']);
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->fresh()->status);

        // Kontrola dodatnia: z potwierdzeniem publikuje się (bramka nie blokuje na stałe).
        try {
            app(PublishRecipe::class)->handle(
                $autor,
                ['title' => 'Duży przepis', 'visibility' => 'private', 'odczyt_sprawdzony' => true, 'source_scan_media_id' => $szkic->source_scan_media_id],
                [['text' => 'sól']], [['instruction' => 'Gotuj.']], false, $szkic->fresh(),
            );
        } catch (ValidationException $e) {
            $this->fail('Bramka odrzuciła zapis szkicu: '.json_encode($e->errors()));
        }
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->fresh()->status);
    }

    public function test_ocr_kompletna_kartka_nie_tworzy_wiersza_pochodzenia(): void
    {
        $this->gotowyOcr(3, 3);

        $this->assertSame(0, PrzepisZImportu::query()->count());
    }

    public function test_ocr_pole_ucieto_ostrzega_o_polu_mimo_komplet_wierszy(): void
    {
        $autor = $this->gotowyOcr(2, 2, str_repeat('ą', 300));
        $szkic = ImportPrzepisu::query()->sole()->recipe()->firstOrFail();

        $this->assertEqualsCanonicalizing(['skladniki' => 0, 'kroki' => 0, 'obciete' => ['skladnik:1']], PrzepisZImportu::query()->findOrFail($szkic->getKey())->pominiete);
        $this->actingAs($autor)->get(route('recipes.edit', $szkic))
            ->assertSee('Za długie pola zostały skrócone')
            ->assertSee('1. składnik');
    }

    // ------------------------------------------------------------------
    // Rollback (D-088)
    // ------------------------------------------------------------------

    public function test_rollback_odmawia_przy_niesprawdzonym_niepelnym_szkicu_i_przechodzi_po_sprawdzeniu(): void
    {
        $szkic = $this->szkicZAdresu($this->user('autor'), 121, 1);
        $migracja = require base_path('database/migrations/2026_10_05_300000_add_pominiete_to_przepisy_z_importu.php');

        try {
            $migracja->down();
            $this->fail('Cofnięcie przeszło mimo niesprawdzonego niepełnego importu.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba: 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('przepisy_z_importu', 'pominiete'));

        PrzepisZImportu::query()->whereKey($szkic->getKey())->update(['sprawdzone_at' => now()]);
        $migracja->down();
        $this->assertFalse(Schema::hasColumn('przepisy_z_importu', 'pominiete'));

        $migracja->up();
        $this->assertTrue(Schema::hasColumn('przepisy_z_importu', 'pominiete'));
    }

    // ------------------------------------------------------------------
    // Pomocnicze
    // ------------------------------------------------------------------

    /** @return list<string> */
    private static function wiersze(string $nazwa, int $ile): array
    {
        return array_map(static fn (int $i): string => $nazwa.'-'.$i, range(1, $ile));
    }

    private function szkicZAdresu(User $autor, int $skladniki, int $kroki): Recipe
    {
        return app(ZapiszSzkicZImportu::class)->handle(
            $autor,
            PrzepisZImportu::ZRODLO_URL,
            'json_ld',
            new OdczytanyPrzepis('Duży przepis', skladniki: self::wiersze('składnik', $skladniki), kroki: self::wiersze('krok', $kroki)),
            'https://przepisy.example.pl/duzy',
        );
    }

    private function importGotowy(User $autor, Recipe $szkic, string $zrodlo): ImportPrzepisu
    {
        $import = new ImportPrzepisu;
        $import->forceFill([
            'user_id' => $autor->getKey(),
            'recipe_id' => $szkic->getKey(),
            'zrodlo' => $zrodlo,
            'status' => ImportPrzepisu::STATUS_GOTOWY,
        ])->save();

        return $import;
    }

    /** Pełny odczyt zdjęcia: HTTP atrapą, kolejka `sync`. */
    private function gotowyOcr(int $skladniki, int $kroki, ?string $pierwszySkladnik = null): User
    {
        config([
            'kuking.import.zrodla.zdjecie' => true,
            'kuking.import.model.klucz' => 'sk-test-import',
            'kuking.import.model.endpoint' => KlientLuna::ADRES,
            'kuking.import.model.nazwa' => 'gpt-6-luna',
            'kuking.import.model.wysilek.ocr' => 'medium',
            'kuking.import.model.cena_wejscie_mln_usd' => '2',
            'kuking.import.model.cena_wyjscie_mln_usd' => '8',
        ]);
        Storage::fake('public');

        $autor = $this->user('kartki');
        app(PrzestawZgodeNaOdczytAi::class)->handle($autor, true, WpisZgody::ZRODLO_EKRAN_IMPORTU);

        $skladnikiModelu = array_map(static fn (string $t): array => ['tekst' => $t, 'grupa' => null], self::wiersze('składnik', $skladniki));
        if ($pierwszySkladnik !== null) {
            $skladnikiModelu[0]['tekst'] = $pierwszySkladnik;
        }

        Http::fake(['api.openai.com/*' => Http::response([
            'id' => 'resp_test',
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode([
                'nieczytelne' => false,
                'tytul' => 'Duży przepis',
                'porcje' => null,
                'uwagi' => null,
                'skladniki' => $skladnikiModelu,
                'kroki' => array_map(static fn (string $t): array => ['tekst' => $t], self::wiersze('krok', $kroki)),
            ])]]]],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ])]);

        $this->actingAs($autor)->post(route('import.zlec'), ['zdjecie' => UploadedFile::fake()->image('kartka.jpg', 1200, 1600)]);

        return $autor;
    }
}

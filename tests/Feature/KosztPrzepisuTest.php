<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Domain\Recipes\KosztPrzepisu;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Szacunkowy koszt dania podany przez autora (V2, D-286).
 *
 * Pilnuje czterech obietnic naraz: kwota wpisana po polsku („24,50") się
 * zapisuje, błędna dostaje zdanie mówiące CO ZROBIĆ i nie znika z pola,
 * strona przepisu mówi „ok." i „wg autora", a droga bez tego pola (ekran
 * dodawania) nie kasuje kwoty po cichu.
 */
class KosztPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Czyste reguły
    // ------------------------------------------------------------------

    /** @return array<string, array{0: mixed, 1: ?string}> */
    public static function wpisy(): array
    {
        return [
            'przecinek' => ['24,50', '24.50'],
            'dopisek zł' => ['24 zł', '24'],
            'dopisek zl z kropką' => ['24 zl.', '24'],
            'spacje w tysiącach' => ['1 200', '1200'],
            'twarda spacja' => ["1\u{00A0}200,5", '1200.5'],
            'puste' => ['   ', null],
            'null' => [null, null],
            'tekst zostaje dla walidatora' => ['abc', 'abc'],
        ];
    }

    #[Test]
    #[DataProvider('wpisy')]
    public function normalizacja_rozumie_zapis_po_polsku(mixed $wpis, ?string $oczekiwane): void
    {
        $this->assertSame($oczekiwane, KosztPrzepisu::normalizuj($wpis));
    }

    #[Test]
    public function zdanie_mowi_ok_i_wg_autora_bez_zbednych_zer(): void
    {
        $this->assertSame('Szacunkowy koszt: ok. 24 zł (wg autora)', KosztPrzepisu::zdanie(24.0));
        $this->assertSame('Szacunkowy koszt: ok. 24,50 zł (wg autora)', KosztPrzepisu::zdanie(24.5));
        $this->assertSame('Szacunkowy koszt: ok. 1 200 zł (wg autora)', KosztPrzepisu::zdanie(1200.0));
        $this->assertSame('24,50', KosztPrzepisu::doPola(24.5));
        $this->assertSame('24', KosztPrzepisu::doPola(24.0));
        $this->assertSame('', KosztPrzepisu::doPola(null));
    }

    #[Test]
    public function przeliczenie_na_porcje_jest_proporcjonalne_i_nie_zgaduje_bazy(): void
    {
        $this->assertSame(36.0, KosztPrzepisu::naPorcje(24.0, 4.0, 6.0));
        $this->assertSame(12.0, KosztPrzepisu::naPorcje(24.0, 4.0, 2.0));
        // Bez liczby porcji autora nie ma podstawy proporcji.
        $this->assertNull(KosztPrzepisu::naPorcje(24.0, null, 6.0));
        $this->assertStringContainsString('przeliczone z kosztu podanego przez autora', KosztPrzepisu::zdaniePrzeliczone(36.0));
    }

    // ------------------------------------------------------------------
    // Formularz szczegółów (zwykły POST)
    // ------------------------------------------------------------------

    #[Test]
    public function autor_zapisuje_koszt_z_przecinkiem_i_widzi_go_na_stronie_przepisu(): void
    {
        [$autor, $przepis] = $this->przepisAutora();

        $this->actingAs($autor)
            ->put(route('recipes.update', $przepis->slug), $this->formularz(['estimated_cost_pln' => '24,50']))
            ->assertSessionHasNoErrors();

        $this->assertSame(24.5, $przepis->fresh()->estimated_cost_pln);

        $this->get(route('recipes.show', $przepis->fresh()->slug))
            ->assertOk()
            ->assertSee('Szacunkowy koszt: ok. 24,50 zł (wg autora)');

        // Pole w formularzu wraca tak, jak się to pisze po polsku.
        $this->get(route('recipes.edit', $przepis->fresh()->slug))
            ->assertOk()
            ->assertSee('value="24,50"', false)
            ->assertSee('Przybliżony koszt całego przepisu (zł)');
    }

    #[Test]
    public function przepis_bez_kosztu_o_koszcie_milczy(): void
    {
        [, $przepis] = $this->przepisAutora();

        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertDontSee('Szacunkowy koszt');
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function bledneKwoty(): array
    {
        return [
            'ujemna' => ['-5', 'Koszt nie może być mniejszy od zera.'],
            'trzy miejsca' => ['24,555', 'najwyżej dwa miejsca po przecinku'],
            'tekst' => ['dużo', 'Koszt wpisz samą liczbą złotych'],
            'za duża' => ['10000', 'nierealnie wysoki'],
        ];
    }

    #[Test]
    #[DataProvider('bledneKwoty')]
    public function bledna_kwota_dostaje_polskie_zdanie_i_nie_znika_z_pola(string $wpis, string $fragment): void
    {
        [$autor, $przepis] = $this->przepisAutora(['estimated_cost_pln' => 30]);
        $adres = route('recipes.edit', $przepis->slug);

        $this->actingAs($autor)
            ->from($adres)
            ->put(route('recipes.update', $przepis->slug), $this->formularz(['estimated_cost_pln' => $wpis]))
            ->assertRedirect($adres)
            ->assertSessionHasErrors(['estimated_cost_pln']);

        $this->assertStringContainsString($fragment, (string) session('errors')->first('estimated_cost_pln'));
        // Nic nie zapisało się w połowie.
        $this->assertSame(30.0, $przepis->fresh()->estimated_cost_pln);

        // `old()` oddaje DOKŁADNIE to, co wpisano — nie przeróbkę z kropką.
        $this->get($adres)->assertOk()->assertSee('value="'.e($wpis).'"', false);
    }

    #[Test]
    public function wyczyszczone_pole_kasuje_koszt(): void
    {
        [$autor, $przepis] = $this->przepisAutora(['estimated_cost_pln' => 30]);

        $this->actingAs($autor)
            ->put(route('recipes.update', $przepis->slug), $this->formularz(['estimated_cost_pln' => '']))
            ->assertSessionHasNoErrors();

        $this->assertNull($przepis->fresh()->estimated_cost_pln);
    }

    #[Test]
    public function zero_zlotych_to_odpowiedz_a_nie_brak_kosztu(): void
    {
        [$autor, $przepis] = $this->przepisAutora();

        $this->actingAs($autor)
            ->put(route('recipes.update', $przepis->slug), $this->formularz(['estimated_cost_pln' => '0']))
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, $przepis->fresh()->estimated_cost_pln);
        $this->get(route('recipes.show', $przepis->fresh()->slug))->assertSee('Szacunkowy koszt: ok. 0 zł (wg autora)');
    }

    #[Test]
    public function zapis_bez_pola_kosztu_nie_kasuje_dawnej_kwoty(): void
    {
        [$autor, $przepis] = $this->przepisAutora(['estimated_cost_pln' => 30]);

        // Żądanie bez klucza `estimated_cost_pln` — tak wysyła każda droga,
        // która tego pola nie zna.
        $this->actingAs($autor)
            ->put(route('recipes.update', $przepis->slug), $this->formularz())
            ->assertSessionHasNoErrors();

        $this->assertSame(30.0, $przepis->fresh()->estimated_cost_pln);
    }

    #[Test]
    public function obca_osoba_nie_zmieni_kosztu_cudzego_przepisu(): void
    {
        [, $przepis] = $this->przepisAutora(['estimated_cost_pln' => 30]);
        $obcy = $this->user('obcy');

        $this->actingAs($obcy)
            ->put(route('recipes.update', $przepis->slug), $this->formularz(['estimated_cost_pln' => '1']))
            ->assertForbidden();

        $this->assertSame(30.0, $przepis->fresh()->estimated_cost_pln);
    }

    // ------------------------------------------------------------------
    // Kreator (Livewire)
    // ------------------------------------------------------------------

    #[Test]
    public function kreator_zapisuje_koszt_i_pokazuje_go_w_podgladzie(): void
    {
        $basia = $this->user('basia');

        $komponent = Livewire::actingAs($basia)
            ->test('recipe-wizard')
            ->set('title', 'Placki ziemniaczane')
            ->set('estimated_cost_pln', '12,5')
            ->call('next')
            ->assertHasNoErrors()
            ->assertSet('step', 2);

        $przepis = Recipe::where('title', 'Placki ziemniaczane')->firstOrFail();
        $this->assertSame(12.5, $przepis->estimated_cost_pln);
        $this->assertSame('Szacunkowy koszt: ok. 12,50 zł (wg autora)', $komponent->instance()->previewCostLabel());

        // Powrót do kreatora: kwota wraca po polsku.
        Livewire::actingAs($basia)
            ->test('recipe-wizard', ['recipeId' => $przepis->getKey()])
            ->assertSet('estimated_cost_pln', '12,50');
    }

    #[Test]
    public function kreator_odrzuca_bledny_koszt_przy_polu_i_zostawia_wpis(): void
    {
        $basia = $this->user('basia');

        Livewire::actingAs($basia)
            ->test('recipe-wizard')
            ->set('title', 'Placki ziemniaczane')
            ->set('estimated_cost_pln', '-3')
            ->call('next')
            ->assertSet('step', 1)
            ->assertHasErrors(['estimated_cost_pln'])
            ->assertSet('estimated_cost_pln', '-3')
            ->assertSee('Koszt nie może być mniejszy od zera.');
    }

    // ------------------------------------------------------------------
    // Wersje, eksport, baza, wyszukiwarka
    // ------------------------------------------------------------------

    #[Test]
    public function koszt_jest_w_wersji_przepisu_i_w_paczce_danych(): void
    {
        [$autor, $przepis] = $this->przepisAutora(['estimated_cost_pln' => 24.5]);

        $wersja = app(SnapshotRecipeVersion::class)->handle($przepis, $autor, 'test');
        $this->assertEquals(24.5, $wersja->snapshot['estimated_cost_pln']);

        $dane = app(CollectUserExportData::class)->handle($autor->fresh(), new ExportPhotoPlan($autor->fresh()), Carbon::now());
        $wiersz = collect($dane['przepisy'])->firstWhere('adres_w_serwisie', $przepis->slug);
        $this->assertNotNull($wiersz, 'Przepisu nie ma w paczce — test mierzyłby nie to.');
        $this->assertArrayHasKey('szacunkowy_koszt_zl', $wiersz);
        $this->assertSame(24.5, $wiersz['szacunkowy_koszt_zl']);
    }

    #[Test]
    public function baza_odrzuca_ujemny_koszt_nawet_z_pominieciem_formularza(): void
    {
        [, $przepis] = $this->przepisAutora();

        $this->expectException(QueryException::class);
        DB::table('recipes')->where('id', $przepis->id)->update(['estimated_cost_pln' => -1]);
    }

    #[Test]
    public function zakres_do_20_zl_pokazuje_tylko_przepisy_z_kosztem_do_granicy(): void
    {
        $autor = $this->user('kasia');
        $tani = $this->przepisDla($autor, 'Kalafior tani', 19.99);
        $graniczny = $this->przepisDla($autor, 'Kalafior graniczny', 20);
        $drogi = $this->przepisDla($autor, 'Kalafior drogi', 20.01);
        $bezKosztu = $this->przepisDla($autor, 'Kalafior bez kosztu', null);

        $odpowiedz = $this->get(route('search', ['q' => 'Kalafior', 'sekcja' => 'tanie']))
            ->assertOk()
            ->assertSee('Do 20 zł');

        $znalezione = $odpowiedz->viewData('recipes')->pluck('id')->all();
        $this->assertContains($tani->id, $znalezione);
        $this->assertContains($graniczny->id, $znalezione);
        $this->assertNotContains($drogi->id, $znalezione);
        // Brak kwoty nie znaczy „tanio".
        $this->assertNotContains($bezKosztu->id, $znalezione);

        // Kontrola dodatnia: bez zakresu widać wszystkie cztery.
        $wszystkie = $this->get(route('search', ['q' => 'Kalafior', 'sekcja' => 'przepisy']))->viewData('recipes');
        $this->assertCount(4, $wszystkie);
    }

    #[Test]
    public function pusty_zakres_do_20_zl_mowi_ze_koszt_podaje_autor(): void
    {
        $autor = $this->user('kasia');
        $this->przepisDla($autor, 'Kalafior bez kosztu', null);

        $this->get(route('search', ['q' => 'Kalafior', 'sekcja' => 'tanie']))
            ->assertOk()
            ->assertSee('Koszt podaje autor, a nie każdy go wpisuje');
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $atrybuty
     * @return array{0: User, 1: Recipe}
     */
    private function przepisAutora(array $atrybuty = []): array
    {
        $autor = $this->user('autor');
        $przepis = Recipe::factory()->create(['author_id' => $autor->id, 'title' => 'Bigos myśliwski', ...$atrybuty]);
        $przepis->steps()->create(['position' => 0, 'instruction' => 'Duś trzy godziny.']);

        return [$autor, $przepis->fresh()];
    }

    private function przepisDla(User $autor, string $tytul, float|int|null $koszt): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->id,
            'title' => $tytul,
            'estimated_cost_pln' => $koszt,
            'visibility' => 'public',
        ]);
    }

    /**
     * @param  array<string, mixed>  $dodatkowo
     * @return array<string, mixed>
     */
    private function formularz(array $dodatkowo = []): array
    {
        return [
            'title' => 'Bigos myśliwski',
            'visibility' => 'public',
            'source_type' => 'own',
            'action' => 'publish',
            'steps' => [['instruction' => 'Duś trzy godziny.']],
            ...$dodatkowo,
        ];
    }
}

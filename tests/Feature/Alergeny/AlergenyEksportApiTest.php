<?php

declare(strict_types=1);

namespace Tests\Feature\Alergeny;

use App\Domain\Recipes\Alergeny\Alergen;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Alergeny w eksporcie danych konta i w API (#1902, D-333): zawsze PARA stan + lista.
 * Sama pusta lista bez stanu mogłaby zostać odczytana jako „brak alergenów”.
 *
 * KONTROLA UJEMNA (ręcznie): (1) usunięcie `alergeny_stan` z eksportu oblewa
 * `test_eksport_niesie_stan_i_liste_razem`; (2) zwracanie `allergens` bez warunku `alergenyZdeklarowane()`
 * w `RecipeResource` oblewa `test_api_nie_oddaje_listy_bez_stanu_declared`; (3) zdjęcie warunku
 * flagi oblewa `test_api_bez_flagi_nie_ma_pola`.
 */
final class AlergenyEksportApiTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.alergeny.wlaczone' => true, 'kuking.api.wlaczone' => true]);
        $this->autor = $this->user('autor_eksportu_alergenow');
    }

    /** @param  list<string>  $kody */
    private function przepis(string $tytul, string $stan, array $kody = []): Recipe
    {
        $przepis = Recipe::factory()->create(['author_id' => $this->autor->getKey(), 'title' => $tytul]);

        if ($stan !== 'unchecked') {
            $przepis->forceFill(['allergen_status' => $stan, 'allergens' => Alergen::znormalizuj($kody), 'allergens_declared_at' => now()])->save();
        }

        return $przepis->refresh();
    }

    public function test_eksport_niesie_stan_i_liste_razem(): void
    {
        $this->przepis('Przepis zdeklarowany', 'declared', ['milk', 'gluten']);
        $this->przepis('Przepis pusty', 'declared', []);
        $this->przepis('Przepis niesprawdzony', 'unchecked');
        $this->przepis('Przepis do przeglądu', 'needs_review', ['eggs']);

        $paczka = app(CollectUserExportData::class)->handle($this->autor, new ExportPhotoPlan($this->autor), Carbon::parse('2026-10-01 12:00', 'UTC'));
        $przepisy = collect($paczka['przepisy'])->keyBy('tytul');

        $this->assertCount(4, $przepisy);
        foreach ($przepisy as $tytul => $wiersz) {
            $this->assertArrayHasKey('alergeny_stan', $wiersz, "Przepis „{$tytul}” nie ma stanu alergenów w eksporcie.");
            $this->assertArrayHasKey('alergeny', $wiersz, "Przepis „{$tytul}” nie ma listy alergenów w eksporcie.");
        }

        $this->assertSame('declared', $przepisy['Przepis zdeklarowany']['alergeny_stan']);
        $this->assertSame(['gluten', 'milk'], $przepisy['Przepis zdeklarowany']['alergeny']);
        $this->assertNotNull($przepisy['Przepis zdeklarowany']['alergeny_potwierdzone']);
        $this->assertSame('declared', $przepisy['Przepis pusty']['alergeny_stan']);
        $this->assertSame([], $przepisy['Przepis pusty']['alergeny']);
        $this->assertSame('unchecked', $przepisy['Przepis niesprawdzony']['alergeny_stan']);
        $this->assertNull($przepisy['Przepis niesprawdzony']['alergeny_potwierdzone']);
        $this->assertSame('needs_review', $przepisy['Przepis do przeglądu']['alergeny_stan']);

        // Po serializacji do `dane.json` nadal tablica (nie tekst `{milk}` z bazy).
        $json = json_decode(json_encode($paczka, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['gluten', 'milk'], collect($json['przepisy'])->firstWhere('tytul', 'Przepis zdeklarowany')['alergeny']);
    }

    public function test_api_oddaje_pare_stan_i_lista(): void
    {
        $przepis = $this->przepis('Zdeklarowany w API', 'declared', ['eggs', 'soy']);
        $this->jako($this->user('czytelnik_api'));

        $this->getJson('/api/v1/przepisy/'.$przepis->getKey())
            ->assertOk()
            ->assertJsonPath('data.allergens.status', 'declared')
            ->assertJsonPath('data.allergens.list', ['eggs', 'soy']);
    }

    public function test_api_nie_oddaje_listy_bez_stanu_declared(): void
    {
        $niesprawdzony = $this->przepis('Niesprawdzony w API', 'unchecked');
        $doPrzegladu = $this->przepis('Do przeglądu w API', 'needs_review', ['milk']);
        $this->jako($this->user('czytelnik_api_dwa'));

        // `null`, nie `[]`: pusta tablica dałaby się odczytać jako „brak alergenów”.
        $this->getJson('/api/v1/przepisy/'.$niesprawdzony->getKey())
            ->assertOk()
            ->assertJsonPath('data.allergens.status', 'unchecked')
            ->assertJsonPath('data.allergens.list', null);

        $odpowiedz = $this->getJson('/api/v1/przepisy/'.$doPrzegladu->getKey())->assertOk();
        $odpowiedz->assertJsonPath('data.allergens.status', 'needs_review')->assertJsonPath('data.allergens.list', null);
        $this->assertStringNotContainsString('milk', (string) $odpowiedz->getContent(), 'Lista z nieaktualnej deklaracji wyciekła przez API.');
    }

    public function test_api_bez_flagi_nie_ma_pola(): void
    {
        config(['kuking.alergeny.wlaczone' => false]);
        $przepis = $this->przepis('API bez flagi', 'declared', ['milk']);
        $this->jako($this->user('czytelnik_api_trzy'));

        $odpowiedz = $this->getJson('/api/v1/przepisy/'.$przepis->getKey())->assertOk();

        $this->assertArrayNotHasKey('allergens', $odpowiedz->json('data'));
    }

    /** Token osobisty jak w `CzytanieApiTest` — zwykłe żądanie Bearer, bez atrapy guarda. */
    private function jako(User $kto): void
    {
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$kto->createToken('Telefon')->plainTextToken);
    }
}

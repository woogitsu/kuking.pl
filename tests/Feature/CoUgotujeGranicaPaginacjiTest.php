<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pantry\PriorytetZuzycia;
use App\Domain\Pantry\ZmienTerminProduktu;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Prawdziwe HTTP i zapytanie domenowe na granicy głębokiego OFFSET (#2599). */
class CoUgotujeGranicaPaginacjiTest extends TestCase
{
    use RefreshDatabase;

    public function test_ostatnia_dostepna_strona_nie_odsyla_do_siebie_w_obu_trybach(): void
    {
        $widz = User::factory()->create();
        $autor = User::factory()->create();
        $produkt = $widz->pantryItems()->create(['name' => 'jajka']);
        $dzis = PriorytetZuzycia::dzis();
        $this->assertTrue(app(ZmienTerminProduktu::class)->handle($produkt, [
            'rodzaj' => PriorytetZuzycia::RODZAJ_ZUZYC_DO,
            'termin_dzien' => substr($dzis, 8, 2),
            'termin_miesiac' => substr($dzis, 5, 2),
            'termin_rok' => substr($dzis, 0, 4),
        ], $dzis));
        // Fabryka tworzy profil po sprawdzeniu relacji i zapamiętuje null.
        // HTTP musi dostać istniejący profil zalogowanej osoby, nie ten cache.
        $widz->unsetRelation('profile');
        $this->assertNotNull($widz->profile);

        // Jedna operacja SQL zamiast 10 020 obiegów fabryki i obserwatorów.
        // Oba zbiory są stabilne podczas każdego przejścia po odnośniku.
        DB::statement(<<<'SQL'
            WITH nowe AS (
                INSERT INTO recipes (id, author_id, title, slug, visibility, status, published_at, prep_minutes, cook_minutes, created_at, updated_at)
                SELECT gen_random_uuid(), ?::uuid, 'Granica 2599 ' || n, 'granica-2599-' || n,
                       'public', 'published', now(), 0, 0, now(), now()
                FROM generate_series(1, 10020) AS n
                RETURNING id
            )
            INSERT INTO recipe_ingredients (id, recipe_id, ingredient_text, position)
            SELECT gen_random_uuid(), id, 'jajka', 0 FROM nowe
            SQL, [(string) $autor->getKey()]);

        // Prywatny przepis także pasuje do produktu, lecz nie może wejść
        // na żadną stronę cudzej listy propozycji.
        $prywatny = Recipe::factory()->create([
            'author_id' => $autor->getKey(), 'title' => 'Prywatna granica 2599',
            'visibility' => 'private', 'prep_minutes' => 0, 'cook_minutes' => 0,
        ]);
        $prywatny->ingredients()->create(['position' => 0, 'ingredient_text' => 'jajka']);

        foreach ([[], ['najpierw' => 'termin']] as $tryb) {
            $pierwsza = $this->actingAs($widz)->get(route('pantry.cook', [...$tryb, 'od' => 9980]))->assertOk();
            $adresOstatniej = $this->adresPokazWiecej((string) $pierwsza->getContent());
            $this->assertSame(route('pantry.cook', ['od' => 10000, ...$tryb]), $adresOstatniej);

            $ostatnia = $this->actingAs($widz)->get($adresOstatniej)->assertOk();
            $this->assertSame(20, substr_count((string) $ostatnia->getContent(), 'data-dopasowanie'));
            $this->assertStringNotContainsString('Pokaż więcej przepisów', (string) $ostatnia->getContent(), 'PAGINACJA_2599_BEZ_PETLI');
            // Dalszych wyników nie ma (#2659): ani pętli, ani zdania, że „mogą
            // być jeszcze inne” przepisy — to byłaby obietnica bez pokrycia.
            $ostatnia->assertDontSee('Mogą być jeszcze inne pasujące przepisy.')
                ->assertSee('To koniec dostępnego przeglądania tej listy.')
                ->assertSee(route('pantry.index'), false)
                ->assertDontSee('Prywatna granica 2599');
        }

        // Teraz jest rzeczywiście dalszy, 10 021. widoczny wynik. Nie można
        // udawać, że wszystkie przepisy pokazano, ani obiecać pętli stron.
        $dodatkowy = Recipe::factory()->create([
            'author_id' => $autor->getKey(), 'title' => 'Dalszy przepis 2599',
            'prep_minutes' => 1, 'cook_minutes' => 0,
        ]);
        $dodatkowy->ingredients()->create(['position' => 0, 'ingredient_text' => 'jajka']);

        foreach ([[], ['najpierw' => 'termin']] as $tryb) {
            $ostatnia = $this->actingAs($widz)->get(route('pantry.cook', [...$tryb, 'od' => 10000]))->assertOk();
            $zaLimitem = $this->actingAs($widz)->get(route('pantry.cook', [...$tryb, 'od' => 10020]))->assertOk();

            foreach ([$ostatnia, $zaLimitem] as $odpowiedz) {
                $this->assertStringNotContainsString('Pokaż więcej przepisów', (string) $odpowiedz->getContent(), 'PAGINACJA_2599_BEZ_PETLI');
                $odpowiedz->assertSee('To koniec dostępnego przeglądania tej listy.')
                    ->assertSee('Mogą być jeszcze inne pasujące przepisy.')
                    ->assertSee(route('pantry.index'), false)
                    ->assertDontSee('Dalszy przepis 2599')
                    ->assertDontSee('Prywatna granica 2599');
            }

            // Bezpośrednie od=10020 nie pokazuje nowego zakresu; musi uczciwie
            // pokazać ostatni dostępny i wyjaśnić ograniczenie.
            $this->assertSame(
                $this->tytulyKart((string) $ostatnia->getContent()),
                $this->tytulyKart((string) $zaLimitem->getContent()),
            );
        }
    }

    private function adresPokazWiecej(string $html): string
    {
        $this->assertSame(1, preg_match('/href="([^"]+)"[^>]*>Pokaż więcej przepisów<\/a>/u', $html, $wynik));

        return html_entity_decode($wynik[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return list<string> */
    private function tytulyKart(string $html): array
    {
        preg_match_all('/Granica 2599 \d+/u', $html, $wyniki);

        return array_values(array_unique($wyniki[0]));
    }
}

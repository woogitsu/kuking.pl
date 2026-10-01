<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prywatność historii wersji (issue #2390). Ukrycie całej wersji (#2270)
 * zamyka adres wersji i porównanie; tu pilnujemy dwóch rzeczy wokół niego:
 * treść ukrytej wersji nie wraca w porównaniu SĄSIEDNIEJ wersji, a ekrany
 * historii nie trafiają do indeksu ani do cache'u współdzielonego.
 */
final class HistoriaWersjiPrywatnoscTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFON = '600 100 200';

    private function przepis(): Recipe
    {
        $przepis = Recipe::factory()->create();
        foreach ([1, 2, 3] as $n) {
            RecipeVersion::create([
                'recipe_id' => $przepis->getKey(),
                'editor_id' => $przepis->author_id,
                'version_number' => $n,
                'change_note' => 'Zmiana '.$n,
                'snapshot' => [
                    'title' => $przepis->title,
                    'summary' => $n === 1 ? 'Od babci, tel. '.self::TELEFON : "Od babci, wersja {$n}.",
                    'ingredients' => [],
                    'steps' => [['position' => 0, 'instruction' => 'Krok '.$n, 'timer_seconds' => null]],
                ],
            ]);
        }

        return $przepis;
    }

    public function test_ukryta_wersja_nie_wraca_w_porownaniu_sasiedniej_wersji(): void
    {
        $przepis = $this->przepis();

        // Kontrola dodatnia: przed ukryciem wersja 1 i porównanie 2 z 1 pokazują telefon.
        $this->get(route('recipes.history.version', [$przepis->slug, 1]))->assertOk()->assertSee(self::TELEFON);
        $this->get(route('recipes.history.changes', [$przepis->slug, 2]))->assertOk()->assertSee(self::TELEFON);

        RecipeVersion::where('recipe_id', $przepis->getKey())->where('version_number', 1)
            ->update(['hidden_at' => now(), 'hidden_by_role' => RecipeVersion::UKRYL_AUTOR]);

        $this->get(route('recipes.history.version', [$przepis->slug, 1]))->assertNotFound();
        $this->get(route('recipes.history.changes', [$przepis->slug, 1]))->assertNotFound();
        $this->get(route('recipes.history.changes', [$przepis->slug, 2]))->assertOk()->assertDontSee(self::TELEFON);
        $this->get(route('recipes.history', $przepis->slug))->assertOk()->assertDontSee(self::TELEFON);
    }

    public function test_ekrany_historii_maja_naglowek_noindex_i_nie_sa_cache_owane_publicznie(): void
    {
        config(['kuking.html_cache.edge_seconds' => 300]);
        $przepis = $this->przepis();

        foreach ([
            route('recipes.history', $przepis->slug),
            route('recipes.history.version', [$przepis->slug, 1]),
            route('recipes.history.changes', [$przepis->slug, 2]),
        ] as $adres) {
            $odpowiedz = $this->get($adres)->assertOk();
            $this->assertStringContainsString('noindex', (string) $odpowiedz->headers->get('X-Robots-Tag'), $adres);
            $this->assertStringNotContainsString('public', (string) $odpowiedz->headers->get('Cache-Control'), $adres);
            $this->assertStringNotContainsString('s-maxage', (string) $odpowiedz->headers->get('Cache-Control'), $adres);
        }
    }
}

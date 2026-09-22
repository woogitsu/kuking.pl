<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresja issue #756: zapis przepisu zmieniał ID kroków i unieważniał
 * postęp trybu gotowania.
 *
 * `PublishRecipe::syncSteps()` kasowała WSZYSTKIE wiersze `recipe_steps`
 * i zakładała je od nowa przy KAŻDYM zapisie — nawet takim, który tylko
 * poprawiał literówkę w jednym kroku. `HasUuids` losuje nowy identyfikator
 * przy każdym `create()`, a `CookingModeController` trzyma „zrobione kroki”
 * w sesji jako listę identyfikatorów (`RecipeStep::getKey()`). Nowy komplet
 * identyfikatorów po zapisie znaczył, że sesja wskazywała na wiersze, które
 * już nie istnieją — postęp gotowania znikał po cichu, mimo że treść kroków
 * się nie zmieniła. AGENTS.md zakazuje tego wprost: „poprawne dane nigdy
 * nie znikają”.
 */
class ZapisPrzepisuNieGubiPostepuGotowaniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_edycja_przepisu_zachowuje_id_niezmienionych_krokow(): void
    {
        $autorka = $this->user('kucharka756');

        $przepis = app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => 'Zupa pomidorowa'],
            steps: [
                ['instruction' => 'Podsmaż warzywa.'],
                ['instruction' => 'Zalej bulionem i gotuj 20 minut.'],
            ],
            publish: true,
        );

        $idPrzed = $przepis->steps()->orderBy('position')->pluck('id', 'position');

        // Drugi zapis: te same dwa kroki, po jednym z nich dopisany trzeci —
        // dokładnie tak, jak wysyła je formularz edycji, z `id` wracającym
        // z ukrytego pola (patrz `resources/views/pages/recipes/szczegoly.blade.php`).
        $poEdycji = app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => 'Zupa pomidorowa'],
            steps: [
                ['id' => $idPrzed[0], 'instruction' => 'Podsmaż warzywa na maśle.'],
                ['id' => $idPrzed[1], 'instruction' => 'Zalej bulionem i gotuj 20 minut.'],
                ['instruction' => 'Zblenduj na gładko.'],
            ],
            publish: true,
            existing: $przepis,
        );

        $idPo = $poEdycji->steps()->orderBy('position')->pluck('id', 'position');

        $this->assertSame($idPrzed[0], $idPo[0], 'Krok 1 miał zachować swoje id po edycji treści.');
        $this->assertSame($idPrzed[1], $idPo[1], 'Krok 2 miał zachować swoje id — jego treść w ogóle się nie zmieniła.');
        $this->assertCount(3, $idPo, 'Nowy, trzeci krok miał dojść, nie zastąpić poprzednich.');
    }

    public function test_usuniety_krok_znika_a_pozostale_zachowuja_id(): void
    {
        $autorka = $this->user('kucharka756b');

        $przepis = app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => 'Naleśniki'],
            steps: [
                ['instruction' => 'Wymieszaj ciasto.'],
                ['instruction' => 'Usmaż naleśniki.'],
            ],
            publish: true,
        );

        $idPrzed = $przepis->steps()->orderBy('position')->pluck('id', 'position');

        app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => 'Naleśniki'],
            steps: [
                ['id' => $idPrzed[1], 'instruction' => 'Usmaż naleśniki.'],
            ],
            publish: true,
            existing: $przepis,
        );

        $this->assertNull(RecipeStep::find($idPrzed[0]), 'Usunięty krok nie powinien zostać w bazie.');
        $this->assertNotNull(RecipeStep::find($idPrzed[1]), 'Zachowany krok miał przetrwać pod tym samym id.');
    }

    /**
     * Koniec do końca: postęp zaznaczony w trybie gotowania przeżywa zapis
     * przepisu, o ile treść zaznaczonego kroku się nie zmieniła — dokładnie
     * scenariusz z issue #756 („autor poprawia literówkę w innym kroku,
     * a ktoś w trakcie gotowania traci odhaczone kroki”).
     */
    public function test_postep_gotowania_przezywa_zapis_przepisu(): void
    {
        $autorka = $this->user('kucharka756c');
        $gotujacy = $this->user('gotujacy756c');

        $przepis = app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => 'Kotlety'],
            steps: [
                ['instruction' => 'Rozbij mięso.'],
                ['instruction' => 'Obtocz w bułce tartej.'],
            ],
            publish: true,
        );

        $krok1 = $przepis->steps()->orderBy('position')->first();

        $this->actingAs($gotujacy)
            ->post(route('cooking.zaznacz', $przepis->slug), ['krok' => 1, 'zrobiono' => 1])
            ->assertRedirect();

        $this->actingAs($gotujacy)
            ->get(route('cooking.show', [$przepis->slug, 'krok' => 1]))
            ->assertSee('Zrobione ✓');

        // Autorka poprawia literówkę w DRUGIM kroku — pierwszy, odhaczony
        // przez gotującego, wraca do zapisu bez zmian, z tym samym `id`.
        app(PublishRecipe::class)->handle(
            author: $autorka,
            attributes: ['title' => 'Kotlety'],
            steps: [
                ['id' => $krok1->getKey(), 'instruction' => 'Rozbij mięso.'],
                ['id' => (string) $przepis->steps()->orderBy('position')->skip(1)->first()->getKey(), 'instruction' => 'Obtocz w bułce tartej i panierce.'],
            ],
            publish: true,
            existing: $przepis,
        );

        $this->actingAs($gotujacy)
            ->get(route('cooking.show', [$przepis->slug, 'krok' => 1]))
            ->assertSee('Zrobione ✓', false);
    }
}

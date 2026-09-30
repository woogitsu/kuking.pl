<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audyt UX 50+: autor wersji ukrytej przez moderację dostawał w historii
 * zmian „napisz do nas” zamiast drogi odwołania (DSA art. 20), choć ukrycie
 * jest decyzją moderacyjną z przyciskiem odwołania w powiadomieniu.
 * Test czyta wyrenderowany HTML historii.
 */
final class HistoriaUkrytaWersjaDajeAutorowiDrogeOdwolaniaTest extends TestCase
{
    use RefreshDatabase;

    private function przepisZUkrytaWersjaPrzezModeracje(): Recipe
    {
        $przepis = Recipe::factory()->create(['title' => 'Bigos myśliwski']);
        for ($n = 1; $n <= 3; $n++) {
            RecipeVersion::create([
                'recipe_id' => $przepis->getKey(),
                'editor_id' => $przepis->author_id,
                'version_number' => $n,
                'change_note' => 'Wersja '.$n,
                'snapshot' => ['title' => $przepis->title, 'ingredients' => [], 'steps' => []],
            ]);
        }

        $this->actingAs($this->moderator())
            ->post(route('recipes.history.hide.store', [$przepis->slug, 1]), [
                'reason_code' => 'cudze-dane-osobowe',
                'user_message' => 'W opisie tej wersji jest cudzy numer telefonu.',
                'note' => 'Notatka.',
            ])
            ->assertSessionHasNoErrors();
        auth()->logout();

        return $przepis;
    }

    public function test_autor_dostaje_przycisk_odwolania_zamiast_napisz_do_nas(): void
    {
        $przepis = $this->przepisZUkrytaWersjaPrzezModeracje();
        $decyzja = ModerationAction::sole();

        foreach ([route('recipes.history', $przepis->slug), route('recipes.history.version', [$przepis->slug, 1])] as $adres) {
            $html = $this->actingAs($przepis->author)->get($adres)->assertOk()->getContent();

            $this->assertStringContainsString('odwołać się od tej decyzji', $html);
            $this->assertStringNotContainsString('napisz do nas', $html);
            $this->assertSame(1, preg_match('~<a class="btn btn-secondary" href="'.preg_quote(route('appeals.show', $decyzja), '~').'">\s*Odwołaj się\s*</a>~u', $html), 'Brak przycisku „Odwołaj się” do appeals.show.');
        }
    }

    public function test_po_zlozeniu_odwolania_przycisk_prowadzi_do_sprawy(): void
    {
        $przepis = $this->przepisZUkrytaWersjaPrzezModeracje();
        $decyzja = ModerationAction::sole();
        $this->actingAs($przepis->author)
            ->post(route('appeals.store', $decyzja), ['body' => 'To numer do mojej własnej pracowni, podałam go świadomie.'])
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Appeal::count());

        $html = $this->actingAs($przepis->author)->get(route('recipes.history', $przepis->slug))->getContent();

        $this->assertStringContainsString('Zobacz swoje odwołanie', $html);
        $this->assertSame(0, preg_match('~>\s*Odwołaj się\s*<~u', $html), 'Po złożeniu odwołania nie ma już przycisku składania.');
    }

    public function test_moderator_i_wersja_ukryta_przez_autora_nie_dostaja_przycisku_odwolania(): void
    {
        $przepis = $this->przepisZUkrytaWersjaPrzezModeracje();

        $html = $this->actingAs($this->moderator())->get(route('recipes.history', $przepis->slug))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('appeals.show', ModerationAction::sole()), $html, 'Moderator nie odwołuje się od własnej decyzji.');

        // Wersja ukryta przez AUTORA: to nie decyzja wobec niego, nie ma od czego się odwoływać.
        $autorska = Recipe::factory()->create();
        foreach ([1, 2] as $n) {
            RecipeVersion::create([
                'recipe_id' => $autorska->getKey(), 'editor_id' => $autorska->author_id, 'version_number' => $n,
                'change_note' => null, 'snapshot' => ['title' => $autorska->title, 'ingredients' => [], 'steps' => []],
            ]);
        }
        $this->actingAs($autorska->author)
            ->post(route('recipes.history.hide.store', [$autorska->slug, 1]))
            ->assertSessionHasNoErrors();
        $html = $this->actingAs($autorska->author)->get(route('recipes.history', $autorska->slug))->assertOk()->getContent();
        $this->assertStringContainsString('Ta wersja jest ukryta', $html);
        $this->assertStringNotContainsString('appeals', $html);
    }
}

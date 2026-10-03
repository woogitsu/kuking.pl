<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookingProgress;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cofnięcie „Zrobione ✓” tekstowym `zrobiono=0` (issue #2242).
 *
 * Zgłoszenie twierdziło, że `(bool) '0'` daje w PHP `true`, więc przycisk
 * cofnięcia ponownie oznacza krok. Na main tak NIE jest — `(bool) '0'` to
 * `false` — ale kontroler miał dwie różne drogi odczytu tej samej wartości
 * (`(bool)` przy koncie, gołe `if` przy sesji) i żadnego testu, który
 * wysyła DOKŁADNIE to, co wysyła widok. Te testy biorą wartość pola
 * `zrobiono` z wyrenderowanego formularza i wysyłają ją jako tekst, na
 * obu ścieżkach: sesji tego urządzenia i zapamiętywania na koncie (#2016).
 */
class CofniecieKrokuWartosciaZeroTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Recipe, 1: list<RecipeStep>} */
    private function przepis(User $autor): array
    {
        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
        ]);
        $kroki = [];
        foreach ([0, 1] as $i) {
            $kroki[] = RecipeStep::create([
                'recipe_id' => $recipe->getKey(),
                'position' => $i,
                'instruction' => 'Krok numer '.($i + 1).'.',
            ]);
        }

        return [$recipe, $kroki];
    }

    /** Wartość ukrytego pola `zrobiono` z formularza kroku, tak jak ją wyśle przeglądarka. */
    private function wartoscZFormularza(User $osoba, Recipe $recipe): string
    {
        $html = $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug).'?krok=1')
            ->assertOk()
            ->getContent();

        $this->assertSame(
            1,
            preg_match('/<input type="hidden" name="zrobiono" value="([^"]*)">/', $html, $trafienie),
            'Widok trybu gotowania nie ma już ukrytego pola `zrobiono` — popraw test razem z widokiem.',
        );

        return $trafienie[1];
    }

    private function wyslij(User $osoba, Recipe $recipe, RecipeStep $krok, string $zrobiono)
    {
        return $this->actingAs($osoba)->post(route('cooking.zaznacz', $recipe->slug), [
            'krok' => 1,
            'krok_id' => $krok->getKey(),
            'zrobiono' => $zrobiono,
            'id_postepu' => CookingProgress::query()->where('user_id', $osoba->getKey())->where('recipe_id', $recipe->getKey())->value('id'),
        ]);
    }

    private function kluczSesji(Recipe $recipe): string
    {
        return 'gotowanie.'.$recipe->getKey().'.zrobione';
    }

    public function test_tekstowe_zero_z_formularza_cofa_krok_w_sesji(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis($osoba);

        $this->assertSame('1', $this->wartoscZFormularza($osoba, $recipe));
        $this->wyslij($osoba, $recipe, $kroki[0], '1')->assertRedirect();
        $this->assertSame([$kroki[0]->getKey()], session($this->kluczSesji($recipe)));

        // Przycisk „Zrobione ✓ — kliknij, żeby cofnąć” wysyła tekst „0”.
        $cofniecie = $this->wartoscZFormularza($osoba, $recipe);
        $this->assertSame('0', $cofniecie);
        $this->wyslij($osoba, $recipe, $kroki[0], $cofniecie)->assertRedirect();

        $this->assertSame([], session($this->kluczSesji($recipe)), 'zrobiono=0 miało COFNĄĆ krok w sesji, a krok nadal jest oznaczony.');
        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug).'?krok=1')
            ->assertSee('Oznacz krok jako zrobiony')
            ->assertDontSee('Zrobione ✓');
    }

    public function test_tekstowe_zero_z_formularza_cofa_krok_zapamietany_na_koncie(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis($osoba);
        $this->actingAs($osoba)->post(route('cooking.sync.wlacz', $recipe->slug), ['krok' => 1])->assertRedirect();

        $this->wyslij($osoba, $recipe, $kroki[0], '1')->assertRedirect();
        $wiersz = CookingProgress::query()->where('user_id', $osoba->getKey())->where('recipe_id', $recipe->getKey())->firstOrFail();
        $this->assertSame([$kroki[0]->getKey()], $wiersz->done_step_ids);

        $cofniecie = $this->wartoscZFormularza($osoba, $recipe);
        $this->assertSame('0', $cofniecie);
        $this->wyslij($osoba, $recipe, $kroki[0], $cofniecie)->assertRedirect();

        $this->assertSame([], $wiersz->fresh()->done_step_ids, 'zrobiono=0 miało COFNĄĆ krok zapamiętany na koncie, a krok nadal jest oznaczony.');

        // Drugie urządzenie (pusta sesja) też widzi krok jako niezrobiony.
        $this->flushSession();
        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug).'?krok=1')
            ->assertSee('Oznacz krok jako zrobiony')
            ->assertDontSee('Zrobione ✓');
    }

    public function test_tekstowa_jedynka_oznacza_krok_i_nie_rusza_drugiego(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis($osoba);

        $this->wyslij($osoba, $recipe, $kroki[1], '1')->assertRedirect();
        $this->wyslij($osoba, $recipe, $kroki[0], '1')->assertRedirect();
        $this->wyslij($osoba, $recipe, $kroki[0], '0')->assertRedirect();

        $this->assertSame([$kroki[1]->getKey()], session($this->kluczSesji($recipe)));
    }

    public function test_wartosc_spoza_zera_i_jedynki_niczego_nie_zmienia(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis($osoba);
        $this->wyslij($osoba, $recipe, $kroki[0], '1');

        // „false” jako tekst to w PHP `true` — walidacja `boolean` go odrzuca,
        // więc nie może ani cofnąć, ani oznaczyć niczego po cichu.
        $this->wyslij($osoba, $recipe, $kroki[0], 'false')->assertRedirect()->assertSessionHasErrors('zrobiono');

        $this->assertSame([$kroki[0]->getKey()], session($this->kluczSesji($recipe)));
    }
}

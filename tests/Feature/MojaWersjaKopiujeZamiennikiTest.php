<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\MojaWersja;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #2238 (audyt BP-02): „Zrób swoją wersję" gubiła zamienniki składników
 * od autora (D-284) — kolumna `substitutes` weszła z innej gałęzi tego
 * samego dnia co kopiowanie wersji. Druga połowa poprawki: zmiana samego
 * zamiennika liczy się jako różnica (`MojaWersja::odcisk()`).
 */
class MojaWersjaKopiujeZamiennikiTest extends TestCase
{
    use RefreshDatabase;

    private function oryginal(User $autor): Recipe
    {
        return app(PublishRecipe::class)->handle(
            $autor,
            ['title' => 'Ciasto drożdżowe babci', 'visibility' => 'public'],
            [['text' => '100 g masła', 'substitutes' => 'margaryna albo olej kokosowy'], ['text' => '500 g mąki']],
            [['instruction' => 'Wymieszaj wszystko i odstaw w ciepłe miejsce na godzinę.']],
            publish: true,
        );
    }

    private function zrobWersje(User $kto, Recipe $oryginal): Recipe
    {
        $this->actingAs($kto)->post(route('recipes.fork', $oryginal->slug))->assertRedirect();

        return Recipe::query()->where('author_id', $kto->getKey())->where('forked_from_id', $oryginal->getKey())->sole();
    }

    public function test_moja_wersja_zachowuje_zamienniki_skladnikow_od_autora(): void
    {
        $oryginal = $this->oryginal($this->user('basia'));
        $this->assertSame('margaryna albo olej kokosowy', $oryginal->ingredients()->orderBy('position')->first()->substitutes);

        $wersja = $this->zrobWersje($this->user('jan'), $oryginal);
        $skladniki = $wersja->ingredients()->orderBy('position')->get();

        $this->assertSame(
            'margaryna albo olej kokosowy',
            $skladniki[0]->substitutes,
            '„Zrób swoją wersję” zgubiła zamiennik składnika wpisany przez autora oryginału (D-284).',
        );
        // Składnik bez zamiennika zostaje bez zamiennika — nic nie jest dopisywane.
        $this->assertNull($skladniki[1]->substitutes);
    }

    public function test_wersja_rozniaca_sie_tylko_zamiennikiem_nie_jest_odrzucana_jako_ten_sam_przepis(): void
    {
        $oryginal = $this->oryginal($this->user('basia'));
        $jan = $this->user('jan');
        $wersja = $this->zrobWersje($jan, $oryginal);

        $formularz = fn (string $zamiennik): array => [
            'content_revision' => $wersja->fresh()->content_revision,
            'title' => $wersja->title,
            'visibility' => 'public',
            'ingredients' => [['text' => '100 g masła', 'substitutes' => $zamiennik], ['text' => '500 g mąki']],
            'steps' => [['instruction' => 'Wymieszaj wszystko i odstaw w ciepłe miejsce na godzinę.']],
        ];

        // Kontrola: ten sam zamiennik to dalej ten sam przepis.
        $this->actingAs($jan)->put(route('recipes.update', $wersja), $formularz('margaryna albo olej kokosowy'))
            ->assertSessionHasErrors(['title' => MojaWersja::KOMUNIKAT_BEZ_ZMIAN]);

        // Inny zamiennik to inna wersja.
        $this->actingAs($jan)->put(route('recipes.update', $wersja), $formularz('smalec'))
            ->assertSessionHasNoErrors();

        $this->assertTrue($wersja->fresh()->isPublished());
        $this->assertSame('smalec', $wersja->fresh()->ingredients()->orderBy('position')->first()->substitutes);
    }
}

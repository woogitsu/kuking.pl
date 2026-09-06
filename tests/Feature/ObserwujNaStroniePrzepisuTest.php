<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Obserwuj" przy autorze na stronie przepisu (UI kit v2, ekran 02).
 *
 * DLACZEGO TO NIE JEST OZDOBA PRZENIESIONA Z MAKIETY
 * Strona przepisu jest najczęstszym wejściem z wyszukiwarki, a obserwowanie
 * autora to jedyny powód, dla którego ktoś tu wróci. Do tej pory, żeby zacząć
 * obserwować, trzeba było najpierw wejść na profil — czyli opuścić przepis,
 * po który się przyszło.
 */
class ObserwujNaStroniePrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(): Recipe
    {
        return Recipe::factory()->create(['author_id' => $this->user('kucharka')->getKey()]);
    }

    public function test_obcy_widzi_przycisk_obserwuj(): void
    {
        $przepis = $this->przepis();

        $this->actingAs($this->user('widz'))
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('Obserwuj', escape: false)
            ->assertSee(route('social.follow', $przepis->author->profile->username), escape: false);
    }

    public function test_kto_juz_obserwuje_widzi_odwrotna_akcje(): void
    {
        // Przycisk „Obserwuj" u kogoś, kto już obserwuje, to przycisk
        // kłamiący o stanie — i jedyny sposób, żeby się o tym przekonać,
        // to go kliknąć.
        $przepis = $this->przepis();
        $widz = $this->user('widz');
        $widz->following()->attach($przepis->author_id);

        $this->actingAs($widz)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('Przestań obserwować', escape: false);
    }

    public function test_autor_nie_obserwuje_sam_siebie(): void
    {
        $przepis = $this->przepis();

        $html = (string) $this->actingAs($przepis->author)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(
            route('social.follow', $przepis->author->profile->username),
            $html,
        );
    }

    public function test_gosc_nie_dostaje_przycisku_ktory_i_tak_go_odeslie(): void
    {
        // Gość nie ma czym obserwować. Przycisk, który prowadzi wyłącznie
        // do logowania, wygląda jak działający i nie jest — a to jest gorsze
        // niż jego brak (docs/UX_50_PLUS.md).
        $przepis = $this->przepis();

        $html = (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();

        $this->assertStringNotContainsString(
            route('social.follow', $przepis->author->profile->username),
            $html,
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Notification;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Ugotowałem” — najważniejsze zdarzenie w Kuking.
 */
class CookedEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_ta_sama_osoba_moze_ugotowac_ten_sam_przepis_wiele_razy(): void
    {
        $autor = $this->user('autor');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $action = app(RecordCookedEvent::class);

        $action->handle($kucharz, $recipe);
        $action->handle($kucharz, $recipe);
        $action->handle($kucharz, $recipe);

        // Brak unique (user_id, recipe_id) to decyzja produktowa, nie przeoczenie.
        $this->assertSame(3, $recipe->cookedEvents()->count());
    }

    public function test_autor_przepisu_dostaje_powiadomienie(): void
    {
        $autor = $this->user('autor');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        app(RecordCookedEvent::class)->handle($kucharz, $recipe, note: 'Wyszło pięknie.');

        $notification = Notification::where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->first();

        $this->assertNotNull($notification, 'Powiadomienie autora jest obowiązkową częścią „Ugotowałem”.');
        $this->assertSame($kucharz->getKey(), $notification->actor_id);
        $this->assertSame($recipe->title, $notification->data['recipe_title']);
    }

    public function test_wykonanie_nie_wymaga_zadnego_pola(): void
    {
        $autor = $this->user('autor');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $response = $this->actingAs($kucharz)->post(route('cooked.store', $recipe->slug));

        $response->assertRedirect();
        $this->assertSame(1, $recipe->cookedEvents()->count());
    }

    public function test_nie_da_sie_dodac_wykonania_do_szkicu(): void
    {
        $autor = $this->user('autor');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->draft()->create(['author_id' => $autor->getKey()]);

        $this->expectException(\RuntimeException::class);

        app(RecordCookedEvent::class)->handle($kucharz, $recipe);
    }

    public function test_autor_nie_dostaje_powiadomienia_o_wlasnym_wykonaniu(): void
    {
        $autor = $this->user('autor');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        app(RecordCookedEvent::class)->handle($autor, $recipe);

        $this->assertSame(0, Notification::where('user_id', $autor->getKey())->count());
    }

    public function test_blokada_uniemozliwia_dodanie_wykonania(): void
    {
        $autor = $this->user('autor');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        app(BlockUser::class)->handle($autor, $kucharz);

        $this->expectException(\RuntimeException::class);

        app(RecordCookedEvent::class)->handle($kucharz->fresh(), $recipe->fresh());
    }
}

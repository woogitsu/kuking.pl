<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Recipe;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Usunięcie wykonania, gdy przepis stał się niedostępny (issue #766).
 *
 * Scenariusz:
 * 1. Autor A publikuje publiczny przepis.
 * 2. Kucharz B zapisuje własne wykonanie („Ugotowałem").
 * 3. Autor A zmienia widoczność przepisu na `private` (albo moderacja ukrywa przepis `hidden`).
 * 4. Kucharz B usuwa swoje wykonanie.
 *
 * Poprzednio: kontroler bezwarunkowo przekierowywał do `recipes.show` po slugu przepisu,
 * a `RecipePolicy::view` odrzucała kucharza z kodem 403 Forbidden — człowiek po poprawnym
 * usunięciu własnej treści tracił drogę powrotu.
 */
class UsuniecieWykonaniaNiedostepnyPrzepisTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuniecie_wykonania_gdy_przepis_stal_sie_prywatny_odsyla_bezpiecznie(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            note: 'Moje pyszne ciasto',
        );

        // Autor zmienia widoczność na prywatną
        $recipe->update(['visibility' => 'private']);

        // Kucharz usuwa swoje wykonanie
        $response = $this->actingAs($kucharz)
            ->followingRedirects()
            ->delete(route('cooked.destroy', $event));

        $response->assertOk();
        $response->assertSee('Wykonanie usunięte.');
        $this->assertNull(CookedEvent::find($event->getKey()));
    }

    public function test_usuniecie_wykonania_gdy_przepis_zostal_ukryty_odsyla_bezpiecznie(): void
    {
        $autor = $this->user('autorka2');
        $kucharz = $this->user('kucharz2');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            note: 'Pyszny obiad',
        );

        // Moderacja ukrywa przepis
        $recipe->update(['status' => Recipe::STATUS_HIDDEN]);

        $response = $this->actingAs($kucharz)
            ->followingRedirects()
            ->delete(route('cooked.destroy', $event));

        $response->assertOk();
        $response->assertSee('Wykonanie usunięte.');
        $this->assertNull(CookedEvent::find($event->getKey()));
    }

    public function test_usuniecie_wykonania_przy_dostepnym_przepisie_odsyla_do_przepisu(): void
    {
        $autor = $this->user('autorka3');
        $kucharz = $this->user('kucharz3');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            note: 'Udany sernik',
        );

        $response = $this->actingAs($kucharz)
            ->delete(route('cooked.destroy', $event));

        $response->assertRedirect(route('recipes.show', $recipe->slug));
        $response->assertSessionHas('status', 'Wykonanie usunięte.');
        $this->assertNull(CookedEvent::find($event->getKey()));
    }

    public function test_moderator_zdejmuje_wykonanie_przy_niedostepnym_przepisie_tylko_z_panelu(): void
    {
        $autor = $this->user('autorka4');
        $kucharz = $this->user('kucharz4');
        $moderator = $this->moderator();

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            note: 'Wykonanie prywatnego',
        );

        $recipe->update(['visibility' => 'private']);

        // Issue #932: zwykły DELETE należy do autora. Moderator dostaje 403
        // i zdejmuje wykonanie decyzją w panelu — z wpisem w rejestrze.
        $this->actingAs($moderator)
            ->delete(route('cooked.destroy', $event))
            ->assertForbidden();
        $this->assertNotNull(CookedEvent::find($event->getKey()));

        $report = Report::create([
            'reporter_id' => $this->user('zglaszajacy')->getKey(),
            'target_type' => 'cooked_event',
            'target_id' => $event->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_REMOVE,
                'reason_code' => 'spam_link',
                'user_message' => 'Usunęliśmy to wykonanie, bo zawierało link reklamowy.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNull(CookedEvent::find($event->getKey()));
        $this->assertDatabaseHas('moderation_actions', [
            'report_id' => $report->getKey(),
            'action' => ModerationAction::ACTION_REMOVE,
        ]);
    }

    public function test_obcy_nie_moze_usunac_cudzego_wykonania(): void
    {
        $autor = $this->user('autorka5');
        $kucharz = $this->user('kucharz5');
        $obcy = $this->user('obcy5');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
        );

        $this->actingAs($obcy)
            ->delete(route('cooked.destroy', $event))
            ->assertForbidden();

        $this->assertNotNull(CookedEvent::find($event->getKey()));
    }
}

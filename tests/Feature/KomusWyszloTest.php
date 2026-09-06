<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\Comment;
use App\Models\Notification;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ekran „Komuś wyszło" (issue #17) — celebracja cudzego wykonania.
 *
 * Kryteria akceptacji z issue:
 *  - widzi wyłącznie autor przepisu (test widoczności — patrz
 *    tests/Feature/Visibility/KomusWyszloWidocznoscTest.php),
 *  - „Podziękuj" tworzy komentarz pod wykonaniem i powiadamia kucharza,
 *  - po obejrzeniu powiadomienie jest oznaczone jako przeczytane,
 *  - ekran pokazuje się raz na wykonanie.
 */
class KomusWyszloTest extends TestCase
{
    use RefreshDatabase;

    public function test_autor_widzi_ekran_celebracji_z_notatka_i_zdjeciem(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz', ['display_name' => 'Halina']);
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey(), 'title' => 'Rosół']);

        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe, note: 'Wyszło pięknie, dziękuję!');

        $this->actingAs($autor)
            ->get(route('cooked.celebrate', $event))
            ->assertOk()
            ->assertSee('Halina', false)
            ->assertSee('Rosół', false)
            ->assertSee('Wyszło pięknie, dziękuję!', false)
            ->assertSee('Podziękuj', false)
            ->assertSee('Zobacz cały wpis', false);
    }

    public function test_obejrzenie_ekranu_oznacza_powiadomienie_jako_przeczytane(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $notification = Notification::where('user_id', $autor->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->firstOrFail();

        $this->assertTrue($notification->isUnread(), 'Powiadomienie musi startować jako nieprzeczytane.');

        $this->actingAs($autor)->get(route('cooked.celebrate', $event))->assertOk();

        $this->assertFalse($notification->fresh()->isUnread(), 'Obejrzenie ekranu ma oznaczyć powiadomienie jako przeczytane.');
    }

    public function test_ekran_pokazuje_sie_raz_na_wykonanie(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $this->actingAs($autor)->get(route('cooked.celebrate', $event))->assertOk();

        // Drugie wejście (np. kliknięcie tego samego linku z e-maila, albo
        // powrót do powiadomień i ponowne kliknięcie „Zobacz") NIE pokazuje
        // celebracji drugi raz — trafia od razu na zwykły wpis.
        $this->actingAs($autor)
            ->get(route('cooked.celebrate', $event))
            ->assertRedirect(route('cooked.show', $event));
    }

    public function test_podziekuj_tworzy_komentarz_i_powiadamia_kucharza(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $response = $this->actingAs($autor)->post(route('cooked.thank', $event), [
            'body' => 'Dziękuję, że ugotowałeś/aś mój przepis! Cieszę się, że wyszło.',
        ]);

        $response->assertRedirect(route('cooked.show', $event));

        $comment = Comment::where('cooked_event_id', $event->getKey())->first();
        $this->assertNotNull($comment, '„Podziękuj" ma zostawić komentarz pod wykonaniem.');
        $this->assertSame($autor->getKey(), $comment->author_id);

        $notification = Notification::where('user_id', $kucharz->getKey())
            ->where('type', Notification::TYPE_COMMENT)
            ->first();
        $this->assertNotNull($notification, 'Kucharz ma dostać powiadomienie o podziękowaniu.');
        $this->assertSame($autor->getKey(), $notification->actor_id);
    }

    public function test_podziekuj_dziala_z_wlasnym_tekstem_zamiast_domyslnego(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $this->actingAs($autor)->post(route('cooked.thank', $event), [
            'body' => 'Zrobię to jeszcze raz w niedzielę dla całej rodziny!',
        ])->assertRedirect(route('cooked.show', $event));

        $this->assertSame(
            'Zrobię to jeszcze raz w niedzielę dla całej rodziny!',
            Comment::where('cooked_event_id', $event->getKey())->first()?->body,
        );
    }

    public function test_gosc_jest_przekierowany_do_logowania(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharz');
        $recipe = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $event = app(RecordCookedEvent::class)->handle($kucharz, $recipe);

        $this->get(route('cooked.celebrate', $event))->assertRedirect(route('login'));
        $this->post(route('cooked.thank', $event), ['body' => 'x'])->assertRedirect(route('login'));
    }
}

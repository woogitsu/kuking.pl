<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wykonanie („Ugotowałem") przepisu, który autor już usunął (audyt A23).
 *
 * `Recipe` ma soft delete, `CookedEvent` nie. Po `$recipe->delete()` relacja
 * `CookedEvent::recipe()` zwraca `null` — a trzy miejsca czytały `->recipe->slug`
 * i `->recipe->title` bezwarunkowo. Efekt: HTTP 500 na własnym profilu,
 * na stronie wykonania i — najgorsze — przy PRÓBIE USUNIĘCIA tego wykonania.
 *
 * To ostatnie jest sednem błędu: człowiek zostawał z trwale zepsutą zakładką
 * „Ugotowane" i nie miał żadnego sposobu, żeby po sobie posprzątać.
 *
 * Testy pilnują też DECYZJI produktowej, a nie tylko braku wyjątku:
 *
 *  1. nie sięgamy po `withTrashed()` — tytuł przepisu, który autor świadomie
 *     usunął, nie ma prawa wrócić na ekran przez cudze wykonanie;
 *  2. wykonanie osierocone widzi wyłącznie jego autor (i moderator) — jego
 *     widoczność szła za przepisem, a przepisu już nie ma, więc nie ma na
 *     czym oprzeć pokazania go obcym;
 *  3. własne zdjęcie i własna notatka NIE znikają właścicielowi z oczu
 *     (AGENTS.md, UX 50+: „poprawne dane nigdy nie znikają").
 */
class UsunietyPrzepisAWykonanieTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: CookedEvent, 3: Recipe} */
    private function wykonanieOsierocone(): array
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharka');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Sernik na zimno Krystyny',
        ]);

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            note: 'Wyszlo wysmienicie, robie znowu w niedziele.',
        );

        $recipe->delete();

        return [$autor, $kucharz, $event->fresh(), $recipe];
    }

    public function test_profil_wlasciciela_otwiera_sie_po_usunieciu_przepisu(): void
    {
        [, $kucharz] = $this->wykonanieOsierocone();

        $odpowiedz = $this->actingAs($kucharz)
            ->get(route('profile.show', $kucharz->profile->username).'?zakladka=ugotowane')
            ->assertOk();

        // Zdjęcie i notatka zostają — to jest treść kucharza, nie autora przepisu.
        $odpowiedz->assertSee('Wyszlo wysmienicie', false);
    }

    public function test_profil_nie_ujawnia_tytulu_usunietego_przepisu(): void
    {
        [, $kucharz, , $recipe] = $this->wykonanieOsierocone();

        // Pokusa „przecież withTrashed() naprawia 500" kończy się tutaj:
        // naprawiłaby wyjątek i jednocześnie przywróciła na ekran treść,
        // którą autor świadomie skasował.
        $this->actingAs($kucharz)
            ->get(route('profile.show', $kucharz->profile->username).'?zakladka=ugotowane')
            ->assertOk()
            ->assertDontSee($recipe->title);
    }

    public function test_strona_wykonania_otwiera_sie_wlascicielowi(): void
    {
        [, $kucharz, $event, $recipe] = $this->wykonanieOsierocone();

        $this->actingAs($kucharz)
            ->get(route('cooked.show', $event))
            ->assertOk()
            ->assertDontSee($recipe->title);
    }

    public function test_obcy_nie_oglada_osieroconego_wykonania(): void
    {
        [, , $event] = $this->wykonanieOsierocone();

        $obcy = $this->user('obca');

        // Widoczność wykonania szła za widocznością przepisu. Bez przepisu nie
        // ma podstawy, żeby pokazać je komukolwiek poza autorem wykonania.
        $this->actingAs($obcy)
            ->get(route('cooked.show', $event))
            ->assertForbidden();
    }

    public function test_wlasciciel_moze_usunac_wykonanie_osierocone(): void
    {
        [, $kucharz, $event] = $this->wykonanieOsierocone();

        // Najważniejszy przypadek z całego A23: bez tego człowiek ma trwale
        // zepsutą zakładkę i ZERO sposobów, żeby ją posprzątać.
        $this->actingAs($kucharz)
            ->delete(route('cooked.destroy', $event))
            ->assertRedirect();

        $this->assertNull(CookedEvent::find($event->getKey()));
    }

    public function test_moderator_zdejmuje_wykonanie_osierocone_tylko_z_panelu(): void
    {
        [, , $event] = $this->wykonanieOsierocone();

        $moderator = $this->moderator();

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
}

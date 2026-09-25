<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Social\Actions\BlockUser;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ISSUE #1385 — powiadomienie o komentarzu pod własnym wykonaniem nie znika
 * po ukryciu przepisu.
 *
 * `CookedEventPolicy::view()` wpuszcza kucharza na własne wykonanie także
 * pod przepisem ukrytym przez moderację (`UgotowalemWlasneWykonanieNieZnikaTest`).
 * `Notification::scopeVisibleTo()` odtwarza tę regułę w SQL, ale gałąź
 * właściciela łapała tylko `recipe_id IS NULL`. Skutek: karta wykonania
 * z komentarzem otwierała się, a powiadomienie o tym komentarzu znikało
 * z listy i z licznika. Lista i Policy mają odpowiadać tak samo.
 */
class PowiadomienieOKomentarzuPodWlasnymWykonaniemTest extends TestCase
{
    use RefreshDatabase;

    public function test_po_ukryciu_przepisu_kucharz_dalej_widzi_powiadomienie_o_komentarzu(): void
    {
        [, $kucharz, $komentujaca, $przepis, $wykonanie] = $this->scenariusz();

        app(PublishComment::class)->handle($komentujaca, $wykonanie, 'Pieknie Ci wyszlo, gratuluje!');

        // KONTROLA DODATNIA: przed ukryciem powiadomienie jest na liście i w liczniku.
        $this->assertSame(1, $this->widoczneKomentarze($kucharz));
        $this->assertSame(1, $kucharz->refresh()->unreadNotificationsCount());

        $przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        // Karta wykonania z komentarzem dalej się otwiera (Policy)…
        $this->actingAs($kucharz)
            ->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertSee('Pieknie Ci wyszlo, gratuluje!');

        // …więc powiadomienie o tym komentarzu też zostaje — lista i licznik.
        $this->assertSame(1, $this->widoczneKomentarze($kucharz));
        $this->assertSame(1, $kucharz->refresh()->unreadNotificationsCount());
        $this->actingAs($kucharz)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Pieknie Ci wyszlo', false);
    }

    public function test_po_skasowaniu_przepisu_kucharz_dalej_widzi_powiadomienie_o_komentarzu(): void
    {
        [, $kucharz, $komentujaca, $przepis, $wykonanie] = $this->scenariusz();

        app(PublishComment::class)->handle($komentujaca, $wykonanie, 'Wyglada smakowicie.');

        // Soft delete zostawia `recipe_id` w wykonaniu — to nie jest przypadek `recipe_id IS NULL`.
        $przepis->delete();
        $this->assertNotNull($wykonanie->refresh()->recipe_id);

        $this->actingAs($kucharz)->get(route('cooked.show', $wykonanie))->assertOk();
        $this->assertSame(1, $this->widoczneKomentarze($kucharz));
    }

    public function test_obca_osoba_traci_powiadomienie_po_ukryciu_przepisu(): void
    {
        [, $kucharz, $komentujaca, $przepis, $wykonanie] = $this->scenariusz();

        $komentarz = app(PublishComment::class)->handle($komentujaca, $wykonanie, 'Jak dlugo sie gotowalo?');
        app(PublishComment::class)->handle($kucharz, $wykonanie, 'Poltorej godziny.', $komentarz, true);

        // KONTROLA DODATNIA: komentująca dostała odpowiedź i ją widzi.
        $this->assertSame(1, $this->widoczneKomentarze($komentujaca));

        $przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        // Wyjątek jest dla właściciela wykonania, nie dla każdego w wątku.
        $this->actingAs($komentujaca)->get(route('cooked.show', $wykonanie))->assertForbidden();
        $this->assertSame(0, $this->widoczneKomentarze($komentujaca));
        $this->assertSame(0, $komentujaca->refresh()->unreadNotificationsCount());
    }

    public function test_blokada_kucharza_z_autorem_przepisu_nie_odcina_powiadomienia(): void
    {
        [$autorka, $kucharz, $komentujaca, $przepis, $wykonanie] = $this->scenariusz();

        app(PublishComment::class)->handle($komentujaca, $wykonanie, 'Super zdjecie.');
        $przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        // KONTROLA DODATNIA: bez blokady widać.
        $this->assertSame(1, $this->widoczneKomentarze($kucharz));

        app(BlockUser::class)->handle($kucharz, $autorka);

        // D-265 (#1394): własne wykonanie otwiera się kucharzowi mimo blokady
        // z autorem przepisu — więc powiadomienie o komentarzu pod nim zostaje.
        // Lista i Policy odpowiadają tak samo.
        $this->actingAs($kucharz->refresh())
            ->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertDontSee($przepis->title);
        $this->assertSame(1, $this->widoczneKomentarze($kucharz));
        $this->assertSame(1, $kucharz->refresh()->unreadNotificationsCount());

        // KONTROLA DODATNIA: wyjątek jest tylko dla właściciela wykonania.
        // Autorka (w blokadzie z kucharzem) i obca osoba (przepis ukryty) nie widzą.
        $this->actingAs($autorka->refresh())->get(route('cooked.show', $wykonanie))->assertForbidden();
        $this->actingAs($this->user('obcaosoba'))->get(route('cooked.show', $wykonanie))->assertForbidden();
    }

    /**
     * @return array{User, User, User, Recipe, CookedEvent}
     */
    private function scenariusz(): array
    {
        $autorka = $this->user('autorkaprzepisu');
        $kucharz = $this->user('kucharz');
        $komentujaca = $this->user('komentujaca');

        $przepis = Recipe::factory()->create([
            'author_id' => $autorka->getKey(),
            'visibility' => 'public',
            'slug' => 'bigos-do-ukrycia',
        ]);

        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $kucharz->getKey(),
            'note' => 'Moj bigos.',
        ]);

        return [$autorka, $kucharz, $komentujaca, $przepis, $wykonanie];
    }

    private function widoczneKomentarze(User $odbiorca): int
    {
        return Notification::query()
            ->where('user_id', $odbiorca->getKey())
            ->whereIn('type', [Notification::TYPE_COMMENT, Notification::TYPE_REPLY])
            ->visibleTo($odbiorca->refresh())
            ->count();
    }
}

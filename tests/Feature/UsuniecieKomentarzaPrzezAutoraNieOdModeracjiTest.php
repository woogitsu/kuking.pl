<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Komentarz usunięty przez autora wpisu nie jest „wiadomością od moderacji”
 * (audyt B9 pkt 4).
 *
 * CO SIĘ DZIAŁO
 * `DeleteComment` zapisywał powiadomienie typu `moderation` bez `title`,
 * a widok brał wtedy domyślny nagłówek „Wiadomość od moderacji Kuking.”.
 * Serwis przypisywał moderacji decyzję, której moderacja nie podjęła —
 * a komentujący szukał potem odwołania od „kary”, której nie było.
 */
class UsuniecieKomentarzaPrzezAutoraNieOdModeracjiTest extends TestCase
{
    use RefreshDatabase;

    public function test_komentarz_pod_wpisem_usuniety_przez_autora_wpisu(): void
    {
        $basia = $this->user('basia');
        $zenek = $this->user('zenek');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);
        $komentarz = Comment::factory()->create(['author_id' => $zenek->getKey(), 'post_id' => $post->getKey()]);

        $this->actingAs($basia)
            ->delete(route('comments.destroy', $komentarz), ['reason' => 'Nie na temat.'])
            ->assertRedirect();

        $powiadomienie = Notification::query()
            ->where('user_id', $zenek->getKey())
            ->where('type', Notification::TYPE_MODERATION)
            ->sole();
        $this->assertSame('Twój komentarz został usunięty przez autora wpisu.', $powiadomienie->data['title'] ?? null);

        $this->actingAs($zenek)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Twój komentarz został usunięty przez autora wpisu.')
            ->assertDontSee('od moderacji');
    }

    public function test_komentarz_pod_przepisem_mowi_o_autorze_przepisu(): void
    {
        $basia = $this->user('basia');
        $zenek = $this->user('zenek');
        $przepis = Recipe::factory()->create(['author_id' => $basia->getKey()]);
        $komentarz = Comment::factory()->create(['author_id' => $zenek->getKey(), 'post_id' => null, 'recipe_id' => $przepis->getKey()]);

        $this->actingAs($basia)
            ->delete(route('comments.destroy', $komentarz), ['reason' => 'Nie na temat.'])
            ->assertRedirect();

        $this->actingAs($zenek)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Twój komentarz został usunięty przez autora przepisu.');
    }

    public function test_stare_powiadomienie_bez_tytulu_nie_mowi_od_moderacji(): void
    {
        // Tak wyglądają powiadomienia zapisane przed tą zmianą.
        $zenek = $this->user('zenek');
        Notification::create([
            'user_id' => $zenek->getKey(),
            'type' => Notification::TYPE_MODERATION,
            'data' => ['message' => 'Twój komentarz „Test” został usunięty przez autora treści. Powód: Nie na temat.'],
        ]);

        $this->actingAs($zenek)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Wiadomość od Kuking.')
            ->assertDontSee('Wiadomość od moderacji Kuking.');
    }

    public function test_decyzja_moderacji_dalej_ma_swoj_naglowek(): void
    {
        // Kontrola dodatnia: prawdziwa decyzja moderacji jest nazwana
        // decyzją moderacji — zmiana dotyczy tylko usunięcia przez autora.
        $zenek = $this->user('zenek');
        $post = Post::factory()->create(['author_id' => $zenek->getKey()]);
        $report = Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($this->moderator())
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => 'hide',
                'reason_code' => 'spam',
                'user_message' => 'Wpis wygląda na reklamę.',
            ])
            ->assertSessionHasNoErrors();

        $tytul = Notification::query()
            ->where('user_id', $zenek->getKey())
            ->where('type', Notification::TYPE_MODERATION)
            ->sole()->data['title'] ?? null;
        $this->assertIsString($tytul);

        $this->actingAs($zenek)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee($tytul)
            ->assertSee('Wpis wygląda na reklamę.');
    }
}

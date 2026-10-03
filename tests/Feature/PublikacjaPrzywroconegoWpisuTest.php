<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\Actions\PublishRestoredDraft;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\PrzeanalizujTresc;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Autor publikuje ponownie wpis, który moderacja przywróciła jako szkic (#2461).
 *
 * Przywrócenie historycznego wpisu bez zapisanego `previous_status` kończy
 * się szkicem (to zostaje). Brakowało kroku autora: `EditPost` zachowuje
 * status, a `PublishPost` tworzy NOWY rekord. Te testy pilnują nowej drogi:
 * ten sam wpis, ta sama data, ta sama widoczność, bez powiadomień.
 */
class PublikacjaPrzywroconegoWpisuTest extends TestCase
{
    use RefreshDatabase;

    private const DAWNA_DATA = '2026-03-04 10:00:00';

    /** Historyczne ukrycie: decyzja `hide` bez `previous_status`. */
    private function historycznieUkryty(User $autor, array $wpis = []): Post
    {
        $post = Post::factory()->create($wpis + [
            'author_id' => $autor->getKey(),
            'visibility' => Post::VISIBILITY_FOLLOWERS,
            'published_at' => Carbon::parse(self::DAWNA_DATA),
        ]);
        $post->forceFill(['status' => Post::STATUS_HIDDEN])->save();

        $report = Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'reason' => 'copyright',
            'status' => Report::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        ModerationAction::create([
            'moderator_id' => $this->moderator()->getKey(),
            'report_id' => $report->getKey(),
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'stary_wpis',
            'previous_status' => null,
        ]);

        return $post;
    }

    private function przywrocPrzezModeratora(Post $post): void
    {
        $report = Report::query()->findOrFail(
            ModerationAction::query()->where('target_id', $post->getKey())->value('report_id'),
        );

        $this->actingAs($this->moderator())
            ->from(route('admin.reports'))
            ->post(route('admin.reports.restore', $report), [
                'reason_code' => 'autor_poprawil',
                'user_message' => 'Dziękujemy za poprawkę. Treść wróciła.',
            ])
            ->assertRedirect(route('admin.reports'));
    }

    /** Wpis w stanie, w jakim zostawia go przywrócenie bez znanego stanu. */
    private function przywroconySzkic(User $autor, array $wpis = []): Post
    {
        $post = $this->historycznieUkryty($autor, $wpis);
        $this->przywrocPrzezModeratora($post);

        return $post->refresh();
    }

    public function test_pelny_ciag_ukrycie_przywrocenie_szkic_publikacja_przez_autora(): void
    {
        $autor = $this->user('basia');
        $obserwujacy = $this->user('adam');
        $autor->followers()->attach($obserwujacy->getKey());

        $post = $this->przywroconySzkic($autor);

        // Przywrócenie nadal kończy się szkicem — bez automatycznego upublicznienia.
        $this->assertSame(Post::STATUS_DRAFT, $post->status);
        $this->assertSame(self::DAWNA_DATA, $post->published_at->format('Y-m-d H:i:s'));

        // Autor ma jasny następny krok w „Moje wpisy”.
        $this->actingAs($autor)->get(route('collections.own-posts'))
            ->assertOk()
            ->assertSee('data-opublikuj-przywrocony', false)
            ->assertSee(route('posts.restored.confirm', $post), false);

        // Ekran potwierdzenia pokazuje DOTYCHCZASOWĄ widoczność i nie ma jej wyboru.
        $this->actingAs($autor)->get(route('posts.restored.confirm', $post))
            ->assertOk()
            ->assertSee('Dla obserwujących')
            ->assertSee('Opublikuj')
            ->assertDontSee('name="visibility"', false);

        $powiadomienPrzed = Notification::query()->count();
        $first = DB::table('first_post_events')->count();
        Queue::fake();

        $this->actingAs($autor)->post(route('posts.restored.publish', $post))
            ->assertRedirect($post->url());

        $po = $post->fresh();
        $this->assertSame(Post::STATUS_PUBLISHED, $po->status);
        $this->assertSame(self::DAWNA_DATA, $po->published_at->format('Y-m-d H:i:s'));
        $this->assertSame(Post::VISIBILITY_FOLLOWERS, $po->visibility);
        $this->assertSame($post->getKey(), $po->getKey());
        $this->assertSame($powiadomienPrzed, Notification::query()->count(), 'Naprawa nie jest nową publikacją — nikt nie dostaje powiadomienia.');
        $this->assertSame($first, DB::table('first_post_events')->count());
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'post.republished')->count());
        Queue::assertPushed(PrzeanalizujTresc::class, 1);
    }

    public function test_powtorzone_zadanie_nie_ma_skutku(): void
    {
        $autor = $this->user('basia');
        $post = $this->przywroconySzkic($autor);
        Queue::fake();

        $this->actingAs($autor)->post(route('posts.restored.publish', $post))->assertRedirect($post->url());
        $this->actingAs($autor)->post(route('posts.restored.publish', $post))
            ->assertRedirect($post->url())
            ->assertSessionHas('status', 'Ten wpis jest już opublikowany.');

        $this->assertSame(1, AuditLogEntry::query()->where('action', 'post.republished')->count());
        Queue::assertPushed(PrzeanalizujTresc::class, 1);
        $this->assertSame(1, Post::query()->where('author_id', $autor->getKey())->count());
    }

    public function test_cudze_konto_moderator_i_gosc_nie_publikuja(): void
    {
        $autor = $this->user('basia');
        $post = $this->przywroconySzkic($autor);

        $this->actingAs($this->user('obcy'))->post(route('posts.restored.publish', $post))->assertForbidden();
        $this->actingAs($this->user('obcy2'))->get(route('posts.restored.confirm', $post))->assertForbidden();
        $this->actingAs($this->moderator())->post(route('posts.restored.publish', $post))->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->post(route('posts.restored.publish', $post))->assertRedirect(route('login'));

        $this->assertSame(Post::STATUS_DRAFT, $post->fresh()->status);
    }

    public function test_wpis_ukryty_przez_moderacje_nie_wraca(): void
    {
        $autor = $this->user('basia');
        $post = $this->historycznieUkryty($autor);

        $this->actingAs($autor)->post(route('posts.restored.publish', $post))
            ->assertRedirect(route('collections.own-posts'))
            ->assertSessionHas('status', PublishRestoredDraft::KOMUNIKAT_POD_DECYZJA);
        $this->actingAs($autor)->get(route('posts.restored.confirm', $post))
            ->assertRedirect(route('collections.own-posts'));

        $this->assertSame(Post::STATUS_HIDDEN, $post->fresh()->status);
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'post.republished')->count());
    }

    public function test_ponowne_ukrycie_po_przywroceniu_zatrzymuje_publikacje(): void
    {
        $autor = $this->user('basia');
        $post = $this->przywroconySzkic($autor);

        // Nowa decyzja moderacji wpada, zanim autor kliknie.
        $post->forceFill(['status' => Post::STATUS_HIDDEN])->save();
        ModerationAction::create([
            'moderator_id' => $this->moderator()->getKey(),
            'report_id' => null,
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam',
            'previous_status' => Post::STATUS_DRAFT,
        ]);

        $this->actingAs($autor)->post(route('posts.restored.publish', $post))
            ->assertSessionHas('status', PublishRestoredDraft::KOMUNIKAT_POD_DECYZJA);
        $this->assertSame(Post::STATUS_HIDDEN, $post->fresh()->status);
    }

    public function test_szkic_ktorego_moderacja_nie_przywracala_nie_idzie_ta_droga(): void
    {
        $autor = $this->user('basia');
        $szkic = Post::factory()->draft()->create(['author_id' => $autor->getKey()]);
        $szkicZData = Post::factory()->create(['author_id' => $autor->getKey()]);
        $szkicZData->forceFill(['status' => Post::STATUS_DRAFT])->save();

        foreach ([$szkic, $szkicZData] as $wpis) {
            $this->actingAs($autor)->post(route('posts.restored.publish', $wpis))
                ->assertRedirect(route('collections.own-posts'))
                ->assertSessionHas('status', PublishRestoredDraft::KOMUNIKAT_NIE_TEN_STAN);
            $this->assertSame(Post::STATUS_DRAFT, $wpis->fresh()->status);
        }
    }

    public function test_zawieszone_konto_nie_publikuje(): void
    {
        $autor = $this->user('basia');
        $post = $this->przywroconySzkic($autor);
        $autor->forceFill([
            'status' => User::STATUS_SUSPENDED,
            'punishment_expires_at' => now()->addDays(3),
        ])->save();

        try {
            app(PublishRestoredDraft::class)->handle($autor->fresh(), $post);
            $this->fail('Zawieszone konto opublikowało wpis.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertStringContainsString('konta', $e->getMessage());
        }

        $this->assertSame(Post::STATUS_DRAFT, $post->fresh()->status);
    }

    public function test_akcja_wola_policy_takze_bez_kontrolera(): void
    {
        $post = $this->przywroconySzkic($this->user('basia'));

        $this->expectException(AuthorizationException::class);
        app(PublishRestoredDraft::class)->handle($this->user('obcy'), $post);
    }

    public function test_przepis_niedostepny_blokuje_publikacje(): void
    {
        $autor = $this->user('basia');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);
        $post = $this->przywroconySzkic($autor, ['recipe_id' => $przepis->getKey()]);
        $przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        $this->actingAs($autor)->post(route('posts.restored.publish', $post))
            ->assertRedirect(route('collections.own-posts'))
            ->assertSessionHas('status');

        $this->assertSame(Post::STATUS_DRAFT, $post->fresh()->status);
    }

    public function test_przycisk_nie_pojawia_sie_przy_zwyklym_szkicu_ani_opublikowanym(): void
    {
        $autor = $this->user('basia');
        Post::factory()->draft()->create(['author_id' => $autor->getKey()]);
        Post::factory()->create(['author_id' => $autor->getKey()]);

        $this->actingAs($autor)->get(route('collections.own-posts'))
            ->assertOk()
            ->assertDontSee('data-opublikuj-przywrocony', false);
    }

    public function test_normalne_przywrocenie_ze_znanym_stanem_dziala_jak_dotad(): void
    {
        $autor = $this->user('basia');
        $post = $this->historycznieUkryty($autor);
        ModerationAction::query()->where('target_id', $post->getKey())->update(['previous_status' => Post::STATUS_PUBLISHED]);

        $this->przywrocPrzezModeratora($post);

        $this->assertSame(Post::STATUS_PUBLISHED, $post->fresh()->status);
    }
}

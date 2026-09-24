<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\RestoreContent;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Przywrócenie treści: transakcja i blokada wiersza (przegląd G31, D-251).
 *
 * Kontrole ujemne (zepsuć → test oblewa → przywrócić):
 *  - `DB::transaction` w `RestoreContent::handle()` zdjęte — oblewa test
 *    awarii przy zapisie (zostaje `unhide` przy wciąż usuniętej treści);
 *  - stan czytany z modelu wołającego zamiast spod blokady — oblewa test
 *    drugiego przywrócenia (druga decyzja i drugie powiadomienie).
 */
class PrzywrocenieWTransakcjiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_awaria_przy_zapisie_komentarza_nie_zostawia_decyzji(): void
    {
        $moderator = $this->moderator();
        $komentarz = $this->komentarzZdjetyZUrzedu($moderator);
        $powiadomienPrzed = Notification::count();

        // Awaria dokładnie w ostatnim kroku — zapisie komentarza. Przed
        // poprawką decyzja `unhide` była już wtedy zapisana, a komentarz
        // zostawał usunięty.
        Comment::saving(static function (): never {
            throw new RuntimeException('Symulowana awaria bazy przy zapisie komentarza.');
        });

        try {
            app(RestoreContent::class)->handle($moderator, Comment::withTrashed()->findOrFail($komentarz->getKey()), 'autor_poprawil');
            $this->fail('Symulowana awaria nie wyszła z RestoreContent.');
        } catch (RuntimeException $e) {
            $this->assertSame('Symulowana awaria bazy przy zapisie komentarza.', $e->getMessage());
        }

        $this->assertSame(0, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->count(), 'Po awarii został wpis „przywrócone”, choć nic nie wróciło.');
        $this->assertSoftDeleted($komentarz);
        $this->assertSame($powiadomienPrzed, Notification::count());
    }

    public function test_drugie_przywrocenie_na_starym_stanie_nie_daje_drugiej_decyzji_ani_powiadomienia(): void
    {
        // Dwie karty / „Przywróć” i „cofam” naraz: obie ścieżki trzymają
        // model przeczytany PRZED pierwszym przywróceniem.
        $moderator = $this->moderator();
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = Report::create([
            'reporter_id' => $this->user('zglasza')->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);
        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'spam-reklama',
                'user_message' => 'Wpis wygląda na reklamę.',
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame(Post::STATUS_HIDDEN, $wpis->fresh()->status);

        // Dwa osobne obiekty, jak w dwóch żądaniach HTTP.
        $karta1 = $wpis->fresh();
        $karta2 = $wpis->fresh();
        $przywroc = app(RestoreContent::class);

        $przywroc->handle($moderator, $karta1, 'autor_poprawil');
        $powiadomienPo = Notification::where('user_id', $autor->getKey())->count();

        try {
            $przywroc->handle($moderator, $karta2, 'autor_poprawil');
            $this->fail('Drugie przywrócenie przeszło — stan nie był czytany pod blokadą.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame('Ta treść jest już widoczna — nie ma czego przywracać.', $e->getMessage());
        }

        $this->assertSame(1, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->count());
        $this->assertSame($powiadomienPo, Notification::where('user_id', $autor->getKey())->count());
    }

    private function komentarzZdjetyZUrzedu(User $moderator): Comment
    {
        $autor = $this->user('autor');
        $komentarz = Comment::factory()->create([
            'author_id' => $autor->getKey(),
            'post_id' => Post::factory()->create()->getKey(),
        ]);
        Comment::factory()->create(['post_id' => $komentarz->post_id, 'parent_id' => $komentarz->getKey()]);

        $this->actingAs($moderator)
            ->post(route('admin.z-urzedu.store', ['typ' => 'comment', 'id' => $komentarz->getKey()]), [
                'reason_code' => 'spam-reklama',
                'user_message' => 'Komentarz reklamuje sklep i nie ma nic wspólnego z gotowaniem.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($komentarz);

        return $komentarz;
    }
}

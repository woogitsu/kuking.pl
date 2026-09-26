<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Moderation\Actions\RestoreContent;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * „Przywróć” cofa decyzję moderacji — i tylko ją (audyt B2-01).
 *
 * CO SIĘ DZIAŁO
 * `RestoreContent` traktował każde `trashed()` jak zdjęcie przez moderację,
 * a trasa `admin.reports.restore` nie sprawdzała, czy przy zgłoszeniu zapadła
 * decyzja `hide`/`remove`. Komentarz, który usunęła właścicielka wpisu, wracał
 * po jednym kliknięciu od razu publicznie, a jego autor dostawał wiadomość
 * „przywróciliśmy Twoją treść”. Moderator cofał też ukrycie zarządzone przez
 * administratora.
 */
class PrzywrocenieTylkoPoDecyzjiModeracjiTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszenie(User $zglaszajacy, string $typ, string $celId): Report
    {
        return Report::create([
            'reporter_id' => $zglaszajacy->getKey(),
            'target_type' => $typ,
            'target_id' => $celId,
            'reason' => 'harassment',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    private function rozstrzygnij(User $kto, Report $report, string $akcja): void
    {
        $this->actingAs($kto)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => $akcja,
                'reason_code' => 'harassment',
                'user_message' => 'Ten komentarz obraża inne osoby.',
            ])
            ->assertRedirect(route('admin.reports'))
            ->assertSessionHasNoErrors();
    }

    private function przywroc(User $kto, Report $report): TestResponse
    {
        return $this->actingAs($kto)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.restore', $report), [
                'reason_code' => 'pomylka',
                'user_message' => 'Treść wróciła.',
            ]);
    }

    /** @return array{0: User, 1: Post, 2: Comment} właścicielka wpisu, wpis, komentarz */
    private function komentarzPodWpisemBasi(): array
    {
        $basia = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $basia->getKey()]);
        $komentarz = Comment::factory()->create([
            'author_id' => $this->user('zenek')->getKey(),
            'post_id' => $post->getKey(),
        ]);

        return [$basia, $post, $komentarz];
    }

    public function test_komentarz_usuniety_przez_wlascicielke_wpisu_nie_wraca(): void
    {
        [$basia, , $komentarz] = $this->komentarzPodWpisemBasi();
        $report = $this->zgloszenie($this->user(), 'comment', (string) $komentarz->getKey());

        app(DeleteComment::class)->handle($basia, $komentarz, 'Nie życzę sobie takich słów u siebie.');
        $this->assertTrue($komentarz->refresh()->trashed());

        $this->przywroc($this->moderator(), $report)
            ->assertRedirect(route('admin.reports'))
            ->assertSessionHasErrors('reason_code');

        $this->assertTrue($komentarz->refresh()->trashed(), 'Komentarz usunięty przez właścicielkę wpisu nie może wrócić decyzją moderatora.');
        $this->assertSame(0, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->count());
        $this->assertFalse(
            Notification::where('user_id', $komentarz->author_id)
                ->get()
                ->contains(fn (Notification $n): bool => ($n->data['decision'] ?? null) === ModerationAction::ACTION_UNHIDE),
            'Autor nie może dostać wiadomości „przywróciliśmy”, skoro nic nie wróciło.',
        );
    }

    public function test_zgloszenie_bez_decyzji_nie_przywraca_tresci_schowanej_przy_innym(): void
    {
        [, , $komentarz] = $this->komentarzPodWpisemBasi();
        $moderator = $this->moderator();

        $pierwsze = $this->zgloszenie($this->user(), 'comment', (string) $komentarz->getKey());
        $drugie = $this->zgloszenie($this->user(), 'comment', (string) $komentarz->getKey());
        $this->rozstrzygnij($moderator, $pierwsze, ModerationAction::ACTION_HIDE);

        $this->przywroc($moderator, $drugie)->assertSessionHasErrors('reason_code');
        $this->assertSame(Comment::STATUS_HIDDEN, $komentarz->refresh()->status);

        // Kontrola dodatnia: przy zgłoszeniu, przy którym zapadła decyzja, działa.
        $this->przywroc($moderator, $pierwsze)->assertSessionHasNoErrors();
        $this->assertSame(Comment::STATUS_PUBLISHED, $komentarz->refresh()->status);
    }

    public function test_usuniety_przez_moderacje_komentarz_wraca(): void
    {
        [, , $komentarz] = $this->komentarzPodWpisemBasi();
        $moderator = $this->moderator();
        $report = $this->zgloszenie($this->user(), 'comment', (string) $komentarz->getKey());

        $this->rozstrzygnij($moderator, $report, ModerationAction::ACTION_REMOVE);
        $this->assertTrue($komentarz->refresh()->trashed());

        $this->actingAs($moderator)
            ->get(route('admin.reports', ['status' => 'resolved']))
            ->assertSee('Przywróć treść');

        $this->przywroc($moderator, $report)->assertSessionHasNoErrors();

        $this->assertFalse($komentarz->refresh()->trashed());
        $this->assertSame(Comment::STATUS_PUBLISHED, $komentarz->status);
    }

    public function test_moderator_nie_cofa_decyzji_administratora_a_administrator_tak(): void
    {
        [, , $komentarz] = $this->komentarzPodWpisemBasi();
        $report = $this->zgloszenie($this->user(), 'comment', (string) $komentarz->getKey());
        $admin = $this->admin();

        $this->rozstrzygnij($admin, $report, ModerationAction::ACTION_HIDE);

        $this->przywroc($this->moderator(), $report)->assertSessionHasErrors('reason_code');
        $this->assertSame(Comment::STATUS_HIDDEN, $komentarz->refresh()->status);

        $this->przywroc($admin, $report)->assertSessionHasNoErrors();
        $this->assertSame(Comment::STATUS_PUBLISHED, $komentarz->refresh()->status);
    }

    public function test_po_cofnieciu_kary_usuniecie_przez_autora_nie_jest_juz_decyzja_moderacji(): void
    {
        // Ukrycie → przywrócenie → autor sam usuwa wpis. Ostatnie słowo
        // moderacji to `unhide`, więc obecne schowanie nie jest karą — także
        // dla „cofam” po odwołaniu, które idzie tą samą akcją.
        $autor = $this->user('basia');
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $moderator = $this->moderator();
        $report = $this->zgloszenie($this->user(), 'post', (string) $post->getKey());

        $this->rozstrzygnij($moderator, $report, ModerationAction::ACTION_HIDE);
        $this->przywroc($moderator, $report)->assertSessionHasNoErrors();

        $post->refresh()->delete();

        $this->expectException(BladDlaCzlowieka::class);

        try {
            app(RestoreContent::class)->handle($this->admin(), $post->refresh(), 'appeal_overturned');
        } finally {
            $this->assertTrue(Post::withTrashed()->find($post->getKey())->trashed());
        }
    }

    public function test_kolejka_nie_pokazuje_przycisku_przy_tresci_usunietej_przez_wlascicielke(): void
    {
        [$basia, , $komentarz] = $this->komentarzPodWpisemBasi();
        $moderator = $this->moderator();
        $report = $this->zgloszenie($this->user(), 'comment', (string) $komentarz->getKey());

        // Moderacja ukryła, potem cofnęła, potem właścicielka usunęła.
        $this->rozstrzygnij($moderator, $report, ModerationAction::ACTION_HIDE);
        $this->przywroc($moderator, $report)->assertSessionHasNoErrors();
        app(DeleteComment::class)->handle($basia, $komentarz->refresh(), 'Nie u mnie.');

        $this->actingAs($moderator)
            ->get(route('admin.reports', ['status' => 'resolved']))
            ->assertOk()
            ->assertDontSee('Przywróć treść');
    }
}

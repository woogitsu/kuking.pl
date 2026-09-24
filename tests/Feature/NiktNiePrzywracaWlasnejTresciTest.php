<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\ResolveAppeal;
use App\Domain\Moderation\WlasnejTresciNiePrzywracasz;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Nikt nie przywraca treści, której jest autorem (#1479).
 *
 * CO BYŁO ZEPSUTE
 * `ModerationController::restore()` i `RestoreContent` sprawdzały tylko rolę.
 * Moderator, którego wpis albo komentarz ukrył ktoś inny z zespołu, klikał
 * „Przywróć treść" przy zgłoszeniu i zdejmował karę sam sobie — z pominięciem
 * odwołania. Ten sam konflikt interesów co przy rozstrzyganiu zgłoszeń (#1408).
 *
 * Każda odmowa ma obok kontrolę dodatnią: tę samą treść przywraca inny
 * moderator (`docs/PULAPKI_TESTOW.md`, pułapka 4 — odmowa dla wszystkich
 * wyglądałaby tu identycznie jak poprawna reguła).
 */
class NiktNiePrzywracaWlasnejTresciTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderator_nie_przywraca_wlasnego_ukrytego_wpisu(): void
    {
        $autor = $this->moderator();
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = $this->ukryte($post, 'post', $autor);

        $this->przywroc($autor, $report)
            ->assertRedirect(route('admin.reports'))
            ->assertSessionHasErrors(['reason_code' => (new WlasnejTresciNiePrzywracasz)->getMessage()]);

        $this->assertSame(Post::STATUS_HIDDEN, $post->refresh()->status);
        $this->assertBrakSladowPrzywrocenia($autor);
    }

    public function test_moderator_nie_przywraca_wlasnego_ukrytego_komentarza(): void
    {
        $autor = $this->moderator();
        $post = Post::factory()->create(['author_id' => $this->user()->getKey()]);
        $komentarz = Comment::factory()->create([
            'author_id' => $autor->getKey(),
            'post_id' => $post->getKey(),
        ]);
        $report = $this->ukryte($komentarz, 'comment', $autor);

        $this->przywroc($autor, $report)
            ->assertRedirect(route('admin.reports'))
            ->assertSessionHasErrors('reason_code');

        $this->assertSame(Comment::STATUS_HIDDEN, $komentarz->refresh()->status);
        $this->assertBrakSladowPrzywrocenia($autor);
    }

    /** Kontrola dodatnia: ten sam wpis przywraca ktoś inny z moderacji. */
    public function test_inny_moderator_przywraca_ten_wpis(): void
    {
        $autor = $this->moderator();
        $inny = $this->moderator();
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = $this->ukryte($post, 'post', $autor);

        $this->przywroc($inny, $report)
            ->assertRedirect(route('admin.reports'))
            ->assertSessionHasNoErrors();

        $this->assertSame(Post::STATUS_PUBLISHED, $post->refresh()->status);
        $this->assertSame(1, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)
            ->where('moderator_id', $inny->getKey())->count());
    }

    /** Kontrola dodatnia dla komentarza. */
    public function test_inny_moderator_przywraca_ten_komentarz(): void
    {
        $autor = $this->moderator();
        $inny = $this->moderator();
        $post = Post::factory()->create(['author_id' => $this->user()->getKey()]);
        $komentarz = Comment::factory()->create([
            'author_id' => $autor->getKey(),
            'post_id' => $post->getKey(),
        ]);
        $report = $this->ukryte($komentarz, 'comment', $autor);

        $this->przywroc($inny, $report)->assertSessionHasNoErrors();

        $this->assertSame(Comment::STATUS_PUBLISHED, $komentarz->refresh()->status);
    }

    /**
     * Druga droga do tej samej akcji: „cofam" po odwołaniu. Odmowy nie wolno
     * tu połknąć — odwołanie zamknięte jako cofnięte przy treści, która dalej
     * jest schowana, mówiłoby autorowi nieprawdę.
     */
    public function test_administrator_nie_cofa_przez_odwolanie_ukrycia_wlasnego_wpisu(): void
    {
        $autor = $this->admin();
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $this->ukryte($post, 'post', $autor);
        $odwolanie = $this->odwolanie($autor);

        try {
            app(ResolveAppeal::class)->handle(
                moderator: $autor,
                odwolanie: $odwolanie,
                wynik: Appeal::STATUS_OVERTURNED,
                uzasadnienie: 'Cofam, ukrycie było pomyłką.',
            );

            $this->fail('Autor cofnął przez odwołanie ukrycie własnego wpisu (#1479).');
        } catch (WlasnejTresciNiePrzywracasz) {
            // Tego oczekujemy.
        }

        $this->assertSame(Post::STATUS_HIDDEN, $post->refresh()->status);
        $this->assertSame(Appeal::STATUS_OPEN, $odwolanie->refresh()->status);
        $this->assertBrakSladowPrzywrocenia($autor);
        $this->assertSame(0, AuditLogEntry::where('action', 'appeal.resolved')->count());
    }

    /** Kontrola dodatnia: to samo odwołanie cofa inny administrator. */
    public function test_inny_administrator_cofa_przez_odwolanie_ukrycie_wpisu(): void
    {
        $autor = $this->admin();
        $inny = $this->admin();
        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $this->ukryte($post, 'post', $autor);
        $odwolanie = $this->odwolanie($autor);

        app(ResolveAppeal::class)->handle(
            moderator: $inny,
            odwolanie: $odwolanie,
            wynik: Appeal::STATUS_OVERTURNED,
            uzasadnienie: 'Cofam, ukrycie było pomyłką.',
        );

        $this->assertSame(Post::STATUS_PUBLISHED, $post->refresh()->status);
        $this->assertSame(Appeal::STATUS_OVERTURNED, $odwolanie->refresh()->status);
    }

    /**
     * Stan „ukryte przez innego moderatora przy zgłoszeniu" budujemy wprost:
     * droga przez `decide` podlega regule rangi celu, a tu mierzymy wyłącznie
     * przywracanie.
     */
    private function ukryte(Model $cel, string $typ, User $autor): Report
    {
        $ukrywajacy = $this->admin();

        $report = Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => $typ,
            'target_id' => $cel->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_RESOLVED,
            'resolved_at' => now(),
        ]);

        ModerationAction::create([
            'moderator_id' => $ukrywajacy->getKey(),
            'report_id' => $report->getKey(),
            'target_type' => $typ,
            'target_id' => $cel->getKey(),
            'subject_user_id' => $autor->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'previous_status' => 'published',
            'reason_code' => 'spam-reklama',
            'user_message' => 'Treść wygląda na reklamę.',
        ]);

        $cel->forceFill(['status' => 'hidden'])->save();

        return $report;
    }

    private function odwolanie(User $autor): Appeal
    {
        $decyzja = ModerationAction::where('action', ModerationAction::ACTION_HIDE)->firstOrFail();

        return Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'Proszę o ponowne rozpatrzenie tej sprawy.',
            'status' => Appeal::STATUS_OPEN,
        ]);
    }

    private function przywroc(User $moderator, Report $report): TestResponse
    {
        return $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.restore', $report), [
                'reason_code' => 'autor_poprawil',
                'user_message' => 'Treść wróciła.',
            ]);
    }

    private function assertBrakSladowPrzywrocenia(User $autor): void
    {
        $this->assertSame(0, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->count(),
            'Odmowa zapisała decyzję `unhide`.');
        $this->assertSame(0, AuditLogEntry::where('action', 'moderation.restored')->count(),
            'Odmowa zapisała w dzienniku audytu przywrócenie, którego nie było.');
        $this->assertSame(0, Notification::where('user_id', $autor->getKey())->count(),
            'Odmowa wysłała autorowi powiadomienie.');
    }
}

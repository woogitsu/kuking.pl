<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Comments\Actions\PublishComment;
use App\Domain\Comments\LockCommentContext;
use App\Domain\Moderation\Actions\ZdejmijTresc;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Appeal;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Tests\TestCase;

/**
 * „Zdejmij z urzędu” — poprawki z przeglądu kodu (G31, D-251 pkt 11–12).
 *
 * Kontrole ujemne (zepsuć → test oblewa → przywrócić):
 *  - usunąć `zablokuj()` z `ModerationController::decide()` — oblewa test
 *    wyścigu (500 z zapory w `tekstDoZachowania()` zamiast błędu dla
 *    moderatora; bez zapory: napis zapisany jako kopia);
 *  - usunąć `isModerator()` z komponentu i eager load `post` w
 *    `PostController` — oblewa test liczby zapytań.
 */
class ZdejmijZUrzeduPoPrzegladzieTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    // ── 1. Stan „już zdjęta” czytany pod blokadą ─────────────────────────

    public function test_komentarz_usuniety_przez_autora_tuz_przed_blokada_nie_zostawia_napisu_jako_kopii(): void
    {
        $autor = $this->user('autor');
        $komentarz = $this->komentarzZOdpowiedzia($autor);
        $report = Report::create([
            'reporter_id' => $this->user('zglasza')->getKey(),
            'target_type' => 'comment',
            'target_id' => $komentarz->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);

        // Wyścig: autor usuwa komentarz (napis, bo ma odpowiedź) DOKŁADNIE
        // przed pierwszą blokadą komentarza w `decide()` — już po tym, jak
        // kontroler wczytał cel bez blokady.
        $raz = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$raz, $komentarz): void {
            if ($raz || ! str_contains($sql, '"comments"') || ! str_contains(strtolower($sql), 'for no key update')) {
                return;
            }

            $raz = true;
            DB::table('comments')->where('id', $komentarz->getKey())->update([
                'body' => DeleteComment::DELETED_PLACEHOLDER,
                'body_removed_at' => now(),
            ]);
        });

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_REMOVE,
                'reason_code' => 'spam-reklama',
                'user_message' => 'Komentarz był reklamą.',
            ])
            ->assertSessionHasErrors('action');

        $this->assertTrue($raz, 'Symulacja wyścigu nie zadziałała — nie było blokady komentarza.');
        $this->assertDatabaseMissing('moderation_actions', ['tresc_sprzed_zdjecia' => DeleteComment::DELETED_PLACEHOLDER]);
        $this->assertSame(0, ModerationAction::count(), 'Decyzja remove zapisana na komentarzu, którego już nie było.');
        $this->assertSame(Report::STATUS_OPEN, $report->fresh()->status);
    }

    public function test_tekst_do_zachowania_odmawia_na_napisie_nawet_ze_starego_modelu(): void
    {
        $komentarz = $this->komentarzZOdpowiedzia($this->user('autor'));
        DB::table('comments')->where('id', $komentarz->getKey())->update([
            'body' => DeleteComment::DELETED_PLACEHOLDER,
            'body_removed_at' => now(),
        ]);

        $this->expectException(LogicException::class);

        DB::transaction(fn () => app(ZdejmijTresc::class)->tekstDoZachowania($komentarz));
    }

    // ── 2. Napis bez odpowiedzi zostaje — jak na main ───────────────────
    //
    // Przegląd G31 próbował sprzątać napis razem z ostatnią odpowiedzią.
    // Wycofane decyzją sesji głównej (D-251 pkt 11). Nowej odpowiedzi pod
    // napisem i tak nie ma (D-251 pkt 13, `OdpowiedzPodNapisemTest`).

    public function test_napis_autora_zostaje_gdy_znika_ostatnia_odpowiedz_ale_nie_przyjmuje_nowej(): void
    {
        $autor = $this->user('autor');
        $komentarz = $this->komentarzZOdpowiedzia($autor);
        $odpowiedz = $komentarz->replies()->sole();

        app(DeleteComment::class)->handle($autor, $komentarz);
        app(DeleteComment::class)->handle($odpowiedz->author, $odpowiedz);

        $this->assertNotSoftDeleted($komentarz);
        $this->assertSame(DeleteComment::DELETED_PLACEHOLDER, $komentarz->fresh()->body);

        try {
            app(PublishComment::class)->handle($this->user('czytelnik'), $komentarz->post, 'Odpowiedź pod napisem.', $komentarz->fresh());
            $this->fail('Odpowiedź pod napisem przeszła (D-251 pkt 13).');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(LockCommentContext::UNAVAILABLE, $e->getMessage());
        }
        $this->assertFalse(Comment::withTrashed()->where('body', 'Odpowiedź pod napisem.')->exists());
    }

    public function test_zwykly_komentarz_bez_odpowiedzi_zostaje_gdy_znika_odpowiedz_obok(): void
    {
        $komentarz = $this->komentarzZOdpowiedzia($this->user('autor'));
        $odpowiedz = $komentarz->replies()->sole();

        app(DeleteComment::class)->handle($odpowiedz->author, $odpowiedz);

        $this->assertNotSoftDeleted($komentarz);
        $this->assertNull($komentarz->fresh()->body_removed_at);
    }

    public function test_napis_moderacji_zostaje_z_ostatnia_odpowiedzia_a_cofniecie_przywraca_tekst(): void
    {
        $autor = $this->user('autor');
        $komentarz = $this->komentarzZOdpowiedzia($autor);
        $tekst = $komentarz->body;
        $odpowiedz = $komentarz->replies()->sole();

        $this->actingAs($this->moderator())
            ->post(route('admin.z-urzedu.store', ['typ' => 'comment', 'id' => $komentarz->getKey()]), [
                'reason_code' => 'spam-reklama',
                'user_message' => 'Komentarz reklamował sklep.',
            ])
            ->assertSessionHasNoErrors();

        app(DeleteComment::class)->handle($odpowiedz->author, $odpowiedz);
        $this->assertNotSoftDeleted($komentarz);
        $this->assertSame(DeleteComment::DELETED_PLACEHOLDER, $komentarz->fresh()->body);

        $decyzja = ModerationAction::sole();
        $this->assertSame($tekst, $decyzja->tresc_sprzed_zdjecia);

        $this->actingAs($autor)->post(route('appeals.store', $decyzja), ['body' => 'To nie była reklama, tylko polecenie sklepu z przyprawami.']);
        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', Appeal::sole()), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Masz rację, komentarz wraca.',
            ])
            ->assertSessionHasNoErrors();

        $przywrocony = $komentarz->fresh();
        $this->assertSame($tekst, $przywrocony->body);
        $this->assertNull($przywrocony->body_removed_at);
    }

    // ── 3. Przycisk „Zdejmij z urzędu” bez N+1 ───────────────────────────

    public function test_strona_wpisu_dla_zwyklego_uzytkownika_nie_ma_zapytan_na_komentarz(): void
    {
        $wpis = Post::factory()->create();
        $widz = $this->user('czytelnik');

        $this->komentarze($wpis, 2, od: 0);
        $malo = $this->policzZapytania(fn () => $this->actingAs($widz)->get(route('posts.show', $wpis))->assertOk());

        $this->komentarze($wpis, 8, od: 2);
        $html = $this->actingAs($widz)->get(route('posts.show', $wpis))->assertOk()->getContent();
        // Kontrola dodatnia: pomiar mierzy rozmowę, nie pustą stronę.
        $this->assertStringContainsString('Komentarz numer 9', $html);
        $this->assertStringContainsString('Odpowiedź numer 9', $html);

        $duzo = $this->policzZapytania(fn () => $this->actingAs($widz)->get(route('posts.show', $wpis))->assertOk());

        $this->assertSame($malo, $duzo, "Strona wpisu dla zalogowanego: {$malo} zapytań przy 2 komentarzach, {$duzo} przy 10.");
    }

    public function test_strona_wpisu_dla_moderatora_tez_nie_ma_zapytan_na_komentarz(): void
    {
        $wpis = Post::factory()->create();
        $moderator = $this->moderator();

        $this->komentarze($wpis, 2, od: 0);
        $malo = $this->policzZapytania(fn () => $this->actingAs($moderator)->get(route('posts.show', $wpis))->assertOk());

        $this->komentarze($wpis, 8, od: 2);
        $html = $this->actingAs($moderator)->get(route('posts.show', $wpis))->assertOk()->getContent();
        $this->assertStringContainsString('Zdejmij z urzędu', $html, 'Moderator nie widzi przycisku — pomiar nie mierzy komponentu.');

        $duzo = $this->policzZapytania(fn () => $this->actingAs($moderator)->get(route('posts.show', $wpis))->assertOk());

        $this->assertSame($malo, $duzo, "Strona wpisu dla moderatora: {$malo} zapytań przy 2 komentarzach, {$duzo} przy 10.");
    }

    public function test_strona_wykonania_dla_moderatora_nie_ma_zapytan_na_komentarz(): void
    {
        $wykonanie = CookedEvent::factory()->create([
            'recipe_id' => Recipe::factory()->create()->getKey(),
        ]);
        $moderator = $this->moderator();

        $this->komentarze($wykonanie, 2, od: 0);
        $malo = $this->policzZapytania(fn () => $this->actingAs($moderator)->get(route('cooked.show', $wykonanie))->assertOk());

        $this->komentarze($wykonanie, 8, od: 2);
        $duzo = $this->policzZapytania(fn () => $this->actingAs($moderator)->get(route('cooked.show', $wykonanie))->assertOk());

        $this->assertSame($malo, $duzo, "Strona wykonania dla moderatora: {$malo} zapytań przy 2 komentarzach, {$duzo} przy 10.");
    }

    private function komentarzZOdpowiedzia(User $autor): Comment
    {
        $komentarz = Comment::factory()->create([
            'author_id' => $autor->getKey(),
            'post_id' => Post::factory()->create()->getKey(),
        ]);
        Comment::factory()->create([
            'post_id' => $komentarz->post_id,
            'parent_id' => $komentarz->getKey(),
            'author_id' => $this->user()->getKey(),
        ]);

        return $komentarz;
    }

    /** Komentarze różnych osób, każdy z jedną odpowiedzią kolejnej osoby. */
    private function komentarze(Post|CookedEvent $gdzie, int $ile, int $od): void
    {
        $kolumna = $gdzie instanceof Post ? 'post_id' : 'cooked_event_id';

        for ($i = $od; $i < $od + $ile; $i++) {
            $komentarz = Comment::factory()->create([
                'post_id' => null,
                $kolumna => $gdzie->getKey(),
                'author_id' => $this->user()->getKey(),
                'body' => 'Komentarz numer '.$i,
            ]);
            Comment::factory()->create([
                'post_id' => null,
                $kolumna => $gdzie->getKey(),
                'parent_id' => $komentarz->getKey(),
                'author_id' => $this->user()->getKey(),
                'body' => 'Odpowiedź numer '.$i,
            ]);
        }
    }

    private function policzZapytania(callable $akcja): int
    {
        $ile = 0;
        DB::listen(function () use (&$ile): void {
            $ile++;
        });

        $akcja();

        return $ile;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Moderation\Actions\RestoreContent;
use App\Domain\Moderation\Actions\ZdejmijTresc;
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
 *  - usunąć wywołanie `usunPustyNapisRodzica()` z `DeleteComment` — oblewa
 *    test pustego napisu;
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

    // ── 2. Pusty napis znika razem z ostatnią odpowiedzią ────────────────

    public function test_napis_autora_znika_gdy_znika_ostatnia_odpowiedz(): void
    {
        $autor = $this->user('autor');
        $komentarz = $this->komentarzZOdpowiedzia($autor);
        $odpowiedz = $komentarz->replies()->sole();
        $druga = Comment::factory()->create(['post_id' => $komentarz->post_id, 'parent_id' => $komentarz->getKey()]);

        app(DeleteComment::class)->handle($autor, $komentarz);
        $this->assertSame(DeleteComment::DELETED_PLACEHOLDER, $komentarz->fresh()->body);

        app(DeleteComment::class)->handle($odpowiedz->author, $odpowiedz);
        $this->assertNotSoftDeleted($komentarz); // zostaje jeszcze jedna odpowiedź

        app(DeleteComment::class)->handle($druga->author, $druga);
        $this->assertSoftDeleted($komentarz);

        $this->actingAs($this->user('czytelnik'))
            ->get(route('posts.show', $komentarz->post_id))
            ->assertOk()
            ->assertDontSee(DeleteComment::DELETED_PLACEHOLDER);
    }

    public function test_zwykly_komentarz_bez_odpowiedzi_zostaje_gdy_znika_odpowiedz_obok(): void
    {
        $komentarz = $this->komentarzZOdpowiedzia($this->user('autor'));
        $odpowiedz = $komentarz->replies()->sole();

        app(DeleteComment::class)->handle($odpowiedz->author, $odpowiedz);

        $this->assertNotSoftDeleted($komentarz);
        $this->assertNull($komentarz->fresh()->body_removed_at);
    }

    public function test_napis_moderacji_znika_z_ostatnia_odpowiedzia_a_cofniecie_przywraca_tekst(): void
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
        $this->assertSoftDeleted($komentarz);

        $decyzja = ModerationAction::sole();
        $this->assertSame($tekst, $decyzja->tresc_sprzed_zdjecia, 'Kopia tekstu przepadła razem z napisem.');

        $this->actingAs($autor)->post(route('appeals.store', $decyzja), ['body' => 'To nie była reklama, tylko polecenie sklepu z przyprawami.']);
        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', Appeal::sole()), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Masz rację, komentarz wraca.',
            ])
            ->assertSessionHasNoErrors();

        $przywrocony = Comment::withTrashed()->find($komentarz->getKey());
        $this->assertFalse($przywrocony->trashed());
        $this->assertSame($tekst, $przywrocony->body);
        $this->assertNull($przywrocony->body_removed_at);
    }

    public function test_odpowiedz_zdjeta_przez_moderacje_zabiera_napis_a_jej_przywrocenie_go_oddaje(): void
    {
        $autor = $this->user('autor');
        $komentarz = $this->komentarzZOdpowiedzia($autor);
        $odpowiedz = $komentarz->replies()->sole();
        app(DeleteComment::class)->handle($autor, $komentarz);

        $moderator = $this->moderator();
        $this->actingAs($moderator)
            ->post(route('admin.z-urzedu.store', ['typ' => 'comment', 'id' => $odpowiedz->getKey()]), [
                'reason_code' => 'spam-reklama',
                'user_message' => 'Odpowiedź reklamowała sklep.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted($odpowiedz);
        $this->assertSoftDeleted($komentarz);

        app(RestoreContent::class)->handle($moderator, $odpowiedz->fresh(), 'autor_poprawil');

        $this->assertNotSoftDeleted($odpowiedz);
        $napis = $komentarz->fresh();
        $this->assertNotNull($napis, 'Odpowiedź wróciła pod komentarz, którego nie ma.');
        $this->assertSame(DeleteComment::DELETED_PLACEHOLDER, $napis->body);
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

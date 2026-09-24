<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Comments\Actions\DeleteComment;
use App\Domain\Moderation\Actions\RestoreContent;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Appeal;
use App\Models\Comment;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Przywrócenie treści: transakcja, blokada wiersza i komentarz, którego
 * nie ma już czym przywrócić (przegląd G31, D-251 pkt 10).
 *
 * Kontrole ujemne (zepsuć → test oblewa → przywrócić):
 *  - `DB::transaction` w `RestoreContent::handle()` zdjęte — oblewa test
 *    awarii przy zapisie (zostaje `unhide`, kopia wyzerowana);
 *  - stan czytany z modelu wołającego zamiast spod blokady — oblewa test
 *    drugiego przywrócenia (druga decyzja i drugie powiadomienie);
 *  - warunek `body_removed_at` w `CommentPolicy::delete()` zdjęty — oblewa
 *    test spreparowanego usunięcia;
 *  - `catch (TekstUsunietyPrzezAutora)` w `ResolveAppeal` zdjęte — oblewa
 *    test odpowiedzi na odwołanie (brak zdania, że komentarz nie wrócił).
 */
class PrzywrocenieWTransakcjiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_awaria_przy_zapisie_komentarza_nie_gubi_kopii_ani_nie_zostawia_decyzji(): void
    {
        $moderator = $this->moderator();
        [$komentarz, $tekst] = $this->komentarzZdjetyZNapisem($moderator);
        $decyzja = ModerationAction::sole();
        $powiadomienPrzed = Notification::count();

        // Awaria dokładnie w ostatnim kroku — zapisie komentarza. Przed
        // poprawką decyzja `unhide` i wyzerowanie kopii były już wtedy
        // zapisane, a tekst znikał na zawsze.
        Comment::saving(static function (): never {
            throw new RuntimeException('Symulowana awaria bazy przy zapisie komentarza.');
        });

        try {
            app(RestoreContent::class)->handle($moderator, $komentarz->fresh(), 'autor_poprawil');
            $this->fail('Symulowana awaria nie wyszła z RestoreContent.');
        } catch (RuntimeException $e) {
            $this->assertSame('Symulowana awaria bazy przy zapisie komentarza.', $e->getMessage());
        }

        $this->assertSame($tekst, $decyzja->fresh()->tresc_sprzed_zdjecia, 'Awaria w środku przywracania skasowała jedyną kopię tekstu.');
        $this->assertSame(0, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->count(), 'Po awarii został wpis „przywrócone”, choć nic nie wróciło.');
        $this->assertSame(DeleteComment::DELETED_PLACEHOLDER, $komentarz->fresh()->body);
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

    public function test_autor_nie_usunie_komentarza_zdjetego_przez_moderacje(): void
    {
        $moderator = $this->moderator();
        [$komentarz, $tekst] = $this->komentarzZdjetyZNapisem($moderator);
        $autor = $komentarz->author;

        $this->assertTrue(Gate::forUser($autor)->denies('delete', $komentarz->fresh()));

        $this->actingAs($autor)
            ->from(route('home'))
            ->delete(route('comments.destroy', $komentarz))
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors(['reason' => 'Ten komentarz jest już usunięty — w wątku stoi napis „Komentarz usunięty.”. Nie trzeba robić nic więcej.']);

        $swiezy = Comment::withTrashed()->find($komentarz->getKey());
        $this->assertFalse($swiezy->trashed(), 'Spreparowany DELETE skasował komentarz zdjęty przez moderację.');
        $this->assertNotNull($swiezy->body_removed_at);
        $this->assertSame($tekst, ModerationAction::sole()->tresc_sprzed_zdjecia);
    }

    public function test_cofam_po_usunieciu_przez_autora_mowi_ze_komentarz_nie_wrocil(): void
    {
        $moderator = $this->moderator();
        [$komentarz] = $this->komentarzZdjetyZNapisem($moderator);
        $autor = $komentarz->author;
        $decyzja = ModerationAction::sole();

        $this->travel(1)->minutes();
        $this->actingAs($autor)
            ->post(route('appeals.store', $decyzja), ['body' => 'To nie był spam, tylko pytanie o przepis.'])
            ->assertSessionHasNoErrors();

        // Zanim ktoś rozpatrzy odwołanie, moderacja sama przywraca komentarz,
        // a autor go potem usuwa — tekst skasował człowiek.
        $this->travel(1)->minutes();
        app(RestoreContent::class)->handle($moderator, $komentarz->fresh(), 'autor_poprawil');
        $this->travel(1)->minutes();
        app(DeleteComment::class)->handle($autor, $komentarz->fresh());
        $this->assertSame(DeleteComment::DELETED_PLACEHOLDER, $komentarz->fresh()->body);

        $this->travel(1)->minutes();
        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', Appeal::sole()), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Masz rację, komentarz wraca.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Appeal::STATUS_OVERTURNED, Appeal::sole()->status);
        $this->assertSame(DeleteComment::DELETED_PLACEHOLDER, $komentarz->fresh()->body);

        $odpowiedz = Notification::where('user_id', $autor->getKey())->where('data->decision', 'appeal.'.Appeal::STATUS_OVERTURNED)->sole();
        $this->assertSame(
            "Masz rację, komentarz wraca.\n\nKomentarz nie wrócił na stronę. Po naszej decyzji usunęła go osoba, "
            .'która go napisała, albo autor treści, pod którą stał — dlatego nie mamy już jego tekstu.',
            $odpowiedz->data['message'],
            'Autor odwołania dostał obietnicę przywrócenia, którego nie było.',
        );
    }

    public function test_cofam_ktore_przywraca_nie_dokleja_zdania(): void
    {
        $moderator = $this->moderator();
        [$komentarz, $tekst] = $this->komentarzZdjetyZNapisem($moderator);
        $autor = $komentarz->author;

        $this->actingAs($autor)->post(route('appeals.store', ModerationAction::sole()), ['body' => 'To nie był spam, tylko pytanie o przepis.']);
        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', Appeal::sole()), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Masz rację, komentarz wraca.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($tekst, $komentarz->fresh()->body);
        $odpowiedz = Notification::where('user_id', $autor->getKey())->where('data->decision', 'appeal.'.Appeal::STATUS_OVERTURNED)->sole();
        $this->assertSame('Masz rację, komentarz wraca.', $odpowiedz->data['message']);
    }

    /** @return array{0: Comment, 1: string} */
    private function komentarzZdjetyZNapisem(User $moderator): array
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

        $this->assertSame(DeleteComment::DELETED_PLACEHOLDER, $komentarz->fresh()->body);

        return [$komentarz, (string) ModerationAction::sole()->tresc_sprzed_zdjecia];
    }
}

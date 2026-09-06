<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Ukrycie treści da się cofnąć — i wraca ona tam, skąd ją zabrano (issue #65).
 *
 * CO SIĘ DZIAŁO
 * `ModerationController::applyAction()` miał w komentarzu „`hide` ukrywa treść
 * (da się przywrócić)". Endpointu przywracania nie było. Jedyną drogą powrotu
 * był `UPDATE` w produkcyjnej bazie — czyli operacja, której AGENTS.md §6
 * zabrania bez zgody właściciela.
 *
 * Boli to najbardziej tam, gdzie podręcznik moderacji każe ukrywać ŚWIADOMIE
 * NA CHWILĘ: przy prawach autorskich pisze „najpierw ukryć (dać szansę
 * poprawy), potem usunąć jeśli brak reakcji". Autor poprawiał przepis własnymi
 * słowami — i nie było komu zdjąć ukrycia. Kara pomyślana jako tymczasowa
 * stawała się dożywotnia.
 *
 * DRUGA CZĘŚĆ PROBLEMU: DO CZEGO WRACAĆ
 * `recipes.status` i `posts.status` trzymają tylko stan bieżący. Przywracanie
 * „na sztywno do published" upubliczniłoby ukryty SZKIC — treść, której autor
 * nigdy nikomu nie pokazał. Dlatego stan sprzed ukrycia zapisujemy w
 * `moderation_actions.previous_status` w chwili ukrywania.
 */
class PrzywrocenieTresciTest extends TestCase
{
    use RefreshDatabase;

    private function zgloszenie(string $typ, string $celId): Report
    {
        return Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => $typ,
            'target_id' => $celId,
            'reason' => 'copyright',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    private function ukryj(User $moderator, Report $report): void
    {
        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'copyright',
                'user_message' => 'Przepis wygląda na skopiowany. Popraw go własnymi słowami.',
            ])
            ->assertRedirect(route('admin.reports'));
    }

    private function przywroc(User $moderator, Report $report, array $dane = []): TestResponse
    {
        return $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.restore', $report), $dane + [
                'reason_code' => 'autor_poprawil',
                'user_message' => 'Dziękujemy za poprawkę. Treść wróciła.',
            ]);
    }

    public function test_ukryty_opublikowany_przepis_wraca_opublikowany(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('basia');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $report = $this->zgloszenie('recipe', $recipe->getKey());

        $this->ukryj($moderator, $report);
        $this->assertSame(Recipe::STATUS_HIDDEN, $recipe->refresh()->status);

        $this->przywroc($moderator, $report)->assertRedirect(route('admin.reports'));

        $this->assertSame(Recipe::STATUS_PUBLISHED, $recipe->refresh()->status);
    }

    public function test_ukryty_szkic_po_przywroceniu_jest_dalej_szkicem(): void
    {
        // To jest sedno issue #65 punkt 3. Przywracanie „na sztywno do
        // published" byłoby wyciekiem: upubliczniałoby treść, której autor
        // nigdy nikomu nie pokazał, i to bez jego wiedzy.
        $moderator = $this->moderator();
        $autor = $this->user('basia');

        $recipe = Recipe::factory()->draft()->create(['author_id' => $autor->getKey()]);

        $report = $this->zgloszenie('recipe', $recipe->getKey());

        $this->ukryj($moderator, $report);
        $this->przywroc($moderator, $report);

        $this->assertSame(
            Recipe::STATUS_DRAFT,
            $recipe->refresh()->status,
            'Ukryty szkic po przywróceniu ma być szkicem — nie wolno go opublikować za autora.',
        );
    }

    public function test_ukryty_wpis_i_komentarz_tez_wracaja(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('basia');

        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $raportPosta = $this->zgloszenie('post', $post->getKey());
        $this->ukryj($moderator, $raportPosta);
        $this->przywroc($moderator, $raportPosta);
        $this->assertSame(Post::STATUS_PUBLISHED, $post->refresh()->status);

        $komentarz = Comment::factory()->create([
            'author_id' => $autor->getKey(),
            'post_id' => $post->getKey(),
        ]);
        $raportKomentarza = $this->zgloszenie('comment', $komentarz->getKey());
        $this->ukryj($moderator, $raportKomentarza);
        $this->assertSame(Comment::STATUS_HIDDEN, $komentarz->refresh()->status);

        $this->przywroc($moderator, $raportKomentarza);
        $this->assertSame(Comment::STATUS_PUBLISHED, $komentarz->refresh()->status);
    }

    public function test_przywrocenie_zostawia_slad_w_logu_moderacji(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('basia');

        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = $this->zgloszenie('post', $post->getKey());

        $this->ukryj($moderator, $report);
        $this->przywroc($moderator, $report);

        $slad = ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->first();

        $this->assertNotNull($slad, 'Cofnięcie kary też jest decyzją i musi być w logu.');
        $this->assertSame('post', $slad->target_type);
        $this->assertSame((string) $post->getKey(), (string) $slad->target_id);
        $this->assertSame((string) $moderator->getKey(), (string) $slad->moderator_id);
        $this->assertSame((string) $autor->getKey(), (string) $slad->subject_user_id);
        $this->assertSame('autor_poprawil', $slad->reason_code);
    }

    public function test_autor_dowiaduje_sie_o_przywroceniu(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('basia');

        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = $this->zgloszenie('post', $post->getKey());

        $this->ukryj($moderator, $report);
        $this->przywroc($moderator, $report);

        // Wiersz w bazie niczego nie dowodzi — liczy się to, co człowiek
        // naprawdę zobaczy na /powiadomienia.
        $this->actingAs($autor)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Twoja treść jest z powrotem na miejscu.')
            ->assertSee('Dziękujemy za poprawkę. Treść wróciła.');
    }

    public function test_powiadomienie_o_przywroceniu_nie_proponuje_odwolania(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('basia');

        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = $this->zgloszenie('post', $post->getKey());

        $this->ukryj($moderator, $report);
        $this->przywroc($moderator, $report);

        $ostatnie = Notification::where('user_id', $autor->getKey())
            ->get()
            ->first(fn (Notification $n): bool => ($n->data['decision'] ?? null) === ModerationAction::ACTION_UNHIDE);

        $this->assertNotNull($ostatnie);

        // „Jeśli uważasz, że to pomyłka, możesz się odwołać" pod wiadomością
        // o zdjęciu ukrycia brzmi jak groźba, nie jak dobra wiadomość.
        $this->assertFalse($ostatnie->data['appeal']);
    }

    public function test_tresc_ukryta_bez_zapisanego_stanu_wraca_jako_szkic(): void
    {
        // Tak wyglądają treści ukryte PRZED migracją
        // `..._add_context_to_moderation_actions` oraz ukryte ręcznie w psql
        // podczas incydentu. Nie wiemy, czym były — więc wybieramy stan mniej
        // widoczny. Pomyłkę w stronę szkicu autor cofa jednym kliknięciem;
        // pomyłki w drugą stronę nie cofnie nikt.
        $moderator = $this->moderator();
        $autor = $this->user('basia');

        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $post->forceFill(['status' => Post::STATUS_HIDDEN])->save();

        $report = $this->zgloszenie('post', $post->getKey());
        $report->update(['status' => Report::STATUS_RESOLVED]);

        ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'report_id' => $report->getKey(),
            'target_type' => 'post',
            'target_id' => $post->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'stary_wpis',
            // Kolumny previous_status wtedy nie było.
            'previous_status' => null,
        ]);

        $this->przywroc($moderator, $report);

        $this->assertSame(Post::STATUS_DRAFT, $post->refresh()->status);
    }

    public function test_nie_da_sie_przywrocic_tresci_ktora_jest_widoczna(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('basia');

        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = $this->zgloszenie('post', $post->getKey());
        $report->update(['status' => Report::STATUS_RESOLVED]);

        $this->przywroc($moderator, $report)
            ->assertRedirect(route('admin.reports'))
            ->assertSessionHasErrors('reason_code');

        $this->assertSame(Post::STATUS_PUBLISHED, $post->refresh()->status);
    }

    public function test_przywracanie_wymaga_powodu(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('basia');

        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = $this->zgloszenie('post', $post->getKey());
        $this->ukryj($moderator, $report);

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.restore', $report), ['reason_code' => ''])
            ->assertSessionHasErrors('reason_code');

        $this->assertSame(Post::STATUS_HIDDEN, $post->refresh()->status);
    }

    public function test_zwykly_uzytkownik_nie_przywraca_niczego(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('basia');

        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = $this->zgloszenie('post', $post->getKey());
        $this->ukryj($moderator, $report);

        // 404, nie 403 — panel moderacji nie potwierdza, że istnieje
        // (EnsureUserIsModerator).
        $this->actingAs($autor)
            ->post(route('admin.reports.restore', $report), ['reason_code' => 'bo_tak'])
            ->assertNotFound();

        $this->assertSame(Post::STATUS_HIDDEN, $post->refresh()->status);
    }

    public function test_kolejka_moderacji_pokazuje_przycisk_tylko_przy_ukrytej_tresci(): void
    {
        $moderator = $this->moderator();
        $autor = $this->user('basia');

        $post = Post::factory()->create(['author_id' => $autor->getKey()]);
        $report = $this->zgloszenie('post', $post->getKey());
        $this->ukryj($moderator, $report);

        $this->actingAs($moderator)
            ->get(route('admin.reports', ['status' => 'resolved']))
            ->assertOk()
            ->assertSee('Przywróć treść');

        $this->przywroc($moderator, $report);

        $this->actingAs($moderator)
            ->get(route('admin.reports', ['status' => 'resolved']))
            ->assertOk()
            ->assertDontSee('Przywróć treść');
    }

    public function test_ugotowalem_nie_ma_juz_decyzji_ukryj_bo_ona_nic_nie_robila(): void
    {
        // Znalezione przy #65: `cooked_events` nie ma kolumny `status`, więc
        // „Ukryj treść" na wykonaniu przechodziło walidację, zamykało
        // zgłoszenie jako rozstrzygnięte i wysyłało autorowi „ukryliśmy Twoją
        // treść" — a wykonanie stało w serwisie dalej. Moderator był
        // przekonany, że coś zrobił.
        $this->assertNotContains(
            ModerationAction::ACTION_HIDE,
            ModerationAction::DOZWOLONE['cooked_event'],
        );

        $moderator = $this->moderator();
        $autor = $this->user('basia');
        $recipe = Recipe::factory()->create(['author_id' => $this->user()->getKey()]);
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $autor->getKey(),
            'recipe_id' => $recipe->getKey(),
        ]);

        $report = $this->zgloszenie('cooked_event', $wykonanie->getKey());

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'spam',
            ])
            ->assertSessionHasErrors('action');

        $this->assertSame(Report::STATUS_OPEN, $report->refresh()->status);
    }
}

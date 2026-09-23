<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Nikt nie rozstrzyga własnej sprawy i nikt nie karze konta o równej lub
 * wyższej roli (issue #1408, D-244).
 *
 * PRZED POPRAWKĄ `ModerationController::decide()` pytał wyłącznie
 * `authorize('moderate', User::class)`. Jeden moderator mógł więc zgłosić
 * administratora, sam to zgłoszenie rozstrzygnąć i zbanować go — ban
 * unieważnia sesje. Razem z #1016 dawało to drogę do odcięcia wszystkich
 * administratorów.
 *
 * Każdy test odmowy sprawdza CAŁY brak skutku: konto celu nadal aktywne,
 * zgłoszenie nadal otwarte, brak wpisu w `moderation_actions` i brak wpisu
 * `moderation.decided` w dzienniku. Sama odpowiedź z błędem nie wystarcza —
 * odmowa zapisana obok wykonanej sankcji byłaby gorsza od braku odmowy.
 */
class ModeratorNieJestSedziaWeWlasnejSprawieTest extends TestCase
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

    /**
     * @param  array<string, string>  $pola
     */
    private function rozstrzygnij(User $aktor, Report $report, array $pola): TestResponse
    {
        return $this->actingAs($aktor)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), $pola + ['reason_code' => 'nekanie']);
    }

    private function assertBezSkutku(Report $report, User $cel): void
    {
        $this->assertSame(User::STATUS_ACTIVE, $cel->refresh()->status, 'Konto celu straciło dostęp mimo odmowy.');
        $this->assertSame(Report::STATUS_OPEN, $report->refresh()->status, 'Odmowa zamknęła zgłoszenie.');
        $this->assertNull($report->resolved_by);
        $this->assertSame(0, ModerationAction::where('report_id', $report->getKey())->count(), 'Odmowa zostawiła decyzję w logu moderacji.');
        $this->assertSame(0, AuditLogEntry::where('action', 'moderation.decided')->count(), 'Odmowa zostawiła wpis „moderation.decided".');
    }

    public function test_moderator_nie_rozstrzyga_wlasnego_zgloszenia(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $basia->getKey(), 'visibility' => 'public']);

        $report = $this->zgloszenie($moderator, 'post', $wpis->getKey());

        $this->rozstrzygnij($moderator, $report, ['action' => ModerationAction::ACTION_HIDE])
            ->assertSessionHasErrors('action');

        $this->assertNotSame(Post::STATUS_HIDDEN, $wpis->refresh()->status, 'Treść ukryto mimo odmowy.');
        $this->assertBezSkutku($report, $basia);
    }

    public function test_moderator_nie_zamyka_wlasnego_zgloszenia_nawet_bez_dzialania(): void
    {
        // „Bez działania" też jest rozstrzygnięciem: zamyka sprawę jako
        // odrzuconą i wysyła odpowiedź zgłaszającemu — czyli samemu sobie.
        $moderator = $this->moderator();
        $basia = $this->user('basia');

        $report = $this->zgloszenie($moderator, 'user', $basia->getKey());

        $this->rozstrzygnij($moderator, $report, ['action' => ModerationAction::ACTION_NONE])
            ->assertSessionHasErrors('action');

        $this->assertBezSkutku($report, $basia);
    }

    public function test_administrator_tez_nie_rozstrzyga_wlasnego_zgloszenia(): void
    {
        $admin = $this->admin();
        $basia = $this->user('basia');

        $report = $this->zgloszenie($admin, 'user', $basia->getKey());

        $this->rozstrzygnij($admin, $report, ['action' => ModerationAction::ACTION_BAN])
            ->assertSessionHasErrors('action');

        $this->assertBezSkutku($report, $basia);
    }

    public function test_moderator_nie_banuje_administratora(): void
    {
        $moderator = $this->moderator();
        $admin = $this->admin();
        $zglaszajaca = $this->user('zglaszajaca');

        // Zgłoszenie od KOGOŚ INNEGO — to jest osobna reguła od „własnej
        // sprawy" i musi działać bez niej.
        $report = $this->zgloszenie($zglaszajaca, 'user', $admin->getKey());

        $this->rozstrzygnij($moderator, $report, ['action' => ModerationAction::ACTION_BAN])
            ->assertSessionHasErrors('action');

        $this->assertBezSkutku($report, $admin);
    }

    public function test_moderator_nie_zawiesza_administratora_przez_zgloszony_wpis(): void
    {
        // Sankcja na koncie może przyjść także ze zgłoszenia TREŚCI — cel
        // kary to wtedy autor (`ModeratedContent::osoba()`).
        $moderator = $this->moderator();
        $admin = $this->admin();
        $zglaszajaca = $this->user('zglaszajaca');
        $wpis = Post::factory()->create(['author_id' => $admin->getKey(), 'visibility' => 'public']);

        $report = $this->zgloszenie($zglaszajaca, 'post', $wpis->getKey());

        $this->rozstrzygnij($moderator, $report, [
            'action' => ModerationAction::ACTION_SUSPEND,
            'suspend_days' => '7',
        ])->assertSessionHasErrors('action');

        $this->assertBezSkutku($report, $admin);
    }

    public function test_moderator_nie_banuje_innego_moderatora(): void
    {
        $moderator = $this->moderator();
        $drugi = $this->moderator();
        $zglaszajaca = $this->user('zglaszajaca');

        $report = $this->zgloszenie($zglaszajaca, 'user', $drugi->getKey());

        $this->rozstrzygnij($moderator, $report, ['action' => ModerationAction::ACTION_BAN])
            ->assertSessionHasErrors('action');

        $this->assertBezSkutku($report, $drugi);
    }

    public function test_moderator_moze_ukryc_tresc_administratora(): void
    {
        // Reguła rangi dotyczy KARY NA KONCIE, nie oceny treści. Wpis
        // administratora łamiący zasady da się ukryć jak każdy inny.
        $moderator = $this->moderator();
        $admin = $this->admin();
        $zglaszajaca = $this->user('zglaszajaca');
        $wpis = Post::factory()->create(['author_id' => $admin->getKey(), 'visibility' => 'public']);

        $report = $this->zgloszenie($zglaszajaca, 'post', $wpis->getKey());

        $this->rozstrzygnij($moderator, $report, ['action' => ModerationAction::ACTION_HIDE])
            ->assertSessionHasNoErrors();

        $this->assertSame(Post::STATUS_HIDDEN, $wpis->refresh()->status);
        $this->assertSame(Report::STATUS_RESOLVED, $report->refresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $admin->refresh()->status);
    }

    public function test_kontrola_dodatnia_moderator_rozstrzyga_cudze_zgloszenie_wobec_zwyklego_uzytkownika(): void
    {
        $moderator = $this->moderator();
        $basia = $this->user('basia');
        $zglaszajaca = $this->user('zglaszajaca');

        $report = $this->zgloszenie($zglaszajaca, 'user', $basia->getKey());

        $this->rozstrzygnij($moderator, $report, ['action' => ModerationAction::ACTION_BAN])
            ->assertSessionHasNoErrors();

        $this->assertSame(User::STATUS_BANNED, $basia->refresh()->status);
        $this->assertSame(Report::STATUS_RESOLVED, $report->refresh()->status);
        $this->assertSame($moderator->getKey(), $report->resolved_by);
        $this->assertSame(1, ModerationAction::where('report_id', $report->getKey())->count());
    }

    public function test_kontrola_dodatnia_administrator_zawiesza_moderatora(): void
    {
        $admin = $this->admin();
        $moderator = $this->moderator();
        $zglaszajaca = $this->user('zglaszajaca');

        $report = $this->zgloszenie($zglaszajaca, 'user', $moderator->getKey());

        $this->rozstrzygnij($admin, $report, [
            'action' => ModerationAction::ACTION_SUSPEND,
            'suspend_days' => '7',
        ])->assertSessionHasNoErrors();

        $this->assertSame(User::STATUS_SUSPENDED, $moderator->refresh()->status);
        $this->assertSame(Report::STATUS_RESOLVED, $report->refresh()->status);
    }
}

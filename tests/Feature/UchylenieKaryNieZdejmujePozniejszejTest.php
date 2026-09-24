<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\ResolveAppeal;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Uchylenie starej kary nie zdejmuje późniejszej, niezależnej (#933).
 *
 * Odtworzenie z issue: zawieszenie A, odwołanie od A, ban B z osobnego
 * zgłoszenia, uznanie odwołania od A. Do 24.09.2026 konto wracało do
 * `active`, choć ban B dalej figurował w rejestrze decyzji — administrator
 * uchylał decyzję A, a aplikacja zdejmowała skutki decyzji B, której nikt
 * nie rozpatrywał.
 *
 * Kontrola ujemna (24.09.2026): po przywróceniu w `ResolveAppeal::cofnij()`
 * bezwarunkowego `$decyzja->subject?->reinstate()` oblewa sześć z siedmiu
 * testów — przechodzi tylko kontrola dodatnia (jedyna kara wraca).
 * Równoległą nową decyzję mierzy
 * `tests/Dwa/UchylenieKaryPrzyRownoleglejNowejKarzeTest.php`.
 */
class UchylenieKaryNieZdejmujePozniejszejTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin();
        $this->autor = $this->user('basia');
    }

    public function test_uchylenie_zawieszenia_nie_zdejmuje_pozniejszego_bana(): void
    {
        $zawieszenie = $this->kara(ModerationAction::ACTION_SUSPEND, '7');
        $odwolanie = $this->odwolanie($zawieszenie);
        $this->kara(ModerationAction::ACTION_BAN);

        $this->assertSame(User::STATUS_BANNED, $this->autor->fresh()->status);

        $this->uznaj($odwolanie);

        $this->assertSame(Appeal::STATUS_OVERTURNED, $odwolanie->fresh()->status);
        $this->assertSame(User::STATUS_BANNED, $this->autor->fresh()->status, 'Uchylenie zawieszenia zdjęło późniejszy ban.');

        // Człowiek ma wiedzieć, że cofnięcie decyzji to nie odblokowanie.
        $odpowiedz = Notification::query()
            ->where('user_id', $this->autor->getKey())
            ->where('data->decision', 'appeal.overturned')
            ->sole();
        $this->assertStringContainsString(ResolveAppeal::KONTO_ZOSTAJE_ZABLOKOWANE, (string) $odpowiedz->data['message']);
    }

    public function test_uchylenie_krotszego_zawieszenia_zostawia_pozniejsze_z_jego_terminem(): void
    {
        $pierwsze = $this->kara(ModerationAction::ACTION_SUSPEND, '7');
        $odwolanie = $this->odwolanie($pierwsze);
        $this->kara(ModerationAction::ACTION_SUSPEND, '30');

        $termin = $this->autor->fresh()->status_expires_at;
        $this->assertNotNull($termin);

        $this->uznaj($odwolanie);

        $konto = $this->autor->fresh();
        $this->assertSame(User::STATUS_SUSPENDED, $konto->status);
        $this->assertTrue($termin->equalTo($konto->status_expires_at), 'Uchylenie pierwszego zawieszenia zmieniło termin drugiego.');
    }

    public function test_uchylenie_bana_nie_zdejmuje_pozniejszego_zawieszenia(): void
    {
        $ban = $this->kara(ModerationAction::ACTION_BAN);
        $odwolanie = $this->odwolanie($ban);
        $this->kara(ModerationAction::ACTION_SUSPEND, '30');

        $this->uznaj($odwolanie);

        $this->assertSame(User::STATUS_SUSPENDED, $this->autor->fresh()->status);
    }

    public function test_uchylenie_jedynej_kary_przywraca_konto(): void
    {
        // KONTROLA DODATNIA: bez późniejszej kary konto naprawdę wraca.
        $ban = $this->kara(ModerationAction::ACTION_BAN);
        $odwolanie = $this->odwolanie($ban);

        $this->uznaj($odwolanie);

        $this->assertSame(User::STATUS_ACTIVE, $this->autor->fresh()->status);
        $odpowiedz = Notification::query()
            ->where('user_id', $this->autor->getKey())
            ->where('data->decision', 'appeal.overturned')
            ->sole();
        $this->assertStringNotContainsString('pozostaje', (string) $odpowiedz->data['message']);
    }

    public function test_uchylenie_pozniejszej_kary_gdy_starsza_juz_cofnieta_przywraca_konto(): void
    {
        // Obie kary uchylone po kolei — po drugim uchyleniu nic już konta nie trzyma.
        $zawieszenie = $this->kara(ModerationAction::ACTION_SUSPEND, '7');
        $odwolanieA = $this->odwolanie($zawieszenie);
        $ban = $this->kara(ModerationAction::ACTION_BAN);
        $odwolanieB = $this->odwolanie($ban);

        $this->uznaj($odwolanieA);
        $this->assertSame(User::STATUS_BANNED, $this->autor->fresh()->status);

        $this->uznaj($odwolanieB);
        $this->assertSame(User::STATUS_ACTIVE, $this->autor->fresh()->status);
    }

    public function test_uchylenie_bana_nie_wskrzesza_konta_w_trakcie_usuwania(): void
    {
        $ban = $this->kara(ModerationAction::ACTION_BAN);
        $odwolanie = $this->odwolanie($ban);
        $this->autor->fresh()->markForDeletion();

        $this->uznaj($odwolanie);

        $this->assertSame(Appeal::STATUS_OVERTURNED, $odwolanie->fresh()->status);
        $this->assertSame(User::STATUS_PENDING_DELETE, $this->autor->fresh()->status);
    }

    public function test_uchylenie_bana_nie_wskrzesza_konta_wymazanego(): void
    {
        $ban = $this->kara(ModerationAction::ACTION_BAN);
        $odwolanie = $this->odwolanie($ban);
        $this->autor->fresh()->markDataErased();

        $this->uznaj($odwolanie);

        $this->assertSame(User::STATUS_ERASED, $this->autor->fresh()->status);
    }

    /** Kara przez prawdziwy formularz decyzji — tak jak w odtworzeniu z issue. */
    private function kara(string $akcja, ?string $dni = null): ModerationAction
    {
        $wpis = Post::factory()->create(['author_id' => $this->autor->getKey()]);
        $zgloszenie = Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'harassment',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($this->admin)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $zgloszenie), array_filter([
                'action' => $akcja,
                'reason_code' => 'harassment',
                'suspend_days' => $dni,
                'user_message' => 'Komentarze naruszały zasadę szacunku.',
            ]))
            ->assertSessionHasNoErrors();

        return ModerationAction::query()->where('report_id', $zgloszenie->getKey())->sole();
    }

    private function odwolanie(ModerationAction $decyzja): Appeal
    {
        return Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $this->autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'To nie były moje komentarze.',
            'status' => Appeal::STATUS_OPEN,
        ]);
    }

    private function uznaj(Appeal $odwolanie): void
    {
        $this->actingAs($this->admin)
            ->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Pierwsze zawieszenie było niezasadne.',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.appeals'));
    }
}

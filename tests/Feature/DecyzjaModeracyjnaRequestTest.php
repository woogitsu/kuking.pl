<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\RozstrzygnijZgloszenie;
use App\Http\Requests\Moderation\DecyzjaModeracyjnaRequest;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Policies\ReportPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Decyzja moderacyjna po wyjęciu z kontrolera (issue #970, krok 2):
 * wejście w `DecyzjaModeracyjnaRequest`, skutki w `RozstrzygnijZgloszenie`.
 *
 * Najważniejsze jest to, czego przeniesienie NIE MIAŁO zmienić: kolejność
 * sprawdzeń. FormRequest waliduje się, zanim ruszy ciało kontrolera, więc bez
 * pilnowania tej kolejności formularz z błędem dostawałby komunikaty pól tam,
 * gdzie wcześniej dostawał odmowę albo „już rozstrzygnięte".
 */
class DecyzjaModeracyjnaRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function zgloszenie(?User $zglaszajacy = null): Report
    {
        $wpis = Post::factory()->create(['author_id' => $this->user()->getKey(), 'visibility' => 'public']);

        return Report::create([
            'reporter_id' => ($zglaszajacy ?? $this->user())->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
        ]);
    }

    public function test_rozstrzygniete_zgloszenie_z_blednym_formularzem_mowi_ze_jest_rozstrzygniete(): void
    {
        $report = $this->zgloszenie();
        $report->update([
            'status' => Report::STATUS_RESOLVED,
            'resolved_by' => $this->moderator()->getKey(),
            'resolved_at' => now(),
        ]);

        $odpowiedz = $this->actingAs($this->moderator())
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), ['action' => '', 'note' => 'Moja notatka 970']);

        $odpowiedz->assertRedirect(route('admin.reports'))
            ->assertSessionHasErrors(['action' => DecyzjaModeracyjnaRequest::JUZ_ROZSTRZYGNIETE])
            ->assertSessionDoesntHaveErrors('reason_code');

        // Ta gałąź nigdy nie oddawała wpisanych danych — i dalej nie oddaje.
        $this->assertNull(session()->getOldInput('note'));
    }

    public function test_wlasna_sprawa_wraca_z_odmowa_i_z_wpisanymi_danymi_przed_regulami_pol(): void
    {
        $moderator = $this->moderator();
        $report = $this->zgloszenie($moderator);

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), ['action' => '', 'note' => 'Moja notatka 970'])
            ->assertRedirect(route('admin.reports'))
            ->assertSessionHasErrors(['action' => ReportPolicy::WLASNE_ZGLOSZENIE])
            ->assertSessionDoesntHaveErrors('reason_code')
            ->assertSessionHasInput('note', 'Moja notatka 970');
    }

    public function test_bledy_pol_i_brak_terminu_zawieszenia_wracaja_razem_z_danymi(): void
    {
        $report = $this->zgloszenie();

        $this->actingAs($this->moderator())
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_SUSPEND,
                'suspend_days' => 'brak',
                'note' => 'Moja notatka 970',
            ])
            ->assertRedirect(route('admin.reports'))
            ->assertSessionHasErrors([
                'reason_code' => 'Wybierz podstawę decyzji — autor treści zobaczy ją w powiadomieniu.',
                'suspend_days' => 'Przy decyzji „Zawieś konto" zaznacz jeszcze, na jak długo. '
                    .'„Bez zawieszenia" znaczy, że kary nie ma.',
            ])
            ->assertSessionHasInput('note', 'Moja notatka 970');

        $this->assertSame(Report::STATUS_OPEN, $report->refresh()->status);
    }

    public function test_wlasny_termin_ma_zakres_tylko_gdy_jest_uzywany(): void
    {
        $report = $this->zgloszenie();
        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_SUSPEND,
                'suspend_days' => 'wlasny',
                'suspend_days_custom' => '999',
                'reason_code' => 'spam',
            ])
            ->assertSessionHasErrors('suspend_days_custom');

        // Kontrola dodatnia: ta sama liczba przy ostrzeżeniu nie zatrzymuje decyzji.
        $this->actingAs($moderator)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_WARN,
                'suspend_days' => 'wlasny',
                'suspend_days_custom' => '999',
                'reason_code' => 'spam',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Decyzja zapisana.');

        $this->assertSame(Report::STATUS_RESOLVED, $report->refresh()->status);
    }

    public function test_akcja_rozstrzyga_raz_a_spozniona_decyzja_dostaje_null(): void
    {
        $moderator = $this->moderator();
        $report = $this->zgloszenie();
        $akcja = app(RozstrzygnijZgloszenie::class);

        $pierwsza = $akcja->handle($moderator, $report, [
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam',
            'note' => 'Pierwsza',
        ], '203.0.113.9');

        $this->assertInstanceOf(ModerationAction::class, $pierwsza);
        $this->assertSame(Report::STATUS_RESOLVED, $report->refresh()->status);
        $this->assertSame(1, AuditLogEntry::where('action', 'moderation.decided')->count());

        // Ten sam, nieodświeżony obiekt — jak druga karta przeglądarki.
        $druga = $akcja->handle($moderator, $report->setAttribute('status', Report::STATUS_OPEN), [
            'action' => ModerationAction::ACTION_REMOVE,
            'reason_code' => 'spam',
            'note' => 'Druga',
        ], '203.0.113.9');

        $this->assertNull($druga);
        $this->assertSame(1, ModerationAction::where('report_id', $report->getKey())->count());
        $this->assertSame('Pierwsza', $report->refresh()->resolution_note);
    }
}

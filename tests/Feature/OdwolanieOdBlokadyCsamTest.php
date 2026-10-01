<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\ResolveAppeal;
use App\Domain\Moderation\PodstawaDecyzji;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Models\ZabezpieczenieDowodu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Odwołanie od blokady powstałej przy zabezpieczeniu dowodu CSAM (#2427). */
class OdwolanieOdBlokadyCsamTest extends TestCase
{
    use RefreshDatabase;

    public function test_panel_odmawia_uznania_odwolania_od_blokady_powiazanej_z_dowodem(): void
    {
        Queue::fake();
        $moderator = $this->moderator();
        $admin = $this->admin();
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $this->actingAs($moderator)->post(
            route('admin.csam.store', ['typ' => 'post', 'id' => $wpis->getKey()]),
            ['potwierdzam' => '1'],
        )->assertRedirectContains('/csam/wynik/');

        $decyzjaTresci = ModerationAction::query()
            ->where('target_type', 'post')->where('target_id', $wpis->getKey())->sole();
        $blokada = ModerationAction::query()
            ->where('target_type', 'user')->where('target_id', $autor->getKey())
            ->where('action', ModerationAction::ACTION_BAN)->sole();
        $dowod = ZabezpieczenieDowodu::query()
            ->where('target_type', 'post')->where('target_id', $wpis->getKey())->sole();

        $this->assertSame($decyzjaTresci->getKey(), $dowod->moderation_action_id);
        $this->assertSame($autor->getKey(), $dowod->subject_user_id);
        $this->assertStringContainsString((string) $decyzjaTresci->getKey(), (string) $blokada->note);
        $this->assertSame(User::STATUS_BANNED, $autor->refresh()->status);

        $odwolanie = $this->odwolanie($autor, $blokada);
        $odpowiedz = $this->actingAs($admin)->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Sprawdziłem odwołanie i cofam blokadę konta.',
            ])
            ->assertRedirect(route('admin.appeals'));

        $this->assertSame(Appeal::STATUS_OPEN, $odwolanie->refresh()->status, 'CSAM_BAN_APPEAL_MUST_STAY_OPEN');
        $odpowiedz->assertSessionHasErrors(['outcome' => ResolveAppeal::BLOKADA_Z_DOWODEM]);

        $this->assertSame(Appeal::STATUS_OPEN, $odwolanie->refresh()->status);
        $this->assertNull($odwolanie->decided_at);
        $this->assertSame(User::STATUS_BANNED, $autor->refresh()->status);
        $this->assertSoftDeleted($wpis);
        $this->assertDatabaseHas('zabezpieczenia_dowodow', ['id' => $dowod->getKey()]);
        $this->assertSame(0, AuditLogEntry::query()->where('action', 'appeal.resolved')->count());
    }

    public function test_zwykla_blokade_mozna_cofnac_mimo_istnienia_innego_dowodu(): void
    {
        Queue::fake();
        $moderator = $this->moderator();
        $admin = $this->admin();
        $autorDowodu = $this->user('autor_dowodu');
        $dowodowyWpis = Post::factory()->create(['author_id' => $autorDowodu->getKey()]);

        $this->actingAs($moderator)->post(
            route('admin.csam.store', ['typ' => 'post', 'id' => $dowodowyWpis->getKey()]),
            ['potwierdzam' => '1'],
        )->assertRedirectContains('/csam/wynik/');
        $this->assertSame(1, ZabezpieczenieDowodu::query()->where('target_type', 'post')->count());

        $autor = $this->user('zwykly_autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zgloszenie = Report::create([
            'reporter_id' => $this->user('zglaszajacy')->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'copyright',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($moderator)->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_BAN,
                'reason_code' => 'spam',
                'user_message' => 'Wpis zawiera reklamę; blokujemy konto.',
            ])->assertSessionHasNoErrors();

        $blokada = ModerationAction::query()->where('report_id', $zgloszenie->getKey())->sole();
        $this->assertSame(User::STATUS_BANNED, $autor->refresh()->status);
        $odwolanie = $this->odwolanie($autor, $blokada);

        $this->actingAs($admin)->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Sprawdziliśmy wpis; to nie była reklama.',
            ])
            ->assertRedirect(route('admin.appeals'))
            ->assertSessionHasNoErrors();

        $this->assertSame(Appeal::STATUS_OVERTURNED, $odwolanie->refresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $autor->refresh()->status);
        $this->assertSame(User::STATUS_BANNED, $autorDowodu->refresh()->status);
    }

    public function test_cudza_decyzja_o_dowodzie_w_notatce_nie_blokuje_zwyklego_odwolania(): void
    {
        Queue::fake();
        $moderator = $this->moderator();
        $admin = $this->admin();
        $autorDowodu = $this->user('autor_dowodu');
        $wpisDowodowy = Post::factory()->create(['author_id' => $autorDowodu->getKey()]);

        $this->actingAs($moderator)->post(
            route('admin.csam.store', ['typ' => 'post', 'id' => $wpisDowodowy->getKey()]),
            ['potwierdzam' => '1'],
        )->assertRedirectContains('/csam/wynik/');
        $dowod = ZabezpieczenieDowodu::query()->where('target_id', $wpisDowodowy->getKey())->sole();

        $autor = $this->user('autor_zwyklej_decyzji');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zgloszenie = Report::create([
            'reporter_id' => $this->user('zglaszajacy_falszywa_notatka')->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'copyright',
            'status' => Report::STATUS_OPEN,
        ]);
        $notatka = 'Blokada razem z zabezpieczeniem dowodu (decyzja '.$dowod->moderation_action_id.').';

        $this->actingAs($moderator)->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_BAN,
                'reason_code' => PodstawaDecyzji::KRZYWDZENIE_DZIECI,
                'note' => $notatka,
                'user_message' => 'Blokujemy konto po rozpatrzeniu zgłoszenia.',
            ])->assertSessionHasNoErrors();

        $blokada = ModerationAction::query()->where('report_id', $zgloszenie->getKey())->sole();
        $this->assertSame($notatka, $blokada->note);
        $this->assertSame($autorDowodu->getKey(), $dowod->subject_user_id);
        $this->assertSame(User::STATUS_BANNED, $autor->refresh()->status);
        $odwolanie = $this->odwolanie($autor, $blokada);

        $this->actingAs($admin)->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Dowód dotyczy innego konta, więc cofamy tę decyzję.',
            ])
            ->assertRedirect(route('admin.appeals'))
            ->assertSessionHasNoErrors();

        $this->assertSame(Appeal::STATUS_OVERTURNED, $odwolanie->refresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $autor->refresh()->status);
        $this->assertSame(User::STATUS_BANNED, $autorDowodu->refresh()->status);
        $this->assertDatabaseHas('zabezpieczenia_dowodow', ['id' => $dowod->getKey()]);
    }

    public function test_pozniejszy_ban_csam_pozostaje_po_uznaniu_odwolania_od_starego_bana(): void
    {
        Queue::fake();
        $moderator = $this->moderator();
        $admin = $this->admin();
        $autor = $this->user('autor_z_dwoma_banami');
        $wpisZgloszony = Post::factory()->create(['author_id' => $autor->getKey()]);
        $wpisDowodowy = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zgloszenie = Report::create([
            'reporter_id' => $this->user('zglaszajacy_dwa_bany')->getKey(),
            'target_type' => 'post',
            'target_id' => $wpisZgloszony->getKey(),
            'reason' => 'copyright',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($moderator)->from(route('admin.reports'))
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_BAN,
                'reason_code' => 'spam',
                'user_message' => 'Blokujemy konto po rozpatrzeniu zgłoszenia.',
            ])->assertSessionHasNoErrors();
        $zwyklaBlokada = ModerationAction::query()->where('report_id', $zgloszenie->getKey())->sole();
        $this->assertSame(User::STATUS_BANNED, $autor->refresh()->status);

        $this->actingAs($moderator)->post(
            route('admin.csam.store', ['typ' => 'post', 'id' => $wpisDowodowy->getKey()]),
            ['potwierdzam' => '1'],
        )->assertRedirectContains('/csam/wynik/');

        $dowod = ZabezpieczenieDowodu::query()->where('target_id', $wpisDowodowy->getKey())->sole();
        $blokady = ModerationAction::query()->where('subject_user_id', $autor->getKey())
            ->where('action', ModerationAction::ACTION_BAN)->get();
        $this->assertCount(2, $blokady);
        $blokadaCsam = $blokady->first(fn (ModerationAction $blokada): bool => $blokada->getKey() !== $zwyklaBlokada->getKey());
        $this->assertNotNull($blokadaCsam);
        $this->assertSame('user', $blokadaCsam->target_type);
        $this->assertSame(PodstawaDecyzji::KRZYWDZENIE_DZIECI, $blokadaCsam->reason_code);
        $this->assertStringContainsString((string) $dowod->moderation_action_id, (string) $blokadaCsam->note);
        $this->assertSame($autor->getKey(), $dowod->subject_user_id);

        $zwykleOdwolanie = $this->odwolanie($autor, $zwyklaBlokada);
        $this->actingAs($admin)->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $zwykleOdwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Cofamy wcześniejszą zwykłą blokadę.',
            ])
            ->assertRedirect(route('admin.appeals'))
            ->assertSessionHasNoErrors();
        $this->assertSame(Appeal::STATUS_OVERTURNED, $zwykleOdwolanie->refresh()->status);
        $this->assertSame(User::STATUS_BANNED, $autor->refresh()->status);

        $odwolanieCsam = $this->odwolanie($autor, $blokadaCsam);
        $odpowiedz = $this->actingAs($admin)->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanieCsam), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Proszę o cofnięcie blokady powiązanej z dowodem.',
            ])
            ->assertRedirect(route('admin.appeals'));
        $this->assertSame(Appeal::STATUS_OPEN, $odwolanieCsam->refresh()->status);
        $odpowiedz->assertSessionHasErrors(['outcome' => ResolveAppeal::BLOKADA_Z_DOWODEM]);
        $this->assertSame(User::STATUS_BANNED, $autor->refresh()->status);
        $this->assertDatabaseHas('zabezpieczenia_dowodow', ['id' => $dowod->getKey()]);
    }

    private function odwolanie(User $autor, ModerationAction $decyzja): Appeal
    {
        return Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'Uważam, że decyzja jest pomyłką.',
            'status' => Appeal::STATUS_OPEN,
        ]);
    }
}

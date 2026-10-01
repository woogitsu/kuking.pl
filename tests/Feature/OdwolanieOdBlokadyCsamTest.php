<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\ResolveAppeal;
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
        $this->actingAs($admin)->from(route('admin.appeals'))
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Sprawdziłem odwołanie i cofam blokadę konta.',
            ])
            ->assertRedirect(route('admin.appeals'))
            ->assertSessionHasErrors(['outcome' => ResolveAppeal::BLOKADA_Z_DOWODEM]);

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
        $autorDowodu = $this->user('autor-dowodu');
        $dowodowyWpis = Post::factory()->create(['author_id' => $autorDowodu->getKey()]);

        $this->actingAs($moderator)->post(
            route('admin.csam.store', ['typ' => 'post', 'id' => $dowodowyWpis->getKey()]),
            ['potwierdzam' => '1'],
        )->assertRedirectContains('/csam/wynik/');
        $this->assertSame(1, ZabezpieczenieDowodu::query()->where('target_type', 'post')->count());

        $autor = $this->user('zwykly-autor');
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

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\SygnalyController;
use App\Jobs\PrzeanalizujTresc;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Moderator nie zamyka oznaczeń automatu przy własnych treściach (audyt A5-11).
 *
 * CO BYŁO ZEPSUTE
 * `SygnalyController::odrzucGrupe()` sprawdzał tylko rolę. Moderator, którego
 * wpis automat oznaczył, wysyłał `POST /admin/sygnaly/odrzuc` z `autor=<własne
 * UUID>` i zamykał całą grupę jako „to nic takiego", zanim zobaczył ją ktoś
 * inny z zespołu. Przy zgłoszeniach od ludzi ten sam konflikt blokuje
 * `ReportPolicy::decide` (`SPRAWA_O_CIEBIE`).
 *
 * Każda odmowa ma obok kontrolę dodatnią: tę samą grupę zamyka inny moderator
 * (`docs/PULAPKI_TESTOW.md` — odmowa dla wszystkich wyglądałaby identycznie).
 */
class SygnalyWlasnychTresciModeratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderator_nie_zamyka_oznaczen_wlasnych_tresci(): void
    {
        $moderator = $this->moderator();
        $this->oznaczonyWpis($moderator);

        $this->actingAs($moderator)
            ->from(route('admin.sygnaly'))
            ->post(route('admin.sygnaly.dismiss'), ['autor' => (string) $moderator->getKey()])
            ->assertRedirect(route('admin.sygnaly'))
            ->assertSessionHasErrors(['autor' => SygnalyController::WLASNE_OZNACZENIA]);

        $this->assertNicSieNieZmienilo();
    }

    /**
     * PostgreSQL porównuje UUID bez względu na wielkość liter, więc ten sam
     * identyfikator wielkimi literami trafia w te same wiersze. Porównanie
     * w kontrolerze nie może go przepuścić.
     */
    public function test_moderator_nie_obchodzi_odmowy_wielkimi_literami(): void
    {
        $moderator = $this->moderator();
        $this->oznaczonyWpis($moderator);

        $this->actingAs($moderator)
            ->from(route('admin.sygnaly'))
            ->post(route('admin.sygnaly.dismiss'), ['autor' => strtoupper((string) $moderator->getKey())])
            ->assertSessionHasErrors(['autor' => SygnalyController::WLASNE_OZNACZENIA]);

        $this->assertNicSieNieZmienilo();
    }

    /**
     * PostgreSQL przyjmuje ten sam UUID także bez myślników i w klamrach —
     * oba zapisy trafiają w te same wiersze, więc samo `strtolower` ich nie
     * zatrzyma. Kontroler przyjmuje wyłącznie postać kanoniczną.
     */
    public function test_moderator_nie_obchodzi_odmowy_innym_zapisem_uuid(): void
    {
        $moderator = $this->moderator();
        $this->oznaczonyWpis($moderator);
        $uuid = (string) $moderator->getKey();

        foreach ([str_replace('-', '', $uuid), '{'.$uuid.'}'] as $zapis) {
            $this->actingAs($moderator)
                ->from(route('admin.sygnaly'))
                ->post(route('admin.sygnaly.dismiss'), ['autor' => $zapis])
                ->assertSessionHasErrors('autor');

            $this->assertNicSieNieZmienilo();
        }
    }

    /** Kontrola dodatnia: tę samą grupę zamyka ktoś inny z moderacji. */
    public function test_inny_moderator_zamyka_te_oznaczenia(): void
    {
        $autor = $this->moderator();
        $inny = $this->moderator();
        $this->oznaczonyWpis($autor);

        $this->actingAs($inny)
            ->from(route('admin.sygnaly'))
            ->post(route('admin.sygnaly.dismiss'), ['autor' => (string) $autor->getKey()])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->where('status', Report::STATUS_REJECTED)
            ->where('resolved_by', $inny->getKey())
            ->count());
    }

    /** Bez martwego przycisku (AGENTS.md §5): autor widzi informację, inny moderator — przycisk. */
    public function test_przycisk_widzi_tylko_moderator_spoza_grupy(): void
    {
        $autor = $this->moderator();
        $inny = $this->moderator();
        $this->oznaczonyWpis($autor);

        $this->actingAs($autor)->get(route('admin.sygnaly'))
            ->assertOk()
            ->assertSee(SygnalyController::WLASNE_OZNACZENIA)
            ->assertDontSee(route('admin.sygnaly.dismiss'));

        $this->actingAs($inny)->get(route('admin.sygnaly'))
            ->assertOk()
            ->assertSee(route('admin.sygnaly.dismiss'))
            ->assertDontSee(SygnalyController::WLASNE_OZNACZENIA);
    }

    private function oznaczonyWpis(User $autor): void
    {
        $wpis = Post::create([
            'author_id' => $autor->getKey(),
            'body' => 'Ciasta na zamówienie, tel. 600 100 200.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        dispatch_sync(new PrzeanalizujTresc(PrzeanalizujTresc::TYP_WPIS, (string) $wpis->getKey()));

        // Kontrola, że jest co zamykać — inaczej odmowa i „nic do zamknięcia"
        // wyglądałyby tak samo.
        $this->assertSame(1, Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->where('status', Report::STATUS_OPEN)
            ->where('autor_tresci_id', $autor->getKey())
            ->count());
    }

    private function assertNicSieNieZmienilo(): void
    {
        $this->assertSame(1, Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->where('status', Report::STATUS_OPEN)
            ->count(), 'Odmowa zamknęła oznaczenie.');
        $this->assertSame(0, ModerationAction::query()->count(), 'Odmowa zapisała decyzję moderacyjną.');
    }
}

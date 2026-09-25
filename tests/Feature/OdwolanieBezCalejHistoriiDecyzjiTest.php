<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Formularz odwołania przed logowaniem wybiera najnowszą decyzję w terminie
 * BEZ wczytywania całej historii decyzji tej osoby (issue #999).
 *
 * Wcześniej `AppealController::ostatniaDecyzjaDoOdwolania()` robił `get()`
 * na wszystkich odwoływalnych decyzjach konta, a dopiero potem w PHP szukał
 * pierwszej w terminie. Konto z długą historią (albo zasypane decyzjami
 * automatycznymi) kosztowało tyle modeli, ile decyzji kiedykolwiek miało.
 */
class OdwolanieBezCalejHistoriiDecyzjiTest extends TestCase
{
    use RefreshDatabase;

    private ?User $moderator = null;

    private function decyzja(User $osoba, string $kiedy): ModerationAction
    {
        $this->moderator ??= $this->user(null, ['role' => User::ROLE_MODERATOR]);

        $decyzja = ModerationAction::create([
            'moderator_id' => $this->moderator->getKey(),
            'target_type' => 'user',
            'target_id' => $osoba->getKey(),
            'subject_user_id' => $osoba->getKey(),
            'action' => ModerationAction::ACTION_WARN,
            'reason_code' => 'spam',
            'user_message' => 'Linki reklamowe pod cudzymi przepisami.',
        ]);
        $decyzja->forceFill(['created_at' => $kiedy])->save();

        return $decyzja;
    }

    private function zlozOdwolanie(): TestResponse
    {
        return $this->from(route('appeals.guest'))->post(route('appeals.guest.store'), [
            'login' => 'odwolujaca',
            'password' => 'haslo-testowe-123',
            'body' => 'To nie były reklamy, tylko linki do przepisów mojej córki.',
        ]);
    }

    #[Test]
    public function test_wybiera_najnowsza_decyzje_i_nie_wczytuje_przedawnionej_historii(): void
    {
        $this->travelTo('2026-09-23 12:00:00');
        $osoba = $this->user('odwolujaca');

        foreach (range(1, 30) as $i) {
            $this->decyzja($osoba, sprintf('2024-%02d-%02d 10:00:00', ($i % 12) + 1, ($i % 27) + 1));
        }

        $starsza = $this->decyzja($osoba, '2026-09-01 10:00:00');
        $najnowsza = $this->decyzja($osoba, '2026-09-20 10:00:00');

        $wczytanych = 0;
        ModerationAction::retrieved(function () use (&$wczytanych): void {
            $wczytanych++;
        });

        $this->zlozOdwolanie()->assertSessionHasNoErrors()->assertRedirect(route('appeals.guest'));

        $this->assertSame((string) $najnowsza->getKey(), (string) Appeal::query()->sole()->moderation_action_id);
        $this->assertNotSame((string) $starsza->getKey(), (string) Appeal::query()->sole()->moderation_action_id);

        // Wybór decyzji wczytuje jedną — najnowszą. Reszta to odczyty
        // `FileAppeal`, który sam sprawdza decyzję pod blokadą. Przed
        // poprawką: 32 modele z samego wyboru.
        $this->assertLessThanOrEqual(3, $wczytanych, "Wczytano {$wczytanych} decyzji zamiast jednej-dwóch.");
    }

    #[Test]
    public function test_decyzja_z_konca_miesiaca_zostaje_w_terminie_mimo_przepelnienia_miesiecy(): void
    {
        // 31 sierpnia + 6 miesięcy = 3 marca (Carbon przepełnia miesiąc),
        // więc 2 marca termin jeszcze trwa. Odcięcie w SQL równo sześć
        // miesięcy wstecz (2 września) zgubiłoby tę decyzję.
        $this->travelTo('2027-03-02 12:00:00');
        $osoba = $this->user('odwolujaca');
        $decyzja = $this->decyzja($osoba, '2026-08-31 10:00:00');

        $this->assertTrue($decyzja->refresh()->isAppealable());

        $this->zlozOdwolanie()->assertSessionHasNoErrors();

        $this->assertSame((string) $decyzja->getKey(), (string) Appeal::query()->sole()->moderation_action_id);
    }

    #[Test]
    public function test_same_przedawnione_decyzje_daja_komunikat_zamiast_odwolania(): void
    {
        $this->travelTo('2026-09-23 12:00:00');
        $osoba = $this->user('odwolujaca');
        $this->decyzja($osoba, '2026-03-01 10:00:00');

        $this->zlozOdwolanie()->assertSessionHasErrors('login');

        $this->assertSame(0, Appeal::query()->count());
    }
}

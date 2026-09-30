<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Formularz odwołania przed logowaniem nie czyta całej historii konta (issue #999).
 *
 * CO SIĘ DZIAŁO
 * `AppealController::ostatniaDecyzjaDoOdwolania()` kończyło zapytanie na
 * `get()` i dopiero w PHP szukało pierwszej decyzji w terminie. Każde
 * wysłanie formularza z poprawnym hasłem hydratowało WSZYSTKIE odwoływalne
 * decyzje konta bez odwołania — także te sprzed lat.
 *
 * PUŁAPKA W NAPRAWIE
 * Issue proponowało samo `first()`, zakładając, że termin rośnie razem
 * z `created_at`. Nie rośnie: `addMonths(6)` przepełnia miesiąc, więc
 * decyzja z 31 sierpnia ma termin do 3 marca, a z 1 września — do 1 marca.
 * `test_na_przelomie_miesiecy_starsza_decyzja_w_terminie_nie_ginie` pilnuje,
 * żeby optymalizacja nie odebrała komuś prawa do odwołania.
 */
class FormularzOdwolaniaCzytaJednaDecyzjeTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'tajne-haslo-babci';

    public function test_przy_dlugiej_historii_formularz_hydratuje_jedna_decyzje_i_wybiera_najnowsza(): void
    {
        $osoba = $this->user('basia', ['password' => self::HASLO]);
        $moderator = $this->moderator();

        // Trzydzieści starszych decyzji w terminie i bez odwołania — każda
        // z nich byłaby przy starym `get()` osobnym modelem w pamięci.
        for ($i = 30; $i >= 1; $i--) {
            $this->decyzja($osoba, $moderator, now()->subDays($i));
        }
        $najnowsza = $this->decyzja($osoba, $moderator, now()->subHour());

        $ile = $this->policzHydratowaneDecyzjePrzyWyslaniu();

        // KONTROLA DODATNIA: odwołanie powstało i dotyczy NAJNOWSZEJ decyzji —
        // bez tego „jedna hydratacja" przeszłaby też przy formularzu, który
        // w ogóle nie znalazł decyzji.
        $this->assertSame((string) $najnowsza->getKey(), (string) Appeal::query()->sole()->moderation_action_id);

        // Jedna z formularza; `FileAppeal` może ją jeszcze raz przeczytać
        // pod blokadą — ale nie zależy to od długości historii.
        $this->assertLessThanOrEqual(2, $ile, "Formularz hydratował {$ile} decyzji przy 31 w historii konta.");
    }

    public function test_decyzja_z_odwolaniem_jest_pomijana(): void
    {
        $osoba = $this->user('basia', ['password' => self::HASLO]);
        $moderator = $this->moderator();

        $starsza = $this->decyzja($osoba, $moderator, now()->subDays(3));
        $zOdwolaniem = $this->decyzja($osoba, $moderator, now()->subDay());

        $this->wyslij()->assertRedirect(route('appeals.guest'));
        $this->assertSame((string) $zOdwolaniem->getKey(), (string) Appeal::query()->sole()->moderation_action_id);

        $this->wyslij()->assertRedirect(route('appeals.guest'));
        $this->assertDatabaseHas('appeals', ['moderation_action_id' => $starsza->getKey()]);
        $this->assertDatabaseCount('appeals', 2);
    }

    public function test_przeterminowana_historia_nie_jest_hydratowana_i_daje_ten_sam_komunikat(): void
    {
        $osoba = $this->user('basia', ['password' => self::HASLO]);
        $moderator = $this->moderator();

        for ($i = 0; $i < 30; $i++) {
            $this->decyzja($osoba, $moderator, now()->subMonths(7)->subDays($i));
        }

        $ile = $this->policzHydratowaneDecyzjePrzyWyslaniu(oczekujBledu: true);

        $this->assertDatabaseCount('appeals', 0);
        $this->assertSame(0, $ile, "Formularz hydratował {$ile} przeterminowanych decyzji.");
    }

    public function test_na_przelomie_miesiecy_starsza_decyzja_w_terminie_nie_ginie(): void
    {
        $osoba = $this->user('basia', ['password' => self::HASLO]);
        $moderator = $this->moderator();

        $this->travelTo(Carbon::parse('2027-03-02 12:00:00'));

        // 31.08 + 6 miesięcy = 03.03 (przepełnienie) — wciąż w terminie.
        $starsza = $this->decyzja($osoba, $moderator, Carbon::parse('2026-08-31 10:00:00'));
        // 01.09 + 6 miesięcy = 01.03 — po terminie, choć NOWSZA.
        $nowsza = $this->decyzja($osoba, $moderator, Carbon::parse('2026-09-01 10:00:00'));

        // KONTROLA: model sam tak to liczy — test nie opiera się na założeniu.
        $this->assertTrue($starsza->refresh()->isAppealable());
        $this->assertFalse($nowsza->refresh()->isAppealable());

        $this->wyslij()->assertRedirect(route('appeals.guest'));
        $this->assertSame((string) $starsza->getKey(), (string) Appeal::query()->sole()->moderation_action_id);
    }

    public function test_granica_terminu_zgodna_z_is_appealable(): void
    {
        $osoba = $this->user('basia', ['password' => self::HASLO]);
        $moderator = $this->moderator();
        $decyzja = $this->decyzja($osoba, $moderator, Carbon::parse('2026-03-10 10:00:00'));
        $termin = $decyzja->refresh()->appealDeadline();

        $this->travelTo($termin->copy());
        $this->assertFalse($decyzja->isAppealable());
        $this->wyslij()->assertSessionHasErrors('login');
        $this->assertDatabaseCount('appeals', 0);

        $this->travelTo($termin->copy()->subSecond());
        $this->assertTrue($decyzja->isAppealable());
        $this->wyslij()->assertRedirect(route('appeals.guest'));
        $this->assertDatabaseCount('appeals', 1);
    }

    // -----------------------------------------------------------------

    private function decyzja(User $osoba, User $moderator, Carbon $kiedy): ModerationAction
    {
        $wpis = Post::factory()->create(['author_id' => $osoba->getKey()]);

        $decyzja = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'subject_user_id' => $osoba->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam',
            'user_message' => 'Treść wyglądała na reklamę.',
        ]);
        $decyzja->forceFill(['created_at' => $kiedy])->save();

        return $decyzja;
    }

    private function wyslij(): TestResponse
    {
        return $this->from(route('appeals.guest'))->post(route('appeals.guest.store'), [
            'login' => 'basia',
            'password' => self::HASLO,
            'body' => 'To nie była reklama, tylko przepis mojej córki.',
        ]);
    }

    private function policzHydratowaneDecyzjePrzyWyslaniu(bool $oczekujBledu = false): int
    {
        $ile = 0;
        Event::listen('eloquent.retrieved: '.ModerationAction::class, function () use (&$ile): void {
            $ile++;
        });

        $odpowiedz = $this->wyslij();

        $oczekujBledu
            ? $odpowiedz->assertSessionHasErrors('login')
            : $odpowiedz->assertRedirect(route('appeals.guest'));

        return $ile;
    }
}

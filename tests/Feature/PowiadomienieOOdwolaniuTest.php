<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\FileAppeal;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NOWE ODWOŁANIE MUSI KOGOŚ OBUDZIĆ (zgłoszenie właściciela z 10 września,
 * DSA art. 20).
 *
 * CO SIĘ DZIAŁO: właściciel przeszedł całą ścieżkę na produkcji — ukrył
 * treść, dostał jako użytkownik powiadomienie o decyzji, złożył odwołanie —
 * i jako administrator nie dowiedział się o nim niczym. Odwołanie po prostu
 * leżało na `/admin/odwolania`. Odwołanie ma termin odpowiedzi
 * (`Appeal::responseDeadline()`), więc kolejka, o której nikt nie wie, że coś
 * w niej leży, to termin, który upływa po cichu.
 *
 * CZEGO TE TESTY PILNUJĄ
 *  1. powiadomienie powstaje dla ADMINISTRATORA (ten, kto może sprawę
 *     zamknąć — `UserPolicy::resolveAppeals()`, D-039);
 *  2. NIE powstaje dla zwykłego moderatora (wezwanie do czynności, której
 *     nie może wykonać) ani dla zwykłego użytkownika;
 *  3. przetrwa blokadę — osoba składająca odwołanie nie może wyciszyć
 *     zawiadomienia o własnym odwołaniu, blokując konto administratora.
 */
class PowiadomienieOOdwolaniuTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Decyzja o ukryciu przepisu, wydana przez podane konto moderujące.
     *
     * @return array{0: User, 1: ModerationAction}
     */
    private function ukrytyPrzepis(User $moderator): array
    {
        $autor = $this->user('basia');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $report = Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'recipe',
            'target_id' => $recipe->getKey(),
            'reason' => 'copyright',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $report), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'sexual',
                'user_message' => 'Przepis wygląda na skopiowany z bloga.',
            ]);

        return [$autor, ModerationAction::firstOrFail()];
    }

    private function ileZawiadomienONowymOdwolaniu(User $osoba): int
    {
        return Notification::query()
            ->where('user_id', $osoba->getKey())
            ->where('type', Notification::TYPE_APPEAL_FILED)
            ->count();
    }

    public function test_nowe_odwolanie_powiadamia_administratora(): void
    {
        $admin = $this->admin();
        [$autor, $decyzja] = $this->ukrytyPrzepis($admin);

        $this->actingAs($autor)->post(route('appeals.store', $decyzja), [
            'body' => 'To jest mój własny przepis mojej babci, nie skopiowałam go z żadnego bloga.',
        ]);

        $this->assertSame(
            1,
            $this->ileZawiadomienONowymOdwolaniu($admin),
            'Administrator musi dowiedzieć się, że w kolejce leży odwołanie z terminem.',
        );

        $zawiadomienie = Notification::query()
            ->where('type', Notification::TYPE_APPEAL_FILED)
            ->firstOrFail();

        // Termin jest treścią tego powiadomienia, nie ozdobą: bez niego
        // pozycja na liście nie różni się od „zajrzę tam kiedyś".
        $this->assertArrayHasKey('termin', $zawiadomienie->data);
        $this->assertNotSame('', (string) $zawiadomienie->data['termin']);

        // Prowadzi na kolejkę odwołań, a nie w pustkę.
        $this->assertSame(route('admin.appeals'), $zawiadomienie->adresDocelowy());
    }

    public function test_nowe_odwolanie_nie_powiadamia_zwyklego_moderatora_ani_uzytkownika(): void
    {
        $admin = $this->admin();
        $moderator = $this->moderator();
        $postronny = $this->user('postronna_osoba');

        [$autor, $decyzja] = $this->ukrytyPrzepis($admin);

        $this->actingAs($autor)->post(route('appeals.store', $decyzja), [
            'body' => 'To jest mój własny przepis mojej babci, nie skopiowałam go z żadnego bloga.',
        ]);

        $this->assertSame(
            0,
            $this->ileZawiadomienONowymOdwolaniu($moderator),
            'Moderator nie rozstrzyga odwołań (D-039) — powiadomienie byłoby wezwaniem '
            .'do czynności, której nie może wykonać.',
        );

        $this->assertSame(
            0,
            $this->ileZawiadomienONowymOdwolaniu($postronny),
            'Zwykły użytkownik nie ma nic wspólnego z kolejką moderacji.',
        );

        $this->assertSame(
            0,
            $this->ileZawiadomienONowymOdwolaniu($autor),
            'Osoba składająca odwołanie nie dostaje zawiadomienia o własnym odwołaniu.',
        );
    }

    public function test_blokada_konta_administratora_nie_wycisza_zawiadomienia(): void
    {
        $admin = $this->admin();
        [$autor, $decyzja] = $this->ukrytyPrzepis($admin);

        // Gdyby zawiadomienie szło ze `actor`, `NotifyUser` odmówiłoby jego
        // utworzenia z powodu blokady — i wystarczyłoby zablokować konto
        // administratora, żeby termin z art. 20 płynął w ciszy.
        $this->actingAs($autor)->post(route('social.block', $admin->profile->username));

        app(FileAppeal::class)->handle(
            $autor->refresh(),
            $decyzja,
            'To jest mój własny przepis mojej babci, nie skopiowałam go z żadnego bloga.',
        );

        $this->assertSame(
            1,
            $this->ileZawiadomienONowymOdwolaniu($admin),
            'Blokada między osobami nie może wyciszyć kolejki z terminem prawnym.',
        );
    }
}

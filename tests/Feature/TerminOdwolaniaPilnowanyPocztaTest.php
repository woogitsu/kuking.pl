<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\FileAppeal;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Notifications\TerminOdwolaniaBlisko;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * POCZTA IDZIE TYLKO WTEDY, GDY TERMIN Z DSA ART. 20 ZARAZ MINIE (D-058).
 *
 * DLACZEGO TO JEST OSOBNA RZECZ OD POWIADOMIENIA W PANELU
 * Nowe odwołanie daje powiadomienie w serwisie i licznik przy pozycji
 * „Odwołania". To wystarcza, dopóki ktoś do panelu zagląda. Poczta jest dla
 * sytuacji, w której to właśnie zawiodło: sprawa leży, termin jest blisko
 * albo minął, a nikt jej nie zamknął.
 *
 * CZEGO TE TESTY PILNUJĄ — Z DWÓCH STRON:
 *  1. list WYCHODZI, gdy termin minął albo jest w progu;
 *  2. list NIE WYCHODZI, gdy nie ma o czym pisać (brak odwołań albo termin
 *     z zapasem). To jest równie ważne: „0 spraw po terminie" codziennie
 *     przez trzy tygodnie to najlepszy sposób, żeby czwarty list przeszedł
 *     niezauważony — i osobno, każdy niepotrzebny list zjada wiadro 300
 *     listów na dobę dzielone z potwierdzeniami rejestracji (D-047).
 */
class TerminOdwolaniaPilnowanyPocztaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.moderation.model.alarm_email' => 'moderacja@kuking.pl']);
    }

    private function odwolanieZlozone(string $kiedy): Appeal
    {
        $moderator = $this->moderator();
        $autor = $this->user();

        $decyzja = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'subject_user_id' => $autor->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam-reklama',
            'user_message' => 'Wpis wygląda na reklamę.',
        ]);

        $odwolanie = Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'To nie była reklama, tylko przepis mojej mamy.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        // `created_at` jest tym, od czego liczy się termin odpowiedzi —
        // przestawiamy je wprost, bo fabryka nie ma jak cofnąć czasu.
        $odwolanie->forceFill(['created_at' => $kiedy])->save();

        return $odwolanie->refresh();
    }

    public function test_list_wychodzi_gdy_termin_minal(): void
    {
        Notification::fake();

        // Trzydzieści dni to z pewnością więcej niż siedem dni roboczych.
        $this->odwolanieZlozone(now()->subDays(30)->toDateTimeString());

        $this->artisan('kuking:pilnuj-terminow-odwolan')->assertSuccessful();

        Notification::assertSentOnDemand(TerminOdwolaniaBlisko::class);
    }

    public function test_list_wychodzi_gdy_termin_jest_w_progu(): void
    {
        Notification::fake();

        // Siedem dni roboczych na odpowiedź, próg przypomnienia to dwa dni
        // robocze. Szukamy sprawy, która jest JESZCZE przed terminem, ale już
        // w progu — inaczej ten test sprawdzałby to samo, co poprzedni
        // (sprawę po terminie) i nie zauważyłby usunięcia całej gałęzi
        // „blisko terminu".
        $wProgu = null;

        for ($ile = 12; $ile >= 1; $ile--) {
            $kandydat = $this->odwolanieZlozone(now()->subDays($ile)->toDateTimeString());

            if (! $kandydat->isOverdue()
                && $kandydat->responseDeadline()->lessThanOrEqualTo(now()->addWeekdays(2))) {
                $wProgu = $kandydat;
                break;
            }

            $kandydat->forceFill([
                'status' => Appeal::STATUS_UPHELD,
                'decided_at' => now(),
                'decided_by' => $this->moderator()->getKey(),
                'decision_note' => 'Odrzucone na potrzeby doboru daty w teście.',
            ])->save();
        }

        $this->assertNotNull(
            $wProgu,
            'asercja kontrolna: nie udało się zbudować sprawy PRZED terminem, ale w progu — '
            .'bez niej ten test nie mierzy gałęzi „blisko terminu".',
        );

        $this->artisan('kuking:pilnuj-terminow-odwolan')->assertSuccessful();

        Notification::assertSentOnDemand(TerminOdwolaniaBlisko::class);
    }

    public function test_list_nie_wychodzi_gdy_termin_ma_zapas(): void
    {
        Notification::fake();

        $this->odwolanieZlozone(now()->toDateTimeString());

        $this->artisan('kuking:pilnuj-terminow-odwolan')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_list_nie_wychodzi_gdy_sprawa_jest_zamknieta(): void
    {
        Notification::fake();

        $odwolanie = $this->odwolanieZlozone(now()->subDays(30)->toDateTimeString());
        $odwolanie->forceFill([
            'status' => Appeal::STATUS_UPHELD,
            'decided_at' => now(),
            'decided_by' => $this->moderator()->getKey(),
            'decision_note' => 'Podtrzymuję decyzję, bo wpis był reklamą.',
        ])->save();

        $this->artisan('kuking:pilnuj-terminow-odwolan')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_bez_adresu_alarmowego_nic_nie_wychodzi(): void
    {
        Notification::fake();
        config(['kuking.moderation.model.alarm_email' => '']);

        $this->odwolanieZlozone(now()->subDays(30)->toDateTimeString());

        $this->artisan('kuking:pilnuj-terminow-odwolan')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_nowe_odwolanie_samo_nie_wysyla_ani_jednego_listu(): void
    {
        // Kluczowa granica tej decyzji (D-058): poczty na KAŻDE odwołanie nie
        // ma. Gdyby ktoś ją kiedyś dołożył „dla bezpieczeństwa", wiadro 300
        // listów na dobę zaczęłoby konkurować z potwierdzeniami rejestracji.
        //
        // Odwołanie MUSI tu przejść przez `FileAppeal`, a nie przez
        // `Appeal::create()` jak w metodzie pomocniczej wyżej: cała droga
        // powiadamiania wisi na tej akcji, więc test omijający ją nie
        // pilnowałby niczego. Zmierzone kontrolą ujemną — pierwsza wersja
        // tego testu przechodziła nawet po dołożeniu listu do
        // `PowiadomOOdwolaniu`.
        Notification::fake();

        $moderator = $this->moderator();
        $autor = $this->user('autor_odwolania');

        $decyzja = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'subject_user_id' => $autor->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam-reklama',
            'user_message' => 'Wpis wygląda na reklamę.',
        ]);

        app(FileAppeal::class)->handle(
            $autor,
            $decyzja,
            'To nie była reklama, tylko przepis mojej mamy.',
        );

        $this->assertSame(1, Appeal::query()->count(), 'asercja kontrolna: odwołanie naprawdę powstało');

        Notification::assertNothingSent();
    }
}

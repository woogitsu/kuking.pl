<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\PilnujTerminowOdwolan;
use App\Domain\Notifications\PrzypomnienieDobowe;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Notifications\TerminOdwolaniaBlisko;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Regresja #1333: „jeden list na dobę, nie jeden na sprawę" było tylko
 * komentarzem. Każde wywołanie `kuking:pilnuj-terminow-odwolan` z zaległą
 * sprawą kolejkowało nowy list — ręczne ponowienie albo restart tego samego
 * dnia dawały duplikat. Teraz przed kolejkowaniem komenda zajmuje wiersz
 * w `przypomnienia_dobowe`.
 *
 * Kontrola ujemna (wykonana przy tej zmianie): bez wywołania
 * `zarezerwuj()` w komendzie `test_dwa_przebiegi_tego_samego_dnia_daja_jeden_list`
 * oblewa (dwa listy). Kontrolą dodatnią jest przebieg następnego dnia —
 * rezerwacja nie może zatrzymać przypomnień na zawsze.
 */
class TerminOdwolaniaJedenListNaDobeTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES = 'moderacja@kuking.pl';

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.moderation.model.alarm_email' => self::ADRES]);
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(7, 10));
    }

    public function test_dwa_przebiegi_tego_samego_dnia_daja_jeden_list(): void
    {
        Notification::fake();
        $this->zalegleOdwolanie();

        $this->artisan('kuking:pilnuj-terminow-odwolan')->assertSuccessful();
        $this->travel(5)->hours();
        $this->artisan('kuking:pilnuj-terminow-odwolan')
            ->expectsOutputToContain('już wyszło')
            ->assertSuccessful();

        Notification::assertSentOnDemandTimes(TerminOdwolaniaBlisko::class, 1);
    }

    public function test_nastepnego_dnia_wychodzi_nowe_przypomnienie_a_po_zamknieciu_zadne(): void
    {
        Notification::fake();
        $odwolanie = $this->zalegleOdwolanie();

        $this->artisan('kuking:pilnuj-terminow-odwolan')->assertSuccessful();
        $this->travel(1)->day();
        $this->artisan('kuking:pilnuj-terminow-odwolan')->assertSuccessful();

        Notification::assertSentOnDemandTimes(TerminOdwolaniaBlisko::class, 2);

        $odwolanie->forceFill([
            'status' => Appeal::STATUS_UPHELD,
            'decided_at' => now(),
            'decided_by' => $this->moderator()->getKey(),
            'decision_note' => 'Podtrzymuję decyzję, bo wpis był reklamą.',
        ])->save();
        $this->travel(1)->day();
        $this->artisan('kuking:pilnuj-terminow-odwolan')->assertSuccessful();

        Notification::assertSentOnDemandTimes(TerminOdwolaniaBlisko::class, 2);
    }

    public function test_rownolegly_przebieg_ktory_juz_zarezerwowal_dobe_blokuje_drugi_list(): void
    {
        Notification::fake();
        $this->zalegleOdwolanie();

        // Tak wygląda świat z perspektywy przebiegu, który przegrał wyścig:
        // wiersz już stoi, bo drugi przebieg wstawił go chwilę wcześniej.
        $przypomnienie = app(PrzypomnienieDobowe::class);
        $this->assertTrue($przypomnienie->zarezerwuj(PilnujTerminowOdwolan::RODZAJ, self::ADRES));
        $this->assertFalse($przypomnienie->zarezerwuj(PilnujTerminowOdwolan::RODZAJ, self::ADRES));

        $this->artisan('kuking:pilnuj-terminow-odwolan')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_klucz_glowny_w_bazie_odrzuca_drugi_wiersz_tej_samej_doby(): void
    {
        // Atomowość nie może zależeć od PHP: dwa procesy widzą tę samą
        // pustą tabelę, więc o zwycięzcy rozstrzyga ograniczenie w bazie.
        $wiersz = ['rodzaj' => 'termin-odwolania', 'doba' => '2026-09-24', 'odbiorca' => str_repeat('a', 64)];
        DB::table('przypomnienia_dobowe')->insert($wiersz);

        $this->expectException(QueryException::class);
        DB::table('przypomnienia_dobowe')->insert($wiersz);
    }

    public function test_zmiana_adresu_alarmowego_daje_list_na_nowy_adres(): void
    {
        Notification::fake();
        $this->zalegleOdwolanie();

        $this->artisan('kuking:pilnuj-terminow-odwolan')->assertSuccessful();
        config(['kuking.moderation.model.alarm_email' => 'nowa-moderacja@kuking.pl']);
        $this->artisan('kuking:pilnuj-terminow-odwolan')->assertSuccessful();

        Notification::assertSentOnDemandTimes(TerminOdwolaniaBlisko::class, 2);
    }

    public function test_nieudane_kolejkowanie_oddaje_dobe_i_konczy_sie_bledem(): void
    {
        $this->zalegleOdwolanie();

        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('kolejka niedostępna'));

        $this->artisan('kuking:pilnuj-terminow-odwolan')
            ->expectsOutputToContain('Nie udało się wstawić przypomnienia do kolejki')
            ->assertFailed();

        $this->assertSame(0, DB::table('przypomnienia_dobowe')->count(), 'Nieudana próba zajęła dobę na zawsze.');

        // Kolejny przebieg tego samego dnia próbuje ponownie i wysyła.
        Notification::fake();
        $this->artisan('kuking:pilnuj-terminow-odwolan')->assertSuccessful();

        Notification::assertSentOnDemandTimes(TerminOdwolaniaBlisko::class, 1);
    }

    public function test_nieudana_rezerwacja_nie_wysyla_listu_i_konczy_sie_bledem(): void
    {
        Notification::fake();
        $this->zalegleOdwolanie();
        Schema::drop('przypomnienia_dobowe');

        $this->artisan('kuking:pilnuj-terminow-odwolan')
            ->expectsOutputToContain('list nie wyszedł')
            ->assertFailed();

        Notification::assertNothingSent();
    }

    private function zalegleOdwolanie(): Appeal
    {
        $decyzja = ModerationAction::create([
            'moderator_id' => $this->moderator()->getKey(),
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'subject_user_id' => $this->user()->getKey(),
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'spam-reklama',
            'user_message' => 'Wpis wygląda na reklamę.',
        ]);

        $odwolanie = Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $decyzja->subject_user_id,
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'To nie była reklama, tylko przepis mojej mamy.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        // Trzydzieści dni to z pewnością więcej niż siedem dni roboczych.
        $odwolanie->forceFill(['created_at' => now()->subDays(30)])->save();

        return $odwolanie->refresh();
    }
}

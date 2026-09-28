<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\AlarmujOPilnymZgloszeniu;
use App\Domain\Moderation\HumanUrgentAlarmAttempt;
use App\Domain\Security\DziennyBudzetListow;
use App\Models\Report;
use App\Notifications\PilneZgloszenieOdCzlowieka;
use App\Poczta\OdmowaEmailLabs;
use App\Poczta\PowodOdmowy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/** #2169: terminalna porażka workera i ograniczone odzyskanie alarmu. */
class PonowPilnyAlarmOdCzlowiekaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'database',
            'queue.default' => 'database',
            'queue.connections.database.after_commit' => false,
            'kuking.moderation.model.alarm_email' => 'moderacja@kuking.test',
        ]);
        Cache::purge('database');
    }

    public function test_terminalna_potwierdzona_odmowa_przechodzi_przez_ponowienie_tylko_raz(): void
    {
        $report = $this->report();
        $alarm = app(AlarmujOPilnymZgloszeniu::class);
        $this->assertTrue($alarm->handle($report));
        $this->assertSame(1, DB::table('jobs')->count());
        $payload = json_decode((string) DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $payload['maxTries']);
        $this->assertSame(1, DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte());

        $id = $this->ostatniaProba($report)->id;
        $notification = new PilneZgloszenieOdCzlowieka($report, probaId: $id);
        $this->assertTrue($notification->shouldSend(new \stdClass, 'mail'));
        $this->assertSame(HumanUrgentAlarmAttempt::STARTED, $this->ostatniaProba($report)->state);
        $this->assertFalse($notification->shouldSend(new \stdClass, 'mail'), 'Drugi worker nie może wysłać tej samej próby.');

        // To Job::fail() jest terminalną drogą workera po wyczerpaniu prób.
        $job = Queue::connection('database')->pop('default');
        $this->assertNotNull($job);
        $job->fail(OdmowaEmailLabs::powodu(PowodOdmowy::PRZEJSCIOWA, 'Potwierdzona odmowa testowa.', 200, confirmedRejection: true));
        $this->assertSame(HumanUrgentAlarmAttempt::REJECTED, $this->ostatniaProba($report)->state);

        $this->assertSame(0, Artisan::call('kuking:ponow-pilne-alarmy-od-ludzi'));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(2, DB::table('human_urgent_alarm_attempts')->where('report_id', $report->getKey())->count());
        $this->assertSame(2, DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte());
        $this->assertSame(HumanUrgentAlarmAttempt::RETRIED, DB::table('human_urgent_alarm_attempts')->where('id', $id)->value('state'));
        $this->assertFalse($notification->shouldSend(new \stdClass, 'mail'), 'Ręczne queue:retry starego joba nie może zdublować listu.');

        Artisan::call('kuking:ponow-pilne-alarmy-od-ludzi');
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(2, DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte());
    }

    public function test_niejednoznaczny_timeout_nie_jest_ponawiany_i_daje_sygnał(): void
    {
        $report = $this->report();
        $this->assertTrue(app(AlarmujOPilnymZgloszeniu::class)->handle($report));
        $id = $this->ostatniaProba($report)->id;
        $job = Queue::connection('database')->pop('default');
        $this->assertNotNull($job);
        $job->fail(new RuntimeException('Nie wiadomo, czy API przyjęło list.'));

        $this->assertSame(HumanUrgentAlarmAttempt::UNCERTAIN, $this->ostatniaProba($report)->state);
        $this->assertSame(1, Artisan::call('kuking:ponow-pilne-alarmy-od-ludzi'));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(1, DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte());
        $this->assertFalse((new PilneZgloszenieOdCzlowieka($report, probaId: $id))->shouldSend(new \stdClass, 'mail'));
    }

    public function test_utkniety_worker_daje_sygnał_bez_automatycznego_ponowienia(): void
    {
        $report = $this->report();
        $this->assertTrue(app(AlarmujOPilnymZgloszeniu::class)->handle($report));
        $id = $this->ostatniaProba($report)->id;
        $notification = new PilneZgloszenieOdCzlowieka($report, probaId: $id);
        $this->assertSame(1, $notification->tries);
        $this->assertTrue($notification->shouldSend(new \stdClass, 'mail'));
        DB::table('human_urgent_alarm_attempts')->where('id', $id)
            ->update(['started_at' => now()->subHours(3)]);

        $this->assertSame(1, Artisan::call('kuking:ponow-pilne-alarmy-od-ludzi'));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte());
        $this->assertFalse($notification->shouldSend(new \stdClass, 'mail'));
    }

    public function test_odzyskiwanie_wymaga_stanu_potwierdzonej_odmowy(): void
    {
        $report = $this->report();
        $id = HumanUrgentAlarmAttempt::create((string) $report->getKey());
        DB::table('human_urgent_alarm_attempts')->where('id', $id)->update([
            'state' => HumanUrgentAlarmAttempt::STARTED,
            'failure_kind' => PowodOdmowy::PRZEJSCIOWA->value,
        ]);
        $zuzytyBudzet = DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte();

        $this->assertFalse(app(AlarmujOPilnymZgloszeniu::class)->recover($report));
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(
            1,
            DB::table('human_urgent_alarm_attempts')->where('report_id', $report->getKey())->count(),
        );
        $this->assertSame($zuzytyBudzet, DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte());
    }

    public function test_rozwiazane_stare_automatyczne_i_wyczerpany_budzet_nie_wracaja(): void
    {
        foreach ([
            [Report::SOURCE_COMMUNITY, Report::STATUS_RESOLVED, now()],
            [Report::SOURCE_COMMUNITY, Report::STATUS_OPEN, now()->subHours(73)],
            [Report::SOURCE_AUTOMAT, Report::STATUS_OPEN, now()],
        ] as [$source, $status, $created]) {
            $report = $this->report($source, $created);
            $report->forceFill([
                'status' => $status,
                'resolved_at' => $status === Report::STATUS_RESOLVED ? now() : null,
            ])->save();
            $this->odmowa($report);
            $this->assertFalse(app(AlarmujOPilnymZgloszeniu::class)->recover($report));
        }

        $report = $this->report();
        $this->odmowa($report);
        config(['kuking.moderation.alarm_czlowieka.dzienny_sufit' => 0]);
        $przed = DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte();
        $this->assertFalse(app(AlarmujOPilnymZgloszeniu::class)->recover($report));
        $this->assertSame($przed, DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte());
    }

    public function test_rollback_odmawia_utraty_sladu_a_pusta_tabela_odtwarza_sie(): void
    {
        $migracja = require database_path('migrations/2026_09_28_190000_create_human_urgent_alarm_attempts_table.php');
        $id = HumanUrgentAlarmAttempt::create((string) $this->report()->getKey());

        try {
            self::wykonajMigracje($migracja, 'down');
            self::fail('Rollback miał odmówić utraty śladu.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('trwałych śladów', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('human_urgent_alarm_attempts'));
        DB::table('human_urgent_alarm_attempts')->where('id', $id)->delete();

        self::wykonajMigracje($migracja, 'down');
        $this->assertFalse(Schema::hasTable('human_urgent_alarm_attempts'));
        self::wykonajMigracje($migracja, 'up');
        $this->assertTrue(Schema::hasTable('human_urgent_alarm_attempts'));
    }

    private function odmowa(Report $report): void
    {
        $id = HumanUrgentAlarmAttempt::create((string) $report->getKey());
        HumanUrgentAlarmAttempt::failed($id, OdmowaEmailLabs::powodu(
            PowodOdmowy::PRZEJSCIOWA, 'Potwierdzona odmowa testowa.', 200, confirmedRejection: true,
        ));
    }

    private function report(string $source = Report::SOURCE_COMMUNITY, ?\DateTimeInterface $created = null): Report
    {
        $report = Report::create([
            'source' => $source,
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'reason' => 'minor',
            'status' => Report::STATUS_OPEN,
        ]);
        if ($created !== null) {
            $report->forceFill(['created_at' => $created, 'updated_at' => $created])->save();
        }

        return $report;
    }

    private function ostatniaProba(Report $report): object
    {
        return DB::table('human_urgent_alarm_attempts')->where('report_id', $report->getKey())
            ->orderByDesc('queued_at')->orderByDesc('id')->firstOrFail();
    }
}

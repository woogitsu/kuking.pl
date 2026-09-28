<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\HumanUrgentAlarmAttempt;
use App\Models\Report;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Dwa workery na odrębnych połączeniach nie rozpoczynają tej samej próby. */
final class PilnyAlarmDwaPolaczeniaTest extends TestCase
{
    use DatabaseMigrations;

    protected function tearDown(): void
    {
        DB::disconnect('alarm_worker_2');
        parent::tearDown();
    }

    public function test_drugi_worker_nie_rozpoczyna_juz_podjetej_proby(): void
    {
        config(['database.connections.alarm_worker_2' => config('database.connections.pgsql')]);
        $report = Report::create([
            'source' => Report::SOURCE_COMMUNITY,
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'reason' => 'minor',
            'status' => Report::STATUS_OPEN,
        ]);
        $id = HumanUrgentAlarmAttempt::create((string) $report->getKey());
        $this->assertTrue(HumanUrgentAlarmAttempt::start($id));

        $pierwotne = DB::getDefaultConnection();
        DB::setDefaultConnection('alarm_worker_2');
        try {
            $this->assertSame('pgsql', DB::connection()->getDriverName());
            $this->assertSame(HumanUrgentAlarmAttempt::STARTED, DB::table('human_urgent_alarm_attempts')->where('id', $id)->value('state'));
            $this->assertFalse(HumanUrgentAlarmAttempt::start($id));
        } finally {
            DB::setDefaultConnection($pierwotne);
            DB::table('human_urgent_alarm_attempts')->where('id', $id)->delete();
        }
    }

    public function test_rownolegle_drugi_worker_czeka_na_rozstrzygniecie_pierwszego(): void
    {
        config(['database.connections.alarm_worker_2' => config('database.connections.pgsql')]);
        $report = Report::create([
            'source' => Report::SOURCE_COMMUNITY,
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'reason' => 'minor',
            'status' => Report::STATUS_OPEN,
        ]);
        $id = HumanUrgentAlarmAttempt::create((string) $report->getKey());
        $pierwotne = DB::getDefaultConnection();
        $pierwszy = DB::connection();
        $pierwszy->beginTransaction();
        try {
            $this->assertTrue(HumanUrgentAlarmAttempt::start($id));
            DB::setDefaultConnection('alarm_worker_2');
            $drugi = DB::connection();
            $drugi->beginTransaction();
            try {
                $drugi->statement("SET LOCAL lock_timeout = '250ms'");
                try {
                    HumanUrgentAlarmAttempt::start($id);
                    self::fail('Drugi worker nie powinien ominąć blokady wiersza.');
                } catch (QueryException $error) {
                    $this->assertSame('55P03', $error->errorInfo[0]);
                }
            } finally {
                $drugi->rollBack();
                DB::setDefaultConnection($pierwotne);
            }
            $pierwszy->commit();

            DB::setDefaultConnection('alarm_worker_2');
            $this->assertFalse(HumanUrgentAlarmAttempt::start($id));
        } finally {
            DB::setDefaultConnection($pierwotne);
            if ($pierwszy->transactionLevel() > 0) {
                $pierwszy->rollBack();
            }
            DB::table('human_urgent_alarm_attempts')->where('id', $id)->delete();
        }
    }
}

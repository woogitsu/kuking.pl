<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Moderation\Actions\AlarmujOPilnymZgloszeniu;
use App\Domain\Moderation\HumanUrgentAlarmAttempt;
use App\Domain\Security\DziennyBudzetListow;
use App\Models\Report;
use App\Poczta\OdmowaEmailLabs;
use App\Poczta\PowodOdmowy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

#[Group('dwa-polaczenia')]
final class PonowieniePilnegoAlarmuNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @var list<ProcesRownolegly> */
    private array $procesyRecover = [];

    private ?string $reportId = null;

    protected function tearDown(): void
    {
        foreach ($this->procesyRecover as $proces) {
            $proces->zabij();
        }
        $this->procesyRecover = [];

        if ($this->reportId !== null) {
            DB::table('jobs')->where('payload', 'like', '%'.$this->reportId.'%')->delete();
            DB::table('reports')->where('id', $this->reportId)->delete();
        }

        parent::tearDown();
    }

    public function test_dwa_rowne_recover_tworuja_jedno_ponowienie_i_jeden_koszt(): void
    {
        config([
            'cache.default' => 'database',
            'queue.default' => 'database',
            'queue.connections.database.after_commit' => false,
            'kuking.moderation.model.alarm_email' => 'moderacja@kuking.test',
        ]);
        Cache::purge('database');

        $report = Report::create([
            'source' => Report::SOURCE_COMMUNITY,
            'target_type' => 'post',
            'target_id' => (string) Str::uuid7(),
            'reason' => 'minor',
            'status' => Report::STATUS_OPEN,
        ]);
        $this->reportId = (string) $report->getKey();
        $proba = HumanUrgentAlarmAttempt::create((string) $report->getKey());
        HumanUrgentAlarmAttempt::failed($proba, OdmowaEmailLabs::powodu(
            PowodOdmowy::PRZEJSCIOWA,
            'Potwierdzona odmowa testowa.',
            200,
            confirmedRejection: true,
        ));

        $przedBudzet = DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte();
        $bariera = $this->bariera(
            'SELECT id FROM reports WHERE id = :id FOR UPDATE',
            ['id' => (string) $report->getKey()],
        );

        $pierwszy = $this->wTleRecover((string) $report->getKey());
        $drugi = $this->wTleRecover((string) $report->getKey());
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikPierwszego = $pierwszy->wynik();
        $wynikDrugiego = $drugi->wynik();
        $this->assertTrue($wynikPierwszego['ok']);
        $this->assertTrue($wynikDrugiego['ok']);
        $wyniki = [
            (bool) $wynikPierwszego['wartosc'],
            (bool) $wynikDrugiego['wartosc'],
        ];
        sort($wyniki);
        $this->assertSame([false, true], $wyniki, 'Jeden proces musi wygrać, a drugi odmówić.');

        $proby = DB::table('human_urgent_alarm_attempts')->where('report_id', $report->getKey())->get();
        $this->assertCount(2, $proby, 'Równoległe recover() nie może utworzyć dwóch nowych prób.');
        $this->assertSame(1, $proby->where('state', HumanUrgentAlarmAttempt::RETRIED)->count());
        $this->assertSame(1, $proby->where('state', HumanUrgentAlarmAttempt::QUEUED)->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
        $this->assertSame($przedBudzet + 1, DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte());

        // Idempotencja obejmuje także ponowne uruchomienie po zakończonym wyścigu.
        $this->assertFalse(app(AlarmujOPilnymZgloszeniu::class)->recover($report->fresh()));
        $this->assertSame(2, DB::table('human_urgent_alarm_attempts')->where('report_id', $report->getKey())->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
        $this->assertSame($przedBudzet + 1, DziennyBudzetListow::dlaAlarmuModeracji()->zuzyte());
    }

    private function wTleRecover(string $reportId): ProcesRownolegly
    {
        $proces = ProcesRownolegly::start(
            __DIR__.'/bin/recoverPilnyAlarm.php',
            'recover',
            ['report_id' => $reportId],
            [
                'DB_DATABASE' => $this->baza,
                'APP_ENV' => 'testing',
                'BCRYPT_ROUNDS' => '4',
                'MAIL_MAILER' => 'array',
                'QUEUE_CONNECTION' => 'database',
                'CACHE_STORE' => 'database',
                'SESSION_DRIVER' => 'array',
                'KUKING_LOCK_TIMEOUT' => self::LOCK_TIMEOUT,
                'KUKING_STATEMENT_TIMEOUT' => self::STATEMENT_TIMEOUT,
                'KUKING_MODEL_ALARM_EMAIL' => 'moderacja@kuking.test',
            ],
        );

        $this->procesyRecover[] = $proces;

        return $proces;
    }
}

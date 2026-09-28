<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Moderation\Actions\AlarmujOPilnymZgloszeniu;
use App\Domain\Moderation\HumanUrgentAlarmAttempt;
use App\Models\Report;
use App\Poczta\PowodOdmowy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Ponawia tylko pewną odmowę dostawcy; niepewny wynik wymaga człowieka. */
final class PonowPilneAlarmyOdLudzi extends Command
{
    protected $signature = 'kuking:ponow-pilne-alarmy-od-ludzi';

    protected $description = 'Ponawia potwierdzone odmowy pilnych alarmów od ludzi młodszych niż 72 godziny';

    public function handle(AlarmujOPilnymZgloszeniu $alarm): int
    {
        $sprawy = DB::table('human_urgent_alarm_attempts as a')
            ->join('reports as r', 'r.id', '=', 'a.report_id')
            ->where('a.state', HumanUrgentAlarmAttempt::REJECTED)
            ->whereIn('a.failure_kind', [PowodOdmowy::PRZEJSCIOWA->value, PowodOdmowy::LIMIT_DOBOWY->value])
            ->whereIn('r.status', Report::STATUSY_OTWARTE)
            ->where('r.source', '!=', Report::SOURCE_AUTOMAT)
            ->whereIn('r.reason', ['minor', 'sexual'])
            ->where('r.created_at', '>=', now()->subHours(72))
            ->where(function ($query): void {
                $query->where('a.failure_kind', PowodOdmowy::PRZEJSCIOWA->value)
                    ->orWhere('a.failed_at', '<', now('UTC')->startOfDay());
            })
            ->orderBy('a.queued_at')
            ->limit(50)
            ->pluck('r.id');

        $ponowione = 0;
        foreach ($sprawy as $id) {
            $report = Report::query()->find($id);
            if ($report !== null && $alarm->recover($report)) {
                $ponowione++;
            }
        }

        $wymagaCzlowieka = DB::table('human_urgent_alarm_attempts as a')
            ->join('reports as r', 'r.id', '=', 'a.report_id')
            ->whereIn('r.status', Report::STATUSY_OTWARTE)
            ->whereIn('a.state', [HumanUrgentAlarmAttempt::UNCERTAIN, HumanUrgentAlarmAttempt::BLOCKED])
            ->count();

        // Worker mógł umrzeć przed failed()/afterSending(); taki wynik jest
        // nieznany, więc też wymaga ręcznego sprawdzenia u dostawcy.
        $utkniete = DB::table('human_urgent_alarm_attempts as a')
            ->join('reports as r', 'r.id', '=', 'a.report_id')
            ->whereIn('r.status', Report::STATUSY_OTWARTE)
            ->where(function ($query): void {
                $query->where(fn ($queued) => $queued
                    ->where('a.state', HumanUrgentAlarmAttempt::QUEUED)
                    ->where('a.queued_at', '<', now()->subHours(2)))
                    ->orWhere(fn ($started) => $started
                        ->where('a.state', HumanUrgentAlarmAttempt::STARTED)
                        ->where('a.started_at', '<', now()->subHours(2)));
            })
            ->count();

        $wymagaCzlowieka += $utkniete;

        $this->info("Ponowione alarmy: {$ponowione}. Wymaga wyjaśnienia: {$wymagaCzlowieka}.");
        if ($wymagaCzlowieka > 0) {
            Log::error('Pilny alarm od człowieka wymaga ręcznego wyjaśnienia wyniku wysyłki.', [
                'liczba' => $wymagaCzlowieka,
                'co_zrobic' => 'Sprawdź terminalne próby i panel dostawcy; nie ponawiaj niepewnych prób automatycznie.',
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Poczta\OdmowaEmailLabs;
use App\Poczta\PowodOdmowy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Stan jednej próby; identyfikator jest też kluczem w kolejkowanym powiadomieniu. */
final class HumanUrgentAlarmAttempt
{
    public const QUEUED = 'queued';

    public const STARTED = 'started';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public const UNCERTAIN = 'uncertain';

    public const BLOCKED = 'blocked';

    public const RETRIED = 'retried';

    public static function create(string $reportId): string
    {
        $id = (string) Str::uuid();
        DB::table('human_urgent_alarm_attempts')->insert([
            'id' => $id,
            'report_id' => $reportId,
            'state' => self::QUEUED,
            'queued_at' => now(),
        ]);

        return $id;
    }

    public static function start(string $id): bool
    {
        return DB::table('human_urgent_alarm_attempts')
            ->where('id', $id)
            ->where('state', self::QUEUED)
            ->update(['state' => self::STARTED, 'started_at' => now()]) === 1;
    }

    public static function accepted(string $id): void
    {
        DB::table('human_urgent_alarm_attempts')
            ->where('id', $id)
            ->whereIn('state', [self::QUEUED, self::STARTED])
            ->update(['state' => self::ACCEPTED, 'accepted_at' => now()]);
    }

    public static function failed(string $id, Throwable $error): void
    {
        $rejection = self::confirmedRejection($error);
        $state = match (true) {
            $rejection === null => self::UNCERTAIN,
            $rejection->powod() === PowodOdmowy::TRWALA,
            $rejection->powod() === PowodOdmowy::NIEZNANA => self::BLOCKED,
            default => self::REJECTED,
        };

        DB::table('human_urgent_alarm_attempts')
            ->where('id', $id)
            ->whereIn('state', [self::QUEUED, self::STARTED])
            ->update([
                'state' => $state,
                'failure_kind' => $rejection?->powod()->value ?? 'uncertain',
                'failed_at' => now(),
            ]);
    }

    private static function confirmedRejection(Throwable $error): ?OdmowaEmailLabs
    {
        for ($depth = 0; $depth < 10; $depth++) {
            if ($error instanceof OdmowaEmailLabs && $error->isConfirmedRejection()) {
                return $error;
            }
            $error = $error->getPrevious();
            if ($error === null) {
                break;
            }
        }

        return null;
    }
}

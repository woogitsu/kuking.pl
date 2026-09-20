<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\DataExportReady;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

/** Ponawianie samego listu, bez ponownego pobierania zdjęć i budowania ZIP. */
class NotifyUserExportReady implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public array $backoff = [120, 300];

    public function __construct(public string $dataExportId)
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        try {
            DB::transaction(function (): void {
                $export = DataExport::query()->lockForUpdate()->find($this->dataExportId);

                if ($export === null || ! $export->isDownloadable() || $export->notified_at !== null) {
                    return;
                }

                $user = $export->user;
                if ($user === null || $user->email === null
                    || in_array($user->status, [User::STATUS_PENDING_DELETE, User::STATUS_ERASED], true)) {
                    return;
                }

                Mail::to($user->email)->send(new DataExportReady($export));
                $export->forceFill(['notified_at' => now()])->save();
            });
        } catch (Throwable $e) {
            Log::warning('Paczka z danymi gotowa, ale e-mail nie wyszedł', [
                'data_export_id' => $this->dataExportId,
                'wyjatek' => $e::class,
            ]);

            // Także failed_jobs nie może przechować adresu ani tokenu z transportu.
            // Bez previous: oryginalny wyjątek nie trafia do serializacji kolejki.
            throw new RuntimeException('Nie udało się wysłać powiadomienia o paczce z danymi.');
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Wyczerpano próby powiadomienia o paczce z danymi', [
            'data_export_id' => $this->dataExportId,
        ]);
    }
}

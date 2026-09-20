<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RequestDataExport
{
    public function handle(User $user): DataExport
    {
        return DB::transaction(function () use ($user): DataExport {
            $export = DataExport::create([
                'user_id' => $user->getKey(),
                'status' => DataExport::STATUS_QUEUED,
            ]);

            app(ExportQueue::class)->dispatch(new GenerateUserExport((string) $export->getKey()));

            return $export;
        });
    }
}

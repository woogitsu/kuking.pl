<?php

declare(strict_types=1);

namespace App\Domain\Pwa;

use App\Domain\Analytics\ZapiszSygnal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RecordPromptShown
{
    public function handle(User $user, ZapiszSygnal $signals): bool
    {
        return DB::transaction(function () use ($user, $signals): bool {
            $account = DB::table('users')->where('id', $user->getKey())->lockForUpdate()->first();
            if ($account === null || in_array($account->status, User::STATUSY_ZAMKNIETEGO_KONTA, true)
                || ! in_array($account->pwa_prompt_state, ['offered', 'requested', 'dismissed', 'installed'], true)) {
                return false;
            }

            if (DB::table('product_signals')->where('user_id', $user->getKey())
                ->where('signal_name', ZapiszSygnal::PWA_PROMPT_SHOWN)->exists()) {
                return false;
            }

            // Zapis następuje dopiero po potwierdzeniu odsłonięcia przez JS.
            // Awaria telemetrii nie zmienia trwałej decyzji użytkownika.
            $signals->handle($user, ZapiszSygnal::PWA_PROMPT_SHOWN);

            return true;
        });
    }
}

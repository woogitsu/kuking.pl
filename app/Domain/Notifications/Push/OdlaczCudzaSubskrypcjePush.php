<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Push;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Po zalogowaniu na wspólnym urządzeniu gasi subskrypcję poprzedniego konta.
 * Klucze są dowodem dostępu do subskrypcji w tej przeglądarce; sam endpoint
 * nie daje prawa do usuwania cudzych urządzeń.
 */
final class OdlaczCudzaSubskrypcjePush
{
    public function handle(User $user, string $endpoint, string $p256dh, string $auth): bool
    {
        return DB::transaction(function () use ($user, $endpoint, $p256dh, $auth): bool {
            $subskrypcja = PushSubscription::query()
                ->whereRaw('md5(endpoint) = md5(?)', [$endpoint])
                ->where('endpoint', $endpoint)
                ->lockForUpdate()
                ->first();

            if ($subskrypcja === null || $subskrypcja->user_id === $user->getKey()) {
                return false;
            }

            if (! hash_equals($subskrypcja->klucz_p256dh, $p256dh)
                || ! hash_equals($subskrypcja->klucz_auth, $auth)) {
                return false;
            }

            $subskrypcja->delete();

            return true;
        });
    }
}

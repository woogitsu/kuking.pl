<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Users\ZamekKonta;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Rezerwacja JEDNEJ zbiorczej próby na lokalną dobę (#35).
 * To nie zgoda ani kanał wysyłki. Wywołujący musi wcześniej sprawdzić zgodę,
 * typy, globalny wyłącznik i mieć niepustą paczkę. Nie rezerwować z wyprzedzeniem.
 * Po niejednoznacznym wyniku transportu nie wolno zwalniać rezerwacji.
 */
final class PushDailyBudget
{
    public function __construct(private readonly PushDeliveryWindow $window) {}

    public function reserve(User $recipient, string $timezone): bool
    {
        // Zły argument odrzucamy także przed oczekiwaniem na blokadę.
        $this->window->nextAllowedAt(CarbonImmutable::now('UTC'), $timezone);

        return ZamekKonta::zablokuj($recipient, function (?User $account) use ($timezone): bool {
            // Czas czytamy POD blokadą: czekanie może przekroczyć 21:00.
            $now = CarbonImmutable::now('UTC');
            if ($account === null || ! $account->mozeCzytac() || $this->window->nextAllowedAt($now, $timezone)->greaterThan($now)) {
                return false;
            }

            $local = $now->setTimezone($timezone);
            // Przeliczamy również dawną rezerwację w AKTUALNEJ strefie.
            // Zmiana Tokio -> Los Angeles nie kupuje drugiego pushu „dziś”.
            if (DB::table('push_daily_reservations')->where('user_id', $account->id)
                ->where('reserved_at', '>=', $local->startOfDay()->setTimezone('UTC'))
                ->exists()) {
                return false;
            }

            return DB::table('push_daily_reservations')->insertOrIgnore([
                'user_id' => $account->id,
                'local_date' => $local->toDateString(),
                'reserved_at' => $now,
            ]) === 1;
        });
    }
}

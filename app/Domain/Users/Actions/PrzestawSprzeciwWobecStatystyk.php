<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * „Nie licz mnie w statystykach” — sprzeciw z art. 21 RODO (#2277).
 *
 * Polityka prywatności opiera statystyki na uzasadnionym interesie i pisze
 * „możesz się temu sprzeciwić”. Sprzeciw działa od chwili kliknięcia:
 *
 *  - `ZapiszSygnal` nie zapisuje już zdarzeń tej osoby,
 *  - `ZanotujOstatniaWizyte` nie zapisuje daty jej ostatniej wizyty,
 *  - układ strony nie wstawia jej skryptu Cloudflare Web Analytics.
 *
 * Przy zgłoszeniu kasujemy też to, co już zebrano: bieżącą datę ostatniej
 * wizyty i powiązanie zapisanych zdarzeń z kontem (zdarzenia zostają bez
 * `user_id`, tak jak po usunięciu konta — liczniki zbiorcze się nie
 * zmieniają). Cofnięcie niczego nie odtwarza, tylko pozwala liczyć od nowa.
 *
 * Kolumna poza `$fillable` (tak jak `ostatnio_widziany_at`); dwa jawne
 * przyciski zamiast pola w formularzu, więc powtórne kliknięcie nic nie psuje.
 */
final class PrzestawSprzeciwWobecStatystyk
{
    public function zglos(User $osoba): void
    {
        DB::transaction(function () use ($osoba): void {
            DB::table('users')
                ->where('id', $osoba->getKey())
                ->whereNull('sprzeciw_statystyk_at')
                ->update(['sprzeciw_statystyk_at' => now(), 'ostatnio_widziany_at' => null]);

            DB::table('product_signals')
                ->where('user_id', $osoba->getKey())
                ->update(['user_id' => null]);
        });

        $osoba->refresh();
    }

    public function cofnij(User $osoba): void
    {
        DB::table('users')
            ->where('id', $osoba->getKey())
            ->update(['sprzeciw_statystyk_at' => null]);

        $osoba->refresh();
    }
}

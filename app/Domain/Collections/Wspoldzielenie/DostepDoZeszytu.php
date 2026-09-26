<?php

declare(strict_types=1);

namespace App\Domain\Collections\Wspoldzielenie;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Odebranie dostępu, odejście z zeszytu i odwołanie zaproszenia (#1743).
 *
 * CZEGO ŻADNA Z TYCH CZYNNOŚCI NIE ROBI: nie kasuje pozycji. To, co
 * współpracownik dopisał, zostaje w zeszycie właściciela z podpisem „dodał"
 * (D-302) — zeszyt jest właściciela, a pozycje to odnośniki do cudzych
 * treści, nie treść współpracownika.
 *
 * ZAPIS KONTRA ODEBRANIE DOSTĘPU
 * Obie strony biorą zamek wiersza zeszytu (`ZamekZapisuDoZeszytu` —
 * `FOR NO KEY UPDATE`, tutaj `FOR UPDATE`, które się z nim wykluczają).
 * Zapis, który dostał zamek pierwszy, kończy się, zanim członkostwo zniknie
 * — pozycja dodana w chwili, gdy dostęp jeszcze był. Zapis, który czekał,
 * pyta Policy PO odebraniu i dostaje odmowę. Nie ma trzeciego wyniku.
 */
final class DostepDoZeszytu
{
    /** Właściciel odbiera dostęp współpracownikowi. */
    public function odbierz(User $wlasciciel, Collection $zeszyt, User $czlonek): bool
    {
        // Odebrać dostęp wolno ZAWSZE właścicielowi — także zawieszonemu.
        // To zawęża, nie rozszerza, więc nie jest „pisaniem".
        if ($wlasciciel->getKey() !== $zeszyt->owner_id) {
            throw new BladDlaCzlowieka('Dostęp do zeszytu może odebrać tylko jego właściciel.');
        }

        return DB::transaction(function () use ($zeszyt, $czlonek): bool {
            Collection::query()->whereKey($zeszyt->getKey())->lockForUpdate()->first();

            return DB::table('collection_members')
                ->where('collection_id', $zeszyt->getKey())
                ->where('user_id', $czlonek->getKey())
                ->delete() > 0;
        });
    }

    /** Współpracownik odchodzi sam. */
    public function odejdz(User $czlonek, Collection $zeszyt): bool
    {
        Gate::forUser($czlonek)->authorize('leave', $zeszyt);

        return DB::transaction(function () use ($czlonek, $zeszyt): bool {
            Collection::query()->whereKey($zeszyt->getKey())->lockForUpdate()->first();

            return DB::table('collection_members')
                ->where('collection_id', $zeszyt->getKey())
                ->where('user_id', $czlonek->getKey())
                ->delete() > 0;
        });
    }

    /** Właściciel odwołuje oczekujące zaproszenie (także link). */
    public function odwolaj(User $wlasciciel, CollectionInvitation $zaproszenie): bool
    {
        return DB::transaction(function () use ($wlasciciel, $zaproszenie): bool {
            $swieze = CollectionInvitation::query()->whereKey($zaproszenie->getKey())->lockForUpdate()->first();

            if ($swieze === null) {
                return false;
            }

            $zeszyt = $swieze->collection;

            if ($zeszyt === null || $zeszyt->owner_id !== $wlasciciel->getKey()) {
                throw new BladDlaCzlowieka('Zaproszenie może odwołać tylko właściciel zeszytu.');
            }

            if ($swieze->status !== CollectionInvitation::STATUS_PENDING) {
                return false;
            }

            $swieze->forceFill([
                'status' => CollectionInvitation::STATUS_REVOKED,
                'token_hash' => null,
                'responded_at' => now(),
            ])->save();

            return true;
        });
    }
}

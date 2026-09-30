<?php

declare(strict_types=1);

namespace App\Domain\Collections\Actions;

use App\Models\Collection;
use App\Models\User;
use App\Support\Odmiana;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Prywatna notatka przy JEDNEJ pozycji JEDNEGO zeszytu (issue #978).
 *
 * Kolumna `collection_items.note` istniała od początku, akcje zapisu umiały
 * ją przyjąć, eksport ją oddawał — ale nie było drogi, którą człowiek mógłby
 * ją wpisać. To jest ta droga, i celowo osobna od „Zapisuję":
 *
 *  - zapis zostaje jednym kliknięciem, bez obowiązkowego pola,
 *  - `Save*ToCollection` zmienia notatkę tylko przy `$note !== null`, więc
 *    ponowny zapis nie umie jej WYCZYŚCIĆ. Tu puste pole znaczy „usuń",
 *    a nie „nie zmieniaj" — jedna operacja, jedno znaczenie,
 *  - para zeszyt–treść: ta sama rzecz w dwóch zeszytach ma dwie notatki.
 *
 * Aktualizujemy wyłącznie `note`: `created_at` wyznacza kolejność w zeszycie
 * i dopisek nie ma jej zmieniać. Autor treści nie dostaje powiadomienia.
 */
final class UpdateCollectionItemNote
{
    public const LIMIT_ZNAKOW = 500;

    public const PRZEPIS = 'przepis';

    public const WPIS = 'wpis';

    public const WOREK_BLEDOW = 'notatka';

    public function handle(User $user, Collection $collection, string $typ, string $id, ?string $note): ?string
    {
        // Notatkę przy pozycji wspólnego zeszytu pisze każdy, kto może do niego
        // dopisywać (#1743, D-302) — właściciel i współpracownicy.
        Gate::forUser($user)->authorize('addItem', $collection);

        $kolumna = match ($typ) {
            self::PRZEPIS => 'recipe_id',
            self::WPIS => 'post_id',
            default => throw new ModelNotFoundException,
        };

        // Same spacje i entery to „wyczyść", nie notatka z pustego tekstu.
        $note = trim((string) $note);
        $note = $note === '' ? null : $note;

        if ($note !== null && mb_strlen($note) > self::LIMIT_ZNAKOW) {
            $zaDuzo = mb_strlen($note) - self::LIMIT_ZNAKOW;

            throw ValidationException::withMessages([
                'note' => 'Notatka może mieć najwyżej '.self::LIMIT_ZNAKOW.' znaków. '
                    .'Skróć ją o '.$zaDuzo.' '.Odmiana::rzeczownik($zaDuzo, 'znak', 'znaki', 'znaków')
                    .' i zapisz jeszcze raz — Twój tekst jest nadal w polu.',
            ])->errorBag(self::WOREK_BLEDOW);
        }

        // Odebranie dostępu (`DostepDoZeszytu`) bierze zamek wiersza zeszytu
        // i kasuje członkostwo w tej samej transakcji (#2311). Sprawdzenie
        // wyżej jest odczytem sprzed tego zamka, więc pod zamkiem pytamy
        // Policy jeszcze raz — o świeże konto i świeży zeszyt. Kolejność
        // konto → zeszyt jak w `ZamekZapisuDoZeszytu`.
        $zmienione = DB::transaction(static function () use ($user, $collection, $kolumna, $id, $note): int {
            $aktor = User::query()->whereKey($user->getKey())->lock('FOR NO KEY UPDATE')->first()
                ?? throw new ModelNotFoundException;
            $zeszyt = Collection::query()->whereKey($collection->getKey())->lock('FOR NO KEY UPDATE')->first()
                ?? throw new ModelNotFoundException;

            Gate::forUser($aktor)->authorize('addItem', $zeszyt);

            return DB::table('collection_items')
                ->where('collection_id', $zeszyt->getKey())
                ->where($kolumna, $id)
                ->update(['note' => $note]);
        });

        // Nie ma takiej pozycji w TYM zeszycie — nie tworzymy jej przy okazji.
        if ($zmienione === 0) {
            throw new ModelNotFoundException;
        }

        return $note;
    }
}

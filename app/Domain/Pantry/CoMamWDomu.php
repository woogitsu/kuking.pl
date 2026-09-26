<?php

declare(strict_types=1);

namespace App\Domain\Pantry;

use App\Models\PantryItem;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Dopisywanie produktu do prywatnej listy „Co mam w domu” (D-285).
 *
 * Reguła domenowa mieszka tutaj, nie w kontrolerze: limit długości listy,
 * „ten sam produkt w innej pisowni już jest” i odrzucenie nazwy bez słów.
 * Porównanie („jajka” = „jajko”) liczy baza funkcją
 * `public.kuking_klucz_skladnika()` — tą samą, z której generuje się
 * kolumna `pantry_items.klucz`. Tu jej nie powtarzamy w PHP.
 */
final class CoMamWDomu
{
    /** Tyle produktów mieści się na jednej liście. */
    public const MAKS_PRODUKTOW = 150;

    public const MAKS_ZNAKOW = 120;

    /**
     * @return array{produkt: PantryItem, nowy: bool}
     *
     * @throws ValidationException
     */
    public function dodaj(User $user, string $nazwa): array
    {
        $nazwa = Str::squish($nazwa);

        if (mb_strlen($nazwa) < 2) {
            throw ValidationException::withMessages([
                'nazwa' => 'Wpisz nazwę produktu, na przykład „mąka” albo „jajka”.',
            ]);
        }

        if (mb_strlen($nazwa) > self::MAKS_ZNAKOW) {
            throw ValidationException::withMessages([
                'nazwa' => 'Skróć nazwę produktu do '.self::MAKS_ZNAKOW.' znaków. Wystarczy samo „mąka” albo „ser żółty”.',
            ]);
        }

        $klucz = (string) DB::scalar('SELECT public.kuking_klucz_skladnika(?)', [$nazwa]);

        if ($klucz === '') {
            throw ValidationException::withMessages([
                'nazwa' => 'Wpisz nazwę produktu słowami, na przykład „mąka” albo „jajka”. Same liczby i znaki nie wystarczą.',
            ]);
        }

        $juzJest = $user->pantryItems()->where('klucz', $klucz)->first();

        if ($juzJest !== null) {
            return ['produkt' => $juzJest, 'nowy' => false];
        }

        if ($user->pantryItems()->count() >= self::MAKS_PRODUKTOW) {
            throw ValidationException::withMessages([
                'nazwa' => 'Na liście jest już '.self::MAKS_PRODUKTOW.' produktów. Usuń te, których już nie masz, i dodaj nowy.',
            ]);
        }

        try {
            /** @var PantryItem $produkt */
            $produkt = $user->pantryItems()->create(['name' => $nazwa]);
        } catch (UniqueConstraintViolationException) {
            // Dwa wysłania naraz (podwójne dotknięcie przycisku): drugie
            // trafia na UNIQUE (user_id, klucz). To nie jest błąd człowieka.
            /** @var PantryItem $produkt */
            $produkt = $user->pantryItems()->where('klucz', $klucz)->firstOrFail();

            return ['produkt' => $produkt, 'nowy' => false];
        }

        return ['produkt' => $produkt, 'nowy' => true];
    }
}

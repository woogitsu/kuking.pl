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
 * „Zmień nazwę” przy produkcie z listy „Co mam w domu” (#2448, V2).
 *
 * Zmienia WYŁĄCZNIE `name` istniejącego produktu: ten sam wiersz (UUID,
 * właściciel, `created_at`, `expires_on`, `expiry_kind`, `quantity_note`,
 * `frozen` zostają). `rdzenie` i `klucz` przelicza baza (kolumny generowane)
 * w tym samym zapisie — algorytmu rdzeni nie powtarzamy w PHP. Zapis idzie
 * zapytaniem o jedną kolumnę, więc równoległa zmiana ilości czy terminu nie
 * jest nadpisywana starą kopią formularza.
 *
 * Te same reguły nazwy co przy dodawaniu (`CoMamWDomu::dodaj`): białe znaki
 * ściśnięte, 2–120 znaków, nazwa musi mieć słowa możliwe do porównania.
 * Korekta nie jest dodawaniem, więc działa też przy pełnej liście.
 *
 * KOLEJNOŚĆ BLOKAD (jedna, ustalona): najpierw wiersz `users` właściciela —
 * tak jak `CoMamWDomu::dodaj` — potem wiersz produktu. `ZmienTerminProduktu`
 * bierze tylko wiersz produktu, więc cykl blokad nie powstaje.
 *
 * Kolizja z INNYM produktem tej samej osoby (ten sam klucz po zmianie) odrzuca
 * zmianę — nic nie jest scalane ani kasowane. Zmiana samej pisowni własnego
 * produktu (klucz bez zmian) jest dozwolona; ta sama nazwa u innej osoby nie
 * przeszkadza. Konflikt kart: formularz niesie nazwę, którą człowiek widział;
 * gdy w międzyczasie ktoś ją zmienił, nic się nie zapisuje.
 */
final class ZmienNazweProduktu
{
    public const ZASTOSOWANO = 'zastosowano';

    public const JUZ_TAK_BYLO = 'juz_tak_bylo';

    public const KONFLIKT = 'konflikt';

    public const BRAK = 'brak';

    /**
     * @param  string  $widzianaNazwa  nazwa, pod którą człowiek widział produkt w formularzu
     * @return self::ZASTOSOWANO|self::JUZ_TAK_BYLO|self::KONFLIKT|self::BRAK
     *
     * @throws ValidationException zła, za krótka, za długa albo powtórzona nazwa
     */
    public function handle(User $user, string $idProduktu, string $nowaNazwa, string $widzianaNazwa): string
    {
        $nazwa = Str::squish($nowaNazwa);

        if (mb_strlen($nazwa) < 2) {
            throw ValidationException::withMessages([
                'nazwa' => 'Wpisz nazwę produktu, na przykład „mąka” albo „jajka”.',
            ]);
        }

        if (mb_strlen($nazwa) > CoMamWDomu::MAKS_ZNAKOW) {
            throw ValidationException::withMessages([
                'nazwa' => 'Skróć nazwę produktu do '.CoMamWDomu::MAKS_ZNAKOW.' znaków. Wystarczy samo „mąka” albo „ser żółty”.',
            ]);
        }

        $klucz = (string) DB::scalar('SELECT public.kuking_klucz_skladnika(?)', [$nazwa]);

        if ($klucz === '') {
            throw ValidationException::withMessages([
                'nazwa' => 'Wpisz nazwę produktu słowami, na przykład „mąka” albo „jajka”. Same liczby i znaki nie wystarczą.',
            ]);
        }

        return DB::transaction(function () use ($user, $idProduktu, $nazwa, $klucz, $widzianaNazwa): string {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // Cudzy produkt jest dla pytającego tym samym, co nieistniejący.
            $produkt = PantryItem::query()
                ->where('user_id', $user->getKey())
                ->whereKey($idProduktu)
                ->lockForUpdate()
                ->first();

            if ($produkt === null) {
                return self::BRAK;
            }

            if ($produkt->name === $nazwa) {
                return self::JUZ_TAK_BYLO;
            }

            if ($produkt->name !== $widzianaNazwa) {
                return self::KONFLIKT;
            }

            $kolizja = PantryItem::query()
                ->where('user_id', $user->getKey())
                ->where('klucz', $klucz)
                ->whereKeyNot($produkt->getKey())
                ->first();

            if ($kolizja !== null) {
                throw $this->kolizja($kolizja);
            }

            try {
                PantryItem::query()
                    ->where('user_id', $user->getKey())
                    ->whereKey($produkt->getKey())
                    ->update(['name' => $nazwa]);
            } catch (UniqueConstraintViolationException) {
                // Zabezpieczenie dodatkowe: pod blokadą konta to nie powinno
                // się zdarzyć, ale surowy błąd bazy nie wychodzi do człowieka.
                throw $this->kolizja(null);
            }

            return self::ZASTOSOWANO;
        });
    }

    private function kolizja(?PantryItem $inny): ValidationException
    {
        $ktory = $inny !== null ? ' „'.$inny->name.'”' : '';

        return ValidationException::withMessages([
            'nazwa' => 'Na Twojej liście jest już taki produkt'.$ktory.'. Wpisz inną nazwę albo usuń któryś z tych produktów — nic nie zostało zmienione.',
        ]);
    }
}

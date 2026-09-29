<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use App\Models\Collection;
use App\Models\User;

/**
 * Rozstrzygnięcie „Usuń z zeszytu" (issue #775, #970, krok 7).
 *
 * Trzyma to, co kontroler potrzebuje do odpowiedzi: zdanie dla człowieka
 * (`komunikat`) i zapis do sesji (`wyjecie`) dla drogi powrotu. Samą sesję
 * zamyka kontroler — domena dostaje i oddaje zwykłe wartości.
 * Gdy nic nie zdjęto (`zdjete === []`), oba pola są `null`: nie ma czego
 * ogłaszać ani do czego wracać.
 */
final readonly class WynikWyjeciaZZeszytu
{
    /**
     * @param  list<array{collection_id: string, note: ?string, created_at: ?string, added_by_id?: ?string}>  $zdjete
     * @param  array{typ: string, id: string, pozycje: list<array{collection_id: string, note: ?string, created_at: ?string, added_by_id?: ?string}>}|null  $wyjecie
     */
    public function __construct(
        public array $zdjete,
        public ?string $komunikat,
        public ?array $wyjecie,
    ) {}

    /**
     * @param  list<array{collection_id: string, note: ?string, created_at: ?string, added_by_id?: ?string}>  $zdjete
     */
    public static function zZdjetych(string $typ, string $id, User $user, array $zdjete): self
    {
        if ($zdjete === []) {
            return new self([], null, null);
        }

        return new self($zdjete, self::komunikat($typ, $user, $zdjete), [
            'typ' => $typ,
            'id' => $id,
            'pozycje' => $zdjete,
        ]);
    }

    /**
     * Zdanie po wyjęciu — MÓWI ZAKRES, bo zakres jest tu całą sprawą.
     *
     * Jeden zeszyt → z nazwy, bo nazwa jest krótsza i pewniejsza niż liczba.
     * Więcej niż jeden → wprost „ze wszystkich Twoich zeszytów" z liczbą,
     * żeby nikt nie odkrył zakresu dopiero po fakcie, w innym zeszycie.
     *
     * „Nie usunęliśmy go z serwisu" zostaje w obu wariantach: to jedyne
     * zdanie, które rozróżnia wyjęcie z zeszytu od skasowania treści.
     *
     * @param  list<array{collection_id: string, note: ?string, created_at: ?string, added_by_id?: ?string}>  $zdjete
     */
    private static function komunikat(string $typ, User $user, array $zdjete): string
    {
        $co = $typ === PowrotPoWyjeciu::TYP_PRZEPIS ? 'Przepis' : 'Wpis';

        if (count($zdjete) === 1) {
            $nazwa = Collection::query()->dostepneDoZapisuDla($user)->whereKey($zdjete[0]['collection_id'])->value('name');

            return $nazwa === null
                ? "{$co} wyjęty z zeszytu. Nie usunęliśmy go z serwisu — możesz go przywrócić."
                : "{$co} wyjęty z zeszytu „{$nazwa}”. Nie usunęliśmy go z serwisu — możesz go przywrócić.";
        }

        $ile = count($zdjete);

        return "{$co} wyjęty z zeszytu — zniknął ze wszystkich Twoich zeszytów, było ich {$ile}. "
            .'Nie usunęliśmy go z serwisu — możesz go przywrócić razem z notatkami.';
    }
}

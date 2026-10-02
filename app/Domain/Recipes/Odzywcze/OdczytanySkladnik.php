<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzywcze;

/**
 * Wynik odczytania jednego wiersza składnika (D-299). Tylko do liczenia —
 * nigdy nie zapisywany i nigdy nie pokazywany zamiast tekstu autora.
 */
final class OdczytanySkladnik
{
    /**
     * @param  float|null  $ilosc  liczba z wiersza („2”, „1½”, „pół”) albo null
     * @param  string|null  $jednostka  kod z `JednostkiMiary::SLOWA` albo null („3 jajka”)
     * @param  string  $nazwa  znormalizowana nazwa bez ilości, jednostki i dopisków
     * @param  string|null  $nazwaZJednostka  nazwa razem ze słowem jednostki („liscie laurowe”),
     *                                        gdy jednostka mogła być częścią nazwy
     * @param  float|null  $gramyZNawiasu  gramy podane w nawiasie, już przeliczone na cały wiersz
     * @param  bool  $bezIlosciZTekstu  autor napisał „do smaku”, „ile weźmie”, „do podania”…
     * @param  bool  $sprzecznaMasaWNawiasie  „po … razem” nie daje wiarygodnej masy
     * @param  bool  $niejednoznacznaIlosc  grupy cyfr są uszkodzone — nie zgadujemy masy
     */
    public function __construct(
        public readonly ?float $ilosc,
        public readonly ?string $jednostka,
        public readonly string $nazwa,
        public readonly ?string $nazwaZJednostka,
        public readonly ?float $gramyZNawiasu,
        public readonly bool $bezIlosciZTekstu,
        public readonly bool $sprzecznaMasaWNawiasie = false,
        public readonly bool $niejednoznacznaIlosc = false,
    ) {}
}

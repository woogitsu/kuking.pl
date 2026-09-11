<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * Dwie odpowiedzi na dwa RÓŻNE pytania o to samo zdjęcie, policzone w jednym
 * przejściu po rodzicach (issue #286, MEDIA-03).
 *
 * PO CO OSOBNY TYP, A NIE DWA WYWOŁANIA `moze()`
 * `MediaController` potrzebuje odpowiedzi na dwa pytania naraz: „czy TEN widz
 * ma prawo do tych bajtów" (to decyduje o 404) i „czy zobaczyłby je ktoś
 * NIEZALOGOWANY" (to i tylko to decyduje o nagłówku `Cache-Control`). Dotąd
 * pytał o to dwoma osobnymi wywołaniami `DostepDoZdjecia::moze()`, a każde
 * z nich budowało cały graf rodziców od nowa — zmierzone: 6 z 15 zapytań na
 * jedno przekierowanie zdjęcia szło wyłącznie na policzenie drugi raz tego
 * samego (`docs/research/2026-09-10-pomiar-zapytan-zdjecia.md`).
 *
 * WSPÓLNE SĄ DANE, NIE DECYZJA — I TO JEST CAŁA OSTROŻNOŚĆ TEJ ZMIANY.
 * Wiersze rodziców czyta się raz, ale `Gate` pytany jest OSOBNO dla widza
 * i osobno dla anonima, na tych samych wierszach. Nie ma tu żadnej pamięci
 * podręcznej decyzji i nie może jej być: „ten widz może" i „anonim może" to
 * odpowiedzi, które rozjeżdżają się w obie strony — zablokowany widz nie
 * zobaczy zdjęcia, które anonim zobaczy (blokada), a autor zobaczy zdjęcie
 * swojego prywatnego przepisu, którego anonim nie zobaczy. Sklejenie tych
 * dwóch odpowiedzi w jedną byłoby albo wyciekiem, albo zdjęciem, które
 * nie wyświetla się właścicielowi.
 *
 * Typ jest `readonly`, żeby nikt po drodze nie „poprawił" jednej odpowiedzi
 * drugą.
 */
final readonly class DecyzjaOZdjeciu
{
    public function __construct(
        /**
         * Czy widz, o którego pytano, ma prawo do bajtów tego zdjęcia.
         * Odpowiedź `false` znaczy 404 — nie 403 (patrz `MediaController`).
         */
        public bool $dlaWidza,
        /**
         * Czy to samo zdjęcie zobaczyłby ktoś NIEZALOGOWANY. Jedyne pytanie,
         * które rozstrzyga o nagłówku `Cache-Control`: odpowiedź wspólną dla
         * wszystkich wolno trzymać we wspólnym cache, odpowiedź zależną od
         * tego, kto pyta — nie wolno nigdzie.
         */
        public bool $dlaAnonima,
    ) {}
}

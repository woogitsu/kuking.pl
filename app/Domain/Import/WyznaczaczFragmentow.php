<?php

declare(strict_types=1);

namespace App\Domain\Import;

/**
 * Model, który dostaje ponumerowane wiersze tekstu i oddaje WYŁĄCZNIE
 * granice fragmentów z etykietami — nigdy tekstu (wymaganie z pilota #814,
 * D-300). Tekst szkicu składa PHP z oryginału (`TrybFragmentow`), więc model
 * nie ma jak dopisać słowa, którego na stronie nie było.
 *
 * Używany tylko dla strony BEZ danych JSON-LD `Recipe`. PDF z warstwą tekstu
 * idzie w całości lokalnie (`ParserTekstuPrzepisu`).
 */
interface WyznaczaczFragmentow
{
    /**
     * @param  list<string>  $wiersze  wiersze tekstu strony, numerowane od 1
     * @return ?list<array{do: int, etykieta: string}> `null` = model wyłączony,
     *                                                 budżet wyczerpany albo
     *                                                 odpowiedź bez sensu
     */
    public function fragmenty(array $wiersze): ?array;
}

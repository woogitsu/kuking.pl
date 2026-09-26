<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzywcze;

/**
 * Jak kalkulator potraktował jeden wiersz składnika (D-299). Do testów,
 * do raportu pokrycia słownika i do diagnozy — nie do widoku.
 */
final class WierszWyliczenia
{
    /** Wiersz wchodzi do rachunku: znamy masę i skład. */
    public const POLICZONY = 'policzony';

    /** Znamy masę, ale składnika nie ma w tabeli — obniża pokrycie. */
    public const NIEZNANY_SKLADNIK = 'nieznany_skladnik';

    /** Nie da się ustalić masy („tyle mąki, żeby…”, „olej do smażenia”). */
    public const BEZ_MASY = 'bez_masy';

    /** Pomijany jawnie: „Bez ilości”, „do smaku”, sól bez ilości. */
    public const POMINIETY = 'pominiety';

    public function __construct(
        public readonly string $tekst,
        public readonly string $stan,
        public readonly ?string $klucz,
        public readonly ?float $gramy,
    ) {}
}

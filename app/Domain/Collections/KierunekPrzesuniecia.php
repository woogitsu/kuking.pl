<?php

declare(strict_types=1);

namespace App\Domain\Collections;

/** Cztery świadome ruchy przy przepisie w ułożonym zeszycie (#2544). */
enum KierunekPrzesuniecia: string
{
    case Wyzej = 'wyzej';
    case Nizej = 'nizej';
    case NaPoczatek = 'poczatek';
    case NaKoniec = 'koniec';

    /** Słowa z przycisku, w mianowniku, do zdania „Przepis … przesunięty …". */
    public function opis(): string
    {
        return match ($this) {
            self::Wyzej => 'wyżej',
            self::Nizej => 'niżej',
            self::NaPoczatek => 'na początek',
            self::NaKoniec => 'na koniec',
        };
    }
}

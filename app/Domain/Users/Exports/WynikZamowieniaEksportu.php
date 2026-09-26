<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

/** Co się stało z zamówieniem paczki — z tego kontroler wybiera zdanie dla człowieka. */
enum WynikZamowieniaEksportu
{
    /** Nowy eksport przyjęty i zlecony. */
    case Przyjety;

    /** Paczka już się robi (D-078) — także przy dwukliku, który odbił się o indeks. */
    case JuzTrwa;

    /** Eksport utknął w kolejce, więc zlecenie ponowiono na tym samym rekordzie (audyt A02). */
    case Ponowiony;

    /**
     * Baza nie przyjęła rekordu albo zadania (issue #824). Transakcja cofnęła
     * OBA, więc nic nie zostało zapisane — człowiek dostaje zdanie, co zrobić,
     * zamiast ekranu 500.
     */
    case Nieprzyjety;
}

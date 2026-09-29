<?php

declare(strict_types=1);

namespace App\Support\Zdrowie;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;

/**
 * Jedna kontrola endpointu `/health` (issue #2212).
 *
 * Kontrakt jest wąski celowo: sonda TYLKO mierzy i rzuca, a wszystko, co
 * wspólne — zamiana awarii na kod, `Log::error`, webhook z odstępem, kolejność
 * kluczy i kod HTTP — robi `HealthController`. Dzięki temu nowa sonda to jedna
 * klasa i jeden wpis na liście kontrolera, bez ruszania odpowiedzi.
 */
interface Sonda
{
    /** Klucz sondy w polu `checks` odpowiedzi (kolejność ustala kontroler). */
    public function nazwa(): string;

    /**
     * Powód dla wyjątków, których sonda nie rozpoznała sama (np. `QueryException`).
     * Zawsze z `Powody::WSZYSTKIE` — ta odpowiedź jest publiczna.
     */
    public function powodDomyslny(): string;

    /**
     * Wraca bez wartości, gdy jest dobrze; rzuca `KontrolaZdrowiaNieprzeszla`
     * (z kodem z `Powody`) albo dowolny `Throwable`, gdy nie jest.
     *
     * @throws KontrolaZdrowiaNieprzeszla
     */
    public function sprawdz(): void;
}

<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Domain\Moderation\ZaleglePotwierdzeniaZgloszen;
use App\Support\Zdrowie\SondaInformacyjna;

/**
 * Sekcja `informacje.potwierdzenia_dsa` endpointu `/health` (#2218,
 * kryterium 3): ile zgłoszeń prawnych bez konta doszło do sufitu prób
 * potwierdzenia przyjęcia (DSA art. 16 ust. 4) bez dostarczenia.
 *
 * INFORMACYJNA, NIE KONTROLNA. Sufit to stan trwały, który czeka na
 * człowieka (`kuking:potwierdz-zgloszenie-inna-droga`); `degraded` na tygodnie
 * uczyłby operatora nie patrzeć na `/health`. O stanie mówi alarm
 * (`AlarmSufituPotwierdzen`), a tu jest liczba na żądanie.
 *
 * Bez danych osobowych: wyłącznie liczba, liczona tak samo jak w dosyłce
 * (`ZaleglePotwierdzeniaZgloszen`).
 */
final class SondaSufituPotwierdzenDsa implements SondaInformacyjna
{
    public function nazwa(): string
    {
        return 'potwierdzenia_dsa';
    }

    public function odczytaj(): array
    {
        return ['na_suficie' => ZaleglePotwierdzeniaZgloszen::ileNaSuficie()];
    }
}

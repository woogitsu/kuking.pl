<?php

declare(strict_types=1);

namespace App\Domain\Planer;

use App\Models\MealPlanEntry;
use App\Models\Recipe;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Plik iCalendar (RFC 5545) z wybranych pozycji planera (#2529, V2, D-333 — paczka E).
 *
 * JEDNORAZOWA KOPIA, generowana na żądanie i oddawana prywatną odpowiedzią.
 * Nie ma tu publicznego, stałego adresu, tokenu ani kanału do subskrypcji.
 *
 * CO TRAFIA DO PLIKU: wyłącznie data (wydarzenie CAŁODNIOWE — plan zapisuje
 * dzień, nie godzinę) i nazwa: tytuł przepisu widocznego dziś dla właściciela
 * albo jego własna etykieta. NIGDY składniki, kroki, zdjęcia, adresy, prywatne
 * dopiski ani e-mail. Bez `VALARM` — przypomnienia ustawia kalendarz docelowy.
 *
 * Przepis niedostępny/usunięty nie ma tu nazwy: takiej pozycji nie eksportujemy
 * (decyzja: pominięcie, nie neutralne wydarzenie — `PlanerTygodnia::pozycje`).
 *
 * UID jest stabilny (`plan-<uuid pozycji>@kuking.pl`), ale NIE obiecujemy braku
 * dubli po ponownym imporcie — zależy to od kalendarza docelowego.
 *
 * FORMAT: CRLF, UTF-8, escaping `\`, `;`, `,` i nowych linii, składanie linii
 * po 75 oktetów BEZ rozcinania znaku UTF-8. `DTEND` całodniowego wydarzenia
 * jest wyłączny (kolejny dzień), więc nic nie przesuwa się przy zmianie strefy.
 */
final class PlikKalendarza
{
    /** @param array{wpis: MealPlanEntry, stan: string, przepis: ?Recipe} $pozycja */
    public static function nazwaPozycji(array $pozycja): ?string
    {
        return match ($pozycja['stan']) {
            PlanerTygodnia::STAN_PRZEPIS => $pozycja['przepis']?->title,
            PlanerTygodnia::STAN_WLASNY => $pozycja['wpis']->label,
            default => null,
        };
    }

    /**
     * @param  list<array{wpis: MealPlanEntry, stan: string, przepis: ?Recipe}>  $pozycje
     */
    public static function zbuduj(array $pozycje, CarbonInterface $teraz): string
    {
        $znacznik = CarbonImmutable::instance($teraz)->utc()->format('Ymd\THis\Z');

        $linie = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Kuking//Planer tygodnia//PL',
            'CALSCALE:GREGORIAN',
        ];

        foreach ($pozycje as $pozycja) {
            $nazwa = self::nazwaPozycji($pozycja);
            if ($nazwa === null || trim($nazwa) === '') {
                continue;
            }

            $dzien = CarbonImmutable::parse($pozycja['wpis']->day->toDateString());

            array_push(
                $linie,
                'BEGIN:VEVENT',
                'UID:plan-'.$pozycja['wpis']->getKey().'@kuking.pl',
                'DTSTAMP:'.$znacznik,
                'DTSTART;VALUE=DATE:'.$dzien->format('Ymd'),
                'DTEND;VALUE=DATE:'.$dzien->addDay()->format('Ymd'),
                'SUMMARY:'.self::tekst($nazwa),
                'TRANSP:TRANSPARENT',
                'END:VEVENT',
            );
        }

        $linie[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::zlozLinie(...), $linie))."\r\n";
    }

    /** Escaping wartości TEXT (RFC 5545 §3.3.11); nowa linia nie otwiera nowej właściwości. */
    public static function tekst(string $tekst): string
    {
        $tekst = str_replace(["\r\n", "\r"], "\n", $tekst);
        // Znaki sterujące (poza nową linią) nie mają prawa być w pliku.
        $tekst = (string) preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', $tekst);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\\;', '\\,', '\\n'], $tekst);
    }

    /** Składanie linii (RFC 5545 §3.1): do 75 oktetów, kontynuacja zaczyna się spacją, znak UTF-8 nie jest rozcinany. */
    public static function zlozLinie(string $linia): string
    {
        if (strlen($linia) <= 75) {
            return $linia;
        }

        $wynik = [];
        $biezaca = '';
        $limit = 75;

        foreach (mb_str_split($linia) as $znak) {
            if (strlen($biezaca) + strlen($znak) > $limit) {
                $wynik[] = $biezaca;
                $biezaca = '';
                $limit = 74; // spacja kontynuacji zajmuje jeden oktet
            }
            $biezaca .= $znak;
        }
        $wynik[] = $biezaca;

        return implode("\r\n ", $wynik);
    }
}

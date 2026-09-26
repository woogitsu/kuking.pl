<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * KIEDY wolno wysłać powiadomienie poza serwisem (etap 1 issue #35).
 *
 * Jedno miejsce z regułami limitu dobowego i ciszy nocnej, NIEZALEŻNE od
 * kanału. Web Push, a później może e-mail o zdarzeniu, mają pytać tę klasę,
 * zamiast każdy liczyć godziny po swojemu (`docs/product/RETENTION_LOOPS.md`
 * §3.2). Dziś żaden kanał jej nie woła, a flaga
 * `kuking.notifications.zewnetrzne.wlaczone` jest domyślnie wyłączona.
 *
 * Klasa nie zapisuje niczego i nie czyta bazy: liczbę powiadomień wysłanych
 * odbiorcy w bieżącej dobie podaje wołający, licząc od `poczatekDoby()`.
 * Dzięki temu reguły da się sprawdzić bez przeglądarki, VAPID i kolejki.
 *
 * ODŁOŻENIE, NIE SKASOWANIE. Cisza nocna i wyczerpany limit zwracają moment,
 * od którego wolno wysłać — nigdy „nie wysyłaj wcale". „Marek ugotował Twój
 * przepis" o 23:00 ma dojść o 8:00, a nie zniknąć.
 *
 * Doba i godziny są w strefie ODBIORCY. Konta nie mają dziś własnej strefy,
 * więc domyślna to `kuking.strefa` (Europe/Warsaw); wołający może podać inną.
 * Moment zwracany jest zawsze w UTC, tak jak liczy i zapisuje aplikacja.
 */
final class TerminPowiadomieniaZewnetrznego
{
    public const TERAZ = 'teraz';

    public const ODLOZ = 'odloz';

    public const KANAL_WYLACZONY = 'kanal_wylaczony';

    /**
     * @return array{decyzja: string, wyslij_od: ?CarbonImmutable}
     */
    public static function rozstrzygnij(CarbonInterface $teraz, int $wyslaneWTejDobie, ?string $strefa = null): array
    {
        $limit = (int) config('kuking.notifications.zewnetrzne.dzienny_limit', 1);

        if (! config('kuking.notifications.zewnetrzne.wlaczone', false) || $limit <= 0) {
            return ['decyzja' => self::KANAL_WYLACZONY, 'wyslij_od' => null];
        }

        $lokalnie = CarbonImmutable::instance($teraz)->setTimezone($strefa ?? self::strefa());
        $koniecCiszy = (int) config('kuking.notifications.zewnetrzne.cisza_do_godziny', 8);

        if ($wyslaneWTejDobie >= $limit) {
            // Następna doba, ale nie w środku jej ciszy nocnej.
            $jutro = $lokalnie->addDay()->startOfDay();

            return self::odloz(self::wCiszy($jutro) ? $jutro->setTime($koniecCiszy, 0) : $jutro);
        }

        if (! self::wCiszy($lokalnie)) {
            return ['decyzja' => self::TERAZ, 'wyslij_od' => null];
        }

        $rano = $lokalnie->setTime($koniecCiszy, 0);

        return self::odloz($rano->lessThanOrEqualTo($lokalnie) ? $rano->addDay() : $rano);
    }

    /** Początek lokalnej doby odbiorcy, w UTC — od tego momentu wołający liczy wysłane. */
    public static function poczatekDoby(CarbonInterface $teraz, ?string $strefa = null): CarbonImmutable
    {
        return CarbonImmutable::instance($teraz)
            ->setTimezone($strefa ?? self::strefa())
            ->startOfDay()
            ->utc();
    }

    private static function wCiszy(CarbonImmutable $lokalnie): bool
    {
        $od = (int) config('kuking.notifications.zewnetrzne.cisza_od_godziny', 21);
        $do = (int) config('kuking.notifications.zewnetrzne.cisza_do_godziny', 8);
        $godzina = $lokalnie->hour;

        if ($od === $do) {
            return false;
        }

        // 21 → 8 przechodzi przez północ; 1 → 5 nie.
        return $od > $do
            ? ($godzina >= $od || $godzina < $do)
            : ($godzina >= $od && $godzina < $do);
    }

    /**
     * @return array{decyzja: string, wyslij_od: CarbonImmutable}
     */
    private static function odloz(CarbonImmutable $lokalnie): array
    {
        return ['decyzja' => self::ODLOZ, 'wyslij_od' => $lokalnie->utc()];
    }

    private static function strefa(): string
    {
        return (string) config('kuking.strefa', 'Europe/Warsaw');
    }
}

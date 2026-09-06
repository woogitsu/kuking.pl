<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Limity zdjęć w JEDNYM miejscu — pochodne `config('kuking.media.*')`.
 *
 * PRZED TĄ KLASĄ TA SAMA FORMUŁA (`floor(max_bytes / 1024)`, żeby dostać
 * kilobajty na regułę walidacji `max:`) BYŁA PRZEPISANA RĘCZNIE W PIĘCIU
 * MIEJSCACH: PostController, CookedEventController, ProfileSettingsController
 * i dwa razy w RecipeController. Każde z nich mogło się rozjechać osobno —
 * a dokładnie taki rozjazd (różne miejsca, ta sama liczba, brak wspólnego
 * źródła) doprowadził do błędu z audytu A31 (post_max_size vs. config).
 *
 * Komunikaty są tutaj też, z tego samego powodu: dwie kopie tekstu
 * o tej samej liczbie potrafią się rozjechać równie łatwo, jak dwie kopie
 * samej liczby.
 */
final class LimityZdjec
{
    public static function maksBajtowJednegoZdjecia(): int
    {
        return (int) config('kuking.media.max_bytes');
    }

    /**
     * Reguła Laravel `max:` dla plików liczy w KILOBAJTACH. `floor`, celowo,
     * nie `round` — zaokrąglenie w górę przepuściłoby plik odrobinę większy
     * niż `max_bytes`, czyli walidacja pozwalałaby na więcej, niż obiecuje
     * konfiguracja.
     */
    public static function maksKilobajtowDoWalidacji(): int
    {
        return (int) floor(self::maksBajtowJednegoZdjecia() / 1024);
    }

    /** Do treści komunikatu — człowiek czyta megabajty, nie kilobajty. */
    public static function maksMegabajtowDoKomunikatu(): int
    {
        return (int) round(self::maksBajtowJednegoZdjecia() / 1024 / 1024);
    }

    /**
     * Ile zdjęć wolno dołączyć do JEDNEJ wysyłki (wpisu albo „Ugotowałem").
     *
     * Ta sama liczba steruje limitem w PostController I w
     * CookedEventController: to jest właśnie ta „jedna wysyłka" z audytu
     * A31, nie osobny limit na kontroler. Wcześniej „Ugotowałem" miało
     * wpisane na sztywno `max:4`, niezależnie od konfiguracji — ten sam
     * błąd, co rozjazd z `post_max_size`, tylko o jedno miejsce dalej.
     */
    public static function maksZdjecNaWysylke(): int
    {
        return (int) config('kuking.media.max_per_post');
    }

    public static function komunikatZaDuzyPlik(): string
    {
        return 'Jedno ze zdjęć waży za dużo. Maksymalny rozmiar to '.self::maksMegabajtowDoKomunikatu().' MB.';
    }

    public static function komunikatZaDuzoZdjec(): string
    {
        $limit = self::maksZdjecNaWysylke();

        if ($limit === 1) {
            // Najczęstszy dziś przypadek (limit = 1) ma osobne zdanie,
            // bo "maksymalnie 1 zdjęć" jest po prostu złą polszczyzną.
            return 'Do jednej wysyłki można dodać tylko jedno zdjęcie. Pozostałe wyślij osobno.';
        }

        return 'Do jednej wysyłki można dodać maksymalnie '.$limit.' '.self::odmianaZdjecia($limit).'.';
    }

    /** Polska odmiana: 1 zdjęcie, 2-4 zdjęcia, 0/5+/12-14 zdjęć. */
    private static function odmianaZdjecia(int $liczba): string
    {
        if ($liczba === 1) {
            return 'zdjęcie';
        }

        $ostatniaCyfra = $liczba % 10;
        $ostatnieDwieCyfry = $liczba % 100;

        if ($ostatniaCyfra >= 2 && $ostatniaCyfra <= 4 && ($ostatnieDwieCyfry < 12 || $ostatnieDwieCyfry > 14)) {
            return 'zdjęcia';
        }

        return 'zdjęć';
    }
}

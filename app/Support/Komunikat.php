<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Rodzaj komunikatu zwrotnego po akcji (issue #988).
 *
 * Wspólny layout rysuje `session('status')` jako plakietkę nad treścią. Do
 * września 2026 plakietka była zawsze zielona — także przy „Nie weszliśmy
 * kontem Google" albo „nic nie usunęliśmy". Tekst mówił „nie", wygląd mówił
 * „udało się". Rodzaj niesie teraz osobny klucz sesji `status_rodzaj`,
 * a layout dokłada do plakietki własny kolor, WIDOCZNĄ etykietę słowną
 * (kolor nie jest jedynym rozróżnieniem — WCAG 1.4.1) i rolę dla czytnika.
 *
 * Rodzaju NIE zgadujemy po treści („nie", „udało") — poprawny sukces też
 * bywa przeczeniem („Nie obserwujesz już tagu"). Wybiera go wywołujący:
 *
 *     return back()->with(Komunikat::blad('Tego tagu nie da się…'));
 *
 * Gołe `->with('status', …)` bez rodzaju zostaje sukcesem — tak wyglądało
 * dotąd i tak wygląda większość wywołań. Odmowy i błędy przechodzą na
 * `blad()`, stan niewymagający naprawy na `informacja()`.
 */
final class Komunikat
{
    public const SUKCES = 'sukces';

    public const INFORMACJA = 'informacja';

    public const BLAD = 'blad';

    /** Widoczna etykieta każdego rodzaju — czytana też przez czytnik ekranu. */
    public const ETYKIETY = [
        self::SUKCES => 'Gotowe',
        self::INFORMACJA => 'Informacja',
        self::BLAD => 'Nie udało się',
    ];

    public const KLUCZ_RODZAJU = 'status_rodzaj';

    /** @return array{status: string, status_rodzaj: string} */
    public static function sukces(string $tresc): array
    {
        return self::zRodzajem($tresc, self::SUKCES);
    }

    /** @return array{status: string, status_rodzaj: string} */
    public static function informacja(string $tresc): array
    {
        return self::zRodzajem($tresc, self::INFORMACJA);
    }

    /** @return array{status: string, status_rodzaj: string} */
    public static function blad(string $tresc): array
    {
        return self::zRodzajem($tresc, self::BLAD);
    }

    /**
     * Rodzaj bieżącego komunikatu z sesji. Nieznana albo brakująca wartość
     * daje sukces — dokładnie wygląd sprzed tej zmiany.
     */
    public static function rodzajZSesji(): string
    {
        $rodzaj = session(self::KLUCZ_RODZAJU);

        return is_string($rodzaj) && array_key_exists($rodzaj, self::ETYKIETY) ? $rodzaj : self::SUKCES;
    }

    /** @return array{status: string, status_rodzaj: string} */
    private static function zRodzajem(string $tresc, string $rodzaj): array
    {
        return ['status' => $tresc, self::KLUCZ_RODZAJU => $rodzaj];
    }
}

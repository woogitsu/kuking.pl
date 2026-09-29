<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Session\Session;

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
 * Gołego `->with('status', …)` w `app/` już nie ma — pilnuje tego
 * `StraznikKomunikatuTest` (issue #988). Każde wywołanie wybiera rodzaj:
 *
 *  - `sukces()`     — czynność została wykonana (zapisane, usunięte, wysłane);
 *  - `informacja()` — nic się nie zepsuło i nic nie trzeba naprawiać, ale
 *                     czynność niczego nie zmieniła („już to masz", „nie było
 *                     czego usuwać", drugie kliknięcie w to samo);
 *  - `blad()`       — czynność NIE zaszła albo człowiek musi coś zrobić
 *                     (odmowa, brak poczty, wygasły link, wyłączona funkcja,
 *                     niedostępny cel).
 *
 * Rodzaj `sukces` rysuje się na zielono, więc wybierając go, mówisz
 * czytelnikowi „udało się". Brak rodzaju (surowy klucz `status` w sesji,
 * np. z testu albo starego kodu) nadal wychodzi jako sukces, żeby nic nie
 * zniknęło — dlatego strażnik, a nie domyślna wartość, jest zabezpieczeniem.
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
     * Komunikat dla ŻĄDANIA, które nie zwraca przekierowania z `->with()`
     * (np. pomocnik wołany w środku przepływu): zapisuje w sesji parę
     * `status` + `status_rodzaj` jednym ruchem.
     *
     * @param  array{status: string, status_rodzaj: string}  $komunikat
     */
    public static function wSesji(Session $sesja, array $komunikat): void
    {
        foreach ($komunikat as $klucz => $wartosc) {
            $sesja->flash($klucz, $wartosc);
        }
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

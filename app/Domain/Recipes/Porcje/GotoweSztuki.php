<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Porcje;

/**
 * Ile gotowych sztuk wychodzi z przepisu — wspólne reguły autora (#2645).
 *
 * Jedno nazwane źródło dla kreatora (Livewire) i formularza szczegółów
 * (zwykły POST): normalizacja tego, co człowiek wpisał, reguły walidacji,
 * komunikaty mówiące CO ZROBIĆ i zdanie dla czytelnika. Liczba sztuk jest
 * OSOBNA od `recipes.servings` — „24 pierogi” nie mówią, ile osób nakarmi
 * przepis.
 */
final class GotoweSztuki
{
    public const NAJMNIEJ = 1;

    public const NAJWIECEJ = 9999;

    public const JEDNOSTKA_MAKS = 40;

    /** @var list<string> */
    public const REGULY_ILE = ['bail', 'nullable', 'integer', 'min:1', 'max:9999', 'required_with:yield_unit'];

    /** @var list<string> */
    public const REGULY_CO = ['nullable', 'string', 'max:40'];

    /** Komunikaty dla kluczy `yield_count` i `yield_unit`. */
    public const KOMUNIKATY = [
        'yield_count.integer' => 'Liczbę gotowych sztuk wpisz pełną liczbą, na przykład 24.',
        'yield_count.min' => 'Liczba gotowych sztuk musi być większa od zera. Wpisz na przykład 24 albo zostaw pole puste.',
        'yield_count.max' => 'Ta liczba sztuk jest nierealna. Wpisz najwyżej 9999 albo zostaw pole puste.',
        'yield_count.required_with' => 'Wpisz, ile sztuk wychodzi z przepisu, na przykład 24, albo wyczyść pole „Czego to sztuki”.',
        'yield_unit.max' => 'Opis sztuk jest za długi. Zostaw najwyżej 40 znaków, na przykład „pierogi”.',
    ];

    /** Tekst z pola jako liczba w postaci dla walidatora albo `null` dla pustego. */
    public static function normalizujIle(mixed $wartosc): ?string
    {
        if (! is_string($wartosc) && ! is_int($wartosc)) {
            return null;
        }

        $tekst = preg_replace('/\s+/u', '', (string) $wartosc) ?? '';

        return $tekst === '' ? null : $tekst;
    }

    /** Opis sztuk: przycięty, wielokrotne spacje w jedną; puste → `null`. */
    public static function normalizujCo(mixed $wartosc): ?string
    {
        if (! is_string($wartosc)) {
            return null;
        }

        $tekst = trim(preg_replace('/\s+/u', ' ', $wartosc) ?? '');

        return $tekst === '' ? null : $tekst;
    }

    /** Liczba z walidatora/bazy jako `int` albo `null`. */
    public static function naLiczbe(mixed $wartosc): ?int
    {
        $tekst = self::normalizujIle($wartosc);

        return $tekst !== null && preg_match('/^\d{1,4}$/', $tekst) === 1 ? (int) $tekst : null;
    }

    /** Pole formularza z liczby w bazie: „24” albo pusty tekst. */
    public static function doPola(mixed $ile): string
    {
        return $ile === null ? '' : (string) (int) $ile;
    }

    /** „24 szt.” albo „24 szt. (pierogi)”. */
    public static function etykieta(int|float $ile, ?string $co = null): string
    {
        $tekst = (int) round((float) $ile).' szt.';
        $co = $co === null ? null : trim($co);

        return $co === null || $co === '' ? $tekst : $tekst.' ('.$co.')';
    }
}

<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Profile;
use App\Support\NazwaUzytkownika;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Nazwa użytkownika zajęta — bez rozróżniania wielkości liter (audyt A25).
 *
 * CO SIĘ DZIAŁO
 * `Rule::unique('profiles', 'username')` porównuje przez `=`, a PostgreSQL
 * rozróżnia wielkość liter. Przy istniejącym koncie `basia` rejestracja
 * na `Basia` przechodziła i w bazie stały dwa profile różniące się jedną
 * literą, której na ekranie nie widać.
 *
 * DLACZEGO TO NIE JEST TYLKO BAŁAGAN
 * `LoginController::findUser()` szuka nazwy JUŻ bez rozróżniania wielkości
 * liter (bo „klawiatura telefonu podnosi pierwszą literę bez pytania").
 * Przy dwóch pasujących wierszach `->first()` zwraca jeden z nich —
 * bez `ORDER BY`, więc w praktyce ten, który baza akurat poda pierwszy.
 * Czyli: ktoś rejestruje `Basia` obok istniejącej `basia`, a prawdziwa
 * Basia zaczyna dostawać „nieprawidłowe hasło" przy poprawnym haśle
 * i nie ma jak się domyślić dlaczego.
 *
 * Do tego dochodzi zwykłe podszycie się: @Basia i @basia w komentarzach
 * to dla czytelnika ta sama osoba.
 *
 * DLACZEGO OSOBNA REGUŁA, A NIE `Rule::unique(...)->where(...)`
 * `Rule::unique` zawsze dokłada warunek `username = :wartosc` i tylko
 * DOKŁADA do niego kolejne. Nie da się przez to zamienić porównania na
 * `lower(username) = lower(:wartosc)` — dodatkowy warunek byłby złączony
 * przez AND z tym, który ma zniknąć.
 *
 * NAZWY ZOSTAJĄ ZAPISANE TAK, JAK KTOŚ JE WPISAŁ. Porównujemy bez
 * rozróżniania wielkości liter, ale nie przepisujemy cudzej nazwy na małe
 * litery: „AniaGotuje" ma zostać „AniaGotuje". Gwarancję po stronie bazy
 * daje unikalny indeks na `lower(username)` — patrz migracja
 * `2026_09_05_220000_add_username_case_insensitive_unique_index`.
 */
class UsernameNotTaken implements ValidationRule
{
    /**
     * @param  string|null  $ignoreUserId  Właściciel, którego własna nazwa
     *                                     nie jest dla niego „zajęta" — bez
     *                                     tego nie dałoby się zapisać profilu
     *                                     bez zmiany nazwy.
     */
    public function __construct(private readonly ?string $ignoreUserId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $zajeta = Profile::query()
            ->whereRaw('lower(username) = ?', [mb_strtolower(trim($value))])
            ->when($this->ignoreUserId !== null, fn ($query) => $query->where('user_id', '!=', $this->ignoreUserId))
            ->exists();

        if ($zajeta) {
            // Ten sam komunikat co przy nazwie zajętej co do znaku — dla
            // człowieka to jest ta sama sytuacja, a tłumaczenie „różnicie się
            // wielkością liter" tylko podpowiada, żeby spróbować inaczej.
            //
            // ZAJĘTE, ALE Z GOTOWYM WYJŚCIEM. „Spróbuj dodać coś na końcu" to
            // polecenie zadania do wykonania, a nie pomoc: człowiek musi sam
            // wymyślić, co dodać, i drugi raz trafić na wolne. Podajemy więc
            // KONKRETNĄ wolną nazwę, którą wystarczy przepisać — o ile da się
            // ją ułożyć.
            $propozycja = NazwaUzytkownika::wolnaPropozycja($value);

            $fail($propozycja === null
                ? 'Ta nazwa jest już zajęta. Spróbuj dodać coś na końcu.'
                : 'Ta nazwa jest już zajęta. Wolna jest: '.$propozycja.' — możesz ją wpisać.');
        }
    }
}

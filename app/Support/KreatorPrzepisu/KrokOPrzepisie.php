<?php

declare(strict_types=1);

namespace App\Support\KreatorPrzepisu;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as WalidatorLaravela;

/**
 * Pierwszy krok kreatora przepisu („o przepisie”): normalizacja pól, reguły
 * i polskie komunikaty — JEDNO nazwane źródło poza szablonem (issue #1387,
 * krok 1).
 *
 * Wydzielone z `resources/views/components/recipe-wizard.blade.php` bez
 * zmiany zachowania. Klasa nie zna Livewire'a, requestu ani widoku: dostaje
 * surowe wartości pól komponentu i zwraca walidator. Komponent dalej sam
 * decyduje, co zrobić z błędami (przenosi je do swojego worka błędów, nie
 * czyszcząc komunikatów innych kroków ani zdjęcia) — to jest orkiestracja
 * Livewire'a i zostaje tam.
 *
 * To NIE jest drugi przypadek użycia: reguły domenowe i transakcja zostają
 * w `PublishRecipe`. Tu stoją tylko reguły formularza, żeby błąd trafił przy
 * polu, zanim akcja domenowa w ogóle ruszy.
 *
 * Reguły są CELOWO identyczne z tym, co kreator miał dotąd, także tam, gdzie
 * różnią się od formularza bez JavaScriptu w `RecipeController` (np. brak
 * `decimal:0,2` przy porcjach, `source_type` wymagane). Wyrównanie obu dróg
 * to osobna, świadoma zmiana zachowania — nie refaktoryzacja.
 */
final class KrokOPrzepisie
{
    /** Pola komponentu, które ten krok sprawdza — w kolejności komunikatów. */
    public const POLA = [
        'title',
        'summary',
        'servings',
        'prep_minutes',
        'cook_minutes',
        'difficulty',
        'visibility',
        'source_type',
        'source_person',
        'source_note',
        'source_url',
        'family_since_year',
    ];

    /** Komunikaty mówią, CO ZROBIĆ (AGENTS.md §5). */
    public const KOMUNIKATY = [
        'title.required' => 'Podaj nazwę przepisu — na przykład „Rosół babci Zofii”.',
        'title.min' => 'Nazwa przepisu musi mieć co najmniej 3 znaki. Dopisz kilka liter.',
        'title.max' => 'Nazwa przepisu jest za długa. Skróć ją do 180 znaków.',
        'summary.max' => 'Krótki opis jest za długi. Zostaw najwyżej 2000 znaków — resztę wpisz w historii przepisu.',
        'servings.numeric' => 'Liczba porcji musi być liczbą. Wpisz na przykład 4.',
        'servings.min' => 'Liczba porcji musi być większa od zera. Wpisz na przykład 4.',
        'servings.max' => 'Ta liczba porcji jest nierealna. Wpisz najwyżej 999.',
        'prep_minutes.integer' => 'Czas przygotowania podaj w pełnych minutach, na przykład 20.',
        'prep_minutes.min' => 'Czas przygotowania nie może być ujemny. Wpisz na przykład 20.',
        'prep_minutes.max' => 'Czas przygotowania jest nierealnie długi. Wpisz najwyżej 10080 minut, czyli tydzień.',
        'cook_minutes.integer' => 'Czas gotowania podaj w pełnych minutach, na przykład 90.',
        'cook_minutes.min' => 'Czas gotowania nie może być ujemny. Wpisz na przykład 90.',
        'cook_minutes.max' => 'Czas gotowania jest nierealnie długi. Wpisz najwyżej 10080 minut, czyli tydzień.',
        'visibility.required' => 'Zaznacz, kto ma widzieć ten przepis.',
        'visibility.in' => 'Zaznacz, kto ma widzieć ten przepis.',
        'source_type.required' => 'Zaznacz, skąd jest ten przepis.',
        'source_type.in' => 'Zaznacz, skąd jest ten przepis.',
        'source_person.max' => 'To pole jest za długie. Zostaw najwyżej 120 znaków — wystarczy krótka wzmianka, na przykład „od mamy”.',
        'source_note.max' => 'Historia przepisu jest za długa. Zostaw najwyżej 2000 znaków.',
        'source_url.url' => 'Wklej adres strony zaczynający się od http:// lub https://.',
        'family_since_year.integer' => 'Rok wpisz czterema cyframi, na przykład 1974.',
        'family_since_year.min' => 'Ten rok jest za wczesny. Wpisz rok od 1850.',
        'family_since_year.max' => 'Ten rok jest za późny. Wpisz rok do 2100.',
    ];

    /**
     * Walidator kroku dla surowych wartości pól komponentu.
     *
     * @param  array<string, mixed>  $pola  wartości pól z `POLA`
     * @param  ?string  $dawnyAdres  `source_url` zapisany w BAZIE (nie w stanie
     *                               komponentu) albo null dla nowego przepisu
     */
    public static function walidator(array $pola, ?string $dawnyAdres): WalidatorLaravela
    {
        $dane = self::dane($pola);

        return Validator::make($dane, self::reguly($dawnyAdres, $dane['source_url']), self::KOMUNIKATY);
    }

    /**
     * Normalizacja przed walidacją: tytuł przycięty, pola opcjonalne puste
     * → null. `visibility` i `source_type` idą bez zmian — to wybór z listy.
     *
     * @param  array<string, mixed>  $pola
     * @return array<string, mixed>
     */
    public static function dane(array $pola): array
    {
        return [
            'title' => trim((string) ($pola['title'] ?? '')),
            'summary' => self::textOrNull($pola['summary'] ?? null),
            'servings' => self::textOrNull($pola['servings'] ?? null),
            'prep_minutes' => self::textOrNull($pola['prep_minutes'] ?? null),
            'cook_minutes' => self::textOrNull($pola['cook_minutes'] ?? null),
            'difficulty' => self::textOrNull($pola['difficulty'] ?? null),
            'visibility' => $pola['visibility'] ?? null,
            'source_type' => $pola['source_type'] ?? null,
            'source_person' => self::textOrNull($pola['source_person'] ?? null),
            'source_note' => self::textOrNull($pola['source_note'] ?? null),
            'source_url' => self::textOrNull($pola['source_url'] ?? null),
            'family_since_year' => self::textOrNull($pola['family_since_year'] ?? null),
        ];
    }

    /**
     * @param  ?string  $dawnyAdres  adres z bazy albo null
     * @param  ?string  $adres  adres po normalizacji (`dane()`)
     * @return array<string, list<string>>
     */
    public static function reguly(?string $dawnyAdres, ?string $adres): array
    {
        return [
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'summary' => ['nullable', 'string', 'max:2000'],
            'servings' => ['nullable', 'numeric', 'min:0.5', 'max:999'],
            'prep_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'cook_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'difficulty' => ['nullable', 'in:easy,medium,hard'],
            'visibility' => ['required', 'in:public,followers,private'],
            'source_type' => ['required', 'in:own,family,adaptation,external'],
            'source_person' => ['nullable', 'string', 'max:120'],
            'source_note' => ['nullable', 'string', 'max:2000'],
            // #900: jak w RecipeController — nowy lub zmieniony adres musi być
            // HTTP/HTTPS, niezmieniony dawny adres z bazy nie blokuje zapisu.
            'source_url' => ['nullable', $dawnyAdres !== null && $adres === $dawnyAdres ? 'url' : 'url:http,https', 'max:2000'],
            'family_since_year' => ['nullable', 'integer', 'min:1850', 'max:2100'],
        ];
    }

    private static function textOrNull(mixed $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}

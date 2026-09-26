<?php

declare(strict_types=1);

namespace App\Support\KreatorPrzepisu;

use App\Domain\Recipes\StepTimer;
use App\Exceptions\BladDlaCzlowieka;
use App\Support\LimityTekstuPrzepisu;

/**
 * Wiersze składników i kroków kreatora przepisu: walidacja i normalizacja
 * (issue #1387, krok 2).
 *
 * Wydzielone z `resources/views/components/recipe-wizard.blade.php` bez
 * zmiany zachowania. Klasa nie zna Livewire'a, requestu ani widoku: dostaje
 * surowe tablice wierszy z komponentu i zwraca albo mapę błędów
 * `klucz pola => komunikat`, albo oczyszczone wiersze dla `PublishRecipe`.
 * Komponent dalej sam decyduje, co z tym zrobić — przenosi błędy do swojego
 * worka błędów i wybiera krok do pokazania. To jest orkiestracja Livewire'a
 * i zostaje tam.
 *
 * To NIE jest drugi przypadek użycia: reguły domenowe (np. „przynajmniej
 * jeden krok”, przeliczenie minutnika na sekundy, „bez ilości” wygrywa
 * z ilością) i transakcja zostają w `PublishRecipe`. Tu stoją tylko granice
 * formularza, żeby błąd trafił PRZY POLU, zanim akcja domenowa w ogóle ruszy.
 *
 * Granice długości biorą się z `LimityTekstuPrzepisu` — tych samych liczb
 * używa formularz bez JavaScriptu (`ZapisPrzepisuRequest`). Komunikaty są
 * przeniesione z kreatora bajt w bajt; formularz bez JavaScriptu ma dla tych
 * pól własne zdania i ich wyrównanie to osobna, świadoma zmiana.
 */
final class WierszePrzepisu
{
    /**
     * Klucze błędów, które sprawdzenie wierszy czyści przed ponowną walidacją.
     * Nie ma tu `steps` (brak kroku — pilnuje publikacja) ani `steps.*.photo`
     * (zdjęcie ma własny stan błędu uploadu).
     */
    public const KLUCZE_BLEDOW = [
        'ingredients.*.text',
        'ingredients.*.group_name',
        'ingredients.*.note',
        'steps.*.instruction',
        'steps.*.timer_minutes',
    ];

    /** Komunikaty mówią, CO ZROBIĆ (AGENTS.md §5). Minutnik mówi zdaniami `StepTimer`. */
    public const KOMUNIKATY = [
        'ingredients.*.text' => 'Ten składnik jest za długi. Zostaw najwyżej 240 znaków albo rozbij go na dwa wiersze.',
        'ingredients.*.group_name' => 'Nazwa grupy jest za długa. Zostaw najwyżej 120 znaków, na przykład „Ciasto”.',
        'ingredients.*.note' => 'Ta uwaga jest za długa. Zostaw najwyżej 300 znaków.',
        'steps.*.instruction' => 'Ten krok jest za długi. Zostaw najwyżej 4000 znaków albo podziel go na dwa kroki.',
    ];

    /**
     * Błędy wierszy składników w kolejności wierszy i pól.
     *
     * @param  array<array-key, array<string, mixed>>  $wiersze
     * @return array<string, string> `ingredients.{indeks}.{pole}` => komunikat
     */
    public static function bledySkladnikow(array $wiersze): array
    {
        $bledy = [];

        foreach ($wiersze as $index => $row) {
            foreach (['text', 'group_name', 'note'] as $pole) {
                if (mb_strlen(trim((string) ($row[$pole] ?? ''))) > self::limit("ingredients.*.{$pole}")) {
                    $bledy["ingredients.{$index}.{$pole}"] = self::KOMUNIKATY["ingredients.*.{$pole}"];
                }
            }
        }

        return $bledy;
    }

    /**
     * Błędy wierszy przygotowania w kolejności wierszy i pól.
     *
     * @param  array<array-key, array<string, mixed>>  $wiersze
     * @return array<string, string> `steps.{indeks}.{pole}` => komunikat
     */
    public static function bledyKrokow(array $wiersze): array
    {
        $bledy = [];

        foreach ($wiersze as $index => $row) {
            if (mb_strlen(trim((string) ($row['instruction'] ?? ''))) > self::limit('steps.*.instruction')) {
                $bledy["steps.{$index}.instruction"] = self::KOMUNIKATY['steps.*.instruction'];
            }

            // Minutnik sprawdzamy TĄ SAMĄ bramką, która go potem przelicza
            // (`StepTimer`), a nie osobnym zestawem reguł obok. Inaczej
            // kreator przyjmowałby wartość, którą akcja domenowa i tak
            // odrzuci — i odrzuci ją nad całym formularzem, a nie przy polu.
            try {
                StepTimer::secondsFromMinutes($row['timer_minutes'] ?? null);
            } catch (BladDlaCzlowieka $e) {
                $bledy["steps.{$index}.timer_minutes"] = $e->getMessage();
            }
        }

        return $bledy;
    }

    /**
     * Puste wiersze są pomijane — pusty składnik nigdy nie trafia do bazy.
     *
     * @param  array<array-key, array<string, mixed>>  $wiersze
     * @return list<array{text: string, group_name: ?string, note: ?string, no_amount: bool}>
     */
    public static function skladniki(array $wiersze): array
    {
        $clean = [];

        foreach ($wiersze as $row) {
            $text = trim((string) ($row['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            $clean[] = [
                'text' => mb_substr($text, 0, self::limit('ingredients.*.text')),
                'group_name' => self::clampOrNull($row['group_name'] ?? null, self::limit('ingredients.*.group_name')),
                'note' => self::clampOrNull($row['note'] ?? null, self::limit('ingredients.*.note')),
                // „Bez ilości” — sól do smaku, mleko ile weźmie (issue #44).
                'no_amount' => (bool) ($row['no_amount'] ?? false),
            ];
        }

        return $clean;
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $wiersze
     * @return list<array{id: null, instruction: string, timer_minutes: string, media_id: ?string}>
     */
    public static function kroki(array $wiersze): array
    {
        $clean = [];

        foreach ($wiersze as $row) {
            $instruction = trim((string) ($row['instruction'] ?? ''));

            if ($instruction === '') {
                continue;
            }

            $clean[] = [
                // `id` ZAWSZE null, i to jest świadome.
                //
                // Formularz bez JavaScriptu musi odesłać identyfikator kroku,
                // bo zdjęcia nie umie przysłać drugi raz. Kreator zdjęcie
                // NIESIE — `mediaId` siedzi w wierszu i przeżywa każde
                // przestawienie kolejności, bo `swapRows()` przenosi cały
                // wiersz. Podanie tu `id` dodałoby DRUGĄ drogę do tego samego
                // zdjęcia, a przy pierwszym rozjeździe między nimi wygrywałaby
                // ta, o której nikt nie pamięta.
                'id' => null,
                'instruction' => mb_substr($instruction, 0, self::limit('steps.*.instruction')),
                'timer_minutes' => (string) ($row['timer_minutes'] ?? ''),
                // Brak `mediaId` znaczy tu „bez zdjęcia" wprost: nie ma `id`,
                // z którego dałoby się cokolwiek odziedziczyć.
                'media_id' => $row['mediaId'] ?? null,
            ];
        }

        return $clean;
    }

    private static function limit(string $pole): int
    {
        return LimityTekstuPrzepisu::POLA[$pole];
    }

    private static function clampOrNull(mixed $value, int $length): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $length);
    }
}

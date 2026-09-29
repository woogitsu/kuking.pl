<?php

declare(strict_types=1);

namespace App\Support\KreatorPrzepisu;

use App\Domain\Recipes\KosztPrzepisu;

/**
 * Mapowanie stanu kreatora przepisu na `attributes` dla `PublishRecipe`
 * (issue #1387, krok 5).
 *
 * Wydzielone z `persist()` w `resources/views/components/recipe-wizard.blade.php`
 * bez zmiany zachowania. Klasa nie zna Livewire'a, requestu, użytkownika ani
 * bazy: dostaje to, co człowiek wpisał w pola (teksty), i zwraca tablicę
 * z liczbami i przyciętymi tekstami. Komponent zostaje przy tym, co jest
 * granicą zaufania: autoryzacja przepisu (`existingRecipe()`), `#[Locked]`
 * identyfikatory, rewizja treści, adres IP i przekierowanie. Upload zdjęć
 * też zostaje w komponencie — tu wchodzą tylko gotowe identyfikatory mediów.
 *
 * To NIE jest drugi przypadek użycia: reguły domenowe i transakcja stoją
 * w `PublishRecipe`.
 */
final class DanePublikacji
{
    /**
     * @return array{
     *     title: string, summary: ?string, servings: ?float, estimated_cost_pln: ?float,
     *     prep_minutes: ?int, cook_minutes: ?int, difficulty: ?string, visibility: string,
     *     source_type: string, source_person: ?string, source_note: ?string, source_url: ?string,
     *     family_since_year: ?int, hero_media_id: ?string, source_scan_media_id: ?string,
     *     sprawdzilem_odczyt: bool, odczyt_sprawdzony: bool
     * }
     */
    public static function atrybuty(
        string $title,
        string $summary,
        string $servings,
        string $estimatedCostPln,
        string $prepMinutes,
        string $cookMinutes,
        string $difficulty,
        string $visibility,
        string $sourceType,
        string $sourcePerson,
        string $sourceNote,
        string $sourceUrl,
        string $familySinceYear,
        ?string $heroMediaId,
        ?string $sourceScanMediaId,
        bool $sprawdzilemOdczyt,
        bool $odczytSprawdzony,
    ): array {
        return [
            'title' => trim($title),
            'summary' => PodgladPrzepisu::tekstLubNull($summary),
            'servings' => PodgladPrzepisu::liczbaLubNull($servings),
            'estimated_cost_pln' => KosztPrzepisu::naLiczbe($estimatedCostPln),
            'prep_minutes' => PodgladPrzepisu::calkowitaLubNull($prepMinutes),
            'cook_minutes' => PodgladPrzepisu::calkowitaLubNull($cookMinutes),
            'difficulty' => PodgladPrzepisu::tekstLubNull($difficulty),
            'visibility' => $visibility,
            'source_type' => $sourceType,
            'source_person' => PodgladPrzepisu::tekstLubNull($sourcePerson),
            'source_note' => PodgladPrzepisu::tekstLubNull($sourceNote),
            'source_url' => PodgladPrzepisu::tekstLubNull($sourceUrl),
            'family_since_year' => PodgladPrzepisu::calkowitaLubNull($familySinceYear),
            'hero_media_id' => $heroMediaId,
            'source_scan_media_id' => $sourceScanMediaId,
            'sprawdzilem_odczyt' => $sprawdzilemOdczyt,
            'odczyt_sprawdzony' => $odczytSprawdzony,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use Livewire\Form;

/**
 * Stan formularza kreatora przepisu jako Livewire Form Object (issue #1387,
 * krok 3; decyzja właściciela z 25.09.2026).
 *
 * Komponent `recipe-wizard` trzyma ten obiekt we właściwości `$form`, więc
 * pola żyją w Livewire pod ścieżką `form.<pole>`: `wire:model="form.visibility"`,
 * błąd pod kluczem `form.visibility`, cel odnośnika z podsumowania błędów
 * `#f-form-visibility`.
 *
 * Od kroku 6 przechodzą tu WSZYSTKIE pola kroku „o przepisie”: nazwa, krótki
 * opis, porcje, koszt, oba czasy, trudność, widoczność i „Skąd ten przepis”.
 * Przemianowanie na `form.<pole>` było celowo osobnym, mechanicznym krokiem
 * (ponad sto ścieżek w testach kreatora), żeby krok 3 zostawał czytelny.
 *
 * CZEGO TU NIE MA — CELOWO:
 *  - reguł i komunikatów: jedno źródło to `KrokOPrzepisie`, wspólne dla
 *    wszystkich pól kroku;
 *  - zapisu: reguły domenowe i transakcja zostają w `PublishRecipe`
 *    (Form Object nie może stać się drugim przypadkiem użycia);
 *  - identyfikatorów przepisu i zdjęć: zostają w komponencie jako `#[Locked]`.
 *    Wszystko, co tu stoi, klient może zmienić — dlatego nic tu nie decyduje
 *    o tym, KTÓRY przepis jest zapisywany.
 */
final class PrzepisForm extends Form
{
    /** Pola kroku „o przepisie” trzymane w tym obiekcie. */
    public const POLA = [
        'title',
        'summary',
        'servings',
        'estimated_cost_pln',
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

    public string $title = '';

    public string $summary = '';

    public string $servings = '';

    /** Koszt całego przepisu w złotych, tak jak go wpisano („24,50") — D-286. */
    public string $estimated_cost_pln = '';

    public string $prep_minutes = '';

    public string $cook_minutes = '';

    public string $difficulty = '';

    public string $visibility = 'public';

    public string $source_type = 'own';

    public string $source_person = '';

    public string $source_note = '';

    public string $source_url = '';

    public string $family_since_year = '';

    /**
     * Surowe wartości pól pod nazwami, których używa `KrokOPrzepisie`
     * (bez przedrostka `form.`).
     *
     * @return array<string, string>
     */
    public function pola(): array
    {
        return [
            'title' => $this->title,
            'summary' => $this->summary,
            'servings' => $this->servings,
            'estimated_cost_pln' => $this->estimated_cost_pln,
            'prep_minutes' => $this->prep_minutes,
            'cook_minutes' => $this->cook_minutes,
            'difficulty' => $this->difficulty,
            'visibility' => $this->visibility,
            'source_type' => $this->source_type,
            'source_person' => $this->source_person,
            'source_note' => $this->source_note,
            'source_url' => $this->source_url,
            'family_since_year' => $this->family_since_year,
        ];
    }

    /**
     * Klucz błędu w worku komponentu dla pola o nazwie z `KrokOPrzepisie`:
     * `visibility` → `form.visibility`; nazwa spoza formularza (np. `steps`)
     * zostaje bez zmian.
     */
    public static function kluczBledu(string $pole, string $wlasciwosc = 'form'): string
    {
        return in_array($pole, self::POLA, true) ? $wlasciwosc.'.'.$pole : $pole;
    }
}

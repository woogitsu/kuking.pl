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
 * ETAP, NIE CAŁOŚĆ. W tym kroku przechodzą tu tylko pola, których nie dotyka
 * żaden otwarty PR kreatora: trudność, widoczność i „Skąd ten przepis”.
 * Nazwa, krótki opis, porcje i oba czasy zostają na razie we właściwościach
 * komponentu — zmiana ich nazw zderzyłaby się z testami i szablonem PR-ów
 * #1642, #1621, #1604, #1543 i #1594. Przejdą tu w następnym kroku.
 *
 * CZEGO TU NIE MA — CELOWO:
 *  - reguł i komunikatów: jedno źródło to `KrokOPrzepisie`, wspólne dla
 *    pól tu i pól, które jeszcze stoją w komponencie;
 *  - zapisu: reguły domenowe i transakcja zostają w `PublishRecipe`
 *    (Form Object nie może stać się drugim przypadkiem użycia);
 *  - identyfikatorów przepisu i zdjęć: zostają w komponencie jako `#[Locked]`.
 *    Wszystko, co tu stoi, klient może zmienić — dlatego nic tu nie decyduje
 *    o tym, KTÓRY przepis jest zapisywany.
 */
final class PrzepisForm extends Form
{
    /** Pola, które ten obiekt już przejął od komponentu. */
    public const POLA = [
        'difficulty',
        'visibility',
        'source_type',
        'source_person',
        'source_note',
        'source_url',
        'family_since_year',
    ];

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
     * `visibility` → `form.visibility`, a pole, które jeszcze stoi
     * w komponencie (`title`), zostaje bez zmian.
     */
    public static function kluczBledu(string $pole, string $wlasciwosc = 'form'): string
    {
        return in_array($pole, self::POLA, true) ? $wlasciwosc.'.'.$pole : $pole;
    }
}

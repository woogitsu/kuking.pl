<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Alergeny;

use App\Models\Recipe;

/**
 * To, co autor powiedział o alergenach w JEDNYM zapisie przepisu (#1902).
 *
 * Dwa pola, bo dwie różne rzeczy: `kody` — które alergeny zaznaczył,
 * `potwierdzone` — czy oświadcza, że lista jest pełna („Składniki
 * sprawdzone — zaznaczone alergeny to wszystkie, o których wiem”).
 * Zaznaczenie bez potwierdzenia NIE jest deklaracją i nie wolno go
 * zapisać jako `declared`. Brak obiektu (`null` w `PublishRecipe`) znaczy
 * „ta droga zapisu nie mówi nic o alergenach — bez zmian”.
 */
final readonly class DeklaracjaAlergenow
{
    /** @var list<string> kody ze słownika, bez powtórzeń, w kolejności słownika */
    public array $kody;

    /**
     * @param  array<array-key, mixed>  $kody  surowe kody z żądania; nieznane odpadają przy normalizacji
     */
    public function __construct(array $kody, public bool $potwierdzone)
    {
        $this->kody = Alergen::znormalizuj($kody);
    }

    /**
     * Czy ta deklaracja to COŚ INNEGO niż to, co przepis ma zapisane.
     *
     * Formularz bez JavaScriptu i kreator wysyłają cały stan pól przy
     * każdym zapisie. Niezmienione pola nie mogą być traktowane jak świeże
     * potwierdzenie: zostawione zaznaczone pole „sprawdzone” obok zmienionego
     * składnika unieważniłoby mechanizm `needs_review`. Za zmianę uznajemy
     * więc tylko rzeczywistą różnicę względem zapisanego stanu.
     */
    public function rozniSieOd(Recipe $przepis): bool
    {
        $zapisane = $przepis->allergens;
        sort($zapisane);
        $nowe = $this->kody;
        sort($nowe);

        return $zapisane !== $nowe
            || $this->potwierdzone !== $przepis->alergenyZdeklarowane();
    }

    /** Zaznaczone alergeny bez potwierdzenia — nie da się tego zapisać. */
    public function wymagaPotwierdzenia(): bool
    {
        return $this->kody !== [] && ! $this->potwierdzone;
    }
}

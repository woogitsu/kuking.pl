<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Porcje;

use App\Models\Recipe;
use App\Models\RecipeIngredient;

/**
 * Ile gotowych sztuk chce zrobić ten, kto patrzy na przepis (#2645, V2).
 *
 * Druga PODSTAWA przeliczenia obok porcji (`WyborPorcji`, D-284), nie druga
 * mnożarka: „24 pierogi → 36” daje współczynnik 36/24 = 1,5, dokładnie jak
 * 6 → 9 porcji, a składniki liczy ten sam `PrzeliczSkladnik` z tymi samymi
 * zasadami zaokrągleń i odmowy. Wybór żyje w adresie (`?sztuki=36`), więc
 * działa bez JavaScriptu i można go wysłać rodzinie.
 *
 * Przepis bez podanej liczby sztuk nie ma od czego liczyć — wtedy wybór jest
 * niedostępny, parametr jest ignorowany, a strona wygląda jak przed #2645.
 * Kiedy adres ma `?sztuki=`, ten wybór wygrywa z `?porcje=`: liczy się jedna
 * podstawa, współczynniki nigdy się nie mnożą.
 */
final readonly class WyborSztuk
{
    private function __construct(
        /** Sztuki z przepisu autora; `null` — autor ich nie podał. */
        public ?int $zPrzepisu,
        /** Sztuki, na które pokazujemy ilości. */
        public ?int $wybrane,
        /** Opis sztuk wg autora („pierogi”). */
        public ?string $co,
        /** Adres miał `?sztuki=`, ale z wartością, której nie da się użyć. */
        public bool $odrzucone,
        /** Co odesłać do pola: tylko coś, co wygląda jak liczba, nigdy surowy adres. */
        private string $wartoscPola,
    ) {}

    public static function dla(Recipe $recipe, mixed $zAdresu): self
    {
        $zPrzepisu = $recipe->yield_count === null ? null : (int) $recipe->yield_count;

        if ($zPrzepisu === null || $zPrzepisu < GotoweSztuki::NAJMNIEJ) {
            return new self(null, null, null, false, '');
        }

        $co = $recipe->yield_unit === null ? null : (trim((string) $recipe->yield_unit) ?: null);

        if ($zAdresu === null || $zAdresu === '') {
            return new self($zPrzepisu, $zPrzepisu, $co, false, (string) $zPrzepisu);
        }

        $tekst = is_int($zAdresu) || is_string($zAdresu) ? trim((string) $zAdresu) : null;

        if ($tekst === null || preg_match('/^\d{1,4}$/', $tekst) !== 1) {
            // Do pola wraca tylko coś, co wygląda jak liczba (cyfry, spacja,
            // przecinek, kropka, minus; najwyżej 10 znaków). Reszta adresu
            // nie wraca na stronę, a poprawny zapis człowieka nie znika.
            $bezpieczne = $tekst !== null && preg_match('/^[0-9 ,.\-]{1,10}$/', $tekst) === 1 ? $tekst : '';

            return new self($zPrzepisu, $zPrzepisu, $co, true, $bezpieczne);
        }

        $wybrane = (int) $tekst;

        if ($wybrane < GotoweSztuki::NAJMNIEJ || $wybrane > GotoweSztuki::NAJWIECEJ) {
            return new self($zPrzepisu, $zPrzepisu, $co, true, (string) $wybrane);
        }

        return new self($zPrzepisu, $wybrane, $co, false, (string) $wybrane);
    }

    /** Autor podał sztuki, więc jest od czego liczyć. */
    public function dostepny(): bool
    {
        return $this->zPrzepisu !== null;
    }

    /** Wybrano inną liczbę sztuk niż w przepisie autora. */
    public function przeliczone(): bool
    {
        return $this->zPrzepisu !== null && $this->wybrane !== null && $this->wybrane !== $this->zPrzepisu;
    }

    public function mnoznik(): float
    {
        if (! $this->przeliczone()) {
            return 1.0;
        }

        return (float) $this->wybrane / (float) $this->zPrzepisu;
    }

    /** Wartość do adresu — `null` dla liczby autora (powrót = adres bez parametru). */
    public function doAdresu(): ?string
    {
        return $this->przeliczone() ? (string) $this->wybrane : null;
    }

    /** Wartość pola „Na ile sztuk?” — zawsze bezpieczna do wypisania. */
    public function wartoscPola(): string
    {
        return $this->wartoscPola;
    }

    public function etykieta(): string
    {
        return GotoweSztuki::etykieta((int) $this->wybrane, $this->co);
    }

    public function etykietaAutora(): string
    {
        return GotoweSztuki::etykieta((int) $this->zPrzepisu, $this->co);
    }

    public function przelicz(RecipeIngredient $skladnik): PrzeliczonySkladnik
    {
        return PrzeliczSkladnik::przelicz($skladnik->ingredient_text, (bool) $skladnik->no_amount, $this->mnoznik());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Porcje;

use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Support\Odmiana;

/**
 * Ile porcji chce ugotować ten, kto patrzy na przepis (D-284, V2).
 *
 * Wybór żyje w adresie (`?porcje=6`), nie w sesji ani w bazie: przyciski
 * „Mniej” i „Więcej” to zwykłe linki, więc działają bez JavaScriptu,
 * da się je wysłać rodzinie i nie zostawiają śladu na koncie. Canonical
 * strony przepisu parametru nie zachowuje (`KanonicznyAdresStrony`), więc
 * wyszukiwarka widzi jedną stronę, a nie sto wariantów.
 *
 * Przepis bez podanej liczby porcji nie ma od czego liczyć — wtedy wybór
 * jest niedostępny i strona wygląda dokładnie tak jak przed D-284.
 */
final readonly class WyborPorcji
{
    public const NAJMNIEJ = 1;

    public const NAJWIECEJ = 100;

    private function __construct(
        /** Porcje z przepisu autora; `null` — autor ich nie podał. */
        public ?float $zPrzepisu,
        /** Porcje, na które pokazujemy ilości. */
        public ?float $wybrane,
        /** Adres miał `?porcje=`, ale z wartością, której nie da się użyć. */
        public bool $odrzucone,
    ) {}

    public static function dla(Recipe $recipe, mixed $zAdresu): self
    {
        $zPrzepisu = $recipe->servings === null ? null : round((float) $recipe->servings, 2);

        if ($zPrzepisu === null || $zPrzepisu <= 0) {
            return new self(null, null, false);
        }

        if ($zAdresu === null || $zAdresu === '') {
            return new self($zPrzepisu, $zPrzepisu, false);
        }

        $liczba = is_string($zAdresu) ? str_replace(',', '.', trim($zAdresu)) : null;

        if ($liczba === null || preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $liczba) !== 1) {
            return new self($zPrzepisu, $zPrzepisu, true);
        }

        $wybrane = (float) $liczba;

        // Liczba z przepisu jest zawsze do przyjęcia, nawet gdy leży poza
        // zakresem przycisków (przepis „na pół porcji”).
        if (abs($wybrane - $zPrzepisu) < 0.001) {
            return new self($zPrzepisu, $zPrzepisu, false);
        }

        if ($wybrane < self::NAJMNIEJ || $wybrane > self::NAJWIECEJ) {
            return new self($zPrzepisu, $zPrzepisu, true);
        }

        return new self($zPrzepisu, $wybrane, false);
    }

    public function dostepny(): bool
    {
        return $this->zPrzepisu !== null;
    }

    public function przeliczone(): bool
    {
        return $this->zPrzepisu !== null
            && $this->wybrane !== null
            && abs($this->wybrane - $this->zPrzepisu) >= 0.001;
    }

    public function mnoznik(): float
    {
        if (! $this->przeliczone()) {
            return 1.0;
        }

        return (float) $this->wybrane / (float) $this->zPrzepisu;
    }

    /** Jedna porcja mniej (do pełnej liczby), albo `null`, gdy mniej się nie da. */
    public function mniej(): ?float
    {
        if ($this->wybrane === null) {
            return null;
        }

        $kandydat = self::calkowita($this->wybrane) ? $this->wybrane - 1 : floor($this->wybrane);

        return $kandydat >= self::NAJMNIEJ ? $kandydat : null;
    }

    /** Jedna porcja więcej (do pełnej liczby), albo `null` powyżej limitu. */
    public function wiecej(): ?float
    {
        if ($this->wybrane === null) {
            return null;
        }

        $kandydat = self::calkowita($this->wybrane) ? $this->wybrane + 1 : ceil($this->wybrane);

        return $kandydat <= self::NAJWIECEJ ? $kandydat : null;
    }

    /**
     * Wartość do adresu — `null` dla liczby z przepisu, żeby powrót do
     * oryginału był adresem bez parametru, a nie jego kopią.
     */
    public function doAdresu(float $porcje): ?string
    {
        if ($this->zPrzepisu !== null && abs($porcje - $this->zPrzepisu) < 0.001) {
            return null;
        }

        return self::liczba($porcje, '.');
    }

    /** „4 porcje”, „1 porcja”, „2,5 porcji”. */
    public static function etykieta(float $porcje): string
    {
        return self::calkowita($porcje)
            ? self::liczba($porcje).' '.Odmiana::rzeczownik((int) round($porcje), 'porcja', 'porcje', 'porcji')
            : self::liczba($porcje).' porcji';
    }

    /** Biernik po „na”: „na 1 porcję”, „na 4 porcje”, „na 2,5 porcji”. */
    public static function naIle(float $porcje): string
    {
        return self::calkowita($porcje)
            ? 'na '.self::liczba($porcje).' '.Odmiana::rzeczownik((int) round($porcje), 'porcję', 'porcje', 'porcji')
            : 'na '.self::liczba($porcje).' porcji';
    }

    public function przelicz(RecipeIngredient $skladnik): PrzeliczonySkladnik
    {
        return PrzeliczSkladnik::przelicz($skladnik->ingredient_text, (bool) $skladnik->no_amount, $this->mnoznik());
    }

    private static function calkowita(float $liczba): bool
    {
        return abs($liczba - round($liczba)) < 0.001;
    }

    private static function liczba(float $liczba, string $przecinek = ','): string
    {
        if (self::calkowita($liczba)) {
            return (string) (int) round($liczba);
        }

        return rtrim(rtrim(number_format($liczba, 2, $przecinek, ''), '0'), $przecinek);
    }
}

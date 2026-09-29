<?php

declare(strict_types=1);

namespace App\Support\KreatorPrzepisu;

/**
 * Stan plakietki autozapisu kreatora: jeden z czterech stanów i tekst, który
 * człowiek czyta (issue #1387, krok 9).
 *
 * Wydzielone z `resources/views/components/recipe-wizard.blade.php` bez
 * zmiany ani jednego słowa komunikatów. Komponent przepisuje wynik do
 * publicznych pól `$saveState` i `$saveMessage` — te muszą zostać polami
 * Livewire'a, bo czyta je szablon i przeglądarka.
 *
 * Każdy komunikat błędu mówi, co się stało, i że tekst został w formularzu
 * — to zasada „poprawne dane nigdy nie znikają” z AGENTS.md.
 */
final readonly class StanZapisu
{
    /** Nic jeszcze się nie zapisało ani nie zawiodło. */
    public const PUSTY = '';

    public const ZAPISANY = 'saved';

    public const OCZEKUJE = 'waiting';

    public const BLAD = 'error';

    /** Tyle znaków nazwy wystarcza, żeby `PublishRecipe` utworzył przepis. */
    public const MIN_ZNAKOW_NAZWY = 3;

    private function __construct(
        public string $stan,
        public string $komunikat,
    ) {}

    /** Stan po wejściu do kreatora, zanim cokolwiek się wydarzyło. */
    public static function pusty(): self
    {
        return new self(self::PUSTY, '');
    }

    /** Bez nazwy nie da się utworzyć przepisu — mówimy wprost, czego brakuje. */
    public static function brakNazwy(bool $juzOpublikowany): self
    {
        return new self(
            self::OCZEKUJE,
            $juzOpublikowany ? 'Podaj nazwę przepisu, żeby zapisać zmiany.' : 'Szkic zapisze się, kiedy podasz nazwę przepisu.',
        );
    }

    /** Walidacja odrzuciła pola: wcześniejszy dobry zapis i tekst w UI zostają. */
    public static function bladPol(): self
    {
        return new self(self::BLAD, 'Nie zapisaliśmy tych zmian. Popraw zaznaczone pola. Cały tekst jest nadal w formularzu.');
    }

    /** Przepis zniknął albo nie należy do tej karty: komunikat pochodzi wprost od kreatora. */
    public static function bladIdentyfikatora(string $komunikat): self
    {
        return new self(self::BLAD, $komunikat);
    }

    /** `PublishRecipe` odmówił zapisu (np. strona nieaktualna, sankcja na koncie). */
    public static function bladZapisu(bool $juzOpublikowany, string $powod): self
    {
        return new self(
            self::BLAD,
            ($juzOpublikowany ? 'Nie udało się zapisać zmian: ' : 'Nie udało się zapisać szkicu: ').$powod.' Nic nie zginęło — cały tekst jest dalej w formularzu.',
        );
    }

    public static function zapisany(bool $juzOpublikowany): self
    {
        return new self(self::ZAPISANY, $juzOpublikowany ? 'Zmiany zapisane.' : 'Szkic zapisany.');
    }

    public function udany(): bool
    {
        return $this->stan === self::ZAPISANY;
    }
}

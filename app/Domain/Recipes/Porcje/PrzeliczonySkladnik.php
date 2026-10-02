<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Porcje;

/**
 * Wiersz składnika po przeliczeniu — w trzech kawałkach, żeby widok mógł
 * wyróżnić samą zmienioną ilość, a resztę zdania autora zostawić co do znaku.
 *
 * `zmieniony = false` znaczy: pokaż `przed` bez niczego — to jest dokładnie
 * `ingredient_text` (szczypta soli, „mleko — ile weźmie”, składnik bez
 * liczby, albo po prostu widz nie zmieniał porcji).
 */
final readonly class PrzeliczonySkladnik
{
    public function __construct(
        public string $przed,
        public string $ilosc = '',
        public string $po = '',
        public bool $zmieniony = false,
        /** Ilość zapisana jako suma — pokazana bez przeliczenia, z uwagą (#2609). */
        public bool $nieprzeliczony = false,
    ) {}

    /** Krótka informacja przy wierszu, którego ilości nie wolno przemnożyć po kawałku. */
    public const UWAGA_SUMA = 'Ilość podana jako suma – nie została przeliczona. Sprawdź ją samodzielnie.';

    public static function nieprzeliczony(string $tekst): self
    {
        return new self($tekst, nieprzeliczony: true);
    }

    public static function bezZmian(string $tekst): self
    {
        return new self($tekst);
    }

    public function tekst(): string
    {
        return $this->przed.$this->ilosc.$this->po;
    }
}

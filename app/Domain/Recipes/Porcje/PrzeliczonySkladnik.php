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
    ) {}

    public static function bezZmian(string $tekst): self
    {
        return new self($tekst);
    }

    public function tekst(): string
    {
        return $this->przed.$this->ilosc.$this->po;
    }
}

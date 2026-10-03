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
        /** Ilość wymagająca odmowy całego zapisu — z uwagą (#2609, #2882). */
        public bool $nieprzeliczony = false,
        public string $uwagaNieprzeliczenia = self::UWAGA_SUMA,
    ) {}

    /** Krótka informacja przy wierszu, którego ilości nie wolno przemnożyć po kawałku. */
    public const UWAGA_SUMA = 'Ilość podana jako suma – nie została przeliczona. Sprawdź ją samodzielnie.';

    public const UWAGA_ULAMKA = 'Zapis ułamka nie został przeliczony. Sprawdź ilość samodzielnie.';

    public static function nieprzeliczony(string $tekst, string $uwaga = self::UWAGA_SUMA): self
    {
        return new self($tekst, nieprzeliczony: true, uwagaNieprzeliczenia: $uwaga);
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

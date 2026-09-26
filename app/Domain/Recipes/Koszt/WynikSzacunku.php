<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Koszt;

/**
 * Wynik szacunku kosztu z cen: przedział ALBO powód, dla którego go nie ma.
 */
final readonly class WynikSzacunku
{
    private function __construct(
        public ?int $od,
        public ?int $do,
        public ?string $okres,
        public ?string $powod,
    ) {}

    public static function przedzial(int $od, int $do, string $okres): self
    {
        return new self($od, $do, $okres, null);
    }

    public static function bez(string $powod): self
    {
        return new self(null, null, null, $powod);
    }

    public function jestPrzedzial(): bool
    {
        return $this->od !== null && $this->do !== null;
    }

    /**
     * „Orientacyjny koszt: ok. 15–20 zł za całość (średnie ceny detaliczne
     * GUS z 2025 r.). W Twoim sklepie może być inaczej." — albo powód.
     */
    public function zdanie(): string
    {
        if (! $this->jestPrzedzial()) {
            return (string) $this->powod;
        }

        $kwota = $this->do <= 1 ? 'mniej niż 1 zł' : 'ok. '.$this->od.'–'.$this->do.' zł';

        return 'Orientacyjny koszt: '.$kwota.' za całość ('.$this->okres.'). W Twoim sklepie może być inaczej.';
    }
}

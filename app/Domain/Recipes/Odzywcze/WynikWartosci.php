<?php

declare(strict_types=1);

namespace App\Domain\Recipes\Odzywcze;

/**
 * Wynik szacunku wartości odżywczych przepisu (D-299).
 *
 * `policzone()` = true tylko wtedy, gdy znamy masę każdego składnika,
 * którego nie pomijamy jawnie, a składniki z tabeli dają co najmniej 90%
 * tej masy. W każdym innym przypadku liczb nie ma i widok mówi dlaczego.
 */
final class WynikWartosci
{
    public const PROG_POKRYCIA = 0.9;

    /**
     * @param  list<WierszWyliczenia>  $wiersze
     * @param  float|null  $porcje  liczba porcji z przepisu; null = wartości na cały przepis
     */
    public function __construct(
        public readonly array $wiersze,
        public readonly ?float $porcje,
        public readonly float $kcal,
        public readonly float $bialko,
        public readonly float $tluszcz,
        public readonly float $weglowodany,
    ) {}

    public function masaZnana(): float
    {
        return array_sum(array_map(
            static fn (WierszWyliczenia $w): float => in_array($w->stan, [WierszWyliczenia::POLICZONY, WierszWyliczenia::NIEZNANY_SKLADNIK], true) ? (float) $w->gramy : 0.0,
            $this->wiersze,
        ));
    }

    public function masaPoliczona(): float
    {
        return array_sum(array_map(
            static fn (WierszWyliczenia $w): float => $w->stan === WierszWyliczenia::POLICZONY ? (float) $w->gramy : 0.0,
            $this->wiersze,
        ));
    }

    /** Udział masy składników z tabeli w masie znanej, 0–1. */
    public function pokrycie(): float
    {
        $znana = $this->masaZnana();

        return $znana > 0 ? $this->masaPoliczona() / $znana : 0.0;
    }

    public function ile(string $stan): int
    {
        return count(array_filter($this->wiersze, static fn (WierszWyliczenia $w): bool => $w->stan === $stan));
    }

    public function policzone(): bool
    {
        return $this->ile(WierszWyliczenia::BEZ_MASY) === 0
            && $this->masaZnana() > 0
            && $this->pokrycie() >= self::PROG_POKRYCIA;
    }

    public function naPorcje(): bool
    {
        return $this->porcje !== null;
    }

    /** Energia do pokazania: zaokrąglona do 10 kcal. */
    public function kcalDoPokazania(): int
    {
        return (int) (round($this->kcal / 10) * 10);
    }

    /** Gramy do pokazania: zaokrąglone do 1 g. */
    public static function gramyDoPokazania(float $gramy): int
    {
        return (int) round($gramy);
    }

    /**
     * Zdania dla czytelnika, dlaczego liczb nie ma (§8.2 projektu, D-299).
     *
     * Rzeczownik PRZED liczbą („Składników …: 2”) — tak zdanie jest
     * poprawne po polsku dla 1, 2, 5 i 22 bez odmiany (D-132). Przykład
     * w cudzysłowie to tekst autora co do znaku, żeby było wiadomo, o który
     * wiersz chodzi. NIGDY nie prosimy autora o dopisanie gramów (D-017).
     *
     * @return list<string>
     */
    public function powodyBrakuLiczb(): array
    {
        if ($this->policzone()) {
            return [];
        }

        $powody = [];
        $bezMasy = $this->pierwszy(WierszWyliczenia::BEZ_MASY);

        if ($bezMasy !== null) {
            $powody[] = 'Składników bez ilości, którą da się przeliczyć na gramy: '.$this->ile(WierszWyliczenia::BEZ_MASY)
                .' (na przykład „'.$bezMasy->tekst.'”).';
        }

        $nieznany = $this->pierwszy(WierszWyliczenia::NIEZNANY_SKLADNIK);
        if ($nieznany !== null && $this->pokrycie() < self::PROG_POKRYCIA) {
            $powody[] = 'Składników spoza tabel, z których liczymy: '.$this->ile(WierszWyliczenia::NIEZNANY_SKLADNIK)
                .' (na przykład „'.$nieznany->tekst.'”). Znamy skład '.(int) floor($this->pokrycie() * 100)
                .'% masy przepisu, a liczymy dopiero od 90%.';
        }

        if ($powody === []) {
            $powody[] = 'Żaden składnik nie ma ilości, z której dałoby się to policzyć.';
        }

        if ($bezMasy !== null) {
            $powody[] = 'To nie błąd — tak się gotuje.';
        }

        return $powody;
    }

    private function pierwszy(string $stan): ?WierszWyliczenia
    {
        foreach ($this->wiersze as $w) {
            if ($w->stan === $stan) {
                return $w;
            }
        }

        return null;
    }
}

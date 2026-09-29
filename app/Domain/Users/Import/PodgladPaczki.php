<?php

declare(strict_types=1);

namespace App\Domain\Users\Import;

/**
 * Wynik czytania paczki: co w niej rozpoznano i co się z tym stanie. Nic nie jest zapisane.
 */
final readonly class PodgladPaczki
{
    /**
     * @param  list<PozycjaPodgladu>  $przepisy
     * @param  list<PozycjaPodgladu>  $wpisy
     * @param  list<PozycjaPodgladu>  $zeszyty
     * @param  list<string>  $pominiete  zdania o tym, czego import celowo nie odtwarza
     */
    public function __construct(
        public int $wersjaFormatu,
        public ?string $wygenerowano,
        public array $przepisy,
        public array $wpisy,
        public array $zeszyty,
        public array $pominiete,
    ) {}

    /** @return list<PozycjaPodgladu> */
    public function wszystkie(): array
    {
        return [...$this->przepisy, ...$this->wpisy, ...$this->zeszyty];
    }

    /** @return array<string, int> liczba pozycji w każdym stanie */
    public function liczbyStanow(): array
    {
        $liczby = [
            PozycjaPodgladu::NOWA => 0,
            PozycjaPodgladu::JUZ_JEST => 0,
            PozycjaPodgladu::POWTORZONA_W_PACZCE => 0,
            PozycjaPodgladu::ODRZUCONA => 0,
        ];

        foreach ($this->wszystkie() as $pozycja) {
            $liczby[$pozycja->stan]++;
        }

        return $liczby;
    }
}

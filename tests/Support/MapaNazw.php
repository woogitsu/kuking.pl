<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Import\Url\RozwiazywaczNazw;

/**
 * DNS dla testów importu z adresu (D-300): stała mapa nazwa → adresy.
 * Nazwa spoza mapy „nie istnieje" — żaden test nie pyta prawdziwej sieci.
 */
final class MapaNazw implements RozwiazywaczNazw
{
    /** @var list<string> */
    public array $pytania = [];

    /**
     * @param  array<string, list<string>>  $mapa
     */
    public function __construct(private array $mapa = []) {}

    public function ustaw(string $host, string ...$adresy): self
    {
        $this->mapa[$host] = array_values($adresy);

        return $this;
    }

    public function adresy(string $host): array
    {
        $this->pytania[] = $host;

        return $this->mapa[$host] ?? [];
    }
}

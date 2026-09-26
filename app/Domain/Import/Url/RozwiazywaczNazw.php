<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

/**
 * Zamiana nazwy hosta na adresy IP — wydzielona, żeby testy nie pytały
 * prawdziwego DNS-u (D-300: „żadnych prawdziwych żądań do sieci w testach").
 *
 * W aplikacji działa `SystemowyRozwiazywaczNazw`; testy podstawiają
 * własną mapę nazwa → adresy przez kontener.
 */
interface RozwiazywaczNazw
{
    /**
     * Wszystkie adresy IPv4 i IPv6, pod którymi stoi host. Pusta lista
     * znaczy „nazwa nie istnieje albo DNS nie odpowiedział".
     *
     * @return list<string>
     */
    public function adresy(string $host): array;
}

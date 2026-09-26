<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

/**
 * Adres po kontroli strażnika: schemat, host i port są dozwolone, a KAŻDY
 * adres IP, pod którym stoi host, jest publiczny.
 *
 * `ip` to adres, z którym pobieracz MA SIĘ połączyć (przypięty przez
 * `CURLOPT_RESOLVE`). Bez przypięcia biblioteka HTTP pytałaby DNS drugi raz,
 * a serwer nazw napastnika mógłby za drugim razem oddać `127.0.0.1`
 * (DNS rebinding) — kontrola byłaby wtedy kontrolą czegoś innego niż to,
 * z czym się łączymy.
 */
final class SprawdzonyAdres
{
    public function __construct(
        public readonly string $url,
        public readonly string $schemat,
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip,
    ) {}

    /** `host:port:ip` w formacie `CURLOPT_RESOLVE`; IPv6 w nawiasach kwadratowych. */
    public function przypiecie(): string
    {
        $ip = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;

        return $this->host.':'.$this->port.':'.$ip;
    }

    public function korzen(): string
    {
        $domyslny = $this->schemat === 'https' ? 443 : 80;

        return $this->schemat.'://'.$this->host.($this->port === $domyslny ? '' : ':'.$this->port);
    }
}

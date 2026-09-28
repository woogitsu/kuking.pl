<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

use LogicException;

/**
 * Adres po kontroli strażnika: schemat, host i port są dozwolone, a KAŻDY
 * adres IP, pod którym stoi host, jest publiczny.
 *
 * `ip` to adres, z którym pobieracz MA SIĘ połączyć (przypięty przez
 * `CURLOPT_RESOLVE`). Bez przypięcia biblioteka HTTP pytałaby DNS drugi raz,
 * a serwer nazw napastnika mógłby za drugim razem oddać `127.0.0.1`
 * (DNS rebinding) — kontrola byłaby wtedy kontrolą czegoś innego niż to,
 * z czym się łączymy.
 *
 * `url` jest składany przez strażnika z KANONICZNEGO hosta, więc host
 * w żądaniu i host w przypięciu to ten sam napis — konstruktor tego pilnuje.
 * Przed #1978 `https://example.com./` szło do cURL-a z kropką, a przypięcie
 * było dla `example.com`; cURL traktuje to jako dwie różne nazwy i drugi raz
 * pytał DNS.
 */
final class SprawdzonyAdres
{
    /**
     * @throws LogicException gdy adres i host nie są tym samym hostem —
     *                        przypięcie dotyczyłoby wtedy innej nazwy niż ta,
     *                        o którą zapyta cURL (#1978)
     */
    public function __construct(
        public readonly string $url,
        public readonly string $schemat,
        public readonly string $host,
        public readonly int $port,
        public readonly string $ip,
    ) {
        $czesci = parse_url($url);
        $hostAdresu = is_array($czesci) ? trim($czesci['host'] ?? '', '[]') : '';
        $portAdresu = is_array($czesci) ? ($czesci['port'] ?? ($schemat === 'https' ? 443 : 80)) : 0;

        if (! is_array($czesci) || ($czesci['scheme'] ?? '') !== $schemat || $hostAdresu !== $host || $portAdresu !== $port) {
            throw new LogicException('Adres do pobrania musi mieć dokładnie ten host, schemat i port, które sprawdził strażnik.');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false || (filter_var($host, FILTER_VALIDATE_IP) !== false && $host !== $ip)) {
            throw new LogicException('Przypięty adres IP musi być poprawnym adresem, a przy hoście-IP — tym samym adresem.');
        }
    }

    /**
     * `host:port:ip` w formacie `CURLOPT_RESOLVE`; IPv6 w nawiasach
     * kwadratowych. Null, gdy host JEST adresem IP — cURL wtedy niczego nie
     * rozwiązuje, więc nie ma czego przypinać.
     */
    public function przypiecie(): ?string
    {
        if (filter_var($this->host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $ip = str_contains($this->ip, ':') ? '['.$this->ip.']' : $this->ip;

        return $this->host.':'.$this->port.':'.$ip;
    }

    public function korzen(): string
    {
        $domyslny = $this->schemat === 'https' ? 443 : 80;
        $host = str_contains($this->host, ':') ? '['.$this->host.']' : $this->host;

        return $this->schemat.'://'.$host.($this->port === $domyslny ? '' : ':'.$this->port);
    }
}

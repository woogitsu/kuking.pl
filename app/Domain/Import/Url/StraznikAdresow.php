<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

use App\Domain\Import\ImportOdrzucony;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Ochrona przed SSRF przy imporcie przepisu z adresu strony (D-300).
 *
 * Serwer Kuking pobiera adres, który wkleił człowiek. Bez tej klasy
 * wklejenie `http://169.254.169.254/` albo `http://postgres.railway.internal/`
 * kazałoby NASZEMU serwerowi zajrzeć do sieci, do której człowiek z zewnątrz
 * nie ma dostępu. Dlatego:
 *
 *  1. tylko `http` i `https`, tylko porty 80 i 443, bez `user:hasło@`;
 *  2. host musi być nazwą domenową z kropką albo publicznym adresem IP —
 *     nazwy jednoczłonowe (`localhost`, `postgres`) i strefy wewnętrzne
 *     (`.internal`, `.local`, `.localhost`) odpadają bez pytania DNS-u;
 *  3. zapisy liczbowe, które resolver systemu czyta jako IP, a człowiek nie
 *     (`2130706433`, `0x7f.1`, `0177.0.0.1`), odpadają jako nieprawidłowe;
 *  4. nazwa jest rozwiązywana TUTAJ i KAŻDY z jej adresów musi być publiczny;
 *     pobieracz łączy się potem z tym samym, sprawdzonym adresem.
 *
 * Każde przekierowanie przechodzi przez tę klasę od nowa (`PobieraczStron`).
 */
final class StraznikAdresow
{
    public const MAKS_DLUGOSC_ADRESU = 2000;

    /**
     * Zakresy, do których serwer Kuking NIGDY się nie łączy.
     *
     * IPv4: „ta sieć", prywatne (RFC 1918), CGNAT, pętla zwrotna, link-local
     * (w tym metadane chmury 169.254.169.254), zakresy dokumentacyjne
     * i testowe, multicast, zarezerwowane, rozgłoszenie.
     *
     * IPv6: nieokreślony, pętla zwrotna, IPv4 zapisane jako IPv6 (mapped
     * i compatible — `::ffff:127.0.0.1` to wciąż 127.0.0.1), NAT64 i 6to4
     * (w środku siedzi adres IPv4, którego tu nie rozpakowujemy), Teredo
     * i przydziały IETF, dokumentacyjny, unikalne lokalne (ULA, `fc00::/7`),
     * link-local, site-local, multicast.
     *
     * @var list<string>
     */
    public const ZAKRESY_ZABLOKOWANE = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '::/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/23',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'fec0::/10',
        'ff00::/8',
    ];

    /**
     * Strefy, które z definicji nie są publicznym internetem. `.internal`
     * to m.in. prywatna sieć Railway (`*.railway.internal`).
     *
     * @var list<string>
     */
    private const STREFY_WEWNETRZNE = ['localhost', 'local', 'internal', 'intranet', 'lan', 'home', 'corp', 'localdomain', 'home.arpa'];

    public function __construct(private readonly RozwiazywaczNazw $dns) {}

    /**
     * @throws ImportOdrzucony
     */
    public function sprawdz(string $url): SprawdzonyAdres
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > self::MAKS_DLUGOSC_ADRESU || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPRAWIDLOWY);
        }

        $czesci = parse_url($url);

        if (! is_array($czesci) || ! isset($czesci['scheme'], $czesci['host'])) {
            throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPRAWIDLOWY);
        }

        $schemat = strtolower($czesci['scheme']);

        if (! in_array($schemat, ['http', 'https'], true)) {
            throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPRAWIDLOWY);
        }

        if (isset($czesci['user']) || isset($czesci['pass'])) {
            throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPRAWIDLOWY);
        }

        $port = isset($czesci['port']) ? (int) $czesci['port'] : ($schemat === 'https' ? 443 : 80);

        if (! in_array($port, [80, 443], true)) {
            throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPUBLICZNY);
        }

        $host = $this->normalizujHost($czesci['host']);

        // Host podany wprost jako adres IP — sprawdzamy bez DNS-u.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (! self::publiczny($host)) {
                throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPUBLICZNY);
            }

            return new SprawdzonyAdres($this->bezFragmentu($url), $schemat, $host, $port, $host);
        }

        $this->sprawdzNazwe($host);

        $adresy = $this->dns->adresy($host);

        if ($adresy === []) {
            throw new ImportOdrzucony(ImportOdrzucony::STRONA_NIEDOSTEPNA);
        }

        foreach ($adresy as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false || ! self::publiczny($ip)) {
                throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPUBLICZNY);
            }
        }

        return new SprawdzonyAdres($this->bezFragmentu($url), $schemat, $host, $port, $adresy[0]);
    }

    public static function publiczny(string $ip): bool
    {
        return ! IpUtils::checkIp($ip, self::ZAKRESY_ZABLOKOWANE);
    }

    private function normalizujHost(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));

        // parse_url zostawia nawiasy przy IPv6: `[::1]`.
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $wnetrze = substr($host, 1, -1);

            if (filter_var($wnetrze, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPRAWIDLOWY);
            }

            return $wnetrze;
        }

        // Nazwy z polskimi znakami (`przepisy-babci.pl` bywa `przepisy-bąbci.pl`)
        // zamieniamy na punycode, żeby reguły niżej widziały to, co zobaczy DNS.
        if (preg_match('/[^\x20-\x7E]/', $host) === 1) {
            $ascii = function_exists('idn_to_ascii') ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) : false;

            if (! is_string($ascii) || $ascii === '') {
                throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPRAWIDLOWY);
            }

            $host = strtolower($ascii);
        }

        return $host;
    }

    private function sprawdzNazwe(string $host): void
    {
        foreach (self::STREFY_WEWNETRZNE as $strefa) {
            if ($host === $strefa || str_ends_with($host, '.'.$strefa)) {
                throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPUBLICZNY);
            }
        }

        if (preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host) !== 1) {
            // Brak kropki (`localhost`, `postgres`), znaki spoza nazwy
            // domenowej albo zapisy w rodzaju `0x7f.1`, które nie są IP.
            if (preg_match('/^[0-9a-fx.]+$/', $host) === 1) {
                throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPUBLICZNY);
            }

            throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPRAWIDLOWY);
        }

        // Same cyfry i kropki, ale nie poprawny adres IPv4 w zapisie
        // dziesiętnym (`127.1`, `0177.0.0.1`, `2130706433.x`): resolver
        // systemu przeczytałby to jako IP, więc nie puszczamy do DNS-u.
        $ostatni = substr($host, (int) strrpos($host, '.') + 1);

        if (preg_match('/^[0-9]+$/', $ostatni) === 1 || preg_match('/^0x[0-9a-f]+$/', $ostatni) === 1) {
            throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPUBLICZNY);
        }

    }

    private function bezFragmentu(string $url): string
    {
        $pozycja = strpos($url, '#');

        return $pozycja === false ? $url : substr($url, 0, $pozycja);
    }
}

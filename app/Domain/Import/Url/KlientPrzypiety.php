<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

use App\Domain\Import\ImportOdrzucony;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * Jedno żądanie `GET` na adres sprawdzony przez `StraznikAdresow` — przez
 * cURL, z adresem IP przypiętym do DOKŁADNIE tej nazwy, której użyje cURL
 * (#1978, D-300).
 *
 * Trzy zabezpieczenia, każde niezależne od pozostałych:
 *
 *  1. `CURLOPT_RESOLVE` z `SprawdzonyAdres::przypiecie()`. Host w adresie
 *     i host w przypięciu to ten sam napis (pilnuje konstruktor
 *     `SprawdzonyAdres`), więc cURL nie pyta DNS-u drugi raz;
 *  2. `CURLOPT_PREREQFUNCTION` — po nawiązaniu połączenia, a PRZED wysłaniem
 *     choćby bajtu żądania cURL podaje adres, z którym się połączył. Jeśli to
 *     nie jest adres sprawdzony przez strażnika, żądanie jest przerywane.
 *     To siatka pod pkt 1: gdyby przypięcie kiedyś znów nie trafiło (inna
 *     wersja cURL-a, inny zapis nazwy), serwer i tak nie wyśle zapytania pod
 *     niesprawdzony adres;
 *  3. limit bajtów liczony w trakcie pobierania (`progress`), a nie po
 *     ściągnięciu całości, i bez rozpakowywania (`decode_content: false`,
 *     `Accept-Encoding: identity`) — 2 MB gzipa nie rozdmucha się do 2 GB.
 *
 * Dlaczego osobna klasa: do 26.09.2026 `PobieraczStron` ustawiał
 * `stream: true`, a Guzzle przy tej opcji wybiera obsługę strumieniową PHP
 * zamiast cURL-a i odrzuca opcję `curl` — przypięcie nie działało nigdy,
 * a testy na `Http::fake` tego nie widziały. Ta klasa ma test na prawdziwym
 * cURL-u i lokalnym serwerze (`ImportKlientPrzypietyTest`).
 */
final class KlientPrzypiety
{
    /**
     * @throws ImportOdrzucony
     */
    public function pobierz(SprawdzonyAdres $adres, string $accept, int $maksBajtow, float $limitPolaczenia, float $limitZadania): Response
    {
        $naglowki = null;
        $przekroczono = false;
        $obcyAdres = null;

        try {
            return Http::withOptions([
                'allow_redirects' => false,
                'proxy' => '',
                'protocols' => ['http', 'https'],
                'decode_content' => false,
                'connect_timeout' => $limitPolaczenia,
                'timeout' => $limitZadania,
                'on_headers' => static function (ResponseInterface $odpowiedz) use (&$naglowki): void {
                    $naglowki = $odpowiedz;
                },
                'progress' => static function (int|float $calosc, int|float $pobrane) use ($maksBajtow, &$przekroczono): bool {
                    if ($pobrane > $maksBajtow) {
                        $przekroczono = true;

                        return true;
                    }

                    return false;
                },
                'curl' => [
                    CURLOPT_RESOLVE => array_filter([$adres->przypiecie()]),
                    CURLOPT_PREREQFUNCTION => static function (mixed $uchwyt, string $polaczony) use ($adres, &$obcyAdres): int {
                        if (self::tenSamAdres($polaczony, $adres->ip)) {
                            return CURL_PREREQFUNC_OK;
                        }

                        $obcyAdres = $polaczony;

                        return CURL_PREREQFUNC_ABORT;
                    },
                ],
            ])->withHeaders([
                'User-Agent' => PobieraczStron::userAgent(),
                'Accept' => $accept,
                'Accept-Encoding' => 'identity',
            ])->get($adres->url);
        } catch (ConnectionException|RequestException $e) {
            if ($obcyAdres !== null) {
                throw new ImportOdrzucony(ImportOdrzucony::ADRES_NIEPUBLICZNY);
            }

            // Przerwane przez limit bajtów: oddajemy to, co przyszło, a decyzję
            // (odmowa czy obcięcie) podejmuje wołający — dla strony to
            // „za duża", dla robots.txt pierwsze 500 KiB (RFC 9309 §2.5).
            if ($przekroczono && $naglowki instanceof ResponseInterface) {
                $naglowki->getBody()->rewind();

                return new Response($naglowki);
            }

            $komunikat = $e->getMessage();

            throw new ImportOdrzucony(
                str_contains(strtolower($komunikat), 'timed out') || str_contains($komunikat, 'cURL error 28')
                    ? ImportOdrzucony::ZA_DLUGO
                    : ImportOdrzucony::STRONA_NIEDOSTEPNA,
            );
        }
    }

    /**
     * Czy adres, z którym cURL się połączył, to adres sprawdzony przez
     * strażnika. Porównanie binarne, więc `::1` i `0:0::1` to ten sam adres;
     * IPv4 zgłoszony przez gniazdo IPv6 jako `::ffff:a.b.c.d` też.
     */
    public static function tenSamAdres(string $polaczony, string $sprawdzony): bool
    {
        $a = @inet_pton(trim($polaczony, '[]'));
        $b = @inet_pton($sprawdzony);

        if ($a === false || $b === false) {
            return false;
        }

        $mapped = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

        if (strlen($a) === 16 && str_starts_with($a, $mapped)) {
            $a = substr($a, 12);
        }

        if (strlen($b) === 16 && str_starts_with($b, $mapped)) {
            $b = substr($b, 12);
        }

        return $a === $b;
    }
}

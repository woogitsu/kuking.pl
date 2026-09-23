<?php

declare(strict_types=1);

namespace App\Support;

use GuzzleHttp\Psr7\Uri;
use Throwable;

/**
 * Czy adres z konfiguracji prowadzi do dostawcy, któremu wolno dać klucz (#991).
 *
 * PO CO TO JEST
 * Adres API poczty, moderacji i czyszczenia CDN przychodzi ze zmiennej
 * środowiskowej. Klient dokłada do żądania klucz API i treść: cudze listy,
 * cudze wpisy do oceny. Samo „zaczyna się od https://" przepuszczało KAŻDY
 * host — literówka albo podmieniona zmienna wysyłała sekret i dane ludzi
 * pod obcy adres, po cichu i z zieloną odpowiedzią.
 *
 * LISTA JEST W KODZIE, NIE W `.env`
 * Gdyby dało się ją rozszerzyć zmienną, ta sama osoba, która może podmienić
 * adres, dopisałaby też host. Nowy host dostawcy to zmiana w kodzie z testem.
 *
 * ADRES CZYTAMY TYM SAMYM PARSEREM, KTÓRYM WYSYŁA GUZZLE
 * Różnica między dwoma parserami (`parse_url` a `Uri`) to klasyczna droga
 * obejścia listy hostów. Ponadto odrzucamy: inny schemat niż `https`, dane
 * logowania w adresie (`https://api.openai.com@obcy.pl`), port inny niż 443
 * oraz odwrotny ukośnik i białe znaki, które przeglądarki i parsery czytają
 * różnie.
 */
final class DozwolonyHostApi
{
    /**
     * @param  list<string>  $hosty  dokładne nazwy hostów, małymi literami
     */
    public static function zgodny(string $adres, array $hosty): bool
    {
        if ($adres === '' || preg_match('/[\s\\\\]/', $adres) === 1) {
            return false;
        }

        try {
            $uri = new Uri($adres);
        } catch (Throwable) {
            return false;
        }

        if (strtolower($uri->getScheme()) !== 'https' || $uri->getUserInfo() !== '') {
            return false;
        }

        $port = $uri->getPort();

        if ($port !== null && $port !== 443) {
            return false;
        }

        return in_array(strtolower($uri->getHost()), $hosty, true);
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

use GuzzleHttp\Psr7\Uri;
use Throwable;

/**
 * Czy adres z konfiguracji prowadzi do dostawcy, któremu wolno dać klucz (#991, D-250).
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
 * adres, dopisałaby też host. Nowy host albo nowa ścieżka dostawcy to zmiana
 * w kodzie z testem.
 *
 * ADRES CZYTAMY TYM SAMYM PARSEREM, KTÓRYM WYSYŁA GUZZLE
 * Różnica między dwoma parserami (`parse_url` a `Uri`) to klasyczna droga
 * obejścia listy hostów. Każdą część sprawdzamy OSOBNO:
 *
 *  - schemat: tylko `https`;
 *  - dane logowania: żadnych, a znaku `@` nie ma prawa być nigdzie w adresie
 *    (`https://api.openai.com@obcy.pl`, `https://api.openai.com#@obcy.pl`);
 *  - host: dokładnie z listy — bez końcowej kropki, bez IDN i homoglifów
 *    (porównanie bajt w bajt z nazwą ASCII);
 *  - port: brak albo 443;
 *  - ścieżka: CAŁA musi pasować do wzoru dostawcy (kotwice `^…$`), więc
 *    `..`, `%2e`, dopisek za ścieżką i inna metoda API odpadają;
 *  - query i fragment: żadnych — żadna z naszych integracji ich nie używa;
 *  - odwrotny ukośnik, białe i sterujące znaki: odrzucone przed parsowaniem,
 *    bo przeglądarki i parsery czytają je różnie.
 */
final class DozwolonyHostApi
{
    /**
     * @param  list<string>  $hosty  dokładne nazwy hostów, małymi literami
     * @param  string  $sciezka  wyrażenie regularne na CAŁĄ ścieżkę, z kotwicami `^…$`
     */
    public static function zgodny(string $adres, array $hosty, string $sciezka): bool
    {
        return self::powod($adres, $hosty, $sciezka) === null;
    }

    /**
     * Która część adresu jest zła — nazwa części, NIGDY jej wartość (wartość
     * bywa wklejona razem z tokenem). `null` = adres zgodny.
     *
     * @param  list<string>  $hosty
     */
    public static function powod(string $adres, array $hosty, string $sciezka): ?string
    {
        if ($adres === '') {
            return 'pusty adres';
        }

        if (preg_match('/[\s\\\\@\x00-\x1F\x7F]/', $adres) === 1) {
            return 'niedozwolony znak w adresie';
        }

        try {
            $uri = new Uri($adres);
        } catch (Throwable) {
            return 'adres, którego nie da się odczytać';
        }

        if (strtolower($uri->getScheme()) !== 'https') {
            return 'schemat inny niż https';
        }

        if ($uri->getUserInfo() !== '') {
            return 'dane logowania w adresie';
        }

        if (! in_array(strtolower($uri->getHost()), $hosty, true)) {
            return 'host spoza listy dostawcy';
        }

        $port = $uri->getPort();

        if ($port !== null && $port !== 443) {
            return 'port inny niż 443';
        }

        if (preg_match($sciezka, $uri->getPath()) !== 1) {
            return 'ścieżka spoza API dostawcy';
        }

        if ($uri->getQuery() !== '' || str_contains($adres, '?')) {
            return 'parametry zapytania (?) w adresie';
        }

        if ($uri->getFragment() !== '' || str_contains($adres, '#')) {
            return 'fragment (#) w adresie';
        }

        return null;
    }
}

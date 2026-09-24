<?php

declare(strict_types=1);

namespace App\Support\Storage;

use GuzzleHttp\Psr7\Uri;
use Throwable;

/**
 * Czy adres magazynu z konfiguracji prowadzi do R2 w jurysdykcji UE (D-255).
 *
 * PO CO TO JEST
 * Każdy dysk R2/S3 (zdjęcia, eksporty RODO, kopie bazy) podpisuje żądania
 * kluczem z `AWS_*` i wysyła je pod `AWS_ENDPOINT`. Do 24.09.2026 nikt tego
 * adresu nie sprawdzał: literówka albo podmieniona zmienna wysyłała podpisane
 * żądania, oryginały z pełnym EXIF-em i paczki z danymi ludzi pod obcy host —
 * po cichu. Pusty adres był jeszcze gorszy: AWS SDK idzie wtedy do Amazona.
 *
 * WZÓR TO DECYZJA WŁAŚCICIELA, NIE WYGODA
 * Jedyny dopuszczony host to `<32 znaki hex>.eu.r2.cloudflarestorage.com`.
 * Segment `eu` nie jest ozdobą: bucket z jurysdykcją UE jest osiągalny
 * WYŁĄCZNIE przez taki endpoint, a endpoint jurysdykcyjny nie sięga do
 * bucketów spoza niej (`docs/infra/LOKALIZACJA_DANYCH_R2.md` §2). Strażnik
 * przypina więc dane do UE — dysk bez `.eu.` się nie zbuduje.
 *
 * WZÓR JEST W KODZIE, NIE W `.env`
 * Ta sama osoba, która może podmienić adres, dopisałaby wyjątek. Zmiana
 * konta Cloudflare to zmiana samej zmiennej (identyfikator konta pasuje do
 * wzoru); zmiana jurysdykcji to zmiana tej klasy z testem i decyzją.
 *
 * ADRES CZYTAMY TYM SAMYM PARSEREM, KTÓRYM WYSYŁA AWS SDK (Guzzle `Uri`),
 * i każdą część sprawdzamy osobno — to ten sam układ co
 * `App\Support\DozwolonyHostApi` ze strażnika kluczy API (#991, PR #1443). Osobna klasa, bo tam lista
 * dokładnych hostów, a tu wzór z identyfikatorem konta i ścieżka „/".
 *
 * ŚRODOWISKO LOCAL/TESTING
 * Tam nie ma prawdziwych sekretów, a testy i przyrządy budują dyski na
 * adresach, które NIE ISTNIEJĄ w internecie: zarezerwowane domeny
 * (RFC 2606/6761: `.test`, `.invalid`, `.example`, `.localhost`) i pętla
 * zwrotna (MinIO z `PomiarOdcieciaDostepuDoPlikuTest`). Tylko tam wolno też
 * pusty adres (dyski R2 zbudowane „na sucho" przez `KonfiguracjaDyskowTest`).
 * Na produkcji i każdym innym środowisku — wyłącznie wzór.
 */
final class DozwolonyHostR2
{
    /** Host endpointu R2 w jurysdykcji UE; `[0-9a-f]{32}` to identyfikator konta. */
    public const WZOR_HOSTA = '/^[0-9a-f]{32}\.eu\.r2\.cloudflarestorage\.com$/';

    /** Hosty, które w local/testing nie opuszczają maszyny. */
    private const HOSTY_LOKALNE = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /** Zarezerwowane domeny najwyższego poziomu — nie rozwiązują się w internecie. */
    private const DOMENY_ZAREZERWOWANE = ['.test', '.invalid', '.example', '.localhost'];

    /**
     * Rzuca, gdy adres jest zły. Komunikat nazywa zmienną i SAM host —
     * nigdy cały adres (bywa wklejony z kluczem w userinfo).
     */
    public static function wymus(string $adres, string $zmienna = 'AWS_ENDPOINT'): void
    {
        $powod = self::powod($adres);

        if ($powod === null) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'Dysk magazynu R2 nie został zbudowany: %s wskazuje na %s (%s). '
            .'Dozwolony jest wyłącznie adres https://<identyfikator konta>.eu.r2.cloudflarestorage.com '
            .'— bez portu, ścieżki i danych logowania (D-255). Klucz nie został nigdzie wysłany.',
            $zmienna,
            self::opisHosta($adres),
            $powod,
        ));
    }

    /**
     * Która część adresu jest zła — opis części, nigdy jej wartość.
     * `null` = adres zgodny.
     */
    public static function powod(string $adres, ?bool $srodowiskoLokalne = null): ?string
    {
        $srodowiskoLokalne ??= app()->environment(['local', 'testing']);

        if ($adres === '') {
            return $srodowiskoLokalne ? null : 'pusty adres';
        }

        if (preg_match('/[\s\\\\@\x00-\x1F\x7F]/', $adres) === 1) {
            return 'niedozwolony znak w adresie';
        }

        try {
            $uri = new Uri($adres);
        } catch (Throwable) {
            return 'adres, którego nie da się odczytać';
        }

        $host = strtolower($uri->getHost());

        if ($srodowiskoLokalne && self::hostLokalny($host)) {
            return $uri->getUserInfo() === '' ? null : 'dane logowania w adresie';
        }

        if (strtolower($uri->getScheme()) !== 'https') {
            return 'schemat inny niż https';
        }

        if ($uri->getUserInfo() !== '') {
            return 'dane logowania w adresie';
        }

        if (preg_match(self::WZOR_HOSTA, $host) !== 1) {
            return 'host spoza R2 w jurysdykcji UE';
        }

        // `Uri` gubi port domyślny (`:443`), więc patrzymy też na surowy tekst:
        // `@` odpadł wyżej, więc jedyny `:` w części hosta to port.
        if ($uri->getPort() !== null || preg_match('#^[a-z][a-z0-9+.\-]*://[^/?\#]*:#i', $adres) === 1) {
            return 'port w adresie';
        }

        if (! in_array($uri->getPath(), ['', '/'], true)) {
            return 'ścieżka w adresie';
        }

        if ($uri->getQuery() !== '' || str_contains($adres, '?')) {
            return 'parametry zapytania (?) w adresie';
        }

        if ($uri->getFragment() !== '' || str_contains($adres, '#')) {
            return 'fragment (#) w adresie';
        }

        return null;
    }

    private static function hostLokalny(string $host): bool
    {
        if (in_array($host, self::HOSTY_LOKALNE, true)) {
            return true;
        }

        foreach (self::DOMENY_ZAREZERWOWANE as $domena) {
            if (str_ends_with($host, $domena)) {
                return true;
            }
        }

        return false;
    }

    /** Sam host (bez schematu, userinfo, ścieżki) — albo opis, gdy go nie ma. */
    public static function opisHosta(string $adres): string
    {
        if ($adres === '') {
            return 'pusty adres';
        }

        try {
            $host = (new Uri($adres))->getHost();
        } catch (Throwable) {
            $host = '';
        }

        // Tylko znaki, które mogą stać w nazwie hosta — nic, co przemyciłoby
        // resztę adresu do logu.
        $host = (string) preg_replace('/[^a-zA-Z0-9.\-\[\]:]/', '', $host);

        return $host === '' ? 'adres bez hosta' : "host „{$host}”";
    }
}

<?php

declare(strict_types=1);

namespace App\Support\Storage;

use GuzzleHttp\Psr7\Uri;
use Throwable;

/**
 * Hosty, spod których przeglądarka ma prawo wczytać zdjęcia i media (#2381).
 *
 * PO CO TO JEST
 * `img-src` miało `https:`, czyli każdy host w internecie. Baseline
 * (`docs/legal/SECURITY_BASELINE.md`) wymaga dokładnych domen. Lista jest
 * WYPROWADZANA Z KONFIGURACJI, nie wpisana na sztywno — endpoint R2 zawiera
 * identyfikator konta, którego w repozytorium nie ma.
 *
 * KTÓRĘDY NAPRAWDĘ IDZIE ZDJĘCIE
 * `Media::url()` zwraca trasę aplikacji (`media.show`, czyli 'self'), a ta
 * przekierowuje 302 na krótko podpisany adres bucketu wariantów. CSP
 * sprawdza także cel przekierowania, więc ten host musi być na liście:
 * `<bucket>.<konto>.eu.r2.cloudflarestorage.com` (adresowanie przez host,
 * `use_path_style_endpoint => false`) albo sam host endpointu przy stylu
 * ścieżkowym. Do tego własne domeny z `KUKING_R2_PUBLICZNE_ADRESY`.
 *
 * Na dysku lokalnym (`public`) zdjęcia idą przez PHP spod 'self' — nic
 * więcej nie dochodzi.
 */
final class ZrodlaZdjecDlaCsp
{
    /** Dyski, z których mogą iść WARIANTY zdjęć (`Media::variantsDisk()`). */
    private const DYSKI_WARIANTOW = ['r2_publiczne', 'r2_legacy'];

    /**
     * @return list<string> originy (`https://host`), bez powtórzeń
     */
    public static function hosty(): array
    {
        $dyski = array_unique([...self::DYSKI_WARIANTOW, (string) config('kuking.media.public_disk')]);

        $originy = [];

        foreach ($dyski as $nazwa) {
            $konfiguracja = config("filesystems.disks.{$nazwa}");

            if (! is_array($konfiguracja) || ($konfiguracja['driver'] ?? null) === 'local') {
                continue;
            }

            $bucket = (string) ($konfiguracja['bucket'] ?? '');
            $endpoint = self::origin((string) ($konfiguracja['endpoint'] ?? ''));

            if ($endpoint !== null) {
                [$schemat, $host] = $endpoint;
                $stylSciezkowy = (bool) ($konfiguracja['use_path_style_endpoint'] ?? false);

                $originy[] = $stylSciezkowy || $bucket === ''
                    ? "{$schemat}://{$host}"
                    : "{$schemat}://{$bucket}.{$host}";
            }

            // Własna domena bucketu (`url`) — tylko gdy jest pełnym adresem.
            $url = self::origin((string) ($konfiguracja['url'] ?? ''));
            if ($url !== null) {
                $originy[] = "{$url[0]}://{$url[1]}";
            }
        }

        foreach ((array) config('kuking.media.publiczne_adresy', []) as $adres) {
            $origin = self::origin((string) $adres);
            if ($origin !== null) {
                $originy[] = "{$origin[0]}://{$origin[1]}";
            }
        }

        return array_values(array_unique($originy));
    }

    /**
     * @return array{0: string, 1: string}|null [schemat, host[:port]]
     */
    private static function origin(string $adres): ?array
    {
        $adres = trim($adres);

        if ($adres === '' || preg_match('/[\s;,\'"*]/', $adres) === 1) {
            return null;
        }

        if (! str_contains($adres, '://')) {
            $adres = 'https://'.$adres;
        }

        try {
            $uri = new Uri($adres);
        } catch (Throwable) {
            return null;
        }

        $schemat = strtolower($uri->getScheme());
        $host = strtolower($uri->getHost());

        if (! in_array($schemat, ['http', 'https'], true) || $host === '') {
            return null;
        }

        // `http:` wolno tylko dla pętli zwrotnej (MinIO lokalnie) — nigdy
        // dla hosta publicznego.
        if ($schemat === 'http' && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
            return null;
        }

        $port = $uri->getPort();

        return [$schemat, $port === null ? $host : "{$host}:{$port}"];
    }
}

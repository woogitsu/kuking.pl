<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Support\ZaufaneHosty;

/**
 * Adres wklejony w publicznym zgłoszeniu prawnym (issue #1636).
 *
 * TO JEST DOWÓD, NIE CEL
 * Pole „Adres strony z tą treścią" przyjmujemy w KAŻDEJ postaci — z obcej
 * domeny, względny, opisany z pamięci. DSA art. 16 nie pozwala odrzucić
 * zgłoszenia tylko dlatego, że nie umiemy rozpoznać adresu, a wartość zostaje
 * w `reports.target_url` dosłownie, jako ślad tego, co człowiek napisał.
 *
 * Do 25 września 2026 kontroler brał z tego pola SAMĄ ŚCIEŻKĘ i nie patrzył
 * na schemat ani host. `https://obcy.example/przepis/<slug-naszego-przepisu>`
 * dostawał więc `target_type = recipe` i identyfikator prawdziwego przepisu
 * Kuking — moderator mógł podjąć decyzję wobec naszej treści, choć zgłoszenie
 * wskazywało inną stronę. Stąd dwie osobne rzeczy w tej klasie:
 *
 *  1. `sciezkaWewnetrzna()` — ścieżka TYLKO dla bezwzględnego adresu http(s)
 *     na hoście, który jest naszym adresem publicznym: `kuking.pl`,
 *     `www.kuking.pl` albo host z `APP_URL` (staging, preview). Nie host
 *     bieżącego żądania — ten kontroluje wysyłający. Bez `user:hasło@`,
 *     bez nieoczekiwanego portu, bez odwrotnych ukośników i białych znaków,
 *     które różne parsery czytają różnie.
 *
 *  2. `doListu()` — wartość do maila jako tekst, którego renderer Markdown
 *     nie zamieni w odnośnik ani obrazek. List wychodzi podpisany przez
 *     Kuking; `[pilne](https://obcy.example)` w polu formularza nie może
 *     zrobić z niego nośnika cudzego linku.
 *
 * Czego tu świadomie NIE MA: `healthcheck.railway.app` i pętli zwrotnej
 * z `ZaufaneHosty`. Tamta lista mówi, na jakie hosty serwis ODPOWIADA; ta —
 * jaki adres wolno uznać za wskazanie naszej treści. Nikt nie ogląda
 * przepisów pod hostem healthchecku.
 */
final class AdresZgloszenia
{
    public const WEWNETRZNY = 'wewnetrzny';

    public const ZEWNETRZNY = 'zewnetrzny';

    public const NIEPOPRAWNY = 'niepoprawny';

    /**
     * Czy to adres Kuking, adres z zewnątrz (albo w nietypowej postaci), czy
     * w ogóle nie adres. Panel moderatora pokazuje to wprost.
     */
    public static function rodzaj(string $adres): string
    {
        $czesci = self::czesciAdresuHttp($adres);

        if ($czesci === null) {
            return self::NIEPOPRAWNY;
        }

        return self::jestNaszymAdresem($czesci) ? self::WEWNETRZNY : self::ZEWNETRZNY;
    }

    /** Ścieżka adresu, ale wyłącznie gdy adres na pewno wskazuje Kuking. */
    public static function sciezkaWewnetrzna(string $adres): ?string
    {
        $czesci = self::czesciAdresuHttp($adres);

        if ($czesci === null || ! self::jestNaszymAdresem($czesci)) {
            return null;
        }

        return is_string($czesci['path'] ?? null) ? $czesci['path'] : null;
    }

    /**
     * Wartość gotowa do `MailMessage::line()`.
     *
     * Białe i sterujące znaki schodzą do pojedynczej spacji — pusta linia
     * zamknęłaby akapit, a reszta zaczęłaby się jako nowy blok Markdown.
     * Znaki, z których CommonMark składa odnośnik, obrazek, wyróżnienie
     * i tabelę, dostają ukośnik wsteczny, więc wychodzą jako zwykły tekst.
     * `<`, `>` i `&` zostają: Blade zamienia je na encje, zanim Markdown je
     * zobaczy, a ukośnik przed encją pokazałby „&lt;" zamiast „<".
     */
    public static function doListu(string $adres): string
    {
        $tekst = trim((string) preg_replace('/[\s\p{C}]+/u', ' ', $adres));

        return (string) preg_replace('/([\\\\`*_\[\]!|~])/', '\\\\$1', $tekst);
    }

    /**
     * @return array<string, mixed>|null części adresu, gdy to bezwzględny
     *                                   adres http(s) z hostem
     */
    private static function czesciAdresuHttp(string $adres): ?array
    {
        $adres = trim($adres);

        // Parsery (PHP, przeglądarka, klient poczty) różnią się właśnie przy
        // odwrotnym ukośniku i znakach niewidocznych. Adres, którego nie
        // czytają jednakowo, nie jest adresem, na którym można się oprzeć.
        if ($adres === '' || preg_match('/[\s\p{C}\\\\]/u', $adres) === 1) {
            return null;
        }

        $czesci = parse_url($adres);

        if (! is_array($czesci)) {
            return null;
        }

        $schemat = strtolower((string) ($czesci['scheme'] ?? ''));

        if (! in_array($schemat, ['http', 'https'], true) || ($czesci['host'] ?? '') === '') {
            return null;
        }

        $czesci['scheme'] = $schemat;
        $czesci['host'] = strtolower((string) $czesci['host']);

        return $czesci;
    }

    /** @param  array<string, mixed>  $czesci */
    private static function jestNaszymAdresem(array $czesci): bool
    {
        // `https://kuking.pl@obcy.example/` to host `obcy.example`, a samo
        // `user@` przed naszym hostem nie ma w adresie publicznym czego szukać.
        if (isset($czesci['user']) || isset($czesci['pass'])) {
            return false;
        }

        $host = $czesci['host'];

        if (! in_array($host, self::hosty(), true)) {
            return false;
        }

        $port = $czesci['port'] ?? null;

        if ($port === null || $port === ($czesci['scheme'] === 'https' ? 443 : 80)) {
            return true;
        }

        // Niestandardowy port przechodzi tylko wtedy, gdy TO środowisko tak
        // stoi (`APP_URL=http://localhost:8000`), i tylko na jego hoście.
        return $host === self::hostZAppUrl() && $port === parse_url((string) config('app.url'), PHP_URL_PORT);
    }

    /** @return list<string> */
    private static function hosty(): array
    {
        return array_values(array_filter([
            ZaufaneHosty::KANONICZNY,
            ZaufaneHosty::WWW,
            self::hostZAppUrl(),
        ]));
    }

    private static function hostZAppUrl(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }
}

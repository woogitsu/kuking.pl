<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

use App\Domain\Import\ImportOdrzucony;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;

/**
 * Pobranie JEDNEJ strony z przepisem — z ochroną SSRF, `robots.txt`
 * i limitami (D-300, projekt `docs/research/V2_IMPORT_OCR_ODZYWCZE.md` §2.4).
 *
 * Co jest pilnowane, w kolejności:
 *
 *  1. `StraznikAdresow` przed KAŻDYM żądaniem — także przed każdym
 *     przekierowaniem i przed pobraniem `robots.txt`. Przekierowań nie
 *     wykonuje biblioteka HTTP (`allow_redirects: false`), tylko ta pętla,
 *     bo inaczej `302 → http://10.0.0.1/` ominęłoby kontrolę;
 *  2. połączenie idzie na adres IP sprawdzony przez strażnika
 *     (`CURLOPT_RESOLVE`), a nie na to, co DNS odpowie za drugim razem;
 *  3. zmienne środowiskowe proxy są wyłączone (`proxy: ''`) — pośrednik
 *     rozwiązywałby nazwę po swojemu i przypięcie adresu nic by nie dało;
 *  4. `robots.txt` szanowany: 4xx = wolno, 5xx i brak odpowiedzi = nie wolno
 *     (RFC 9309 §2.3.1); uczciwy `User-Agent`, bez udawania przeglądarki;
 *  5. tylko `text/html` / `application/xhtml+xml`, najwyżej `url_max_bajtow`
 *     czytanych strumieniowo, limit czasu na żądanie i na całość.
 *
 * Zdjęć ze strony NIE pobieramy (decyzja właściciela z 26.09.2026) — ta klasa
 * pobiera wyłącznie dokument HTML i `robots.txt`, nic więcej.
 */
final class PobieraczStron
{
    public const MAKS_PRZEKIEROWAN = 3;

    /** RFC 9309 §2.5: co najmniej 500 KiB pliku robots trzeba przeczytać. */
    public const MAKS_BAJTOW_ROBOTS = 512_000;

    /**
     * Parametry śledzące, które NIE są częścią adresu przepisu — zdejmujemy
     * je z adresu zapisywanego jako źródło.
     *
     * @var list<string>
     */
    private const PARAMETRY_SLEDZACE = ['fbclid', 'gclid', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', 'igshid', 'yclid', '_ga'];

    /** @var array<string, RobotsTxt> */
    private array $robots = [];

    private float $koniec = 0.0;

    public function __construct(private readonly StraznikAdresow $straznik) {}

    public static function userAgent(): string
    {
        return 'KukingImport/1.0 (+'.rtrim((string) config('app.url'), '/').'/o-kuking)';
    }

    /**
     * @throws ImportOdrzucony
     */
    public function pobierz(string $url): PobranaStrona
    {
        $this->robots = [];
        $this->koniec = microtime(true) + $this->limitCalosci();

        $adres = $this->straznik->sprawdz($url);

        for ($skok = 0; ; $skok++) {
            if (! $this->robotsDla($adres)->wolno($this->sciezka($adres->url))) {
                throw new ImportOdrzucony(ImportOdrzucony::ROBOTS_ZABRANIA);
            }

            $odpowiedz = $this->zadanie($adres, 'text/html,application/xhtml+xml;q=0.9');

            if ($odpowiedz->redirect()) {
                if ($skok >= self::MAKS_PRZEKIEROWAN) {
                    throw new ImportOdrzucony(ImportOdrzucony::ZA_DUZO_PRZEKIEROWAN);
                }

                $adres = $this->straznik->sprawdz($this->nastepny($adres->url, $odpowiedz));

                continue;
            }

            if (! $odpowiedz->successful()) {
                throw new ImportOdrzucony(ImportOdrzucony::STRONA_NIEDOSTEPNA);
            }

            $typ = strtolower(trim(explode(';', (string) $odpowiedz->header('Content-Type'))[0]));

            if (! in_array($typ, ['text/html', 'application/xhtml+xml'], true)) {
                throw new ImportOdrzucony(ImportOdrzucony::NIE_STRONA);
            }

            $html = $this->przeczytaj($odpowiedz, $this->maksBajtow(), ImportOdrzucony::ZA_DUZA_STRONA);

            return new PobranaStrona(self::bezSledzenia($adres->url), $this->doUtf8($html, (string) $odpowiedz->header('Content-Type')));
        }
    }

    /**
     * Adres bez parametrów śledzących (`utm_*`, `fbclid`…) i bez fragmentu —
     * to on trafia do `recipes.source_url`.
     */
    public static function bezSledzenia(string $url): string
    {
        $url = explode('#', $url, 2)[0];
        $czesci = parse_url($url);

        if (! is_array($czesci) || ! isset($czesci['query'])) {
            return $url;
        }

        $zostaja = [];

        foreach (explode('&', $czesci['query']) as $para) {
            $nazwa = strtolower(urldecode(explode('=', $para, 2)[0]));

            if ($nazwa === '' || str_starts_with($nazwa, 'utm_') || in_array($nazwa, self::PARAMETRY_SLEDZACE, true)) {
                continue;
            }

            $zostaja[] = $para;
        }

        $bezZapytania = explode('?', $url, 2)[0];

        return $zostaja === [] ? $bezZapytania : $bezZapytania.'?'.implode('&', $zostaja);
    }

    private function robotsDla(SprawdzonyAdres $adres): RobotsTxt
    {
        $korzen = $adres->korzen();

        return $this->robots[$korzen] ??= $this->pobierzRobots($korzen);
    }

    private function pobierzRobots(string $korzen): RobotsTxt
    {
        try {
            $adres = $this->straznik->sprawdz($korzen.'/robots.txt');

            for ($skok = 0; ; $skok++) {
                $odpowiedz = $this->zadanie($adres, 'text/plain');

                if ($odpowiedz->redirect()) {
                    if ($skok >= self::MAKS_PRZEKIEROWAN) {
                        return RobotsTxt::nicNieWolno();
                    }

                    $adres = $this->straznik->sprawdz($this->nastepny($adres->url, $odpowiedz));

                    continue;
                }

                if ($odpowiedz->successful()) {
                    return RobotsTxt::zTresci($this->przeczytaj($odpowiedz, self::MAKS_BAJTOW_ROBOTS, null));
                }

                // RFC 9309 §2.3.1.3: 4xx = pliku nie ma, wolno wszystko.
                // §2.3.1.4: 5xx = serwer niedostępny, zakładamy pełny zakaz.
                return $odpowiedz->clientError() ? RobotsTxt::wszystkoWolno() : RobotsTxt::nicNieWolno();
            }
        } catch (ImportOdrzucony) {
            // Przekierowanie robots.txt na adres prywatny, limit czasu,
            // brak połączenia — nie wiemy, czy wolno, więc nie wolno.
            return RobotsTxt::nicNieWolno();
        }
    }

    /**
     * @throws ImportOdrzucony
     */
    private function zadanie(SprawdzonyAdres $adres, string $accept): Response
    {
        $zostalo = $this->koniec - microtime(true);

        if ($zostalo <= 0) {
            throw new ImportOdrzucony(ImportOdrzucony::ZA_DLUGO);
        }

        try {
            return Http::withOptions([
                'allow_redirects' => false,
                'stream' => true,
                'proxy' => '',
                'connect_timeout' => min(5.0, $zostalo),
                'timeout' => min((float) $this->limitZadania(), $zostalo),
                'curl' => [
                    CURLOPT_RESOLVE => [$adres->przypiecie()],
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                ],
            ])->withHeaders([
                'User-Agent' => self::userAgent(),
                'Accept' => $accept,
            ])->get($adres->url);
        } catch (ConnectionException $e) {
            throw new ImportOdrzucony(
                str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28')
                    ? ImportOdrzucony::ZA_DLUGO
                    : ImportOdrzucony::STRONA_NIEDOSTEPNA,
            );
        }
    }

    /**
     * @throws ImportOdrzucony
     */
    private function nastepny(string $obecny, Response $odpowiedz): string
    {
        $cel = trim((string) $odpowiedz->header('Location'));

        if ($cel === '') {
            throw new ImportOdrzucony(ImportOdrzucony::STRONA_NIEDOSTEPNA);
        }

        return self::rozwiaz($obecny, $cel);
    }

    /**
     * Adres z nagłówka `Location` względem adresu bieżącego (RFC 3986 §5,
     * w zakresie, jaki spotyka się w przekierowaniach).
     */
    public static function rozwiaz(string $baza, string $cel): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $cel) === 1) {
            return $cel;
        }

        $czesci = parse_url($baza);
        $schemat = $czesci['scheme'] ?? 'https';
        $host = $czesci['host'] ?? '';
        $port = isset($czesci['port']) ? ':'.$czesci['port'] : '';

        if (str_starts_with($cel, '//')) {
            return $schemat.':'.$cel;
        }

        $korzen = $schemat.'://'.(str_contains($host, ':') ? '['.$host.']' : $host).$port;

        if (str_starts_with($cel, '/')) {
            return $korzen.$cel;
        }

        $sciezka = $czesci['path'] ?? '/';
        $katalog = substr($sciezka, 0, (int) strrpos($sciezka, '/') + 1);

        return $korzen.($katalog === '' ? '/' : $katalog).$cel;
    }

    /**
     * @throws ImportOdrzucony
     */
    private function przeczytaj(Response $odpowiedz, int $maks, ?string $kodPrzekroczenia): string
    {
        $dlugosc = $odpowiedz->header('Content-Length');

        if ($kodPrzekroczenia !== null && is_numeric($dlugosc) && (int) $dlugosc > $maks) {
            throw new ImportOdrzucony($kodPrzekroczenia);
        }

        /** @var StreamInterface $strumien */
        $strumien = $odpowiedz->toPsrResponse()->getBody();
        $tresc = '';

        while (! $strumien->eof()) {
            if (microtime(true) > $this->koniec) {
                throw new ImportOdrzucony(ImportOdrzucony::ZA_DLUGO);
            }

            $kawalek = $strumien->read(8192);

            if ($kawalek === '') {
                break;
            }

            $tresc .= $kawalek;

            if (strlen($tresc) > $maks) {
                if ($kodPrzekroczenia !== null) {
                    throw new ImportOdrzucony($kodPrzekroczenia);
                }

                return substr($tresc, 0, $maks);
            }
        }

        return $tresc;
    }

    private function doUtf8(string $html, string $typ): string
    {
        if (mb_check_encoding($html, 'UTF-8')) {
            return $html;
        }

        $kodowanie = null;

        if (preg_match('/charset=["\']?([a-z0-9_-]+)/i', $typ, $m) === 1) {
            $kodowanie = $m[1];
        } elseif (preg_match('/<meta[^>]+charset=["\']?([a-z0-9_-]+)/i', $html, $m) === 1) {
            $kodowanie = $m[1];
        }

        $kodowanie = $kodowanie !== null && in_array(strtoupper($kodowanie), array_map('strtoupper', mb_list_encodings()), true)
            ? $kodowanie
            : 'ISO-8859-2';

        return (string) mb_convert_encoding($html, 'UTF-8', $kodowanie);
    }

    private function sciezka(string $url): string
    {
        $czesci = parse_url($url);
        $sciezka = $czesci['path'] ?? '/';

        return isset($czesci['query']) ? $sciezka.'?'.$czesci['query'] : $sciezka;
    }

    private function maksBajtow(): int
    {
        return (int) config('kuking.import.url.max_bajtow', 2_000_000);
    }

    private function limitZadania(): int
    {
        return (int) config('kuking.import.url.limit_czasu', 10);
    }

    private function limitCalosci(): int
    {
        return (int) config('kuking.import.url.limit_czasu_calosci', 25);
    }
}

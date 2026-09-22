<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Tags\InlineTagTokens;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\HtmlString;

/** Linki są dodatkiem do zwykłego tekstu, nigdy interpretacją HTML autora. */
final class LinkiWTekscie
{
    public static function bezpiecznyAdres(string $adres): ?string
    {
        if (strlen($adres) > 2048 || preg_match('/[\x00-\x20\x7f\\\\<>"\']/u', $adres)
            || preg_match('/[\x{202a}-\x{202e}\x{2066}-\x{2069}]/u', $adres)
            || preg_match('/%0[ad]/i', $adres) || str_contains($adres, '…')) {
            return null;
        }

        if (str_starts_with(strtolower($adres), 'www.')) {
            $adres = 'https://'.$adres;
        }

        // Polskie/Unicode znaki w ŚCIEŻCE, ZAPYTANIU i FRAGMENCIE do postaci
        // procentowej (issue #763) — `FILTER_VALIDATE_URL` niżej akceptuje
        // tylko RFC 3986, więc `https://…/żurek` bez tego kroku był zwykłym
        // tekstem, mimo że `https://…/%C5%BCurek` (ten sam cel) już linkiem
        // był. DOMENA (host) celowo zostaje nietknięta — Unicode w hoście to
        // osobna decyzja o IDNA, nie ta poprawka.
        $adres = self::procentowoZakodujSciezkeIZapytanie($adres);

        $czesci = parse_url($adres);
        if ($czesci === false || ! in_array(strtolower($czesci['scheme'] ?? ''), ['http', 'https'], true)
            || isset($czesci['user']) || isset($czesci['pass']) || empty($czesci['host'])
            || filter_var($adres, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        return $adres;
    }

    /**
     * Koduje WYŁĄCZNIE bajty spoza ASCII (`\x80`–`\xFF`) w ścieżce, zapytaniu
     * i fragmencie adresu — nigdy w schemacie ani w hoście.
     *
     * Dlaczego nie `rawurlencode()` całego adresu: to zakodowałoby też `/`,
     * `?`, `&`, `=`, `#`, zmieniając strukturę adresu, nie tylko jego treść.
     * Dlaczego bez podwójnego kodowania: istniejące `%XX` składa się
     * wyłącznie z bajtów ASCII (`%`, cyfry, litery A–F), więc wzorzec
     * `[\x80-\xFF]+` nigdy go nie dotyka — `%C5%BC` na wejściu wychodzi
     * niezmienione.
     *
     * Jeśli `parse_url()` nie potrafi rozpoznać hosta (np. adres bez
     * schematu), zwraca oryginalny tekst bez zmian — dalsze sprawdzenia
     * w `bezpiecznyAdres()` i tak go odrzucą.
     */
    private static function procentowoZakodujSciezkeIZapytanie(string $adres): string
    {
        $czesci = parse_url($adres);
        if ($czesci === false || empty($czesci['scheme']) || empty($czesci['host'])) {
            return $adres;
        }

        $koduj = static fn (string $wartosc): string => preg_replace_callback(
            '/[\x80-\xFF]+/',
            static fn (array $m): string => rawurlencode($m[0]),
            $wartosc,
        ) ?? $wartosc;

        $wynik = $czesci['scheme'].'://';
        if (isset($czesci['user'])) {
            $wynik .= $czesci['user'].(isset($czesci['pass']) ? ':'.$czesci['pass'] : '').'@';
        }
        $wynik .= $czesci['host'];
        if (isset($czesci['port'])) {
            $wynik .= ':'.$czesci['port'];
        }
        $wynik .= $koduj($czesci['path'] ?? '');
        if (isset($czesci['query'])) {
            $wynik .= '?'.$koduj($czesci['query']);
        }
        if (isset($czesci['fragment'])) {
            $wynik .= '#'.$koduj($czesci['fragment']);
        }

        return $wynik;
    }

    public static function wewnetrzny(string $adres): bool
    {
        $cel = parse_url($adres);
        $aplikacja = parse_url((string) config('app.url'));
        if ($cel === false || $aplikacja === false) {
            return false;
        }
        $port = static fn (array $url): int => $url['port'] ?? (strtolower($url['scheme'] ?? '') === 'https' ? 443 : 80);

        return strtolower($cel['host'] ?? '') === strtolower($aplikacja['host'] ?? '')
            && strtolower($cel['scheme'] ?? '') === strtolower($aplikacja['scheme'] ?? '')
            && $port($cel) === $port($aplikacja);
    }

    /**
     * ADRESY I — jeśli wywołujący je poda — TAGI PRZYPIĘTE DO TEJ TREŚCI.
     *
     * `$tagi` to mapa "znormalizowany token => adres strony tagu". Widok
     * buduje ją z RELACJI wpisu, nigdy z samego tekstu (issue #737): adres
     * policzony z tego, co ktoś napisał, prowadziłby przy literówce albo
     * przy `#2024` na stronę, której nie ma. Pusta mapa = zachowanie sprzed
     * tej zmiany, czyli same adresy — dlatego komentarze nie musiały się
     * zmienić.
     *
     * Ucieczka HTML zostaje bez zmian: escapujemy WSZYSTKO poza tym, co sami
     * rozpoznaliśmy, a etykieta odnośnika też przechodzi przez `e()`.
     *
     * @param  array<string, string>  $tagi
     */
    public static function render(string $tekst, array $tagi = []): HtmlString
    {
        $zamiany = [];

        preg_match_all('~(?<![\pL\pN_@])(?:https?://|www\.)[^\s<>"\'`„“”«»‘’]+~iu', $tekst, $trafienia, PREG_OFFSET_CAPTURE);
        foreach ($trafienia[0] as [$kandydat, $start]) {
            $zamiany[] = ['start' => $start, 'dlugosc' => strlen($kandydat), 'html' => self::adresJakoHtml($kandydat)];
        }

        if ($tagi !== []) {
            foreach ((new InlineTagTokens)->wTekscie($tekst) as $trafienie) {
                $href = $tagi[$trafienie['token']] ?? null;
                if ($href === null) {
                    continue;
                }
                $zamiany[] = [
                    'start' => $trafienie['offset'],
                    'dlugosc' => $trafienie['dlugosc'],
                    'html' => '<a class="tag-w-tresci" href="'.e($href).'">'.e(substr($tekst, $trafienie['offset'], $trafienie['dlugosc'])).'</a>',
                ];
            }
            usort($zamiany, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        }

        $html = '';
        $offset = 0;
        foreach ($zamiany as $zamiana) {
            // Adres pochłonął już ten fragment (np. `https://x.test/#sernik`)
            // — nie tniemy go w środku drugim odnośnikiem.
            if ($zamiana['start'] < $offset) {
                continue;
            }
            $html .= e(substr($tekst, $offset, $zamiana['start'] - $offset)).$zamiana['html'];
            $offset = $zamiana['start'] + $zamiana['dlugosc'];
        }

        return new HtmlString($html.e(substr($tekst, $offset)));
    }

    private static function adresJakoHtml(string $kandydat): string
    {
        $etykieta = rtrim($kandydat, '.,;:!?');
        foreach ([')' => '(', ']' => '[', '}' => '{'] as $koniec => $poczatek) {
            while (str_ends_with($etykieta, $koniec) && substr_count($etykieta, $koniec) > substr_count($etykieta, $poczatek)) {
                $etykieta = substr($etykieta, 0, -1);
            }
        }
        $adres = self::bezpiecznyAdres($etykieta);
        if ($adres === null) {
            return e($kandydat);
        }

        // Porównujemy pełny origin z konfiguracją, nie prefiks ani nagłówek Host.
        $href = self::wewnetrzny($adres) ? $adres : route('links.external', ['cel' => Crypt::encryptString($adres)]);

        return '<a class="link-w-tresci" href="'.e($href).'" rel="nofollow ugc">'.e($etykieta).'</a>'.e(substr($kandydat, strlen($etykieta)));
    }
}

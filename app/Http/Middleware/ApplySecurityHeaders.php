<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AnalitykaCloudflare;
use App\Support\Turnstile;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nagłówki bezpieczeństwa.
 *
 * CSP JEST JUŻ WYMUSZANA, NIE TYLKO MIERZONA (issue #12)
 *
 * Poprzedni etap tego zadania wymuszał tylko cztery dyrektywy, które nie
 * mogły niczego zepsuć (`base-uri`, `object-src`, `frame-ancestors`,
 * `form-action`), a całą resztę — w tym jedyną dyrektywę, która realnie
 * zatrzymuje XSS-a, czyli `script-src` — trzymał w nagłówku Report-Only
 * z `unsafe-inline` i `unsafe-eval`. Powód był prawdziwy: `default-src`
 * odziedziczyłby się na `script-src` i położył kreator przepisu, bo Livewire
 * z Alpine wstawiają skrypt inline i używają `new Function`.
 *
 * Ten etap usuwa tamten powód, zamiast go obchodzić:
 *
 *   1. NONCE. Każde żądanie dostaje jednorazowy, losowy podpis. Wstawiamy go
 *      przed wyrenderowaniem widoku (`Vite::useCspNonce()`), więc `@vite`,
 *      `@livewireStyles`, `@livewireScripts` i nasz `<x-json-ld>` dopisują
 *      go sobie same. Skrypt bez tego podpisu przeglądarka odrzuca — a
 *      wstrzyknięty przez atakującego znać go nie może, bo zmienia się
 *      z każdą odsłoną strony.
 *   2. LIVEWIRE W TRYBIE CSP-SAFE. `livewire.csp_safe` przełącza bundel na
 *      wersję Alpine bez `new Function`, więc `unsafe-eval` przestało być
 *      potrzebne (patrz komentarz przy tej opcji w config/livewire.php).
 *
 *   3. ZERO ATRYBUTÓW `style=` W WIDOKACH (issue #107). Nonce ich nie ratuje:
 *      działa na ELEMENTY `<style>` i `<script>`, a nie na atrybut `style` —
 *      jedyne, co go dopuszcza, to `unsafe-inline`. Wszystkie 355 atrybutów
 *      zostało przeniesione do nazwanych klas w `resources/css/app.css`,
 *      a strona awarii (`errors/_prosty.blade.php`), która musi działać bez
 *      zewnętrznego arkusza, dostała jeden blok `<style nonce>`.
 *
 * POLITYKA JEST DOMKNIĘTA. `style-src` nie ma już `unsafe-inline`.
 *
 * Nagłówek REPORT-ONLY zostaje mimo to i nadal mierzy NASTĘPNY krok — dziś
 * jest to ta sama polityka co wymuszana. Zostawiamy go, bo zgłoszenia
 * z produkcji są jedynym sposobem, żeby zobaczyć naruszenie, którego nie
 * widać w testach: pakiet dokładający własny `style=`, wklejony fragment
 * cudzego HTML-a, wtyczka przeglądarki. Bez tego kanału dowiedzielibyśmy się
 * o tym z pustej strony u użytkownika.
 *
 * DWA NIE-WYJĄTKI, ŻEBY NIKT ICH NIE „POPRAWIŁ":
 * `resources/views/mail/**` ma style inline i tak ma zostać — klienty pocztowe
 * nie czytają arkuszy, a CSP nie dotyczy poczty w ogóle.
 * `resources/views/exports/**` to pliki HTML z paczki RODO, otwierane Z DYSKU:
 * nie ma tam żadnych nagłówków, więc nie ma czego naruszać.
 *
 * Co realnie daje część wymuszająca — to nie są dyrektywy dekoracyjne:
 *
 *   script-src           Bez `unsafe-inline` wstrzyknięty `<script>` nie
 *                        wykona się w ogóle. To jest cała stawka tego zadania.
 *   default-src 'self'   Domyka wszystko, czego nie wymieniamy osobno
 *                        (`media-src`, `manifest-src`, `frame-src`...), żeby
 *                        nowy rodzaj zasobu nie wjechał tu bez ograniczeń.
 *   base-uri 'self'      Wstrzyknięty `<base href="...">` przekierowuje
 *                        KAŻDY względny adres na stronie, razem z akcją
 *                        formularza logowania. Klasyczne wzmocnienie XSS-a
 *                        z „skrypt się wykonał" do „hasło poszło gdzie indziej".
 *   form-action 'self'   Zamyka drugą drogę tego samego: przestawienie
 *                        `action` formularza na obcy adres.
 *   object-src 'none'    `<object>` i `<embed>` to najstarsza droga do
 *                        wykonania kodu w kontekście strony. Nie używamy ich.
 *   frame-ancestors      Clickjacking. Nowsze przeglądarki czytają tę
 *                        dyrektywę zamiast `X-Frame-Options`, który zostaje
 *                        dla starszych.
 */
class ApplySecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // PODPIS MUSI POWSTAĆ PRZED `$next()`, NIE PO.
        //
        // Widok renderuje się w środku `$next($request)`. Gdyby nonce
        // powstawał niżej, w nagłówku byłby inny ciąg niż w HTML-u — i cała
        // strona zostałaby bez skryptów, bez żadnego błędu w logu. To jest
        // dokładnie ten rodzaj awarii, którego nie widać na oczy, więc
        // pilnuje go osobny test (PolitykaBezpieczenstwaTest).
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // /search i strony zalogowanego nigdy nie idą do indeksu.
        if ($this->shouldNotIndex($request)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        if (app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $zgloszenia = route('csp.report');

        // Serwer deweloperski Vite (`npm run dev`) serwuje skrypty i style
        // z innego portu, a HMR chodzi po WebSockecie. Na produkcji tej
        // gałęzi nie ma — `public/hot` powstaje wyłącznie lokalnie.
        $vite = $this->zrodlaSerweraVite();

        // Cloudflare Turnstile (D-050). Widget dociąga własne skrypty, rysuje
        // się w RAMCE i ODZYWA SIĘ Z POWROTEM do Cloudflare, więc potrzebuje
        // TRZECH dyrektyw naraz: `script-src`, `frame-src` i `connect-src`.
        // Sam podpis (`nonce`) nie wystarczy — nonce nie przechodzi na
        // skrypty, które api.js wstawia sam.
        //
        // TRZECIA DYREKTYWA KOSZTOWAŁA MARTWE LOGOWANIE HASŁEM (issue #697).
        // Do 19 września 2026 host szedł tylko do `script-src` i `frame-src`.
        // Widget rysował się poprawnie, po czym przechodził w „Weryfikacja
        // negatywna", bo jego wywołanie do
        // `challenges.cloudflare.com/cdn-cgi/challenge-platform/…` ginęło na
        // `connect-src`, a w konsoli stawał `TurnstileError 600010`. Formularz
        // hasła nie dawał się wysłać NIKOMU — polityka idzie z każdą
        // odpowiedzią. Serwis nie był zamknięty tylko dlatego, że Google,
        // Facebook i list z odnośnikiem nie przechodzą przez Turnstile.
        //
        // To jest DOKŁADNIE ta sama pułapka, którą opisuje akapit o analityce
        // kilkadziesiąt linii niżej — ten sam plik, drugi host, przeoczona.
        // Dlatego pilnuje jej teraz test
        // `tests/Feature/PolitykaCspDopuszczaPowrotTurnstileTest.php`, a nie
        // komentarz: komentarz stał tu już wtedy i nie zatrzymał niczego.
        //
        // DOKŁADAMY TO TYLKO WTEDY, GDY TURNSTILE MA KLUCZE. Bez nich widget
        // się nie renderuje, więc rozluźnianie polityki nie miałoby czego
        // obsłużyć — a każdy obcy host w `script-src` to poszerzenie
        // powierzchni ataku dla XSS-a (issue #12). Polityka opisuje to,
        // co strona naprawdę ładuje.
        //
        // ŚWIADOMIE NIE dokładamy `style-src 'unsafe-inline'`, o którym
        // wspominają niektóre poradniki: własne style widgetu żyją WEWNĄTRZ
        // jego ramki, czyli pod polityką Cloudflare, nie naszą. Dodanie
        // `unsafe-inline` skasowałoby cały efekt issue #107.
        $turnstile = Turnstile::skonfigurowany() ? ['https://challenges.cloudflare.com'] : [];

        // Analityka Cloudflare Web Analytics (D-092). Ten sam warunek co
        // przy Turnstile i z tego samego powodu: polityka opisuje to, co
        // strona NAPRAWDĘ ładuje. Bez `CLOUDFLARE_ANALYTICS_TOKEN` żaden
        // znacznik nie wychodzi z widoku, więc nie ma czego dopuszczać — a
        // każdy obcy host w `script-src` to poszerzenie powierzchni ataku dla
        // XSS-a (issue #12).
        //
        // DWIE DYREKTYWY I DWA RÓŻNE HOSTY — TU JEST CAŁA PUŁAPKA.
        // Zmierzone w `beacon.min.js` (D-092): plik pobiera się z
        // `static.cloudflareinsights.com`, a zdarzenia lecą przez
        // `navigator.sendBeacon` na `cloudflareinsights.com/cdn-cgi/rum`,
        // czyli na host BEZ `static.`. To są dwie różne wartości, nie jedna
        // powtórzona — przy Plausible, które tu stało wcześniej, oba adresy
        // były tym samym hostem i jedna linijka obsługiwała obie dyrektywy.
        //
        // `script-src` pozwala POBRAĆ plik i na tym koniec. Wysyłka podlega
        // `connect-src` — która w tej polityce jest wypisana osobno, więc NIE
        // dziedziczy nic z `default-src 'self'`. Gdyby zabrakło drugiej
        // linijki albo gdyby wpisano do niej ten sam host co do pierwszej,
        // skrypt pobrałby się poprawnie i każde zdarzenie ginęłoby na
        // barierze CSP: strona bez usterki, panel Cloudflare pusty,
        // w dzienniku serwera ani śladu. Pilnują tego DWA osobne testy —
        // jeden na dyrektywę, żeby żaden nie zdał za drugiego.
        //
        // Podpis (`nonce`) tego nie załatwia: nonce dotyczy znacznika,
        // a nie połączenia wychodzącego — i tak samo jak przy Turnstile
        // hosty trzeba wymienić z nazwy.
        $analitykaWlaczona = AnalitykaCloudflare::wlaczona();
        $analitykaSkrypt = $analitykaWlaczona ? [AnalitykaCloudflare::hostSkryptu()] : [];
        $analitykaZdarzenia = $analitykaWlaczona ? [AnalitykaCloudflare::hostZdarzen()] : [];

        $wspolne = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            // `https:` zamiast konkretnego hosta, bo zdjęcia mogą iść z dysku
            // lokalnego albo z R2 pod własną domeną (docs/DECISIONS.md).
            // `blob:` jest potrzebny do podglądu wybranego pliku przed
            // wysłaniem (resources/js/app.js).
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "worker-src 'self'",
            'connect-src '.implode(' ', ["'self'", ...$vite['connect'], ...$turnstile, ...$analitykaZdarzenia]),
            'script-src '.implode(' ', ["'self'", "'nonce-{$nonce}'", ...$vite['host'], ...$turnstile, ...$analitykaSkrypt]),
        ];

        // `frame-src` pojawia się w polityce WYŁĄCZNIE z Turnstile. Bez niego
        // ramki dziedziczą `default-src 'self'` — czyli domyślnie nie wolno
        // wstawiać żadnej obcej, i tak ma zostać.
        if ($turnstile !== []) {
            $wspolne[] = 'frame-src '.implode(' ', ["'self'", ...$turnstile]);
        }

        // `style-src` bez `unsafe-inline` — od issue #107 w widokach nie ma
        // ani jednego atrybutu `style=`. Podpis zostaje, bo obejmuje `<style>`
        // wstawiany przez Livewire i blok w `errors/_prosty.blade.php`.
        $styleSrc = 'style-src '.implode(' ', ["'self'", "'nonce-{$nonce}'", ...$vite['host']]);

        $response->headers->set('Content-Security-Policy', implode('; ', [
            ...$wspolne,
            $styleSrc,
            'report-uri '.$zgloszenia,
        ]));

        // MIERZONA: dziś to ta sama polityka co wymuszana, i tak ma być.
        // Nagłówek zostaje jako KANAŁ ZGŁOSZEŃ, nie jako zapowiedź kolejnego
        // kroku: pokazuje naruszenia, których nie widać w testach — pakiet
        // dokładający własny `style=`, wklejony fragment cudzego HTML-a,
        // wtyczkę przeglądarki. Bez niego dowiedzielibyśmy się o takim
        // naruszeniu dopiero z pustej strony u użytkownika.
        $response->headers->set('Content-Security-Policy-Report-Only', implode('; ', [
            ...$wspolne,
            $styleSrc,
            'report-uri '.$zgloszenia,
        ]));

        return $response;
    }

    /**
     * Adresy serwera deweloperskiego Vite — puste, kiedy go nie ma.
     *
     * @return array{host: list<string>, connect: list<string>}
     */
    private function zrodlaSerweraVite(): array
    {
        if (! Vite::isRunningHot()) {
            return ['host' => [], 'connect' => []];
        }

        $adres = trim((string) @file_get_contents(Vite::hotFile()));

        if ($adres === '' || ! str_starts_with($adres, 'http')) {
            return ['host' => [], 'connect' => []];
        }

        $adres = rtrim($adres, '/');

        return [
            'host' => [$adres],
            // HMR chodzi po WebSockecie pod tym samym hostem i portem.
            'connect' => [$adres, str_replace(['https://', 'http://'], ['wss://', 'ws://'], $adres)],
        ];
    }

    private function shouldNotIndex(Request $request): bool
    {
        foreach (['szukaj', 'home', 'dodaj', 'powiadomienia', 'ustawienia', 'zeszyt', 'admin', 'zglos', 'witaj'] as $prefix) {
            if ($request->is($prefix, $prefix.'/*')) {
                return true;
            }
        }

        return false;
    }
}

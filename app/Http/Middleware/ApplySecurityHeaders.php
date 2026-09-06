<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
 * CO ZOSTAŁO NIEDOMKNIĘTE I DLACZEGO TO WIDAĆ, A NIE JEST ZAMIECIONE
 * `style-src` nadal ma `unsafe-inline`. W widokach jest ponad trzysta
 * atrybutów `style="..."` i każdy z nich jest naruszeniem tej dyrektywy.
 * Podpis nonce ich NIE ratuje: nonce działa na elementy `<style>` i
 * `<script>`, a nie na atrybut `style` — jedyne, co go dopuszcza, to
 * `unsafe-inline`. Przepisanie tych trzystu miejsc na klasy CSS to osobna,
 * duża praca (issue założone przy tej zmianie), więc na razie:
 *
 *   WYMUSZANA     — pełna polityka z `default-src 'self'` i `script-src`
 *                   bez `unsafe-inline` i bez `unsafe-eval`. Działa dziś.
 *   REPORT-ONLY   — ta sama polityka, ale ze `style-src` bez `unsafe-inline`
 *                   (samo `'self'` i podpis). Nie blokuje niczego, tylko
 *                   liczy, ile tych atrybutów naprawdę zostało na produkcji.
 *
 * Nagłówek mierzony ma więc dalej sens: mierzy NASTĘPNY krok, a nie ten,
 * który właśnie zrobiliśmy.
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
            'connect-src '.implode(' ', ["'self'", ...$vite['connect']]),
            'script-src '.implode(' ', ["'self'", "'nonce-{$nonce}'", ...$vite['host']]),
        ];

        // WYMUSZANA: to, co działa dziś. `style-src` z `unsafe-inline`,
        // bo w widokach są jeszcze atrybuty `style="..."`.
        $response->headers->set('Content-Security-Policy', implode('; ', [
            ...$wspolne,
            'style-src '.implode(' ', ["'self'", "'unsafe-inline'", ...$vite['host']]),
            'report-uri '.$zgloszenia,
        ]));

        // MIERZONA: następny krok, czyli `style-src` bez `unsafe-inline`.
        // Nagłówek nic nie blokuje — tylko liczy, ile tych atrybutów
        // naprawdę zostało na stronach, których nie ruszaliśmy.
        //
        // Podpis jest tu POTRZEBNY, choć w polityce wymuszanej go nie ma.
        // Bez niego przeglądarka zgłaszałaby także `<style>` wstawiany przez
        // Livewire — czyli coś, co jest już w porządku — i pomiar tonąłby
        // w szumie. Z podpisem zostają dokładnie atrybuty `style="..."`,
        // bo tych żaden nonce nie obejmuje.
        $response->headers->set('Content-Security-Policy-Report-Only', implode('; ', [
            ...$wspolne,
            'style-src '.implode(' ', ["'self'", "'nonce-{$nonce}'", ...$vite['host']]),
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

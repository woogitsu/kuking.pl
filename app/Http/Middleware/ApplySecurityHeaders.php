<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nagłówki bezpieczeństwa.
 *
 * CSP IDZIE W DWÓCH NAGŁÓWKACH NARAZ I TO JEST CELOWE (issue #12)
 *
 * Wcześniej stał tu wyłącznie `Content-Security-Policy-Report-Only`
 * z komentarzem, że tryb wymuszający wymaga „listy naruszeń zebranych
 * z produkcji". Dwie rzeczy były z tym nie tak:
 *
 *   1. Polityka nie podawała adresu, pod który przeglądarka miałaby zgłosić
 *      naruszenie. Tryb Report-Only bez `report-uri` NIE ZBIERA NICZEGO —
 *      więc zadanie „włącz na podstawie danych" nie mogło ruszyć, bo dane
 *      nigdy by nie przybyły.
 *   2. Polityka i tak dopuszczała `unsafe-inline` i `unsafe-eval` dla
 *      skryptów, więc jej włączenie w tej postaci nie dałoby prawie nic.
 *      Czekanie z CAŁOŚCIĄ na rozwiązanie NAJTRUDNIEJSZEJ części znaczyło,
 *      że nie działa też część łatwa i wartościowa.
 *
 * Dlatego są dwa nagłówki:
 *
 *   WYMUSZAJĄCY  — tylko te dyrektywy, które NIE MOGĄ niczego zepsuć,
 *                  bo dzisiejszy kod ich nie narusza. Zero `default-src`:
 *                  gdyby był, `script-src` i `style-src` dziedziczyłyby
 *                  po nim i wyłączyłyby Livewire z Alpine. Bez niego
 *                  ograniczamy dokładnie to, co wymieniamy.
 *   REPORT-ONLY  — pełna polityka docelowa, ta sama co wcześniej, ale
 *                  z adresem zgłoszeń. Mierzy, ile pracy zostało.
 *
 * Co realnie daje część wymuszająca — to nie są dyrektywy dekoracyjne:
 *
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

        // Część WYMUSZAJĄCA. Świadomie bez `default-src` — patrz opis klasy.
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "form-action 'self'",
            'report-uri '.$zgloszenia,
        ]));

        // Polityka DOCELOWA, na razie tylko mierzona. Różnica między nią
        // a nagłówkiem wyżej to lista rzeczy do zrobienia: pozbycie się
        // `unsafe-inline` i `unsafe-eval` (Livewire ma tryb CSP-safe,
        // Alpine wymaga wersji bez `new Function`).
        $response->headers->set('Content-Security-Policy-Report-Only', implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'none'",
            "img-src 'self' data: blob: https:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'",
            "connect-src 'self'",
            "font-src 'self' data:",
            "form-action 'self'",
            'report-uri '.$zgloszenia,
        ]));

        return $response;
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

<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nagłówki bezpieczeństwa.
 *
 * CSP jest tu w trybie Report-Only, świadomie. Livewire i Alpine wymagają
 * ostrożnego doboru dyrektyw i włączenie CSP w trybie wymuszającym bez
 * przetestowania na realnych stronach zepsułoby interfejs. Przejście
 * na tryb wymuszający jest osobnym zadaniem w backlogu — z listą
 * naruszeń zebranych z produkcji.
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

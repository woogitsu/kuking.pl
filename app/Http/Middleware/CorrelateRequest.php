<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Łączy jedno żądanie z logiem i alarmem, bez odczytu danych człowieka. */
final class CorrelateRequest
{
    public const ATTRIBUTE = 'kuking_request_id';

    public function handle(Request $request, Closure $next): Response
    {
        // Nagłówek klienta nie jest źródłem identyfikatora, nawet gdy wygląda jak UUID.
        $id = (string) Str::uuid();
        $request->attributes->set(self::ATTRIBUTE, $id);
        Log::shareContext(['request_id' => $id]);

        try {
            // Pipeline Laravela raportuje i renderuje wyjątki wewnątrz $next.
            // Kontekst musi żyć aż do końca renderowania strony błędu.
            $response = $next($request);
            $response->headers->set('X-Request-ID', $id);

            return $response;
        } finally {
            // withoutContext czyści otwarte kanały, ale NIE przyszłe kanały.
            // Odtwarzamy pozostałe współdzielone pola, nie kasując cudzej telemetrii.
            $context = (array) Log::sharedContext();
            unset($context['request_id']);
            Log::withoutContext(['request_id']);
            Log::flushSharedContext();
            Log::shareContext($context);
        }
    }
}

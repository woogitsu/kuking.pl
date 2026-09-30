<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Baza\LimitCzasuZapytanHttp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `statement_timeout` na czas żądania HTTP (issue #2290). Stoi w stosie
 * GLOBALNYM, więc obejmuje też odczyt sesji i zapytania stron błędu — a nie
 * obejmuje konsoli (worker, harmonogram, migracje), bo tam kernel HTTP nie
 * rusza. Uzasadnienie i sposób: `LimitCzasuZapytanHttp`.
 */
final class UstawLimitCzasuZapytan
{
    public function __construct(private readonly LimitCzasuZapytanHttp $limit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->limit->wlacz();

        try {
            // Pipeline Laravela renderuje wyjątki wewnątrz $next, więc strona
            // błędu powstaje jeszcze z limitem, a `RESET` idzie na końcu.
            return $next($request);
        } finally {
            $this->limit->wylacz();
        }
    }
}

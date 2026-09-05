<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dostęp do panelu moderacji.
 *
 * Zwracamy 404, nie 403 — panel moderacji nie musi nikomu potwierdzać,
 * że istnieje.
 */
class EnsureUserIsModerator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isModerator() === true, 404);

        return $next($request);
    }
}

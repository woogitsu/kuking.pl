<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventSharedSessionCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $statelessMedia = $request->attributes->get(StartSessionExceptAnonymousMedia::STATELESS_MEDIA) === true;

        // Warstwa zewnętrzna widzi także Set-Cookie dopisane przez sesję,
        // wyjątki routera i błędy przed grupą web. Nie usuwa ciasteczek.
        if (($request->hasSession() && ! $statelessMedia)
            || $request->headers->has('Cookie') || $request->cookies->count() > 0
            || $request->headers->has('Authorization') || $request->user() !== null
            || $response->headers->getCookies() !== [] || $response->getStatusCode() >= 400) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->remove('CDN-Cache-Control');
            $response->headers->remove('Cloudflare-CDN-Cache-Control');
            $response->headers->remove('Surrogate-Control');
        }

        return $response;
    }
}

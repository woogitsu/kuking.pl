<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PublicznyHtmlGoscia;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventSharedSessionCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $statelessMedia = $request->attributes->get(StartSessionExceptAnonymousMedia::STATELESS_MEDIA) === true;
        $publicznyHtml = PublicznyHtmlGoscia::bezSesji($request);

        // Warstwa zewnętrzna widzi także Set-Cookie dopisane przez sesję,
        // wyjątki routera i błędy przed grupą web. Nie usuwa ciasteczek.
        if (($request->hasSession() && ! $statelessMedia && ! $publicznyHtml)
            || $request->headers->has('Cookie') || $request->cookies->count() > 0
            || $request->headers->has('Authorization') || $request->user() !== null
            || $response->headers->getCookies() !== [] || $response->getStatusCode() >= 400) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->remove('CDN-Cache-Control');
            $response->headers->remove('Cloudflare-CDN-Cache-Control');
            $response->headers->remove('Surrogate-Control');

            return $response;
        }

        // #610: tu dochodzi wyłącznie żądanie bez sesji, ciasteczek,
        // Authorization i zalogowanego, a odpowiedź nie ustawia ciasteczka
        // i nie jest błędem. Wspólny cache dostaje TYLKO strona z listy
        // `PublicznyHtmlGoscia`, ze statusem 200 i bez tokenu CSRF w treści.
        // Przekierowanie starego adresu przepisu zostaje przy tym, co ustawił
        // framework — nie ma powodu dawać mu publicznego TTL.
        if ($publicznyHtml) {
            if ($response->getStatusCode() === 200 && ! PublicznyHtmlGoscia::zawieraStanKlienta($response)) {
                // `max-age=0`: przeglądarka pyta za każdym razem, więc po
                // zalogowaniu nigdy nie pokaże z własnego dysku strony gościa.
                // `s-maxage` dotyczy wyłącznie cache współdzielonego (brzegu).
                $response->headers->set('Cache-Control', 'public, max-age=0, s-maxage='.PublicznyHtmlGoscia::sekundy());
                $response->setVary(['Cookie', 'Authorization'], false);
            } else {
                $response->headers->set('Cache-Control', 'private, no-store');
            }
            $response->headers->remove('CDN-Cache-Control');
            $response->headers->remove('Cloudflare-CDN-Cache-Control');
            $response->headers->remove('Surrogate-Control');
        }

        return $response;
    }
}

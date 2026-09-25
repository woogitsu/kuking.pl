<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PublicznyHtmlGoscia;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

class PreventRequestForgeryExceptMediaCookie extends PreventRequestForgery
{
    protected function addCookieToResponse($request, $response)
    {
        // Walidacja CSRF pozostaje w rodzicu bez zmian. Odczyt zdjęcia
        // bez sesji nie wystawia tokenu, którego żaden formularz nie użyje.
        if ($request->attributes->get(StartSessionExceptAnonymousMedia::STATELESS_MEDIA) === true
            || PublicznyHtmlGoscia::bezSesji($request)) {
            return $response;
        }

        return parent::addCookieToResponse($request, $response);
    }

    /**
     * #610: strona z brzegu nie niesie tokenu, więc przełącznik motywu gościa
     * bez ciasteczek przechodzi wyłącznie przez sprawdzenie pochodzenia.
     * Rodzic uznaje `Sec-Fetch-Site: same-origin`; starsze przeglądarki
     * (Safari przed 16.4) tego nagłówka nie wysyłają, ale wysyłają `Origin`.
     * Wyjątek jest wąski: tylko `theme.update`, tylko bez `Sec-Fetch-Site`
     * i tylko przy `Origin` równym dokładnie naszemu hostowi (ten jest już
     * przefiltrowany przez `TrustHosts`). Obcy formularz ma obcy `Origin`.
     */
    protected function hasValidOrigin($request)
    {
        if (parent::hasValidOrigin($request)) {
            return true;
        }

        return $request->routeIs('theme.update')
            && ! $request->headers->has('Sec-Fetch-Site')
            && $request->headers->get('Origin') === $request->getSchemeAndHttpHost();
    }
}

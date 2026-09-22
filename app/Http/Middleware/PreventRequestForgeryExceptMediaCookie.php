<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

class PreventRequestForgeryExceptMediaCookie extends PreventRequestForgery
{
    protected function addCookieToResponse($request, $response)
    {
        // Walidacja CSRF pozostaje w rodzicu bez zmian. Odczyt zdjęcia
        // bez sesji nie wystawia tokenu, którego żaden formularz nie użyje.
        if ($request->attributes->get(StartSessionExceptAnonymousMedia::STATELESS_MEDIA) === true) {
            return $response;
        }

        return parent::addCookieToResponse($request, $response);
    }
}

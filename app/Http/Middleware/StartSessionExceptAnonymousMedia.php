<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PublicznyHtmlGoscia;
use Closure;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Session\Store;

class StartSessionExceptAnonymousMedia extends StartSession
{
    public const STATELESS_MEDIA = 'kuking_stateless_media';

    public function handle($request, Closure $next)
    {
        // Tylko odczyt zdjęcia bez jakiegokolwiek stanu klienta. Sesja dla
        // formularzy, HTML, zalogowanego i nawet błędnego cookie zostaje.
        if ($request->routeIs('media.show')
            && in_array($request->method(), ['GET', 'HEAD'], true)
            && ! $request->headers->has('Cookie') && $request->cookies->count() === 0
            && ! $request->headers->has('Authorization') && $request->user() === null) {
            $request->attributes->set(self::STATELESS_MEDIA, true);
            // ShareErrorsFromSession potrzebuje magazynu, także dla strony
            // odmowy. Ten magazyn nie jest odczytywany ani zapisywany w bazie.
            $request->setLaravelSession(new Store('anonymous-media', new ArraySessionHandler(1)));

            return $next($request);
        }

        // #610: landing, przepis i profil dla gościa bez żadnego ciasteczka —
        // tylko gdy właściciel włączył cache HTML. Ten sam pusty magazyn co
        // wyżej: widok nie zobaczy komunikatu flash ani `old()`, bo gość bez
        // ciasteczka i tak nie ma sesji, z której by je wziął.
        if (PublicznyHtmlGoscia::kwalifikuje($request)) {
            $request->attributes->set(PublicznyHtmlGoscia::ATRYBUT, true);
            $request->setLaravelSession(new Store('anonymous-html', new ArraySessionHandler(1)));

            return $next($request);
        }

        return parent::handle($request, $next);
    }
}

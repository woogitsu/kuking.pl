<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Polski, konkretny komunikat dla `Illuminate\Http\Exceptions\PostTooLargeException`
 * (audyt A31) — zamiast domyślnej strony frameworka.
 *
 * DLACZEGO TU W OGÓLE POTRZEBNA JEST OSOBNA KLASA, SKORO LARAVEL JUŻ
 * WYKRYWA TEN PRZYPADEK
 *
 * Laravel ma wbudowany globalny middleware `ValidatePostSize`, który
 * PRZED wszystkim innym (przed sesją, przed CSRF, przed routingiem)
 * porównuje nagłówek `Content-Length` z `post_max_size` i rzuca
 * `PostTooLargeException`, gdy żądanie jest za duże. To jest DOBRA
 * WIADOMOŚĆ: nie trzeba pisać drugiej wersji tego samego porównania —
 * druga, niezależna implementacja tej samej logiki byłaby dokładnie tym
 * rozjazdem, któremu ma zapobiegać ten audyt.
 *
 * Zła wiadomość: domyślne renderowanie tego wyjątku to standardowa strona
 * błędu frameworka — bez polskiego, konkretnego komunikatu. Człowiek,
 * który wybrał za duże zdjęcie, i tak nie dowiaduje się, co zrobić.
 * Ta klasa naprawia WYŁĄCZNIE to renderowanie (przez
 * `AppServiceProvider::boot()`, patrz tam), nie wykrywanie.
 *
 * DLACZEGO NIE DA SIĘ ODDAĆ WPISANEGO TEKSTU
 *
 * `ValidatePostSize` rzuca wyjątek, ZANIM PHP w ogóle zdąży wypełnić
 * `$_POST`/`$_FILES` (przy przekroczeniu `post_max_size` PHP i tak by je
 * wyzerował). Nie ma więc czego odzyskiwać — w przeciwieństwie do zwykłej
 * sesji wygasłej (issue #79/#81), tu tekst nie istnieje po stronie
 * serwera w ŻADNEJ formie. Komunikat mówi wprost, co zrobić, zamiast
 * udawać odzyskanie danych, których nie ma.
 *
 * DLACZEGO SUROWY HTML, NIE `view()`
 *
 * Wyjątek jest rzucany PRZED middleware sesji — `view()` z layoutem
 * strony (`x-layout`) mogłoby po cichu wymagać sesji (token CSRF
 * w `<head>`, `Auth::user()` w nagłówku) i rzucić kolejny wyjątek
 * dokładnie w miejscu, które ma naprawiać ten pierwszy.
 */
final class KomunikatZaDuzaWysylka
{
    public static function odpowiedz(Request $request): Response
    {
        $limitMb = LimityZdjec::maksMegabajtowDoKomunikatu();
        $powrot = htmlspecialchars(self::urlPowrotu($request), ENT_QUOTES, 'UTF-8');

        // PODPIS DO BLOKU `<style>` — ten sam mechanizm co w
        // `errors/_prosty.blade.php`. Od 20 września 2026 ta odpowiedź NIE
        // wychodzi już bez polityki bezpieczeństwa: `ApplySecurityHeaders`
        // stoi drugi w stosie globalnym, czyli na zewnątrz `ValidatePostSize`
        // (patrz bootstrap/app.php). `style-src` nie ma `unsafe-inline`, więc
        // blok bez podpisu przeglądarka odrzuci i strona zostanie szara.
        //
        // Gdyby podpisu z jakiegoś powodu nie było, atrybut się nie pojawia,
        // a strona renderuje się bez stylów — nagłówek, akapit i odnośnik są
        // zwykłym HTML-em. To jest gorszy wygląd, nie utrata treści.
        $nonce = Vite::cspNonce();
        $atrybutNonce = is_string($nonce) && $nonce !== ''
            ? ' nonce="'.htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8').'"'
            : '';

        $html = <<<HTML
            <!doctype html>
            <html lang="pl">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <title>Zdjęcie jest za duże — Kuking</title>
            <style{$atrybutNonce}>
              /* `rem`, NIE `px`. Rozmiar podany w `px` nie zmienia się, gdy
                 człowiek powiększy czcionkę w ustawieniach przeglądarki — a to
                 jest druga, całkiem niezależna od naszej, droga do większego
                 tekstu (WCAG 1.4.4). Zmierzone: przy podwojonej czcionce
                 przeglądarki ta strona zostawała przy 18 px, podczas gdy
                 `errors/_prosty.blade.php` (500 i 503) rósł poprawnie, bo
                 od początku używa `rem`. 1.125rem = 18 px przy domyślnych 16. */
              body { font: 1.125rem/1.5 system-ui, -apple-system, sans-serif; max-width: 40rem; margin: 3rem auto; padding: 0 1.25rem; color: #1a1a1a; background: #fff; }
              h1 { font-size: 1.75rem; line-height: 1.3; }
              a.btn { display: inline-block; margin-top: 1.5rem; padding: 0.85rem 1.5rem; min-height: 48px; box-sizing: border-box; line-height: 1.3; background: #1a1a1a; color: #fff; text-decoration: none; border-radius: 0.5rem; font-weight: 700; font-size: 1.125rem; }
              a.btn:focus-visible { outline: 3px solid #155EEF; outline-offset: 4px; }
            </style>
            </head>
            <body>
            <h1>Zdjęcie jest za duże, żeby je wysłać</h1>
            <p>Maksymalny rozmiar to {$limitMb} MB. Wybierz mniejsze zdjęcie albo wyślij je osobno — nie razem z innymi.</p>
            <a class="btn" href="{$powrot}">Wróć i spróbuj ponownie</a>
            </body>
            </html>
            HTML;

        return response($html, 413);
    }

    /**
     * Tylko adres z TEGO SAMEGO originu — `Referer` jest kontrolowany przez
     * klienta i nie jest tu w żaden sposób uwierzytelniony, więc nie może
     * stać się otwartym przekierowaniem na obcy adres.
     */
    private static function urlPowrotu(Request $request): string
    {
        $referer = $request->headers->get('referer');

        if (is_string($referer) && str_starts_with($referer, $request->getSchemeAndHttpHost())) {
            return $referer;
        }

        return url('/');
    }
}

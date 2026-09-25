<?php

declare(strict_types=1);

namespace App\Domain\Contact;

use Illuminate\Routing\Route;
use Illuminate\Support\Str;

final class PageContext
{
    /**
     * Nazwane trasy, których adres niesie sekret albo jednorazowy
     * identyfikator (#836). Z takiego adresu zapisujemy wyłącznie stałą
     * część ścieżki przed pierwszym parametrem, np. `/nowe-haslo`.
     *
     * Lista trzyma NAZWY tras, nie wpisane ręcznie adresy: zmiana ścieżki
     * w `routes/web.php` przenosi się tu sama. Nowa trasa z `{token}`
     * albo `signed` bez wpisu tutaj wywraca
     * `KontekstKontaktuBezSekretowTest::test_kazda_trasa_z_sekretem_jest_maskowana`.
     */
    public const SENSITIVE_ROUTES = [
        'password.reset',
        'login.link.confirm',
        'zaproszenie.pokaz',
        'verification.verify',
        'settings.email.confirm',
        'settings.data.download',
        'podsumowanie.wypisz',
        'podsumowanie.wracam',
        'appeals.reporter',
    ];

    /** Kontekst diagnostyczny nie jest miejscem na dane uwierzytelniające. */
    public static function clean(?string $address): ?string
    {
        if ($address === null || trim($address) === '') {
            return null;
        }

        $parts = parse_url(trim($address));
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['host']) && strcasecmp($parts['host'], (string) parse_url((string) config('app.url'), PHP_URL_HOST)) !== 0) {
            return null;
        }
        if (isset($parts['scheme']) && ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        $path = rawurldecode($parts['path'] ?? '');
        // Niejednoznacznych i wielokrotnie kodowanych ścieżek nie zapisujemy.
        if (! str_starts_with($path, '/') || preg_match('~[%\\\\\x00-\x20]|//|(?:^|/)\.{1,2}(?:/|$)~', $path)) {
            return null;
        }

        // Porównanie po prefiksie, nie dopasowanie trasy: podstawione
        // `/nowe-haslo/{token}/cokolwiek` nie pasuje do żadnej trasy,
        // a sekret niesie tak samo.
        foreach (self::maskedScreens() as $screen) {
            if ($path === $screen || str_starts_with($path, $screen.'/')) {
                return $screen;
            }
        }

        return Str::limit($path, 297, '…');
    }

    /**
     * Stałe części ścieżek tras z `SENSITIVE_ROUTES`, najdłuższe pierwsze.
     * Brak trasy o danej nazwie to błąd konfiguracji — lepiej wyrzucić
     * wyjątek, niż po cichu przestać maskować.
     *
     * @return list<string>
     */
    public static function maskedScreens(): array
    {
        $routes = app('router')->getRoutes();
        $routes->refreshNameLookups();

        $screens = array_map(static function (string $name) use ($routes): string {
            $route = $routes->getByName($name);
            if (! $route instanceof Route) {
                throw new \LogicException("PageContext: brak trasy {$name} — popraw SENSITIVE_ROUTES.");
            }

            return self::staticPrefix($route->uri());
        }, self::SENSITIVE_ROUTES);

        $screens = array_values(array_unique($screens));
        usort($screens, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $screens;
    }

    /** `nowe-haslo/{token}` → `/nowe-haslo`; `@{username}` → `/`. */
    public static function staticPrefix(string $uri): string
    {
        $static = strstr($uri, '{', true);
        if ($static === false) {
            return '/'.trim($uri, '/');
        }
        // Ucinamy do ostatniego pełnego segmentu — `@{username}` nie może
        // zostawić połówki segmentu z parametrem.
        $static = substr($static, 0, (int) strrpos('/'.$static, '/'));

        return '/'.trim($static, '/');
    }
}

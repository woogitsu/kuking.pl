<?php

declare(strict_types=1);

namespace App\Domain\Contact;

use Illuminate\Support\Str;

final class PageContext
{
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

        foreach (['/nowe-haslo', '/logowanie/link', '/zaproszenie', '/potwierdz-email'] as $screen) {
            if ($path === $screen || str_starts_with($path, $screen.'/')) {
                return $screen;
            }
        }

        return Str::limit($path, 297, '…');
    }
}

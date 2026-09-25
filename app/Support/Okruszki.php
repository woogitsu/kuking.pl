<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Post;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Ścieżka nadrzędna strony — widoczne okruszki i `BreadcrumbList` z JEDNEJ
 * listy (#1033).
 *
 * DLACZEGO JEDNA LISTA
 * Google traktuje dane strukturalne niezgodne z widoczną treścią jako
 * naruszenie wytycznych (docs/seo/SEO_TECHNICAL.md §2). Dwie osobno pisane
 * ścieżki — jedna w `<ol class="okruchy">`, druga w JSON-LD — rozjadą się
 * przy pierwszej zmianie etykiety. Widok buduje więc listę raz i podaje ją
 * komponentowi `x-okruszki` oraz `Okruszki::jsonLd()`.
 *
 * Element to `['nazwa' => string, 'url' => ?string]`. Ostatni element to
 * bieżąca strona: w JSON-LD nie ma `item` (zgodnie z przykładem Google),
 * a na ekranie nie powtarza się, bo stoi tuż pod okruszkami jako treść.
 *
 * Przepis ma na razie własną ścieżkę („Świeżo z Kuking") — jej etykieta
 * i cel czekają na rozstrzygnięcie w #667, więc nie utrwalamy jej tutaj.
 */
final class Okruszki
{
    /** Dla wpisu bez tekstu: tyle słów z treści idzie do nazwy strony. */
    private const SLOW_Z_TRESCI = 8;

    /**
     * @param  list<array{nazwa: string, url: ?string}>  $elementy
     * @return array<string, mixed>
     */
    public static function jsonLd(array $elementy): array
    {
        $ostatni = array_key_last($elementy);
        $pozycje = [];

        foreach (array_values($elementy) as $i => $element) {
            $pozycje[] = array_filter([
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $element['nazwa'],
                'item' => $i === $ostatni ? null : $element['url'],
            ], static fn ($wartosc) => $wartosc !== null);
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $pozycje,
        ];
    }

    /** @return list<array{nazwa: string, url: ?string}> */
    public static function dlaProfilu(Profile $profil): array
    {
        return [
            self::kuking(),
            ['nazwa' => '@'.$profil->username, 'url' => route('profile.show', $profil->username)],
        ];
    }

    /** @return list<array{nazwa: string, url: ?string}> */
    public static function dlaPytania(Post $pytanie): array
    {
        return [
            self::kuking(),
            ['nazwa' => 'Poradźcie', 'url' => route('questions.index')],
            ['nazwa' => (string) $pytanie->title, 'url' => $pytanie->url()],
        ];
    }

    /**
     * Kuking → @autor → opis wpisu.
     *
     * Profil autora wchodzi TYLKO wtedy, gdy gość może go otworzyć
     * (`UserPolicy::viewProfile` bez zalogowanej osoby). Wpis konta
     * wymazanego zostaje widoczny, ale jego profilu gość nie otworzy — okruszek
     * prowadziłby wyszukiwarkę i człowieka w ścianę.
     *
     * @return list<array{nazwa: string, url: ?string}>
     */
    public static function dlaWpisu(Post $wpis): array
    {
        $elementy = [self::kuking()];
        $autor = $wpis->author;

        if ($autor instanceof User && $autor->profile !== null && Gate::forUser(null)->allows('viewProfile', $autor)) {
            $elementy[] = ['nazwa' => '@'.$autor->profile->username, 'url' => route('profile.show', $autor->profile->username)];
        }

        $elementy[] = ['nazwa' => self::nazwaWpisu($wpis), 'url' => $wpis->url()];

        return $elementy;
    }

    /**
     * Uczciwa nazwa wpisu: pierwsze słowa tego, co autor napisał, a gdy
     * wpis jest samym zdjęciem — data publikacji. Bez UUID i bez zgadywania
     * nazwy dania ze zdjęcia.
     */
    public static function nazwaWpisu(Post $wpis): string
    {
        $tresc = trim((string) preg_replace('/\s+/u', ' ', (string) $wpis->body));

        if ($tresc !== '') {
            return Str::words($tresc, self::SLOW_Z_TRESCI, '…');
        }

        return $wpis->published_at !== null
            ? 'Wpis z '.Czas::data($wpis->published_at, 'j F Y')
            : 'Wpis';
    }

    /** @return array{nazwa: string, url: string} */
    private static function kuking(): array
    {
        return ['nazwa' => 'Kuking', 'url' => route('landing')];
    }
}

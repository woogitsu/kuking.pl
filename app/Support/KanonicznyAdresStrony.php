<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;

/**
 * Adres do `<link rel="canonical">` i `og:url` (issue #963).
 *
 * CO BYŁO ŹLE
 * Layout wstawiał `url()->current()`, czyli adres bez ŻADNYCH parametrów.
 * Dla `?utm_source=...` to dobrze, ale druga strona profilu, zakładka
 * „Przepisy" albo filtr „Bez odpowiedzi" w Poradźcie pokazują INNĄ treść —
 * a canonical mówił wyszukiwarce, że to duplikat strony pierwszej.
 * `docs/seo/SEO_TECHNICAL.md` §1.2 wprost tego zabrania: strony 2+ to
 * prawdziwe przepisy autora, które inaczej „znikają" z Google.
 *
 * DLACZEGO BIAŁA LISTA PER TRASA, A NIE CAŁY QUERY STRING
 * Zachowanie wszystkiego zrobiłoby z każdego śmieciowego parametru osobną
 * „kanoniczną" stronę. Globalna lista nazw też nie wystarcza: `?page=2` na
 * stronie przepisu nic nie zmienia, a `?zakladka=` na przepisie to właśnie
 * przypadek, który `og:url` ma sklejać do jednej puli udostępnień
 * (`KartaDoUdostepnianiaTest`). Dlatego trasa mówi, które parametry u NIEJ
 * wybierają treść, a wartość musi być taka, jaką kontroler naprawdę
 * uwzględnia — `?zakladka=cokolwiek` daje zakładkę domyślną, więc
 * i canonical wskazuje zakładkę domyślną.
 *
 * Kolejność parametrów bierze się z listy, nie z adresu, więc
 * `?tag=x&filtr=y` i `?filtr=y&tag=x` mają ten sam canonical.
 *
 * HOST Z `APP_URL`, NIE Z ŻĄDANIA (issue #1369)
 * `ZaufaneHosty` wpuszcza też `www.kuking.pl`; przekierowanie na apex robi
 * Cloudflare, ale gdy reguły zabraknie, strona `www` ogłaszała SIEBIE jako
 * kanoniczną, a sitemapa (budowana z `APP_URL`) — apex. Korzeń bierzemy więc
 * z konfiguracji, tak jak sitemapa. Staging i preview mają własne `APP_URL`,
 * więc wskazują same siebie. Bez `APP_URL` ze schematem — korzeń z żądania.
 *
 * ŚCIEŻKA MOŻE PRZYJŚĆ Z KONTROLERA (issue #1311)
 * Profil szuka nazwy bez rozróżniania wielkości liter, więc `/@basia_1971`
 * i `/@BASIA_1971` pokazują konto `Basia_1971`. Kontroler podaje wtedy
 * ścieżkę z ZAPISANĄ pisownią (`ustawSciezke()`), już po autoryzacji —
 * odmowa dostępu nie ujawnia więc nazwy. Zostajemy przy 200 zamiast 301:
 * stare linki działają bez zmian, a canonical, `og:url`, sitemapa
 * i `ProfilePage` wskazują jeden adres.
 */
final class KanonicznyAdresStrony
{
    /**
     * Trasa → parametry wybierające treść, w stałej kolejności.
     *
     * Nowa publiczna lista z paginacją lub zakładkami trafia TUTAJ; bez
     * wpisu jej dalsze strony wracają do canonical strony pierwszej.
     */
    private const PARAMETRY_TRAS = [
        'profile.show' => ['zakladka', 'rok', 'page'],
        'tags.index' => ['page'],
        'tags.show' => ['page'],
        'questions.index' => ['filtr', 'tag', 'cursor'],
        'discover' => ['cursor'],
    ];

    private const ATRYBUT_SCIEZKI = 'kanoniczna_sciezka';

    /** Kontroler zna kanoniczną ścieżkę lepiej niż adres wpisany przez człowieka. */
    public static function ustawSciezke(Request $request, string $sciezka): void
    {
        $request->attributes->set(self::ATRYBUT_SCIEZKI, '/'.ltrim($sciezka, '/'));
    }

    public static function dla(Request $request): string
    {
        $adres = self::korzen($request).self::sciezka($request);
        $parametry = self::PARAMETRY_TRAS[$request->route()?->getName() ?? ''] ?? [];

        $zachowane = [];
        foreach ($parametry as $nazwa) {
            $wartosc = $request->query($nazwa);
            if (is_string($wartosc) && self::wybieraTresc($nazwa, $wartosc)) {
                $zachowane[$nazwa] = $wartosc;
            }
        }

        return $zachowane === [] ? $adres : $adres.'?'.http_build_query($zachowane, '', '&', PHP_QUERY_RFC3986);
    }

    private static function korzen(Request $request): string
    {
        $korzen = rtrim((string) config('app.url'), '/');
        $schemat = parse_url($korzen, PHP_URL_SCHEME);

        return is_string($schemat) && $schemat !== '' ? $korzen : $request->root();
    }

    private static function sciezka(Request $request): string
    {
        $ustawiona = $request->attributes->get(self::ATRYBUT_SCIEZKI);
        if (is_string($ustawiona)) {
            return $ustawiona === '/' ? '' : $ustawiona;
        }

        // `url()` bez korzenia — ścieżka zakodowana tak, jak przyszła.
        return substr($request->url(), strlen($request->root()));
    }

    /** Czy wartość zmienia treść względem widoku domyślnego (tak jak czyta ją kontroler). */
    private static function wybieraTresc(string $nazwa, string $wartosc): bool
    {
        return match ($nazwa) {
            // `?page=1` to strona pierwsza, a nie osobny adres.
            'page' => ctype_digit($wartosc) && (int) $wartosc >= 2,
            // Nieczytelny kursor paginator traktuje jak brak kursora.
            'cursor' => Cursor::fromEncoded($wartosc) !== null,
            // ProfileController: inna zakładka → zakładka „wszystko".
            'zakladka' => in_array($wartosc, ['przepisy', 'ugotowane'], true),
            // ProfileController: rok spoza zakresu → całe archiwum.
            'rok' => ctype_digit($wartosc) && (int) $wartosc >= 1990 && (int) $wartosc <= 2999,
            // QuestionController: „najnowsze" jest domyślne.
            'filtr' => $wartosc === 'bez-odpowiedzi',
            'tag' => $wartosc !== '' && mb_strlen($wartosc) <= 180,
            default => false,
        };
    }
}

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

    public static function dla(Request $request): string
    {
        $adres = $request->url();
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

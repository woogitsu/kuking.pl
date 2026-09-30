<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

/**
 * Adres strony, który trafia do `recipes.source_url` szkicu z importu —
 * a stamtąd na publiczną stronę przepisu, do danych strukturalnych
 * i do każdej migawki w historii wersji (issue #2229).
 *
 * ZASADA: LISTA ZGÓD, NIE LISTA ZAKAZÓW. Do 30.09.2026 zdejmowaliśmy tylko
 * znane parametry śledzące (`utm_*`, `fbclid`…), a wszystko inne zostawało.
 * Adres wklejony z poczty albo z panelu serwisu potrafi nieść `token=`,
 * `session=`, `email=` pod dowolną nazwą — takiej listy nie da się domknąć.
 * Dlatego zostaje: schemat, host, port i ścieżka, a z zapytania wyłącznie
 * parametry z `PARAMETRY_STRONY`, które wskazują stronę przepisu
 * (WordPress `?p=123`, `?page_id=`, `?przepis=`), i tylko z krótką,
 * prostą wartością. Fragment (`#…`) i dane logowania (`user:haslo@`)
 * znikają zawsze.
 *
 * Pobieramy stronę PEŁNYM adresem — czyszczony jest wyłącznie adres
 * zapisywany jako źródło.
 */
final class PublicznyAdresZrodla
{
    /**
     * Parametry, które identyfikują stronę przepisu. Nic poza nimi.
     *
     * @var list<string>
     */
    public const PARAMETRY_STRONY = ['p', 'page_id', 'post', 'id', 'przepis', 'recipe', 'recipe_id', 'article', 'artykul', 'lang'];

    /**
     * Wartość parametru z listy zgód: litery łacińskie, cyfry, `.`, `_`, `-`,
     * najwyżej 64 znaki. Adres e-mail (`@`), zakodowane dane czy długi
     * losowy ciąg przez to nie przejdą, nawet pod dozwoloną nazwą.
     */
    private const WARTOSC = '/^[A-Za-z0-9._-]{1,64}$/';

    public static function z(string $url): string
    {
        $url = explode('#', trim($url), 2)[0];
        $czesci = parse_url($url);

        if (! is_array($czesci) || ! isset($czesci['scheme'], $czesci['host'])) {
            return explode('?', $url, 2)[0];
        }

        $adres = strtolower($czesci['scheme']).'://'.$czesci['host']
            .(isset($czesci['port']) ? ':'.$czesci['port'] : '')
            .($czesci['path'] ?? '');

        $zostaja = [];

        foreach (explode('&', $czesci['query'] ?? '') as $para) {
            [$nazwa, $wartosc] = array_pad(explode('=', $para, 2), 2, '');
            $nazwa = strtolower(rawurldecode($nazwa));

            if (in_array($nazwa, self::PARAMETRY_STRONY, true) && preg_match(self::WARTOSC, rawurldecode($wartosc)) === 1) {
                $zostaja[] = $para;
            }
        }

        return $zostaja === [] ? $adres : $adres.'?'.implode('&', $zostaja);
    }
}

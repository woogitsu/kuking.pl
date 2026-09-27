<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Post;

/**
 * Tytuł, nagłówek i opis strony zwykłego wpisu (issue #967).
 *
 * PO CO TO ISTNIEJE
 * Do tej zmiany każdy wpis jednej osoby miał ten sam `<title>` i `og:title`:
 * „Basia — wpis". Zupa i ciasto wyglądały w wynikach wyszukiwania, w karcie
 * Messengera i w historii przeglądarki jak ten sam adres. Tytuł ma mówić,
 * CZEGO dotyczy wpis — a o tym mówi tylko to, co napisał autor.
 *
 * SKĄD BIERZEMY TYTUŁ
 * Z pierwszego wiersza treści, skróconego po CAŁYCH wyrazach. Pierwszy
 * wiersz, bo ludzie zaczynają od nazwy dania („Pierogi ruskie"), a dalej
 * idzie lista składników — ta w tytule by tylko przeszkadzała.
 *
 * WPIS BEZ TEKSTU NIE DOSTAJE WYMYŚLONEJ NAZWY
 * Z samego zdjęcia nie wiemy, co jest na talerzu. Zostaje uczciwe zdanie:
 * kto i kiedy („Basia — zdjęcie z 12 września 2026"). Pełna data z rokiem,
 * nie „12 września" jak na karcie: tytuł ma być STAŁY — ten sam dziś
 * i za rok — a nie zmieniać się pierwszego stycznia. Imię w mianowniku
 * przed myślnikiem nie wymaga odmiany i nie zakłada płci autora.
 *
 * ESCAPOWANIE ROBI BLADE
 * Zwracamy zwykły tekst; `<title>` i `<meta>` w layoucie wypisują go
 * przez `{{ }}`, więc `<b>` z treści zostaje napisem, nie znacznikiem.
 */
final class TytulStronyWpisu
{
    /** Tyle znaków mieści się w tytule wyniku wyszukiwania razem z „ — Kuking". */
    public const LIMIT_TYTULU = 60;

    /** Tyle znaków pokazuje opis pod wynikiem wyszukiwania. */
    public const LIMIT_OPISU = 155;

    private const WIELOKROPEK = '…';

    public static function tytul(Post $post): string
    {
        $pierwszyWiersz = self::pierwszyWiersz($post->body);

        if ($pierwszyWiersz !== '') {
            return self::skroc($pierwszyWiersz, self::LIMIT_TYTULU);
        }

        return self::zapasowy($post);
    }

    public static function opis(Post $post): string
    {
        $tekst = self::jednaLinia($post->body);

        if ($tekst !== '') {
            return self::skroc($tekst, self::LIMIT_OPISU);
        }

        return self::zapasowy($post).' — na Kuking.';
    }

    /**
     * Zdanie dla wpisu bez tekstu: co to jest, od kogo i kiedy.
     *
     * Przepis wskazany przez wpis jest prawdziwą nazwą (autor go wybrał),
     * więc wchodzi do tytułu — ale z autorem i datą, bo ta sama osoba może
     * ugotować ten sam przepis wiele razy.
     */
    private static function zapasowy(Post $post): string
    {
        $kto = $post->author->displayName();
        $moment = $post->published_at ?? $post->created_at;
        $kiedy = $moment !== null ? ', '.Czas::data($moment) : '';

        if ($post->recipe !== null) {
            return 'Z przepisu „'.$post->recipe->title.'” — '.$kto.$kiedy;
        }

        // Imię zostaje w mianowniku, bo nie odmieniamy imion („od Zenek” byłoby
        // niegramatyczne), a rzeczownik bez czasownika nie zakłada płci.
        $zKiedy = $moment !== null ? ' z '.Czas::data($moment) : '';

        if ($post->media->isNotEmpty()) {
            return $kto.' — zdjęcie'.$zKiedy;
        }

        return $kto.' — wpis'.$zKiedy;
    }

    private static function pierwszyWiersz(?string $tresc): string
    {
        foreach (preg_split('/\R/u', (string) $tresc) ?: [] as $wiersz) {
            $wiersz = self::jednaLinia($wiersz);

            if ($wiersz !== '') {
                return $wiersz;
            }
        }

        return '';
    }

    private static function jednaLinia(?string $tresc): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $tresc));
    }

    /**
     * Początek tekstu złożony z całych wyrazów, z wielokropkiem, gdy coś ucięto.
     */
    private static function skroc(string $tekst, int $limit): string
    {
        if (mb_strlen($tekst) <= $limit) {
            return $tekst;
        }

        // Miejsce na wielokropek liczy się do limitu.
        $poczatek = mb_substr($tekst, 0, $limit - 1);
        $spacja = mb_strrpos($poczatek, ' ');

        // Bez spacji w zasięgu (jeden długi ciąg znaków) nie ma granicy
        // wyrazu — tniemy po znakach, jak `ZapowiedzWpisu`.
        if ($spacja !== false && mb_substr($tekst, $limit - 1, 1) !== ' ') {
            $poczatek = mb_substr($poczatek, 0, $spacja);
        }

        return rtrim($poczatek, " \t,;:-—–").self::WIELOKROPEK;
    }
}

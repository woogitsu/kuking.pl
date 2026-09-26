<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

/**
 * Czysty tekst strony bez danych strukturalnych — wejście dla trybu
 * fragmentów (projekt §5.3: „czysty tekst pobranej strony, bez HTML,
 * skryptów, komentarzy czytelników, ≤ 12 000 znaków").
 *
 * Zdejmujemy wszystko, co nie jest treścią artykułu: skrypty, style,
 * nawigację, nagłówek i stopkę strony, formularze (w tym formularze
 * komentarzy), ramki i obrazy. Zostają wiersze tekstu, bez pustych.
 */
final class TekstStrony
{
    public const MAKS_ZNAKOW = 12_000;

    public const MAKS_WIERSZY = 400;

    /**
     * @return list<string>
     */
    public static function wiersze(string $html): array
    {
        $html = (string) preg_replace('#<!--.*?-->#s', ' ', $html);
        $html = (string) preg_replace(
            '#<(script|style|noscript|template|svg|nav|header|footer|aside|form|iframe|object|button|select)\b[^>]*>.*?</\1\s*>#is',
            "\n",
            $html,
        );
        // Sekcje komentarzy czytelników — cudze wypowiedzi, nie przepis.
        $html = (string) preg_replace('#<(section|div|ol|ul)\b[^>]*\b(id|class)\s*=\s*["\'][^"\']*\bcomments?\b[^"\']*["\'][^>]*>.*?</\1\s*>#is', "\n", $html);
        $html = (string) preg_replace('#<\s*(br|/?p|/?li|/?div|/?h[1-6]|/?tr|/?section|/?article|/?ul|/?ol|/?table|/?blockquote)\b[^>]*>#i', "\n", $html);

        $tekst = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $wiersze = [];
        $znaki = 0;

        foreach (preg_split('/\R/u', $tekst) ?: [] as $wiersz) {
            $wiersz = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $wiersz));

            if ($wiersz === '') {
                continue;
            }

            $znaki += mb_strlen($wiersz) + 1;

            if ($znaki > self::MAKS_ZNAKOW || count($wiersze) >= self::MAKS_WIERSZY) {
                break;
            }

            $wiersze[] = $wiersz;
        }

        return $wiersze;
    }
}

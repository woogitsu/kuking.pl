<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * Dokumentacja schematu bazy: indeks `docs/DATABASE.md` + pliki obszarów w `docs/baza/`.
 *
 * DLACZEGO PLIKI (decyzja właściciela z 2 października 2026). Jeden dokument
 * miał ponad 480 KB (~137 tys. tokenów) — za dużo, by agent albo człowiek
 * wczytał go w całości, i konflikt przy każdej parze równoległych zmian schematu.
 * Teraz `docs/DATABASE.md` to krótki indeks (tabela -> plik), a treść leży
 * w `docs/baza/*.md`, jak dziennik decyzji w `docs/decyzje/`.
 *
 * Testy, które pytają „czy dokument opisuje X”, czytają przez `tresc()`:
 * indeks plus wszystkie pliki obszarów w STAŁEJ kolejności (alfabetycznej),
 * więc wynik nie zależy od systemu plików. Test, któremu zależy na jednej
 * sekcji, szuka jej w sklejonej treści — nie zakłada, w którym pliku leży.
 */
final class DokumentacjaBazy
{
    public const INDEKS = 'docs/DATABASE.md';

    public const KATALOG = 'docs/baza';

    /**
     * Ścieżki względne plików obszarów, alfabetycznie.
     *
     * @return list<string>
     */
    public static function pliki(?string $korzen = null): array
    {
        $korzen ??= self::korzen();
        $nazwy = [];

        foreach (scandir($korzen.'/'.self::KATALOG) ?: [] as $nazwa) {
            if (str_ends_with($nazwa, '.md')) {
                $nazwy[] = $nazwa;
            }
        }

        sort($nazwy, SORT_STRING);

        return array_map(static fn (string $n): string => self::KATALOG.'/'.$n, $nazwy);
    }

    /** Indeks + wszystkie pliki obszarów, rozdzielone pustą linią. */
    public static function tresc(?string $korzen = null): string
    {
        $korzen ??= self::korzen();
        $czesci = [self::czytaj($korzen.'/'.self::INDEKS)];

        foreach (self::pliki($korzen) as $plik) {
            $czesci[] = self::czytaj($korzen.'/'.$plik);
        }

        return implode("\n\n", $czesci);
    }

    /**
     * Zwykłe względne linki i kotwice nagłówków w plikach `docs/baza/`.
     * To wąski sprawdzian dokumentacji, nie parser całego Markdown.
     *
     * @return list<string>
     */
    public static function zepsuteLinki(?string $korzen = null): array
    {
        $korzen ??= self::korzen();
        $zepsute = [];

        foreach (self::pliki($korzen) as $plik) {
            $tresc = self::bezBlokowKodu(self::czytaj($korzen.'/'.$plik));
            preg_match_all('~\]\(([^)\s]+)\)~', $tresc, $linki);

            foreach ($linki[1] as $cel) {
                if (preg_match('~^(https?:|mailto:)~', $cel) === 1) {
                    continue;
                }

                [$sciezka, $fragment] = array_pad(explode('#', $cel, 2), 2, '');
                $docelowy = $sciezka === '' ? $korzen.'/'.$plik : dirname($korzen.'/'.$plik).'/'.$sciezka;

                if (! is_file($docelowy)) {
                    $zepsute[] = $plik.' -> '.$cel;

                    continue;
                }

                if ($fragment !== '' && str_ends_with($docelowy, '.md') && ! in_array(rawurldecode($fragment), self::kotwiceNaglowkow(self::bezBlokowKodu(self::czytaj($docelowy))), true)) {
                    $zepsute[] = $plik.' -> '.$cel;
                }
            }
        }

        return $zepsute;
    }

    private static function bezBlokowKodu(string $tresc): string
    {
        return (string) preg_replace('/```.*?```/su', '', $tresc);
    }

    /** @return list<string> */
    private static function kotwiceNaglowkow(string $tresc): array
    {
        preg_match_all('/^#{1,6}\s+(.+?)\s*#*\s*$/mu', $tresc, $naglowki);

        return array_map(static function (string $naglowek): string {
            $tekst = mb_strtolower(trim($naglowek));
            $tekst = (string) preg_replace('/[^\p{L}\p{N}_ -]/u', '', $tekst);

            return str_replace(' ', '-', $tekst);
        }, $naglowki[1]);
    }

    private static function czytaj(string $sciezka): string
    {
        $tresc = is_file($sciezka) ? file_get_contents($sciezka) : false;

        if (! is_string($tresc)) {
            throw new RuntimeException('Brak pliku dokumentacji bazy: '.$sciezka);
        }

        return $tresc;
    }

    private static function korzen(): string
    {
        return function_exists('base_path') ? base_path() : dirname(__DIR__, 2);
    }
}

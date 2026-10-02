<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * CHANGELOG.md razem z archiwum starszych wersji (`docs/changelog/*.md`).
 *
 * Od 2 października 2026 w `CHANGELOG.md` zostają sekcja „Nieopublikowane”
 * i kilka najnowszych wersji, a starsze leżą w archiwum. Testy, które
 * wcześniej pilnowały CAŁEGO pliku (numeracja wersji, zdublowane wpisy,
 * zera wiodące), czytają go przez tę klasę — kolejność jest stała:
 * najpierw CHANGELOG.md, potem pliki archiwum w kolejności odnośników
 * z sekcji „Starsze wersje” na dole CHANGELOG.md (od najnowszych do
 * najstarszych), więc nagłówki „## Alfa 0.N” nadal maleją od góry do dołu.
 */
final class PelnyChangelog
{
    public const GLOWNY = 'CHANGELOG.md';

    public const KATALOG_ARCHIWUM = 'docs/changelog';

    /**
     * Ścieżki względne: CHANGELOG.md i podlinkowane pliki archiwum, po kolei.
     *
     * @return list<string>
     */
    public static function pliki(): array
    {
        $glowny = (string) file_get_contents(base_path(self::GLOWNY));
        $pliki = [self::GLOWNY];

        if (preg_match_all('#\]\((docs/changelog/[A-Za-z0-9._-]+\.md)\)#', $glowny, $dopasowania)) {
            foreach ($dopasowania[1] as $sciezka) {
                if (! in_array($sciezka, $pliki, true)) {
                    $pliki[] = $sciezka;
                }
            }
        }

        return $pliki;
    }

    /**
     * Pliki `docs/changelog/*.md`, do których CHANGELOG.md nie ma odnośnika —
     * archiwum, które cicho wypadłoby z testów.
     *
     * @return list<string>
     */
    public static function niepodlinkowane(): array
    {
        $istniejace = array_map(
            static fn (string $p): string => self::KATALOG_ARCHIWUM.'/'.basename($p),
            glob(base_path(self::KATALOG_ARCHIWUM.'/*.md')) ?: [],
        );

        return array_values(array_diff($istniejace, self::pliki()));
    }

    /** Sklejona treść wszystkich plików, w stałej kolejności. */
    public static function tresc(): string
    {
        return implode("\n", array_map(
            static fn (string $p): string => (string) file_get_contents(base_path($p)),
            self::pliki(),
        ));
    }
}

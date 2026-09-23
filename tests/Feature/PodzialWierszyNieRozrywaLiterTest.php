<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `\R` BEZ MODYFIKATORA `u` TNIE POLSKIE „ą" NA PÓŁ (#1276).
 *
 * MECHANIZM
 * Bez `u` PCRE pracuje na bajtach, a `\R` dopasowuje wtedy także bajt 0x85
 * (NEL, U+0085). W UTF-8 litera „ą" to `C4 85` — jej DRUGI bajt jest więc dla
 * `\R` końcem wiersza. `preg_split('/\R/', …)` na `ci.yml` dawał 2148
 * „wierszy" zamiast 1899, a skaner dostawał strzępy w rodzaju `#  która ma własn?`.
 * Żadna inna polska litera ani typograficzny znak (– — „ ” …) nie zawiera 0x85
 * — ale jedno „ą" w komentarzu wystarczy, a komentarze w workflow są po polsku.
 *
 * DLACZEGO TO NIE KOSMETYKA
 * `CiDajeKazdemuJobowiWlasneNarzedziaTest` już raz przez to nie widział ANI
 * JEDNEGO joba i przechodził, nie sprawdzając niczego. Dziewięć innych miejsc
 * w `tests/` cięło dalej tak samo, w tym skaner poświadczeń
 * (`PoswiadczeniaPozaRepozytoriumTest`) i okno `array_slice` przed `exit 0`
 * w `PortMarkiMaWlasnaBramkeCiTest`, które trafiało tylko dlatego, że w tych
 * czterech wierszach akurat nie było „ą".
 *
 * CZEGO PILNUJE
 * Żaden wzorzec w `tests/`, `scripts/` i `app/` nie używa `\R` bez `u`.
 * Poprawny zapis to jawna lista końców wiersza — `/\r\n|\n|\r/` — a nie
 * dopisanie `u`: `u` na niepoprawnym UTF-8 każe `preg_split` zwrócić `false`,
 * a `?: []` robi z tego pustą listę, czyli znowu skan, który nic nie widzi.
 * `\R` z `u` strażnik przepuszcza — dzieli poprawnie, choć kruche.
 *
 * CZEGO NIE PILNUJE
 *   • wzorców w heredoc/nowdoc i składanych z kilku literałów, w których `\R`
 *     stoi w innym kawałku niż modyfikatory — wtedy strażnik ZGŁOSI brak `u`
 *     (fałszywy alarm, nie fałszywa zieleń);
 *   • `\v` bez `u` (też łapie 0x85) — dziś nikt go nie używa;
 *   • skryptów w Pythonie i JS: tam wyrażenia działają na znakach, nie bajtach.
 */
class PodzialWierszyNieRozrywaLiterTest extends TestCase
{
    private const KATALOGI = ['tests', 'scripts', 'app'];

    /**
     * Kontrola ujemna samego mechanizmu: pokazuje, że wada jest prawdziwa w tym
     * PHP i tym PCRE. Gdyby kiedyś przestała być, ten test zapali i powie, że
     * strażnik chroni przed czymś, czego już nie ma — a nie będzie po cichu zielony.
     */
    public function test_nel_bez_u_rozrywa_litere_a_jawna_lista_nie(): void
    {
        $tekst = "# ma własną\nkolejka: tak";

        $this->assertSame('c485', bin2hex('ą'), 'Plik testu nie jest w UTF-8 — kontrola nic nie mierzy.');

        // C4 | 85 \n → cięcie na 0x85 i drugie na \n: trzy strzępy zamiast dwóch wierszy.
        $this->assertSame(
            ["# ma własn\xC4", '', 'kolejka: tak'],
            preg_split(self::wzorzecNel(''), $tekst),
            'Bez `u` podział miał przeciąć „ą" między bajtem C4 a 85 — jeśli już nie tnie, wada zniknęła z PCRE.',
        );

        $this->assertSame(['# ma własną', 'kolejka: tak'], preg_split('/\r\n|\n|\r/', $tekst));
        $this->assertSame(['# ma własną', 'kolejka: tak'], preg_split(self::wzorzecNel('u'), $tekst));
        $this->assertSame(['a', 'b', 'c', 'd'], preg_split('/\r\n|\n|\r/', "a\r\nb\nc\rd"), 'Jawna lista ma rozumieć też CRLF i samo CR.');
    }

    /** Klasyfikator ma łapać wadę i przepuszczać poprawne zapisy. */
    public function test_skaner_rozpoznaje_wzorzec_bez_u(): void
    {
        $nel = '\\'.'R';

        $this->assertSame([3], $this->wadliweWiersze("<?php\n\n\$w = preg_split('/{$nel}/', \$t);\n"));
        $this->assertSame([1], $this->wadliweWiersze("<?php preg_match('/^a:{$nel}(.*)/ms', \$t);"));
        $this->assertSame([1], $this->wadliweWiersze("<?php preg_split(\"/{$nel}/\", \$t);"));

        $this->assertSame([], $this->wadliweWiersze("<?php preg_split('/{$nel}/u', \$t);"));
        $this->assertSame([], $this->wadliweWiersze("<?php preg_match('~a{$nel}b~mu', \$t);"));
        $this->assertSame([], $this->wadliweWiersze("<?php preg_split('/\\r\\n|\\n|\\r/', \$t);"));
        $this->assertSame([], $this->wadliweWiersze("<?php\n// preg_split('/{$nel}/') psuło\n/* '/{$nel}/' */\n"), 'Komentarz nie jest wzorcem.');
    }

    public function test_zaden_wzorzec_w_repozytorium_nie_uzywa_nel_bez_u(): void
    {
        $wady = [];
        $przeskanowane = 0;

        foreach ($this->plikiPhp() as $sciezka) {
            $przeskanowane++;

            foreach ($this->wadliweWiersze((string) file_get_contents(base_path($sciezka))) as $wiersz) {
                $wady[] = $sciezka.':'.$wiersz;
            }
        }

        // Skan, który nie znalazł plików, też byłby zielony — więc pustka ma zapalić.
        $this->assertGreaterThan(500, $przeskanowane, 'Strażnik przeskanował podejrzanie mało plików PHP.');

        $nel = '`\\'.'R`';

        $this->assertSame([], $wady, implode("\n", [
            'Wzorzec z '.$nel.' bez modyfikatora `u` tnie „ą" (C4 85) na pół — bajt 0x85 to dla '.$nel.' koniec wiersza.',
            "Dziel wiersze jawną listą: preg_split('/\\r\\n|\\n|\\r/', \$tekst). Zobacz #1276.",
            'Miejsca:',
            ...$wady,
        ]));
    }

    // ---------------------------------------------------------------- pomocnicze

    /**
     * Numery wierszy literałów, które zawierają `\R`, a nie kończą się
     * ogranicznikiem z modyfikatorami zawierającymi `u`.
     *
     * @return list<int>
     */
    private function wadliweWiersze(string $zrodlo): array
    {
        $nel = '\\'.'R';
        $wiersze = [];

        foreach (token_get_all($zrodlo) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $wartosc = substr($token[1], 1, -1);
            $wartosc = $token[1][0] === "'"
                ? str_replace(['\\\\', "\\'"], ['\\', "'"], $wartosc)
                : str_replace('\\\\', '\\', $wartosc);

            if (! str_contains($wartosc, $nel)) {
                continue;
            }

            if (preg_match('~[/#\~!@%|]([a-zA-Z]*)$~', $wartosc, $m) === 1 && str_contains($m[1], 'u')) {
                continue;
            }

            $wiersze[] = $token[2];
        }

        return $wiersze;
    }

    /** @return list<string> */
    private function plikiPhp(): array
    {
        $pliki = [];

        foreach (self::KATALOGI as $katalog) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($katalog), \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $plik) {
                if ($plik->isFile() && $plik->getExtension() === 'php') {
                    $pliki[] = substr($plik->getPathname(), strlen(base_path()) + 1);
                }
            }
        }

        sort($pliki);

        return $pliki;
    }

    /** Wzorzec `\R` składany z kawałków, żeby ten plik sam nie zawierał wady, której szuka. */
    private static function wzorzecNel(string $modyfikatory): string
    {
        return '/'.'\\'.'R/'.$modyfikatory;
    }
}

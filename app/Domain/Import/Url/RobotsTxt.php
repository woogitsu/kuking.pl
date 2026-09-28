<?php

declare(strict_types=1);

namespace App\Domain\Import\Url;

/**
 * Odczyt pliku `robots.txt` według RFC 9309 — tyle, ile potrzeba, żeby
 * uczciwie odpowiedzieć „czy wolno nam pobrać TĘ ścieżkę" (D-300).
 *
 * Reguły:
 *  - grupa dla naszego tokenu (`KukingImport`) wygrywa z grupą `*`;
 *    kilka grup dla tego samego tokenu łączy się w jedną;
 *  - wygrywa NAJDŁUŻSZE dopasowanie; przy remisie `Allow` wygrywa z `Disallow`;
 *  - `*` w ścieżce to dowolny ciąg, `$` na końcu kotwiczy koniec adresu;
 *  - pusta reguła `Disallow:` niczego nie zabrania.
 *
 * Co robić, gdy pliku nie ma albo serwer nie odpowiada, rozstrzyga
 * `PobieraczStron` (RFC 9309 §2.3.1: 4xx = wolno, 5xx i brak odpowiedzi
 * = nie wolno) — ta klasa czyta tylko treść.
 */
final class RobotsTxt
{
    public const TOKEN = 'kukingimport';

    /**
     * @param  list<array{bool, string}>  $reguly  [czy_allow, wzorzec]
     */
    private function __construct(private readonly array $reguly) {}

    public static function wszystkoWolno(): self
    {
        return new self([]);
    }

    public static function nicNieWolno(): self
    {
        return new self([[false, '/']]);
    }

    public static function zTresci(string $tresc): self
    {
        /** @var array<string, list<array{bool, string}>> $grupy */
        $grupy = [];
        $biezaceAgenty = [];
        $wRegulach = false;

        foreach (preg_split('/\r\n|\r|\n/', $tresc) ?: [] as $linia) {
            $linia = trim((string) preg_replace('/#.*$/', '', $linia));

            if ($linia === '' || ! str_contains($linia, ':')) {
                continue;
            }

            [$pole, $wartosc] = array_map('trim', explode(':', $linia, 2));
            $pole = strtolower($pole);

            if ($pole === 'user-agent') {
                // Nowa grupa zaczyna się od user-agent, który stoi PO regułach.
                if ($wRegulach) {
                    $biezaceAgenty = [];
                    $wRegulach = false;
                }

                $agent = strtolower($wartosc);
                $biezaceAgenty[] = $agent;
                $grupy[$agent] ??= [];

                continue;
            }

            if ($pole !== 'allow' && $pole !== 'disallow') {
                continue;
            }

            $wRegulach = true;

            if ($wartosc === '') {
                continue;
            }

            foreach ($biezaceAgenty as $agent) {
                $grupy[$agent][] = [$pole === 'allow', $wartosc];
            }
        }

        if (array_key_exists(self::TOKEN, $grupy)) {
            return new self($grupy[self::TOKEN]);
        }

        return new self($grupy['*'] ?? []);
    }

    /**
     * @param  string  $sciezka  ścieżka z zapytaniem, np. `/przepis/sernik?porcje=4`
     */
    public function wolno(string $sciezka): bool
    {
        $sciezka = $sciezka === '' ? '/' : $sciezka;
        $najlepszaDlugosc = -1;
        $wynik = true;

        foreach ($this->reguly as [$allow, $wzorzec]) {
            if (! self::pasuje($wzorzec, $sciezka)) {
                continue;
            }

            $dlugosc = strlen($wzorzec);

            if ($dlugosc > $najlepszaDlugosc || ($dlugosc === $najlepszaDlugosc && $allow)) {
                $najlepszaDlugosc = $dlugosc;
                $wynik = $allow;
            }
        }

        return $wynik;
    }

    private static function pasuje(string $wzorzec, string $sciezka): bool
    {
        $kotwica = str_ends_with($wzorzec, '$');
        $rdzen = $kotwica ? substr($wzorzec, 0, -1) : $wzorzec;

        $regex = '#^'.str_replace('\*', '.*', preg_quote($rdzen, '#')).($kotwica ? '$' : '').'#';

        return preg_match($regex, $sciezka) === 1;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Dziennik decyzji: jeden plik na decyzję w `docs/decyzje/`, indeks w `docs/DECISIONS.md`.
 *
 * DLACZEGO PLIKI, A NIE JEDEN DZIENNIK (25 września 2026, decyzja właściciela).
 * Jeden plik z ponad 16 tys. wierszy, dopisywany zawsze na końcu, dawał konflikt
 * w każdej parze równoległych PR-ów z nową decyzją. Nowa decyzja to teraz NOWY
 * PLIK, a indeks jest generowany z plików (`php scripts/decyzje-indeks.php`),
 * więc konflikt w indeksie rozwiązuje się jednym poleceniem, nie ręcznie.
 *
 * Bez zależności od Laravela: tę samą klasę woła generator z `scripts/`
 * i testy przez `Tests\Support\DziennikDecyzji`.
 */
final class DziennikDecyzji
{
    public const KATALOG = 'docs/decyzje';

    public const INDEKS = 'docs/DECISIONS.md';

    /** `D-NNN-slug.md` (decyzja) albo `U-NNN-slug.md` (uzupełnienie do issue #NNN). */
    public const WZORZEC_NAZWY = '/^(D|U)-(\d{3,4})-[a-z0-9]+(?:-[a-z0-9]+)*\.md$/';

    public const POCZATEK_TABELI = '<!-- indeks-decyzji:poczatek — generuje php scripts/decyzje-indeks.php, nie edytuj ręcznie -->';

    public const KONIEC_TABELI = '<!-- indeks-decyzji:koniec -->';

    public function __construct(private readonly string $korzen) {}

    /** Czy ścieżka (względna albo pełna) jest plikiem wpisu dziennika. */
    public static function jestWpisem(string $sciezka): bool
    {
        $sciezka = str_replace('\\', '/', $sciezka);

        return str_contains($sciezka, self::KATALOG.'/')
            && preg_match(self::WZORZEC_NAZWY, basename($sciezka)) === 1;
    }

    /**
     * Pliki wpisów w kolejności numerów: najpierw decyzje, potem uzupełnienia.
     * Inne pliki katalogu (ADR-y, przeglądy) nie są wpisami.
     *
     * @return list<string> ścieżki względne
     */
    public function pliki(): array
    {
        $pliki = [];

        foreach (scandir($this->korzen.'/'.self::KATALOG) ?: [] as $nazwa) {
            if (preg_match(self::WZORZEC_NAZWY, $nazwa, $m) === 1) {
                $pliki[] = [$m[1] === 'D' ? 0 : 1, (int) $m[2], $nazwa];
            }
        }

        sort($pliki);

        return array_map(static fn (array $p): string => self::KATALOG.'/'.$p[2], $pliki);
    }

    /**
     * Treść wszystkich wpisów złączona w kolejności numerów — tak, jak stał
     * dawny `docs/DECISIONS.md`. Dla strażników, które szukają nagłówka
     * „## D-NNN" albo sekcji między nagłówkami.
     */
    public function tresc(): string
    {
        return implode("\n", array_map(
            fn (string $plik): string => (string) file_get_contents($this->korzen.'/'.$plik),
            $this->pliki(),
        ));
    }

    /**
     * Wiersze indeksu wyprowadzone z plików — jedyne źródło prawdy o indeksie.
     *
     * @return list<array{numer: string, tytul: string, status: string, plik: string}>
     */
    public function wpisy(): array
    {
        $wpisy = [];

        foreach ($this->pliki() as $plik) {
            $linie = explode("\n", (string) file_get_contents($this->korzen.'/'.$plik));
            $naglowek = $linie[0];

            if (preg_match('/^## (D-\d+(?:-ROBOCZA)?|Uzupełnienie #\d+)\s*(?:·|—)\s*(.+)$/u', $naglowek, $m) === 1) {
                $numer = $m[1];
                $tytul = trim($m[2]);
            } else {
                $numer = '?';
                $tytul = $naglowek;
            }

            $wpisy[] = [
                'numer' => $numer,
                'tytul' => $tytul,
                'status' => $this->status($linie),
                'plik' => $plik,
            ];
        }

        return $wpisy;
    }

    /**
     * Status z pierwszego wiersza „Status: …" (do „·" albo końca wiersza).
     * Wpis bez takiego wiersza dostaje „—": starsze decyzje go nie mają.
     *
     * @param  list<string>  $linie
     */
    private function status(array $linie): string
    {
        foreach ($linie as $linia) {
            if (preg_match('/Status:\s*(.+)$/u', $linia, $m) === 1) {
                $status = explode(' · ', $m[1])[0];
                $status = trim(str_replace(['**', '`'], '', $status), " \t,;.");

                return $status === '' ? '—' : $status;
            }
        }

        return '—';
    }

    /** Tabela indeksu dokładnie w tej postaci, w jakiej ma stać w `docs/DECISIONS.md`. */
    public function tabela(): string
    {
        $wiersze = [
            self::POCZATEK_TABELI,
            '',
            '| Numer | Decyzja | Status | Plik |',
            '|---|---|---|---|',
        ];

        foreach ($this->wpisy() as $wpis) {
            $wzgledny = substr($wpis['plik'], strlen('docs/'));
            $wiersze[] = '| '.$wpis['numer'].' | '.$this->komorka($wpis['tytul']).' | '
                .$this->komorka($wpis['status']).' | ['.basename($wpis['plik']).']('.$wzgledny.') |';
        }

        $wiersze[] = '';
        $wiersze[] = self::KONIEC_TABELI;

        return implode("\n", $wiersze);
    }

    private function komorka(string $tekst): string
    {
        return str_replace('|', '\\|', $tekst);
    }

    /** Tabela obecnie zapisana w indeksie (między znacznikami) albo null. */
    public function tabelaWIndeksie(): ?string
    {
        $indeks = (string) @file_get_contents($this->korzen.'/'.self::INDEKS);
        $od = strpos($indeks, self::POCZATEK_TABELI);
        $do = strpos($indeks, self::KONIEC_TABELI);

        if ($od === false || $do === false || $do < $od) {
            return null;
        }

        return substr($indeks, $od, $do - $od + strlen(self::KONIEC_TABELI));
    }

    /** Wpisuje świeżą tabelę w indeks. Zwraca true, gdy coś się zmieniło. */
    public function odswiezIndeks(): bool
    {
        $sciezka = $this->korzen.'/'.self::INDEKS;
        $indeks = (string) file_get_contents($sciezka);
        $stara = $this->tabelaWIndeksie();

        if ($stara === null) {
            throw new \RuntimeException('W '.self::INDEKS.' nie ma znaczników tabeli indeksu.');
        }

        $nowy = str_replace($stara, $this->tabela(), $indeks);
        file_put_contents($sciezka, $nowy);

        return $nowy !== $indeks;
    }

    /** Pierwszy wolny numer decyzji: największy zwykły numer (< 1000) plus jeden. */
    public function nastepnyNumer(): string
    {
        $najwyzszy = 0;

        foreach ($this->pliki() as $plik) {
            if (preg_match('/^D-(\d{3})-/', basename($plik), $m) === 1) {
                $najwyzszy = max($najwyzszy, (int) $m[1]);
            }
        }

        return sprintf('D-%03d', $najwyzszy + 1);
    }

    /**
     * Wszystkie usterki dziennika, po polsku, każda z tym, co zrobić.
     *
     * @return list<string>
     */
    public function usterki(): array
    {
        $usterki = [];
        $numery = [];

        foreach (scandir($this->korzen.'/'.self::KATALOG) ?: [] as $nazwa) {
            if (preg_match('/^[DU]-\d/', $nazwa) === 1 && preg_match(self::WZORZEC_NAZWY, $nazwa) !== 1) {
                $usterki[] = self::KATALOG.'/'.$nazwa.' — nazwa poza wzorem D-NNN-krotki-slug.md '
                    .'(małe litery, cyfry i myślniki). Zmień nazwę pliku.';
            }
        }

        foreach ($this->pliki() as $plik) {
            $tresc = (string) file_get_contents($this->korzen.'/'.$plik);
            $linie = explode("\n", $tresc);
            preg_match(self::WZORZEC_NAZWY, basename($plik), $m);
            $numerZNazwy = $m[1].'-'.$m[2];

            $oczekiwany = $m[1] === 'D'
                ? '/^## D-'.$m[2].'(-ROBOCZA)?\s*(·|—)\s*\S/u'
                : '/^## Uzupełnienie #'.ltrim($m[2], '0').'\s*—\s*\S/u';

            if (preg_match($oczekiwany, $linie[0]) !== 1) {
                $usterki[] = $plik.' — pierwszy wiersz ma być nagłówkiem „## '
                    .($m[1] === 'D' ? $numerZNazwy.' · Tytuł' : 'Uzupełnienie #'.ltrim($m[2], '0').' — Tytuł')
                    .'" z tym samym numerem co nazwa pliku. Jest: '.var_export($linie[0], true);
            }

            $naglowkiH2 = preg_match_all('/^## /m', $tresc);

            if ($naglowkiH2 !== 1) {
                $usterki[] = $plik.' — ma '.$naglowkiH2.' nagłówków „## ". Jeden plik to jedna decyzja; '
                    .'podsekcje pisz jako „### ".';
            }

            $numery[$numerZNazwy][] = $plik;
        }

        foreach ($numery as $numer => $pliki) {
            if (count($pliki) > 1) {
                $usterki[] = $numer.' ma '.count($pliki).' pliki: '.implode(', ', $pliki).'. Dwa PR-y wzięły '
                    .'ten sam numer — nadaj młodszemu pierwszy wolny (php scripts/decyzje-indeks.php --nastepny), '
                    .'zmień nagłówek i nazwę pliku, przepnij odnośniki.';
            }
        }

        $indeks = (string) @file_get_contents($this->korzen.'/'.self::INDEKS);

        if (preg_match_all('/^## (D-\d+|Uzupełnienie #\d+).*$/mu', $indeks, $stare) > 0) {
            $usterki[] = self::INDEKS.' ma treść decyzji w starym układzie ('.implode('; ', $stare[1]).'). '
                .'Ten plik jest tylko indeksem. Przenieś każdy wpis do NOWEGO pliku '
                .self::KATALOG.'/D-NNN-krotki-slug.md (nagłówek „## D-NNN · Tytuł" w pierwszym wierszu), '
                .'usuń go z indeksu i odśwież tabelę: php scripts/decyzje-indeks.php. '
                .'Kroki: docs/flota/PRZENIESIENIE_PO_PODZIALE.md';
        }

        $tabela = $this->tabelaWIndeksie();

        if ($tabela === null) {
            $usterki[] = self::INDEKS.' — brak znaczników tabeli indeksu. Weź plik z main '
                .'(git checkout origin/main -- '.self::INDEKS.') i uruchom: php scripts/decyzje-indeks.php';
        } elseif ($tabela !== $this->tabela()) {
            $usterki[] = self::INDEKS.' — tabela indeksu nie zgadza się z plikami w '.self::KATALOG.'/. '
                .'Uruchom: php scripts/decyzje-indeks.php (także po konflikcie w tabeli — nie scalaj jej ręcznie).';
        }

        return $usterki;
    }
}

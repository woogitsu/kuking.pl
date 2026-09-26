<?php

declare(strict_types=1);

/*
 * Szczyt połączeń PostgreSQL z wyeksportowanego dziennika serwera (issue #598).
 *
 * PO CO. Czujka `kuking:budzet-polaczen` zapisuje co godzinę jedną linię
 * pomiaru do kanału `pomiary`, a tryb `--probki` jedną linię podsumowania
 * okna. Dziennik Railway jest jedynym magazynem tego szeregu
 * (docs/DATABASE.md §598 F) — ale szereg w dzienniku to jeszcze nie liczba
 * do wpisania w #598. Ten skrypt robi z niego liczbę: minimum, medianę,
 * maksimum i chwilę maksimum, osobno dla czujki godzinnej i dla okien.
 *
 * Działa LOKALNIE na pliku, który właściciel sam wyeksportował z panelu.
 * Nie łączy się z niczym, nie potrzebuje Laravela ani bazy.
 *
 * UŻYCIE
 *   php scripts/szczyt-polaczen-z-dziennika.php dziennik.txt
 *   railway logs ... | php scripts/szczyt-polaczen-z-dziennika.php
 *
 * Rozumie trzy kształty linii:
 *   1. zwykły LineFormatter:  [...] production.INFO: kuking:budzet-polaczen {"stan":...}
 *   2. JsonFormatter Monologa: {"message":"kuking:budzet-polaczen","context":{...},"datetime":"..."}
 *   3. eksport Railway, gdzie kształt 1 albo 2 siedzi w polu "message".
 *
 * KOD WYJŚCIA
 *   0 — znaleziono co najmniej jeden pomiar,
 *   2 — nie znaleziono ŻADNEGO pomiaru. To nie jest „zapas jest", tylko
 *       „szeregu nie ma" — dokładnie ta pomyłka, która 17–19.09.2026 wisiała
 *       za LOG_LEVEL=warning (WERYFIKACJA_BUDZETU_POLACZEN_598.md §2).
 */

const ZNACZNIK_GODZINNY = 'kuking:budzet-polaczen';
const ZNACZNIK_OKNA = 'kuking:budzet-polaczen:szczyt';

/**
 * @return array{0: string, 1: array<string, mixed>, 2: string|null}|null [znacznik, kontekst, czas]
 */
function rozbierzLinie(string $linia): ?array
{
    $linia = trim($linia);

    if ($linia === '' || ! str_contains($linia, ZNACZNIK_GODZINNY)) {
        return null;
    }

    $czas = null;
    $json = json_decode($linia, true);

    if (is_array($json)) {
        $czas = isset($json['datetime']) ? (string) $json['datetime']
            : (isset($json['timestamp']) ? (string) $json['timestamp'] : null);

        if (isset($json['context']) && is_array($json['context']) && isset($json['message'])) {
            $znacznik = trim((string) $json['message']);

            return in_array($znacznik, [ZNACZNIK_GODZINNY, ZNACZNIK_OKNA], true)
                ? [$znacznik, $json['context'], $czas]
                : null;
        }

        if (isset($json['message']) && is_string($json['message'])) {
            $wewnetrzna = rozbierzLinie($json['message']);

            if ($wewnetrzna !== null) {
                return [$wewnetrzna[0], $wewnetrzna[1], $wewnetrzna[2] ?? $czas];
            }
        }

        return null;
    }

    // Kształt 1. Znacznik okna zawiera znacznik godzinny, więc najpierw okno.
    foreach ([ZNACZNIK_OKNA, ZNACZNIK_GODZINNY] as $znacznik) {
        $pozycja = strpos($linia, $znacznik.' {');

        if ($pozycja === false) {
            continue;
        }

        $reszta = substr($linia, $pozycja + strlen($znacznik) + 1);
        $koniec = strrpos($reszta, '}');
        $kontekst = $koniec === false ? null : json_decode(substr($reszta, 0, $koniec + 1), true);

        if (! is_array($kontekst)) {
            return null;
        }

        if (preg_match('/^\[([^\]]+)\]/', $linia, $m) === 1) {
            $czas = $m[1];
        }

        return [$znacznik, $kontekst, $czas];
    }

    return null;
}

/**
 * @param  list<array{wartosc: int, czas: string|null}>  $probki
 * @return array{liczba: int, min: int, mediana: int, max: int, max_o: string|null}
 */
function podsumuj(array $probki): array
{
    $wartosci = array_column($probki, 'wartosc');
    sort($wartosci);
    $szczyt = $probki[0];

    foreach ($probki as $p) {
        if ($p['wartosc'] > $szczyt['wartosc']) {
            $szczyt = $p;
        }
    }

    return [
        'liczba' => count($wartosci),
        'min' => $wartosci[0],
        'mediana' => $wartosci[intdiv(count($wartosci) - 1, 2)],
        'max' => $szczyt['wartosc'],
        'max_o' => $szczyt['czas'],
    ];
}

$zrodlo = $argv[1] ?? 'php://stdin';
$uchwyt = @fopen($zrodlo, 'r');

if ($uchwyt === false) {
    fwrite(STDERR, "Nie da się otworzyć: {$zrodlo}\n");
    exit(1);
}

$godzinne = [];
$okna = [];
$stany = [];
$progi = null;

while (($linia = fgets($uchwyt)) !== false) {
    $wynik = rozbierzLinie($linia);

    if ($wynik === null) {
        continue;
    }

    [$znacznik, $kontekst, $czas] = $wynik;

    if ($znacznik === ZNACZNIK_OKNA && isset($kontekst['szczyt_zajete_serwer'])) {
        $okna[] = ['wartosc' => (int) $kontekst['szczyt_zajete_serwer'], 'czas' => $kontekst['szczyt_o'] ?? $czas];
    } elseif ($znacznik === ZNACZNIK_GODZINNY && isset($kontekst['zajete_serwer'])) {
        $godzinne[] = ['wartosc' => (int) $kontekst['zajete_serwer'], 'czas' => $czas];
        $stan = (string) ($kontekst['stan'] ?? '?');
        $stany[$stan] = ($stany[$stan] ?? 0) + 1;
    } else {
        continue;
    }

    $progi = [
        'dostepne' => $kontekst['dostepne'] ?? null,
        'budzet_szczytowy' => $kontekst['budzet_szczytowy'] ?? null,
        'prog_ostrzegawczy' => $kontekst['prog_ostrzegawczy'] ?? null,
    ];
}

fclose($uchwyt);

if ($godzinne === [] && $okna === []) {
    fwrite(STDERR, "Nie znaleziono ani jednej linii pomiaru `kuking:budzet-polaczen {...}`.\n"
        ."To znaczy: szeregu NIE MA, a nie: zapas jest. Sprawdź okno eksportu i kanał `pomiary`.\n");
    exit(2);
}

echo "Szczyt połączeń PostgreSQL z dziennika (zajęte backendy na CAŁYM serwerze)\n";

foreach (['czujka godzinna (:25)' => $godzinne, 'okna --probki' => $okna] as $nazwa => $probki) {
    if ($probki === []) {
        echo "\n{$nazwa}: brak linii\n";

        continue;
    }

    $p = podsumuj($probki);
    echo "\n{$nazwa}: {$p['liczba']} pomiarów\n";
    echo "  min {$p['min']} · mediana {$p['mediana']} · MAX {$p['max']}".($p['max_o'] !== null ? " (o {$p['max_o']})" : '')."\n";
}

if ($stany !== []) {
    ksort($stany);
    echo "\nstany czujki godzinnej: ".implode(', ', array_map(fn ($s, $n) => "{$s}={$n}", array_keys($stany), $stany))."\n";
}

if ($progi !== null) {
    echo "\nz ostatniej linii: dostępne miejsca {$progi['dostepne']}, budżet policzony {$progi['budzet_szczytowy']}, "
        ."próg ostrzegawczy {$progi['prog_ostrzegawczy']}\n";
}

exit(0);

<?php

declare(strict_types=1);

/*
 * Indeks dziennika decyzji: `docs/DECISIONS.md` ← pliki `docs/decyzje/D-NNN-*.md`.
 *
 *   php scripts/decyzje-indeks.php             odśwież tabelę indeksu z plików
 *   php scripts/decyzje-indeks.php --sprawdz   tylko sprawdź (kod 1 przy usterce)
 *   php scripts/decyzje-indeks.php --nastepny  wypisz pierwszy wolny numer decyzji
 *
 * Po konflikcie scalania w tabeli indeksu: weź dowolną stronę i uruchom bez
 * argumentów — tabela jest w całości wyprowadzana z plików. Te same reguły
 * pilnuje w CI `tests/Feature/DziennikDecyzjiZgodnyZIndeksemTest.php`.
 */

require __DIR__.'/../tests/Support/DziennikDecyzji.php';

use Tests\Support\DziennikDecyzji;

$dziennik = new DziennikDecyzji(dirname(__DIR__));
$argumenty = array_slice($argv, 1);

if ($argumenty === ['--nastepny']) {
    echo $dziennik->nastepnyNumer(), "\n";
    exit(0);
}

if ($argumenty === []) {
    try {
        $zmiana = $dziennik->odswiezIndeks();
    } catch (RuntimeException $e) {
        fwrite(STDERR, $e->getMessage()."\n");
        exit(1);
    }

    echo $zmiana ? "Tabela indeksu odświeżona.\n" : "Tabela indeksu była aktualna.\n";
} elseif ($argumenty !== ['--sprawdz']) {
    fwrite(STDERR, 'Nieznane argumenty: '.implode(' ', $argumenty).". Dozwolone: --sprawdz, --nastepny.\n");
    exit(2);
}

$usterki = $dziennik->usterki();

foreach ($usterki as $usterka) {
    fwrite(STDERR, '✗ '.$usterka."\n");
}

if ($usterki !== []) {
    exit(1);
}

echo count($dziennik->pliki())." wpisów, indeks zgodny. Następny wolny numer: {$dziennik->nastepnyNumer()}.\n";

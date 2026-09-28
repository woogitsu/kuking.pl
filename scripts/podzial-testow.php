<?php

declare(strict_types=1);

/*
 * PODZIAŁ ZESTAWU TESTÓW NA CZĘŚCI — dla równoległych jobów CI.
 *
 * DLACZEGO WŁASNY SKRYPT. Job „Testy (PostgreSQL 18)" trwał 25–40 minut
 * i wyznaczał czas całego przebiegu. Decyzja właściciela z 24.09.2026: cztery
 * równoległe części. PHPUnit 12.5 (composer.lock) nie ma opcji `--shard`,
 * a `php artisan test` (Collision 8) też jej nie dokłada. Oba narzędzia
 * przyjmują za to LISTĘ PLIKÓW jako argumenty pozycyjne — i to wystarcza.
 *
 * SKĄD LISTA. Dokładnie z tych katalogów i plików, które wymienia
 * `phpunit.xml` w `<testsuites>`, z tym samym sufiksem (domyślnie `Test.php`)
 * i tymi samymi `<exclude>`. Nie z `find tests`: pod `tests/` leżą też
 * `Fixtures/` i `Measurements/`, których zwykłe `php artisan test` NIE
 * uruchamia — wciągnięte do części zmieniłyby zestaw, zamiast go podzielić.
 * `<groups><exclude>` z `phpunit.xml` działa dalej, bo część uruchamia się
 * z tą samą konfiguracją — pliki grupy `dwa-polaczenia` trafiają do części,
 * ale ich testy są odfiltrowane tak samo jak w pełnym przebiegu.
 *
 * JAK DZIELI. Deterministycznie i bez stanu: waga pliku to liczba metod
 * testowych, pliki od najcięższego (remis: ścieżka rosnąco) trafiają do
 * części o najmniejszej dotąd wadze (remis: niższy numer). Ten sam commit
 * daje zawsze ten sam podział, na każdym runnerze.
 *
 * KOMPLETNOŚĆ. Przed wypisaniem czegokolwiek skrypt liczy WSZYSTKIE części
 * i sprawdza, że ich suma to pełna lista, bez dubli i bez pustej części.
 * Niezależnie od tego `PodzialTestowJestKompletnyTest` porównuje sumę części
 * z listą, którą zwraca sam PHPUnit (`--list-test-files`).
 *
 * UŻYCIE
 *   php scripts/podzial-testow.php 2 4       pliki części 2 z 4, jeden na wiersz
 *   php scripts/podzial-testow.php --wszystkie   pełna lista (odniesienie)
 *   php scripts/podzial-testow.php --podsumowanie 4   liczność i waga części
 */

$repo = dirname(__DIR__);

/**
 * @return list<string> ścieżki względem repozytorium, posortowane, bez dubli
 */
function kuking_pliki_testow(string $repo): array
{
    $konfiguracja = $repo.'/phpunit.xml';
    $xml = @simplexml_load_file($konfiguracja);

    if ($xml === false || ! isset($xml->testsuites->testsuite)) {
        kuking_przerwij("Nie da się odczytać <testsuites> z {$konfiguracja}.");
    }

    $pliki = [];

    foreach ($xml->testsuites->testsuite as $zestaw) {
        // `<exclude>` w PHPUnicie dotyczy tylko własnego zestawu.
        $zZestawu = [];
        $wykluczone = [];

        foreach ($zestaw->exclude as $wykluczenie) {
            $wykluczone[] = rtrim(kuking_sciezka_wzgledna($repo, (string) $wykluczenie), '/');
        }

        foreach ($zestaw->directory as $katalog) {
            $sufiks = (string) ($katalog['suffix'] ?? '') ?: 'Test.php';
            $sciezka = $repo.'/'.trim((string) $katalog);

            if (! is_dir($sciezka)) {
                kuking_przerwij("Katalog zestawu z phpunit.xml nie istnieje: {$katalog}");
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sciezka, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $plik) {
                if ($plik->isFile() && str_ends_with($plik->getFilename(), $sufiks)) {
                    $zZestawu[] = kuking_sciezka_wzgledna($repo, $plik->getPathname());
                }
            }
        }

        foreach ($zestaw->file as $plik) {
            $zZestawu[] = kuking_sciezka_wzgledna($repo, trim((string) $plik));
        }

        $pliki[] = array_filter($zZestawu, function (string $plik) use ($wykluczone): bool {
            foreach ($wykluczone as $wykluczenie) {
                if ($plik === $wykluczenie || str_starts_with($plik, $wykluczenie.'/')) {
                    return false;
                }
            }

            return true;
        });
    }

    $pliki = array_values(array_unique(array_merge(...$pliki)));
    sort($pliki, SORT_STRING);

    if ($pliki === []) {
        kuking_przerwij('phpunit.xml nie wskazuje ani jednego pliku testu — brak trafień nie jest zgodą.');
    }

    return $pliki;
}

function kuking_sciezka_wzgledna(string $repo, string $sciezka): string
{
    $sciezka = str_replace('\\', '/', $sciezka);
    $sciezka = (string) preg_replace('#^\./#', '', $sciezka);
    $prefiks = rtrim(str_replace('\\', '/', $repo), '/').'/';

    return str_starts_with($sciezka, $prefiks) ? substr($sciezka, strlen($prefiks)) : $sciezka;
}

/** Liczba metod testowych w pliku; co najmniej 1, żeby plik zawsze coś ważył. */
function kuking_waga(string $repo, string $plik): int
{
    $tresc = (string) file_get_contents($repo.'/'.$plik);
    $metody = preg_match_all('/^\s*public\s+function\s+test\w*\s*\(/m', $tresc);
    $atrybuty = preg_match_all('/#\[Test\]/', $tresc);

    return max(1, (int) $metody + (int) $atrybuty);
}

/**
 * @param  list<string>  $pliki
 * @return array<int, list<string>> numer części (od 1) => posortowane pliki
 */
function kuking_podziel(string $repo, array $pliki, int $liczba): array
{
    $wagi = [];

    foreach ($pliki as $plik) {
        $wagi[$plik] = kuking_waga($repo, $plik);
    }

    $kolejnosc = $pliki;
    usort($kolejnosc, fn (string $a, string $b): int => [$wagi[$b], $a] <=> [$wagi[$a], $b]);

    $czesci = array_fill(1, $liczba, []);
    $obciazenie = array_fill(1, $liczba, 0);

    foreach ($kolejnosc as $plik) {
        $najlzejsza = 1;

        for ($numer = 2; $numer <= $liczba; $numer++) {
            if ($obciazenie[$numer] < $obciazenie[$najlzejsza]) {
                $najlzejsza = $numer;
            }
        }

        $czesci[$najlzejsza][] = $plik;
        $obciazenie[$najlzejsza] += $wagi[$plik];
    }

    foreach ($czesci as $numer => $lista) {
        sort($lista, SORT_STRING);
        $czesci[$numer] = $lista;
    }

    return $czesci;
}

/**
 * Suma części = pełna lista, bez dubli, bez pustej części.
 *
 * @param  list<string>  $pliki
 * @param  array<int, list<string>>  $czesci
 */
function kuking_sprawdz_kompletnosc(array $pliki, array $czesci): void
{
    $suma = array_merge(...array_values($czesci));
    $duble = array_keys(array_filter(array_count_values($suma), fn (int $ile): bool => $ile > 1));

    if ($duble !== []) {
        kuking_przerwij('Plik w więcej niż jednej części: '.implode(', ', $duble));
    }

    $brakujace = array_diff($pliki, $suma);
    $obce = array_diff($suma, $pliki);

    if ($brakujace !== [] || $obce !== []) {
        kuking_przerwij('Suma części różni się od pełnej listy. Brakuje: '
            .implode(', ', $brakujace).'; spoza listy: '.implode(', ', $obce));
    }

    foreach ($czesci as $numer => $lista) {
        if ($lista === []) {
            kuking_przerwij("Część {$numer} jest pusta — job byłby zielony, nie uruchamiając niczego.");
        }
    }
}

function kuking_przerwij(string $powod): never
{
    fwrite(STDERR, 'Podział testów: '.$powod.PHP_EOL);

    exit(1);
}

function kuking_liczba(string $wartosc, string $co): int
{
    if (preg_match('/^[1-9][0-9]*$/', $wartosc) !== 1) {
        kuking_przerwij("{$co} musi być dodatnią liczbą całkowitą, dostałem „{$wartosc}\".");
    }

    return (int) $wartosc;
}

$argumenty = array_slice($argv, 1);
$pliki = kuking_pliki_testow($repo);

if ($argumenty === ['--wszystkie']) {
    echo implode(PHP_EOL, $pliki).PHP_EOL;

    exit(0);
}

if (count($argumenty) === 2 && $argumenty[0] === '--podsumowanie') {
    $liczba = kuking_liczba($argumenty[1], 'Liczba części');
    $czesci = kuking_podziel($repo, $pliki, $liczba);
    kuking_sprawdz_kompletnosc($pliki, $czesci);

    foreach ($czesci as $numer => $lista) {
        $waga = array_sum(array_map(fn (string $plik): int => kuking_waga($repo, $plik), $lista));
        echo "Część {$numer}/{$liczba}: ".count($lista)." plików, {$waga} metod testowych".PHP_EOL;
    }

    echo 'Razem: '.count($pliki).' plików, każdy w dokładnie jednej części.'.PHP_EOL;

    exit(0);
}

if (count($argumenty) !== 2) {
    kuking_przerwij('użycie: php scripts/podzial-testow.php <część> <liczba części>'
        .' | --wszystkie | --podsumowanie <liczba części>');
}

$liczba = kuking_liczba($argumenty[1], 'Liczba części');
$numer = kuking_liczba($argumenty[0], 'Numer części');

if ($numer > $liczba) {
    kuking_przerwij("Część {$numer} nie istnieje przy podziale na {$liczba}.");
}

$czesci = kuking_podziel($repo, $pliki, $liczba);
kuking_sprawdz_kompletnosc($pliki, $czesci);

echo implode(PHP_EOL, $czesci[$numer]).PHP_EOL;

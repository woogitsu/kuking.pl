<?php

declare(strict_types=1);

// Izolowany proces: fatal memory_limit starego parsera nie zabija PHPUnit.
$root = dirname(__DIR__, 2);

require $root.'/app/Domain/Users/Import/PaczkaOdrzucona.php';
require $root.'/app/Domain/Users/Import/PodgladPaczkiEksportu.php';

use App\Domain\Users\Import\PaczkaOdrzucona;
use App\Domain\Users\Import\PodgladPaczkiEksportu;

$naglowek = '{"o_tym_pliku":{"serwis":"Kuking.pl","wersja_formatu":1},"przepisy":[]';
$pusteSekcje = $naglowek.',"wpisy":[],"kolekcje":[]';
$tryb = $argv[1] ?? '';

function gestyEksportReceptur(int $liczba = 5_000, int $liczbaSkladnikow = 5, int $liczbaKrokow = 3): string
{
    // Te same klucze co CollectUserExportData::recipes(), w tym zagnieżdżone
    // składniki i kroki. Oba warianty mieszczą się w limitach sekcji i 12 MiB.
    $przepis = [
        'tytul' => 'Przepis 0', 'adres_w_serwisie' => 'przepis-0',
        'plik_do_czytania' => 'przepisy/przepis-0.html', 'krotki_opis' => null,
        'porcje' => 4, 'pokazuj_wartosci_odzywcze' => false,
        'szacunkowy_koszt_zl' => null, 'przygotowanie_minuty' => 10,
        'gotowanie_minuty' => 20, 'trudnosc' => 'easy', 'widocznosc' => 'private',
        'status' => 'draft', 'skad_przepis' => 'own',
        'skad_przepis_opis' => 'Mój własny', 'zrodlo_adres' => null,
        'od_kogo' => null, 'notatka_o_zrodle' => null, 'w_rodzinie_od_roku' => null,
        'alergeny_stan' => 'unchecked', 'alergeny' => [],
        'alergeny_potwierdzone' => null, 'moja_wersja_od' => null,
        'na_podstawie_przepisu' => null, 'zdjecie_glowne' => null,
        'skan_zeszytu' => null, 'utworzono' => '2026-10-02T00:00:00+00:00',
        'opublikowano' => null, 'skladniki' => array_fill(0, $liczbaSkladnikow, [
            'grupa' => null, 'zapis' => '2 g mąki', 'ile' => 2,
            'bez_ilosci' => false, 'jednostka' => 'g',
            'skladnik_ze_slownika' => 'mąka', 'uwaga' => null, 'zamienniki' => null,
        ]), 'kroki' => array_map(static fn (int $numer): array => [
            'numer' => $numer, 'opis' => 'Wymieszaj', 'minutnik_sekundy' => null, 'zdjecie' => null,
        ], range(1, $liczbaKrokow)), 'ile_razy_ugotowany_przez_innych' => 0, 'komentarze' => [],
    ];

    $json = '{"o_tym_pliku":{"serwis":"Kuking.pl","wersja_formatu":1},"przepisy":[';

    for ($i = 0; $i < $liczba; $i++) {
        $przepis['tytul'] = 'Przepis '.$i;
        $przepis['adres_w_serwisie'] = 'przepis-'.$i;
        $przepis['plik_do_czytania'] = 'przepisy/przepis-'.$i.'.html';
        $json .= ($i === 0 ? '' : ',').json_encode($przepis, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    return $json.'],"wpisy":[],"kolekcje":[]}';
}

$json = match ($tryb) {
    // Dokładnie ten kształt z #2611: płytki, poprawny, pod limitem 12 MB.
    'obce_obiekty' => $pusteSekcje.',"extra":['.str_repeat('{"a":0},', 799_999).'{"a":0}]}',
    'dozwolone_obiekty' => '{"o_tym_pliku":{"serwis":"Kuking.pl","wersja_formatu":1},"przepisy":['.str_repeat('{"a":0},', 799_999).'{"a":0}],"wpisy":[],"kolekcje":[]}',
    'puste_tablice' => $pusteSekcje.',"extra":['.str_repeat('[],', 799_999).'[]]}',
    'granica_dozwolona' => $pusteSekcje.',"extra":['.str_repeat('{"a":0},', 149_993).'{"a":0}]}',
    'granica_odrzucona' => $pusteSekcje.',"extra":['.str_repeat('{"a":0},', 149_994).'{"a":0}]}',
    'za_duzo_separatorow' => $pusteSekcje.',"extra":['.str_repeat('0,', 1_000_001).'0]}',
    'wiele_kluczy' => $pusteSekcje.',"extra":['.str_repeat('{"a":0,"b":0,"c":0,"d":0,"e":0,"f":0,"g":0,"h":0},', 99_999).'{"a":0,"b":0,"c":0,"d":0,"e":0,"f":0,"g":0,"h":0}]}',
    'gesty_eksport' => gestyEksportReceptur(),
    'gesty_eksport_rozbudowany' => gestyEksportReceptur(1_000, 40, 20),
    // Poprawna paczka blisko limitu 12 MB: 3500 wpisów, treść < 4000 znaków.
    'duzy_eksport' => $naglowek.',"wpisy":['.str_repeat('{"rodzaj":"dish","tresc":"'.str_repeat('a', 3_300).'"},', 3_499).'{"rodzaj":"dish","tresc":"'.str_repeat('a', 3_300).'"}],"kolekcje":[]}',
    // Nawiasy i przecinki w tekście, także po escapowanym cudzysłowie, nie są slotami.
    'napisy' => $pusteSekcje.',"extra":"'.str_repeat('{},[]\\"{,}[}', 850_000).'"}',
    'zagniezdzenie' => $pusteSekcje.',"extra":'.str_repeat('[', 12).'0'.str_repeat(']', 12).'}',
    'maly' => $pusteSekcje.'}',
    default => throw new InvalidArgumentException('Nieznany syntetyczny tryb.'),
};

$r = new ReflectionClass(PodgladPaczkiEksportu::class);

if (in_array($tryb, ['gesty_eksport', 'gesty_eksport_rozbudowany'], true)) {
    // Ten syntetyczny tekst nie ma nawiasów ani przecinków wewnątrz stringów.
    echo 'GESTY_EKSPORT kontenery='.(substr_count($json, '{') + substr_count($json, '['))
        .' separatory='.substr_count($json, ',').PHP_EOL;
}

try {
    /** @var array<string, mixed> $wynik */
    $wynik = $r->getMethod('dane')->invoke($r->newInstanceWithoutConstructor(), $json);
    echo 'OK bajty='.strlen($json).' szczyt='.memory_get_peak_usage(true).' sekcje='.count($wynik).PHP_EOL;
} catch (PaczkaOdrzucona $e) {
    echo 'ODRZUCENIE kod='.$e->kod.' bajty='.strlen($json).' szczyt='.memory_get_peak_usage(true).PHP_EOL;

    exit(3);
}

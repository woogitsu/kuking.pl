<?php

declare(strict_types=1);

/*
 * Prawdziwe polskie zapisy składników do testów kalkulatora (D-299).
 *
 * Dwa pierwsze przepisy to listy składników z `DemoSeeder` co do znaku.
 * Pozostałe to listy spisane z opowieści w
 * `database/seeders/dane/tresc-zalazkowa.json` (tam przepis jest jednym
 * akapitem, bez listy) — ilości i sposób zapisu wzięte z tekstu autora,
 * łącznie z tym, czego autor nie zmierzył („trochę śmietany”, „olej do
 * smażenia”). To jest celowe: kalkulator ma uczciwie odmówić tam, gdzie
 * człowiek gotuje „na oko”.
 *
 * `oczekiwane`: 'policzone' albo 'bez_liczb' — dokładnie tak, jak liczy
 * dziś słownik z `database/data/odzywcze/`. Zmiana słownika, która to
 * przestawia, ma być świadoma (test pokaże, który przepis się zmienił).
 */
return [
    ['ref' => 'demo-rosol', 'tytul' => 'Rosół babci Zofii', 'porcje' => 6, 'oczekiwane' => 'policzone', 'skladniki' => [
        '1 kurczak zagrodowy, najlepiej starsza kura',
        '2 duże marchewki',
        '1 pietruszka, korzeń',
        'kawałek selera, wielkości pięści',
        '1 por, sama biała część',
        '1 cebula, opalona nad palnikiem',
        '4 ziarna ziela angielskiego',
        '2 liście laurowe',
        'sól — do smaku, na końcu',
        'natka pietruszki do podania',
    ]],
    ['ref' => 'demo-chleb', 'tytul' => 'Chleb pszenno-żytni na zakwasie', 'porcje' => 1, 'oczekiwane' => 'bez_liczb', 'skladniki' => [
        '150 g aktywnego zakwasu żytniego',
        '350 g mąki pszennej chlebowej typ 750',
        '150 g mąki żytniej typ 720',
        '350 ml letniej wody',
        '10 g soli',
    ]],
    ['ref' => 'p1', 'tytul' => 'Sernik po mamie', 'porcje' => 12, 'oczekiwane' => 'policzone', 'skladniki' => [
        '1 kg twarogu',
        '6 jajek',
        'niecała szklanka cukru',
        'pół kostki masła',
        '2 łyżki mąki ziemniaczanej',
    ]],
    ['ref' => 'p2', 'tytul' => 'Fasola z pomidorami na dwa dni', 'porcje' => 6, 'oczekiwane' => 'policzone', 'skladniki' => [
        'pół kilo suchej fasoli',
        '1 cebula',
        'kawałek kiełbasy',
        '2-3 obrane pomidory',
        'trochę majeranku',
        'pieprz',
        'sól do smaku',
    ]],
    ['ref' => 'p4', 'tytul' => 'Zwykły placek ze śliwkami', 'porcje' => 16, 'oczekiwane' => 'policzone', 'skladniki' => [
        '3 jajka',
        'szklanka cukru',
        'pół szklanki oleju',
        'szklanka kwaśnej śmietany',
        'dwie i pół szklanki mąki',
        '2 łyżeczki proszku do pieczenia',
        'śliwki węgierki – ok. 1 kg',
        'odrobina cukru do posypania',
    ]],
    ['ref' => 'p7', 'tytul' => 'Placki ziemniaczane bez kombinowania', 'porcje' => 3, 'oczekiwane' => 'bez_liczb', 'skladniki' => [
        '4 duże ziemniaki',
        '1 nieduża cebula',
        '1 jajko',
        '1 łyżka mąki',
        'sól',
        'olej do smażenia',
    ]],
    ['ref' => 'p10', 'tytul' => 'Śledzie z cebulą i jabłkiem', 'porcje' => 4, 'oczekiwane' => 'bez_liczb', 'skladniki' => [
        '4 filety śledziowe',
        '2 cebule',
        '1 kwaśne jabłko',
        'kilka łyżek oleju',
        'pieprz',
        'odrobina soku z cytryny',
    ]],
    ['ref' => 'p12', 'tytul' => 'Chleb żytni na zakwasie', 'porcje' => 1, 'oczekiwane' => 'bez_liczb', 'skladniki' => [
        'ok. pół szklanki aktywnego zakwasu',
        '2 szklanki mąki żytniej',
        '2 szklanki letniej wody',
        '1 łyżeczka soli',
        'garść ziaren słonecznika',
    ]],
    ['ref' => 'p15', 'tytul' => 'Zupa grzybowa z ziemniakami', 'porcje' => 4, 'oczekiwane' => 'bez_liczb', 'skladniki' => [
        '2 garście pokrojonych grzybów',
        '1 cebula',
        '1 marchew',
        '5 ziemniaków',
        'liść laurowy',
        'sól, pieprz',
        'trochę śmietany',
        'koperek',
    ]],
    ['ref' => 'p16', 'tytul' => 'Jajecznica z pomidorami i cebulą', 'porcje' => 2, 'oczekiwane' => 'policzone', 'skladniki' => [
        '1 cebula',
        '2 pomidory',
        '4 jajka',
        'sól, pieprz',
    ]],
    ['ref' => 'p17', 'tytul' => 'Pyzy drożdżowe do sosu', 'porcje' => 4, 'oczekiwane' => 'policzone', 'skladniki' => [
        'pół kilograma mąki',
        'ok. 25 g świeżych drożdży',
        'szklanka letniego mleka',
        '2 jajka',
        'łyżka roztopionego masła',
        'szczypta soli',
    ]],
    ['ref' => 'p19', 'tytul' => 'Drożdżowiec ze śliwkami i kruszonką', 'porcje' => 16, 'oczekiwane' => 'bez_liczb', 'skladniki' => [
        '500 g mąki',
        '30 g świeżych drożdży',
        'szklanka ciepłego mleka',
        '2 jajka',
        'pół szklanki cukru',
        'ok. 80 g roztopionego masła',
        'połówki śliwek',
        'kruszonka z mąki, masła i cukru',
    ]],
    ['ref' => 'p20', 'tytul' => 'Kompot z jabłek i śliwek', 'porcje' => 12, 'oczekiwane' => 'policzone', 'skladniki' => [
        '4-5 jabłek',
        '2 garście śliwek bez pestek',
        '3 l wody',
        'kawałek cynamonu',
        '2 łyżki cukru',
    ]],
];

<?php

declare(strict_types=1);

/*
 * Router `php -S` dla `ImportKlientPrzypietyTest` — lokalna strona, na którą
 * `KlientPrzypiety` łączy się prawdziwym cURL-em. Każde żądanie dopisuje
 * wiersz `Host|ścieżka` do pliku z KUKING_DZIENNIK_SERWERA, żeby test widział,
 * co naprawdę doszło do serwera.
 */

$dziennik = getenv('KUKING_DZIENNIK_SERWERA');

if (is_string($dziennik) && $dziennik !== '') {
    file_put_contents($dziennik, ($_SERVER['HTTP_HOST'] ?? '').'|'.($_SERVER['REQUEST_URI'] ?? '')."\n", FILE_APPEND);
}

$sciezka = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($sciezka === '/duza') {
    // 3 MB bez Content-Length — limit musi zadziałać w trakcie pobierania.
    header('Content-Type: text/html; charset=utf-8');

    for ($i = 0; $i < 300; $i++) {
        echo str_repeat('a', 10_000);
        flush();
    }

    return true;
}

header('Content-Type: text/html; charset=utf-8');
echo '<html><body><p>Sernik z lokalnego serwera</p></body></html>';

return true;

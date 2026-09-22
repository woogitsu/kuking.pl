<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Prawdziwe pliki zdjęć do testu obciążeniowego (#605)
|--------------------------------------------------------------------------
|
| #605 wymienia wprost jedno z ograniczeń baseline'u load581: „testowy WebP
| miał ok. 2,3 KB zamiast rozmiaru zbliżonego do prawdziwego feedu”. Ten
| skrypt robi pliki, które NIE są tym błędem: prawdziwe JPEG-i 12 / 24 / 48 Mpx,
| o rozmiarze megabajtowym, przechodzące walidację `ObslugiwaneZdjecie`.
|
| Obraz nie jest ani szumem, ani płaskim kolorem, i to jest celowe:
|   * czysty szum daje plik grubo ponad `media.max_bytes` (15 MB) i nie
|     przechodzi walidacji,
|   * płaska plama daje 200 KB przy 48 Mpx, czyli dokładnie ten sam błąd
|     co WebP 2,3 KB w load581.
| Zamiast tego: gradient + pasy + prostokąty + ziarno na grubej siatce —
| entropia zbliżona do zdjęcia z telefonu.
|
| Pliki lądują POZA repozytorium (domyślnie /home/mateusz/kuking-b605-run/zdjecia),
| bo kilkanaście megabajtów binariów nie ma czego szukać w gicie.
|
| Uruchomienie:
|
|     php scripts/zdjecia-obciazenia-605.php /sciezka/do/katalogu
|
| Wypisuje JSON: wymiary, megapiksele, jakość i rzeczywistą liczbę bajtów.
*/

$katalog = $argv[1] ?? '/home/mateusz/kuking-b605-run/zdjecia';

if (! is_dir($katalog) && ! mkdir($katalog, 0o775, true) && ! is_dir($katalog)) {
    fwrite(STDERR, "Nie mogę utworzyć katalogu $katalog".PHP_EOL);
    exit(1);
}

/** Górna granica z `config/kuking.php` → `media.max_bytes`. */
const MAKS_BAJTOW = 15 * 1024 * 1024;

$warianty = [
    '12mpx' => [4000, 3000],
    '24mpx' => [5657, 4243],
    '48mpx' => [8000, 6000],
];

$wynik = [];

foreach ($warianty as $nazwa => [$szerokosc, $wysokosc]) {
    $plik = rtrim($katalog, '/')."/kuking-b605-$nazwa.jpg";

    $jakosc = 88;
    $bajty = 0;

    do {
        $obraz = imagecreatetruecolor($szerokosc, $wysokosc);

        // Tło: pionowy gradient — tanie, a już samo w sobie nie jest płaskie.
        for ($y = 0; $y < $wysokosc; $y++) {
            $kolor = imagecolorallocate(
                $obraz,
                (int) (40 + 180 * ($y / $wysokosc)),
                (int) (90 + 120 * (1 - $y / $wysokosc)),
                (int) (60 + 150 * abs(0.5 - $y / $wysokosc) * 2),
            );
            imageline($obraz, 0, $y, $szerokosc - 1, $y, $kolor);
        }

        // Kształty: to one dają krawędzie, na których JPEG naprawdę pracuje.
        mt_srand(605);
        for ($i = 0; $i < 600; $i++) {
            $x = mt_rand(0, $szerokosc - 1);
            $y = mt_rand(0, $wysokosc - 1);
            $kolor = imagecolorallocate($obraz, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledellipse($obraz, $x, $y, mt_rand(50, 900), mt_rand(50, 900), $kolor);
        }

        // Ziarno na siatce 8 px: podnosi entropię do poziomu zdjęcia,
        // ale nie do poziomu białego szumu (ten nie zmieściłby się w 15 MB).
        for ($y = 0; $y < $wysokosc; $y += 8) {
            for ($x = 0; $x < $szerokosc; $x += 8) {
                $j = mt_rand(0, 255);
                imagefilledrectangle($obraz, $x, $y, min($x + 3, $szerokosc - 1), min($y + 3, $wysokosc - 1), imagecolorallocate($obraz, $j, 255 - $j, ($j * 7) % 256));
            }
        }

        ob_start();
        imagejpeg($obraz, null, $jakosc);
        $tresc = (string) ob_get_clean();
        imagedestroy($obraz);

        $bajty = strlen($tresc);
        if ($bajty <= MAKS_BAJTOW) {
            file_put_contents($plik, $tresc);
            break;
        }

        // Za duży — schodzimy z jakością i próbujemy jeszcze raz. To jest
        // prawdziwe ograniczenie produktu (`media.max_bytes`), a nie wygoda
        // pomiaru: plik, którego serwis by nie przyjął, nic tu nie mierzy.
        $jakosc -= 8;
    } while ($jakosc >= 40);

    if (! is_file($plik)) {
        fwrite(STDERR, 'Nie udało się zejść poniżej '.MAKS_BAJTOW." B dla $nazwa".PHP_EOL);
        exit(1);
    }

    [$w, $h] = getimagesize($plik);
    $wynik[$nazwa] = [
        'plik' => $plik,
        'szerokosc' => $w,
        'wysokosc' => $h,
        'megapiksele' => round($w * $h / 1_000_000, 1),
        'jakosc_jpeg' => $jakosc,
        'bajty' => filesize($plik),
        'megabajty' => round(filesize($plik) / 1048576, 2),
    ];
}

echo json_encode($wynik, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL;

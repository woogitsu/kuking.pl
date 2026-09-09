<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * Usuwa z bajtów zdjęcia WYŁĄCZNIE współrzędne GPS, zostawiając resztę
 * metadanych nietkniętą (D-023).
 *
 * PO CO TO ISTNIEJE
 * Warianty pokazywane w serwisie powstają przez przekodowanie do WebP, więc
 * EXIF-u w nich nie ma. Oryginał był zapisywany bajt w bajt — łącznie ze
 * współrzędnymi, czyli adresem kuchni użytkownika — i trafiał do paczki
 * RODO. Opublikowana polityka prywatności mówi wprost „nie zbieramy
 * lokalizacji GPS", więc to zdanie było nieprawdziwe: RODO patrzy na
 * przechowywanie, nie na użycie, a „nie czytamy tego pola" nie znaczy „nie
 * zbieramy".
 *
 * DLACZEGO NIE PRZEKODOWUJEMY OBRAZU, ŻEBY POZBYĆ SIĘ EXIF-U W CAŁOŚCI
 * Bo oryginał ma pozostać wierną kopią pliku użytkownika — po to istnieje
 * (eksport danych ma oddać człowiekowi jego zdjęcie, nie zmniejszoną
 * kopię). Przekodowanie przez GD skasowałoby całe EXIF, ale i zmieniło
 * piksele. Aparat, obiektyw i data to informacja o ZDJĘCIU, którą
 * właściciel może chcieć odzyskać. Lokalizacja to informacja o CZŁOWIEKU.
 * Wypada tylko ona.
 *
 * DLACZEGO WYŁĄCZNIE ZERUJEMY BAJTY W MIEJSCU, NIGDY NIE SKRACAMY PLIKU
 * To jest sedno tej implementacji. EXIF to blok TIFF pełen przesunięć
 * liczonych od jego początku: wyrzucenie choćby jednego bajtu unieważnia
 * każde przesunięcie za nim, a długość segmentu zapisana jest jeszcze
 * w kontenerze (APP1 w JPEG, chunk w PNG i WebP). Zerowanie w miejscu
 * zachowuje wszystkie długości i wszystkie przesunięcia, więc ten sam kod
 * działa dla każdego z czterech obsługiwanych formatów bez znajomości ich
 * kontenerów — wystarczy znaleźć w pliku blok TIFF.
 *
 * NIE WYSTARCZY USTAWIĆ LICZBY WPISÓW GPS NA ZERO. Same bajty ze
 * współrzędnymi zostałyby wtedy w pliku jako nieodwoływany śmieć —
 * niewidoczny dla czytnika EXIF, ale wciąż czytelny dla kogoś, kto zajrzy
 * do pliku. Dlatego zerujemy też każdą wartość, na którą wskazywały wpisy.
 *
 * ZAKRES I POPRAWKA Z 9 WRZEŚNIA (A6-02)
 *
 * Do tego dnia ta klasa szukała bloku TIFF przez `strpos($bajty,
 * "Exif\0\0")` i zakładała, że ten sześciobajtowy nagłówek stoi przed TIFF-em
 * we WSZYSTKICH czterech kontenerach. TO JEST PRAWDA TYLKO DLA JPEG-a.
 * W JPEG prefiks `Exif\0\0` jest częścią segmentu APP1 i odróżnia go od
 * innych APP1 (np. XMP). W PNG i WebP dane chunku `eXIf` / `EXIF` to JUŻ
 * SAM BLOK TIFF, zaczynający się od `II*\0` albo `MM\0*` — żadnego prefiksu
 * tam nie ma. Poprawnie zapisany PNG i WebP przechodziły więc przez tę
 * funkcję NIETKNIĘTE, ze współrzędnymi w środku. Znalazł to audyt
 * zewnętrzny, odczytując zapisane pliki niezależnym dekoderem.
 *
 * Dlatego blok TIFF znajdujemy teraz PO KONTENERZE, a nie po tekście:
 *
 *   PNG    idziemy po chunkach od sygnatury do `IEND`, szukamy `eXIf`;
 *   WebP   idziemy po chunkach RIFF (z dopełnieniem do parzystej), `EXIF`;
 *   JPEG   idziemy po segmentach do `SOS`, szukamy APP1 z `Exif\0\0`;
 *   reszta zostaje przy `strpos` — to jedyna droga, jaką mamy do AVIF-a
 *          (blok `Exif` w ISOBMFF) i do plików zapisanych nietypowo.
 *
 * Zarówno w PNG, jak i w WebP część narzędzi MIMO WSZYSTKO wkłada prefiks
 * `Exif\0\0` na początek danych chunku. Dlatego po znalezieniu chunku
 * pomijamy prefiks, JEŚLI tam jest — obie odmiany działają.
 *
 * Jeśli bloku nie ma — zwracamy bajty bez zmiany, bo nie ma czego usuwać.
 * Jeśli plik ma EXIF, ale bez GPS-a (zdecydowana większość wgrań) — też nie
 * zmieniamy ani jednego bajtu.
 *
 * CZEGO TA KLASA NADAL NIE OBEJMUJE, wprost, żeby nikt nie zakładał więcej,
 * niż jest: EXIF-u zapisanego w PNG jako tekst (`zTXt`/`iTXt` z profilem
 * „Raw profile type exif"), metadanych XMP w żadnym kontenerze, ani AVIF-a
 * inaczej niż przez awaryjne `strpos`. XMP potrafi nieść własne pola
 * lokalizacji — to jest znana, nieprzykryta luka, a nie przeoczenie.
 *
 * PNG: chunk `eXIf` niesie własną sumę CRC32, więc po zerowaniu trzeba ją
 * przeliczyć. Bez tego przeglądarka uznałaby plik za uszkodzony. JPEG i
 * WebP nie mają sumy kontrolnej per segment, AVIF też nie.
 */
final class UsunGps
{
    /**
     * Prefiks segmentu APP1 w JPEG. W PNG i WebP zgodnie ze specyfikacją go
     * NIE MA — patrz „ZAKRES I POPRAWKA Z 9 WRZEŚNIA" w komentarzu klasy.
     */
    private const NAGLOWEK = "Exif\x00\x00";

    /** Osiem bajtów otwierających każdy plik PNG. */
    private const SYGNATURA_PNG = "\x89PNG\r\n\x1A\n";

    /** IFD0, wskaźnik na pod-IFD z GPS-em. */
    private const TAG_GPS_IFD = 0x8825;

    /** Rozmiar w bajtach jednej wartości danego typu TIFF (indeks = numer typu). */
    private const ROZMIAR_TYPU = [
        1 => 1,  // BYTE
        2 => 1,  // ASCII
        3 => 2,  // SHORT
        4 => 4,  // LONG
        5 => 8,  // RATIONAL
        6 => 1,  // SBYTE
        7 => 1,  // UNDEFINED
        8 => 2,  // SSHORT
        9 => 4,  // SLONG
        10 => 8, // SRATIONAL
        11 => 4, // FLOAT
        12 => 8, // DOUBLE
    ];

    public static function zBajtow(string $bajty): string
    {
        $blok = self::znajdzBlokTiff($bajty);

        if ($blok === null) {
            return $bajty;
        }

        [$tiff, $chunkPng] = $blok;

        $porzadek = substr($bajty, $tiff, 2);

        $maloEndian = match ($porzadek) {
            'II' => true,
            'MM' => false,
            default => null,
        };

        if ($maloEndian === null) {
            return $bajty;
        }

        // Magiczna liczba 42 tuż za porządkiem bajtów. Bez tego sprawdzenia
        // chunk, który przypadkiem zaczyna się od „II" albo „MM", zostałby
        // potraktowany jak TIFF i mogłoby dojść do zerowania czegokolwiek.
        if (self::short($bajty, $tiff + 2, $maloEndian) !== 42) {
            return $bajty;
        }

        $offsetIfd0 = self::long($bajty, $tiff + 4, $maloEndian);

        if ($offsetIfd0 === null) {
            return $bajty;
        }

        $offsetGps = self::znajdzOffsetGps($bajty, $tiff, $offsetIfd0, $maloEndian);

        if ($offsetGps === null) {
            return $bajty;
        }

        $wyczyszczone = self::wyzerujIfd($bajty, $tiff, $offsetGps, $maloEndian);

        if ($wyczyszczone === null) {
            return $bajty;
        }

        return $chunkPng === null
            ? $wyczyszczone
            : self::poprawCrcPng($wyczyszczone, $chunkPng);
    }

    /**
     * Znajduje początek bloku TIFF, idąc po STRUKTURZE kontenera — nie po
     * szukaniu tekstu w całym pliku. Uzasadnienie w komentarzu klasy
     * („ZAKRES I POPRAWKA Z 9 WRZEŚNIA").
     *
     * @return array{0: int, 1: ?int}|null pozycja TIFF-u oraz — dla PNG —
     *                                     pozycja typu chunku `eXIf`, której
     *                                     potrzebuje przeliczenie CRC
     */
    private static function znajdzBlokTiff(string $bajty): ?array
    {
        if (str_starts_with($bajty, self::SYGNATURA_PNG)) {
            return self::blokWPng($bajty);
        }

        if (str_starts_with($bajty, 'RIFF') && substr($bajty, 8, 4) === 'WEBP') {
            return self::blokWWebp($bajty);
        }

        if (str_starts_with($bajty, "\xFF\xD8")) {
            return self::blokWJpeg($bajty);
        }

        // AVIF i wszystko, czego nie rozpoznajemy. Jedyne, co możemy zrobić,
        // to poszukać nagłówka w bajtach — tak działała cała ta klasa przed
        // poprawką i dla AVIF-a nadal nie mamy nic lepszego.
        $pozycja = strpos($bajty, self::NAGLOWEK);

        return $pozycja === false ? null : [$pozycja + strlen(self::NAGLOWEK), null];
    }

    /**
     * PNG: sygnatura, potem chunki `długość (4, BE) · typ (4) · dane · CRC (4)`.
     * `eXIf` wolno postawić i przed `IDAT`, i po nim, więc idziemy do `IEND`,
     * a nie do pierwszego obrazu.
     *
     * @return array{0: int, 1: ?int}|null
     */
    private static function blokWPng(string $bajty): ?array
    {
        $poz = strlen(self::SYGNATURA_PNG);
        $koniec = strlen($bajty);

        while ($poz + 8 <= $koniec) {
            $dlugosc = self::dlugoscBe($bajty, $poz);
            $typ = substr($bajty, $poz + 4, 4);

            if ($dlugosc === null) {
                return null;
            }

            if ($typ === 'eXIf') {
                return [self::pomijPrefiks($bajty, $poz + 8), $poz + 4];
            }

            if ($typ === 'IEND') {
                return null;
            }

            // 4 długość + 4 typ + dane + 4 CRC. Przy zepsutym pliku suma może
            // się przekręcić albo stanąć w miejscu — wtedy wychodzimy, zamiast
            // kręcić się w nieskończoność.
            $nastepny = $poz + 12 + $dlugosc;

            if ($nastepny <= $poz || $nastepny > $koniec) {
                return null;
            }

            $poz = $nastepny;
        }

        return null;
    }

    /**
     * WebP: `RIFF · rozmiar (4, LE) · WEBP`, potem chunki
     * `typ (4) · rozmiar (4, LE) · dane`, każdy dopełniony do parzystej
     * długości. Chunk `EXIF` istnieje wyłącznie w formacie rozszerzonym
     * (z `VP8X`); prosty WebP z samym `VP8 ` nie ma gdzie trzymać metadanych.
     *
     * @return array{0: int, 1: ?int}|null
     */
    private static function blokWWebp(string $bajty): ?array
    {
        $poz = 12;
        $koniec = strlen($bajty);

        while ($poz + 8 <= $koniec) {
            $typ = substr($bajty, $poz, 4);
            $dlugosc = self::dlugoscLe($bajty, $poz + 4);

            if ($dlugosc === null) {
                return null;
            }

            if ($typ === 'EXIF') {
                return [self::pomijPrefiks($bajty, $poz + 8), null];
            }

            $nastepny = $poz + 8 + $dlugosc + ($dlugosc % 2);

            if ($nastepny <= $poz || $nastepny > $koniec) {
                return null;
            }

            $poz = $nastepny;
        }

        return null;
    }

    /**
     * JPEG: segmenty `FF · znacznik · długość (2, BE) · dane`. Szukamy APP1
     * (`FFE1`) zaczynającego się od `Exif\0\0`; inne APP1 (na przykład XMP)
     * pomijamy. Zatrzymujemy się na `SOS` (`FFDA`), bo za nim idą już bajty
     * obrazu, w których `Exif\0\0` mogłoby wystąpić przypadkiem — i właśnie
     * tego rodzaju trafienie stare `strpos` mogło potraktować poważnie.
     *
     * @return array{0: int, 1: ?int}|null
     */
    private static function blokWJpeg(string $bajty): ?array
    {
        $poz = 2;
        $koniec = strlen($bajty);

        while ($poz + 4 <= $koniec) {
            if ($bajty[$poz] !== "\xFF") {
                return null;
            }

            $znacznik = ord($bajty[$poz + 1]);

            // Znaczniki bez ładunku: RSTn (D0-D7), SOI (D8), EOI (D9).
            if ($znacznik >= 0xD0 && $znacznik <= 0xD9) {
                $poz += 2;

                continue;
            }

            if ($znacznik === 0xDA) {
                return null;
            }

            $dlugosc = self::short($bajty, $poz + 2, false);

            if ($dlugosc === null || $dlugosc < 2) {
                return null;
            }

            if ($znacznik === 0xE1 && substr($bajty, $poz + 4, strlen(self::NAGLOWEK)) === self::NAGLOWEK) {
                return [$poz + 4 + strlen(self::NAGLOWEK), null];
            }

            $nastepny = $poz + 2 + $dlugosc;

            if ($nastepny <= $poz || $nastepny > $koniec) {
                return null;
            }

            $poz = $nastepny;
        }

        return null;
    }

    /**
     * W PNG i WebP dane chunku to zgodnie ze specyfikacją SAM blok TIFF, ale
     * część narzędzi mimo wszystko wkłada tam prefiks `Exif\0\0` z JPEG-a.
     * Pomijamy go, jeśli jest — obie odmiany mają działać.
     */
    private static function pomijPrefiks(string $bajty, int $od): int
    {
        return substr($bajty, $od, strlen(self::NAGLOWEK)) === self::NAGLOWEK
            ? $od + strlen(self::NAGLOWEK)
            : $od;
    }

    private static function dlugoscBe(string $bajty, int $od): ?int
    {
        return self::long($bajty, $od, false);
    }

    private static function dlugoscLe(string $bajty, int $od): ?int
    {
        return self::long($bajty, $od, true);
    }

    /** Przechodzi wpisy IFD0 w poszukiwaniu wskaźnika na pod-IFD z GPS-em. */
    private static function znajdzOffsetGps(
        string $bajty,
        int $tiff,
        int $offsetIfd0,
        bool $maloEndian,
    ): ?int {
        $ileWpisow = self::short($bajty, $tiff + $offsetIfd0, $maloEndian);

        if ($ileWpisow === null) {
            return null;
        }

        for ($i = 0; $i < $ileWpisow; $i++) {
            $wpis = $tiff + $offsetIfd0 + 2 + ($i * 12);

            if (self::short($bajty, $wpis, $maloEndian) !== self::TAG_GPS_IFD) {
                continue;
            }

            return self::long($bajty, $wpis + 8, $maloEndian);
        }

        return null;
    }

    /**
     * Zeruje pod-IFD: najpierw wartości, na które wskazują jego wpisy, potem
     * same wpisy, a na końcu liczbę wpisów. Kolejność ma znaczenie — po
     * wyzerowaniu wpisów nie wiedzielibyśmy już, gdzie leżą ich wartości.
     *
     * Zwraca `null`, gdy którekolwiek przesunięcie wychodzi za plik. Wtedy
     * lepiej nie ruszyć niczego niż zniszczyć zdjęcie: plik z GPS-em jest
     * problemem prywatności, plik uszkodzony jest utratą pamiątki.
     */
    private static function wyzerujIfd(
        string $bajty,
        int $tiff,
        int $offsetIfd,
        bool $maloEndian,
    ): ?string {
        $poczatek = $tiff + $offsetIfd;
        $ileWpisow = self::short($bajty, $poczatek, $maloEndian);

        if ($ileWpisow === null) {
            return null;
        }

        $koniecWpisow = $poczatek + 2 + ($ileWpisow * 12);

        if ($koniecWpisow > strlen($bajty)) {
            return null;
        }

        for ($i = 0; $i < $ileWpisow; $i++) {
            $wpis = $poczatek + 2 + ($i * 12);

            $typ = self::short($bajty, $wpis + 2, $maloEndian);
            $ileWartosci = self::long($bajty, $wpis + 4, $maloEndian);

            if ($typ === null || $ileWartosci === null) {
                return null;
            }

            $dlugosc = (self::ROZMIAR_TYPU[$typ] ?? 0) * $ileWartosci;

            // Do czterech bajtów wartość siedzi w samym wpisie — wyzeruje ją
            // zerowanie wpisów niżej. Powyżej czterech wpis trzyma
            // przesunięcie i tam trzeba pójść osobno.
            if ($dlugosc <= 4) {
                continue;
            }

            $offsetWartosci = self::long($bajty, $wpis + 8, $maloEndian);

            if ($offsetWartosci === null) {
                return null;
            }

            $od = $tiff + $offsetWartosci;

            if ($od < 0 || $od + $dlugosc > strlen($bajty)) {
                return null;
            }

            $bajty = substr_replace($bajty, str_repeat("\x00", $dlugosc), $od, $dlugosc);
        }

        $bajty = substr_replace($bajty, str_repeat("\x00", $ileWpisow * 12), $poczatek + 2, $ileWpisow * 12);

        return substr_replace($bajty, "\x00\x00", $poczatek, 2);
    }

    /**
     * Przelicza CRC32 chunku `eXIf` w PNG. Chunk ma postać:
     * długość (4) · typ (4) · dane (długość) · CRC (4), a CRC liczy się
     * z typu i danych razem.
     *
     * Dla JPEG i WebP nie ma czego przeliczać, więc funkcja wychodzi od razu
     * — rozpoznajemy to po tym, że przed blokiem EXIF nie stoi `eXIf`.
     */
    private static function poprawCrcPng(string $bajty, int $typ): string
    {
        // `$typ` wskazuje cztery bajty nazwy chunku — dostajemy je wprost
        // z przejścia po chunkach, bo dane `eXIf` NIE muszą się zaczynać od
        // `Exif\0\0` i odliczanie wstecz od tego prefiksu było właśnie tym,
        // co nie działało dla poprawnie zapisanych PNG-ów.
        if ($typ < 4 || substr($bajty, $typ, 4) !== 'eXIf') {
            return $bajty;
        }

        $dlugosc = unpack('N', substr($bajty, $typ - 4, 4));

        if ($dlugosc === false) {
            return $bajty;
        }

        $dlugosc = (int) $dlugosc[1];
        $koniecDanych = $typ + 4 + $dlugosc;

        if ($koniecDanych + 4 > strlen($bajty)) {
            return $bajty;
        }

        $crc = crc32(substr($bajty, $typ, 4 + $dlugosc));

        return substr_replace($bajty, pack('N', $crc), $koniecDanych, 4);
    }

    private static function short(string $bajty, int $od, bool $maloEndian): ?int
    {
        if ($od < 0 || $od + 2 > strlen($bajty)) {
            return null;
        }

        $rozpakowane = unpack($maloEndian ? 'v' : 'n', substr($bajty, $od, 2));

        return $rozpakowane === false ? null : (int) $rozpakowane[1];
    }

    private static function long(string $bajty, int $od, bool $maloEndian): ?int
    {
        if ($od < 0 || $od + 4 > strlen($bajty)) {
            return null;
        }

        $rozpakowane = unpack($maloEndian ? 'V' : 'N', substr($bajty, $od, 4));

        return $rozpakowane === false ? null : (int) $rozpakowane[1];
    }
}

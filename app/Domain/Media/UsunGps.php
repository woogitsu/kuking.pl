<?php

declare(strict_types=1);

namespace App\Domain\Media;

/**
 * Usuwa z bajtów zdjęcia współrzędne GPS z EXIF-u i cały pakiet XMP,
 * zostawiając resztę metadanych nietkniętą (D-023, issue #1004).
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
 * XMP WYPADA W CAŁOŚCI, NIE POLE PO POLU (issue #1004)
 *
 * XMP to drugi, niezależny od EXIF-u zapis metadanych — i niesie własne
 * współrzędne: `exif:GPSLatitude`, `exif:GPSLongitude`, `GPSDest*`, a do tego
 * struktury lokalizacji IPTC (`Iptc4xmpExt:LocationCreated`/`LocationShown`)
 * i pola producentów (np. drony) w dowolnych przestrzeniach nazw, w kilku
 * serializacjach RDF naraz. Wyszukiwanie „pól GPS" w tym XML-u zawsze
 * przegapi jakąś odmianę, więc nie szukamy pól: CAŁY pakiet XMP zamieniamy
 * na spacje. To jest świadome odstępstwo od „reszta metadanych zostaje"
 * z D-023 i jest tanie: aparat, obiektyw, data i orientacja żyją w EXIF-ie,
 * który zostaje nietknięty; XMP dokłada zwykle historię edycji i właśnie
 * lokalizację.
 *
 * Z tego samego powodu wypada tekstowy profil EXIF w PNG („Raw profile type
 * exif", a razem z nim „… xmp", „… iptc", „… app1" — ImageMagick zapisuje
 * tam szesnastkowo te same bloki, razem z GPS-em).
 *
 * Zasada „nigdy nie skracamy pliku" obowiązuje dalej: pakiet dostaje spacje
 * w miejscu, a nie znika, więc długości segmentów i chunków się nie zmieniają.
 * Gdzie XMP jest:
 *
 *   JPEG   APP1 `http://ns.adobe.com/xap/1.0/\0` i XMP rozszerzony
 *          (`http://ns.adobe.com/xmp/extension/\0`, po nagłówku GUID-u);
 *   PNG    `iTXt`/`zTXt`/`tEXt` ze słowem kluczowym `XML:com.adobe.xmp`
 *          albo `Raw profile type …` — chunk staje się `tEXt` o tej samej
 *          długości, z samymi spacjami, i dostaje nową sumę CRC (dane
 *          `zTXt`/skompresowanego `iTXt` są zlib-em, spacje w nich nie dałyby
 *          poprawnego pliku);
 *   WebP   chunk `XMP `;
 *   AVIF i reszta  pakiet szukany w bajtach (`<?xpacket begin` … `end…?>`
 *          albo `<…xmpmeta` … `</…xmpmeta>`), jak awaryjne `strpos` wyżej.
 *
 * IPTC-IIM (JPEG APP13) współrzędnych nie ma — tylko nazwy miejsc — więc
 * zostaje, tak jak reszta EXIF-u.
 *
 * KILKA OBRAZÓW W JEDNYM JPEG-U (MPF, mapa wzmocnienia HDR) i AVIF BEZ
 * PREFIKSU `Exif\0\0` — od 25.09.2026 (audyt B5, znalezisko 5): każdy
 * kolejny obraz za pierwszym jest czyszczony tak samo jak główny
 * (`kolejneObrazyJpeg()`), a w kontenerze bez rozpoznanej struktury szukamy
 * też samego nagłówka TIFF (`usunGpsZKandydatowTiff()`).
 *
 * CZEGO TA KLASA NADAL NIE OBEJMUJE, wprost, żeby nikt nie zakładał więcej,
 * niż jest: AVIF-a inaczej niż przez szukanie w bajtach (EXIF po `Exif\0\0`
 * albo nagłówku TIFF, XMP po nagłówku pakietu) — bez parsera ISOBMFF
 * skompresowany element XMP w AVIF-ie zostałby niewidoczny.
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

    /** Nagłówek APP1 z pakietem XMP w JPEG. */
    private const NAGLOWEK_XMP_JPEG = "http://ns.adobe.com/xap/1.0/\x00";

    /**
     * Nagłówek APP1 z XMP rozszerzonym w JPEG. Za nim stoi 32-znakowy GUID
     * i dwie liczby po 4 bajty (pełna długość, przesunięcie) — dopiero potem
     * kawałek XML-u.
     */
    private const NAGLOWEK_XMP_ROZSZERZONY = "http://ns.adobe.com/xmp/extension/\x00";

    private const NAGLOWEK_XMP_ROZSZERZONY_RESZTA = 32 + 4 + 4;

    /** Słowo kluczowe chunku tekstowego PNG niosącego XMP. */
    private const SLOWO_XMP_PNG = 'XML:com.adobe.xmp';

    /** Prefiks słów kluczowych, pod którymi ImageMagick zapisuje surowe profile. */
    private const SLOWO_SUROWY_PROFIL_PNG = 'Raw profile type ';

    /**
     * Najwięcej obrazów w jednym pliku, które czyścimy poza pierwszym — i
     * najwięcej kandydatów na blok TIFF w kontenerze bez struktury (AVIF).
     * Telefon zapisuje 2–3 (obraz, podgląd MPF, mapa wzmocnienia HDR);
     * sufit jest po to, żeby spreparowany plik nie zamienił sanitacji w pętlę.
     */
    private const LIMIT_OBRAZOW = 16;

    public static function zBajtow(string $bajty): string
    {
        $wynik = self::usunXmp(self::usunGpsZExif($bajty));

        return str_starts_with($wynik, "\xFF\xD8")
            ? self::kolejneObrazyJpeg($wynik)
            : $wynik;
    }

    /**
     * KAŻDY OBRAZ W JPEG-U, NIE TYLKO PIERWSZY (audyt B5, znalezisko 5).
     *
     * JPEG z telefonu bywa kilkoma JPEG-ami sklejonymi w jeden plik: za `EOI`
     * obrazu głównego stoi podgląd albo mapa wzmocnienia HDR (MPF, APP2
     * `MPF\0` z tabelą przesunięć), a każdy z nich ma WŁASNY APP1 z EXIF-em
     * i własnym GPS-em. `blokWJpeg()` i `usunXmpZJpeg()` kończą na pierwszym
     * `SOS`, więc czyściły tylko obraz główny — pomiar z audytu: dwa
     * wystąpienia współrzędnych przed sanitacją, jedno po niej.
     *
     * Szukamy więc każdego kolejnego `FF D8 FF` (SOI i pierwszy znacznik).
     * W danych skompresowanych `FF` jest zawsze uzupełniane zerem albo
     * znacznikiem RST, więc `FF D8` nie pojawi się tam przypadkiem. Trafienie
     * w miniaturę wewnątrz APP1 obrazu głównego też nie szkodzi: to też JPEG
     * i czyszczenie go niczego nie psuje. Obie operacje nie zmieniają długości
     * pliku, więc przesunięcia z tabeli MPF zostają prawdziwe.
     */
    private static function kolejneObrazyJpeg(string $bajty): string
    {
        $od = 2;

        for ($i = 0; $i < self::LIMIT_OBRAZOW; $i++) {
            $poz = strpos($bajty, "\xFF\xD8\xFF", $od);

            if ($poz === false) {
                break;
            }

            $obraz = substr($bajty, $poz);
            $czysty = self::usunXmpZJpeg(self::usunGpsZExif($obraz));

            if ($czysty !== $obraz && strlen($czysty) === strlen($obraz)) {
                $bajty = substr($bajty, 0, $poz).$czysty;
            }

            $od = $poz + 3;
        }

        return $bajty;
    }

    /**
     * Zamienia na spacje każdy pakiet XMP, jaki kontener niesie — bez zmiany
     * długości pliku. Uzasadnienie w komentarzu klasy („XMP WYPADA W CAŁOŚCI").
     */
    private static function usunXmp(string $bajty): string
    {
        if (str_starts_with($bajty, self::SYGNATURA_PNG)) {
            return self::usunXmpZPng($bajty);
        }

        if (str_starts_with($bajty, 'RIFF') && substr($bajty, 8, 4) === 'WEBP') {
            return self::usunXmpZWebp($bajty);
        }

        if (str_starts_with($bajty, "\xFF\xD8")) {
            return self::usunXmpZJpeg($bajty);
        }

        return self::usunPakietyXmp($bajty);
    }

    /** JPEG: segmenty do `SOS`, tak jak w `blokWJpeg()`. */
    private static function usunXmpZJpeg(string $bajty): string
    {
        $poz = 2;
        $koniec = strlen($bajty);

        while ($poz + 4 <= $koniec) {
            if ($bajty[$poz] !== "\xFF") {
                return $bajty;
            }

            $znacznik = ord($bajty[$poz + 1]);

            if ($znacznik >= 0xD0 && $znacznik <= 0xD9) {
                $poz += 2;

                continue;
            }

            if ($znacznik === 0xDA) {
                return $bajty;
            }

            $dlugosc = self::short($bajty, $poz + 2, false);

            if ($dlugosc === null || $dlugosc < 2) {
                return $bajty;
            }

            $nastepny = $poz + 2 + $dlugosc;

            if ($nastepny <= $poz || $nastepny > $koniec) {
                return $bajty;
            }

            if ($znacznik === 0xE1) {
                $dane = $poz + 4;
                $od = null;

                if (substr($bajty, $dane, strlen(self::NAGLOWEK_XMP_JPEG)) === self::NAGLOWEK_XMP_JPEG) {
                    $od = $dane + strlen(self::NAGLOWEK_XMP_JPEG);
                } elseif (substr($bajty, $dane, strlen(self::NAGLOWEK_XMP_ROZSZERZONY)) === self::NAGLOWEK_XMP_ROZSZERZONY) {
                    $od = $dane + strlen(self::NAGLOWEK_XMP_ROZSZERZONY) + self::NAGLOWEK_XMP_ROZSZERZONY_RESZTA;
                }

                if ($od !== null && $od < $nastepny) {
                    $bajty = self::spacje($bajty, $od, $nastepny - $od);
                }
            }

            $poz = $nastepny;
        }

        return $bajty;
    }

    /**
     * PNG: chunki do `IEND`, tak jak w `blokWPng()`. Chunk tekstowy z XMP albo
     * surowym profilem staje się `tEXt` tej samej długości: słowo kluczowe,
     * bajt zerowy i same spacje — z nową sumą CRC.
     */
    private static function usunXmpZPng(string $bajty): string
    {
        $poz = strlen(self::SYGNATURA_PNG);
        $koniec = strlen($bajty);

        while ($poz + 12 <= $koniec) {
            $dlugosc = self::dlugoscBe($bajty, $poz);

            if ($dlugosc === null) {
                return $bajty;
            }

            $typ = substr($bajty, $poz + 4, 4);
            $nastepny = $poz + 12 + $dlugosc;

            if ($nastepny <= $poz || $nastepny > $koniec) {
                return $bajty;
            }

            if ($typ === 'IEND') {
                return $bajty;
            }

            if (in_array($typ, ['iTXt', 'zTXt', 'tEXt'], true)) {
                $dane = substr($bajty, $poz + 8, $dlugosc);
                $zero = strpos($dane, "\x00");
                $slowo = $zero === false ? '' : substr($dane, 0, $zero);

                if ($zero !== false
                    && ($slowo === self::SLOWO_XMP_PNG || str_starts_with($slowo, self::SLOWO_SUROWY_PROFIL_PNG))) {
                    $noweDane = $slowo."\x00".str_repeat(' ', $dlugosc - $zero - 1);
                    $chunk = 'tEXt'.$noweDane;

                    $bajty = substr_replace($bajty, $chunk.pack('N', crc32($chunk)), $poz + 4, 4 + $dlugosc + 4);
                }
            }

            $poz = $nastepny;
        }

        return $bajty;
    }

    /** WebP: chunki RIFF, tak jak w `blokWWebp()`; `XMP ` dostaje spacje. */
    private static function usunXmpZWebp(string $bajty): string
    {
        $poz = 12;
        $koniec = strlen($bajty);

        while ($poz + 8 <= $koniec) {
            $typ = substr($bajty, $poz, 4);
            $dlugosc = self::dlugoscLe($bajty, $poz + 4);

            if ($dlugosc === null) {
                return $bajty;
            }

            $nastepny = $poz + 8 + $dlugosc + ($dlugosc % 2);

            if ($nastepny <= $poz || $nastepny > $koniec) {
                return $bajty;
            }

            if ($typ === 'XMP ') {
                $bajty = self::spacje($bajty, $poz + 8, $dlugosc);
            }

            $poz = $nastepny;
        }

        return $bajty;
    }

    /**
     * AVIF i wszystko, czego nie rozpoznajemy: pakiety XMP szukane w bajtach.
     * Najpierw ramka `<?xpacket begin … <?xpacket end…?>`, potem — dla
     * pakietów bez niej — sam element `xmpmeta` z dowolnym prefiksem.
     */
    private static function usunPakietyXmp(string $bajty): string
    {
        // `strpos`, a nie `preg_replace` z `.*?`: na kilkunastu megabajtach
        // AVIF-a PCRE potrafi przekroczyć limit cofania i oddać `null` —
        // a rzutowane na tekst dałoby PUSTY oryginał zamiast zdjęcia.
        $od = 0;

        while (($poczatek = strpos($bajty, '<?xpacket begin', $od)) !== false) {
            $znacznikKonca = strpos($bajty, '<?xpacket end', $poczatek);
            $koniec = $znacznikKonca === false ? false : strpos($bajty, '?>', $znacznikKonca);

            if ($koniec === false) {
                break;
            }

            $bajty = self::spacje($bajty, $poczatek, $koniec + 2 - $poczatek);
            $od = $koniec + 2;
        }

        // Pakiet bez ramki `xpacket` — sam element `xmpmeta`, z dowolnym
        // prefiksem przestrzeni nazw (w praktyce prawie zawsze `x:`).
        $od = 0;

        while (preg_match('/<([A-Za-z_][\w.-]*:)?xmpmeta\b/', $bajty, $m, PREG_OFFSET_CAPTURE, $od) === 1) {
            $poczatek = (int) $m[0][1];
            $zamkniecie = strpos($bajty, '</'.($m[1][0] ?? '').'xmpmeta', $poczatek);
            $koniec = $zamkniecie === false ? false : strpos($bajty, '>', $zamkniecie);

            if ($koniec === false) {
                break;
            }

            $bajty = self::spacje($bajty, $poczatek, $koniec + 1 - $poczatek);
            $od = $koniec + 1;
        }

        return $bajty;
    }

    private static function spacje(string $bajty, int $od, int $ile): string
    {
        return $ile <= 0 ? $bajty : substr_replace($bajty, str_repeat(' ', $ile), $od, $ile);
    }

    private static function usunGpsZExif(string $bajty): string
    {
        $blok = self::znajdzBlokTiff($bajty);

        if ($blok === null) {
            return self::kontenerBezStruktury($bajty)
                ? self::usunGpsZKandydatowTiff($bajty)
                : $bajty;
        }

        [$tiff, $chunkPng] = $blok;

        return self::usunGpsZTiff($bajty, $tiff, $chunkPng);
    }

    /** AVIF, HEIF i wszystko, czego nie rozpoznajemy po sygnaturze. */
    private static function kontenerBezStruktury(string $bajty): bool
    {
        return ! str_starts_with($bajty, self::SYGNATURA_PNG)
            && ! (str_starts_with($bajty, 'RIFF') && substr($bajty, 8, 4) === 'WEBP')
            && ! str_starts_with($bajty, "\xFF\xD8");
    }

    /**
     * AVIF BEZ PREFIKSU `Exif\0\0` (audyt B5, znalezisko 5).
     *
     * Element `Exif` w HEIF/AVIF zaczyna się od czterobajtowego przesunięcia
     * do nagłówka TIFF, a prefiks `Exif\0\0` jest w nim opcjonalny — spec
     * go dopuszcza, ale nie wymaga. Bez parsera ISOBMFF (`iinf`/`iloc`) jedyne,
     * co mamy, to nagłówek TIFF: `II*\0` albo `MM\0*`. Każde trafienie
     * przechodzi przez te same bramki co blok z JPEG-a (magiczne 42, poprawny
     * IFD0, wskaźnik GPS, IFD w granicach pliku), więc przypadkowe bajty
     * obrazu bez całej tej struktury zostają nietknięte.
     */
    private static function usunGpsZKandydatowTiff(string $bajty): string
    {
        foreach (["II\x2A\x00", "MM\x00\x2A"] as $naglowek) {
            $od = 0;

            for ($i = 0; $i < self::LIMIT_OBRAZOW; $i++) {
                $poz = strpos($bajty, $naglowek, $od);

                if ($poz === false) {
                    break;
                }

                $bajty = self::usunGpsZTiff($bajty, $poz, null);
                $od = $poz + 4;
            }
        }

        return $bajty;
    }

    private static function usunGpsZTiff(string $bajty, int $tiff, ?int $chunkPng): string
    {

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

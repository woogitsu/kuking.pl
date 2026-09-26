<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Exceptions\BladDlaCzlowieka;

/**
 * Import z adresu strony albo z pliku PDF nie może pójść dalej — z powodu,
 * który znamy i umiemy nazwać człowiekowi (D-300).
 *
 * KOD JEST ZAMKNIĘTĄ LISTĄ, NIE TREŚCIĄ
 *
 * Kod trafia do bazy i do testów; zdanie dla człowieka jest wyliczane z kodu
 * w jednym miejscu (`KOMUNIKATY`). Dzięki temu zmiana słowa w komunikacie
 * nie zmienia danych, a nowy powód odmowy nie wejdzie do kodu bez zdania,
 * które mówi, CO ZROBIĆ (AGENTS.md §5, `docs/UX_50_PLUS.md` § Błędy).
 *
 * Dziedziczy po `BladDlaCzlowieka`, bo jego treść JEST napisana dla
 * człowieka i wolno ją pokazać na ekranie. Adres strony, adres IP po
 * rozwiązaniu nazwy ani treść odpowiedzi cudzego serwera NIE trafiają do
 * komunikatu — to byłaby informacja o sieci wewnętrznej dla kogoś, kto
 * próbuje ją zmapować.
 */
final class ImportOdrzucony extends BladDlaCzlowieka
{
    public const ADRES_NIEPRAWIDLOWY = 'adres_nieprawidlowy';

    public const ADRES_NIEPUBLICZNY = 'adres_niepubliczny';

    public const ROBOTS_ZABRANIA = 'robots_zabrania';

    public const STRONA_NIEDOSTEPNA = 'strona_niedostepna';

    public const ZA_DUZO_PRZEKIEROWAN = 'za_duzo_przekierowan';

    public const ZA_DUZA_STRONA = 'za_duza_strona';

    public const ZA_DLUGO = 'za_dlugo';

    public const NIE_STRONA = 'nie_strona';

    public const BRAK_PRZEPISU = 'brak_przepisu';

    public const PDF_ZA_DUZY = 'pdf_za_duzy';

    public const PDF_ZA_DUZO_STRON = 'pdf_za_duzo_stron';

    public const PDF_USZKODZONY = 'pdf_uszkodzony';

    public const PDF_ZASZYFROWANY = 'pdf_zaszyfrowany';

    public const PDF_BEZ_TEKSTU = 'pdf_bez_tekstu';

    public const NARZEDZIE_PDF_NIEDOSTEPNE = 'narzedzie_pdf_niedostepne';

    public const LIMIT_OSOBY = 'limit_osoby';

    /**
     * @var array<string, string>
     */
    public const KOMUNIKATY = [
        self::ADRES_NIEPRAWIDLOWY => 'To nie wygląda na adres strony. Skopiuj go z paska adresu przeglądarki — '
            .'powinien zaczynać się od https://.',
        self::ADRES_NIEPUBLICZNY => 'Ten adres nie prowadzi do publicznej strony. Sprawdź, czy zaczyna się od https:// '
            .'i czy otwiera się w przeglądarce.',
        self::ROBOTS_ZABRANIA => 'Ta strona nie pozwala na pobieranie przepisów przez inne serwisy. Skopiuj tekst przepisu '
            .'ze strony i wklej go w polu „Przygotowanie” — adres zapisaliśmy jako źródło.',
        self::STRONA_NIEDOSTEPNA => 'Z tej strony nie da się teraz pobrać przepisu. Sprawdź adres w przeglądarce '
            .'albo skopiuj tekst przepisu i wklej go w polu „Przygotowanie”.',
        self::ZA_DUZO_PRZEKIEROWAN => 'Ten adres przekierowuje zbyt wiele razy. Otwórz stronę w przeglądarce '
            .'i skopiuj adres, który pokaże się na końcu.',
        self::ZA_DUZA_STRONA => 'Ta strona jest za duża, żeby ją odczytać. Skopiuj tekst przepisu ze strony '
            .'i wklej go w polu „Przygotowanie”.',
        self::ZA_DLUGO => 'Strona odpowiadała zbyt długo. Spróbuj jeszcze raz za kilka minut albo skopiuj '
            .'tekst przepisu i wklej go sam.',
        self::NIE_STRONA => 'Pod tym adresem nie ma zwykłej strony z tekstem. Jeśli to plik PDF, '
            .'pobierz go i dodaj przyciskiem „Dodaj plik PDF”.',
        self::BRAK_PRZEPISU => 'Na tej stronie nie znaleźliśmy przepisu. Skopiuj tekst przepisu ze strony '
            .'i wklej go w polu „Przygotowanie” — adres zapisaliśmy jako źródło.',
        self::PDF_ZA_DUZY => 'Ten plik PDF jest za duży. Wybierz plik mniejszy niż :mb MB '
            .'albo zapisz z niego tylko strony z przepisem.',
        self::PDF_ZA_DUZO_STRON => 'Ten plik PDF ma za dużo stron. Zapisz z niego tylko strony z przepisem '
            .'(najwyżej :strony) i dodaj go jeszcze raz.',
        self::PDF_USZKODZONY => 'Nie umiemy otworzyć tego pliku. Sprawdź, czy to na pewno PDF i czy otwiera się '
            .'na Twoim komputerze — jeśli tak, zapisz go jeszcze raz i spróbuj ponownie.',
        self::PDF_ZASZYFROWANY => 'Ten plik PDF jest zabezpieczony hasłem. Otwórz go u siebie, zapisz kopię '
            .'bez hasła i dodaj ją jeszcze raz.',
        self::PDF_BEZ_TEKSTU => 'Ten plik PDF to zeskanowane strony, bez tekstu do odczytania. Zrób zdjęcie '
            .'strony z przepisem i dodaj je przyciskiem „Przepisz z kartki lub zeszytu” albo przepisz przepis sam.',
        self::NARZEDZIE_PDF_NIEDOSTEPNE => 'Odczyt plików PDF chwilowo nie działa. Spróbuj później '
            .'albo przepisz przepis sam — nic nie zginęło.',
        self::LIMIT_OSOBY => 'Dziś odczytaliśmy już :limit Twoich przepisów — to dzienny limit. Jutro będzie można '
            .'dalej. Możesz też od razu wpisać przepis sam.',
    ];

    /**
     * @param  array<string, string|int>  $zamiany  wartości do komunikatu (`:mb`, `:strony`, `:limit`)
     */
    public function __construct(public readonly string $kod, array $zamiany = [])
    {
        $komunikat = self::KOMUNIKATY[$kod] ?? self::KOMUNIKATY[self::STRONA_NIEDOSTEPNA];

        foreach ($zamiany as $klucz => $wartosc) {
            $komunikat = str_replace(':'.$klucz, (string) $wartosc, $komunikat);
        }

        parent::__construct($komunikat);
    }
}

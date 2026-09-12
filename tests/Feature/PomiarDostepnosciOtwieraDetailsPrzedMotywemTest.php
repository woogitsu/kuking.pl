<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Automat dostępności otwiera `<details>` PRZED zmianą motywu i skali tekstu,
 * a nie tuż przed `analyze()`.
 *
 * SKĄD TEN TEST (issue #454). Automat meldował raz na kilkaset przebiegów
 * „[serious] color-contrast — label[for=\"f-password\"]" na ekranie usuwania
 * konta, przy nietkniętej palecie. Zmierzone liczby z takiego przebiegu
 * (wariant „ciemny", 1280 px):
 *
 *     tekst  #2b241d   ← `--color-ink` z motywu JASNEGO
 *     tło    #1e1a16   ← `--color-surface` z motywu CIEMNEGO
 *     kontrast 1.13:1  przy wymaganym 4.5:1
 *
 * Ta para nie występuje w żadnym motywie: pomiar zestawiał tekst sprzed
 * przełączenia z tłem po przełączeniu. Zamknięty `<details>` jest w Chromium
 * poddrzewem pominiętym w przeliczaniu stylu — zmiana `data-theme` na
 * `<html>` go nie dotyka, a `getComputedStyle` nie wymusza przeliczenia.
 * Dopóki otwarcie stało tuż przed `analyze()`, axe potrafił przeczytać
 * z tego poddrzewa kolory sprzed przełączenia.
 *
 * ZMIERZONE, nie wywnioskowane — odczyt w tym samym zadaniu co otwarcie:
 *
 *   | kolejność                                   | rozjazd pary tekst/tło |
 *   |---------------------------------------------|------------------------|
 *   | motyw, potem `<details>` (stan sprzed #454)  | 100 na 100             |
 *   | `<details>`, potem motyw (stan po #454)      |   0 na 100             |
 *
 * To jest ta sama choroba co D-099 (skala tekstu włączana po wczytaniu
 * strony) i co dwa wcześniejsze migotania kontrastu opisane w samym
 * `scripts/dostepnosc.mjs`: automat mierzy stan PRZEJŚCIOWY i melduje
 * naruszenie, którego w produkcie nie ma. Fałszywy alarm z wagą „blokujące"
 * jest gorszy niż brak sprawdzenia, bo uczy ludzi ignorować czerwień.
 *
 * Powrót kolejności psuje pomiar PO CICHU: objawia się raz na kilkaset
 * przebiegów i tylko na obciążonej maszynie. Nic poza tym testem tego nie
 * łapie.
 *
 * @see PomiarDostepnosciKonczyKodemJedenTest
 */
class PomiarDostepnosciOtwieraDetailsPrzedMotywemTest extends TestCase
{
    private function zrodlo(): string
    {
        $sciezka = base_path('scripts/dostepnosc.mjs');

        $this->assertFileExists($sciezka, 'Nie ma automatu dostępności — ten test pilnowałby pustki.');

        $zrodlo = (string) file_get_contents($sciezka);

        // Próg długości: plik przeniesiony albo opróżniony spełniłby każde
        // „nie zawiera" i nie spełniłby niczego innego — pułapka 2
        // w `docs/PULAPKI_TESTOW.md`.
        $this->assertGreaterThan(
            50000,
            strlen($zrodlo),
            'Automat dostępności jest podejrzanie krótki — test sprawdzałby pustkę.',
        );

        return $zrodlo;
    }

    /** Pozycja jedynego wystąpienia igły; oblewa, gdy jest ich zero albo więcej niż jedno. */
    private function jedynaPozycja(string $zrodlo, string $igla, string $po_co): int
    {
        $ile = substr_count($zrodlo, $igla);

        $this->assertSame(
            1,
            $ile,
            "W automacie dostępności `{$igla}` występuje {$ile} razy, a ma raz. {$po_co} "
            .'Przy kilku wystąpieniach ten test porównywałby kolejność nie tych miejsc, '
            .'o które chodzi — czyli mierzyłby nie to (pułapka 3b w docs/PULAPKI_TESTOW.md).',
        );

        return (int) strpos($zrodlo, $igla);
    }

    public function test_details_otwiera_sie_przed_motywem_i_skala_tekstu(): void
    {
        $zrodlo = $this->zrodlo();

        $details = $this->jedynaPozycja(
            $zrodlo,
            'details:not([open])',
            'To jedyne miejsce, w którym automat rozwija zwinięte sekcje.',
        );
        $motyw = $this->jedynaPozycja(
            $zrodlo,
            "setAttribute('data-theme', 'dark')",
            'To jedyne miejsce, w którym automat włącza motyw ciemny.',
        );
        $axe = $this->jedynaPozycja(
            $zrodlo,
            'new AxeBuilder(',
            'To jedyne miejsce, w którym automat uruchamia analizę axe.',
        );

        $skala = strpos($zrodlo, 'await wlaczSkaleTekstu(');

        $this->assertNotFalse($skala, 'Zniknęło włączanie naszej skali tekstu.');

        $this->assertLessThan(
            $motyw,
            $details,
            'Automat otwiera `<details>` PO włączeniu motywu ciemnego. Zamknięty `<details>` '
            .'jest poddrzewem pominiętym w przeliczaniu stylu, więc axe czyta z niego kolory '
            .'sprzed przełączenia — zmierzone #2b241d (tekst z motywu jasnego) na #1e1a16 '
            .'(tło z ciemnego), kontrast 1.13:1 przy wymaganym 4.5:1. Naruszenia w palecie '
            .'nie ma; jest w kolejności (#454).',
        );

        $this->assertLessThan(
            $skala,
            $details,
            'Automat otwiera `<details>` PO włączeniu skali tekstu. Nieaktualna wielkość '
            .'pisma w tym poddrzewie przestawia próg kontrastu z 4.5:1 na 3:1 — a to jest '
            .'gorszy kierunek niż fałszywy alarm, bo CICHO PRZEPUSZCZA naruszenie (#454).',
        );

        $this->assertLessThan(
            $axe,
            $details,
            'Automat uruchamia axe, nie otwierając wcześniej `<details>`. Wtedy najważniejszy '
            .'nieodwracalny formularz serwisu (usunięcie konta, D-022) nie jest mierzony '
            .'wcale, a raport świeci na zielono.',
        );
    }

    public function test_po_otwarciu_details_automat_czeka_na_przeliczenie(): void
    {
        $zrodlo = $this->zrodlo();

        $details = $this->jedynaPozycja(
            $zrodlo,
            'details:not([open])',
            'To jedyne miejsce, w którym automat rozwija zwinięte sekcje.',
        );

        /*
         * OKNO O STAŁEJ DŁUGOŚCI, A NIE „DO NASTĘPNEJ KOTWICY".
         *
         * Pierwsza wersja tego testu brała wycinek od otwarcia `<details>`
         * do `wlaczSkaleTekstu`. Przy sabotażu przenoszącym otwarcie z
         * powrotem na koniec pętli ta druga kotwica stoi WCZEŚNIEJ, długość
         * wychodzi ujemna, a `substr` zwraca wtedy kawałek liczony od końca
         * pliku — i test przechodził, mierząc nie to miejsce (pułapka 3b
         * w `docs/PULAPKI_TESTOW.md`). Okno liczone w przód od samego
         * otwarcia nie ma jak się odwrócić.
         *
         * 400 znaków to dokładnie tyle, żeby zmieścić blok otwierający i
         * czekanie zaraz po nim, a za mało, żeby złapać jakikolwiek inny
         * `requestAnimationFrame` w tym pliku.
         */
        $miedzy = substr($zrodlo, $details, 400);

        /*
         * Dwa zagnieżdżone `requestAnimationFrame`: pierwszy kończy
         * przeliczanie stylu odsłoniętego poddrzewa, drugi daje pewność, że
         * przemalowanie już się odbyło. Czekanie na zegar („sleep 100") byłoby
         * zakładem o szybkość maszyny — dokładnie tym błędem, przez który
         * ta usterka objawiała się raz na kilkaset przebiegów.
         */
        $this->assertStringContainsString(
            'requestAnimationFrame(() => requestAnimationFrame(',
            $miedzy,
            'Po otwarciu `<details>` automat nie czeka na pełny obieg klatki. Odsłonięte '
            .'poddrzewo przelicza się dopiero przy pierwszym obiegu po otwarciu — bez tego '
            .'pomiar wielkości pisma i kolorów w rozwiniętych sekcjach jest zakładem '
            .'o to, czy klatka zdążyła wejść (#454).',
        );
    }
}

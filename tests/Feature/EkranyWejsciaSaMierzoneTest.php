<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Ekrany, na których człowiek WCHODZI kontem Google albo Facebooka, są
 * mierzone przez `scripts/dostepnosc.mjs` — i są mierzone W TYM STANIE,
 * w którym te przyciski w ogóle istnieją (issue #345).
 *
 * PO CO TO JEST — DWA RÓŻNE MILCZĄCE POMINIĘCIA, NIE JEDNO.
 *
 * 1. `/ustawienia/bezpieczenstwo` nie był mierzony ANI RAZU. To ten ekran,
 *    na którym stoi „Połącz konto Facebooka", czyli jedyna bezpieczna droga
 *    powiązania istniejącego konta z Facebookiem (D-098). Nie wypisał go
 *    `PomiarDostepnosciObejmujeStronyPubliczneTest`, bo tamten skan z założenia
 *    pomija trasy za `auth` — a ekranu nie było też w `EKRANY_UKLADU`.
 *    Brak ekranu na liście niczego nie psuje: raport wygląda na kompletny
 *    i świeci na zielono.
 *
 * 2. `/login` i `/register` BYŁY na liście — ale mierzone w wariancie BEZ
 *    rzędu „Wejdź kontem Google / Facebooka". Cały ten blok renderuje się
 *    pod warunkiem `App\Support\Google::dziala()`, a `.env` deweloperski
 *    i job `dostepnosc` w CI mają klucze dostawców puste. Zmierzone
 *    12 września przy 320 px: `/login` z kluczami to 40 węzłów i 2 znaki
 *    marki w `<main>`, bez kluczy — 25 węzłów i ZERO znaków marki. Axe nie
 *    widział tych przycisków nigdy, na żadnym ekranie. To ta sama fałszywa
 *    zieleń co wariant „poczta nie działa" (D-106), tyle że na ekranie,
 *    na który człowiek trafia pierwszy.
 *
 * CZEGO TEN TEST NIE PILNUJE. Czterech ekranów ZA zgodą dostawcy
 * (`/wejdz/google/domknij`, `/wejdz/google/polacz` i odpowiedniki Facebooka).
 * Te dalej nie są mierzone i dalej są wypisane jako dług nazwany w stałej
 * `WYJATKI` w `PomiarDostepnosciObejmujeStronyPubliczneTest` — czytają z sesji
 * tożsamość, którą zakłada wyłącznie `callback()` po wymianie kodu
 * u dostawcy, a adres tej wymiany jest stałą w kodzie i nie da się go wskazać
 * konfiguracją. Atrapa klucza otwiera przycisk; tamto wymaga atrapy CAŁEGO
 * DOSTAWCY, czyli osobnej pracy (issue #345).
 *
 * @see PomiarDostepnosciObejmujeStronyPubliczneTest — strażnik kompletności
 *      listy dla stron publicznych; ten plik dokłada ekrany wejścia
 *      zewnętrznego, których tamten z założenia nie widzi
 */
class EkranyWejsciaSaMierzoneTest extends TestCase
{
    /** Źródło automatu dostępności — czytane raz, sprawdzane z kilku stron. */
    private function zrodloAutomatu(): string
    {
        $sciezka = base_path('scripts/dostepnosc.mjs');

        $this->assertFileExists($sciezka, 'Nie ma automatu dostępności — ten test pilnowałby pustki.');

        return (string) file_get_contents($sciezka);
    }

    /**
     * Adresy wymienione w `EKRANY` w automacie dostępności.
     *
     * @return list<string>
     */
    private function mierzoneAdresy(): array
    {
        $zrodlo = $this->zrodloAutomatu();

        $poczatek = strpos($zrodlo, 'const EKRANY = [');
        $this->assertNotFalse($poczatek, 'W automacie nie ma już listy `EKRANY`.');

        $koniec = strpos($zrodlo, "\n];", $poczatek);
        $this->assertNotFalse($koniec);

        $lista = substr($zrodlo, $poczatek, $koniec - $poczatek);

        preg_match_all("~adres:\s*(?:'([^']*)'|`([^`]*)`)~", $lista, $trafienia);

        $adresy = array_values(array_filter(array_map(
            static fn (string $a, string $b): string => $a !== '' ? $a : $b,
            $trafienia[1],
            $trafienia[2],
        )));

        /*
         * PRÓG LICZBY ZNALEZIONYCH ADRESÓW — kontrola dodatnia dla samego
         * czytania pliku (`docs/PULAPKI_TESTOW.md`, pułapka 2). Bez niego
         * zmiana nazwy stałej albo kształtu wpisów daje zero trafień,
         * a zero trafień byłoby dla tego testu sukcesem.
         */
        $this->assertGreaterThan(
            15,
            count($adresy),
            'Z listy `EKRANY` wyszło podejrzanie mało adresów — zmienił się kształt pliku, '
            .'a test przestał cokolwiek mierzyć.',
        );

        return $adresy;
    }

    public function test_ekran_bezpieczenstwa_konta_jest_mierzony(): void
    {
        $this->assertContains(
            '/ustawienia/bezpieczenstwo',
            $this->mierzoneAdresy(),
            'Ekran „Bezpieczeństwo" (`/ustawienia/bezpieczenstwo`) wypadł z listy `EKRANY` '
            .'w automacie dostępności. To jedyne miejsce z przyciskiem „Połącz konto '
            .'Facebooka" (D-098) i rzędami „coś jest włączone" z przyciskiem obok tekstu — '
            .'czyli układ, który przy 320 px i tekście 140% najłatwiej wypycha stronę w bok. '
            .'Stoi za `auth`, więc `PomiarDostepnosciObejmujeStronyPubliczneTest` go nie widzi '
            .'i nikt inny tego nie zauważy.',
        );
    }

    public function test_logowanie_i_rejestracja_sa_mierzone(): void
    {
        $mierzone = $this->mierzoneAdresy();

        foreach (['/login', '/register'] as $adres) {
            $this->assertContains(
                $adres,
                $mierzone,
                "Ekran `{$adres}` wypadł z listy `EKRANY` w automacie dostępności — a to na nim "
                .'stoi rząd „Wejdź kontem Google / Facebooka".',
            );
        }
    }

    public function test_automat_stawia_serwer_z_kluczami_dostawcow(): void
    {
        $zrodlo = $this->zrodloAutomatu();

        /*
         * Sama stała nie wystarcza. Zadeklarowana i NIEUŻYTA wygląda
         * w przeglądzie kodu dokładnie tak samo jak używana, a serwer wstaje
         * wtedy bez kluczy i mierzy `/login` bez przycisków — czyli usterkę
         * nie do odróżnienia od poprawnego wyniku. Dlatego sprawdzamy jedno
         * i drugie: że stała jest KOMPLETNA i że wchodzi do środowiska
         * procesu serwera.
         */
        $this->assertStringContainsString(
            'const KLUCZE_DOSTAWCOW_DO_POMIARU = {',
            $zrodlo,
            'Automat nie ma już atrap kluczy dostawców tożsamości. Bez nich '
            .'`App\Support\Google::dziala()` odpowiada „nie", rząd „Wejdź kontem Google / '
            .'Facebooka" nie renderuje się wcale, a axe mierzy `/login` bez przycisków, '
            .'którymi wchodzi większość ludzi.',
        );

        foreach (['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'FACEBOOK_CLIENT_ID', 'FACEBOOK_CLIENT_SECRET'] as $zmienna) {
            $this->assertStringContainsString(
                $zmienna,
                $zrodlo,
                "Automat nie ustawia już `{$zmienna}` dla mierzonego serwera. Brak jednego "
                .'klucza z pary wystarczy, żeby `skonfigurowany()` odpowiedziało „nie" — '
                .'i żeby przycisk tego dostawcy zniknął z ekranu bez śladu w raporcie.',
            );
        }

        $this->assertStringContainsString(
            '...KLUCZE_DOSTAWCOW_DO_POMIARU,',
            $zrodlo,
            'Atrapy kluczy są zadeklarowane, ale nie wchodzą do środowiska procesu '
            .'`php artisan serve`. Serwer wstanie bez nich, a raport i tak pokaże ✓ nad '
            .'ekranami bez przycisków wejścia zewnętrznego.',
        );
    }

    public function test_automat_sprawdza_czy_przyciski_wejscia_naprawde_wyszly(): void
    {
        $zrodlo = $this->zrodloAutomatu();

        $this->assertStringContainsString(
            'async function przeszkodaWWejsciachZewnetrznych(',
            $zrodlo,
            'Zniknęła kontrola, że rząd „Wejdź kontem Google / Facebooka" naprawdę się '
            .'wyrenderował. Same klucze to za mało: wyłącznik `KUKING_WEJSCIE_GOOGLE`, '
            .'zapamiętana konfiguracja (`config:cache`) albo zmiana w `dziala()` po cichu '
            .'wracają do wariantu bez przycisków.',
        );

        /*
         * I że jest WOŁANA, a niepowodzenie kończy przebieg. Funkcja
         * napisana i nigdy nie wywołana to ten sam rodzaj cichej usterki
         * co nieużyta stała wyżej.
         */
        $poczatek = strpos($zrodlo, 'await przeszkodaWWejsciachZewnetrznych(adres)');

        $this->assertNotFalse(
            $poczatek,
            'Kontrola rzędu wejść zewnętrznych istnieje, ale nikt jej nie woła — '
            .'a wtedy nie sprawdza niczego.',
        );

        $this->assertStringContainsString(
            'process.exit(1)',
            substr($zrodlo, $poczatek, 400),
            'Kontrola rzędu wejść zewnętrznych jest wołana, ale jej niepowodzenie nie kończy '
            .'przebiegu. Pomiar w nieznanym stanie jest gorszy niż jego brak — automat ma '
            .'wtedy stanąć i powiedzieć dlaczego, a nie mierzyć dalej.',
        );
    }

    /**
     * Napisy, których szuka kontrola wyżej, muszą być TYMI SAMYMI, które
     * naprawdę stoją na przyciskach.
     *
     * Bez tego kontrola sprawdza napis, którego w serwisie już nie ma:
     * zmiana treści przycisku przewracałaby wtedy cały pomiar dostępności
     * z komunikatem o „braku przycisków", które są na ekranie — albo,
     * po dopisaniu napisu do kontroli w drugą stronę, przepuszczała ekran
     * bez nich. Wiąże więc te dwa pliki jednym sprawdzeniem.
     */
    public function test_napisy_przyciskow_sa_te_same_w_widoku_i_w_automacie(): void
    {
        $widok = (string) file_get_contents(
            base_path('resources/views/components/wejscia-zewnetrzne.blade.php'),
        );
        $zrodlo = $this->zrodloAutomatu();

        foreach (['Wejdź kontem Google', 'Wejdź kontem Facebooka'] as $napis) {
            $this->assertStringContainsString(
                $napis,
                $widok,
                "Napis „{$napis}” nie stoi już w `components/wejscia-zewnetrzne.blade.php`, "
                .'a automat dostępności dalej go szuka — pomiar przewróci się na ekranie, '
                .'który jest w porządku.',
            );

            $this->assertStringContainsString(
                $napis,
                $zrodlo,
                "Automat dostępności nie szuka już napisu „{$napis}” — kontrola przepuści "
                .'ekran logowania bez przycisku tego dostawcy.',
            );
        }
    }
}

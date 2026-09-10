<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Każda kontrola automatu dostępności jest OBECNA WE WSZYSTKICH TRZECH
 * miejscach zbiorczych `scripts/dostepnosc.mjs`: w linii podsumowania,
 * w warunku wyjścia kodem 1 i w artefakcie `storage/dostepnosc.json`.
 *
 * SKĄD TEN TEST. Wieczorem 10 września trzy niezależne gałęzie dopisywały
 * kontrole do tego samego pliku. Każda strona konfliktu wyglądała kompletnie
 * i przechodziła `node --check`, więc rozwiązanie „weź jedną stronę" wyglądało
 * na poprawne — a dawało skrypt, który **kończy się zerem MIMO NARUSZENIA**:
 * licznik dalej rósł, tylko nikt go już nie pytał. Nic tego nie łapało, bo
 * jedyną rzeczą, która by to pokazała, jest przebieg z prawdziwą usterką.
 *
 * Ta sama choroba miała już w tym repozytorium dwie odsłony
 * (`docs/PULAPKI_TESTOW.md`, pułapka 5): narzędzie melduje sukces, nie robiąc
 * nic. Zniknięcie kontroli z warunku wyjścia jest jej najczystszym
 * przypadkiem — automat chodzi, mierzy, drukuje liczbę i kończy zerem.
 *
 * DLACZEGO LISTA JEST TU WPISANA Z RĘKI, A NIE CZYTANA Z PLIKU. Bo kontrola
 * wyprowadzona z badanego pliku znika razem z nim: gdyby ten test wyciągał
 * nazwy liczników ze źródła, usunięcie kontroli ze WSZYSTKICH trzech miejsc
 * naraz przeszłoby na zielono. Lista poniżej jest niezależnym zapisem tego,
 * co ten automat ma pilnować — i dopisanie do niej nowej kontroli jest
 * świadomym krokiem, tak samo jak jej usunięcie.
 *
 * @see PomiarDostepnosciObejmujeStronyPubliczneTest
 */
class PomiarDostepnosciKonczyKodemJedenTest extends TestCase
{
    /**
     * Liczniki, których niezerowa wartość MUSI zatrzymać CI, wraz z nazwą
     * klucza, pod którym stoją w artefakcie JSON.
     *
     * @var array<string, string>
     */
    private const KONTROLE = [
        // licznik w kodzie => klucz sekcji w storage/dostepnosc.json
        'blokujacych' => 'blokujacych',
        'przepelnienia' => 'przepelnien',
        'rozjazdyBelki' => 'rozjazdow',
        'niespojneSzerokosci' => 'niespojnychSzerokosci',
        'naruszeniaFocus' => 'naruszen',
        'rozjazdyTablicy' => 'rozjazdow',
        'rozjazdyLiczb' => 'rozjazdow',
    ];

    private function zrodlo(): string
    {
        $sciezka = base_path('scripts/dostepnosc.mjs');

        $this->assertFileExists($sciezka, 'Nie ma automatu dostępności — ten test pilnowałby pustki.');

        $zrodlo = (string) file_get_contents($sciezka);

        // Próg długości: plik przeniesiony albo opróżniony spełniłby
        // „nie zawiera" i nie spełniłby niczego innego — patrz pułapka 2
        // w `docs/PULAPKI_TESTOW.md`.
        $this->assertGreaterThan(
            50000,
            strlen($zrodlo),
            'Automat dostępności jest podejrzanie krótki — test sprawdzałby pustkę.',
        );

        return $zrodlo;
    }

    /** Warunek `if (...) { process.exit(1); }` na końcu pliku. */
    private function warunekWyjscia(): string
    {
        $zrodlo = $this->zrodlo();

        $poczatek = strrpos($zrodlo, "\nif (\n");

        $this->assertNotFalse(
            $poczatek,
            'W automacie nie ma już wieloliniowego warunku wyjścia kodem 1. '
            .'Jeśli zmienił kształt, popraw ten test — ale NIE usuwaj go: to jedyna '
            .'rzecz pilnująca, że mierzone usterki naprawdę zatrzymują CI.',
        );

        $koniec = strpos($zrodlo, 'process.exit(1);', $poczatek);

        $this->assertNotFalse($koniec, 'Warunek wyjścia nie kończy się już `process.exit(1)`.');

        return substr($zrodlo, $poczatek, $koniec - $poczatek);
    }

    public function test_kazda_kontrola_zatrzymuje_ci_kodem_jeden(): void
    {
        $warunek = $this->warunekWyjscia();

        foreach (array_keys(self::KONTROLE) as $licznik) {
            $this->assertStringContainsString(
                $licznik,
                $warunek,
                "Kontrola `{$licznik}` wypadła z warunku wyjścia kodem 1. Automat będzie ją "
                .'liczyć i drukować, a przebieg skończy się ZEREM mimo naruszenia — dokładnie '
                .'to zdarzyło się 10 września przy scalaniu trzech gałęzi w tym pliku.',
            );
        }
    }

    public function test_kazda_kontrola_jest_w_linii_podsumowania(): void
    {
        $zrodlo = $this->zrodlo();

        $poczatek = strpos($zrodlo, 'Wynik zapisany: storage/dostepnosc.json');

        $this->assertNotFalse($poczatek, 'Zniknęła linia podsumowania.');

        $koniec = strpos($zrodlo, ');', $poczatek);
        $podsumowanie = substr($zrodlo, $poczatek, (int) $koniec - $poczatek);

        foreach (array_keys(self::KONTROLE) as $licznik) {
            $this->assertStringContainsString(
                $licznik,
                $podsumowanie,
                "Kontrola `{$licznik}` wypadła z linii podsumowania. Przebieg CI pokazuje wtedy "
                .'liczby, wśród których tej jednej po prostu nie ma — a jej brak wygląda '
                .'identycznie jak zero.',
            );
        }
    }

    public function test_kazda_kontrola_jest_w_artefakcie_json(): void
    {
        $zrodlo = $this->zrodlo();

        $poczatek = strpos($zrodlo, "writeFileSync('storage/dostepnosc.json'");

        $this->assertNotFalse($poczatek, 'Automat nie zapisuje już artefaktu.');

        $koniec = strpos($zrodlo, '}, null, 2));', $poczatek);

        $this->assertNotFalse($koniec);

        $artefakt = substr($zrodlo, $poczatek, $koniec - $poczatek);

        foreach (self::KONTROLE as $licznik => $klucz) {
            $this->assertStringContainsString(
                $licznik,
                $artefakt,
                "Kontrola `{$licznik}` nie trafia do `storage/dostepnosc.json`. Artefakt jest "
                .'jedynym miejscem, z którego da się odczytać PRZYCZYNĘ po skończonym '
                .'przebiegu — kontrola widoczna tylko w kodzie wyjścia mówi „coś jest źle” '
                .'i nic więcej. Tak było z pomiarem liczb o osobie (D-091) do 11 września.',
            );

            $this->assertStringContainsString(
                $klucz,
                $artefakt,
                "W artefakcie nie ma klucza `{$klucz}` dla kontroli `{$licznik}`.",
            );
        }
    }

    public function test_skala_tekstu_jest_sprawdzana_po_wlaczeniu(): void
    {
        $zrodlo = $this->zrodlo();

        /*
         * TO JEST USTERKA, KTÓRA TRZYMAŁA `main` NA CZERWONO (D-099), i jedyna
         * rzecz, która nie pozwoli jej wrócić.
         *
         * Produkt wydaje `data-text-scale` na `<html>` po stronie serwera, więc
         * strona człowieka jest ułożona wielkim tekstem od pierwszego ułożenia.
         * Automat dokładał ten atrybut PO wczytaniu strony i zaczynał mierzyć,
         * zanim przeglądarka przeliczyła układ. Strona przy 140% jest o jedną
         * trzecią wyższa — element, który przeglądarka przed chwilą przewinęła
         * nad belkę, zjeżdżał razem z nią pod belkę i automat notował 2.4.11
         * FAIL, którego w produkcie nie ma.
         *
         * Powrót do gołego `setAttribute` w którymkolwiek z trzech pomiarów
         * przywraca ten wyścig — i przywraca go po cichu, bo objawia się on
         * dopiero na obciążonej maszynie i raz na kilka przebiegów.
         */
        $this->assertStringContainsString(
            'async function wlaczSkaleTekstu(',
            $zrodlo,
            'Zniknęła funkcja `wlaczSkaleTekstu`, która czeka na PRZELICZONY układ '
            .'i sprawdza, że skala naprawdę weszła.',
        );

        $this->assertSame(
            3,
            substr_count($zrodlo, 'await wlaczSkaleTekstu('),
            'Nasza skala tekstu ma być włączana przez `wlaczSkaleTekstu` we WSZYSTKICH '
            .'trzech pomiarach, które jej używają (axe, układ, focus not obscured). '
            .'Gołe `setAttribute` mierzy stronę w trakcie przeliczania układu.',
        );

        $this->assertSame(
            1,
            substr_count($zrodlo, "setAttribute('data-text-scale'"),
            'Poza `wlaczSkaleTekstu` nie ma prawa być drugiego miejsca ustawiającego '
            .'`data-text-scale` — ono nie czeka na przeliczenie układu.',
        );
    }
}

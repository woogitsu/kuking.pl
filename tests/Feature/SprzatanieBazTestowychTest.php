<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Sprzątacz baz testowych nie kasuje bazy żywej kopii roboczej (#66, #736).
 *
 * DLACZEGO TEN PLIK ISTNIEJE, CHOĆ TEST JEST W POWŁOCE
 * ----------------------------------------------------
 * Bo `scripts/cleanup-test-dbs.sh` jest skryptem powłoki, a `php artisan test`
 * skryptów powłoki nie widzi — ten sam powód i ten sam kształt, co
 * `ProbaOdtworzeniaTest`. Bez tego pliku dowód leżałby obok przebiegu i nikt
 * by nie zauważył, gdyby przestał przechodzić.
 *
 * DLACZEGO AKURAT TEN SKRYPT DOSTAJE WŁASNY DOWÓD
 * Bo jest jedynym miejscem w repozytorium, które robi `DROP DATABASE` na
 * bazach nienależących do siebie, a od #736/#920 nazwa bazy kopii bez `.git`
 * zawiera SKRÓT ścieżki kopii roboczej — czyli nie da się z niej odczytać,
 * czyja jest, bo skrót jest jednokierunkowy. Sprzątacz
 * opiera się więc na rejestrze i na `pg_stat_activity`, a to są dwa nowe
 * miejsca, w których da się pomylić „śmieć" z „cudzą pracą w toku".
 *
 * CZEGO TEN TEST NIE DOWODZI: że sprzątacz posprząta WSZYSTKO. Nie posprząta
 * i tak ma być — baza bez wpisu w rejestrze zostaje na dysku na zawsze,
 * dopóki człowiek nie skasuje jej sam. To jest cena za to, że nigdy nie
 * skasuje cudzej.
 *
 * @see tests/skrypty/sprzatanie-baz-testowych.sh
 * @see scripts/cleanup-test-dbs.sh
 * @see tests/nazwa-bazy.php
 *
 * @bez-kontroli-dodatniej base_path() wskazuje tylko skrypt dowodu uruchamiany w piaskownicy, a asercje czytają jego wyjście (liczbę i nazwy przypadków), nie treść źródła; pusty albo okrojony dowód zapala asercję na liczbie przypadków.
 */
class SprzatanieBazTestowychTest extends TestCase
{
    #[Test]
    public function test_sprzatacz_baz_przechodzi_wlasne_testy(): void
    {
        $skrypt = base_path('tests/skrypty/sprzatanie-baz-testowych.sh');

        // Bez tej asercji test byłby zielony także wtedy, gdyby plik zniknął —
        // `shell_exec` zwróciłby wtedy komunikat powłoki, a nie wynik testów
        // (pułapka 2 z docs/PULAPKI_TESTOW.md).
        $this->assertFileExists(
            $skrypt,
            'Nie ma tests/skrypty/sprzatanie-baz-testowych.sh — bez niego nic '.
            'nie pilnuje, żeby sprzątacz baz nie skasował bazy żywej kopii.',
        );

        $wyjscie = (string) shell_exec(
            'bash '.escapeshellarg($skrypt).' 2>&1; printf "KOD=%s" "$?"',
        );

        // Kody kolorów ANSI stoją MIĘDZY znakiem „✗" a treścią werdyktu, więc
        // bez ich usunięcia wzorzec niżej nie łapał niczego i asercja na listę
        // oblanych przypadków przechodziła zawsze. Wykryła to kontrola ujemna:
        // po wycięciu bezpiecznika sprzątacza test oblewał się dopiero na
        // `KOD=0`, czyli komunikatem, z którego nie da się odczytać przyczyny.
        $wyjscie = (string) preg_replace('/\e\[[0-9;]*m/', '', $wyjscie);

        // NAJPIERW konkretne werdykty, POTEM kod wyjścia. Kolejność nie jest
        // kosmetyczna: gdy oblewa się jeden przypadek, komunikat ma powiedzieć
        // KTÓRY, a nie „skrypt zwrócił 1". Kontrola ujemna na wyciętym
        // sprawdzaniu katalogu kopii dała najpierw dokładnie ten bezużyteczny
        // komunikat i nie dało się z niego odczytać przyczyny.
        preg_match_all('/✗\s+(.+)/u', $wyjscie, $oblane);

        $this->assertSame(
            [],
            array_map('trim', $oblane[1]),
            "Sprzątacz baz oblał te przypadki:\n  ".
            implode("\n  ", array_map('trim', $oblane[1]))."\n\n".
            "Cały przebieg:\n".$wyjscie,
        );

        $this->assertStringContainsString(
            'KOD=0',
            $wyjscie,
            "Dowód sprzątacza baz OBLEWA SIĘ. Uruchom go wprost:\n".
            "  bash tests/skrypty/sprzatanie-baz-testowych.sh\n\n".$wyjscie,
        );

        // KONTROLA DODATNIA. `KOD=0` dostalibyśmy także od skryptu, z którego
        // wycięto wszystkie przypadki. Pytamy więc o liczbę i o nazwy dwóch
        // przypadków, które są sednem: ten, który dowodzi, że sprzątacz w ogóle
        // sprząta, i ten, który dowodzi, że nie rusza trwającego przebiegu.
        $this->assertMatchesRegularExpression(
            '/Wszystkie (\d) sprawdzenia sprzątacza baz przechodzą/u',
            $wyjscie,
            "Skrypt wyszedł zerem, ale nie zameldował liczby zdanych przypadków.\n\n".$wyjscie,
        );

        foreach ([
            'baza po skasowanej kopii roboczej ZNIKA',
            'baza z OTWARTYM POŁĄCZENIEM zostaje',
            'raport nazywa ją trwającym przebiegiem',
            'baza bez wpisu w rejestrze ZOSTAJE',
            'baza kopii, która istnieje na dysku, ZOSTAJE',
        ] as $przypadek) {
            $this->assertStringContainsString(
                $przypadek,
                $wyjscie,
                "W przebiegu nie ma przypadku „{$przypadek}”.\n\n".$wyjscie,
            );
        }
    }
}

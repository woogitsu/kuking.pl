<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Polityka prywatności musi wymieniać KAŻDE ciasteczko, które serwis stawia
 * na urządzeniu człowieka — nie tylko sesję (R6, audyt #8).
 *
 * CO BYŁO ZEPSUTE. Sekcja 5 mówiła o „technicznie niezbędnych plikach cookies
 * (np. do utrzymania sesji logowania)" i na tym kończyła. Tymczasem serwis
 * stawia jeszcze dwa trwałe, roczne ciasteczka preferencji: skalę tekstu
 * i motyw. Oba są ustawiane w `ThemeController` i
 * `AccessibilitySettingsController`, a ich nazwy stoją w `config/kuking.php`.
 * Dokument prawny, który o nich milczy, jest po prostu niepełny — a to
 * akurat te dwa, które dla grupy 50+ są najczęściej używane.
 *
 * DLACZEGO STRAŻNIK, A NIE JEDNORAZOWA POPRAWKA. Nazwa ciasteczka mieszka
 * w configu i da się ją zmienić jedną linijką, nie dotykając polityki.
 * Ten test wiąże jedno z drugim: zmiana nazwy albo dołożenie trzeciego
 * ciasteczka preferencji oblewa go, zamiast po cichu rozjechać dokument
 * z rzeczywistością.
 *
 * CZEGO NIE PILNUJE, ŚWIADOMIE. Nie sprawdza ciasteczka sesji ani tokenu
 * CSRF — ich nazwy pochodzą z konfiguracji frameworka, nie z `kuking.*`,
 * i są opisane osobno. Nie ocenia też, czy ciasteczka są „niezbędne"
 * w rozumieniu prawa; to jest ocena dla prawnika, nie dla testu.
 */
final class PolitykaWymieniaKazdeCiasteczkoTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function ciasteczka(): array
    {
        return [
            'skala tekstu' => ['kuking.text.cookie', 'kuking_text_scale'],
            'motyw' => ['kuking.theme.cookie', 'motyw'],
        ];
    }

    private function polityka(): string
    {
        $sciezka = resource_path('legal/polityka-prywatnosci.md');

        $this->assertFileExists($sciezka, 'Polityka prywatności zniknęła — reszta tego testu nie sprawdzałaby niczego.');

        return (string) file_get_contents($sciezka);
    }

    #[DataProvider('ciasteczka')]
    public function test_polityka_wymienia_ciasteczko_z_nazwy(string $klucz, string $oczekiwana): void
    {
        // Kontrola dodatnia numer jeden: klucz configu naprawdę istnieje
        // i naprawdę niesie tę nazwę. Bez tego test przechodziłby także
        // wtedy, gdyby ciasteczko przestało być stawiane.
        $nazwa = config($klucz);
        $this->assertSame(
            $oczekiwana,
            $nazwa,
            "Nazwa ciasteczka w `{$klucz}` zmieniła się. Popraw politykę prywatności (sekcja 5) i ten test.",
        );

        $tresc = $this->polityka();

        // Kontrola dodatnia numer dwa: plik jest tym, za co się podaje.
        $this->assertStringContainsString(
            'Pliki cookies',
            $tresc,
            'To nie wygląda na politykę prywatności z sekcją o ciasteczkach.',
        );

        $this->assertStringContainsString(
            $nazwa,
            $tresc,
            "Polityka prywatności nie wymienia ciasteczka `{$nazwa}`, które serwis stawia na rok. "
            .'Sekcja 5 ma wymieniać każde ciasteczko z nazwy — patrz R6 w audycie #8.',
        );
    }

    public function test_polityka_nie_twierdzi_ze_ciasteczka_sluza_statystyce(): void
    {
        // Druga strona tej samej decyzji: ujawnienie dwóch ciasteczek nie może
        // podważyć powodu, dla którego serwis nie ma banera zgody (D-092).
        $tresc = $this->polityka();

        $this->assertStringContainsString(
            '**Nie używamy żadnych plików cookies do statystyk ani do reklam.**',
            $tresc,
            'Zniknęło zdanie, na którym stoi brak banera cookies (D-092).',
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Polityka nazywa wszystko, co serwis zostawia w przeglądarce (#2278, audyt 30.09 Z2).
 *
 * `PolitykaNazywaCiasteczkaUstawienTest` pilnował dwóch ciasteczek wyglądu,
 * a polityka mówiła „sesja do 30 dni”. Tymczasem każde logowanie
 * (`Auth::login(..., remember: true)`) stawia ciasteczko „zapamiętaj mnie”
 * na 400 dni z frameworka, a skrypty zapisują znacznik w `localStorage`
 * i stan trybu gotowania w `sessionStorage`. Na wspólnym komputerze
 * człowiek czytał, że po miesiącu bez wizyty zostanie wylogowany.
 *
 * Liczby i nazwy są WYLICZANE: czas życia z samej bramki logowania, nazwa
 * ciasteczka sesji z planu wdrożenia, klucze `localStorage` ze skryptów.
 * Nowy klucz albo zmieniony czas zapala test w dniu zmiany.
 */
class PolitykaNazywaPamiecPrzegladarkiTest extends TestCase
{
    private function polityka(): string
    {
        return (string) file_get_contents(resource_path('legal/polityka-prywatnosci.md'));
    }

    /** Sama sekcja 5 — żeby „400 dni” z innego miejsca nie dawało zieleni (PULAPKI §1). */
    private function sekcjaCiasteczek(): string
    {
        $polityka = $this->polityka();
        $od = strpos($polityka, '## 5. Pliki cookies');
        $do = strpos($polityka, '## 6.');
        $this->assertNotFalse($od, 'Kontrola: w polityce nie ma sekcji 5 o ciasteczkach.');
        $this->assertNotFalse($do);

        return substr($polityka, $od, $do - $od);
    }

    public function test_ciasteczko_zapamietaj_mnie_ma_nazwe_i_termin_z_bramki_logowania(): void
    {
        $bramka = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $bramka);
        $nazwa = $bramka->getRecallerName();
        // `getRememberDuration()` jest chronione — czytamy tę samą wartość,
        // której bramka użyje przy `Auth::login(..., remember: true)`.
        $dni = intdiv((int) (new ReflectionMethod($bramka, 'getRememberDuration'))->invoke($bramka), 60 * 24);

        $this->assertStringStartsWith('remember_web_', $nazwa, 'Kontrola: bramka nazywa ciasteczko inaczej, niż zakłada ten test.');
        $this->assertGreaterThan(30, $dni, 'Kontrola: czas życia ciasteczka jest krótszy od sesji — sprawdź, czy ten test ma jeszcze sens.');

        $sekcja = $this->sekcjaCiasteczek();

        $this->assertStringContainsString('`remember_web_…`', $sekcja, 'Polityka nie podaje nazwy ciasteczka „zapamiętaj mnie”.');
        $this->assertStringContainsString("ważne **{$dni} dni** od zalogowania", $sekcja, "Ciasteczko „zapamiętaj mnie” żyje {$dni} dni, a polityka podaje inny termin albo żaden.");

        // Wiersz o sesji w punkcie 2 mówi o tym samym, żeby „do 30 dni” nie czytało się jak wylogowanie.
        $this->assertMatchesRegularExpression(
            '/^\| Utrzymanie zalogowania.*ważne \*\*'.$dni.' dni\*\* od zalogowania/mu',
            $this->polityka(),
            'Wiersz „Utrzymanie zalogowania” nie mówi, że po wygaśnięciu sesji przeglądarka loguje sama.',
        );
    }

    public function test_ciasteczko_sesji_i_ochrony_formularzy_maja_nazwy_z_produkcji(): void
    {
        $plan = (string) file_get_contents(base_path('.railway/railway.ts'));
        $this->assertSame(1, preg_match('/\bAPP_NAME:\s*"([^"]+)"/', $plan, $m), 'Kontrola: brak APP_NAME w .railway/railway.ts.');
        $this->assertStringNotContainsString('SESSION_COOKIE', $plan, 'Plan wdrożenia nadpisuje nazwę ciasteczka sesji — popraw ten test i politykę.');

        $sesja = Str::slug($m[1]).'-session';
        $sekcja = $this->sekcjaCiasteczek();

        $this->assertStringContainsString('`'.$sesja.'`', $sekcja, "Polityka nie nazywa ciasteczka sesji „{$sesja}”.");
        $this->assertStringContainsString('`XSRF-TOKEN`', $sekcja, 'Polityka nie nazywa ciasteczka ochrony formularzy.');
    }

    public function test_kazdy_klucz_local_storage_ze_skryptow_jest_w_polityce(): void
    {
        $pliki = glob(resource_path('js/*.js')) ?: [];
        $this->assertGreaterThan(10, count($pliki), 'Kontrola: skan nie widzi skryptów w resources/js.');

        $klucze = [];
        $sesyjne = false;

        foreach ($pliki as $plik) {
            $kod = (string) file_get_contents($plik);
            preg_match_all('/localStorage\.setItem\(\s*[\'"]([^\'"]+)[\'"]/', $kod, $trafienia);
            $klucze = array_merge($klucze, $trafienia[1]);
            $sesyjne = $sesyjne || str_contains($kod, 'sessionStorage');
        }

        $klucze = array_values(array_unique($klucze));
        $this->assertNotEmpty($klucze, 'Kontrola: skan nie znalazł żadnego klucza localStorage — zmienił się sposób zapisu?');

        $sekcja = $this->sekcjaCiasteczek();
        $this->assertStringContainsString('`localStorage`', $sekcja);

        foreach ($klucze as $klucz) {
            $this->assertStringContainsString('`'.$klucz.'`', $sekcja, "Skrypt zapisuje w localStorage „{$klucz}”, a polityka tego nie nazywa.");
        }

        if ($sesyjne) {
            $this->assertStringContainsString('`sessionStorage`', $sekcja, 'Skrypty używają sessionStorage, a polityka o tym milczy.');
        }
    }

    public function test_pamiec_podreczna_service_workera_jest_w_polityce(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString('caches.open', $sw, 'Kontrola: service worker nie zapisuje już nic w pamięci podręcznej.');

        $this->assertStringContainsString('service worker', $this->sekcjaCiasteczek());
    }
}

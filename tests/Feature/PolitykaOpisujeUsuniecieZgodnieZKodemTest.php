<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POLITYKA PRYWATNOŚCI OPISUJE USUNIĘCIE KONTA TAK, JAK ROBI TO KOD
 *
 * `DokumentyPrawneNieKlamiaTest` pilnuje LICZB — każdy okres w dniach
 * z polityki musi zgadzać się z konfiguracją, która go egzekwuje. Ten plik
 * pilnuje tego samego dla dwóch NAPISÓW, o których polityka mówi wprost,
 * bo one obiecują człowiekowi konkretny skutek:
 *
 *  1. podpis, który zostaje pod tekstem po anonimizacji konta,
 *  2. etykieta haczyka, którym człowiek wybiera zakres usunięcia (D-022).
 *
 * PO CO. Polityka mówiła, że pod tekstem zostaje „autor: konto usunięte",
 * a `EraseAccountData` wpisuje **„Użytkownik usunięty"**. Nikt nie kłamał
 * świadomie — zdanie w dokumencie napisano wcześniej niż kod i nikt go potem
 * nie sprawdził. To ta sama klasa błędu co „14 dni" w dokumentacji przy
 * sześciomiesięcznym terminie odwołania w kodzie: dokument opisuje wersję
 * produktu, która już nie istnieje. Przy podpisie pod czyimś przepisem to
 * nie jest drobiazg redakcyjny — na tej podstawie człowiek decyduje, czy
 * zaznaczyć haczyk, który KASUJE jego przepisy nieodwracalnie.
 *
 * Asercja idzie w obie strony: napis musi być w polityce ORAZ w kodzie.
 * Zmiana którejkolwiek strony bez drugiej zapala ten test.
 */
class PolitykaOpisujeUsuniecieZgodnieZKodemTest extends TestCase
{
    use RefreshDatabase;

    private function polityka(): string
    {
        $sciezka = resource_path('legal/polityka-prywatnosci.md');
        $tresc = file_get_contents($sciezka);

        $this->assertIsString($tresc, 'Bez pliku polityki ten test nie ma czego porównywać.');
        $this->assertStringContainsString('## 7. Usunięcie konta', $tresc, 'Polityka nie ma sekcji o usunięciu konta — zmieniła się jej struktura.');

        return $tresc;
    }

    public function test_podpis_po_anonimizacji_jest_ten_sam_w_polityce_i_w_kodzie(): void
    {
        $uzytkownik = $this->user('kucharka');

        // Anonimizacja rusza WYŁĄCZNIE konto w karencji usunięcia — na
        // aktywnym koncie `handle()` nic nie robi. Test przez długi czas
        // wołał ją na koncie aktywnym i czytał `users.display_name`, którego
        // nie ma (nazwa jest w `profiles`), więc porównywał pusty napis
        // i przechodził zawsze. Znalazł to PHPStan na poziomie 2 (#1731).
        $uzytkownik->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();

        $this->assertTrue(
            app(EraseAccountData::class)->handle($uzytkownik->fresh()),
            'Anonimizacja się nie wykonała — nie ma czego porównywać z polityką.',
        );

        $podpis = (string) $uzytkownik->fresh()?->profile?->display_name;

        // Kontrola: bez tej asercji test przechodziłby, gdyby anonimizacja
        // w ogóle nie zmieniła nazwy.
        $this->assertNotSame('kucharka', $podpis, 'Anonimizacja nie zmieniła podpisu — nie ma czego porównywać z polityką.');

        $this->assertStringContainsString(
            $podpis,
            $this->polityka(),
            "Kod wpisuje pod tekstem podpis „{$podpis}”, a polityka prywatności obiecuje inny.",
        );
    }

    public function test_etykieta_haczyka_zakresu_usuniecia_jest_ta_sama_w_polityce_i_w_formularzu(): void
    {
        $widok = file_get_contents(resource_path('views/pages/settings/data.blade.php'));
        $this->assertIsString($widok);

        // Etykieta wyciągnięta z widoku, nie przepisana do testu z ręki —
        // inaczej test pilnowałby własnej kopii, a nie formularza.
        $this->assertSame(
            1,
            preg_match('/<span class="choice-label">(Usuń także[^<]+)<\/span>/u', $widok, $t),
            'Nie znalazłem w formularzu etykiety haczyka zakresu usunięcia — zmienił się widok.',
        );

        $etykieta = trim($t[1]);

        $this->assertStringContainsString(
            $etykieta,
            $this->polityka(),
            "Formularz nazywa ten wybór „{$etykieta}”, a polityka prywatności nazywa go inaczej.",
        );
    }

    public function test_haczyk_jest_domyslnie_odhaczony_tak_jak_mowi_polityka(): void
    {
        $this->actingAs($this->user('kucharka'));

        $odpowiedz = $this->get(route('settings.data'));
        $odpowiedz->assertOk();

        $html = $odpowiedz->getContent();
        $this->assertIsString($html);

        $this->assertSame(
            1,
            preg_match('/<input id="f-usun-tresci"[^>]*>/u', $html, $t),
            'Nie znalazłem haczyka zakresu usunięcia na ekranie danych.',
        );

        // Polityka mówi „jeśli nie zaznaczysz nic (tak jest domyślnie)".
        // Gdyby haczyk był domyślnie zaznaczony, to zdanie byłoby nieprawdą,
        // a skutek — skasowane przepisy kogoś, kto niczego nie wybierał.
        $this->assertStringNotContainsString('checked', $t[0], 'Haczyk kasujący treści jest domyślnie ZAZNACZONY — polityka obiecuje odwrotnie.');
        $this->assertStringContainsString('tak jest domyślnie', $this->polityka(), 'Polityka przestała mówić, który wariant jest domyślny.');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\LimityZdjec;
use Tests\TestCase;

/**
 * Regulamin §13 „Wymagania techniczne” i §14 „Reklamacje” (#2220, art. 8
 * ustawy o świadczeniu usług drogą elektroniczną).
 *
 * Każda liczba i każdy format, które regulamin podaje, pochodzą
 * z konfiguracji, która je egzekwuje — ten sam reżim co
 * `DokumentyPrawneNieKlamiaTest` dla polityki prywatności. Limity zdjęć
 * da się przestawić zmienną środowiskową (`KUKING_MEDIA_MAX_BYTES`,
 * `KUKING_MEDIA_MAX_MEGAPIXELS`); bez tego testu taka zmiana po cichu
 * unieważniłaby opublikowany dokument.
 */
class RegulaminWymaganiaIReklamacjeTest extends TestCase
{
    private function tresc(): string
    {
        return (string) file_get_contents(resource_path('legal/regulamin.md'));
    }

    /** Treść jednego punktu: od jego nagłówka do następnego nagłówka `## `. */
    private function punkt(string $naglowek): string
    {
        $tresc = $this->tresc();
        $poczatek = strpos($tresc, "\n## {$naglowek}\n");

        $this->assertNotFalse($poczatek, "Regulamin nie ma punktu „{$naglowek}”.");

        $reszta = substr($tresc, (int) $poczatek + 1);
        $koniec = strpos($reszta, "\n## ", 3);

        return $koniec === false ? $reszta : substr($reszta, 0, $koniec);
    }

    public function test_oba_punkty_sa_na_zywej_stronie_regulaminu(): void
    {
        $this->get(route('terms'))
            ->assertOk()
            ->assertSee('13. Wymagania techniczne')
            ->assertSee('14. Reklamacje');
    }

    public function test_limity_zdjec_w_regulaminie_zgadzaja_sie_z_konfiguracja(): void
    {
        $punkt = $this->punkt('13. Wymagania techniczne');

        $megabajty = LimityZdjec::maksMegabajtowDoKomunikatu();
        $megapiksele = (int) config('kuking.media.max_megapixels');
        $naWpis = (int) config('kuking.media.max_per_post');

        // KONTROLA: zero dałoby „**0 MB**” i mylący komunikat.
        $this->assertGreaterThan(0, $megabajty);
        $this->assertGreaterThan(0, $megapiksele);
        $this->assertGreaterThan(0, $naWpis);

        $this->assertStringContainsString("**{$megabajty} MB**", $punkt, 'Regulamin podaje inny limit wagi zdjęcia niż kuking.media.max_bytes.');
        $this->assertStringContainsString("**{$megapiksele} megapikseli**", $punkt, 'Regulamin podaje inny limit megapikseli niż kuking.media.max_megapixels.');
        $this->assertStringContainsString("**{$naWpis} zdjęć**", $punkt, 'Regulamin podaje inną liczbę zdjęć we wpisie niż kuking.media.max_per_post.');
        $this->assertStringContainsString('**'.LimityZdjec::formatyDlaCzlowieka().'**', $punkt, 'Formaty zdjęć w regulaminie to nie kuking.media.accepted_mime_types.');
    }

    /**
     * HEIC nie jest przyjmowany (D-064), więc regulamin ma o tym mówić —
     * a gdy kiedyś wejdzie na listę formatów, zdanie o odrzucaniu stanie się
     * nieprawdą i ten test to pokaże.
     */
    public function test_heic_opisany_zgodnie_z_lista_formatow(): void
    {
        $punkt = $this->punkt('13. Wymagania techniczne');
        $heicPrzyjmowany = array_intersect(['image/heic', 'image/heif'], LimityZdjec::dozwoloneTypy()) !== [];

        $this->assertFalse($heicPrzyjmowany, 'HEIC jest na liście formatów — popraw zdanie o HEIC w regulaminie §13 (D-064).');
        $this->assertStringContainsString('**HEIC**', $punkt);
        $this->assertStringContainsString('nie przyjmujemy', $punkt);
    }

    public function test_wymagania_wymieniaja_przegladarke_javascript_cookies_i_e_mail(): void
    {
        $punkt = $this->punkt('13. Wymagania techniczne');

        foreach (['przeglądarki internetowej', 'JavaScriptu', 'cookies', 'adresu e-mail'] as $wymaganie) {
            $this->assertStringContainsString($wymaganie, $punkt, "Wymagania techniczne nie mówią o: {$wymaganie}.");
        }
    }

    public function test_reklamacja_ma_kanal_z_konfiguracji_termin_i_eskalacje(): void
    {
        $punkt = $this->punkt('14. Reklamacje');
        $dni = (int) config('kuking.reklamacje.termin_odpowiedzi_dni');

        $this->assertGreaterThan(0, $dni, 'Kontrola: kuking.reklamacje.termin_odpowiedzi_dni musi być liczbą dodatnią.');
        // Art. 7a ustawy o prawach konsumenta: najwyżej 14 dni.
        $this->assertLessThanOrEqual(14, $dni);
        $this->assertStringContainsString("w ciągu **{$dni} dni** od otrzymania reklamacji", $punkt);

        foreach (['email', 'nazwa_pelna', 'ulica', 'kod_pocztowy', 'miejscowosc'] as $pole) {
            $wartosc = (string) config('kuking.podmiot.'.$pole);
            $this->assertNotSame('', $wartosc, "Kontrola: kuking.podmiot.{$pole} jest puste.");
            $this->assertStringContainsString($wartosc, $punkt, "Kanał reklamacji nie zgadza się z kuking.podmiot.{$pole}.");
        }

        // Odesłanie do odwołania (§8) i pozasądowej drogi dla konsumenta.
        $this->assertStringContainsString('punkcie 8', $punkt);
        $this->assertStringContainsString('rzecznika konsumentów', $punkt);
        $this->assertStringContainsString('Inspekcji Handlowej', $punkt);
    }
}

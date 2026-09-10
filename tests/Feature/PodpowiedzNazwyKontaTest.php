<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * NAZWA KONTA PODPOWIADANA Z IMIENIA (decyzja właściciela z 10 września 2026).
 *
 * SKĄD TA ZMIANA
 * Zewnętrzny audyt 60+ (`docs/research/AUDYT_60_PLUS.md`) wskazał, że
 * rejestracja każe wymyślić dwa podobne pojęcia naraz: imię widoczne dla
 * innych i nazwę, która idzie do adresu profilu. Właściciel rozstrzygnął:
 * **dwa pola zostają** (adres profilu ma być świadomym wyborem, nie
 * niespodzianką), ale nazwa jest podpowiadana i można ją nadpisać.
 *
 * CZEGO TE TESTY PILNUJĄ
 * Nie tego, że JavaScript „działa" — tego z PHPUnita sprawdzić się nie da.
 * Pilnują dwóch rzeczy, które mogą po cichu zniknąć przy kolejnej zmianie
 * ekranu: że podpowiedź jest w ogóle podłączona do tych dwóch pól i że
 * przestaje działać, gdy człowiek wpisze nazwę sam. Druga rzecz jest tu
 * ważniejsza: nadpisywanie tego, co ktoś napisał ręcznie, wyglądałoby przy
 * 65-latce jak usterka („kasuje mi to, co piszę").
 */
class PodpowiedzNazwyKontaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_pomoc_przy_polu_mowi_ze_nazwa_jest_podpowiedzia(): void
    {
        $ekran = $this->get(route('register'))->assertOk();

        $ekran->assertSee('Podpowiadamy ją z Twojego imienia', false);
        $ekran->assertSee('możesz zostawić albo wpisać własną', false);

        // Oba pola nadal istnieją — decyzja właściciela była „dwa pola
        // zostają", więc zniknięcie któregoś z nich to regresja, nie
        // uproszczenie.
        $ekran->assertSee('id="f-display_name"', false);
        $ekran->assertSee('id="f-username"', false);
    }

    #[Test]
    public function test_podpowiedz_jest_podlaczona_do_tych_dwoch_pol(): void
    {
        $js = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("getElementById('f-display_name')", $js);
        $this->assertStringContainsString("getElementById('f-username')", $js);
    }

    #[Test]
    public function test_podpowiedz_ustepuje_temu_co_czlowiek_wpisze_sam(): void
    {
        $js = (string) file_get_contents(resource_path('js/app.js'));

        // Znacznik „człowiek ruszył pole sam" i warunek, który po nim
        // przestaje podpowiadać. Bez tego podpowiedź kasowałaby wpisywaną
        // nazwę przy każdej literze dopisanej do imienia.
        $this->assertStringContainsString('wlasnyWybor', $js);
        $this->assertMatchesRegularExpression('/if \(wlasnyWybor\) \{\s*return;/', $js);
    }

    #[Test]
    public function test_serwer_i_tak_uklada_nazwe_po_swojemu(): void
    {
        // Autorytetem jest PHP, nie podpowiedź w przeglądarce. Gdyby ktoś
        // wyłączył JavaScript albo wpisał nazwę ręcznie po ludzku, serwer
        // musi ją znormalizować sam — inaczej cała ta wygoda byłaby jedyną
        // linią obrony przed odmową przy rejestracji.
        $this->post(route('register'), [
            'display_name' => 'Grażynka K',
            'username' => 'Grażynka z Podlasia',
            'email' => 'grazynka.podpowiedz@example.test',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('profiles', ['username' => 'grazynka_z_podlasia']);
    }
}

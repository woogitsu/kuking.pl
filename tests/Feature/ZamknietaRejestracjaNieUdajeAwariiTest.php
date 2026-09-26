<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\RejestracjaZamknieta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Zamknięta rejestracja mówi, że nie zakładamy kont — nie, że serwis leży.
 *
 * SKĄD TEN TEST (audyt B9, pkt 1)
 * `RegisterController` kończył się `abort_unless(…, 503)`. Człowiek dostawał
 * ekran „Robimy przerwę techniczną / Kuking jest teraz niedostępny”, choć
 * serwis działał, a logowanie było otwarte. Tekst przekazany do `abort()`
 * nigdzie się nie pokazywał. Drogi przez Google i Facebooka są w
 * `LogowanieKontemGoogleTest`, `LogowanieKontemFacebookiemTest`
 * i `WejdzPrzezDostawceTest`.
 *
 * KONTROLA DODATNIA: przy otwartej rejestracji formularz się wyświetla —
 * bez tego przekierowanie mogłoby dotyczyć każdego wejścia na /register.
 */
class ZamknietaRejestracjaNieUdajeAwariiTest extends TestCase
{
    use RefreshDatabase;

    public function test_formularz_przy_zamknietej_rejestracji_odsyla_na_logowanie_z_wyjasnieniem(): void
    {
        config(['kuking.account.registration_open' => false]);

        $this->get(route('register'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', RejestracjaZamknieta::KOMUNIKAT);

        $html = (string) $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('Nowych kont chwilowo nie zakładamy. Jeśli masz już konto, zaloguj się.', $html);
        $this->assertStringNotContainsString('przerwę techniczną', $html);
    }

    public function test_wyslanie_formularza_przy_zamknietej_rejestracji_nie_zaklada_konta_i_nie_daje_503(): void
    {
        config(['kuking.account.registration_open' => false]);

        $this->post(route('register'), [
            'display_name' => 'Basia',
            'username' => 'basia',
            'email' => 'basia@example.test',
            'password' => 'trzy slowa razem',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', RejestracjaZamknieta::KOMUNIKAT);

        $this->assertSame(0, User::count());
    }

    public function test_otwarta_rejestracja_pokazuje_formularz(): void
    {
        config(['kuking.account.registration_open' => true]);

        $this->get(route('register'))->assertOk();
    }
}

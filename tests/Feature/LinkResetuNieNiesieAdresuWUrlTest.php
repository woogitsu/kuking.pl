<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\UstawienieNowegoHasla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ADRES E-MAIL NIE STOI W ADRESIE LINKU DO USTAWIENIA HASŁA.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO TU JEST PILNOWANE
 * ────────────────────────────────────────────────────────────────────────
 *
 * `Illuminate\Auth\Notifications\ResetPassword::resetUrl()` buduje domyślnie
 *
 *     https://kuking.pl/nowe-haslo/<ŻETON>?email=basia@wp.pl
 *
 * czyli żywy żeton resetu i adres, na który ten żeton pasuje — razem,
 * w jednym łańcuchu. Adres strony nie jest treścią żądania: zapisuje go
 * historia przeglądarki na wspólnym komputerze, dziennik dostępu hostingu
 * i każde proxy po drodze. Człowiek, który zobaczyłby ten jeden wiersz, ma
 * komplet do wejścia na cudze konto i wie, czyje ono jest.
 *
 * Żeton z adresu zdjąć się nie da — bez niego link nie działa. Adres e-mail
 * owszem: ekran `auth/reset-password.blade.php` ma własne, widoczne pole
 * „Twój adres e-mail" z `autocomplete="email"`, a `Password::reset()` czyta
 * adres z CIAŁA formularza. Parametr w adresie był wyłącznie wypełniaczem.
 *
 * Reguła mieszka w `AppServiceProvider::zdejmijAdresZLinkuResetu()`
 * (hak `ResetPassword::createUrlUsing`), bo adres budują DWA powiadomienia
 * i trzecie, dopisane kiedyś, ma dostać tę poprawkę za darmo.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TEN PLIK PILNUJE RÓWNIE MOCNO
 * ────────────────────────────────────────────────────────────────────────
 *
 * Że link DALEJ DZIAŁA. Poprawka prywatności, która psuje odzyskiwanie
 * hasła, jest gorsza od problemu, który naprawia — więc ostatni przypadek
 * przechodzi całą drogę: prośba, link, ustawienie hasła, wejście.
 */
final class LinkResetuNieNiesieAdresuWUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Suita chodzi domyślnie na sterowniku `array`, a przy nim
        // `PasswordResetController::sendLink()` NIE dochodzi do wysyłki —
        // odpowiada „poczta nie działa" i kończy (`ResetHaslaMowiPrawdeTest`).
        // Bez tej linii ten plik pilnowałby kształtu linku, który nigdy nie
        // powstaje.
        config(['mail.default' => 'smtp']);
    }

    public function test_link_z_listu_nie_niesie_adresu_w_parametrach(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'basia@example.com']);

        $this->post(route('password.email'), ['email' => 'basia@example.com']);

        Notification::assertSentTo($user, UstawienieNowegoHasla::class, function ($powiadomienie) use ($user): bool {
            $link = $powiadomienie->toMail($user)->viewData['linkUrl'];

            $this->assertIsString($link);
            $this->assertStringNotContainsString('email=', $link);
            $this->assertStringNotContainsString($user->email, $link);
            $this->assertStringNotContainsString(rawurlencode($user->email), $link);

            // KONTROLA DODATNIA: to nadal jest link do ustawienia hasła,
            // a nie pusty łańcuch, na którym każde `assertStringNotContains`
            // przechodzi z definicji.
            $this->assertStringContainsString('/nowe-haslo/', $link);

            return true;
        });
    }

    public function test_link_bez_adresu_dalej_otwiera_ekran_ustawienia_hasla(): void
    {
        $odpowiedz = $this->get(route('password.reset', ['token' => 'zeton-testowy-0123456789']));

        $odpowiedz->assertOk();
        $odpowiedz->assertSee('Twój adres e-mail');
    }

    /**
     * STARE LINKI, KTÓRE JUŻ LEŻĄ W CZYICHŚ SKRZYNKACH, DZIAŁAJĄ BEZ ZMIAN.
     *
     * `PasswordResetController::resetForm()` dalej czyta `?email=` i wypełnia
     * nim pole — zmienia się tylko to, co od teraz WYCHODZI z serwera.
     */
    public function test_stary_link_z_adresem_dalej_wypelnia_pole(): void
    {
        $odpowiedz = $this->get(
            route('password.reset', ['token' => 'zeton-testowy-0123456789']).'?email=basia%40example.com',
        );

        $odpowiedz->assertOk();
        $odpowiedz->assertSee('basia@example.com', escape: false);
    }

    public function test_odzyskanie_hasla_dziala_od_poczatku_do_konca(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'basia@example.com']);

        $this->post(route('password.email'), ['email' => 'basia@example.com']);

        $zeton = null;

        Notification::assertSentTo($user, UstawienieNowegoHasla::class, function ($powiadomienie) use (&$zeton): bool {
            $zeton = $powiadomienie->token;

            return true;
        });

        $this->assertIsString($zeton);

        $this->post(route('password.update'), [
            'token' => $zeton,
            'email' => 'basia@example.com',
            'password' => 'zupelnie-nowe-haslo-2026',
            'password_confirmation' => 'zupelnie-nowe-haslo-2026',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(
            auth()->validate(['email' => 'basia@example.com', 'password' => 'zupelnie-nowe-haslo-2026']),
        );
    }
}

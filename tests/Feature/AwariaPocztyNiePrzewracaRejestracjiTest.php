<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\PotwierdzenieAdresu;
use App\Notifications\UstawienieNowegoHasla;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Listy transakcyjne idą przez kolejkę, nie w żądaniu (audyt W3-13).
 *
 * CO SIĘ DZIAŁO
 * Konto i profil powstają w transakcji, transakcja się zatwierdza, potem leci
 * `event(new Registered($user))`, a listener Laravela wysyłał list
 * SYNCHRONICZNIE. Wyjątek z serwera poczty przewracał więc żądanie PO
 * utworzeniu konta:
 *
 *     wysyłam formularz → konto zapisane → SMTP pada → strona błędu
 *     → rejestruję się jeszcze raz → „na ten adres jest już założone konto"
 *
 * Dla człowieka wygląda to jak zgubiona rejestracja przy jednoczesnym
 * zablokowaniu adresu — czyli najgorsze możliwe połączenie. Konto istnieje,
 * ale on o tym nie wie i nie ma jak wejść.
 *
 * To samo dotyczyło przypomnienia hasła, czyli drugiej drogi, którą ktoś
 * próbuje wrócić do konta.
 */
class AwariaPocztyNiePrzewracaRejestracjiTest extends TestCase
{
    use RefreshDatabase;

    public function test_oba_listy_transakcyjne_sa_kolejkowane(): void
    {
        // TO JEST WŁAŚCIWA ASERCJA TEJ NAPRAWY. Reszta testów sprawdza
        // zachowanie, ale o synchroniczności decyduje ten jeden interfejs —
        // i to jego brak był całą usterką.
        foreach ([PotwierdzenieAdresu::class, UstawienieNowegoHasla::class] as $klasa) {
            $this->assertTrue(
                is_subclass_of($klasa, ShouldQueue::class),
                class_basename($klasa).' nie jest kolejkowane — awaria serwera poczty przewróci '
                .'żądanie PO zapisaniu konta i człowiek zobaczy błąd zamiast potwierdzenia.',
            );
        }
    }

    public function test_rejestracja_konczy_sie_powodzeniem_a_list_idzie_do_kolejki(): void
    {
        Notification::fake();

        // Trasa POST bez nazwy — adres wprost.
        $this->post('/register', [
            'email' => 'basia@example.com',
            'password' => 'bardzo-tajne-haslo-123',
            'password_confirmation' => 'bardzo-tajne-haslo-123',
            'display_name' => 'Basia',
            'username' => 'basia',
            'terms_accepted' => '1',
            'age_confirmed' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'basia@example.com']);

        Notification::assertSentTo(
            User::where('email', 'basia@example.com')->firstOrFail(),
            PotwierdzenieAdresu::class,
        );
    }
}

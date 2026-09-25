<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Logowanie hasłem wydaje NOWY identyfikator sesji (audyt B7-24).
 *
 * Fiksacja sesji: ktoś podsuwa ofierze swój identyfikator (np. przez
 * wspólny komputer w bibliotece), czeka, aż ofiara się zaloguje, i wchodzi
 * na jej konto tym samym identyfikatorem. Obroną jest zmiana identyfikatora
 * w chwili logowania. Rotację pilnowały dotąd testy 2FA, resetu hasła
 * i dostawców — zwykłego logowania hasłem, najczęstszej drogi, nie.
 *
 * Test sprawdza ZACHOWANIE, nie linijkę: przeglądarka przychodzi z ciasteczkiem
 * sesji, a po logowaniu stary identyfikator nie jest już sesją zalogowanej
 * osoby. Bez ciasteczka test byłby pusty — każde żądanie bez ciasteczka
 * dostaje w testach świeży identyfikator niezależnie od kodu.
 *
 * Kontrola ujemna (25.09.2026, `scripts/kontrola-ujemna.sh`): zastąpienie
 * `regenerate()` + `Auth::login()` w `LoginController::store()` ustawieniem
 * użytkownika w sesji bez rotacji → test OBLAŁ. Samo usunięcie linii
 * `regenerate()` test PRZEŻYWA i to jest poprawny wynik: `Auth::login()`
 * i tak wywołuje `migrate(true)` na sesji (SessionGuard::updateSession),
 * więc zachowanie się nie zmienia — jawne `regenerate()` jest drugą warstwą.
 */
class SesjaPoLogowaniuHaslemTest extends TestCase
{
    use RefreshDatabase;

    public function test_logowanie_haslem_zmienia_identyfikator_sesji(): void
    {
        $basia = $this->user('basia');

        $this->get(route('login'))->assertOk();
        $staryId = $this->app['session']->getId();
        $this->assertNotEmpty($staryId);

        // Kontrola przyrządu: z ciasteczkiem, a BEZ logowania identyfikator
        // zostaje ten sam. Inaczej asercja niżej przechodziłaby zawsze.
        $this->withCookie(config('session.cookie'), $staryId)
            ->get(route('login'))->assertOk();
        $this->assertSame($staryId, $this->app['session']->getId(), 'Ciasteczko sesji nie dotarło — test nie sprawdzałby niczego.');

        $this->withCookie(config('session.cookie'), $staryId)
            ->post(route('login'), [
                'login' => 'basia',
                'password' => 'haslo-testowe-123',
            ])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($basia);
        $this->assertNotSame(
            $staryId,
            $this->app['session']->getId(),
            'Po logowaniu hasłem sesja ma stary identyfikator — ktoś, kto go znał przed logowaniem, jest teraz zalogowany jako ta osoba.',
        );
    }
}

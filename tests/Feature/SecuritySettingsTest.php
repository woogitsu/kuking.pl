<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * „Wyloguj mnie z innych urządzeń" i rotacja sesji po zmianie hasła
 * w ustawieniach (issue #12).
 *
 * Obie akcje dzielą ten sam mechanizm co blokada konta —
 * `User::invalidateSessions()` (AccountStatusTest) — ale z jedną różnicą:
 * BIEŻĄCA sesja (ta, w której ktoś właśnie kliknął przycisk albo zmienił
 * hasło) ma zostać żywa. Testy niżej sprawdzają obie strony tej zasady:
 * cudza sesja pada, własna zostaje — a bez podania prawidłowego hasła
 * nic w ogóle się nie dzieje.
 */
class SecuritySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function wstawCudzaSesje(string $userId, string $id = 'cudza-sesja'): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'cudze-urzadzenie',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    /**
     * Zakłada BIEŻĄCĄ sesję żądania testowego pod znanym, prawdziwym
     * identyfikatorem — i wysyła ciasteczko wskazujące dokładnie na nią.
     *
     * Dzięki temu test nie zgaduje, jakiej sesji użyje `actingAs()` (ten
     * bezpośrednio ustawia usera na guardzie, nie loguje przez sesję) —
     * wiadomo z góry, jaki wiersz w `sessions` ma PRZETRWAĆ.
     */
    private function zalozBiezacaSesje(string $userId): string
    {
        // Musi przejść `Store::isValidId()` (alfanumeryczne, 40 znaków) —
        // inaczej StartSession uzna cookie za nieprawidłowe i wygeneruje
        // zupełnie nowy, losowy identyfikator zamiast wczytać ten wiersz.
        $id = Str::random(40);

        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'biezace-urzadzenie',
            'payload' => '',
            'last_activity' => time(),
        ]);

        $this->withCookie((string) config('session.cookie'), $id);

        return $id;
    }

    // -----------------------------------------------------------------
    // „Wyloguj mnie z innych urządzeń"
    // -----------------------------------------------------------------

    public function test_wyloguj_z_innych_urzadzen_kasuje_cudza_sesje_a_biezaca_zostaje(): void
    {
        config(['session.driver' => 'database']);
        $basia = $this->user('basia');
        $this->wstawCudzaSesje($basia->getKey());
        $biezacaSesjaId = $this->zalozBiezacaSesje($basia->getKey());

        $this->actingAs($basia)
            ->post(route('settings.security.logout-others'), ['password' => 'haslo-testowe-123'])
            ->assertRedirect();

        // Cudza sesja — ta, którą ktoś zostawił na cudzym telefonie — nie
        // żyje.
        $this->assertDatabaseMissing('sessions', ['id' => 'cudza-sesja']);

        // Bieżąca sesja (ta, w której właśnie kliknięto przycisk) ZOSTAJE —
        // inaczej wylogowanie z innych urządzeń wylogowałoby też z tego.
        $this->assertDatabaseHas('sessions', ['id' => $biezacaSesjaId, 'user_id' => $basia->getKey()]);

        $this->get(route('home'))->assertOk();
    }

    public function test_wyloguj_z_innych_urzadzen_bez_hasla_nic_nie_kasuje(): void
    {
        config(['session.driver' => 'database']);
        $basia = $this->user('basia');
        $this->wstawCudzaSesje($basia->getKey());

        $this->actingAs($basia)
            ->post(route('settings.security.logout-others'), [])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseHas('sessions', ['id' => 'cudza-sesja']);
    }

    public function test_wyloguj_z_innych_urzadzen_ze_zlym_haslem_nic_nie_kasuje(): void
    {
        config(['session.driver' => 'database']);
        $basia = $this->user('basia');
        $this->wstawCudzaSesje($basia->getKey());

        $this->actingAs($basia)
            ->post(route('settings.security.logout-others'), ['password' => 'zupelnie-zle-haslo'])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseHas('sessions', ['id' => 'cudza-sesja']);
    }

    // -----------------------------------------------------------------
    // Rotacja sesji po zmianie hasła w ustawieniach
    // -----------------------------------------------------------------

    public function test_zmiana_hasla_kasuje_cudza_sesje_a_biezaca_zostaje(): void
    {
        config(['session.driver' => 'database']);
        $basia = $this->user('basia');
        $this->wstawCudzaSesje($basia->getKey());
        $biezacaSesjaId = $this->zalozBiezacaSesje($basia->getKey());

        $this->actingAs($basia)
            ->put(route('settings.security.password'), [
                'current_password' => 'haslo-testowe-123',
                'password' => 'zupelnieinnehaslo456',
                'password_confirmation' => 'zupelnieinnehaslo456',
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('sessions', ['id' => 'cudza-sesja']);
        $this->assertTrue(Hash::check('zupelnieinnehaslo456', $basia->fresh()->password));

        // Osoba, która właśnie zmieniła hasło, NIE zostaje wylogowana ze
        // swojej własnej przeglądarki — to byłoby nieodróżnialne od awarii.
        $this->assertDatabaseHas('sessions', ['id' => $biezacaSesjaId, 'user_id' => $basia->getKey()]);

        $this->get(route('home'))->assertOk();
    }

    public function test_zmiana_hasla_ze_zlym_obecnym_haslem_nic_nie_zmienia(): void
    {
        config(['session.driver' => 'database']);
        $basia = $this->user('basia');
        $this->wstawCudzaSesje($basia->getKey());
        $staryHash = $basia->password;

        $this->actingAs($basia)
            ->put(route('settings.security.password'), [
                'current_password' => 'zle-haslo',
                'password' => 'zupelnieinnehaslo456',
                'password_confirmation' => 'zupelnieinnehaslo456',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertSame($staryHash, $basia->fresh()->password);
        $this->assertDatabaseHas('sessions', ['id' => 'cudza-sesja']);
    }

    public function test_zmiana_hasla_bez_zgodnego_potwierdzenia_jest_odrzucana(): void
    {
        $basia = $this->user('basia');
        $staryHash = $basia->password;

        $this->actingAs($basia)
            ->put(route('settings.security.password'), [
                'current_password' => 'haslo-testowe-123',
                'password' => 'zupelnieinnehaslo456',
                'password_confirmation' => 'cos-zupelnie-innego',
            ])
            ->assertSessionHasErrors('password');

        $this->assertSame($staryHash, $basia->fresh()->password);
    }

    public function test_gosc_nie_wejdzie_na_ekran_bezpieczenstwa(): void
    {
        $this->get(route('settings.security'))->assertRedirect(route('login'));
    }

    public function test_blad_hasla_przy_wylogowaniu_innych_urzadzen_nie_oznacza_formularza_zmiany_hasla(): void
    {
        $basia = $this->user('basia');

        $odpowiedz = $this->actingAs($basia)
            ->from(route('settings.security'))
            ->post(route('settings.security.logout-others'), [
                'password' => 'zupelnie-zle-haslo',
            ]);

        $odpowiedz->assertRedirect(route('settings.security'));

        $ekran = $this->actingAs($basia)
            ->get(route('settings.security'))
            ->assertOk();

        $html = (string) $ekran->getContent();

        // Pole „Nowe hasło" w formularzu zmiany hasła NIE może mieć błędu ani czerwieni.
        $this->assertStringNotContainsString('id="f-password-error"', $html, 'Pole nowego hasła dostało błąd z formularza wylogowania innych urządzeń.');
        // Błąd powinien być przypięty do formularza wylogowania innych urządzeń.
        $this->assertStringContainsString('id="f-password-wyloguj-error"', $html);
    }
}

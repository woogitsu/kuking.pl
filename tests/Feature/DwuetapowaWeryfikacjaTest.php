<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Weryfikacja dwuetapowa (2FA / TOTP) dla moderatorów i adminów (issue #12).
 *
 * Kryteria akceptacji z issue: poprawny kod wpuszcza, zły odrzuca, TEN SAM
 * kod użyty drugi raz nie wpuszcza (odtworzenie), kod zapasowy działa RAZ,
 * moderator bez 2FA nie wchodzi na /admin, zwykły użytkownik dalej dostaje
 * 404 (nie 403).
 */
class DwuetapowaWeryfikacjaTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    private function totp(): TwoFactorAuthenticator
    {
        return app(TwoFactorAuthenticator::class);
    }

    /**
     * Kod, który TERAZ przejdzie weryfikację dla danego sekretu — dokładnie
     * to samo liczy aplikacja w telefonie.
     */
    private function aktualnyKod(string $sekret): string
    {
        return (new Google2FA)->getCurrentOtp($sekret);
    }

    /**
     * Włącza 2FA na koncie w całości (bez przechodzenia przez HTTP) i zwraca
     * sekret — wygodne dla testów, które sprawdzają coś INNEGO niż sam ekran
     * włączenia.
     */
    private function wlacz2fa(User $user): string
    {
        $sekret = $this->totp()->generateSecret();
        $user->beginTwoFactorSetup($sekret);
        $user->confirmTwoFactor($this->totp()->hashBackupCodes(['ABCD-1234']));
        $user->refresh();

        return $sekret;
    }

    // -----------------------------------------------------------------
    // Ekran włączenia
    // -----------------------------------------------------------------

    public function test_ekran_wlaczenia_pokazuje_qr_i_sekret_tekstem(): void
    {
        $basia = $this->user('basia');

        $response = $this->actingAs($basia)->get(route('settings.two_factor.enable'));

        $response->assertOk();
        $response->assertSee('<svg', false);

        $basia->refresh();
        $response->assertSee($basia->two_factor_secret);
    }

    public function test_poprawny_kod_wlacza_2fa_i_pokazuje_kody_zapasowe_raz(): void
    {
        $basia = $this->user('basia');
        $this->actingAs($basia)->get(route('settings.two_factor.enable'));
        $basia->refresh();

        $kod = $this->aktualnyKod($basia->two_factor_secret);

        $response = $this->actingAs($basia)
            ->post(route('settings.two_factor.confirm'), ['code' => $kod, 'password' => 'haslo-testowe-123']);

        $response->assertRedirect(route('settings.two_factor.codes'));
        $response->assertSessionHas('kody_zapasowe');

        $basia->refresh();
        $this->assertTrue($basia->hasTwoFactorConfirmed());
        $this->assertNotEmpty($basia->two_factor_backup_codes);

        // Strona z kodami pokazuje je RAZ — z flashem z poprzedniego żądania.
        //
        // NA TREŚCI EKRANU, NIE NA CAŁYM DOKUMENCIE (pułapka 1b): to zdanie
        // jest równocześnie `<title>` tej strony, więc asercja na całej
        // odpowiedzi przechodziła także po skasowaniu nagłówka — zmierzone
        // 12.09.2026 na `pages/settings/two_factor/codes.blade.php`.
        $ekran = $this->followRedirects($response)->assertOk();

        $this->assertStringContainsString(
            'Zapisz swoje kody zapasowe',
            $this->trescEkranu((string) $ekran->getContent()),
        );
    }

    public function test_zly_kod_nie_wlacza_2fa(): void
    {
        $basia = $this->user('basia');
        $this->actingAs($basia)->get(route('settings.two_factor.enable'));
        $basia->refresh();

        $this->actingAs($basia)
            ->post(route('settings.two_factor.confirm'), ['code' => '000000', 'password' => 'haslo-testowe-123'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($basia->refresh()->hasTwoFactorConfirmed());
    }

    public function test_odswiezenie_kodow_zapasowych_po_odejsciu_ich_nie_pokazuje(): void
    {
        $basia = $this->user('basia');
        $this->wlacz2fa($basia);

        // Wejście na ekran kodów BEZ świeżego flasha (np. zakładka, drugie
        // wejście) — kody nie mogą się tam pojawić drugi raz.
        $this->actingAs($basia)
            ->get(route('settings.two_factor.codes'))
            ->assertRedirect(route('settings.two_factor.edit'));
    }

    // -----------------------------------------------------------------
    // Logowanie — poprawny kod wpuszcza, zły odrzuca, powtórzenie nie wpuszcza
    // -----------------------------------------------------------------

    public function test_logowanie_z_2fa_wymaga_kodu_po_hasle(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $this->wlacz2fa($basia);

        $response = $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);

        $response->assertRedirect(route('login.two_factor'));
        $this->assertGuest();
    }

    public function test_poprawny_kod_2fa_loguje(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $sekret = $this->wlacz2fa($basia);

        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);

        $this->post(route('login.two_factor.store'), ['code' => $this->aktualnyKod($sekret)])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($basia->fresh());
    }

    public function test_zly_kod_2fa_nie_loguje(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $this->wlacz2fa($basia);

        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);

        $this->post(route('login.two_factor.store'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_ten_sam_kod_uzyty_drugi_raz_nie_loguje(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $sekret = $this->wlacz2fa($basia);
        $kod = $this->aktualnyKod($sekret);

        // Pierwsze użycie — przechodzi i loguje.
        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);
        $this->post(route('login.two_factor.store'), ['code' => $kod])->assertRedirect(route('home'));

        // Wylogowanie i drugie logowanie TYM SAMYM kodem — atak powtórzenia.
        $this->post(route('logout'));
        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);

        $this->post(route('login.two_factor.store'), ['code' => $kod])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_kod_zapasowy_dziala_raz(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $sekret = $this->totp()->generateSecret();
        $basia->beginTwoFactorSetup($sekret);
        $basia->confirmTwoFactor($this->totp()->hashBackupCodes(['ABCD-1234']));
        $basia->refresh();

        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);
        $this->post(route('login.two_factor.store'), ['backup_code' => 'ABCD-1234'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($basia->fresh());

        $this->post(route('logout'));
        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);

        // Ten sam kod zapasowy drugi raz — już go nie ma w bazie.
        $this->post(route('login.two_factor.store'), ['backup_code' => 'ABCD-1234'])
            ->assertSessionHasErrors('backup_code');

        $this->assertGuest();
    }

    public function test_limit_prob_blokuje_zgadywanie_kodu(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $sekret = $this->wlacz2fa($basia);

        [$maxProb] = explode(',', config('kuking.limits.two_factor'));

        // Sesję po pierwszym kroku logowania (hasło) ustawiamy WPROST,
        // zamiast wołać POST /login — /login ma WŁASNY throttle na tym samym,
        // dzielonym po adresie IP, kluczu Laravela (`ThrottleRequests`
        // klucz sygnatury liczy się z domeny i IP, NIE z trasy), więc
        // wywołanie go tutaj zużyłoby jedną z prób limitu, który ma testować
        // WYŁĄCZNIE ten test. Test logowania z 2FA (wyżej) i tak sprawdza
        // pełną ścieżkę przez /login.
        $this->withSession(TwoFactorAuthenticator::oczekujaceLogowanie($basia->fresh()));

        for ($i = 0; $i < (int) $maxProb; $i++) {
            $this->post(route('login.two_factor.store'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        // Szósta próba z tego samego adresu IP nie dociera już do sprawdzania
        // kodu — trasa ma limit `throttle:` (ten sam wpis konfiguracji),
        // więc kończy się zwykłym 429, dokładnie jak przy logowaniu samym
        // hasłem (patrz SecurityTest).
        $this->post(route('login.two_factor.store'), ['code' => $this->aktualnyKod($sekret)])
            ->assertStatus(429);

        $this->assertGuest();
    }

    /**
     * Limit z trasy (`throttle:`, po adresie IP) i limit w kontrolerze
     * (po koncie) to DWIE OSOBNE bariery. Test wyżej pokazuje tylko tę
     * pierwszą — z jednego adresu IP nie da się odróżnić, która zadziałała.
     * Ten test zmienia adres IP przy KAŻDEJ próbie (rozproszony atak), więc
     * throttle po IP nigdy się nie uzbraja — jeśli konto mimo to jest
     * chronione, to wyłącznie dzięki limitowi liczonemu po koncie.
     */
    public function test_limit_prob_liczy_sie_po_koncie_nie_po_ip(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $sekret = $this->wlacz2fa($basia);

        [$maxProb] = explode(',', config('kuking.limits.two_factor'));

        $this->withSession(TwoFactorAuthenticator::oczekujaceLogowanie($basia->fresh()));

        for ($i = 0; $i < (int) $maxProb; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.{$i}.1"])
                ->post(route('login.two_factor.store'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        // Szósty adres IP — jeszcze nigdy nieużyty, więc throttle trasy
        // widzi go po raz pierwszy. Mimo to konto jest zablokowane, bo to
        // limit w kontrolerze (klucz: identyfikator konta) go pilnuje.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.99.1'])
            ->post(route('login.two_factor.store'), ['code' => $this->aktualnyKod($sekret)])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    // -----------------------------------------------------------------
    // Blokada /admin bez 2FA
    // -----------------------------------------------------------------

    public function test_moderator_bez_2fa_nie_wchodzi_do_panelu(): void
    {
        // Świadomie NIE `$this->moderator()` — ten skrót (TestCase) domyślnie
        // WŁĄCZA 2FA, żeby setki innych testów panelu moderacji nie padały
        // na tę samą blokadę, którą właśnie tu sprawdzamy. Tu chcemy
        // dokładnie odwrotnego stanu konta.
        $moderator = $this->user(null, ['role' => User::ROLE_MODERATOR]);

        $response = $this->actingAs($moderator)->get(route('admin.reports'));

        $response->assertForbidden();

        // NA TREŚCI EKRANU (pułapka 1b): `pages/admin/wymagane_2fa.blade.php`
        // ma to zdanie także w `title=`, więc asercja na całej odpowiedzi
        // przechodziła również wtedy, gdy ze strony znikał JEDYNY przycisk
        // mówiący moderatorowi, co ma zrobić. Zmierzone 12.09.2026.
        $this->assertStringContainsString(
            'Włącz weryfikację dwuetapową',
            $this->trescEkranu((string) $response->getContent()),
        );
    }

    public function test_moderator_z_2fa_wchodzi_do_panelu(): void
    {
        // `$this->moderator()` (TestCase) domyślnie zwraca konto z JUŻ
        // potwierdzonym 2FA — to jest jego produkcyjny, oczekiwany stan.
        $moderator = $this->moderator();

        $this->actingAs($moderator)->get(route('admin.reports'))->assertOk();
    }

    public function test_zwykly_uzytkownik_dalej_dostaje_404_a_nie_403(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->get(route('admin.reports'))->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Wyłączenie wymaga hasła
    // -----------------------------------------------------------------

    public function test_wylaczenie_wymaga_poprawnego_hasla(): void
    {
        $basia = $this->user('basia');
        $this->wlacz2fa($basia);

        $this->actingAs($basia)
            ->post(route('settings.two_factor.disable'), ['password' => 'zle-haslo'])
            ->assertSessionHasErrorsIn('disable', ['password']);

        $this->assertTrue($basia->refresh()->hasTwoFactorConfirmed());
    }

    public function test_wylaczenie_z_poprawnym_haslem_dziala(): void
    {
        $basia = $this->user('basia');
        $this->wlacz2fa($basia);

        $this->actingAs($basia)
            ->post(route('settings.two_factor.disable'), ['password' => 'haslo-testowe-123'])
            ->assertRedirect(route('settings.two_factor.edit'));

        $this->assertFalse($basia->refresh()->hasTwoFactorConfirmed());
    }

    // -----------------------------------------------------------------
    // Sekret i kody zapasowe zaszyfrowane w bazie
    // -----------------------------------------------------------------

    public function test_sekret_i_kody_zapasowe_sa_zaszyfrowane_w_bazie(): void
    {
        $basia = $this->user('basia');
        $sekret = $this->wlacz2fa($basia);

        $surowyWiersz = DB::table('users')->where('id', $basia->getKey())->first();

        $this->assertNotSame($sekret, $surowyWiersz->two_factor_secret);
        $this->assertStringNotContainsString($sekret, (string) $surowyWiersz->two_factor_secret);
        $this->assertStringNotContainsString('ABCD-1234', (string) $surowyWiersz->two_factor_backup_codes);
    }

    // -----------------------------------------------------------------
    // Nowe kody zapasowe bez zdejmowania 2FA
    // -----------------------------------------------------------------

    public function test_nowe_kody_zapasowe_nie_wylaczaja_2fa_i_uniewazniaja_stare(): void
    {
        // POWÓD, DLA KTÓREGO TO W OGÓLE ISTNIEJE
        // Kody pokazujemy raz. Kto zgubił kartkę, miał jedną drogę do nowych:
        // zdjąć 2FA i włączyć od zera. To zdejmowało ochronę z konta na czas
        // przeklikania, odbierało moderatorowi wejście do panelu i kazało
        // przepisywać sekret do telefonu jeszcze raz — choć z sekretem nic
        // nie było nie tak.
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $sekret = $this->totp()->generateSecret();
        $basia->beginTwoFactorSetup($sekret);
        $basia->confirmTwoFactor($this->totp()->hashBackupCodes(['ABCD-1234']));
        $basia->refresh();

        $odpowiedz = $this->actingAs($basia)->post(route('settings.two_factor.regenerate'), [
            'password' => 'haslo-testowe-123',
        ]);

        $odpowiedz->assertRedirect(route('settings.two_factor.codes'));
        $odpowiedz->assertSessionHas('kody_zapasowe');

        $swiezy = $basia->fresh();

        // 2FA ZOSTAJE WŁĄCZONE, a sekret ten sam — telefonu nie trzeba ruszać.
        $this->assertTrue($swiezy->hasTwoFactorConfirmed());
        $this->assertSame($sekret, $swiezy->two_factor_secret);

        // Stary kod przestał działać w tej samej chwili. O to właśnie chodzi:
        // powodem wymiany bywa „kartka gdzieś jest, tylko nie wiem gdzie".
        $this->post(route('logout'));
        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);
        $this->post(route('login.two_factor.store'), ['backup_code' => 'ABCD-1234'])
            ->assertSessionHasErrors('backup_code');
        $this->assertGuest();
    }

    public function test_nowy_kod_zapasowy_z_wymiany_naprawde_loguje(): void
    {
        // Bez tego testu poprzedni dowodziłby tylko, że stare kody padły —
        // a konto bez działających kodów jest w gorszym stanie niż przed
        // wymianą, nie w lepszym.
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $basia->beginTwoFactorSetup($this->totp()->generateSecret());
        $basia->confirmTwoFactor($this->totp()->hashBackupCodes(['ABCD-1234']));
        $basia->refresh();

        $kody = $this->actingAs($basia)
            ->post(route('settings.two_factor.regenerate'), ['password' => 'haslo-testowe-123'])
            ->assertRedirect()
            ->getSession()
            ->get('kody_zapasowe');

        $this->assertIsArray($kody);
        $this->assertNotEmpty($kody);

        $this->post(route('logout'));
        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);
        $this->post(route('login.two_factor.store'), ['backup_code' => $kody[0]])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($basia->fresh());
    }

    public function test_nowe_kody_zapasowe_wymagaja_hasla(): void
    {
        // Kody zapasowe OMIJAJĄ aplikację w telefonie, więc świeży komplet
        // w rękach kogoś, kto akurat siedzi przy otwartej sesji, jest wart
        // dokładnie tyle co zdjęcie 2FA. Stąd to samo pytanie o hasło.
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $basia->beginTwoFactorSetup($this->totp()->generateSecret());
        $basia->confirmTwoFactor($this->totp()->hashBackupCodes(['ABCD-1234']));
        $basia->refresh();

        $this->actingAs($basia)
            ->post(route('settings.two_factor.regenerate'), ['password' => 'nie-to-haslo'])
            ->assertSessionHasErrorsIn('regenerate', ['password']);

        // Stary kod dalej działa — nic się nie zmieniło.
        $this->post(route('logout'));
        $this->post('/login', ['login' => 'basia@example.com', 'password' => 'haslo-testowe-123']);
        $this->post(route('login.two_factor.store'), ['backup_code' => 'ABCD-1234'])
            ->assertRedirect(route('home'));
    }
}

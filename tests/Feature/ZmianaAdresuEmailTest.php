<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\LoginLinkToken;
use App\Models\PendingEmailChange;
use App\Models\User;
use App\Notifications\PotwierdzenieNowegoAdresu;
use App\Notifications\UstawienieNowegoHasla;
use App\Notifications\ZgloszonaZmianaAdresu;
use App\Support\AdresEmail;
use App\Support\OdzyskiwalneDane;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zmiana adresu e-mail w ustawieniach (issue #195).
 *
 * ZGŁOSZENIE, KTÓRE TO WYWOŁAŁO
 * Zalogowany człowiek nie widział NIGDZIE własnego adresu i nie miał jak go
 * poprawić — a reset hasła idzie właśnie na ten adres. Literówka przy
 * rejestracji znaczyła więc konto bez drogi powrotu, i to od dnia,
 * w którym poczta zaczęła realnie wysyłać listy.
 *
 * NAJWAŻNIEJSZY TEST W TYM PLIKU to
 * `test_niepotwierdzony_nowy_adres_nie_zastepuje_starego`. Cała reszta drogi
 * (hasło, ostrzeżenie, limit, audyt) jest po to, żeby tamta jedna reguła
 * miała sens: DO KLIKNIĘCIA W LINK obowiązuje stary adres.
 */
class ZmianaAdresuEmailTest extends TestCase
{
    use RefreshDatabase;

    private const HASLO = 'haslo-testowe-123';

    protected function setUp(): void
    {
        parent::setUp();

        // Suita chodzi na sterowniku `array`, czyli takim, który NIE
        // dostarcza — a ekran zmiany adresu świadomie nie zaczyna zmiany,
        // gdy poczta nie działa (ten sam wybór co w „Nie pamiętam hasła",
        // `ResetHaslaMowiPrawdeTest`). Testy sprawdzają zachowanie
        // produkcyjne, więc udajemy działającego dostawcę.
        config(['mail.default' => 'smtp']);
    }

    private function basia(): User
    {
        return $this->user('basia', ['email' => 'basia@example.test']);
    }

    /** Zamawia zmianę i zwraca oczekujące żądanie. */
    private function zamow(User $user, string $nowy = 'nowa.basia@example.test'): PendingEmailChange
    {
        $this->actingAs($user)->post(route('settings.email.request'), [
            'current_password' => self::HASLO,
            'email' => $nowy,
        ])->assertRedirect(route('settings.email'));

        $zmiana = PendingEmailChange::query()->where('user_id', $user->getKey())->first();

        $this->assertNotNull($zmiana, 'Żądanie zmiany adresu nie powstało.');

        return $zmiana;
    }

    private function link(PendingEmailChange $zmiana): string
    {
        return URL::signedRoute('settings.email.confirm', ['zmiana' => $zmiana->getKey()]);
    }

    // -----------------------------------------------------------------
    //  Widoczność adresu — sedno zgłoszenia
    // -----------------------------------------------------------------

    public function test_zalogowany_widzi_swoj_adres_bez_zamawiania_eksportu(): void
    {
        $basia = $this->basia();

        $this->actingAs($basia)
            ->get(route('settings.email'))
            ->assertOk()
            ->assertSee('basia@example.test');
    }

    public function test_ekran_mowi_czy_adres_jest_potwierdzony_i_daje_wyslac_ponownie(): void
    {
        $basia = $this->basia();

        $basia->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($basia->fresh())
            ->get(route('settings.email'))
            ->assertOk()
            ->assertSee('nie jest jeszcze potwierdzony')
            ->assertSee(route('verification.send'));

        $basia->forceFill(['email_verified_at' => now()])->save();

        $this->actingAs($basia->fresh())
            ->get(route('settings.email'))
            ->assertOk()
            ->assertSee('Adres jest potwierdzony');
    }

    public function test_gosc_nie_wchodzi_na_ekran_adresu(): void
    {
        $this->get(route('settings.email'))->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------
    //  Reguła nadrzędna: nowy adres obowiązuje DOPIERO po potwierdzeniu
    // -----------------------------------------------------------------

    public function test_niepotwierdzony_nowy_adres_nie_zastepuje_starego(): void
    {
        Notification::fake();

        $basia = $this->basia();

        $this->zamow($basia);

        // 1. Konto ma nadal STARY adres.
        $this->assertSame('basia@example.test', $basia->fresh()->email);

        // 2. Logowanie działa na STARYM adresie, a na nowym nie.
        $this->assertTrue($basia->fresh()->is(User::findByLogin('basia@example.test')));
        $this->assertNull(User::findByLogin('nowa.basia@example.test'));

        // „Nie pamiętam hasła" to droga dla NIEZALOGOWANEGO (grupa `guest`),
        // a `zamow()` wyżej zostawia sesję Basi.
        Auth::logout();

        // 3. „Nie pamiętam hasła" na NOWY adres nie wysyła niczego — nie ma
        //    jeszcze konta pod tym adresem, więc nie ma komu wysłać.
        $this->post(route('password.email'), ['email' => 'nowa.basia@example.test']);
        Notification::assertNotSentTo($basia, UstawienieNowegoHasla::class);

        // 4. …a na STARY adres wysyła. To jest cała reguła: do kliknięcia
        //    w link droga powrotu na konto biegnie starym adresem.
        $this->post(route('password.email'), ['email' => 'basia@example.test']);
        Notification::assertSentTo($basia, UstawienieNowegoHasla::class);
    }

    public function test_samo_wyslanie_formularza_nie_przejmuje_konta(): void
    {
        Notification::fake();

        $basia = $this->basia();

        // Napastnik siedzi w cudzej, niezablokowanej przeglądarce i zna
        // adres formularza — ale hasła nie zna.
        $this->actingAs($basia)->post(route('settings.email.request'), [
            'current_password' => 'zgaduje-haslo',
            'email' => 'napastnik@example.test',
        ])->assertSessionHasErrors('current_password');

        $this->assertSame('basia@example.test', $basia->fresh()->email);
        $this->assertSame(0, PendingEmailChange::count());
        Notification::assertNothingSent();
    }

    public function test_potwierdzenie_linkiem_zmienia_adres_i_potwierdza_go(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $zmiana = $this->zamow($basia);

        $this->actingAs($basia)
            ->get($this->link($zmiana))
            ->assertRedirect(route('settings.email'));

        $swiezy = $basia->fresh();

        $this->assertSame('nowa.basia@example.test', $swiezy->email);
        $this->assertTrue($swiezy->hasVerifiedEmail(), 'Kliknięcie w link jest dowodem dostępu do skrzynki.');
        $this->assertSame(0, PendingEmailChange::count(), 'Żądanie ma zniknąć po potwierdzeniu.');
    }

    /**
     * Regresja #979: potwierdzenie nowego adresu nie unieważniało niczego.
     * Link logowania wysłany na STARY adres dalej otwierał konto, a sesja
     * na obcym urządzeniu żyła dalej — choć adres zmienia się zwykle
     * właśnie wtedy, gdy stara skrzynka przestała być tylko nasza.
     */
    public function test_potwierdzenie_nowego_adresu_uniewaznia_link_logowania_i_inne_sesje(): void
    {
        Notification::fake();
        config(['session.driver' => 'database']);

        $basia = $this->basia();

        // Bieżąca przeglądarka — ta, w której Basia zamawia i klika link.
        $biezaca = Str::random(40);
        foreach ([$biezaca => 'biezace-urzadzenie', 'cudza-sesja' => 'cudze-urzadzenie'] as $id => $urzadzenie) {
            DB::table('sessions')->insert([
                'id' => $id,
                'user_id' => $basia->getKey(),
                'ip_address' => '127.0.0.1',
                'user_agent' => $urzadzenie,
                'payload' => '',
                'last_activity' => time(),
            ]);
        }
        $this->withCookie((string) config('session.cookie'), $biezaca);

        $zmiana = $this->zamow($basia);

        // Link logowania czekający w STAREJ skrzynce.
        $link = new LoginLinkToken;
        $link->user_id = $basia->getKey();
        $link->token_hash = LoginLinkToken::skrot(Str::random(40));
        $link->created_at = now();
        $link->expires_at = now()->addMinutes(30);
        $link->save();

        // Link do ustawienia hasła czekający w STAREJ skrzynce — ta tabela
        // jest kluczowana adresem, nie kontem.
        $tokenResetu = Password::broker()->createToken($basia);
        $this->assertTrue(Password::broker()->tokenExists($basia, $tokenResetu), 'Kontrola dodatnia: token resetu istnieje.');

        $tokenPrzed = $basia->fresh()->remember_token;

        $this->actingAs($basia)
            ->get($this->link($zmiana))
            ->assertRedirect(route('settings.email'))
            ->assertSessionHas('status', fn (string $s): bool => str_contains($s, 'Wylogowaliśmy wszystkie inne urządzenia'));

        $this->assertSame('nowa.basia@example.test', $basia->fresh()->email);
        $this->assertSame(0, LoginLinkToken::query()->where('user_id', $basia->getKey())->count(),
            'Link logowania wysłany na stary adres ma przestać działać.');
        $this->assertDatabaseMissing('sessions', ['id' => 'cudza-sesja']);
        $this->assertNotSame($tokenPrzed, $basia->fresh()->remember_token,
            'Ciasteczko „zapamiętaj mnie" z innego urządzenia ma przestać działać.');

        $this->assertSame(0, DB::table('password_reset_tokens')->whereRaw('lower(email) = ?', ['basia@example.test'])->count(),
            'Link do ustawienia hasła wysłany na stary adres ma przestać działać.');

        // Osoba, która właśnie potwierdziła adres, nie zostaje wylogowana —
        // ale jej sesja dostaje NOWY identyfikator, a stary nie działa.
        $this->assertDatabaseMissing('sessions', ['id' => $biezaca]);
        $this->assertSame(1, DB::table('sessions')->where('user_id', $basia->getKey())->count(),
            'Bieżąca przeglądarka ma zostać zalogowana pod nowym identyfikatorem sesji.');
        $this->get(route('settings.email'))->assertOk();
    }

    public function test_link_dziala_dokladnie_raz(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $zmiana = $this->zamow($basia);
        $link = $this->link($zmiana);

        $this->actingAs($basia)->get($link)->assertRedirect(route('settings.email'));

        // Drugie wejście nie ma już czego potwierdzać i nie może się wywalić
        // pięćsetką w twarz człowiekowi, który dwa razy kliknął w list.
        $this->actingAs($basia->fresh())
            ->get($link)
            ->assertRedirect(route('settings.email'))
            ->assertSessionHas('status');
    }

    // -----------------------------------------------------------------
    //  Listy
    // -----------------------------------------------------------------

    public function test_list_z_linkiem_idzie_na_nowy_adres_a_ostrzezenie_na_stary(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $this->zamow($basia);

        Notification::assertSentOnDemand(
            PotwierdzenieNowegoAdresu::class,
            static fn ($powiadomienie, array $kanaly, AnonymousNotifiable $adresat): bool => $adresat->routes['mail'] === 'nowa.basia@example.test',
        );

        // Ostrzeżenie idzie „na adres" utrwalony w chwili prośby, a NIE
        // `notify()` do konta — to czytałoby `users.email` dopiero przy
        // wysyłce, czyli po potwierdzeniu już nowy (#888).
        Notification::assertSentOnDemand(
            ZgloszonaZmianaAdresu::class,
            static fn ($powiadomienie, array $kanaly, AnonymousNotifiable $adresat): bool => $adresat->routes['mail'] === 'basia@example.test',
        );
        Notification::assertNotSentTo($basia, ZgloszonaZmianaAdresu::class);
    }

    public function test_ostrzezenie_pokazuje_nowy_adres_w_skrocie(): void
    {
        $basia = $this->basia();

        $powiadomienie = new ZgloszonaZmianaAdresu(
            AdresEmail::maska('napastnik-zadzwon-pod-500600700@example.test'),
            now()->addDay(),
        );

        $tresc = (string) $powiadomienie->toMail($basia)->render();

        $this->assertStringContainsString('n***@example.test', $tresc);
        $this->assertStringNotContainsString(
            'zadzwon-pod-500600700',
            $tresc,
            'Część adresu przed @ wpisuje żądający — w całości zamieniłaby ten list w tablicę ogłoszeń napastnika.',
        );
    }

    // -----------------------------------------------------------------
    //  Wygasanie, anulowanie, hasło
    // -----------------------------------------------------------------

    public function test_wygasly_link_nie_zmienia_adresu_i_mowi_co_zrobic(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $zmiana = $this->zamow($basia);
        $link = $this->link($zmiana);

        $this->travel((int) config('kuking.account.email_change_ttl_hours') + 1)->hours();

        $this->actingAs($basia)
            ->get($link)
            ->assertRedirect(route('settings.email'))
            ->assertSessionHasErrors('email');

        $this->assertSame('basia@example.test', $basia->fresh()->email);
    }

    public function test_wygasle_zadanie_nie_wisi_na_ekranie(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $this->zamow($basia);

        $this->travel((int) config('kuking.account.email_change_ttl_hours') + 1)->hours();

        $this->actingAs($basia)
            ->get(route('settings.email'))
            ->assertOk()
            ->assertDontSee('Zmiana adresu czeka na potwierdzenie');
    }

    public function test_komenda_sprzata_wygasle_zadania(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $this->zamow($basia);

        $this->artisan('kuking:sprzataj-zmiany-adresu')->assertSuccessful();
        $this->assertSame(1, PendingEmailChange::count(), 'Ważnego żądania nie wolno skasować.');

        $this->travel((int) config('kuking.account.email_change_ttl_hours') + 1)->hours();

        $this->artisan('kuking:sprzataj-zmiany-adresu')->assertSuccessful();
        $this->assertSame(0, PendingEmailChange::count());
    }

    public function test_anulowanie_uniewaznia_link(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $zmiana = $this->zamow($basia);
        $link = $this->link($zmiana);

        $this->actingAs($basia)->post(route('settings.email.cancel'))->assertRedirect(route('settings.email'));

        $this->assertSame(0, PendingEmailChange::count());

        $this->actingAs($basia)->get($link)->assertRedirect(route('settings.email'));
        $this->assertSame('basia@example.test', $basia->fresh()->email);
    }

    /**
     * TO JEST TEST OBIETNICY Z LISTU OSTRZEGAWCZEGO.
     *
     * List na stary adres mówi „jeśli to nie Ty — zmień hasło". Gdyby zmiana
     * hasła nie kasowała oczekującego żądania, ta rada byłaby nieprawdziwa:
     * napastnik dokończyłby przejęcie konta swoim odnośnikiem mimo nowego
     * hasła.
     */
    public function test_zmiana_hasla_uniewaznia_oczekujaca_zmiane_adresu(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $zmiana = $this->zamow($basia);
        $link = $this->link($zmiana);

        $this->actingAs($basia)->put(route('settings.security.password'), [
            'current_password' => self::HASLO,
            'password' => 'zielonapietruszkarano2026',
            'password_confirmation' => 'zielonapietruszkarano2026',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, PendingEmailChange::count());

        $this->actingAs($basia->fresh())->get($link);
        $this->assertSame('basia@example.test', $basia->fresh()->email);
    }

    public function test_reset_hasla_uniewaznia_oczekujaca_zmiane_adresu(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $this->zamow($basia);

        $token = Password::createToken($basia);

        // Reset hasła to droga DLA NIEZALOGOWANEGO — `zamow()` wyżej zostawia
        // sesję Basi, a trasa stoi w grupie `guest`.
        Auth::logout();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'basia@example.test',
            'password' => 'zielonapietruszkarano2026',
            'password_confirmation' => 'zielonapietruszkarano2026',
        ])->assertRedirect(route('login'));

        $this->assertSame(0, PendingEmailChange::count());
    }

    // -----------------------------------------------------------------
    //  Adres zajęty przez inne konto — bez wyjawiania, że takie konto jest
    // -----------------------------------------------------------------

    public function test_formularz_odpowiada_tak_samo_na_adres_wolny_i_zajety(): void
    {
        Notification::fake();

        $this->user('marek', ['email' => 'marek@example.test']);
        $basia = $this->basia();

        $wolny = $this->actingAs($basia)->post(route('settings.email.request'), [
            'current_password' => self::HASLO,
            'email' => 'nikt@example.test',
        ]);

        $zajety = $this->actingAs($basia)->post(route('settings.email.request'), [
            'current_password' => self::HASLO,
            'email' => 'marek@example.test',
        ]);

        $wolny->assertRedirect(route('settings.email'))->assertSessionHasNoErrors();
        $zajety->assertRedirect(route('settings.email'))->assertSessionHasNoErrors();

        $this->assertSame(
            $wolny->getSession()->get('status'),
            $zajety->getSession()->get('status'),
            'Odpowiedź różni się dla adresu zajętego i wolnego — formularz zamienia się w wyrocznię '
            .'„kto ma konto w Kuking".',
        );
    }

    public function test_zajety_adres_odbija_sie_dopiero_przy_potwierdzeniu(): void
    {
        Notification::fake();

        $marek = $this->user('marek', ['email' => 'marek@example.test']);
        $basia = $this->basia();

        $zmiana = $this->zamow($basia, 'marek@example.test');

        $this->actingAs($basia)
            ->get($this->link($zmiana))
            ->assertRedirect(route('settings.email'))
            ->assertSessionHasErrors('email');

        $this->assertSame('basia@example.test', $basia->fresh()->email);
        $this->assertSame('marek@example.test', $marek->fresh()->email);
    }

    public function test_wlasny_obecny_adres_odbija_sie_od_razu(): void
    {
        Notification::fake();

        $basia = $this->basia();

        // Tu wolno powiedzieć wprost, czyj to adres — jest jego własny.
        $this->actingAs($basia)->post(route('settings.email.request'), [
            'current_password' => self::HASLO,
            'email' => 'BASIA@example.test',
        ])->assertSessionHasErrors('email');

        $this->assertSame(0, PendingEmailChange::count());
        Notification::assertNothingSent();
    }

    // -----------------------------------------------------------------
    //  Autoryzacja i podpis
    // -----------------------------------------------------------------

    public function test_link_bez_podpisu_nie_dziala(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $zmiana = $this->zamow($basia);

        $this->actingAs($basia)
            ->get(route('settings.email.confirm', ['zmiana' => $zmiana->getKey()]))
            ->assertForbidden();

        $this->assertSame('basia@example.test', $basia->fresh()->email);
    }

    public function test_identyfikator_zadania_nie_jest_autoryzacja(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $marek = $this->user('marek', ['email' => 'marek@example.test']);

        $zmiana = $this->zamow($basia);

        // Marek ma prawidłowo podpisany link Basi — i to mu nic nie daje.
        $this->actingAs($marek)
            ->get($this->link($zmiana))
            ->assertRedirect(route('settings.email'));

        $this->assertSame('basia@example.test', $basia->fresh()->email);
        $this->assertSame('marek@example.test', $marek->fresh()->email);
        $this->assertSame(1, PendingEmailChange::count(), 'Cudze żądanie zostaje nietknięte.');
    }

    // -----------------------------------------------------------------
    //  Ograniczenie tempa i dziennik audytu
    // -----------------------------------------------------------------

    public function test_zamawianie_zmiany_ma_limit_zapytan(): void
    {
        Notification::fake();

        $basia = $this->basia();

        [$prob] = explode(',', (string) config('kuking.limits.confirm_password'));

        for ($i = 0; $i < (int) $prob; $i++) {
            $this->actingAs($basia)->post(route('settings.email.request'), [
                'current_password' => 'zgaduje',
                'email' => "proba{$i}@example.test",
            ]);
        }

        $this->actingAs($basia)->post(route('settings.email.request'), [
            'current_password' => 'zgaduje',
            'email' => 'jeszcze-jedna@example.test',
        ])->assertStatus(429);
    }

    public function test_zmiana_adresu_zostawia_slad_w_dzienniku_audytu(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $zmiana = $this->zamow($basia);

        $this->assertTrue(
            AuditLogEntry::query()
                ->where('action', 'account.email_change_requested')
                ->where('actor_id', $basia->getKey())
                ->exists(),
        );

        $this->actingAs($basia)->get($this->link($zmiana));

        $wpis = AuditLogEntry::query()
            ->where('action', 'account.email_changed')
            ->where('actor_id', $basia->getKey())
            ->first();

        $this->assertNotNull($wpis);

        // Dziennik notuje FAKT, nie treść: adresy tylko w skrócie.
        $this->assertSame('n***@example.test', $wpis->metadata['nowy_adres_skrot']);
        $this->assertSame('b***@example.test', $wpis->metadata['stary_adres_skrot']);
    }

    // -----------------------------------------------------------------
    //  UX 50+
    // -----------------------------------------------------------------

    public function test_poprawnie_wpisany_adres_nie_znika_przy_bledzie_hasla(): void
    {
        $basia = $this->basia();

        $this->actingAs($basia)
            ->from(route('settings.email'))
            ->post(route('settings.email.request'), [
                'current_password' => 'zle-haslo',
                'email' => 'nowa.basia@example.test',
            ])
            ->assertRedirect(route('settings.email'));

        // Adres wraca do pola…
        $this->actingAs($basia)
            ->get(route('settings.email'))
            ->assertOk()
            ->assertSee('value="nowa.basia@example.test"', escape: false);

        // …a hasło NIE. Pole `password` nigdy nie wraca z wartością
        // (audyt W7-03, `x-field`).
        $this->assertNull(session('_old_input.current_password'));
    }

    public function test_bez_dzialajacej_poczty_nie_obiecujemy_listu(): void
    {
        config(['mail.default' => 'log']);

        $basia = $this->basia();

        $this->actingAs($basia)
            ->get(route('settings.email'))
            ->assertOk()
            ->assertSee('Nie wysyłamy jeszcze wiadomości e-mail');

        $this->actingAs($basia)->post(route('settings.email.request'), [
            'current_password' => self::HASLO,
            'email' => 'nowa.basia@example.test',
        ])->assertRedirect();

        $this->assertSame(0, PendingEmailChange::count());
    }

    /**
     * FORMULARZ Z HASŁEM NIE MA PRAWA TRAFIĆ NA LISTĘ TRAS ODZYSKIWANYCH.
     *
     * `App\Support\OdzyskiwalneDane` działa na zgodę po NAZWIE TRASY, nie na
     * zakaz po nazwie pola — i ma w komentarzu wprost, że tras z hasłem na
     * tej liście być nie może. Ten formularz niesie `current_password`, więc
     * dopisanie go tam odłożyłoby hasło do treści odpowiedzi ekranu 419/429.
     *
     * Adres e-mail przeżywa nieudaną walidację inną drogą: przez zwykły
     * `old()` z `ValidationException`, która odkłada wejście pomniejszone
     * o pola haseł. Sprawdza to test wyżej.
     */
    public function test_formularz_zmiany_adresu_nie_jest_trasa_odzyskiwana(): void
    {
        foreach (['settings.email.request', 'settings.email.cancel'] as $nazwa) {
            $trasa = app('router')->getRoutes()->getByName($nazwa);

            $zadanie = Request::create('/', 'POST', ['current_password' => 'tajne']);
            $zadanie->setRouteResolver(static fn () => $trasa);

            $this->assertFalse(
                OdzyskiwalneDane::wolnoOdzyskac($zadanie),
                "Trasa {$nazwa} trafiła na listę tras odzyskiwanych, a niesie hasło.",
            );
            $this->assertSame([], OdzyskiwalneDane::zZadania($zadanie));
        }
    }

    /**
     * ZAWIESZENIE JEST KARĄ ZA PISANIE, NIE ZA POSIADANIE KONTA.
     *
     * Ta sama zasada, dla której zawieszone konto może pobrać swoje dane
     * (RODO art. 15 i 20) i złożyć odwołanie (DSA art. 20): sprostowanie
     * danych osobowych to art. 16, a adres e-mail jest jedyną drogą powrotu
     * na konto — także po zakończeniu kary.
     */
    public function test_zawieszone_konto_moze_poprawic_swoj_adres(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $basia->suspend(now()->addWeek());

        $this->actingAs($basia->fresh())->post(route('settings.email.request'), [
            'current_password' => self::HASLO,
            'email' => 'nowa.basia@example.test',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, PendingEmailChange::count());
    }

    public function test_ekran_dziala_bez_javascriptu(): void
    {
        Notification::fake();

        $basia = $this->basia();
        $this->zamow($basia);

        $html = (string) $this->actingAs($basia)->get(route('settings.email'))->getContent();

        // Każda droga na tym ekranie to zwykły formularz POST albo odnośnik.
        $this->assertStringContainsString('<form method="POST" action="'.route('settings.email.request').'"', $html);
        $this->assertStringContainsString('<form method="POST" action="'.route('settings.email.cancel').'"', $html);
        $this->assertStringNotContainsString('wire:', $html);
        $this->assertStringNotContainsString('onclick=', $html);
    }
}

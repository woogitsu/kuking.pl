<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\ZaproszenieWSesji;
use App\Models\RegistrationInvite;
use App\Models\User;
use App\Notifications\PotwierdzenieAdresu;
use App\Turnstile\KlientTurnstile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Zakładanie konta z zaproszenia — WEJŚCIE DO KONTA, czyli powierzchnia
 * bezpieczeństwa (D-085).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO TA DROGA ROZDAJE I DLACZEGO TRZEBA JEJ PILNOWAĆ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Konto założone z zaproszenia powstaje z `email_verified_at` USTAWIONYM
 * i BEZ drugiej wiadomości weryfikacyjnej. Cała ta ulga stoi na jednym
 * założeniu: ktoś kliknął link ZE SWOJEJ skrzynki, więc jest jej
 * właścicielem. Każde miejsce, w którym dałoby się to obejść, daje konto
 * z „potwierdzonym" adresem, którego nikt nigdy nie potwierdził — a stamtąd
 * wystarczy „nie pamiętam hasła", żeby wejść na cudzą skrzynkę pocztową
 * jako drogę do cudzego konta.
 *
 * Dlatego ten plik pilnuje przede wszystkim rzeczy, które NIE MAJĄ prawa
 * zadziałać:
 *
 *  1. SAMO POSIADANIE TOKENU NIE JEST UPRAWNIENIEM. Token z adresu otwiera
 *     ekran, a nie konto — o adresie konta rozstrzyga WIERSZ W BAZIE
 *     wskazany przez sesję (AGENTS.md: „UUID w adresie to nie autoryzacja").
 *  2. ADRESU NIE DA SIĘ PODMIENIĆ POLEM `email` W FORMULARZU.
 *  3. ZAPROSZENIE JEST JEDNORAZOWE — zużywa je utworzenie konta.
 *  4. `status` I `role` NIE WCHODZĄ MASOWYM PRZYPISANIEM.
 *  5. WYGASŁE ZAPROSZENIE NIE ZAKŁADA KONTA Z POTWIERDZONYM ADRESEM.
 */
class RejestracjaZZaproszeniaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.login_link.zaproszenia.wlaczone' => true,
            'kuking.account.registration_open' => true,
        ]);
    }

    // ------------------------------------------------------------------
    //  Droga szczęśliwa — po co to wszystko powstało
    // ------------------------------------------------------------------

    /**
     * KONTO Z ZAPROSZENIA MA ADRES JUŻ POTWIERDZONY I NIE DOSTAJE DRUGIEGO
     * LISTU.
     *
     * To jest cała obietnica tej drogi, wypisana na ekranie zaproszenia:
     * „Adresu e-mail nie będziemy potwierdzać drugi raz". Do czasu tej
     * poprawki obietnica była nieprawdziwa — `RegisterController` nie wiedział
     * o zaproszeniach w ogóle.
     */
    public function test_konto_z_zaproszenia_ma_adres_potwierdzony_i_bez_drugiego_listu(): void
    {
        Notification::fake();

        $this->zPrzyjetymZaproszeniem('basia@example.com');

        $this->zaloz()->assertRedirect(route('onboarding.interests'));

        $basia = User::query()->where('email', 'basia@example.com')->firstOrFail();

        $this->assertNotNull($basia->email_verified_at,
            'Konto z zaproszenia musi mieć adres potwierdzony — na tym stoi cała ta droga.');

        // Listener Laravela pyta `hasVerifiedEmail()`, więc nie ma czego
        // wysłać. Gdyby list mimo to wyszedł, obietnica z ekranu byłaby
        // fałszywa, a osoba, dla której to powstało, znowu czekałaby na
        // wiadomość — tylko tym razem niepotrzebnie.
        Notification::assertNotSentTo($basia, PotwierdzenieAdresu::class);

        $this->assertAuthenticatedAs($basia);
    }

    /**
     * ZWYKŁA REJESTRACJA DZIAŁA JAK DOTĄD — adres NIEPOTWIERDZONY i list
     * z potwierdzeniem wychodzi. Bez tego testu poprawka mogłaby po cichu
     * rozdawać potwierdzenie wszystkim.
     */
    public function test_rejestracja_bez_zaproszenia_dalej_wymaga_potwierdzenia_adresu(): void
    {
        Notification::fake();

        $this->zaloz(['email' => 'basia@example.com'])->assertRedirect();

        $basia = User::query()->where('email', 'basia@example.com')->firstOrFail();

        $this->assertNull($basia->email_verified_at);
        Notification::assertSentTo($basia, PotwierdzenieAdresu::class);
    }

    // ------------------------------------------------------------------
    //  Token nie jest uprawnieniem
    // ------------------------------------------------------------------

    /**
     * SAMO POSIADANIE TOKENU NIE DAJE NICZEGO — trzeba przejść przez ekran.
     *
     * To jest w tym repozytorium zasada twarda („UUID w adresie to nie
     * autoryzacja"). Tutaj znaczy ona tyle: kto zna token, ale nie przyjął
     * zaproszenia (nie ma go w sesji), rejestruje się jak każdy inny —
     * z adresem z formularza i BEZ potwierdzenia. Token nie jest kluczem do
     * niczego, jest tylko przepustką na ekran.
     */
    public function test_sam_token_bez_przejscia_przez_ekran_nie_daje_potwierdzonego_adresu(): void
    {
        Notification::fake();

        // Zaproszenie istnieje i token jest w ręku — ale nikt go nie przyjął.
        $this->zaproszenie('basia@example.com');

        $this->zaloz(['email' => 'basia@example.com'])->assertRedirect();

        $basia = User::query()->where('email', 'basia@example.com')->firstOrFail();

        $this->assertNull($basia->email_verified_at,
            'Samo istnienie zaproszenia nie ma prawa potwierdzić adresu — potwierdza je przejście przez ekran.');
        Notification::assertSentTo($basia, PotwierdzenieAdresu::class);

        // Zaproszenie leży nietknięte: nic go nie zużyło.
        $this->assertDatabaseCount('registration_invites', 1);
    }

    /**
     * ADRES BIERZE SIĘ Z WIERSZA W BAZIE, NIE Z POLA W FORMULARZU.
     *
     * Najgroźniejszy scenariusz tej drogi: mam zaproszenie na SWÓJ adres,
     * a w formularzu wpisuję CUDZY. Gdyby serwis uwierzył formularzowi,
     * powstałoby konto na cudzej skrzynce, z adresem oznaczonym jako
     * potwierdzony — i z „nie pamiętam hasła" jako dalszym ciągiem.
     */
    public function test_podmiana_adresu_w_formularzu_niczego_nie_zmienia(): void
    {
        Notification::fake();

        $this->zPrzyjetymZaproszeniem('basia@example.com');

        $this->zaloz(['email' => 'ofiara@example.com'])->assertRedirect();

        // Konto powstało na adresie Z ZAPROSZENIA…
        $this->assertDatabaseHas('users', ['email' => 'basia@example.com']);
        // …a na wpisanym nie powstało nic.
        $this->assertDatabaseMissing('users', ['email' => 'ofiara@example.com']);
        $this->assertSame(1, User::query()->count());
    }

    // ------------------------------------------------------------------
    //  Jednorazowość i termin
    // ------------------------------------------------------------------

    /**
     * ZAPROSZENIE ZUŻYWA SIĘ NA UTWORZENIU KONTA — i tylko tam.
     *
     * Nie na ekranie i nie przy przyjęciu do sesji: za tamtymi krokami stoi
     * jeszcze cały formularz, o który ta osoba już raz się odbiła.
     */
    public function test_zaproszenie_zuzywa_sie_dopiero_przy_zalozeniu_konta(): void
    {
        Notification::fake();

        [$token] = $this->zPrzyjetymZaproszeniem('basia@example.com');

        // Po przyjęciu — jeszcze żyje.
        $this->assertDatabaseCount('registration_invites', 1);

        $this->zaloz()->assertRedirect();

        // Po założeniu konta — nie ma go.
        $this->assertDatabaseCount('registration_invites', 0);
        $this->assertNull(RegistrationInvite::znajdzPoTokenie($token));
    }

    /**
     * PONOWNE UŻYCIE ZUŻYTEGO ZAPROSZENIA NIE DZIAŁA — ani ekranem, ani
     * drugim wysłaniem formularza.
     */
    public function test_zuzytego_zaproszenia_nie_da_sie_uzyc_drugi_raz(): void
    {
        Notification::fake();

        [$token] = $this->zPrzyjetymZaproszeniem('basia@example.com');
        $this->zaloz()->assertRedirect();

        // WYLOGOWANIE, bo trasy zaproszenia stoją w grupie `guest` — a pytanie
        // brzmi „czy KTOKOLWIEK użyje tego linku drugi raz", nie „czy użyje go
        // osoba właśnie zalogowana".
        Auth::logout();
        $this->flushSession();

        // 1. Ekran z tym samym linkiem wygląda jak każdy nieaktualny.
        $this->get(route('zaproszenie.pokaz', ['token' => $token]))
            ->assertOk()
            ->assertSee('To zaproszenie już nie działa');

        // 2. Przyjęcie go z powrotem odsyła na zwykłą rejestrację.
        $this->post(route('zaproszenie.przyjmij'), ['token' => $token])
            ->assertRedirect(route('register'));

        $this->assertNull(session(RegistrationInvite::KLUCZ_SESJI));

        // 3. Drugie konto nie powstało.
        $this->assertSame(1, User::query()->count());
    }

    /**
     * WYGASŁE ZAPROSZENIE W SESJI NIE ZAKŁADA KONTA Z POTWIERDZONYM ADRESEM
     * — i NIE ZOSTAWIA CZŁOWIEKA PRZED ŚLEPĄ ŚCIANĄ.
     *
     * Sesja żyje długo, zaproszenie najwyżej dobę. Gdy zaproszenie wygaśnie,
     * `ZaproszenieWSesji::biezace()` oddaje `null` i czyści klucz — formularz
     * zachowuje się wtedy jak ZWYKŁA rejestracja. Konto z potwierdzonym
     * adresem nie ma prawa powstać, a człowiek dostaje polski błąd mówiący,
     * co zrobić, i swoje poprawnie wpisane dane z powrotem
     * (docs/UX_50_PLUS.md). Nie ma ślepej ściany.
     */
    public function test_wygasle_zaproszenie_nie_tworzy_konta_i_nie_gubi_wpisanych_danych(): void
    {
        Notification::fake();

        $this->zPrzyjetymZaproszeniem('basia@example.com');

        // Zaproszenie wygasa MIĘDZY wejściem na formularz a jego wysłaniem.
        RegistrationInvite::query()->update([
            'created_at' => now()->subHours(30),
            'expires_at' => now()->subHours(6),
        ]);

        $odpowiedz = $this->zaloz(['display_name' => 'Basia z Podkarpacia']);

        // Konto nie powstało — ani z potwierdzonym adresem, ani żadne inne.
        $this->assertSame(0, User::query()->count());
        $this->assertGuest();

        // BŁĄD MÓWI, CO ZROBIĆ.
        $odpowiedz->assertSessionHasErrors('email');
        $blad = (string) session('errors')->first('email');
        $this->assertStringContainsString('Podaj swój adres e-mail', $blad);

        // POPRAWNE DANE NIE ZNIKAJĄ Z FORMULARZA. Hasła nie oddajemy nigdy.
        $this->assertSame('Basia z Podkarpacia', old('display_name'));
        $this->assertNull(old('password'));
    }

    // ------------------------------------------------------------------
    //  `status` i `role` — zasada twarda tego repozytorium
    // ------------------------------------------------------------------

    /**
     * `status` I `role` NIE DA SIĘ USTAWIĆ PRZEZ FORMULARZ REJESTRACJI —
     * także na ścieżce z zaproszeniem.
     *
     * Obie kolumny są poza `$fillable` w `User` (AGENTS.md §7), ale ta droga
     * dokłada nowy formularz i nowy zapis konta, więc reguła musi być
     * sprawdzona TUTAJ, a nie tylko na modelu.
     */
    public function test_statusu_i_roli_nie_da_sie_ustawic_przez_ten_formularz(): void
    {
        Notification::fake();

        $this->zPrzyjetymZaproszeniem('basia@example.com');

        $this->zaloz([
            'status' => User::STATUS_BANNED,
            'role' => 'admin',
            'email_verified_at' => now()->toDateTimeString(),
        ])->assertRedirect();

        $basia = User::query()->where('email', 'basia@example.com')->firstOrFail();

        $this->assertNotSame('admin', $basia->role);
        $this->assertNotSame(User::STATUS_BANNED, $basia->status);
    }

    // ------------------------------------------------------------------
    //  Bez JavaScriptu
    // ------------------------------------------------------------------

    /**
     * FORMULARZ REJESTRACJI Z ZAPROSZENIEM DZIAŁA BEZ JAVASCRIPTU.
     *
     * Adres jest pokazany jako tekst (nie pole), a wyjście „chcę konto na
     * inny adres" jest osobnym, zwykłym formularzem POST — nie może być
     * zagnieżdżone w tamtym, bo HTML na to nie pozwala, i nie może być
     * odnośnikiem, bo zmienia stan sesji.
     */
    public function test_formularz_z_zaproszeniem_dziala_bez_javascriptu(): void
    {
        Notification::fake();

        $this->zPrzyjetymZaproszeniem('basia@example.com');

        $html = (string) $this->get(route('register'))->assertOk()->getContent();

        // Adres widać, ale NIE jest polem do wpisania — inaczej `readonly`
        // udawałoby zabezpieczenie.
        $this->assertStringContainsString('basia@example.com', $html);
        $this->assertStringNotContainsString('name="email"', $html);

        $porzucenie = $this->formularzZAkcja($html, route('zaproszenie.porzuc'));

        $this->assertStringContainsString('method="POST"', $porzucenie);
        $this->assertStringContainsString('name="_token"', $porzucenie);
        $this->assertStringContainsString('type="submit"', $porzucenie);
        $this->assertStringNotContainsString('<script', $porzucenie);
        $this->assertStringNotContainsString('onclick', $porzucenie);
    }

    /**
     * PO PORZUCENIU ZAPROSZENIA WRACA ZWYKŁE POLE NA ADRES — droga wyjścia
     * naprawdę prowadzi z powrotem do zwykłej rejestracji, a nie donikąd.
     */
    public function test_po_porzuceniu_zaproszenia_wraca_zwykle_pole_na_adres(): void
    {
        Notification::fake();

        $this->zPrzyjetymZaproszeniem('basia@example.com');
        $this->post(route('zaproszenie.porzuc'));

        $html = (string) $this->get(route('register'))->assertOk()->getContent();

        $this->assertStringContainsString('name="email"', $html);
        $this->assertStringNotContainsString('basia@example.com', $html);
    }

    // ------------------------------------------------------------------
    //  Zabezpieczenie, które nie ma prawa milczeć
    // ------------------------------------------------------------------

    /*
     * CZEGO TU ŚWIADOMIE NIE MA: testu na `LogicException` przy zużyciu
     * zaproszenia POZA transakcją.
     *
     * `RefreshDatabase` owija KAŻDY test we własną transakcję, więc
     * `DB::transactionLevel()` jest w testach zawsze większe od zera i tego
     * warunku nie da się tutaj wywołać. Test, który by go „sprawdzał",
     * przechodziłby także po usunięciu całego zabezpieczenia — czyli byłby
     * kontrolą pozorną. Zabezpieczenie zostaje w kodzie
     * (`ZaproszenieWSesji::zuzyj()`), bo chroni przed przyszłym wołającym
     * spoza transakcji; sprawdzone jest to, co sprawdzić się da: że w
     * transakcji zużycie działa i że wygasłego zaproszenia nie zużyje.
     */

    /**
     * A W TRANSAKCJI — zużywa i oddaje adres Z WIERSZA.
     * Wołający nie ma po co znać tego adresu skądkolwiek indziej.
     */
    public function test_zuzycie_w_transakcji_oddaje_adres_z_wiersza(): void
    {
        [, $zaproszenie] = $this->zaproszenie('basia@example.com');

        $adres = DB::transaction(fn () => app(ZaproszenieWSesji::class)->zuzyj($zaproszenie));

        $this->assertSame('basia@example.com', $adres);
        $this->assertDatabaseCount('registration_invites', 0);
    }

    /**
     * WYGASŁEGO ZAPROSZENIA NIE ZUŻYJE NAWET TRANSAKCJA ZAKŁADAJĄCA KONTO.
     *
     * To jest ta gałąź, która musi wycofać całe zakładanie konta: konto
     * z potwierdzonym adresem bez ważnego dowodu posiadania skrzynki nie ma
     * prawa powstać. Martwy wiersz przy okazji znika.
     */
    public function test_wygasle_zaproszenie_nie_daje_sie_zuzyc(): void
    {
        [, $zaproszenie] = $this->zaproszenie('basia@example.com');

        RegistrationInvite::query()->update([
            'created_at' => now()->subHours(30),
            'expires_at' => now()->subHours(6),
        ]);

        $adres = DB::transaction(fn () => app(ZaproszenieWSesji::class)->zuzyj($zaproszenie->refresh()));

        $this->assertNull($adres);
        $this->assertDatabaseCount('registration_invites', 0);
    }

    /** Kontrolowany przeplot w jednym żądaniu, nie pomiar współbieżności. */
    public function test_wygasniecie_podczas_rejestracji_przypomina_o_hasle_i_zachowuje_dane(): void
    {
        Notification::fake();
        [, $invite] = $this->zPrzyjetymZaproszeniem('basia@example.com');
        config(['kuking.turnstile.klucz_publiczny' => 'test-publiczny', 'kuking.turnstile.sekret' => 'test-sekret']);
        Http::preventStrayRequests();
        Http::fake([
            KlientTurnstile::ADRES => function () use ($invite) {
                // Kontroler już odczytał ważne zaproszenie; akcja jeszcze go nie zużyła.
                $this->assertTrue($invite->fresh()->jestWazne());
                $invite->forceFill(['created_at' => now()->subDays(2), 'expires_at' => now()->subMinute()])->save();

                return Http::response(['success' => true, 'hostname' => 'kuking.pl']);
            },
            'https://api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);
        $response = $this->zaloz(['cf-turnstile-response' => 'token-testowy']);
        $response->assertRedirect(route('register'));
        $this->assertArrayNotHasKey('password', session('_old_input'));
        $page = $this->followRedirects($response)->assertOk();
        $this->assertDatabaseMissing('users', ['email' => 'basia@example.com']);
        Notification::assertNothingSent();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$page->getContent());
        $xpath = new \DOMXPath($dom);
        foreach (['display_name' => 'Basia', 'username' => 'basia_z_podkarpacia', 'email' => 'basia@example.com', 'password' => ''] as $name => $value) {
            $field = $xpath->query('//input[@name="'.$name.'"]');
            $this->assertSame(1, $field->length, $name);
            $this->assertSame($value, $field->item(0)->getAttribute('value'));
        }
        foreach (['age_confirmed', 'terms_accepted'] as $name) {
            $this->assertSame(1, $xpath->query('//input[@name="'.$name.'" and @checked]')->length);
        }
        $page->assertDontSee('zielonapietruszkarano');
        $message = trim($xpath->query('//a[@href="#f-email"]')->item(0)?->textContent ?? '');
        $this->assertStringContainsString('zaproszenie przestało działać', $message);
        $this->assertStringContainsString('adres e-mail', $message);
        $this->assertStringContainsString('hasło ponownie', $message);
    }

    // ------------------------------------------------------------------
    //  Pomocnicze
    // ------------------------------------------------------------------

    /**
     * Zaproszenie wstawione wprost.
     *
     * @return array{0: string, 1: RegistrationInvite}
     */
    private function zaproszenie(string $adres): array
    {
        $token = RegistrationInvite::nowyToken();

        $wiersz = new RegistrationInvite;
        $wiersz->email = $adres;
        $wiersz->token_hash = RegistrationInvite::skrot($token);
        $wiersz->created_at = now();
        $wiersz->expires_at = now()->addHours(24);
        $wiersz->save();

        return [$token, $wiersz];
    }

    /**
     * Zaproszenie przyjęte przez EKRAN, a nie wstawione do sesji ręcznie —
     * żeby test szedł tą samą drogą co człowiek.
     *
     * @return array{0: string, 1: RegistrationInvite}
     */
    private function zPrzyjetymZaproszeniem(string $adres): array
    {
        [$token, $wiersz] = $this->zaproszenie($adres);

        $this->post(route('zaproszenie.przyjmij'), ['token' => $token])
            ->assertRedirect(route('register'));

        return [$token, $wiersz];
    }

    /**
     * Wysłanie formularza rejestracji. `$nadpisz` pozwala dołożyć albo
     * podmienić dowolne pole — także takie, którego formularz nie ma.
     *
     * @param  array<string, mixed>  $nadpisz
     */
    private function zaloz(array $nadpisz = []): TestResponse
    {
        return $this->from(route('register'))->post(route('register'), array_merge([
            'display_name' => 'Basia',
            'username' => 'basia_z_podkarpacia',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ], $nadpisz));
    }

    /**
     * Wycina JEDEN formularz o podanej akcji — asercja na całym HTML łapie
     * to samo słowo skądinąd (układ strony ma własne formularze).
     */
    private function formularzZAkcja(string $html, string $akcja): string
    {
        $dokument = new \DOMDocument;
        @$dokument->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        $formularze = (new \DOMXPath($dokument))->query('//form[@action="'.$akcja.'"]');

        $this->assertNotFalse($formularze);
        $this->assertGreaterThan(0, $formularze->length,
            "Na stronie nie ma formularza o akcji {$akcja} — test nie sprawdził niczego.");

        return (string) $dokument->saveHTML($formularze->item(0));
    }
}

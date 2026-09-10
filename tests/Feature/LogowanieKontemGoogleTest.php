<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Domain\Users\Actions\EraseAccountData;
use App\Google\KlientGoogle;
use App\Models\TozsamoscZewnetrzna;
use App\Models\User;
use App\Support\Google;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * WEJŚCIE KONTEM GOOGLE (issue #258, D-069).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO TE TESTY PILNUJĄ, A CZEGO NIE DOWODZĄ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Nie dowodzą, że rozmawiamy z prawdziwym Google — odpowiedź punktu tokenu
 * jest podstawiona (`Http::fake`). Dowodzą czegoś innego i ważniejszego:
 * że NASZE decyzje o tym, kogo wpuścić i z kim połączyć konto, są takie,
 * jak opisuje D-069, i że nie da się ich obejść.
 *
 * Najważniejsze cztery testy w tym pliku to:
 *
 *  1. `test_niepotwierdzony_adres_z_google_nie_wchodzi_nigdzie` — reguła 1;
 *  2. `test_konto_z_niepotwierdzonym_u_nas_adresem_nie_da_sie_przejac` —
 *     reguła 2, atak z wyprzedzeniem („pre-hijacking");
 *  3. `test_polaczenie_wymaga_potwierdzenia_na_naszym_ekranie` — reguła 3;
 *  4. `test_powrot_bez_zgodnego_state_nie_wchodzi` — CSRF na drodze OAuth.
 *
 * Do każdego z nich (i do pozostałych istotnych) jest KONTROLA UJEMNA —
 * tabela w opisie Pull Requesta. Kontrola ujemna to sprawdzenie, że test
 * naprawdę oblewa, gdy zabezpieczenie zepsuć: bez niej „zielono" znaczy
 * tylko tyle, że test nic nie sprawdza.
 */
class LogowanieKontemGoogleTest extends TestCase
{
    use RefreshDatabase;

    private const KLIENT = 'klient-testowy.apps.googleusercontent.com';

    protected function setUp(): void
    {
        parent::setUp();

        // Poczta z rejestracji nie ma nic do rzeczy w tych testach — a przy
        // koncie z potwierdzonym adresem MA NIE WYJŚĆ i to sprawdzamy wprost
        // w `test_domkniecie_zaklada_konto_bez_hasla_i_bez_wiadomosci`.
        Notification::fake();
    }

    // ───────────────────────────── narzędzia ─────────────────────────────

    private function wlaczGoogle(): void
    {
        config([
            'kuking.google.wlaczone' => true,
            'kuking.google.identyfikator_klienta' => self::KLIENT,
            'kuking.google.sekret_klienta' => 'sekret-testowy',
        ]);
    }

    private function wylaczGoogle(): void
    {
        config([
            'kuking.google.identyfikator_klienta' => '',
            'kuking.google.sekret_klienta' => '',
        ]);
    }

    /**
     * Kliknięcie „Wejdź kontem Google" — oddaje `state` i `nonce` z sesji.
     *
     * @return array{state: string, nonce: string, pkce: string, adres: string}
     */
    private function klikamyWejdz(): array
    {
        $odpowiedz = $this->get(route('google.start'));

        $odpowiedz->assertRedirect();

        return [
            'state' => (string) session('wejscie_google.state'),
            'nonce' => (string) session('wejscie_google.nonce'),
            'pkce' => (string) session('wejscie_google.pkce'),
            'adres' => (string) $odpowiedz->headers->get('Location'),
        ];
    }

    /** Token tożsamości w kształcie, w jakim oddaje go Google (bez podpisu). */
    private function tokenTozsamosci(array $dane): string
    {
        $czesc = static fn (array $tresc): string => rtrim(strtr(
            base64_encode((string) json_encode($tresc)), '+/', '-_',
        ), '=');

        return $czesc(['alg' => 'RS256', 'typ' => 'JWT'])
            .'.'.$czesc($dane)
            .'.podpis-ktorego-nie-sprawdzamy';
    }

    /**
     * Podstawiona odpowiedź punktu tokenu Google.
     *
     * @param  array<string, mixed>  $nadpisania  pola tokenu do podmiany
     */
    private function udajemyGoogle(string $nonce, array $nadpisania = []): void
    {
        $dane = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::KLIENT,
            'sub' => '109876543210987654321',
            'email' => 'basia@example.test',
            'email_verified' => true,
            'given_name' => 'Basia',
            'exp' => time() + 3600,
            'nonce' => $nonce,
        ], $nadpisania);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'token-dostepu-ktorego-nie-zapisujemy',
                'expires_in' => 3599,
                'token_type' => 'Bearer',
                'id_token' => $this->tokenTozsamosci($dane),
            ]),
        ]);
    }

    /** Cała droga do powrotu z Google. Oddaje odpowiedź z `callback`. */
    private function wracamyZGoogle(array $nadpisania = [], array $parametry = []): TestResponse
    {
        $sesja = $this->klikamyWejdz();
        $this->udajemyGoogle($sesja['nonce'], $nadpisania);

        return $this->get(route('google.callback', array_merge([
            'code' => 'kod-autoryzacyjny-od-google',
            'state' => $sesja['state'],
        ], $parametry)));
    }

    /**
     * Jeden element `<input>` z HTML-a, po identyfikatorze.
     *
     * PUŁAPKA, KTÓRA ZŁAPAŁA W TYM REPOZYTORIUM JUŻ KILKA ZLECEŃ: asercja
     * na całym HTML-u strony łapie to samo słowo z innego miejsca ekranu.
     * `checked` pada w tym repozytorium w kilku miejscach layoutu, więc
     * „czy haczyk jest zaznaczony" trzeba sprawdzać WEWNĄTRZ tego jednego
     * elementu, a nie w całej stronie.
     */
    private function element(string $html, string $id): string
    {
        preg_match('/<input[^>]*id="'.preg_quote($id, '/').'"[^>]*>/', $html, $trafienia);

        $this->assertNotEmpty($trafienia, "Nie ma na stronie elementu o id=\"{$id}\" — sprawdzam zły ekran.");

        return $trafienia[0];
    }

    /**
     * Identyfikator konta Google powiązany z tym kontem — albo `null`.
     *
     * Pytamy TABELĘ, nie model, i to jest celowe: `User::hasGoogleConnected()`
     * mógłby odpowiadać ze wczytanej relacji, a tu chcemy wiedzieć, co
     * naprawdę leży w bazie (D-098).
     */
    private function identyfikatorGoogle(User $user): ?string
    {
        $wartosc = TozsamoscZewnetrzna::query()
            ->where('user_id', $user->getKey())
            ->where('dostawca', TozsamoscZewnetrzna::DOSTAWCA_GOOGLE)
            ->value('identyfikator');

        return $wartosc === null ? null : (string) $wartosc;
    }

    /** Kiedy powstało powiązanie — albo `null`, gdy powiązania nie ma. */
    private function kiedyPolaczono(User $user): mixed
    {
        return TozsamoscZewnetrzna::query()
            ->where('user_id', $user->getKey())
            ->where('dostawca', TozsamoscZewnetrzna::DOSTAWCA_GOOGLE)
            ->value('connected_at');
    }

    // ─────────────────────── wyłącznik i martwy przycisk ───────────────────────

    #[Test]
    public function test_bez_kluczy_przycisku_nie_ma_i_trasa_odsyla_na_logowanie(): void
    {
        $this->wylaczGoogle();

        $this->assertFalse(Google::dziala());

        foreach ([route('login'), route('register')] as $adres) {
            $html = $this->get($adres)->assertOk()->getContent();

            $this->assertStringNotContainsString(route('google.start'), (string) $html,
                'Bez kluczy Google nie wolno pokazać przycisku — to jest dokładnie martwy przycisk z D-053.');
            $this->assertStringNotContainsString('Wejdź kontem Google', (string) $html);
        }

        // Trasa wpisana wprost nie oddaje 404 bez wyjaśnienia i nie wywala
        // się na pustym identyfikatorze klienta — odsyła na logowanie.
        $this->get(route('google.start'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'wyłączone'));
    }

    #[Test]
    public function test_z_kluczami_przycisk_stoi_na_logowaniu_i_na_rejestracji(): void
    {
        $this->wlaczGoogle();

        foreach ([route('login'), route('register')] as $adres) {
            $this->get($adres)->assertOk()->assertSee('Wejdź kontem Google');
        }
    }

    #[Test]
    public function test_wylacznik_zmienna_srodowiskowa_zdejmuje_droge_bez_migracji(): void
    {
        $this->wlaczGoogle();
        config(['kuking.google.wlaczone' => false]);

        $this->assertFalse(Google::dziala());
        $this->get(route('login'))->assertOk()->assertDontSee('Wejdź kontem Google');
        $this->get(route('google.start'))->assertRedirect(route('login'));
    }

    // ─────────────────────────── state, PKCE, zakres ───────────────────────────

    #[Test]
    public function test_adres_zgody_niesie_state_pkce_i_tylko_potrzebny_zakres(): void
    {
        $this->wlaczGoogle();

        $sesja = $this->klikamyWejdz();
        $adres = $sesja['adres'];

        $this->assertStringStartsWith(Google::ADRES_AUTORYZACJI, $adres);

        parse_str((string) parse_url($adres, PHP_URL_QUERY), $parametry);

        // PKCE: do Google idzie SKRÓT weryfikatora, nigdy sam weryfikator.
        $this->assertSame('S256', $parametry['code_challenge_method'] ?? null);
        $this->assertSame(KlientGoogle::wyzwanie($sesja['pkce']), $parametry['code_challenge'] ?? null);
        $this->assertStringNotContainsString($sesja['pkce'], $adres,
            'Weryfikator PKCE nie ma prawa opuścić naszej sesji.');

        // `state` i `nonce` — jednorazowe, wiązane z sesją.
        $this->assertSame($sesja['state'], $parametry['state'] ?? null);
        $this->assertSame($sesja['nonce'], $parametry['nonce'] ?? null);
        $this->assertNotSame($sesja['state'], $sesja['nonce']);

        // ZAKRES: tyle i ani słowa więcej (issue #258 pkt 5).
        $this->assertSame('openid email profile', $parametry['scope'] ?? null);

        // TOKENU ODŚWIEŻANIA NIE CHCEMY DOSTAĆ, a nie „nie zapisujemy".
        $this->assertSame('online', $parametry['access_type'] ?? null);
    }

    #[Test]
    public function test_powrot_bez_zgodnego_state_nie_wchodzi(): void
    {
        $this->wlaczGoogle();
        $user = $this->user('basia', ['email' => 'basia@example.test']);
        $user->connectGoogle('109876543210987654321');

        $sesja = $this->klikamyWejdz();
        $this->udajemyGoogle($sesja['nonce']);

        $this->get(route('google.callback', [
            'code' => 'kod-autoryzacyjny-od-google',
            'state' => 'podrzucony-przez-napastnika',
        ]))->assertRedirect(route('login'));

        // Niezgodny `state` to CSRF na drodze OAuth — nie wolno nikogo wpuścić.
        $this->assertGuest();
        Http::assertNothingSent();
    }

    #[Test]
    public function test_powrot_bez_niczego_w_sesji_nie_wchodzi(): void
    {
        $this->wlaczGoogle();
        $user = $this->user('basia', ['email' => 'basia@example.test']);
        $user->connectGoogle('109876543210987654321');

        // Bez kliknięcia „Wejdź kontem Google" w sesji nie ma ani `state`,
        // ani weryfikatora PKCE — czyli dokładnie sytuacja, w której ktoś
        // próbuje wejść z gotowym adresem powrotu.
        Http::fake();

        $this->get(route('google.callback', ['code' => 'kod', 'state' => 'cokolwiek']))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        Http::assertNothingSent();
    }

    #[Test]
    public function test_state_dziala_tylko_raz(): void
    {
        $this->wlaczGoogle();
        $user = $this->user('basia', ['email' => 'basia@example.test']);
        $user->connectGoogle('109876543210987654321');

        $sesja = $this->klikamyWejdz();
        $this->udajemyGoogle($sesja['nonce']);

        $adres = route('google.callback', ['code' => 'kod', 'state' => $sesja['state']]);

        $this->get($adres);
        $this->assertAuthenticatedAs($user);

        // Ten sam adres drugi raz — po wylogowaniu — nie ma prawa wpuścić.
        $this->post(route('logout'));
        $this->get($adres)->assertRedirect(route('login'));
        // `state` jest jednorazowy: powtórzony powrót nie wchodzi.
        $this->assertGuest();
    }

    #[Test]
    public function test_token_wystawiony_dla_innej_aplikacji_jest_odrzucany(): void
    {
        $this->wlaczGoogle();

        $this->wracamyZGoogle(['aud' => 'cudza-aplikacja.apps.googleusercontent.com'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function test_token_z_obcym_nonce_jest_odrzucany(): void
    {
        $this->wlaczGoogle();

        $this->wracamyZGoogle(['nonce' => 'nonce-z-innej-sesji'])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function test_token_nie_od_google_jest_odrzucany(): void
    {
        $this->wlaczGoogle();

        $this->wracamyZGoogle(['iss' => 'https://logowanie.napastnik.example'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    // ─────────────────── reguła 1: potwierdzenie adresu przez Google ───────────────────

    #[Test]
    public function test_niepotwierdzony_adres_z_google_nie_wchodzi_nigdzie(): void
    {
        $this->wlaczGoogle();

        // Prawdziwa Basia ma u nas konto z POTWIERDZONYM adresem.
        $basia = $this->user('basia', [
            'email' => 'basia@example.test',
            'email_verified_at' => now(),
        ]);

        // Napastnik zakłada konto Google na JEJ adres i nie potwierdza go.
        $odpowiedz = $this->wracamyZGoogle(['email_verified' => false]);

        $odpowiedz->assertRedirect(route('login'));
        // Bez potwierdzenia adresu przez Google to jest gotowe przejęcie konta.
        $this->assertGuest();

        $this->assertNull($this->identyfikatorGoogle($basia->refresh()));
        // Nie wolno też ZAŁOŻYĆ konta na niepotwierdzony adres.
        $this->assertDatabaseCount('users', 1);

        $status = (string) session('status');
        $this->assertStringContainsString('Google nie potwierdziło', $status);
        $this->assertStringContainsString('hasłem', $status, 'Hasło zostaje drogą równoległą (D-056).');
    }

    #[Test]
    public function test_niepotwierdzony_adres_nie_zaklada_tez_nowego_konta(): void
    {
        $this->wlaczGoogle();

        $this->wracamyZGoogle(['email_verified' => false])->assertRedirect(route('login'));

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    // ─────────────────── reguła 2: atak z wyprzedzeniem ───────────────────

    #[Test]
    public function test_konto_z_niepotwierdzonym_u_nas_adresem_nie_da_sie_przejac(): void
    {
        $this->wlaczGoogle();

        /*
         * SCENA: napastnik zakłada DZIŚ konto na adres `basia@example.test`,
         * którego nie kontroluje. Nasza rejestracja świadomie nie wymaga
         * potwierdzenia adresu (osoba 60+ nie może zostać odesłana do
         * skrzynki), więc takie konto po prostu jest — z hasłem napastnika
         * i bez potwierdzonego adresu.
         *
         * Potem przychodzi prawdziwa Basia, kontem Google, z adresem
         * POTWIERDZONYM przez Google. Automatyczne połączenie wpuściłoby ją
         * do konta napastnika: on zna hasło, widzi wszystko, co ona napisze,
         * i ma wejście, o którym ona nie wie.
         */
        $kontoNapastnika = $this->user('napastnik', [
            'email' => 'basia@example.test',
            'email_verified_at' => null,
        ]);

        $odpowiedz = $this->wracamyZGoogle();

        $odpowiedz->assertRedirect(route('login'));
        // Nikt nie wchodzi na konto z niepotwierdzonym u nas adresem.
        $this->assertGuest();

        $kontoNapastnika->refresh();
        $this->assertNull($this->identyfikatorGoogle($kontoNapastnika), 'Powiązanie nie ma prawa powstać.');
        $this->assertNull($this->kiedyPolaczono($kontoNapastnika));
        // Drugie konto na ten sam adres też nie powstaje.
        $this->assertDatabaseCount('users', 1);

        // Zdanie na ekranie musi mówić, CO ZROBIĆ — inaczej prawdziwa Basia
        // odbija się od odmowy i odchodzi.
        $status = (string) session('status');
        $this->assertStringContainsString('nie potwierdził', $status);
        $this->assertStringContainsString('hasłem', $status);
        $this->assertStringContainsString('potwierdź adres', $status);
    }

    // ─────────────────── reguła 3: połączenie za zgodą człowieka ───────────────────

    #[Test]
    public function test_polaczenie_wymaga_potwierdzenia_na_naszym_ekranie(): void
    {
        $this->wlaczGoogle();

        $basia = $this->user('basia', [
            'email' => 'basia@example.test',
            'email_verified_at' => now(),
            'display_name' => 'Basia',
        ]);

        $this->wracamyZGoogle()->assertRedirect(route('google.link'));

        // NIE zalogowani i NIE połączeni — samo rozpoznanie adresu nie
        // wystarcza, choćby Google go potwierdziło.
        $this->assertGuest();
        $this->assertNull($this->identyfikatorGoogle($basia->refresh()));

        // Ekran mówi, na jakie konto wchodzi, ZANIM cokolwiek się stanie.
        $this->get(route('google.link'))
            ->assertOk()
            ->assertSee('basia@example.test')
            ->assertSee('Basia')
            ->assertSee('Tak, to moje konto');

        // Dopiero POST łączy konta i wpuszcza.
        $this->post(route('google.link.store'))->assertRedirect(route('home'));

        $basia->refresh();
        $this->assertSame('109876543210987654321', $this->identyfikatorGoogle($basia));
        $this->assertNotNull($this->kiedyPolaczono($basia));
        $this->assertAuthenticatedAs($basia);

        // Hasło zostaje drogą równoległą — nie ruszamy go przy łączeniu.
        $this->assertDatabaseHas('audit_log', ['action' => 'account.google_connected']);
    }

    #[Test]
    public function test_ekran_polaczenia_niczego_nie_zmienia(): void
    {
        $this->wlaczGoogle();

        $basia = $this->user('basia', [
            'email' => 'basia@example.test',
            'email_verified_at' => now(),
        ]);

        $this->wracamyZGoogle();

        // GET, i to kilka razy — skanery odnośników i przycisk „wstecz"
        // otwierają adresy same. Nic nie ma prawa się zmienić (GET nie
        // zmienia stanu, D-056).
        $this->get(route('google.link'))->assertOk();
        $this->get(route('google.link'))->assertOk();

        $this->assertNull($this->identyfikatorGoogle($basia->refresh()));
        $this->assertGuest();
    }

    #[Test]
    public function test_blokada_konta_miedzy_ekranami_zatrzymuje_polaczenie(): void
    {
        $this->wlaczGoogle();

        $basia = $this->user('basia', [
            'email' => 'basia@example.test',
            'email_verified_at' => now(),
        ]);

        $this->wracamyZGoogle()->assertRedirect(route('google.link'));

        // Między pokazaniem ekranu a kliknięciem zapada decyzja moderacyjna.
        $basia->ban();

        $this->post(route('google.link.store'))->assertRedirect(route('login'));

        $this->assertNull($this->identyfikatorGoogle($basia->refresh()),
            'O dostępie do konta nie może rozstrzygać stan z przeszłości.');
        $this->assertGuest();
    }

    // ─────────────────── pierwsze wejście: domknięcie konta ───────────────────

    #[Test]
    public function test_pierwsze_wejscie_nie_zaklada_konta_po_cichu(): void
    {
        $this->wlaczGoogle();

        $this->wracamyZGoogle()->assertRedirect(route('google.finish'));

        // Konto powstaje dopiero po dwóch oświadczeniach.
        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    #[Test]
    public function test_ekran_domkniecia_podpowiada_nazwe_i_nie_zaznacza_oswiadczen(): void
    {
        $this->wlaczGoogle();

        $this->wracamyZGoogle();

        $html = (string) $this->get(route('google.finish'))->assertOk()->getContent();

        // DWA POLA ZOSTAJĄ (decyzja właściciela z 10 września): imię widoczne
        // dla innych i nazwa w adresie profilu, ta druga PODPOWIEDZIANA.
        $this->assertStringContainsString('value="Basia"', $this->element($html, 'f-display_name'));
        // `NazwaUzytkownika` nie rusza wartości, która JUŻ jest poprawna —
        // „Basia" zostaje „Basią", z wielką literą i wszystkim.
        $this->assertStringContainsString('value="Basia"', $this->element($html, 'f-username'));

        // Człowiek widzi, na jaki adres zakłada konto.
        $this->assertStringContainsString('basia@example.test', $html);

        /*
         * OŚWIADCZENIA NIE SĄ ZAZNACZONE ZA CZŁOWIEKA.
         *
         * Sprawdzane WEWNĄTRZ tych dwóch elementów, a nie w całym HTML-u:
         * słowo „checked" pada w tym repozytorium w kilku miejscach layoutu,
         * więc asercja na całej stronie przechodziłaby także wtedy, gdyby
         * haczyki były zaznaczone.
         */
        foreach (['f-age_confirmed', 'f-terms_accepted'] as $id) {
            $this->assertStringNotContainsString('checked', $this->element($html, $id),
                "Oświadczenie {$id} jest zaznaczone z góry — to ciemny wzorzec, a przy wieku dodatkowo bez wartości.");
        }
    }

    #[Test]
    public function test_bez_oswiadczen_konto_nie_powstaje(): void
    {
        $this->wlaczGoogle();
        $this->wracamyZGoogle();

        $this->post(route('google.finish.store'), [
            'display_name' => 'Basia',
            'username' => 'basia',
        ])->assertSessionHasErrors(['age_confirmed', 'terms_accepted']);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();

        // Tylko jedno z dwóch też nie wystarcza.
        $this->post(route('google.finish.store'), [
            'display_name' => 'Basia',
            'username' => 'basia',
            'terms_accepted' => '1',
        ])->assertSessionHasErrors('age_confirmed');

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function test_domkniecie_zaklada_konto_bez_hasla_i_bez_wiadomosci(): void
    {
        $this->wlaczGoogle();
        $this->wracamyZGoogle();

        $this->post(route('google.finish.store'), [
            'display_name' => 'Basia',
            'username' => 'Basia z Podkarpacia',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ])->assertRedirect(route('onboarding.interests'));

        $basia = User::where('email', 'basia@example.test')->first();

        $this->assertNotNull($basia);
        $this->assertAuthenticatedAs($basia);
        $this->assertSame('109876543210987654321', $this->identyfikatorGoogle($basia));
        $this->assertNotNull($this->kiedyPolaczono($basia));

        // ADRES JEST OD RAZU POTWIERDZONY — Google to potwierdziło, więc nie
        // pytamy o to samo drugi raz (i nie zużywamy listu z dobowej puli).
        $this->assertNotNull($basia->email_verified_at);
        Notification::assertNothingSent();

        // NAZWĘ UKŁADA SERWER, tak samo jak przy rejestracji hasłem.
        $this->assertSame('basia_z_podkarpacia', $basia->profile->username);
        $this->assertSame('Basia', $basia->profile->display_name);

        // OŚWIADCZENIE O WIEKU JEST ZAPISANE — wymóg prawny, nie ozdoba.
        $this->assertNotNull($basia->age_confirmed_at);

        // Powitanie i wpis w dzienniku, jak przy każdej innej drodze.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $basia->getKey(),
            'type' => \App\Models\Notification::TYPE_WELCOME,
        ]);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.registered']);
    }

    #[Test]
    public function test_zamknieta_rejestracja_zamyka_takze_droge_przez_google(): void
    {
        $this->wlaczGoogle();
        $this->wracamyZGoogle();

        config(['kuking.account.registration_open' => false]);

        $this->get(route('google.finish'))->assertRedirect(route('login'));
        $this->post(route('google.finish.store'), [
            'display_name' => 'Basia',
            'username' => 'basia',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ])->assertStatus(503);

        $this->assertDatabaseCount('users', 0);
    }

    #[Test]
    public function test_nazwa_zajeta_nie_odbija_czlowieka_bez_podpowiedzi(): void
    {
        $this->wlaczGoogle();
        $this->user('basia');

        $this->wracamyZGoogle();

        $html = (string) $this->get(route('google.finish'))->assertOk()->getContent();

        // `basia` jest zajęte, więc podpowiedź musi być inna i wolna —
        // inaczej człowiek klika „Załóż konto" i dostaje odmowę za coś,
        // czego nie wpisał.
        $this->assertStringContainsString('value="Basia_2"', $this->element($html, 'f-username'));
    }

    // ─────────────────── kolejne wejścia i stany konta ───────────────────

    #[Test]
    public function test_kolejne_wejscie_rozpoznaje_konto_po_identyfikatorze_google(): void
    {
        $this->wlaczGoogle();

        $basia = $this->user('basia', ['email' => 'basia@example.test', 'email_verified_at' => now()]);
        $basia->connectGoogle('109876543210987654321');

        // ADRES U GOOGLE SIĘ ZMIENIŁ, `sub` został ten sam — i to `sub`
        // rozstrzyga, kim jest ta osoba.
        $this->wracamyZGoogle(['email' => 'basia-nowy@example.test'])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($basia);
        $this->assertDatabaseHas('audit_log', ['action' => 'account.login_google']);
    }

    #[Test]
    public function test_konto_zablokowane_nie_wchodzi_i_czyta_uzasadnienie(): void
    {
        $this->wlaczGoogle();

        $zbanowany = $this->user('zbanowany', ['email' => 'basia@example.test', 'email_verified_at' => now()]);
        $zbanowany->connectGoogle('109876543210987654321');
        $zbanowany->ban();

        $this->wracamyZGoogle()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertStringContainsString('zablokowane', (string) session('status'),
            'Osoba zablokowana ma przeczytać uzasadnienie także na tej drodze (DSA art. 17).');
    }

    #[Test]
    public function test_konto_zawieszone_wchodzi_bo_kara_jest_tylko_do_odczytu(): void
    {
        $this->wlaczGoogle();

        $zawieszony = $this->user('zawieszony', ['email' => 'basia@example.test', 'email_verified_at' => now()]);
        $zawieszony->connectGoogle('109876543210987654321');
        $zawieszony->suspend(now()->addDays(3));

        $this->wracamyZGoogle()->assertRedirect(route('home'));

        // Zawieszenie jest karą „tylko do odczytu" — odmowa wejścia
        // zamieniałaby ją w blokadę na zawsze.
        $this->assertAuthenticatedAs($zawieszony);
    }

    #[Test]
    public function test_konto_usuniete_nie_wchodzi(): void
    {
        $this->wlaczGoogle();

        $konto = $this->user('odchodzi', ['email' => 'basia@example.test', 'email_verified_at' => now()]);
        $konto->connectGoogle('109876543210987654321');
        $konto->markForDeletion();

        $this->wracamyZGoogle()->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function test_konto_obslugi_serwisu_nie_wchodzi_ta_droga(): void
    {
        $this->wlaczGoogle();

        $moderator = $this->user('moderator_google', [
            'email' => 'basia@example.test',
            'email_verified_at' => now(),
            'role' => User::ROLE_MODERATOR,
        ]);
        $moderator->connectGoogle('109876543210987654321');

        $this->wracamyZGoogle()->assertRedirect(route('login'));

        // Moderator wchodzi hasłem i kodem z aplikacji (ten sam zakres co D-056).
        $this->assertGuest();
        $this->assertStringContainsString('Konta obsługi serwisu', (string) session('status'));
    }

    #[Test]
    public function test_konto_z_2fa_trafia_na_ekran_kodu_a_nie_do_serwisu(): void
    {
        $this->wlaczGoogle();

        $basia = $this->user('basia', ['email' => 'basia@example.test', 'email_verified_at' => now()]);
        $basia->connectGoogle('109876543210987654321');

        $totp = app(TwoFactorAuthenticator::class);
        $basia->beginTwoFactorSetup($totp->generateSecret());
        $basia->confirmTwoFactor($totp->hashBackupCodes($totp->generateBackupCodes()));

        $this->wracamyZGoogle()->assertRedirect(route('login.two_factor'));

        // Google zastępuje hasło, nie drugi składnik.
        $this->assertGuest();
        $this->assertSame($basia->getKey(), session('logowanie.2fa.user_id'));
    }

    // ─────────────────── awarie po stronie Google ───────────────────

    #[Test]
    public function test_odmowa_zgody_wraca_z_polskim_zdaniem(): void
    {
        $this->wlaczGoogle();

        $sesja = $this->klikamyWejdz();
        Http::fake();

        $this->get(route('google.callback', [
            'error' => 'access_denied',
            'state' => $sesja['state'],
        ]))->assertRedirect(route('login'));

        $status = (string) session('status');
        $this->assertStringContainsString('zgoda nie została udzielona', $status);
        $this->assertStringNotContainsString('access_denied', $status,
            'Angielski kod od dostawcy nie mówi człowiekowi niczego.');

        // Hasło i wiadomość z linkiem zostają widoczne — nie ma martwego ekranu.
        $ekran = $this->get(route('login'))->assertOk();
        $ekran->assertSee('Zaloguj się');
        $ekran->assertSee('Wyślij mi link do zalogowania');
    }

    #[Test]
    public function test_gdy_google_nie_odpowiada_odsylamy_na_haslo(): void
    {
        $this->wlaczGoogle();

        $sesja = $this->klikamyWejdz();
        Http::fake(['oauth2.googleapis.com/*' => Http::response('', 500)]);

        $this->get(route('google.callback', ['code' => 'kod', 'state' => $sesja['state']]))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
        $this->assertStringContainsString('hasłem', (string) session('status'));
    }

    #[Test]
    public function test_gdy_google_oddaje_odpowiedz_bez_tokenu_nikogo_nie_wpuszczamy(): void
    {
        $this->wlaczGoogle();

        $sesja = $this->klikamyWejdz();
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['access_token' => 'cos'])]);

        $this->get(route('google.callback', ['code' => 'kod', 'state' => $sesja['state']]))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    // ─────────────────── zakres danych ───────────────────

    #[Test]
    public function test_wymiana_kodu_niesie_weryfikator_pkce_i_nie_prosi_o_nic_wiecej(): void
    {
        $this->wlaczGoogle();

        $sesja = $this->klikamyWejdz();
        $this->udajemyGoogle($sesja['nonce']);

        $this->get(route('google.callback', ['code' => 'kod-abc', 'state' => $sesja['state']]));

        Http::assertSent(function ($zadanie) use ($sesja): bool {
            $dane = $zadanie->data();

            return $zadanie->url() === Google::ADRES_TOKENU
                && ($dane['code_verifier'] ?? null) === $sesja['pkce']
                && ($dane['grant_type'] ?? null) === 'authorization_code'
                && ($dane['code'] ?? null) === 'kod-abc';
        });

        // Ani jedno żądanie do API Google poza wymianą kodu — zdjęcia
        // profilowego nie pobieramy (D-061), kontaktów nie czytamy.
        Http::assertSentCount(1);
    }

    #[Test]
    public function test_w_bazie_nie_ma_gdzie_zapisac_tokenu_google(): void
    {
        // Kontrola projektu, nie zachowania: gdyby ktoś kiedyś dołożył
        // kolumnę na token dostępu albo odświeżania, ten test ma o tym
        // powiedzieć — bo to jest zmiana zakresu danych, a nie refaktor.
        foreach (['access_token', 'refresh_token', 'id_token', 'zdjecie', 'email'] as $kolumna) {
            $this->assertFalse(Schema::hasColumn('tozsamosci_zewnetrzne', $kolumna),
                "Kolumna `tozsamosci_zewnetrzne.{$kolumna}` nie ma prawa istnieć — trzymamy wyłącznie "
                .'dostawcę, identyfikator i datę połączenia (D-069, D-098).');
        }

        // Kolumn na `users` też nie ma i nie ma ich mieć — powiązanie
        // mieszka w osobnej tabeli (D-098).
        foreach (['google_sub', 'google_connected_at', 'google_access_token'] as $kolumna) {
            $this->assertFalse(Schema::hasColumn('users', $kolumna),
                "Kolumna `users.{$kolumna}` nie ma prawa istnieć — powiązania z dostawcami "
                .'tożsamości leżą w `tozsamosci_zewnetrzne` (D-098).');
        }

        $this->assertSame(
            ['connected_at', 'dostawca', 'id', 'identyfikator', 'user_id'],
            collect(Schema::getColumnListing('tozsamosci_zewnetrzne'))->sort()->values()->all(),
            'Zmiana zakresu danych o człowieku wymaga decyzji, nie refaktoru (AGENTS.md §6).',
        );
    }

    #[Test]
    public function test_jedno_konto_google_wchodzi_na_jedno_konto_kuking(): void
    {
        $pierwsze = $this->user('pierwsze');
        $drugie = $this->user('drugie');

        $pierwsze->connectGoogle('109876543210987654321');

        // Pilnuje tego BAZA (`UNIQUE (dostawca, identyfikator)`), nie tylko
        // PHP — bo walidator obchodzi się drugim endpointem.
        $this->expectException(QueryException::class);
        $drugie->connectGoogle('109876543210987654321');
    }

    #[Test]
    public function test_powiazanie_znika_razem_z_wymazaniem_danych_konta(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.test', 'email_verified_at' => now()]);
        $basia->connectGoogle('109876543210987654321');
        $basia->markForDeletion();

        app(EraseAccountData::class)->handle($basia->refresh());

        $this->assertNull($this->identyfikatorGoogle($basia->refresh()),
            'Po wymazaniu danych konto nie ma właściciela — wejście kontem Google musi zniknąć razem z hasłem.');
    }

    // ─────────────────── model danych na DWÓCH dostawców (D-098) ───────────────────

    #[Test]
    public function test_jedno_konto_kuking_nie_ma_dwoch_polaczen_z_google(): void
    {
        $basia = $this->user('basia');
        $basia->connectGoogle('109876543210987654321');

        /*
         * Pilnuje tego BAZA (`UNIQUE (dostawca, user_id)`), nie PHP.
         *
         * To jest ograniczenie, którego wersja na kolumnach nie potrzebowała
         * (kolumna jest jedna) — więc przy przejściu na tabelę trzeba je było
         * napisać wprost. Bez niego „połącz" wołane dwa razy dokładałoby
         * drugi wiersz i konto miałoby DWA konta Google, z których jedno
         * nikomu by o niczym nie mówiło.
         */
        $this->expectException(QueryException::class);
        $basia->connectGoogle('209876543210987654321');
    }

    #[Test]
    public function test_baza_nie_przyjmuje_dostawcy_poza_zamknieta_lista(): void
    {
        $basia = $this->user('basia');

        /*
         * Facebooka na liście CHECK-a NIE MA i to jest celowe (D-098):
         * wchodzi razem ze swoim kodem, bo warunki wejścia są u niego INNE —
         * nie oddaje `email_verified`, więc warunku z D-069 nie da się dla
         * niego spełnić, a łączenie po adresie musi być u niego ZAKAZANE.
         * Gdyby lista była otwarta, wystarczyłby jeden `INSERT` z cudzą
         * nazwą dostawcy, żeby ten wywód obejść bez żadnej decyzji.
         */
        $this->expectException(QueryException::class);

        DB::table('tozsamosci_zewnetrzne')->insert([
            'user_id' => $basia->getKey(),
            'dostawca' => 'facebook',
            'identyfikator' => '1234567890',
            'connected_at' => now(),
        ]);
    }

    #[Test]
    public function test_baza_nie_przyjmuje_pustego_identyfikatora(): void
    {
        $basia = $this->user('basia');

        // `CHECK (identyfikator ~ '^\S{1,255}$')`. Pusty identyfikator
        // pasowałby do każdego kolejnego pustego — czyli jedno konto Google
        // wpuszczałoby na dowolne konto, które też ma pusty wpis.
        $this->expectException(QueryException::class);

        DB::table('tozsamosci_zewnetrzne')->insert([
            'user_id' => $basia->getKey(),
            'dostawca' => 'google',
            'identyfikator' => '   ',
            'connected_at' => now(),
        ]);
    }

    #[Test]
    public function test_powiazanie_ginie_kaskada_gdy_wiersz_konta_znika_naprawde(): void
    {
        $basia = $this->user('basia');
        $basia->connectGoogle('109876543210987654321');

        /*
         * Kont w Kuking się NIE KASUJE, tylko anonimizuje (D-022), więc ta
         * kaskada nie jest drogą, którą powiązanie znika w praktyce — robi to
         * jawnie `EraseAccountData` (test wyżej). Kaskada jest siatką na
         * wypadek realnego `DELETE`: `migrate:fresh`, sprzątanie danych
         * zasianych, przyszłe twarde usunięcie. Wiersz-sierota trzymałby
         * identyfikator konta Google wskazujący w pustkę i BLOKOWAŁBY
         * ponowne połączenie tego konta Google z czymkolwiek.
         */
        DB::table('users')->where('id', $basia->getKey())->delete();

        $this->assertDatabaseCount('tozsamosci_zewnetrzne', 0);
    }

    // ─────────────────── dowód, że to moje konto ───────────────────

    #[Test]
    public function test_polaczenie_bez_tozsamosci_w_sesji_odmawia(): void
    {
        $this->wlaczGoogle();

        $basia = $this->user('basia', [
            'email' => 'basia@example.test',
            'email_verified_at' => now(),
        ]);

        /*
         * Samo wejście POST-em na adres łączenia nie łączy niczego. Bez
         * tożsamości w sesji nie ma DOWODU, że ten człowiek przeszedł przez
         * ekran zgody Google dla tego adresu — a bez tego dowodu połączenie
         * jest przejęciem konta na życzenie.
         */
        $this->post(route('google.link.store'))->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull($this->identyfikatorGoogle($basia->refresh()));
    }

    #[Test]
    public function test_moderator_nie_polaczy_konta_ta_droga_nawet_gdy_adres_sie_zgadza(): void
    {
        $this->wlaczGoogle();

        // Konto obsługi serwisu z POTWIERDZONYM adresem, jeszcze NIE powiązane
        // — czyli dokładnie ten stan, w którym reguła 3 wpuściłaby zwykłą
        // osobę na ekran „połączyć?".
        $moderator = $this->user('moderator_niepolaczony', [
            'email' => 'basia@example.test',
            'email_verified_at' => now(),
            'role' => User::ROLE_MODERATOR,
        ]);

        $this->wracamyZGoogle()->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertStringContainsString('Konta obsługi serwisu', (string) session('status'));

        // Ekran łączenia też nie ma czego pokazać, a POST nic nie zapisuje —
        // 2FA moderatora nie da się obejść, dokładając mu konto Google.
        $this->get(route('google.link'))->assertRedirect(route('login'));
        $this->post(route('google.link.store'))->assertRedirect(route('login'));
        $this->assertNull($this->identyfikatorGoogle($moderator->refresh()));
    }

    #[Test]
    public function test_token_bez_terminu_waznosci_nie_wchodzi(): void
    {
        $this->wlaczGoogle();

        // `exp` jest polem OBOWIĄZKOWYM tokenu tożsamości (OIDC Core §2).
        // Sprawdzenie „o ile pole jest" byłoby sprawdzeniem, które napastnik
        // wyłącza, pomijając pole — a tym tokenem wchodzi się na konto.
        $this->wracamyZGoogle(['exp' => null])->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }
}

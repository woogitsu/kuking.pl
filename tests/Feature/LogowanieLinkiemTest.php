<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Security\TwoFactorAuthenticator;
use App\Models\AuditLogEntry;
use App\Models\LoginLinkToken;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Logowanie linkiem e-mail — „magic link" (issue #25, D-056).
 *
 * CZEGO TE TESTY PILNUJĄ
 *
 * Link wysłany pocztą JEST hasłem jednorazowym, więc większość tego pliku to
 * nie sprawdzanie „czy działa", tylko sprawdzanie, czy NIE DZIAŁA tam, gdzie
 * nie ma prawa: drugi raz, po terminie, na cudzym koncie, z pominięciem 2FA,
 * na koncie moderatora, po zmianie hasła. Do tego dwie rzeczy, które łatwo
 * zepsuć bez zauważenia:
 *
 *  - ODPOWIEDŹ FORMULARZA JEST NIEODRÓŻNIALNA dla adresu z kontem i bez
 *    konta (test enumeracji porównuje CAŁĄ odpowiedź, nie samo słowo
 *    „wysłaliśmy");
 *  - W BAZIE LEŻY SKRÓT, NIGDY TOKEN — sprawdzane zapytaniem po surowej
 *    kolumnie, a nie przez model, żeby żaden akcesor nie mógł tego upiększyć.
 */
class LogowanieLinkiemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // W testach `MAIL_MAILER=array`, a `App\Support\Poczta` uznaje to
        // (słusznie) za „poczta nie działa" — formularz chowa się wtedy
        // w całości. Bez tego sprawdzalibyśmy ekran, który nie ma formularza.
        config(['mail.default' => 'smtp']);
    }

    // ------------------------------------------------------------------
    //  Droga szczęśliwa i ten jeden przycisk więcej
    // ------------------------------------------------------------------

    public function test_link_z_listu_loguje_dopiero_po_kliknieciu_przycisku(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $link = $this->popros($basia->email);

        // KROK 1: samo wejście pod adres z listu NICZEGO NIE ZUŻYWA
        // i NIKOGO NIE LOGUJE.
        $this->get($link)
            ->assertOk()
            ->assertSee('Zaloguj mnie')
            ->assertSee('b***@example.com');

        $this->assertGuest();
        $this->assertDatabaseCount('login_link_tokens', 1);

        // KROK 2: dopiero przycisk.
        $this->wejdz($link)->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($basia);
        $this->assertDatabaseCount('login_link_tokens', 0);
    }

    /**
     * SKANER ANTYWIRUSOWY W POCZCIE NIE MA PRAWA ZUŻYĆ LINKU.
     *
     * To jest powód, dla którego środkowy ekran w ogóle istnieje (D-056).
     * Outlook Safe Links i bramki operatorów otwierają każdy adres z listu,
     * ZANIM zrobi to człowiek — i robią to metodą GET. Gdyby GET logował,
     * właściciel konta dostawałby „ten link już nie działa" przy pierwszym
     * własnym kliknięciu, za każdym razem.
     */
    public function test_wielokrotne_otwarcie_linku_nie_zuzywa_go(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $link = $this->popros($basia->email);

        // Trzy „kliknięcia" skanera.
        $this->get($link)->assertOk();
        $this->get($link)->assertOk();
        $this->get($link)->assertOk();

        $this->assertGuest();
        $this->assertDatabaseCount('login_link_tokens', 1);

        // Człowiek wchodzi jako czwarty i wchodzi normalnie.
        $this->wejdz($link);
        $this->assertAuthenticatedAs($basia);
    }

    /**
     * LINK OTWARTY NA INNYM URZĄDZENIU NIŻ TO, Z KTÓREGO POSZŁA PROŚBA.
     *
     * To jest droga TYPOWA, nie brzegowa: prośba idzie z komputera, poczta
     * jest w telefonie. Dlatego linku świadomie NIE wiążemy z sesją
     * proszącego — takie wiązanie zamknęłoby główną drogę tej funkcji.
     * `flushSession()` odtwarza tu „inne urządzenie": inne ciasteczko sesji,
     * zero śladu po formularzu.
     */
    public function test_link_dziala_na_innym_urzadzeniu_niz_prosba(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $link = $this->popros($basia->email);

        $this->flushSession();

        $this->get($link)->assertOk()->assertSee('Zaloguj mnie');
        $this->wejdz($link);

        $this->assertAuthenticatedAs($basia);
    }

    // ------------------------------------------------------------------
    //  Jednorazowość, termin, cudze konto
    // ------------------------------------------------------------------

    public function test_token_nie_dziala_drugi_raz(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $link = $this->popros($basia->email);

        $this->wejdz($link);
        $this->assertAuthenticatedAs($basia);

        // Wylogowanie odtwarza sytuację „ktoś inny znalazł ten sam list".
        auth()->logout();
        $this->flushSession();

        $odpowiedz = $this->wejdz($link);

        $this->assertGuest();
        $odpowiedz->assertRedirect(route('login.link'));
        $this->assertStringContainsString('już nie działa', (string) session('status'));

        // I ekran z linku też ma już nic nie oferować.
        $this->get($link)->assertOk()->assertSee('Ten link już nie działa');
    }

    public function test_wygasly_token_nie_loguje(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $link = $this->popros($basia->email);

        // Minutę po terminie z `config('kuking.login_link.waznosc_minut')`.
        $this->travel((int) config('kuking.login_link.waznosc_minut') + 1)->minutes();

        $this->get($link)->assertOk()->assertSee('Ten link już nie działa');
        $this->wejdz($link);

        $this->assertGuest();
    }

    /**
     * TOKEN Z CUDZEGO KONTA NIE WPUSZCZA NA MOJE — I ODWROTNIE.
     *
     * Pilnowana własność: wiersz musi być odnajdywany PO SKRÓCIE TOKENU,
     * a nie po czymkolwiek innym, co akurat jest pod ręką (pierwszy wiersz
     * tabeli, adres z formularza, identyfikator z sesji).
     *
     * KOLEJNOŚĆ ZAKŁADANIA TOKENÓW JEST W TYM TEŚCIE ISTOTNA I NIE WOLNO JEJ
     * ZAMIENIAĆ. Wiersz Halinki powstaje PIERWSZY, a logujemy się linkiem
     * Basi. Dzięki temu pomyłka „szukamy dowolnego wiersza" (`first()` bez
     * warunku na skrót) daje wynik JEDNOZNACZNIE zły — wpuszcza Halinkę.
     * Przy odwrotnej kolejności taka pomyłka przechodziłaby niezauważona,
     * bo pierwszy wiersz byłby przypadkiem tym właściwym; sprawdzone
     * kontrolą ujemną, w której test w tamtym układzie ŚWIECIŁ NA ZIELONO
     * mimo usuniętego warunku.
     */
    public function test_token_wpuszcza_wylacznie_na_swoje_konto(): void
    {
        $halinka = $this->user('halinka', ['email' => 'halinka@example.com']);
        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        $linkHalinki = $this->popros($halinka->email);
        $linkBasi = $this->popros($basia->email);

        $this->wejdz($linkBasi);

        $this->assertAuthenticatedAs($basia);

        // Token Halinki jest nietknięty — zużyliśmy DOKŁADNIE ten wiersz,
        // który należał do tokenu z linku, a nie „jakiś".
        $this->assertDatabaseCount('login_link_tokens', 1);
        $this->assertDatabaseHas('login_link_tokens', [
            'token_hash' => LoginLinkToken::skrot($this->tokenZLinku($linkHalinki)),
            'user_id' => $halinka->getKey(),
        ]);

        auth()->logout();
        $this->flushSession();

        $this->wejdz($linkHalinki);
        $this->assertAuthenticatedAs($halinka);
    }

    public function test_nowa_prosba_uniewaznia_poprzedni_link(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        $pierwszy = $this->popros($basia->email);
        $drugi = $this->popros($basia->email);

        $this->assertNotSame($pierwszy, $drugi);
        $this->assertDatabaseCount('login_link_tokens', 1);

        $this->wejdz($pierwszy);
        $this->assertGuest();

        $this->wejdz($drugi);
        $this->assertAuthenticatedAs($basia);
    }

    public function test_zmyslony_token_nie_loguje_i_nie_wywraca_strony(): void
    {
        $this->user('basia', ['email' => 'basia@example.com']);

        foreach (['abc', str_repeat('a', 64), str_repeat('Z', 200)] as $zmyslony) {
            $this->get('/logowanie/link/'.$zmyslony)
                ->assertOk()
                ->assertSee('Ten link już nie działa');

            $this->post(route('login.link.store'), ['token' => $zmyslony]);

            $this->assertGuest();
        }
    }

    // ------------------------------------------------------------------
    //  W BAZIE LEŻY SKRÓT, NIE TOKEN
    // ------------------------------------------------------------------

    public function test_w_bazie_nie_lezy_jawny_token(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $link = $this->popros($basia->email);
        $token = $this->tokenZLinku($link);

        // Czytamy SUROWY wiersz zapytaniem, nie przez model — żeby żaden
        // akcesor ani cast nie mógł tej odpowiedzi upiększyć.
        $wiersz = DB::table('login_link_tokens')->first();

        $this->assertNotNull($wiersz);
        $this->assertNotSame($token, $wiersz->token_hash);

        // Token nie może się znaleźć w ŻADNEJ kolumnie tego wiersza.
        $this->assertStringNotContainsString(
            $token,
            (string) json_encode((array) $wiersz),
            'Token w postaci jawnej trafił do bazy. Wiersz `login_link_tokens` '
            .'ma trzymać wyłącznie skrót (`Skrot::hmac`).',
        );

        // ...i musi być skrótem TEGO tokenu, bo inaczej link by nie działał.
        $this->assertSame(LoginLinkToken::skrot($token), $wiersz->token_hash);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $wiersz->token_hash);
    }

    public function test_dziennik_audytu_notuje_fakt_bez_tokenu_i_bez_pelnego_adresu(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $link = $this->popros($basia->email);
        $token = $this->tokenZLinku($link);

        $prosba = AuditLogEntry::query()->where('action', 'account.login_link_requested')->first();

        $this->assertNotNull($prosba, 'Prośba o link ma zostawiać ślad w dzienniku audytu.');
        $this->assertSame($basia->getKey(), $prosba->actor_id);
        $this->assertSame('b***@example.com', $prosba->metadata['adres_skrot'] ?? null);

        $this->wejdz($link);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'account.login_link_used',
            'actor_id' => $basia->getKey(),
        ]);

        // Ani token, ani pełny adres nie mają prawa być w dzienniku
        // (SECURITY_BASELINE §7: fakt i aktor, nigdy treści ani tokenów).
        $wpisy = (string) json_encode(
            AuditLogEntry::query()->get()->toArray(),
            JSON_UNESCAPED_UNICODE,
        );

        $this->assertStringNotContainsString($token, $wpisy);
        $this->assertStringNotContainsString('basia@example.com', $wpisy);
    }

    // ------------------------------------------------------------------
    //  ENUMERACJA: adres z kontem i bez konta mają dać TO SAMO
    // ------------------------------------------------------------------

    /**
     * ODPOWIEDŹ MUSI BYĆ NIEODRÓŻNIALNA — CO DO ZNAKU.
     *
     * Nie porównujemy „czy oba mówią coś o wysłaniu", tylko CAŁĄ obserwowalną
     * odpowiedź: kod HTTP, adres przekierowania, treść komunikatu i to, czy
     * są błędy walidacji. Wcześniejsza wersja takiego testu w innym miejscu
     * tego repozytorium sprawdzała samo `assertSessionHas('status')` i była
     * zielona także wtedy, gdy komunikaty różniły się treścią.
     *
     * OBA ADRESY MAJĄ TĘ SAMĄ PIERWSZĄ LITERĘ I TĘ SAMĄ DOMENĘ. To nie jest
     *
     * kosmetyka testu: komunikat pokazuje adres w skrócie (`b***@example.com`),
     * więc przy adresach o różnych pierwszych literach różniłby się zawsze
     * i porównanie nic by nie mierzyło. Skrót bierze się z tego, co człowiek
     * WPISAŁ, a nie z bazy — i to jest właśnie ta własność.
     */
    public function test_odpowiedz_dla_adresu_bez_konta_jest_nieodrozninalna(): void
    {
        $this->user('basia', ['email' => 'basia@example.com']);

        // POCZTA W TYM TEŚCIE NAPRAWDĘ PRÓBUJE WYJŚĆ I NAPRAWDĘ SIĘ NIE UDAJE
        // (kolejka `sync`, SMTP na 127.0.0.1:2525, gdzie nikt nie słucha).
        // To jest tu CELOWE, a nie niedoróbka środowiska: gdyby awaria wysyłki
        // umiała wywrócić żądanie, ścieżka „konto istnieje" oddawałaby 500,
        // a „konta nie ma" — 302. Sam kod odpowiedzi byłby wtedy wyrocznią
        // obecności konta i żaden wspólny komunikat by tego nie zasłonił.
        // Tak właśnie zachowywał się ten kod, zanim `WyslijLinkDoLogowania`
        // dostało `try`/`catch` wokół wysyłki.
        $zKontem = $this->wyslijFormularz('basia@example.com');
        $bezKonta = $this->wyslijFormularz('bogumila@example.com');

        $this->assertSame($zKontem->status(), $bezKonta->status(), 'Różny kod odpowiedzi.');
        $this->assertSame(
            $zKontem->headers->get('Location'),
            $bezKonta->headers->get('Location'),
            'Różny adres przekierowania.',
        );

        $this->assertSame(
            $this->komunikat($zKontem),
            $this->komunikat($bezKonta),
            'Komunikat po wysłaniu formularza różni się dla adresu z kontem i bez konta '
            .'— to jest wyrocznia „kto ma konto w Kuking".',
        );

        // Sprawdzenie kontrolne, że test w ogóle mierzy to, co ma mierzyć:
        // komunikat naprawdę zawiera skrót adresu i naprawdę poszedł tylko
        // jeden list.
        $this->assertStringContainsString('b***@example.com', $this->komunikat($zKontem));
        $this->assertDatabaseCount('login_link_tokens', 1);
    }

    public function test_konto_zablokowane_nie_dostaje_linku_i_nikt_sie_o_tym_nie_dowiaduje(): void
    {
        $zwykla = $this->user('basia', ['email' => 'basia@example.com']);
        $zablokowana = $this->user('bogumila', [
            'email' => 'bogumila@example.com',
            'status' => User::STATUS_BANNED,
        ]);

        Notification::fake();

        $dobre = $this->wyslijFormularz($zwykla->email);
        $zablokowane = $this->wyslijFormularz($zablokowana->email);

        Notification::assertSentTo($zwykla, LinkDoLogowania::class);
        Notification::assertNotSentTo($zablokowana, LinkDoLogowania::class);

        $this->assertSame($this->komunikat($dobre), $this->komunikat($zablokowane));
    }

    /**
     * KONTA OBSŁUGI SERWISU TĄ DROGĄ NIE WCHODZĄ (issue #25).
     *
     * Moderator i administrator mają hasło plus 2FA — przeniesienie ich
     * bezpieczeństwa na skrzynkę pocztową byłoby rozluźnieniem, którego
     * `EnsureModeratorHasTwoFactor` nie widzi, bo tamten middleware pilnuje
     * panelu, a nie wejścia do serwisu.
     */
    public function test_moderator_i_administrator_nie_dostaja_linku(): void
    {
        $moderator = $this->user('moderatorka', [
            'email' => 'moderatorka@example.com',
            'role' => User::ROLE_MODERATOR,
        ]);
        $admin = $this->user('adminka', [
            'email' => 'adminka@example.com',
            'role' => User::ROLE_ADMIN,
        ]);

        Notification::fake();

        $this->wyslijFormularz($moderator->email);
        $this->wyslijFormularz($admin->email);

        Notification::assertNothingSent();
        $this->assertDatabaseCount('login_link_tokens', 0);

        // A na ekranie stoi zdanie, z którego moderator dowie się, dlaczego
        // list nie przyjdzie — widoczne dla WSZYSTKICH, więc nic nie zdradza.
        $this->get(route('login.link'))
            ->assertOk()
            ->assertSee('Kontom obsługi serwisu');
    }

    // ------------------------------------------------------------------
    //  2FA NIE JEST OMIJANE
    // ------------------------------------------------------------------

    /**
     * LINK ZASTĘPUJE HASŁO, NIE DRUGI SKŁADNIK.
     *
     * Konto z potwierdzoną weryfikacją dwuetapową po kliknięciu „Zaloguj
     * mnie" trafia dokładnie tam, gdzie trafia po poprawnym haśle:
     * na `/logowanie/kod`. Sesja NIE JEST wtedy zalogowana — w sesji leży sam
     * identyfikator konta, tak jak to robi `LoginController`.
     */
    public function test_konto_z_2fa_nie_wchodzi_linkiem_z_pominieciem_kodu(): void
    {
        $basia = $this->uzytkownikZDwuetapowa();
        $link = $this->popros($basia->email);

        $this->wejdz($link)->assertRedirect(route('login.two_factor'));

        $this->assertGuest();
        $this->assertSame($basia->getKey(), session('logowanie.2fa.user_id'));

        // Strona główna dalej odsyła do logowania — sesja nie jest zalogowana.
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_konto_z_2fa_wchodzi_dopiero_po_podaniu_kodu(): void
    {
        $basia = $this->uzytkownikZDwuetapowa();
        $link = $this->popros($basia->email);

        $this->wejdz($link);

        $kod = (new Google2FA)->getCurrentOtp((string) $basia->fresh()->two_factor_secret);

        $this->post(route('login.two_factor.store'), ['code' => $kod])
            ->assertRedirect(route('home'));

        $this->assertAuthenticatedAs($basia);
    }

    // ------------------------------------------------------------------
    //  Unieważnianie razem z sesjami
    // ------------------------------------------------------------------

    public function test_zmiana_hasla_uniewaznia_oczekujacy_link(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com', 'password' => Hash::make('zielonapietruszkarano')]);
        $link = $this->popros($basia->email);

        $this->actingAs($basia)->put(route('settings.security.password'), [
            'current_password' => 'zielonapietruszkarano',
            'password' => 'czerwonaporzeczkawieczorem',
            'password_confirmation' => 'czerwonaporzeczkawieczorem',
        ]);

        $this->assertDatabaseCount('login_link_tokens', 0);

        auth()->logout();
        $this->flushSession();

        $this->wejdz($link);
        $this->assertGuest();
    }

    public function test_wylogowanie_innych_urzadzen_uniewaznia_oczekujacy_link(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com', 'password' => Hash::make('zielonapietruszkarano')]);
        $link = $this->popros($basia->email);

        $this->actingAs($basia)->post(route('settings.security.logout-others'), [
            'password' => 'zielonapietruszkarano',
        ]);

        $this->assertDatabaseCount('login_link_tokens', 0);

        auth()->logout();
        $this->flushSession();

        $this->wejdz($link);
        $this->assertGuest();
    }

    public function test_reset_hasla_uniewaznia_oczekujacy_link(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $link = $this->popros($basia->email);

        $token = app('auth.password.broker')->createToken($basia);

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $basia->email,
            'password' => 'czerwonaporzeczkawieczorem',
            'password_confirmation' => 'czerwonaporzeczkawieczorem',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('login_link_tokens', 0);

        $this->wejdz($link);
        $this->assertGuest();
    }

    public function test_blokada_konta_uniewaznia_oczekujacy_link(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $link = $this->popros($basia->email);

        $basia->ban();

        $this->assertDatabaseCount('login_link_tokens', 0);

        $this->wejdz($link);
        $this->assertGuest();
    }

    // ------------------------------------------------------------------
    //  Limity i budżet poczty
    // ------------------------------------------------------------------

    /**
     * LIMIT PO ADRESIE E-MAIL — i to on, a nie limit po IP, chroni skrzynkę
     * konkretnej osoby przed zalaniem.
     *
     * Licznik rusza dla KAŻDEGO wysłania formularza, także dla adresu bez
     * konta — inaczej samo „ten formularz mnie jeszcze nie zatrzymał"
     * odpowiadałoby na pytanie, czy konto istnieje. Dlatego drugą połowę
     * tego testu robimy na adresie, którego w bazie nie ma.
     */
    public function test_limit_prosb_na_jeden_adres_zatrzymuje_wysylke(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $proby = (int) config('kuking.login_link.limit_na_adres.proby');

        Notification::fake();

        for ($i = 0; $i < $proby; $i++) {
            $this->wyslijFormularz($basia->email)->assertSessionHasNoErrors();
        }

        $odbite = $this->wyslijFormularz($basia->email);

        $odbite->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'spróbuj za godzinę',
            (string) session('errors')->first('email'),
        );

        Notification::assertSentToTimes($basia, LinkDoLogowania::class, $proby);
    }

    public function test_limit_na_adres_liczy_takze_adresy_bez_konta(): void
    {
        $proby = (int) config('kuking.login_link.limit_na_adres.proby');

        for ($i = 0; $i < $proby; $i++) {
            $this->wyslijFormularz('nikogo-takiego@example.com')->assertSessionHasNoErrors();
        }

        $this->wyslijFormularz('nikogo-takiego@example.com')->assertSessionHasErrors('email');
    }

    /**
     * BUDŻET DOBOWY POCZTY — i to, że po jego wyczerpaniu NIE MILCZYMY.
     *
     * EmailLabs na planie darmowym daje 300 listów na dobę na cały serwis
     * (D-047), dzielone z potwierdzeniami rejestracji. Bez tego sufitu fala
     * próśb o linki zjada pulę i pierwszą rzeczą, która przestaje działać,
     * jest rejestracja — a przyczyna siedzi kilka warstw dalej.
     */
    public function test_po_wyczerpaniu_dobowego_budzetu_nie_wysylamy_i_mowimy_o_tym(): void
    {
        config(['kuking.login_link.dzienny_budzet' => 1]);

        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $halinka = $this->user('halinka', ['email' => 'halinka@example.com']);

        Notification::fake();

        $this->wyslijFormularz($basia->email);
        Notification::assertSentTo($basia, LinkDoLogowania::class);

        $odbite = $this->wyslijFormularz($halinka->email);

        Notification::assertNotSentTo($halinka, LinkDoLogowania::class);

        // Cicha odmowa byłaby tu najgorsza: człowiek czekałby na list, który
        // nie wyjdzie. Komunikat mówi, co się stało i co zrobić zamiast tego.
        $komunikat = $this->komunikat($odbite);
        $this->assertStringContainsString('nie czekaj na niego', $komunikat);
        $this->assertStringContainsString('Zaloguj się hasłem', $komunikat);
        $this->assertStringContainsString(
            (string) config('kuking.community.contact_email'),
            $komunikat,
        );
    }

    /**
     * BUDŻET ZAJMUJE SIĘ DOPIERO PRZY WYSŁANYM LIŚCIE.
     *
     * Gdyby licznik ruszał przy każdym wysłaniu formularza, byle automat
     * wpisujący nieistniejące adresy wyczerpałby dobową pulę w kilka minut
     * i zamknął drogę wszystkim prawdziwym ludziom, nie wysławszy ani
     * jednego listu.
     */
    public function test_adresy_bez_konta_nie_zjadaja_dobowego_budzetu(): void
    {
        config(['kuking.login_link.dzienny_budzet' => 1]);

        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        Notification::fake();

        $this->wyslijFormularz('nikogo-takiego@example.com');
        $this->wyslijFormularz('tez-nikogo@example.com');

        $this->wyslijFormularz($basia->email);

        Notification::assertSentTo($basia, LinkDoLogowania::class);
    }

    // ------------------------------------------------------------------
    //  Turnstile i poczta, która nie działa
    // ------------------------------------------------------------------

    /**
     * Ten formularz należy do rodziny chronionej Turnstile (D-050, D-053).
     * Komplet sprawdzeń — `<noscript>`, osobne komunikaty, awaria Cloudflare
     * — jest w `TurnstileWymagaPotwierdzeniaTest`; tutaj pilnujemy samego
     * połączenia: z kluczami wysłanie BEZ tokenu nie wysyła listu.
     */
    public function test_z_kluczami_turnstile_wyslanie_bez_tokenu_nie_wysyla_listu(): void
    {
        config([
            'kuking.turnstile.klucz_publiczny' => '1x00000000000000000000AA',
            'kuking.turnstile.sekret' => '1x0000000000000000000000000000000AA',
        ]);

        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        Notification::fake();

        $this->from(route('login.link'))
            ->post(route('login.link.send'), ['email' => $basia->email])
            ->assertSessionHasErrors('cf-turnstile-response');

        Notification::assertNothingSent();
        $this->assertDatabaseCount('login_link_tokens', 0);
    }

    /**
     * Poczta niedziałająca (`MAIL_MAILER=log` albo `array`) ma CHOWAĆ
     * formularz, a nie przyjmować adres i milczeć. To ta sama lekcja co przy
     * „Nie pamiętam hasła" (`App\Support\Poczta`): pole, które przyjmuje dane
     * i nic nie robi, jest gorsze niż jego brak.
     */
    public function test_bez_dzialajacej_poczty_formularz_sie_nie_pokazuje_i_nic_nie_obiecuje(): void
    {
        config(['mail.default' => 'log']);

        $basia = $this->user('basia', ['email' => 'basia@example.com']);

        $this->get(route('login.link'))
            ->assertOk()
            ->assertDontSee('Wyślij mi link</button>', escape: false)
            ->assertSee('Nie wysyłamy jeszcze wiadomości e-mail');

        $odpowiedz = $this->wyslijFormularz($basia->email);

        $this->assertStringContainsString('link do zalogowania nie przyjdzie', $this->komunikat($odpowiedz));
        $this->assertDatabaseCount('login_link_tokens', 0);
    }

    // ------------------------------------------------------------------
    //  Wejście z ekranu logowania i wyłącznik
    // ------------------------------------------------------------------

    public function test_ekran_logowania_prowadzi_do_tej_drogi_rownorzednie_z_haslem(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Wyślij mi link do zalogowania')
            ->assertSee(route('login.link'), escape: false);
    }

    /**
     * WYŁĄCZNIK JEST DROGĄ WYCOFANIA — i musi zdejmować OBIE rzeczy naraz:
     * wejście z ekranu logowania ORAZ samą drogę. Zostawienie jednego bez
     * drugiego znaczy albo martwy przycisk (D-053), albo trasę działającą
     * po wyłączeniu funkcji.
     */
    public function test_wylacznik_zdejmuje_wejscie_z_ekranu_logowania_i_cala_droge(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.com']);
        $link = $this->popros($basia->email);

        config(['kuking.login_link.wlaczone' => false]);

        $this->get(route('login'))->assertOk()->assertDontSee('Wyślij mi link do zalogowania');

        $this->get(route('login.link'))->assertOk()->assertSee('Logowanie linkiem jest teraz wyłączone');
        $this->get($link)->assertOk()->assertSee('Logowanie linkiem jest teraz wyłączone');

        Notification::fake();
        $this->wyslijFormularz($basia->email);
        Notification::assertNothingSent();

        $this->wejdz($link);
        $this->assertGuest();
    }

    // ------------------------------------------------------------------
    //  Pomocnicze
    // ------------------------------------------------------------------

    /**
     * Poproś o link i oddaj adres, KTÓRY NAPRAWDĘ POSZEDŁ W LIŚCIE.
     *
     * Czytamy go z powiadomienia, a nie z bazy — w bazie leży skrót, a chodzi
     * o to, żeby testy chodziły dokładnie tą drogą co człowiek.
     */
    private function popros(string $adres): string
    {
        Notification::fake();

        $this->wyslijFormularz($adres);

        $link = null;

        Notification::assertSentTo(
            User::query()->where('email', User::normalizeEmail($adres))->firstOrFail(),
            LinkDoLogowania::class,
            function (LinkDoLogowania $powiadomienie, array $kanaly, User $odbiorca) use (&$link): bool {
                $link = $powiadomienie->toMail($odbiorca)->viewData['linkUrl'] ?? null;

                return true;
            },
        );

        $this->assertIsString($link, 'Z listu nie dało się wyjąć adresu linku.');

        return $link;
    }

    private function wyslijFormularz(string $adres): TestResponse
    {
        return $this->from(route('login.link'))
            ->post(route('login.link.send'), ['email' => $adres]);
    }

    private function wejdz(string $link): TestResponse
    {
        return $this->from($link)->post(route('login.link.store'), [
            'token' => $this->tokenZLinku($link),
        ]);
    }

    private function tokenZLinku(string $link): string
    {
        return (string) mb_substr($link, mb_strrpos($link, '/') + 1);
    }

    private function komunikat(TestResponse $odpowiedz): string
    {
        return (string) $odpowiedz->getSession()->get('status', '');
    }

    private function uzytkownikZDwuetapowa(): User
    {
        $user = $this->user('basia', ['email' => 'basia@example.com']);

        $totp = app(TwoFactorAuthenticator::class);
        $user->beginTwoFactorSetup($totp->generateSecret());
        $user->confirmTwoFactor($totp->hashBackupCodes($totp->generateBackupCodes()));

        return $user->refresh();
    }
}

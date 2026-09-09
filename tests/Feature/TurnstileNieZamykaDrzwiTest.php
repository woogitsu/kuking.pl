<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Turnstile;
use App\Turnstile\KlientTurnstile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cloudflare Turnstile (D-050, issue #217) — filtr taniego ruchu
 * automatycznego, NIE warunek dostępu.
 *
 * DLACZEGO TEN PLIK JEST TAKI STANOWCZY
 * Turnstile to widget JavaScriptu bez wersji bez JS, a `AGENTS.md` §5 mówi,
 * że rejestracja i odzyskanie hasła działają bez JavaScriptu. Te dwie rzeczy
 * dają się pogodzić tylko w jeden sposób: brak tokenu przepuszczamy,
 * a odrzucamy wyłącznie token, który PRZYSZEDŁ i okazał się nieprawdziwy.
 *
 * Jest to zachowanie, które wygląda na niedokończone. Ktoś kiedyś zobaczy
 * regułę bez `required`, uzna to za przeoczenie i „dokręci" ją jedną linijką
 * — a wtedy rejestrację stracą osoby z wyłączonym skryptem, czytnikiem
 * ekranu, starą przeglądarką albo słabym zasięgiem, w którym skrypt się nie
 * dociągnął. Czyli dokładnie ci, dla których ten serwis powstał.
 *
 * `test_formularz_przechodzi_bez_tokenu_czyli_bez_javascriptu` jest tu
 * najważniejszym testem i jego czerwony wynik NIE JEST powodem, żeby
 * poprawić test.
 */
class TurnstileNieZamykaDrzwiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Klucze testowe Cloudflare („always passes"). Trafiają tylko do
     * konfiguracji tego procesu — pod `siteverify` i tak stoi `Http::fake`.
     */
    private const KLUCZ_PUBLICZNY = '1x00000000000000000000AA';

    private const SEKRET = '1x0000000000000000000000000000000AA';

    /**
     * Komplet z D-050: sześć formularzy, na które wchodzi ktoś niezalogowany.
     *
     * Lista jest tu jawna, a nie wyliczana z `kuking.turnstile.miejsca`,
     * celowo — inaczej test przepuściłby wpis w konfiguracji, którego nikt
     * nie podpiął do żadnego widoku (martwy przełącznik).
     *
     * @var list<string>
     */
    private const FORMULARZE = [
        '/register',
        '/login',
        '/nie-pamietam-hasla',
        '/cofnij-usuniecie-konta',
        '/napisz-do-nas',
        '/zglos-nielegalna-tresc',
    ];

    // ------------------------------------------------------------------
    //  Weryfikacja tokenu
    // ------------------------------------------------------------------

    public function test_formularz_przechodzi_z_prawidlowym_tokenem(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => true, 'hostname' => 'kuking.pl']);

        $this->zarejestruj(['cf-turnstile-response' => 'token-od-widgetu'])
            ->assertRedirect(route('onboarding.interests'));

        $this->assertNotNull(User::findByLogin('basia@example.com'));

        // Token poszedł na `siteverify`, a NASZ SEKRET nie wyszedł nigdzie
        // indziej — to jest cała różnica między weryfikacją a ozdobą.
        Http::assertSent(function (Request $zadanie): bool {
            return $zadanie->url() === KlientTurnstile::ADRES
                && $zadanie['response'] === 'token-od-widgetu'
                && $zadanie['secret'] === self::SEKRET;
        });
    }

    /**
     * TO JEST NAJWAŻNIEJSZY TEST W TEJ PACZCE.
     *
     * Przeglądarka bez JavaScriptu nie wykona widgetu, więc pole
     * `cf-turnstile-response` w ogóle nie powstanie. Formularz ma przejść —
     * i nie ma po co pytać Cloudflare o token, którego nie ma.
     */
    public function test_formularz_przechodzi_bez_tokenu_czyli_bez_javascriptu(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['missing-input-response']]);

        $this->zarejestruj()->assertRedirect(route('onboarding.interests'));

        $this->assertNotNull(
            User::findByLogin('basia@example.com'),
            'Rejestracja bez tokenu Turnstile MUSI się udać (AGENTS.md §5). '
            .'Jeśli ten test jest czerwony, ktoś dołożył `required` — przeczytaj '
            .'App\Rules\TurnstileNieJestPodrobiony, zanim poprawisz test.',
        );

        $this->assertNiePytalismyCloudflare();
    }

    public function test_formularz_odrzuca_token_uznany_przez_cloudflare_za_nieprawidlowy(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $odpowiedz = $this->zarejestruj(['cf-turnstile-response' => 'token-podrobiony']);

        $odpowiedz->assertRedirect('/register');
        $odpowiedz->assertSessionHasErrors(Turnstile::POLE);

        $this->assertNull(
            User::findByLogin('basia@example.com'),
            'Podrobiony token musi zatrzymać rejestrację — inaczej widget jest ozdobą.',
        );

        // Komunikat mówi, CO ZROBIĆ, i nie każe odświeżać strony (to skasowałoby
        // wpisany tekst). Poprawne dane wracają przez `old()`.
        $blad = (string) session('errors')->first(Turnstile::POLE);
        $this->assertStringContainsString('wyślij formularz jeszcze raz', $blad);
        $this->assertStringNotContainsString('Odśwież', $blad);
        $odpowiedz->assertSessionHasInput('display_name', 'Basia');
    }

    public function test_zuzyty_albo_wygasly_token_tez_jest_odrzucany(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['timeout-or-duplicate']]);

        $this->zarejestruj(['cf-turnstile-response' => 'token-sprzed-godziny'])
            ->assertSessionHasErrors(Turnstile::POLE);
    }

    // ------------------------------------------------------------------
    //  Awaria po drugiej stronie nie może zamykać rejestracji
    // ------------------------------------------------------------------

    public function test_gdy_siteverify_nie_odpowiada_formularz_przechodzi(): void
    {
        $this->wlaczTurnstile();

        Http::fake([
            KlientTurnstile::ADRES => static function (): never {
                throw new ConnectionException('Connection timed out after 4000 milliseconds');
            },
        ]);

        $this->zarejestruj(['cf-turnstile-response' => 'token-od-widgetu'])
            ->assertRedirect(route('onboarding.interests'));

        $this->assertNotNull(
            User::findByLogin('basia@example.com'),
            'Niedostępność Cloudflare nie może zamykać rejestracji.',
        );
    }

    public function test_gdy_siteverify_oddaje_piecsetke_formularz_przechodzi(): void
    {
        $this->wlaczTurnstile();

        Http::fake([KlientTurnstile::ADRES => Http::response('Bad gateway', 502)]);

        $this->zarejestruj(['cf-turnstile-response' => 'token-od-widgetu'])
            ->assertRedirect(route('onboarding.interests'));

        $this->assertNotNull(User::findByLogin('basia@example.com'));
    }

    /**
     * Zły sekret w Railway to NASZ błąd konfiguracji, nie wina człowieka przed
     * ekranem. Gdyby zamykał rejestrację, jedna literówka w panelu wyłączałaby
     * wejście do serwisu — a z zewnątrz wyglądałoby to jak działający serwis.
     */
    public function test_zly_sekret_nie_zamyka_rejestracji(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['invalid-input-secret']]);

        $this->zarejestruj(['cf-turnstile-response' => 'token-od-widgetu'])
            ->assertRedirect(route('onboarding.interests'));

        $this->assertNotNull(User::findByLogin('basia@example.com'));
    }

    // ------------------------------------------------------------------
    //  Brak kluczy: nic się nie renderuje, nic nie blokuje
    // ------------------------------------------------------------------

    public function test_bez_kluczy_widget_nie_jest_renderowany(): void
    {
        $this->wylaczTurnstile();
        $this->pocztaDziala();

        foreach (self::FORMULARZE as $adres) {
            $this->get($adres)
                ->assertOk()
                ->assertDontSee('cf-turnstile')
                ->assertDontSee('challenges.cloudflare.com');
        }
    }

    public function test_bez_kluczy_walidacja_nie_blokuje_i_nikogo_nie_pyta(): void
    {
        $this->wylaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['invalid-input-response']]);

        // Nawet z tokenem podstawionym ręcznie: bez kluczy nie mamy czym
        // sprawdzać i nie wolno nam nikogo z tego powodu zatrzymać.
        $this->zarejestruj(['cf-turnstile-response' => 'cokolwiek'])
            ->assertRedirect(route('onboarding.interests'));

        $this->assertNotNull(User::findByLogin('basia@example.com'));
        $this->assertNiePytalismyCloudflare();
    }

    // ------------------------------------------------------------------
    //  Gdzie widget jest, a gdzie świadomie go nie ma
    // ------------------------------------------------------------------

    public function test_widget_stoi_na_wszystkich_szesciu_formularzach_publicznych(): void
    {
        $this->wlaczTurnstile();
        $this->pocztaDziala();

        foreach (self::FORMULARZE as $adres) {
            $odpowiedz = $this->get($adres)->assertOk();

            $odpowiedz->assertSee('cf-turnstile', escape: false);
            $odpowiedz->assertSee(self::KLUCZ_PUBLICZNY);

            // Widget nie jest jedynym nośnikiem informacji (docs/UX_50_PLUS.md):
            // nad obcą ramką stoi zdanie po polsku.
            $odpowiedz->assertSee('nie musisz nic robić', escape: false);

            // SEKRET NIE MA PRAWA POJAWIĆ SIĘ W HTML-u.
            $odpowiedz->assertDontSee(self::SEKRET);
        }
    }

    /**
     * LOGOWANIE MA TURNSTILE — i to jest decyzja właściciela z 9 września
     * 2026 (issue #217): „captcha trzeba normalnie zrobić, ten od cloudflare
     * jest nieinwazyjny". Pierwotny szkic #217 przewidywał tu wariant
     * „dopiero po nieudanych próbach"; nie powstał i nie jest potrzebny,
     * bo tryb Managed przechodzi bez interakcji.
     *
     * Trzy koszyki `login_limits` zostają bez zmian — captcha ich nie
     * zastępuje.
     */
    public function test_logowanie_z_podrobionym_tokenem_nie_przechodzi(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $osoba = $this->user('basia', ['password' => Hash::make('zielonapietruszkarano')]);

        $this->from('/login')->post('/login', [
            'login' => $osoba->email,
            'password' => 'zielonapietruszkarano',
            'cf-turnstile-response' => 'token-podrobiony',
        ])->assertSessionHasErrors(Turnstile::POLE);

        $this->assertGuest();
    }

    /**
     * …ale bez JavaScriptu logowanie MUSI działać dalej. Ta gałąź jest
     * ważniejsza niż captcha: decyzja właściciela dotyczyła inwazyjności
     * widgetu, a nie reguły z `AGENTS.md` §5.
     */
    public function test_logowanie_bez_tokenu_dziala_dalej(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['missing-input-response']]);

        $osoba = $this->user('basia', ['password' => Hash::make('zielonapietruszkarano')]);

        $this->post('/login', [
            'login' => $osoba->email,
            'password' => 'zielonapietruszkarano',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($osoba->fresh());
        $this->assertNiePytalismyCloudflare();
    }

    /**
     * Punktowe wyłączenie jednego miejsca naprawdę działa — przełącznik
     * w konfiguracji nie jest ozdobą. To jest ta sama klasa błędu, której
     * pilnuje reszta tego repozytorium: wpis, który wygląda, jakby coś robił
     * (martwy `kuking.media_disk`, limit `upload` niepodpięty do trasy).
     */
    public function test_wylaczenie_jednego_miejsca_zdejmuje_widget_i_walidacje(): void
    {
        $this->wlaczTurnstile();
        $this->udawajOdpowiedz(['success' => false, 'error-codes' => ['invalid-input-response']]);

        $this->get('/register')->assertOk()->assertSee('cf-turnstile', escape: false);

        config(['kuking.turnstile.miejsca.rejestracja' => false]);

        $this->get('/register')->assertOk()->assertDontSee('cf-turnstile');

        // Nawet token podstawiony ręcznie nie ma prawa nikogo zatrzymać na
        // formularzu, którego Turnstile nie dotyczy — ani wywołać ruchu
        // wychodzącego na cudze API.
        $this->zarejestruj(['cf-turnstile-response' => 'cokolwiek'])
            ->assertRedirect(route('onboarding.interests'));

        $this->assertNiePytalismyCloudflare();
    }

    // ------------------------------------------------------------------
    //  Twardy sygnał: produkcja bez kluczy to błędna konfiguracja
    // ------------------------------------------------------------------

    public function test_produkcja_bez_kluczy_melduje_bledna_konfiguracje(): void
    {
        Artisan::call('storage:link');

        $this->wylaczTurnstile();
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->get('/health')
            // Świadomie 200, nie 503: healthcheck oddający 503 już raz położył
            // ten serwis. Monitoring pilnuje TREŚCI odpowiedzi.
            ->assertOk()
            ->assertJsonPath('status', 'degraded')
            ->assertJsonPath('checks.turnstile.ok', false)
            ->assertJsonPath('checks.turnstile.error', 'turnstile_bez_kluczy')
            // Baza i zdjęcia są całe — sygnał dotyczy WYŁĄCZNIE konfiguracji.
            ->assertJsonPath('checks.database.ok', true)
            ->assertJsonPath('checks.media.ok', true);
    }

    public function test_produkcja_z_kluczami_jest_zdrowa(): void
    {
        Artisan::call('storage:link');

        $this->wlaczTurnstile();
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.turnstile.ok', true);
    }

    /**
     * Poza produkcją brak kluczy jest stanem NORMALNYM (tak stoi w
     * `.env.example`, tak chodzi CI). Stały `degraded` w tych środowiskach
     * byłby szumem, który uczy ignorować to pole.
     */
    public function test_poza_produkcja_brak_kluczy_nie_jest_awaria(): void
    {
        Artisan::call('storage:link');

        $this->wylaczTurnstile();

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.turnstile.ok', true);
    }

    /**
     * Świadome wyłączenie Turnstile wszędzie jest poprawną konfiguracją —
     * wtedy nic nie kłamie i nie ma o czym krzyczeć. Sygnał ma dotyczyć
     * ROZJAZDU między obietnicą a rzeczywistością, nie samego braku kluczy.
     */
    public function test_produkcja_z_turnstile_wylaczonym_wszedzie_jest_zdrowa(): void
    {
        Artisan::call('storage:link');

        $this->wylaczTurnstile();
        config(['kuking.turnstile.miejsca' => array_fill_keys(
            array_keys(Turnstile::miejsca()),
            false,
        )]);
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.turnstile.ok', true);
    }

    // ------------------------------------------------------------------
    //  Polityka bezpieczeństwa musi wpuścić widget — i tylko wtedy, gdy jest
    // ------------------------------------------------------------------

    public function test_polityka_wpuszcza_widget_dopiero_z_kluczami(): void
    {
        $this->wylaczTurnstile();
        $bez = (string) $this->get('/register')->headers->get('Content-Security-Policy');

        $this->assertStringNotContainsString('challenges.cloudflare.com', $bez);
        $this->assertStringNotContainsString('frame-src', $bez);

        $this->wlaczTurnstile();
        $z = (string) $this->get('/register')->headers->get('Content-Security-Policy');

        // Widget dociąga własne skrypty i rysuje się w ramce — bez obu tych
        // dyrektyw przeglądarka pokazałaby puste miejsce zamiast sprawdzenia.
        $this->assertMatchesRegularExpression(
            '/script-src [^;]*https:\/\/challenges\.cloudflare\.com/',
            $z,
        );
        $this->assertMatchesRegularExpression(
            '/frame-src [^;]*https:\/\/challenges\.cloudflare\.com/',
            $z,
        );

        // I ANI KROKU DALEJ: `unsafe-inline` w stylach skasowałoby efekt #107.
        $this->assertStringNotContainsString("style-src 'self' 'unsafe-inline'", $z);
    }

    // ------------------------------------------------------------------

    private function wlaczTurnstile(): void
    {
        config([
            'kuking.turnstile.klucz_publiczny' => self::KLUCZ_PUBLICZNY,
            'kuking.turnstile.sekret' => self::SEKRET,
        ]);
    }

    /**
     * Ekran „Nie pamiętam hasła" chowa CAŁY formularz, gdy serwis nie ma czym
     * wysyłać poczty (`App\Support\Poczta`) — a w testach sterownikiem jest
     * `array`. Bez tego sprawdzalibyśmy brak widgetu na stronie, która i tak
     * nie ma formularza.
     */
    private function pocztaDziala(): void
    {
        config(['mail.default' => 'smtp']);
    }

    private function wylaczTurnstile(): void
    {
        config([
            'kuking.turnstile.klucz_publiczny' => '',
            'kuking.turnstile.sekret' => '',
        ]);
    }

    /**
     * @param  array<string, mixed>  $tresc
     */
    private function udawajOdpowiedz(array $tresc): void
    {
        Http::fake([KlientTurnstile::ADRES => Http::response($tresc)]);
    }

    /**
     * `assertNotSent` z filtrem po adresie, a nie `assertNothingSent`:
     * rejestracja pyta jeszcze o wyciek hasła (`Password::uncompromised`),
     * więc „zero żądań" byłoby asercją o czymś innym.
     */
    private function assertNiePytalismyCloudflare(): void
    {
        Http::assertNotSent(
            static fn (Request $zadanie): bool => $zadanie->url() === KlientTurnstile::ADRES,
        );
    }

    /**
     * @param  array<string, string>  $dodatkowe
     */
    private function zarejestruj(array $dodatkowe = []): TestResponse
    {
        return $this->from('/register')->post('/register', [
            'display_name' => 'Basia',
            'username' => 'basia_z_podkarpacia',
            'email' => 'basia@example.com',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
            ...$dodatkowe,
        ]);
    }
}

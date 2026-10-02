<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LoginLinkToken;
use App\Models\User;
use App\Notifications\LinkDoLogowania;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * „Zapamiętaj mnie na tym urządzeniu” przy każdej drodze logowania (#2708, pyt. 15,
 * decyzja właściciela z 2.10.2026).
 *
 * Zaznaczone (domyślnie) albo brak pola w żądaniu = ciasteczko `remember_web_*`
 * jak dotąd. Odznaczone (ukryte `0`) = zwykła sesja, bez tego ciasteczka.
 */
class ZapamietajMnieNaTymUrzadzeniuTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    private const HASLO = 'zielona-pietruszka-rano';

    private const KLIENT = 'klient-testowy.apps.googleusercontent.com';

    private const FB_ID = '1234567890123456';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['mail.default' => 'smtp']);
    }

    private function maCiasteczko(TestResponse $odpowiedz): bool
    {
        foreach ($odpowiedz->headers->getCookies() as $ciasteczko) {
            if (str_starts_with($ciasteczko->getName(), 'remember_web_') && $ciasteczko->getValue() !== null && $ciasteczko->getValue() !== '') {
                return true;
            }
        }

        return false;
    }

    // ---------------------------------------------------------------- hasło

    /** @return array<string, array{0: array<string, string>, 1: bool}> */
    public static function wybory(): array
    {
        return [
            'zaznaczone' => [['zapamietaj' => '1'], true],
            'odznaczone' => [['zapamietaj' => '0'], false],
            'stary formularz bez pola' => [[], true],
        ];
    }

    /** @param array<string, string> $pole */
    #[DataProvider('wybory')]
    public function test_logowanie_haslem(array $pole, bool $ciasteczko): void
    {
        $this->user('basia', ['email' => 'basia@example.test', 'password' => Hash::make(self::HASLO)]);

        $odpowiedz = $this->post(route('login'), ['login' => 'basia@example.test', 'password' => self::HASLO] + $pole);

        $odpowiedz->assertRedirect();
        $this->assertAuthenticated();
        $this->assertSame($ciasteczko, $this->maCiasteczko($odpowiedz));
    }

    // ---------------------------------------------------------- link e-mail

    /** @param array<string, string> $pole */
    #[DataProvider('wybory')]
    public function test_logowanie_linkiem_z_listu(array $pole, bool $ciasteczko): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $this->post(route('login.link.send'), ['email' => $basia->email]);

        $link = null;
        Notification::assertSentTo($basia, LinkDoLogowania::class, function (LinkDoLogowania $p, array $k, User $odbiorca) use (&$link): bool {
            $link = $p->toMail($odbiorca)->viewData['linkUrl'] ?? null;

            return true;
        });
        $this->assertIsString($link);

        $this->assertGreaterThan(0, LoginLinkToken::query()->count());
        $token = (string) mb_substr($link, mb_strrpos($link, '/') + 1);

        $odpowiedz = $this->from($link)->post(route('login.link.store'), ['token' => $token] + $pole);

        $odpowiedz->assertRedirect(route('home'));
        $this->assertAuthenticatedAs($basia);
        $this->assertSame($ciasteczko, $this->maCiasteczko($odpowiedz));
    }

    // ----------------------------------------------------------- rejestracja

    /** @param array<string, string> $pole */
    #[DataProvider('wybory')]
    public function test_rejestracja(array $pole, bool $ciasteczko): void
    {
        $odpowiedz = $this->post(route('register'), [
            'display_name' => 'Nowa Osoba', 'username' => 'nowaosoba',
            'email' => 'nowaosoba@example.test', 'password' => self::HASLO,
            'age_confirmed' => '1', 'terms_accepted' => '1',
        ] + $pole);

        $odpowiedz->assertRedirect(route('onboarding.interests'));
        $this->assertAuthenticated();
        $this->assertSame($ciasteczko, $this->maCiasteczko($odpowiedz));
    }

    // ------------------------------------------------------------ Google

    private function wlaczGoogle(): void
    {
        config([
            'kuking.google.wlaczone' => true,
            'kuking.google.identyfikator_klienta' => self::KLIENT,
            'kuking.google.sekret_klienta' => 'sekret-testowy',
        ]);
    }

    private function wracamyZGoogle(array $start): TestResponse
    {
        $this->get(route('google.start', $start))->assertRedirect();
        $nonce = (string) session('wejscie_google.nonce');
        $czesc = static fn (array $tresc): string => rtrim(strtr(base64_encode((string) json_encode($tresc)), '+/', '-_'), '=');
        $token = $czesc(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$czesc([
            'iss' => 'https://accounts.google.com', 'aud' => self::KLIENT, 'sub' => '109876543210987654321',
            'email' => 'basia@example.test', 'email_verified' => true, 'given_name' => 'Basia',
            'exp' => time() + 3600, 'nonce' => $nonce,
        ]).'.podpis';
        Http::fake(['oauth2.googleapis.com/*' => Http::response([
            'access_token' => 'x', 'expires_in' => 3599, 'token_type' => 'Bearer', 'id_token' => $token,
        ])]);

        return $this->get(route('google.callback', ['code' => 'kod', 'state' => (string) session('wejscie_google.state')]));
    }

    /** @param array<string, string> $pole */
    #[DataProvider('wybory')]
    public function test_google_istniejace_konto(array $pole, bool $ciasteczko): void
    {
        $this->wlaczGoogle();
        $this->user('basia', ['email' => 'basia@example.test'])->connectGoogle('109876543210987654321');

        $odpowiedz = $this->wracamyZGoogle($pole);

        $odpowiedz->assertRedirect();
        $this->assertAuthenticated();
        $this->assertSame($ciasteczko, $this->maCiasteczko($odpowiedz));
    }

    /** @param array<string, string> $pole */
    #[DataProvider('wybory')]
    public function test_google_nowe_konto_po_domknieciu(array $pole, bool $ciasteczko): void
    {
        $this->wlaczGoogle();

        $this->wracamyZGoogle($pole)->assertRedirect(route('google.finish'));
        $odpowiedz = $this->post(route('google.finish.store'), [
            'display_name' => 'Basia', 'username' => 'basia', 'age_confirmed' => '1', 'terms_accepted' => '1',
        ]);

        $odpowiedz->assertRedirect(route('onboarding.interests'));
        $this->assertAuthenticated();
        $this->assertSame($ciasteczko, $this->maCiasteczko($odpowiedz));
    }

    // ---------------------------------------------------------- Facebook

    /** @param array<string, string> $pole */
    #[DataProvider('wybory')]
    public function test_facebook_istniejace_konto(array $pole, bool $ciasteczko): void
    {
        config([
            'kuking.facebook.wlaczone' => true,
            'kuking.facebook.identyfikator_klienta' => '1234567890',
            'kuking.facebook.sekret_klienta' => 'sekret-testowy',
        ]);
        $this->user('basia', ['email' => 'basia@example.test'])->connectFacebook(self::FB_ID);

        $this->get(route('facebook.start', $pole))->assertRedirect();
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'x', 'token_type' => 'bearer', 'expires_in' => 5183944]),
            'graph.facebook.com/*/me*' => Http::response(['id' => self::FB_ID, 'name' => 'Basia Kowalska', 'email' => 'basia@example.test']),
        ]);
        $odpowiedz = $this->get(route('facebook.callback', ['code' => 'kod', 'state' => (string) session('wejscie_facebook.state')]));

        $odpowiedz->assertRedirect();
        $this->assertAuthenticated();
        $this->assertSame($ciasteczko, $this->maCiasteczko($odpowiedz));
    }

    // -------------------------------------------------------------- ekrany

    public function test_ekrany_logowania_i_rejestracji_maja_pole_domyslnie_zaznaczone_z_pomoca(): void
    {
        foreach ([route('login'), route('register')] as $adres) {
            $html = (string) $this->get($adres)->assertOk()->getContent();

            $this->assertMatchesRegularExpression('/<input[^>]*id="f-zapamietaj"[^>]*type="checkbox"[^>]*checked/s', $html, "Brak zaznaczonego pola na {$adres}.");
            // Ukryte `0` przed polem: bez niego odznaczenie nie różniłoby się od starego formularza.
            $this->assertMatchesRegularExpression('/<input type="hidden" name="zapamietaj" value="0"\s*>\s*<label class="choice" for="f-zapamietaj">/', $html, "Brak ukrytego „0” przed polem na {$adres}.");
            $this->assertStringContainsString('Zapamiętaj mnie na tym urządzeniu', $html);
            $this->assertStringContainsString('Na cudzym lub wspólnym komputerze odznacz to pole.', $html);
        }
    }

    public function test_ekran_z_linku_e_mail_ma_pole_domyslnie_zaznaczone(): void
    {
        $basia = $this->user('basia', ['email' => 'basia@example.test']);
        $this->post(route('login.link.send'), ['email' => $basia->email]);
        $link = null;
        Notification::assertSentTo($basia, LinkDoLogowania::class, function (LinkDoLogowania $p, array $k, User $odbiorca) use (&$link): bool {
            $link = $p->toMail($odbiorca)->viewData['linkUrl'] ?? null;

            return true;
        });

        $html = (string) $this->get((string) $link)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*id="f-zapamietaj"[^>]*type="checkbox"[^>]*checked/s', $html);
        $this->assertStringContainsString('Na cudzym lub wspólnym komputerze odznacz to pole.', $html);
    }

    public function test_przyciski_google_i_facebook_sa_w_formularzu_z_polem(): void
    {
        $this->wlaczGoogle();
        config([
            'kuking.facebook.wlaczone' => true,
            'kuking.facebook.identyfikator_klienta' => '1234567890',
            'kuking.facebook.sekret_klienta' => 'sekret-testowy',
        ]);

        $html = (string) $this->get(route('login'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<form id="wejscie-dostawcy-logowanie" method="GET"[^>]*><\/form>.*id="f-zapamietaj-dostawca".*form="wejscie-dostawcy-logowanie".*formaction="[^"]*wejdz\/google".*formaction="[^"]*wejdz\/facebook"/s', $html);
    }
}

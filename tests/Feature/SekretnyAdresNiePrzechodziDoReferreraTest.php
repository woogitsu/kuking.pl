<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LoginLinkToken;
use App\Models\RegistrationInvite;
use App\Support\AnalitykaCloudflare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** HTTP mierzy nagłówki. Przejście między dokumentami mierzy osobny skrypt przeglądarkowy. */
final class SekretnyAdresNiePrzechodziDoReferreraTest extends TestCase
{
    use RefreshDatabase;

    public static function sensitiveQueries(): iterable
    {
        foreach (['token', 'email', 'hash', 'cel'] as $field) {
            foreach (['=TEST_1052_QUERY', '=', '[]=TEST_1052_QUERY'] as $value) {
                foreach ([false, true] as $analytics) {
                    yield $field.$value.'/'.(int) $analytics => [$field.$value, $analytics];
                }
            }
        }
    }

    #[DataProvider('sensitiveQueries')]
    public function test_wspolna_klasyfikacja_chroni_query_takze_bez_analityki(string $query, bool $analytics): void
    {
        config()->set('kuking.analytics.cloudflare.token', $analytics ? 'test-1052' : '');
        $this->get('/login?'.$query)->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')->assertDontSee('data-cf-beacon', false);
        $ordinary = $this->get('/login')->assertOk()
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        if ($analytics) {
            $ordinary->assertSee(AnalitykaCloudflare::adresSkryptu(), false);
        } else {
            $ordinary->assertDontSee('data-cf-beacon', false);
        }
    }

    public function test_rzeczywiste_ekrany_z_tokenem_i_bramka_linku(): void
    {
        config()->set('kuking.analytics.cloudflare.token', 'test-1052');
        foreach (['/nowe-haslo/TEST_1052_PATH', '/logowanie/link/TEST_1052_PATH', '/zaproszenie/TEST_1052_PATH'] as $path) {
            $this->get($path)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertDontSee('data-cf-beacon', false);
        }
        $this->get('/otworz-link?cel='.urlencode(Crypt::encryptString('https://example.org/test')))
            ->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get('/otworz-link?cel=TEST_1052_INVALID')
            ->assertNotFound()->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_potwierdzenie_email_chroni_przekierowanie_i_odmowe(): void
    {
        $user = $this->user(null, ['email_verified_at' => null]);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), [
            'id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification()),
        ]);
        $this->actingAs($user)->get($url)->assertRedirect()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get('/potwierdz-email/'.$user->getKey().'/TEST_1052_INVALID')
            ->assertForbidden()->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_prawidlowe_i_wygasle_linki_chronia_prawdziwy_formularz_i_odmowe(): void
    {
        config()->set('kuking.login_link.wlaczone', true);
        config()->set('kuking.login_link.zaproszenia.wlaczone', true);
        $user = $this->user();
        foreach ([LoginLinkToken::class, RegistrationInvite::class] as $model) {
            $token = $model::nowyToken();
            $record = (new $model)->forceFill([
                ...($model === LoginLinkToken::class ? ['user_id' => $user->getKey()] : ['email' => 'fixture1052@example.test']),
                'token_hash' => $model::skrot($token), 'created_at' => now(), 'expires_at' => now()->addMinutes(10),
            ]);
            $record->save();
            $path = ($model === LoginLinkToken::class ? '/logowanie/link/' : '/zaproszenie/').$token;
            $view = $model === LoginLinkToken::class ? 'auth.login-link-confirm' : 'auth.zaproszenie';
            $this->get($path)->assertOk()->assertViewIs($view)
                ->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('name="token"', false);
            $record->forceFill(['created_at' => now()->subMinutes(20), 'expires_at' => now()->subMinute()])->save();
            $this->get($path)->assertOk()
                ->assertViewIs($model === LoginLinkToken::class ? 'auth.login-link-unavailable' : 'auth.zaproszenie-nieaktualne')
                ->assertHeader('Referrer-Policy', 'no-referrer')->assertDontSee('name="token"', false);
        }
    }

    public function test_nowa_trasa_z_parametrem_i_wlasnym_csp_nie_omija_ochrony(): void
    {
        Route::middleware('web')->get('/_1052/nowa/{token}', fn () => response('fixture')->withHeaders([
            'Content-Security-Policy' => "default-src 'none'",
            'Referrer-Policy' => 'unsafe-url',
        ]));
        $response = $this->get('/_1052/nowa/TEST_1052_PATH')->assertOk()
            ->assertHeader('Content-Security-Policy', "default-src 'none'")
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertCount(1, $response->headers->all('Referrer-Policy'));
        Route::middleware('web')->get('/_1052/zwykla', fn () => response('fixture')->withHeaders([
            'Content-Security-Policy' => "default-src 'none'", 'Referrer-Policy' => 'same-origin',
        ]));
        $this->get('/_1052/zwykla')->assertOk()->assertHeader('Referrer-Policy', 'same-origin');
    }

    public function test_dwie_warstwy_nie_zmieniaja_nonce_ani_nie_powielaja_naglowka(): void
    {
        $response = $this->get('/nowe-haslo/TEST_1052_PATH')->assertOk();
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertCount(1, $response->headers->all('Referrer-Policy'));
        $this->assertCount(1, $response->headers->all('Content-Security-Policy'));
        preg_match("/'nonce-([^']+)'/", $response->headers->get('Content-Security-Policy'), $nonce);
        $this->assertNotEmpty($nonce[1]);

        // DLACZEGO NIE `assertSee('nonce="…"')` NA HTML-u.
        //
        // Ta strona w teście NIE MA ANI JEDNEGO znacznika, który mógłby nonce
        // ponieść: `Tests\TestCase::setUp()` woła `withoutVite()`, więc `@vite`
        // nic nie emituje, a poza tym `/nowe-haslo` nie ma własnych skryptów.
        // Zmierzone: zero `<script>` i zero wystąpień `nonce=` w odpowiedzi.
        //
        // Asercja na HTML przechodziła WYŁĄCZNIE przez PRZECIEK: gdy wcześniej
        // w tym samym procesie jakiś test wyrenderował komponent Livewire'a,
        // `SupportAutoInjectedAssets` doklejało `@livewireScripts` do każdej
        // następnej odpowiedzi 200 text/html — i to ten wstrzyknięty skrypt
        // niósł nonce. Pomiar wprost na czystym `main`: ten test SAM oblewa,
        // a puszczony po `KazdaTrasaZIdentyfikatoremPodPolicyTest` przechodzi
        // (38/38). Zieleń brała się z cudzego stanu, nie z zachowania CSP.
        //
        // Sprawdzamy więc to, co ten test NAPRAWDĘ ma pilnować i co da się
        // sprawdzić bez żadnego wyrenderowanego assetu: że nagłówek niesie
        // DOKŁADNIE ten nonce, który aplikacja wydała jako swój jedyny
        // (`Vite::useCspNonce()` w `ApplySecurityHeaders`). Gdyby druga
        // warstwa middleware wygenerowała własny, te dwie wartości by się
        // rozjechały — czyli dokładnie regresja z nazwy tego testu.
        $this->assertSame(
            $nonce[1],
            Vite::cspNonce(),
            'Nagłówek CSP niesie inny nonce niż ten, który aplikacja wydała jako swój jedyny — '
            .'druga warstwa middleware wygenerowała własny.',
        );
    }
}

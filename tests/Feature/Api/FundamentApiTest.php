<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\PersonalAccessToken;
use App\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiter as Limiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Fundament `/api/v1` (D-270): wyłącznik, uwierzytelnianie tokenem, format
 * błędów po polsku i dwa limity żądań.
 *
 * Trasy sprawdzające rejestruje ten test sam, w grupie `api` i pod tym samym
 * prefiksem co `routes/api.php` — etap 1 nie ma jeszcze tras produkcyjnych,
 * a fundament ma być zmierzony, zanim ktoś na nim postawi pierwszą. Test
 * `test_zamkniete_api_odpowiada_404_na_kazdej_trasie_z_pliku_tras` chodzi
 * po PRAWDZIWEJ tablicy tras, więc obejmie każdą, która dojdzie później.
 */
class FundamentApiTest extends TestCase
{
    use RefreshDatabase;

    private const ZDANIE_404 = 'Nie ma tu niczego takiego. Mogło zostać usunięte albo adres jest niepełny.';

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.api.wlaczone' => true]);
        config(['kuking.api.limity.na_adres' => '1000,1', 'kuking.api.limity.na_token' => '1000,1']);

        Route::middleware('api')->prefix('api/v1/_proba')->group(function (): void {
            Route::get('/otwarta', fn () => ['ok' => true]);

            Route::middleware('auth:sanctum')->group(function (): void {
                Route::get('/ja', fn (Request $request) => ['id' => $request->user()?->getKey()]);
                Route::post('/walidacja', function (Request $request) {
                    $request->validate(['body' => ['required', 'string']]);

                    return ['ok' => true];
                });
                Route::get('/odmowa', fn () => Gate::authorize('proba-odmowa'));
                Route::get('/odmowa-z-powodem', fn () => Gate::authorize('proba-odmowa-z-powodem'));
                Route::get('/wpis/{post}', fn (Post $post) => ['id' => $post->getKey()])->whereUuid('post');
                Route::get('/awaria', fn () => throw new RuntimeException('SQLSTATE[42P01] host=10.0.0.5 haslo=tajne'));
                Route::get('/regula', fn () => throw new BladDlaCzlowieka('Nie można obserwować samego siebie.'));
            });
        });

        Gate::define('proba-odmowa', fn () => false);
        Gate::define('proba-odmowa-z-powodem', fn () => Response::deny('Tę treść widzą tylko osoby obserwowane przez autora.'));
    }

    // --------------------------------------------------------------
    //  Wyłącznik
    // --------------------------------------------------------------

    public function test_domyslnie_api_jest_zamkniete(): void
    {
        $konfiguracja = require config_path('kuking.php');

        $this->assertFalse($konfiguracja['api']['wlaczone'],
            'Bez KUKING_API_ENABLED API ma być zamknięte — otwarcie jest decyzją wdrożeniową, nie skutkiem merge\'a.');
    }

    public function test_zamkniete_api_odpowiada_tym_samym_404_na_trase_istniejaca_i_nieistniejaca(): void
    {
        config(['kuking.api.wlaczone' => false]);
        [, $token] = $this->osobaZTokenem();

        $istniejaca = $this->getJson('/api/v1/_proba/ja', ['Authorization' => 'Bearer '.$token]);
        $bezTokenu = $this->getJson('/api/v1/_proba/ja');
        $nieistniejaca = $this->getJson('/api/v1/tego-nie-ma');

        foreach ([$istniejaca, $bezTokenu, $nieistniejaca] as $odpowiedz) {
            $odpowiedz->assertNotFound()->assertExactJson(['message' => self::ZDANIE_404, 'code' => 'nie_znaleziono']);
        }
    }

    public function test_zamkniete_api_nie_zjada_licznika_limitu(): void
    {
        config(['kuking.api.wlaczone' => false, 'kuking.api.limity.na_adres' => '2,1']);

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/_proba/otwarta')->assertNotFound();
        }

        config(['kuking.api.wlaczone' => true]);

        $this->getJson('/api/v1/_proba/otwarta')->assertOk();
    }

    public function test_zamkniete_api_odpowiada_404_na_kazdej_trasie_z_pliku_tras(): void
    {
        config(['kuking.api.wlaczone' => false]);
        [, $token] = $this->osobaZTokenem();

        $sprawdzone = 0;

        foreach (Route::getRoutes() as $trasa) {
            if (! str_starts_with($trasa->uri(), 'api/')) {
                continue;
            }

            $adres = '/'.preg_replace('/\{[^}]+\}/', (string) Str::uuid7(), $trasa->uri());

            foreach ($trasa->methods() as $metoda) {
                if ($metoda === 'HEAD') {
                    continue;
                }

                $sprawdzone++;

                $this->json($metoda, $adres, [], ['Authorization' => 'Bearer '.$token])
                    ->assertNotFound()
                    ->assertJsonPath('code', 'nie_znaleziono');
            }
        }

        $this->assertGreaterThanOrEqual(8, $sprawdzone,
            'Skan nie widzi tras pod /api — zero tras wygląda jak komplet.');
    }

    // --------------------------------------------------------------
    //  Uwierzytelnianie
    // --------------------------------------------------------------

    public function test_wazny_token_wchodzi_kontrola_dodatnia(): void
    {
        [$osoba, $token] = $this->osobaZTokenem();

        $this->getJson('/api/v1/_proba/ja', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertExactJson(['id' => $osoba->getKey()]);
    }

    public function test_brak_tokenu_to_401_po_polsku(): void
    {
        $this->getJson('/api/v1/_proba/ja')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Zaloguj się w aplikacji jeszcze raz. Ten dostęp wygasł albo został odwołany.',
                'code' => 'brak_logowania',
            ]);
    }

    public function test_bez_naglowka_accept_dalej_jest_401_w_json_a_nie_przekierowanie(): void
    {
        $this->get('/api/v1/_proba/ja')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('code', 'brak_logowania');
    }

    /**
     * Pakiet woła `find($id)` na tym, co stoi przed `|`. Na kolumnie `uuid`
     * PostgreSQL odpowiada na „abc" błędem składni — bez naszego
     * `PersonalAccessToken::findToken()` każdy z tych nagłówków dawał 500.
     */
    public function test_smieciowy_albo_cudzy_token_to_401_a_nie_500(): void
    {
        [, $token] = $this->osobaZTokenem();
        [$id] = explode('|', $token, 2);

        $zle = [
            'abc',
            'abc|def',
            '12|'.Str::random(40),
            (string) Str::uuid7().'|'.Str::random(40),
            $id.'|'.Str::random(40),
            $id.'|',
            "'; DROP TABLE users; --|x",
        ];

        foreach ($zle as $naglowek) {
            $this->getJson('/api/v1/_proba/ja', ['Authorization' => 'Bearer '.$naglowek])
                ->assertUnauthorized();
        }
    }

    public function test_sesja_z_www_nie_otwiera_api(): void
    {
        $osoba = $this->user('zalogowanawww');

        $this->actingAs($osoba, 'web')
            ->getJson('/api/v1/_proba/ja')
            ->assertUnauthorized();
    }

    public function test_token_skasowany_razem_z_sesjami_przestaje_dzialac(): void
    {
        [$osoba, $token] = $this->osobaZTokenem();

        $this->getJson('/api/v1/_proba/ja', ['Authorization' => 'Bearer '.$token])->assertOk();

        $osoba->invalidateSessions();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/_proba/ja', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
    }

    public function test_odpowiedz_api_nie_zaklada_sesji_ani_ciasteczek(): void
    {
        [, $token] = $this->osobaZTokenem();

        $odpowiedz = $this->getJson('/api/v1/_proba/ja', ['Authorization' => 'Bearer '.$token]);

        $odpowiedz->assertOk();
        $this->assertSame([], $odpowiedz->headers->getCookies());
    }

    // --------------------------------------------------------------
    //  Format błędów
    // --------------------------------------------------------------

    public function test_walidacja_to_422_z_komunikatem_z_lang_pl(): void
    {
        [, $token] = $this->osobaZTokenem();

        $odpowiedz = $this->postJson('/api/v1/_proba/walidacja', [], ['Authorization' => 'Bearer '.$token]);

        $odpowiedz->assertUnprocessable()
            ->assertJsonPath('code', 'bledne_dane')
            ->assertJsonPath('message', 'Popraw zaznaczone pola i wyślij jeszcze raz.');

        $komunikat = $odpowiedz->json('errors.body.0');
        $this->assertSame(__('validation.required', ['attribute' => __('validation.attributes.body')]), $komunikat);
        $this->assertStringStartsWith('Uzupełnij pole', (string) $komunikat, 'Komunikat walidacji nie przyszedł z lang/pl.');
    }

    public function test_odmowa_policy_to_403_po_polsku(): void
    {
        [, $token] = $this->osobaZTokenem();

        $this->getJson('/api/v1/_proba/odmowa', ['Authorization' => 'Bearer '.$token])
            ->assertForbidden()
            ->assertExactJson(['message' => 'Nie masz dostępu do tej treści.', 'code' => 'brak_dostepu']);

        $this->getJson('/api/v1/_proba/odmowa-z-powodem', ['Authorization' => 'Bearer '.$token])
            ->assertForbidden()
            ->assertJsonPath('message', 'Tę treść widzą tylko osoby obserwowane przez autora.');
    }

    public function test_nieistniejacy_obiekt_i_zly_identyfikator_to_404_a_nie_500(): void
    {
        [, $token] = $this->osobaZTokenem();

        foreach ([(string) Str::uuid7(), 'nie-uuid'] as $id) {
            $this->getJson('/api/v1/_proba/wpis/'.$id, ['Authorization' => 'Bearer '.$token])
                ->assertNotFound()
                ->assertExactJson(['message' => self::ZDANIE_404, 'code' => 'nie_znaleziono']);
        }
    }

    public function test_zla_metoda_to_405_po_polsku(): void
    {
        [, $token] = $this->osobaZTokenem();

        $this->deleteJson('/api/v1/_proba/ja', [], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(405)
            ->assertJsonPath('code', 'zla_metoda');
    }

    public function test_awaria_to_500_bez_tresci_wyjatku_nawet_z_app_debug(): void
    {
        config(['app.debug' => true]);
        [, $token] = $this->osobaZTokenem();

        $odpowiedz = $this->getJson('/api/v1/_proba/awaria', ['Authorization' => 'Bearer '.$token]);

        $odpowiedz->assertStatus(500)->assertExactJson([
            'message' => 'Coś poszło nie tak po naszej stronie. Spróbuj jeszcze raz za chwilę.',
            'code' => 'blad_serwera',
        ]);

        foreach (['SQLSTATE', '10.0.0.5', 'tajne', 'RuntimeException', 'trace'] as $slad) {
            $this->assertStringNotContainsString($slad, (string) $odpowiedz->getContent());
        }
    }

    public function test_blad_dla_czlowieka_wychodzi_ze_swoim_zdaniem(): void
    {
        [, $token] = $this->osobaZTokenem();

        $this->getJson('/api/v1/_proba/regula', ['Authorization' => 'Bearer '.$token])
            ->assertUnprocessable()
            ->assertExactJson(['message' => 'Nie można obserwować samego siebie.', 'code' => 'odmowa']);
    }

    // --------------------------------------------------------------
    //  Limity
    // --------------------------------------------------------------

    public function test_limit_na_adres_liczy_takze_zadania_bez_tokenu_i_z_falszywym_tokenem(): void
    {
        config(['kuking.api.limity.na_adres' => '3,1']);

        $this->getJson('/api/v1/_proba/otwarta')->assertOk();
        $this->getJson('/api/v1/_proba/ja', ['Authorization' => 'Bearer abc|def'])->assertUnauthorized();
        $this->getJson('/api/v1/_proba/ja')->assertUnauthorized();

        $odbicie = $this->getJson('/api/v1/_proba/otwarta');

        $odbicie->assertStatus(429)->assertJsonPath('code', 'za_duzo_prob');
        $this->assertTrue($odbicie->headers->has('Retry-After'), '429 bez Retry-After — aplikacja nie wie, ile czekać.');
        $this->assertMatchesRegularExpression('/^Za dużo prób w krótkim czasie\. Spróbuj jeszcze raz za \d+ s\.$/u', (string) $odbicie->json('message'));
    }

    public function test_limit_na_token_odcina_jedno_urzadzenie_a_nie_drugie(): void
    {
        config(['kuking.api.limity.na_token' => '2,1']);

        [, $pierwszy] = $this->osobaZTokenem();
        [$drugaOsoba, $drugi] = $this->osobaZTokenem();

        $this->getJson('/api/v1/_proba/ja', ['Authorization' => 'Bearer '.$pierwszy])->assertOk();
        $this->getJson('/api/v1/_proba/ja', ['Authorization' => 'Bearer '.$pierwszy])->assertOk();
        $this->getJson('/api/v1/_proba/ja', ['Authorization' => 'Bearer '.$pierwszy])
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        // Strażnik trzyma rozpoznaną osobę do końca procesu — w teście to
        // jeden proces na wiele żądań, na produkcji każde żądanie ma własny.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/_proba/ja', ['Authorization' => 'Bearer '.$drugi])
            ->assertOk()
            ->assertJsonPath('id', $drugaOsoba->getKey());
    }

    /**
     * Regresja: `RateLimiter::for()` w `boot()` tworzył singleton limitera
     * przy starcie aplikacji, z magazynem cache z tej chwili. Test startu
     * kontenera (StartKonteneraNieCzysciCacheTest) przełącza potem
     * `cache.default` na `database` i jego kontrola dodatnia `cache:clear`
     * przestała cokolwiek zerować.
     */
    public function test_limiter_api_nie_tworzy_rate_limitera_przy_starcie_aplikacji(): void
    {
        $this->assertFalse($this->app->resolved(Limiter::class), 'Limiter powstał już przy starcie aplikacji.');

        // Kontrola dodatnia: przy pierwszym użyciu limiter `api` jest na miejscu.
        $this->assertNotNull(RateLimiter::limiter('api'));
    }

    // --------------------------------------------------------------
    //  Pomocnicze
    // --------------------------------------------------------------

    /**
     * @return array{0: User, 1: string}
     */
    private function osobaZTokenem(): array
    {
        $osoba = $this->user();
        $token = $osoba->createToken('Telefon testowy')->plainTextToken;

        $this->assertInstanceOf(PersonalAccessToken::class, PersonalAccessToken::findToken($token));

        return [$osoba, $token];
    }
}

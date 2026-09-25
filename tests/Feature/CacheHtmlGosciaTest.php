<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Support\PublicznyHtmlGoscia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * #610: HTML gościa na brzegu Cloudflare. Macierz „kto pyta × jaka strona”
 * i kontrola dodatnia: przy wyłączonej flagi nic się nie zmienia, a przy
 * włączonej WYŁĄCZNIE gość bez ciasteczek na trzech trasach dostaje `public`.
 */
class CacheHtmlGosciaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: string, 1: string, 2: string} landing, przepis, profil */
    private function strony(): array
    {
        $author = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $author->id, 'visibility' => 'public']);

        return ['/', $recipe->url(), route('profile.show', $author->profile->username)];
    }

    private function assertPrywatna(TestResponse $response, string $opis): void
    {
        $this->assertTrue($response->headers->hasCacheControlDirective('private'), $opis);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'), $opis);
        $this->assertFalse($response->headers->hasCacheControlDirective('public'), $opis);
        $this->assertFalse($response->headers->hasCacheControlDirective('s-maxage'), $opis);
    }

    private function assertPubliczna(TestResponse $response, int $sekundy, string $opis): void
    {
        $response->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('public'), $opis);
        $this->assertSame('0', $response->headers->getCacheControlDirective('max-age'), $opis);
        $this->assertSame((string) $sekundy, $response->headers->getCacheControlDirective('s-maxage'), $opis);
        $this->assertFalse($response->headers->hasCacheControlDirective('private'), $opis);
        $this->assertFalse($response->headers->hasCacheControlDirective('no-store'), $opis);
        $this->assertCount(0, $response->headers->getCookies(), $opis.': publiczna strona nie może rozdawać ciasteczek.');
        $this->assertContains('Cookie', $response->getVary(), $opis);
        $this->assertContains('Authorization', $response->getVary(), $opis);
        $this->assertStringNotContainsString('name="_token"', $response->getContent(), $opis);
        $this->assertStringNotContainsString('csrf-token', $response->getContent(), $opis);
    }

    public function test_wylaczone_domyslnie_strony_goscia_zostaja_z_sesja_i_no_store(): void
    {
        $this->assertSame(0, PublicznyHtmlGoscia::sekundy());
        foreach ($this->strony() as $path) {
            $response = $this->get($path)->assertOk();
            $this->assertPrywatna($response, $path);
            $this->assertNotEmpty($response->headers->getCookies(), $path);
        }
    }

    public function test_gosc_bez_ciasteczek_dostaje_publiczny_html_bez_sesji_i_tokenu(): void
    {
        config(['kuking.html_cache.edge_seconds' => 120, 'session.driver' => 'database']);
        foreach ($this->strony() as $path) {
            $this->assertPubliczna($this->get($path), 120, $path);
            $this->assertPubliczna($this->head($path), 120, 'HEAD '.$path);
        }
        $this->assertDatabaseCount('sessions', 0);
        // Formularz motywu nadal stoi na stronie — bez tokenu, ale działa.
        $this->get('/')->assertSee(route('theme.update'), false);
    }

    public function test_zalogowany_na_tych_samych_stronach_dostaje_no_store(): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $strony = $this->strony();
        $viewer = $this->user();
        foreach ($strony as $path) {
            $response = $this->actingAs($viewer)->get($path);
            $this->assertPrywatna($response, 'zalogowany '.$path);
        }
    }

    public function test_jakiekolwiek_ciasteczko_albo_authorization_wylacza_wspolny_cache(): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        [$landing, $recipe] = $this->strony();
        foreach ([[config('session.cookie'), 'x'], [config('kuking.theme.cookie'), 'dark'], ['__cf_bm', 'x']] as [$name, $value]) {
            $response = $this->withUnencryptedCookie($name, $value)->get($recipe)->assertOk();
            $this->assertPrywatna($response, 'ciasteczko '.$name);
            $this->assertStringContainsString('name="_token"', $response->getContent(), 'Strona z sesją ma działający formularz.');
            $this->defaultCookies = $this->unencryptedCookies = [];
        }
        $this->assertPrywatna($this->get($landing, ['Authorization' => 'Bearer fixture']), 'Authorization');
    }

    public function test_strona_z_formularzem_trasa_spoza_listy_query_i_404_zostaja_prywatne(): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $author = $this->user();
        $private = Recipe::factory()->create(['author_id' => $author->id, 'visibility' => 'private']);
        foreach (['/login' => 200, '/odkryj' => 200, '/?strona=2' => 200, $private->url() => 403, '/nie-ma-takiej-strony-610' => 404] as $path => $status) {
            $response = $this->get($path)->assertStatus($status);
            $this->assertPrywatna($response, $path);
        }
        $this->assertStringContainsString('name="_token"', $this->get('/login')->getContent());
    }

    public function test_bezpiecznik_odmawia_publicznego_cache_przy_tokenie_ciasteczku_i_przekierowaniu(): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        // Kontrola dodatnia bezpiecznika: trasa o nazwie z listy, która
        // mimo braku sesji wypisuje token. Musi wyjść `no-store`.
        Route::middleware('web')->get('/fixture-610-token', fn () => response('<form><input type="hidden" name="_token" value="x"></form>'))->name('landing');
        Route::getRoutes()->refreshNameLookups();
        $this->assertPrywatna($this->get('/fixture-610-token')->assertOk(), 'token w treści');
        Route::middleware('web')->get('/fixture-610-ciastko', fn () => response('ok')->withCookie(cookie('obce', '1')))->name('profile.show');
        Route::getRoutes()->refreshNameLookups();
        $this->assertPrywatna($this->get('/fixture-610-ciastko')->assertOk(), 'Set-Cookie w odpowiedzi');
        // Przekierowanie starego adresu przepisu nie dostaje publicznego TTL.
        Route::middleware('web')->get('/fixture-610-stary-adres', fn () => redirect('/', 301))->name('recipes.show');
        Route::getRoutes()->refreshNameLookups();
        $this->assertPrywatna($this->get('/fixture-610-stary-adres')->assertStatus(301), 'przekierowanie');
    }

    public function test_ttl_jest_obciety_do_granicy_kodu(): void
    {
        config(['kuking.html_cache.edge_seconds' => 86400]);
        [$landing] = $this->strony();
        $this->assertPubliczna($this->get($landing), PublicznyHtmlGoscia::MAKS_SEKUND, 'obcięcie');
        config(['kuking.html_cache.edge_seconds' => -5]);
        $this->assertPrywatna($this->get($landing), 'ujemna wartość = wyłączone');
    }

    public function test_motyw_z_publicznej_strony_przechodzi_przez_pochodzenie_a_obce_dostaje_419(): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        // Framework pomija CSRF w testach; tu świadomie przywracamy walidację.
        $this->app->instance('env', 'local');
        $host = rtrim((string) config('app.url'), '/');
        $this->post('/motyw', ['theme' => 'dark'], ['Sec-Fetch-Site' => 'same-origin'])->assertRedirect()->assertCookie(config('kuking.theme.cookie'), 'dark');
        $this->post('/motyw', ['theme' => 'dark'], ['Origin' => $host])->assertRedirect();
        $this->post('/motyw', ['theme' => 'dark'], ['Origin' => 'https://obcy.invalid'])->assertStatus(419);
        $this->post('/motyw', ['theme' => 'dark'], ['Sec-Fetch-Site' => 'cross-site', 'Origin' => $host])->assertStatus(419);
        $this->post('/motyw', ['theme' => 'dark'])->assertStatus(419);
        // Wyjątek od pochodzenia jest tylko dla motywu.
        Route::middleware('web')->post('/fixture-610-post', fn () => response('Zapisano'));
        $this->post('/fixture-610-post', [], ['Origin' => $host])->assertStatus(419);
    }
}

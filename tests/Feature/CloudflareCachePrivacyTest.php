<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class CloudflareCachePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private function photo(string $visibility = 'public'): Media
    {
        config(['filesystems.disks.cache_probe' => [
            'driver' => 's3', 'key' => 'fixture', 'secret' => 'fixture',
            'region' => 'auto', 'bucket' => 'fixture',
            'endpoint' => 'https://storage.invalid',
        ]]);
        $photo = Media::factory()->create([
            'owner_id' => $this->user()->id,
            'disk' => 'cache_probe', 'variants_disk' => 'cache_probe',
            'status' => Media::STATUS_READY,
            'metadata' => ['variants' => ['feed' => ['key' => 'fixture.webp', 'width' => 960, 'height' => 960]]],
        ]);
        Recipe::factory()->create(['author_id' => $photo->owner_id, 'visibility' => $visibility, 'hero_media_id' => $photo->id]);

        return $photo;
    }

    public function test_publiczne_zdjecie_nie_wydaje_ciasteczek_i_ma_podpis_z_terminem(): void
    {
        $response = $this->get($this->photo()->url('feed'))->assertStatus(302);
        $this->assertCount(0, $response->headers->getCookies(), 'Publiczny cache nie może rozdawać sesji.');
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['X-Amz-Signature']);
        $this->assertNotEmpty($query['X-Amz-Date']);
        $this->assertOknoPodpisu(3600, $query['X-Amz-Expires']);
        $this->assertLessThan(3600, (int) $response->headers->getCacheControlDirective('max-age'));
    }

    public function test_zalogowany_nie_dostaje_publicznego_cache_nawet_dla_publicznego_zdjecia(): void
    {
        $photo = $this->photo();
        $response = $this->actingAs($photo->owner)->get($photo->url('feed'))->assertStatus(302);
        $this->assertTrue($response->headers->hasCacheControlDirective('private'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertStringContainsString('no-store', $query['response-cache-control']);
    }

    public function test_prywatny_podpis_zostaje_krotki_a_obcy_nie_dostaje_adresu(): void
    {
        $photo = $this->photo('private');
        $this->get($photo->url('feed'))->assertNotFound()->assertHeaderMissing('Location');
        $response = $this->actingAs($photo->owner)->get($photo->url('feed'))->assertStatus(302);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertOknoPodpisu(300, $query['X-Amz-Expires']);
        $this->assertStringContainsString('no-store', $query['response-cache-control']);
    }

    public function test_sesja_w_html_i_bledy_sa_no_store(): void
    {
        $author = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $author->id, 'visibility' => 'public']);
        foreach (['/', '/login', $recipe->url(), route('profile.show', $author->profile->username), '/nie-ma-takiej-strony-cache'] as $path) {
            $response = $this->get($path);
            $response->assertStatus($path === '/nie-ma-takiej-strony-cache' ? 404 : 200);
            $this->assertTrue($response->headers->hasCacheControlDirective('private'), $path);
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'), $path);
        }
    }

    public function test_ochrona_nadpisuje_bledny_publiczny_naglowek_na_odpowiedzi_z_sesja(): void
    {
        Route::middleware('web')->get('/cache-fixture', fn () => response('Dane sesji', 200, [
            'Cache-Control' => 'public, max-age=600',
            'CDN-Cache-Control' => 'public, max-age=600',
            'Cloudflare-CDN-Cache-Control' => 'public, max-age=600',
        ]));
        $response = $this->actingAs($this->user())->get('/cache-fixture')->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $response->assertHeaderMissing('CDN-Cache-Control')->assertHeaderMissing('Cloudflare-CDN-Cache-Control');
    }

    public function test_nawet_nieznane_cookie_i_authorization_wykluczaja_wspolny_cache(): void
    {
        $url = $this->photo()->url('feed');
        foreach ([['Cookie' => 'nieznane=1'], ['Authorization' => 'Bearer fixture']] as $headers) {
            $response = $this->get($url, $headers)->assertStatus(302);
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
            $this->assertStringContainsString('no-store', $query['response-cache-control']);
        }
    }

    public function test_po_zmianie_na_prywatne_nie_powstaje_nowy_publiczny_podpis(): void
    {
        $photo = $this->photo();
        $this->get($photo->url('feed'))->assertStatus(302);
        Recipe::where('hero_media_id', $photo->id)->firstOrFail()->forceFill(['visibility' => 'private'])->save();
        $response = $this->get($photo->url('feed'))->assertNotFound()->assertHeaderMissing('Location');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        // To mierzy nową decyzję originu, nie unieważnienie starego podpisu R2.
    }

    public function test_odczyt_zdjecia_nie_zapisuje_sesji_w_bazie_ale_formularz_nadal_ja_ma(): void
    {
        config(['session.driver' => 'database']);
        $this->get($this->photo()->url('feed'))->assertStatus(302);
        $this->assertDatabaseCount('sessions', 0);
        $response = $this->get('/login')->assertOk();
        $this->assertTrue(collect($response->headers->getCookies())->contains(fn ($cookie) => $cookie->getName() === config('session.cookie')));
        $this->assertDatabaseCount('sessions', 1);
    }

    public function test_blad_zdjecia_i_head_nie_daja_wyjatku_od_prywatnosci(): void
    {
        $photo = $this->photo();
        $response = $this->head($photo->url('feed'))->assertStatus(302);
        $this->assertCount(0, $response->headers->getCookies());
        $response = $this->get('/zdjecia/nie-uuid/feed')->assertNotFound();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_prawdziwe_cookie_dwoch_kont_nie_omija_policy(): void
    {
        config(['session.driver' => 'database']);
        $photo = $this->photo('private');
        foreach ([$photo->owner, $this->user()] as $index => $user) {
            $sessionId = Str::random(40);
            DB::table('sessions')->insert([
                'id' => $sessionId, 'user_id' => $user->id,
                'payload' => base64_encode(json_encode([Auth::guard()->getName() => $user->id, '_token' => Str::random(40)], JSON_THROW_ON_ERROR)),
                'last_activity' => time(),
            ]);
            Auth::forgetGuards();
            $response = $this->withCookie(config('session.cookie'), $sessionId)->get($photo->url('feed'));
            $response->assertStatus($index === 0 ? 302 : 404);
            $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
            if ($index === 1) {
                $response->assertHeaderMissing('Location');
            }
        }
    }

    public function test_post_nadal_wymaga_csrf_po_bezsesyjnym_zdjeciu(): void
    {
        $this->get($this->photo()->url('feed'))->assertStatus(302);
        // Framework pomija CSRF w testach; tu świadomie przywracamy walidację.
        $this->app->instance('env', 'local');
        Route::middleware('web')->post('/cache-csrf-fixture', fn () => response('Zapisano'));
        $this->post('/cache-csrf-fixture')->assertStatus(419);
    }

    public function test_konfiguracja_nie_przekracza_przyjetych_granic_podpisu(): void
    {
        config(['kuking.media.signed_url_minutes' => 60, 'kuking.media.public_signed_url_minutes' => 120]);
        $photo = $this->photo('private');
        $response = $this->actingAs($photo->owner)->get($photo->url('feed'))->assertStatus(302);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertOknoPodpisu(300, $query['X-Amz-Expires']);
        $publicPhoto = $this->photo();
        $response = $this->get($publicPhoto->url('feed'))->assertStatus(302);
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertOknoPodpisu(3600, $query['X-Amz-Expires']);
    }
}

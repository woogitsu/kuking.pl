<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #2396 i #2395: 301 ze starego sluga przepisu i z dawnej nazwy profilu
 * zależy od widoczności (`Location` zdradza aktualny adres). Przeglądarka
 * albo CDN, która zapamięta takie 301, oddałaby adres także po ukryciu
 * przepisu albo zablokowaniu konta — dlatego KAŻDE takie przekierowanie
 * ma `Cache-Control: private, no-store` i żadnego nagłówka CDN, także przy
 * włączonym cache brzegu dla gościa (#610).
 */
class PrzekierowaniaZaleznePodWidocznosciNieSaCacheowaneTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string}> */
    public static function adresyPrzepisu(): array
    {
        return array_map(fn (string $s): array => [$s], [
            'strona' => '/przepisy/zurek-stary',
            'gotowanie' => '/przepisy/zurek-stary/gotuj',
            'karta qr' => '/przepisy/zurek-stary/karta-qr',
            'historia' => '/przepisy/zurek-stary/historia',
            'wersja' => '/przepisy/zurek-stary/historia/1',
            'zmiany' => '/przepisy/zurek-stary/historia/1/zmiany',
            'szczegoly' => '/przepisy/zurek-stary/szczegoly',
            'edycja' => '/przepisy/zurek-stary/edycja',
            'ugotowalem' => '/przepisy/zurek-stary/ugotowalem',
            'lista zakupow' => '/przepisy/zurek-stary/lista-zakupow',
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function adresyProfilu(): array
    {
        return array_map(fn (string $s): array => [$s], [
            'profil' => '/@basia',
            'profil z query' => '/@basia?zakladka=przepisy',
            'obserwujacy' => '/@basia/obserwujacy',
            'obserwowani' => '/@basia/obserwowani',
            'kanal' => '/@basia/kanal',
        ]);
    }

    private function przepis(User $autor): Recipe
    {
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'slug' => 'zurek-nowy',
        ]);
        DB::table('recipe_slug_redirects')->insert(['slug' => 'zurek-stary', 'recipe_id' => $przepis->getKey(), 'created_at' => now()]);

        return $przepis;
    }

    private function dawnaNazwa(User $u): void
    {
        $u->profile->update(['username' => 'barbara']);
        DB::table('profile_username_redirects')->insert(['username' => 'basia', 'user_id' => $u->getKey(), 'created_at' => now()]);
    }

    private function assertNieDoZapamietania(TestResponse $odpowiedz, string $adres): void
    {
        $odpowiedz->assertStatus(301);
        $cc = (string) $odpowiedz->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cc, "{$adres}: brak no-store ({$cc}).");
        $this->assertStringContainsString('private', $cc, "{$adres}: brak private ({$cc}).");
        $this->assertFalse($odpowiedz->headers->has('CDN-Cache-Control'), "{$adres}: CDN-Cache-Control.");
        $this->assertFalse($odpowiedz->headers->has('Cloudflare-CDN-Cache-Control'), "{$adres}: Cloudflare-CDN-Cache-Control.");
        $this->assertFalse($odpowiedz->headers->has('Surrogate-Control'), "{$adres}: Surrogate-Control.");
    }

    #[DataProvider('adresyPrzepisu')]
    public function test_301_starego_sluga_gosc_przy_wlaczonym_cache_brzegu(string $adres): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $this->przepis($this->user('autor'));

        $odpowiedz = $this->get($adres);

        // Ekrany za logowaniem odsyłają gościa 302 na logowanie — bez
        // aktualnego sluga; kontrakt 301 sprawdzamy tam, gdzie istnieje.
        if ($odpowiedz->getStatusCode() === 302) {
            $this->assertStringNotContainsString('zurek', (string) $odpowiedz->headers->get('Location'));

            return;
        }
        $this->assertNieDoZapamietania($odpowiedz, $adres);
    }

    #[DataProvider('adresyPrzepisu')]
    public function test_301_starego_sluga_zalogowany_autor_przy_wlaczonym_cache_brzegu(string $adres): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $autor = $this->user('autor');
        $this->przepis($autor);

        $this->assertNieDoZapamietania($this->actingAs($autor)->get($adres), $adres);
    }

    #[DataProvider('adresyPrzepisu')]
    public function test_po_ukryciu_przepisu_biezace_zadanie_daje_404_bez_location(string $adres): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $autor = $this->user('autor');
        $przepis = $this->przepis($autor);

        $this->assertNieDoZapamietania($this->actingAs($autor)->get($adres), $adres);

        $przepis->forceFill(['visibility' => 'private'])->save();

        $odpowiedz = $this->actingAs($this->user('obcy'))->get($adres);
        $odpowiedz->assertNotFound();
        $this->assertNull($odpowiedz->headers->get('Location'));
        $this->assertStringContainsString('no-store', (string) $odpowiedz->headers->get('Cache-Control'));

        auth()->logout();
        $gosc = $this->get($adres);
        $this->assertContains($gosc->getStatusCode(), [302, 404]);
        $this->assertStringNotContainsString('zurek', (string) $gosc->headers->get('Location'));
    }

    #[DataProvider('adresyProfilu')]
    public function test_301_dawnej_nazwy_profilu_przy_wlaczonym_cache_brzegu(string $adres): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $this->dawnaNazwa($this->user('basia'));

        $this->assertNieDoZapamietania($this->get($adres), $adres);
        $this->assertNieDoZapamietania($this->actingAs($this->user('ktos'))->get($adres), $adres);
    }

    #[DataProvider('adresyProfilu')]
    public function test_po_zablokowaniu_konta_biezace_zadanie_daje_404_bez_location(string $adres): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $u = $this->user('basia');
        $this->dawnaNazwa($u);

        $this->assertNieDoZapamietania($this->get($adres), $adres);

        $u->forceFill(['status' => User::STATUS_BANNED])->save();

        $odpowiedz = $this->get($adres);
        $odpowiedz->assertNotFound();
        $this->assertNull($odpowiedz->headers->get('Location'));
    }

    public function test_middleware_zdejmuje_naglowki_cdn_i_nadaje_no_store_kazdemu_301_takze_bez_sesji(): void
    {
        Route::get('/__test-301', fn () => redirect('/gdzies', 301)
            ->header('Cache-Control', 'public, max-age=86400')
            ->header('CDN-Cache-Control', 'max-age=86400')
            ->header('Cloudflare-CDN-Cache-Control', 'max-age=86400'));

        $this->assertNieDoZapamietania($this->get('/__test-301'), '/__test-301');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Models\Comment;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Czytanie przez API (D-272): feed obserwowanych, wpis, przepis, profil,
 * komentarze, zdjęcia. Każde wejście przez tę samą Policy co WWW.
 */
class CzytanieApiTest extends TestCase
{
    use RefreshDatabase;

    private User $autorka;

    private User $obserwujaca;

    private User $obca;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.api.wlaczone' => true]);

        $this->autorka = $this->user('autorka', ['email' => 'autorka@example.com']);
        $this->obserwujaca = $this->user('obserwujaca');
        $this->obca = $this->user('obca');

        app(FollowUser::class)->handle($this->obserwujaca, $this->autorka);
    }

    // --------------------------------------------------------------
    //  Feed
    // --------------------------------------------------------------

    public function test_feed_jest_chronologiczny_i_ma_tylko_obserwowanych(): void
    {
        $starszy = $this->wpis($this->autorka, ['published_at' => now()->subDay(), 'body' => 'Wczorajszy rosół']);
        $nowszy = $this->wpis($this->autorka, ['published_at' => now()->subHour(), 'body' => 'Dzisiejsze pierogi']);
        $this->wpis($this->obca, ['body' => 'Wpis osoby nieobserwowanej']);

        $odpowiedz = $this->jako($this->obserwujaca)->getJson('/api/v1/feed');

        $odpowiedz->assertOk();
        $this->assertSame([$nowszy->getKey(), $starszy->getKey()], $odpowiedz->json('data.*.id'));
        $this->assertArrayHasKey('next_cursor', $odpowiedz->json('meta'));
    }

    public function test_feed_ma_stronicowanie_kursorowe(): void
    {
        config(['kuking.feed.page_size' => 2]);

        foreach (range(1, 3) as $i) {
            $this->wpis($this->autorka, ['published_at' => now()->subMinutes($i)]);
        }

        $pierwsza = $this->jako($this->obserwujaca)->getJson('/api/v1/feed')->assertOk();
        $this->assertCount(2, $pierwsza->json('data'));

        $druga = $this->jako($this->obserwujaca)->getJson('/api/v1/feed?cursor='.$pierwsza->json('meta.next_cursor'));
        $druga->assertOk();
        $this->assertCount(1, $druga->json('data'));
        $this->assertNull($druga->json('meta.next_cursor'));
    }

    public function test_feed_bez_tokenu_to_401(): void
    {
        $this->getJson('/api/v1/feed')->assertUnauthorized();
    }

    // --------------------------------------------------------------
    //  Wpis
    // --------------------------------------------------------------

    public function test_wpis_dla_obserwujacych_widzi_obserwujaca_a_nie_obca(): void
    {
        $wpis = $this->wpis($this->autorka, ['visibility' => Post::VISIBILITY_FOLLOWERS, 'body' => 'Tylko dla swoich']);

        $this->jako($this->obserwujaca)->getJson('/api/v1/wpisy/'.$wpis->getKey())
            ->assertOk()
            ->assertJsonPath('data.body', 'Tylko dla swoich')
            ->assertJsonPath('data.author.username', 'autorka');

        $this->jako($this->obca)->getJson('/api/v1/wpisy/'.$wpis->getKey())
            ->assertForbidden();
    }

    public function test_wpis_prywatny_widzi_tylko_autorka(): void
    {
        $wpis = $this->wpis($this->autorka, ['visibility' => Post::VISIBILITY_PRIVATE]);

        $this->jako($this->autorka)->getJson('/api/v1/wpisy/'.$wpis->getKey())->assertOk();
        $this->jako($this->obserwujaca)->getJson('/api/v1/wpisy/'.$wpis->getKey())->assertForbidden();
    }

    public function test_blokada_dziala_w_obie_strony(): void
    {
        $wpis = $this->wpis($this->autorka);
        $blokujaca = $this->user('blokujaca');
        $zablokowana = $this->user('zablokowana');

        app(BlockUser::class)->handle($blokujaca, $this->autorka);
        app(BlockUser::class)->handle($this->autorka, $zablokowana);

        $this->jako($blokujaca)->getJson('/api/v1/wpisy/'.$wpis->getKey())->assertForbidden();
        $this->jako($zablokowana)->getJson('/api/v1/wpisy/'.$wpis->getKey())->assertForbidden();
        $this->jako($blokujaca)->getJson('/api/v1/profile/autorka')->assertForbidden();
        $this->jako($zablokowana)->getJson('/api/v1/profile/autorka')->assertForbidden();

        // Kontrola dodatnia: osoba bez blokady wchodzi.
        $this->jako($this->obca)->getJson('/api/v1/wpisy/'.$wpis->getKey())->assertOk();
    }

    public function test_nieistniejacy_wpis_to_404(): void
    {
        $this->jako($this->obca)->getJson('/api/v1/wpisy/'.Str::uuid7())->assertNotFound();
        $this->jako($this->obca)->getJson('/api/v1/wpisy/nie-uuid')->assertNotFound();
    }

    public function test_wpis_nie_wypuszcza_pol_prywatnych(): void
    {
        $wpis = $this->wpis($this->autorka, ['klucz_wyslania' => (string) Str::uuid7()]);

        $tresc = (string) $this->jako($this->obca)->getJson('/api/v1/wpisy/'.$wpis->getKey())->assertOk()->getContent();

        foreach (['klucz_wyslania', (string) $wpis->klucz_wyslania, 'autorka@example.com', 'hide_as_memory', '"status"', 'password'] as $zakazane) {
            $this->assertStringNotContainsString($zakazane, $tresc);
        }
    }

    // --------------------------------------------------------------
    //  Komentarze
    // --------------------------------------------------------------

    public function test_komentarze_pomijaja_osobe_z_blokada_i_sa_pod_ta_sama_policy(): void
    {
        $wpis = $this->wpis($this->autorka);
        $zablokowana = $this->user('zablokowana');

        Comment::factory()->create(['post_id' => $wpis->getKey(), 'author_id' => $this->obca->getKey(), 'body' => 'Pyszne!']);
        Comment::factory()->create(['post_id' => $wpis->getKey(), 'author_id' => $zablokowana->getKey(), 'body' => 'Komentarz zablokowanej']);

        app(BlockUser::class)->handle($this->obserwujaca, $zablokowana);

        $odpowiedz = $this->jako($this->obserwujaca)->getJson('/api/v1/wpisy/'.$wpis->getKey().'/komentarze');

        $odpowiedz->assertOk()->assertJsonPath('data.0.body', 'Pyszne!');
        $this->assertCount(1, $odpowiedz->json('data'));

        $prywatny = $this->wpis($this->autorka, ['visibility' => Post::VISIBILITY_PRIVATE]);
        $this->jako($this->obca)->getJson('/api/v1/wpisy/'.$prywatny->getKey().'/komentarze')->assertForbidden();
    }

    // --------------------------------------------------------------
    //  Przepis i profil
    // --------------------------------------------------------------

    public function test_przepis_po_uuid_z_policy(): void
    {
        $publiczny = Recipe::factory()->create(['author_id' => $this->autorka->getKey(), 'title' => 'Bigos babci']);
        $prywatny = Recipe::factory()->create(['author_id' => $this->autorka->getKey(), 'visibility' => 'private']);

        $this->jako($this->obca)->getJson('/api/v1/przepisy/'.$publiczny->getKey())
            ->assertOk()
            ->assertJsonPath('data.title', 'Bigos babci')
            ->assertJsonPath('data.author.username', 'autorka');

        $this->jako($this->obca)->getJson('/api/v1/przepisy/'.$prywatny->getKey())->assertForbidden();
        $this->jako($this->obca)->getJson('/api/v1/przepisy/'.$prywatny->getKey().'/komentarze')->assertForbidden();
        $this->jako($this->autorka)->getJson('/api/v1/przepisy/'.$prywatny->getKey())->assertOk();
    }

    public function test_profil_bez_adresu_email(): void
    {
        $odpowiedz = $this->jako($this->obserwujaca)->getJson('/api/v1/profile/Autorka');

        $odpowiedz->assertOk()
            ->assertJsonPath('data.username', 'autorka')
            ->assertJsonPath('data.is_following', true);

        $this->assertStringNotContainsString('autorka@example.com', (string) $odpowiedz->getContent());
        $this->jako($this->obca)->getJson('/api/v1/profile/nikt-taki')->assertNotFound();
    }

    // --------------------------------------------------------------
    //  Zdjęcia
    // --------------------------------------------------------------

    public function test_zdjecie_wpisu_ma_adres_api_i_idzie_przez_dostep_do_zdjecia(): void
    {
        Storage::fake('public');

        $zdjecie = Media::factory()->create(['owner_id' => $this->autorka->getKey()]);
        $klucz = $zdjecie->wariantDoSerwowania('feed')['klucz'];
        Storage::disk('public')->put($klucz, 'BAJTY-ZDJECIA');

        $wpis = $this->wpis($this->autorka, ['visibility' => Post::VISIBILITY_FOLLOWERS]);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $adres = (string) $this->jako($this->obserwujaca)
            ->getJson('/api/v1/wpisy/'.$wpis->getKey())
            ->assertOk()
            ->json('data.photos.0.warianty.feed');

        $this->assertStringContainsString('/api/v1/zdjecia/'.$zdjecie->getKey().'/feed', $adres);
        $this->assertStringNotContainsString($zdjecie->object_key, $adres, 'Adres zdjęcia zdradza klucz oryginału.');

        $sciezka = (string) parse_url($adres, PHP_URL_PATH);

        // Dysk z podpisanymi adresami (jak R2): 302 na krótko ważny adres
        // WARIANTU — nigdy oryginału.
        $dozwolone = $this->jako($this->obserwujaca)->get($sciezka);
        $dozwolone->assertRedirect();
        $this->assertStringContainsString($klucz, (string) $dozwolone->headers->get('Location'));
        $this->assertStringContainsString('no-store', (string) $dozwolone->headers->get('Cache-Control'));

        $this->jako($this->obca)->get($sciezka)->assertNotFound();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->get($sciezka, ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_zdjecie_bez_gotowego_wariantu_nie_trafia_do_odpowiedzi(): void
    {
        $zdjecie = Media::factory()->pending()->create(['owner_id' => $this->autorka->getKey()]);
        $wpis = $this->wpis($this->autorka);
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        $this->jako($this->obca)->getJson('/api/v1/wpisy/'.$wpis->getKey())
            ->assertOk()
            ->assertJsonPath('data.photos', []);
    }

    // --------------------------------------------------------------
    //  Pomocnicze
    // --------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $atrybuty
     */
    private function wpis(User $autor, array $atrybuty = []): Post
    {
        return Post::factory()->create(['author_id' => $autor->getKey(), ...$atrybuty]);
    }

    private function jako(User $kto): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$kto->createToken('Telefon')->plainTextToken);
    }
}

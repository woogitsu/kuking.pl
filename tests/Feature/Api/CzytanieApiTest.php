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
use Illuminate\Support\Facades\DB;
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

    /**
     * #1971: liczba zapytań feedu API nie rośnie z liczbą wpisów z przepisem.
     *
     * Przed poprawką `PostResource` pytał `RecipePolicy::view()` osobno dla
     * każdego wpisu — autor przepisu, blokady, obserwowanie: kilka zapytań na
     * wpis, choć `FollowingFeed` już przefiltrował przepisy jednym zapytaniem
     * (`Post::ukryjNiedostepnePrzepisy`). Przepisy od RÓŻNYCH autorów i część
     * „dla obserwujących", żeby polityka nie mogła niczego wziąć z pamięci.
     */
    public function test_feed_z_przepisami_ma_stala_liczbe_zapytan(): void
    {
        config(['kuking.feed.page_size' => 20]);

        $this->wpisyZPrzepisami(1);
        $jeden = $this->zapytaniaFeedu(1);

        $this->wpisyZPrzepisami(19);
        $dwadziescia = $this->zapytaniaFeedu(20);

        $this->assertSame(
            $jeden,
            $dwadziescia,
            "Feed z 1 wpisem: {$jeden} zapytań, z 20 wpisami: {$dwadziescia} — serializacja pyta bazę per wpis (N+1).",
        );
    }

    /**
     * Granica widoczności przepisu dalej działa bez polityki w zasobie:
     * przepis „dla obserwujących" autora, którego widz nie obserwuje,
     * i przepis prywatny znikają z karty wpisu, wpis zostaje.
     */
    public function test_feed_nie_wypuszcza_przepisu_ktorego_widz_nie_moze_zobaczyc(): void
    {
        $tworca = $this->user('tworca');
        $dlaObserwujacych = Recipe::factory()->create(['author_id' => $tworca->getKey(), 'visibility' => 'followers', 'title' => 'Tajny bigos']);
        $publiczny = Recipe::factory()->create(['author_id' => $tworca->getKey(), 'visibility' => 'public', 'title' => 'Jawny bigos']);

        $zUkrytym = $this->wpis($this->autorka, ['recipe_id' => $dlaObserwujacych->getKey(), 'published_at' => now()->subHour()]);
        $zJawnym = $this->wpis($this->autorka, ['recipe_id' => $publiczny->getKey(), 'published_at' => now()->subMinute()]);

        $odpowiedz = $this->jako($this->obserwujaca)->getJson('/api/v1/feed')->assertOk();

        $wpisy = collect($odpowiedz->json('data'))->keyBy('id');
        $this->assertNull($wpisy[$zUkrytym->getKey()]['recipe']);
        $this->assertStringNotContainsString('Tajny bigos', (string) $odpowiedz->getContent());
        // KONTROLA DODATNIA: widoczny przepis zostaje na karcie.
        $this->assertSame('Jawny bigos', $wpisy[$zJawnym->getKey()]['recipe']['title']);
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

    /**
     * #1970: strona komentarzy nie niesie całego podwątku. Przed poprawką
     * `replies` ładowało się bez limitu — wątek z 30 odpowiedziami oddawał
     * w liście wszystkie 30.
     */
    public function test_watek_na_liscie_komentarzy_niesie_ograniczona_liczbe_odpowiedzi(): void
    {
        config(['kuking.api.odpowiedzi_w_watku' => 3, 'kuking.comments.page_size' => 12]);

        $wpis = $this->wpis($this->autorka);
        $przepis = Recipe::factory()->create(['author_id' => $this->autorka->getKey()]);
        $korzenWpisu = $this->watek(['post_id' => $wpis->getKey()], 30);
        $korzenPrzepisu = $this->watek(['recipe_id' => $przepis->getKey(), 'post_id' => null], 30);
        // Drugi wątek z jedną odpowiedzią: limit jest NA WĄTEK, nie na stronę.
        $maly = $this->watek(['post_id' => $wpis->getKey()], 1);

        foreach ([
            ['/api/v1/wpisy/'.$wpis->getKey().'/komentarze', $korzenWpisu],
            ['/api/v1/przepisy/'.$przepis->getKey().'/komentarze', $korzenPrzepisu],
        ] as [$adres, $korzen]) {
            $watki = collect($this->jako($this->obca)->getJson($adres)->assertOk()->json('data'))->keyBy('id');
            $watek = $watki[$korzen->getKey()];

            $this->assertCount(3, $watek['replies'], "{$adres}: wątek niesie więcej odpowiedzi niż limit.");
            // Najstarsze, w kolejności rozmowy.
            $this->assertSame(['Odpowiedź 1', 'Odpowiedź 2', 'Odpowiedź 3'], array_column($watek['replies'], 'body'));
            $this->assertSame(30, $watek['replies_count']);
            $this->assertStringEndsWith('/api/v1/komentarze/'.$korzen->getKey().'/odpowiedzi', (string) $watek['more_replies_url']);
        }

        // KONTROLA DODATNIA: wątek mieszczący się w limicie nie ma adresu dalszych odpowiedzi.
        $watki = collect($this->jako($this->obca)->getJson('/api/v1/wpisy/'.$wpis->getKey().'/komentarze')->json('data'))->keyBy('id');
        $this->assertCount(1, $watki[$maly->getKey()]['replies']);
        $this->assertSame(1, $watki[$maly->getKey()]['replies_count']);
        $this->assertNull($watki[$maly->getKey()]['more_replies_url']);
    }

    /**
     * Pobrane z bazy odpowiedzi są policzone, nie tylko przycięte w JSON-ie:
     * liczba modeli komentarza nie rośnie z długością wątku.
     */
    public function test_lista_komentarzy_nie_pobiera_z_bazy_calego_podwatku(): void
    {
        config(['kuking.api.odpowiedzi_w_watku' => 3]);

        $wpis = $this->wpis($this->autorka);
        $this->watek(['post_id' => $wpis->getKey()], 40);

        $zadanie = $this->jako($this->obca);
        $przycinajacych = 0;
        $pobrane = 0;
        DB::listen(function ($zapytanie) use (&$przycinajacych): void {
            if (str_contains($zapytanie->sql, 'parent_id') && str_contains($zapytanie->sql, 'row_number')) {
                $przycinajacych++;
            }
        });
        Comment::retrieved(function () use (&$pobrane): void {
            $pobrane++;
        });

        $zadanie->getJson('/api/v1/wpisy/'.$wpis->getKey().'/komentarze')->assertOk();

        // 1 korzeń + 3 odpowiedzi. Bez limitu w bazie byłoby 41.
        $this->assertSame(4, $pobrane, 'Lista komentarzy pobrała z bazy więcej odpowiedzi niż limit wątku.');
        $this->assertGreaterThan(0, $przycinajacych, 'Odpowiedzi nie są przycinane w SQL (ROW_NUMBER per wątek).');
    }

    public function test_dalsze_odpowiedzi_stronami_z_kursorem_i_pod_ta_sama_policy(): void
    {
        config(['kuking.comments.page_size' => 4]);

        $wpis = $this->wpis($this->autorka);
        $korzen = $this->watek(['post_id' => $wpis->getKey()], 6);
        $zablokowana = $this->user('zablokowana2');
        Comment::factory()->create([
            'post_id' => $wpis->getKey(),
            'parent_id' => $korzen->getKey(),
            'author_id' => $zablokowana->getKey(),
            'body' => 'Odpowiedź zablokowanej',
            'created_at' => now()->addMinutes(2),
        ]);
        app(BlockUser::class)->handle($this->obca, $zablokowana);

        $pierwsza = $this->jako($this->obca)->getJson('/api/v1/komentarze/'.$korzen->getKey().'/odpowiedzi')->assertOk();
        $this->assertSame(['Odpowiedź 1', 'Odpowiedź 2', 'Odpowiedź 3', 'Odpowiedź 4'], $pierwsza->json('data.*.body'));
        $this->assertNotNull($pierwsza->json('meta.next_cursor'));

        $druga = $this->jako($this->obca)
            ->getJson('/api/v1/komentarze/'.$korzen->getKey().'/odpowiedzi?cursor='.$pierwsza->json('meta.next_cursor'))
            ->assertOk();
        // Blokada działa na każdej stronie — odpowiedź zablokowanej nie wraca.
        $this->assertSame(['Odpowiedź 5', 'Odpowiedź 6'], $druga->json('data.*.body'));
        $this->assertNull($druga->json('meta.next_cursor'));

        // Odpowiedź nie ma własnych odpowiedzi: 404, nie pusta lista.
        $odpowiedz = Comment::query()->where('parent_id', $korzen->getKey())->oldest()->firstOrFail();
        $this->jako($this->obca)->getJson('/api/v1/komentarze/'.$odpowiedz->getKey().'/odpowiedzi')->assertNotFound();

        // Wątek pod wpisem prywatnym — ta sama Policy co wpis.
        $prywatny = $this->wpis($this->autorka, ['visibility' => Post::VISIBILITY_PRIVATE]);
        $ukryty = $this->watek(['post_id' => $prywatny->getKey()], 1);
        $this->jako($this->obca)->getJson('/api/v1/komentarze/'.$ukryty->getKey().'/odpowiedzi')->assertForbidden();
        $this->jako($this->autorka)->getJson('/api/v1/komentarze/'.$ukryty->getKey().'/odpowiedzi')->assertOk();
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

    /**
     * Wpisy autorki (obserwowanej), każdy z przepisem INNEGO twórcy; co drugi
     * przepis „dla obserwujących" twórcy, którego obserwująca też obserwuje.
     */
    private function wpisyZPrzepisami(int $ile): void
    {
        for ($i = 0; $i < $ile; $i++) {
            $tworca = $this->user('tworca'.Str::lower(Str::random(8)));
            app(FollowUser::class)->handle($this->obserwujaca, $tworca);

            $przepis = Recipe::factory()->create([
                'author_id' => $tworca->getKey(),
                'visibility' => $i % 2 === 0 ? 'public' : 'followers',
            ]);

            $this->wpis($this->autorka, ['recipe_id' => $przepis->getKey(), 'published_at' => now()->subMinutes($i + 1)]);
        }
    }

    private function zapytaniaFeedu(int $oczekiwanychWpisow): int
    {
        $zadanie = $this->jako($this->obserwujaca);

        $licznik = 0;
        DB::listen(function () use (&$licznik): void {
            $licznik++;
        });

        $odpowiedz = $zadanie->getJson('/api/v1/feed')->assertOk();

        $liczba = $licznik;
        DB::flushQueryLog();
        $licznik = PHP_INT_MIN; // kolejne nasłuchy nie dopisują się do tego pomiaru

        $this->assertCount($oczekiwanychWpisow, $odpowiedz->json('data'));
        $this->assertCount($oczekiwanychWpisow, array_filter($odpowiedz->json('data.*.recipe')), 'Pomiar bez przepisów niczego nie mierzy.');

        return $liczba;
    }

    /**
     * Komentarz główny z `$ile` odpowiedziami „Odpowiedź 1…N" w kolejności czasu.
     *
     * @param  array<string, mixed>  $rodzic
     */
    private function watek(array $rodzic, int $ile): Comment
    {
        $korzen = Comment::factory()->create([...$rodzic, 'author_id' => $this->autorka->getKey(), 'created_at' => now()->subDay()]);

        for ($i = 1; $i <= $ile; $i++) {
            Comment::factory()->create([
                ...$rodzic,
                'parent_id' => $korzen->getKey(),
                'author_id' => $this->obserwujaca->getKey(),
                'body' => 'Odpowiedź '.$i,
                'created_at' => now()->subDay()->addMinutes($i),
            ]);
        }

        return $korzen;
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

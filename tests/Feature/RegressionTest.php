<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Search\SearchQuery;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Testy regresyjne do błędów znalezionych po fakcie.
 *
 * Każdy z nich odpowiada konkretnej pomyłce, która była w kodzie i przeszła
 * przez pierwszą rundę testów. Bugfix bez testu regresyjnego to zaproszenie
 * do powtórki (AGENTS.md §10).
 */
class RegressionTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // Wyciek GPS: Media::url() wracał do oryginalnego pliku użytkownika
    // -----------------------------------------------------------------

    public function test_url_zdjecia_nigdy_nie_wskazuje_na_oryginalny_plik(): void
    {
        Storage::fake('public');

        $media = Media::factory()->create([
            'object_key' => 'media/oryginal-z-exif.jpg',
            'metadata' => ['variants' => [
                'feed' => ['key' => 'media/oryginal-z-exif_feed.webp', 'width' => 960, 'height' => 720],
            ]],
        ]);

        // Wariant, którego nie ma — dawniej uruchamiał fallback na oryginał,
        // czyli na plik z nietkniętym EXIF-em i lokalizacją kuchni.
        $url = $media->url('nieistniejacy_wariant');

        $this->assertStringNotContainsString('oryginal-z-exif', $url);

        // Po W7-02 adresem zdjęcia nie jest już nazwa pliku w buckecie, tylko
        // trasa aplikacji — stąd asercja na nazwę WARIANTU zamiast na klucz.
        // Regresja, której ten test pilnuje, jest ta sama: zapasowym wariantem
        // ma być jedyny wygenerowany (`feed`), a nigdy oryginał.
        $this->assertSame(
            route('media.show', ['media' => $media->getKey(), 'wariant' => 'feed']),
            $url,
        );
    }

    public function test_zdjecie_bez_wariantow_daje_placeholder_a_nie_oryginal(): void
    {
        Storage::fake('public');

        $media = Media::factory()->create([
            'object_key' => 'media/oryginal-z-exif.jpg',
            'metadata' => ['variants' => []],
        ]);

        $this->assertStringNotContainsString('oryginal-z-exif.jpg', $media->url());
        $this->assertStringContainsString('kuking-mark.svg', $media->url());
    }

    // -----------------------------------------------------------------
    // Zdjęcia z telefonu publikowały się obrócone
    // -----------------------------------------------------------------

    public function test_orientacja_exif_jest_odczytywana_przy_przyjeciu_pliku(): void
    {
        Storage::fake('public');

        $media = app(StoreUploadedImage::class)->handle(
            $this->user(),
            UploadedFile::fake()->image('obiad.jpg', 800, 600),
        );

        // Klucz musi istnieć nawet gdy zdjęcie nie ma EXIF-u — zadanie w tle
        // czyta go bez sprawdzania, czy w ogóle jest.
        $this->assertArrayHasKey('exif_orientation', $media->metadata);
    }

    public function test_przetworzenie_odnotowuje_czy_zastosowano_obrot(): void
    {
        Storage::fake('public');

        // Wstrzymujemy kolejkę, żeby zdjęcie nie zostało przetworzone od razu —
        // job kończy się natychmiast, gdy status to już `ready`.
        Queue::fake();

        $media = app(StoreUploadedImage::class)->handle(
            $this->user(),
            UploadedFile::fake()->image('obiad.jpg', 800, 600),
        );

        // Symulujemy zdjęcie z telefonu trzymanego pionowo.
        $media->update(['metadata' => array_merge($media->metadata, ['exif_orientation' => 6])]);

        (new ProcessUploadedImage($media->getKey()))->handle();

        $media->refresh();

        $this->assertSame(Media::STATUS_READY, $media->status);
        $this->assertTrue($media->metadata['orientation_applied']);

        // Obrót o 90 stopni zamienia boki miejscami.
        $this->assertGreaterThan(
            $media->metadata['variants']['large']['width'],
            $media->metadata['variants']['large']['height'],
            'Zdjęcie z orientacją 6 musi wyjść pionowe.',
        );
    }

    // -----------------------------------------------------------------
    // Konto założone z telefonu bywało nie do zalogowania
    // -----------------------------------------------------------------

    public function test_adres_email_zapisuje_sie_malymi_literami(): void
    {
        $user = User::factory()->create(['email' => '  Basia@Example.TEST  ']);

        $this->assertSame('basia@example.test', $user->fresh()->email);
    }

    public function test_mozna_zalogowac_sie_adresem_z_wielkimi_literami(): void
    {
        $this->user('basia', ['email' => 'basia@example.test']);

        $this->post('/login', [
            'login' => 'Basia@Example.TEST',
            'password' => 'haslo-testowe-123',
        ])->assertRedirect();

        $this->assertAuthenticated();
    }

    public function test_mozna_zalogowac_sie_nazwa_z_wielkiej_litery(): void
    {
        $this->user('basia');

        // Klawiatura telefonu podnosi pierwszą literę bez pytania.
        $this->post('/login', [
            'login' => 'Basia',
            'password' => 'haslo-testowe-123',
        ])->assertRedirect();

        $this->assertAuthenticated();
    }

    public function test_rejestracja_normalizuje_adres_email(): void
    {
        $this->post('/register', [
            'display_name' => 'Basia',
            'username' => 'basia',
            'email' => 'Basia@Example.TEST',
            'password' => 'zielonapietruszkarano',
            'age_confirmed' => '1',
            'terms_accepted' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'basia@example.test']);
    }

    // -----------------------------------------------------------------
    // Indeksy trigramowe stały na innym wyrażeniu niż zapytania
    // -----------------------------------------------------------------

    public function test_wyszukiwarka_korzysta_z_indeksu_trigramowego(): void
    {
        $basia = $this->user('basia');
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Żurek z jajkiem',
            'slug' => 'zurek-z-jajkiem',
        ]);

        // Wyłączamy skan sekwencyjny: jeśli indeks jest nieużywalny,
        // PostgreSQL i tak wybierze Seq Scan — i to właśnie łapie ten test.
        DB::statement('SET enable_seqscan = off');

        // Od 9 września 2026 wyszukiwarka porównuje KOLUMNĘ `title_search`
        // (generowaną z `kuking_normalize(title)`), a nie samo wyrażenie —
        // i indeks stoi na tej kolumnie. Test pyta o to samo, o co pyta
        // `SearchQuery::KANDYDACI_SQL`; gdyby pytał o wyrażenie, sprawdzałby
        // indeks, którego już nie ma, zamiast tego, którego używa serwis.
        // Od 9 września 2026 (issue #187) pierwsza gałąź pyta operatorem `<%`
        // z frazą po LEWEJ stronie — test pyta o dokładnie to samo, bo inaczej
        // sprawdzałby indeks pod operator, którego wyszukiwarka nie używa.
        $plan = collect(DB::select(
            'EXPLAIN (FORMAT TEXT) SELECT id FROM recipes WHERE ? <% title_search',
            ['zurek'],
        ))->pluck('QUERY PLAN')->implode("\n");

        DB::statement('SET enable_seqscan = on');

        $this->assertStringContainsString(
            'recipes_title_trgm_idx',
            $plan,
            "Zapytanie wyszukiwarki musi używać indeksu. Plan:\n{$plan}",
        );
    }

    public function test_funkcja_normalizujaca_dziala_tak_samo_w_bazie_i_w_php(): void
    {
        // NORMALIZATOR Z KODU, NIE JEGO KOPIA PRZEPISANA DO TESTU.
        //
        // Ten test miał wcześniej po stronie PHP wpisane wprost
        // `mb_strtolower(Str::ascii($fraza))` z komentarzem „to samo, co robi
        // SearchQuery::normalize()". Porównywał więc bazę z WŁASNĄ kopią
        // reguły, a nie z regułą, która naprawdę chodzi w wyszukiwarce —
        // czyli mierzył dokładnie ten rozjazd, którego nie mógł zauważyć.
        //
        // Zmierzone: po zdjęciu `mb_strtolower` z `SearchQuery::normalize()`
        // cały ten plik był dalej zielony.
        //
        // `normalize()` jest prywatne i takie ma zostać — to szczegół
        // wyszukiwarki, nie część jej publicznej umowy. Refleksja jest tu
        // ceną za mierzenie prawdziwej funkcji zamiast jej kopii.
        $normalizator = new \ReflectionMethod(SearchQuery::class, 'normalize');
        $wyszukiwarka = app(SearchQuery::class);

        $frazy = ['Żurek', 'ŁÓDŹ', 'Pierogi Ruskie', 'ćwikła', 'Zupa'];

        foreach ($frazy as $fraza) {
            $wBazie = DB::selectOne('SELECT kuking_normalize(?) AS wynik', [$fraza])->wynik;

            $wPhp = $normalizator->invoke($wyszukiwarka, $fraza);

            $this->assertSame(
                $wBazie,
                $wPhp,
                "Normalizacja „{$fraza}” rozjeżdża się między bazą a PHP — wyszukiwarka przestanie znajdować.",
            );
        }
    }

    public function test_szukanie_bez_polskich_znakow_znajduje_z_polskimi(): void
    {
        $basia = $this->user('basia');
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Żurek na zakwasie',
            'slug' => 'zurek-na-zakwasie',
        ]);

        $this->assertCount(1, app(SearchQuery::class)->recipes('zurek'));
        $this->assertCount(1, app(SearchQuery::class)->recipes('Żurek'));
        $this->assertCount(1, app(SearchQuery::class)->recipes('ZUREK'));
    }

    // -----------------------------------------------------------------
    // kuking_normalize() padało przy budowaniu indeksu na PostgreSQL 18
    // -----------------------------------------------------------------

    /**
     * Od PostgreSQL 17 `CREATE INDEX` i `REINDEX` chodzą z ograniczonym
     * `search_path` (`pg_catalog, pg_temp`). Ciało funkcji SQL jest wtedy
     * re-parsowane przy inliningu, więc niekwalifikowane `unaccent(...)`
     * przestaje być widoczne i migracja pada:
     *
     *     ERROR: function unaccent(unknown, text) does not exist
     *     CONTEXT: SQL function "kuking_normalize" during inlining
     *
     * Na PostgreSQL 16 przechodziło, więc lokalnie nie było tego widać —
     * błąd wyszedł dopiero przy pierwszym przebiegu CI na obrazie `postgres:18`.
     *
     * Ten test wymusza ograniczony `search_path` w ramach jednej transakcji
     * i sprawdza, że funkcja nadal działa. Odtwarza mechanizm, nie wersję
     * serwera, więc łapie regresję także na PostgreSQL 16.
     */
    public function test_normalizacja_dziala_przy_ograniczonym_search_path(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Dotyczy wyłącznie PostgreSQL.');
        }

        DB::transaction(function (): void {
            // Dokładnie to, co PostgreSQL 17+ ustawia przy CREATE INDEX.
            DB::statement('SET LOCAL search_path = pg_catalog, pg_temp');

            $wynik = DB::selectOne("SELECT public.kuking_normalize('Żurek') AS wynik")->wynik;

            $this->assertSame(
                'zurek',
                $wynik,
                'kuking_normalize() nie przetrwało ograniczonego search_path — '
                .'budowanie indeksu wywali się na PostgreSQL 17+.',
            );
        });
    }

    /**
     * Druga połowa tej samej pułapki: samo ZBUDOWANIE indeksu na wyrażeniu
     * `kuking_normalize(...)` przy ograniczonym `search_path`. To jest
     * operacja, która realnie padła w CI — test wyżej sprawdza wywołanie,
     * ten sprawdza to, o co się wywróciła migracja.
     */
    public function test_indeks_na_kuking_normalize_da_sie_zbudowac(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Dotyczy wyłącznie PostgreSQL.');
        }

        DB::transaction(function (): void {
            DB::statement('CREATE TEMP TABLE probka_indeksu (tytul text)');
            DB::statement('SET LOCAL search_path = pg_catalog, pg_temp');

            DB::statement(
                'CREATE INDEX probka_indeksu_idx ON probka_indeksu '
                .'USING gin (public.kuking_normalize(tytul) public.gin_trgm_ops)',
            );

            $this->assertTrue(true, 'Indeks zbudowany bez błędu.');
        });
    }
}

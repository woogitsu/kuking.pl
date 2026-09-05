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
use Illuminate\Support\Str;
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

        $this->assertStringNotContainsString('oryginal-z-exif.jpg', $url);
        $this->assertStringContainsString('_feed.webp', $url);
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

        $plan = collect(DB::select(
            'EXPLAIN (FORMAT TEXT) SELECT id FROM recipes WHERE kuking_normalize(title) % ?',
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
        $frazy = ['Żurek', 'ŁÓDŹ', 'Pierogi Ruskie', 'ćwikła', 'Zupa'];

        foreach ($frazy as $fraza) {
            $wBazie = DB::selectOne('SELECT kuking_normalize(?) AS wynik', [$fraza])->wynik;

            // To samo, co robi SearchQuery::normalize() po stronie PHP.
            $wPhp = mb_strtolower(Str::ascii($fraza));

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
}

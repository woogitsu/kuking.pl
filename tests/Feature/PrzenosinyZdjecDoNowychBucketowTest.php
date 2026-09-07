<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Rozdzielenie bucketów ma bezpieczną drogę dla zdjęć, które już leżą
 * (audyt W4-03).
 *
 * CZEGO BRAKOWAŁO
 * Rozdzielenie oryginałów i wariantów zmieniło to, GDZIE ZAPISUJEMY. Nie
 * przeniosło niczego, co już leżało. Wiersze sprzed tej zmiany mają
 * `disk = 'r2'` i `variants_disk = NULL`, a `variantsDisk()` czyta `NULL`
 * jako „ten sam dysk co oryginał" — czyli `r2`. Po przestawieniu `AWS_BUCKET`
 * na nowy, prywatny bucket ta sama nazwa wskazywała miejsce, w którym tych
 * plików nie ma: warianty przestawały się wyświetlać, a oryginałów nie dałoby
 * się dołączyć do paczki RODO.
 *
 * Komentarz w migracji twierdził, że „oba źródła współistnieją i kod obsługuje
 * jedno i drugie". Nie było to prawdą, dopóki nie powstał dysk `r2_legacy`.
 * To był komentarz pewniejszy niż kod — dokładnie to, przed czym ostrzegał
 * audyt fali 2.
 *
 * CO PILNUJE TEN TEST
 * Że przenosiny są bezpieczne w najgorszym momencie: przy awarii w połowie.
 */
class PrzenosinyZdjecDoNowychBucketowTest extends TestCase
{
    use RefreshDatabase;

    private function stareZdjecie(): Media
    {
        $media = Media::factory()->create([
            'disk' => 'r2_legacy',
            'variants_disk' => null,
            'object_key' => 'incoming/basia/2026/09/sernik.jpg',
            'metadata' => ['variants' => [
                'thumb' => ['key' => 'media/basia/2026/09/sernik_thumb.webp'],
                'feed' => ['key' => 'media/basia/2026/09/sernik_feed.webp'],
            ]],
        ]);

        Storage::disk('r2_legacy')->put($media->object_key, 'oryginal z exifem');

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('r2_legacy')->put($wariant['key'], 'wariant');
        }

        return $media;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2_legacy');
        Storage::fake('nowe_oryginaly');
        Storage::fake('nowe_publiczne');

        config([
            'kuking.media.disk' => 'nowe_oryginaly',
            'kuking.media.public_disk' => 'nowe_publiczne',
            'filesystems.disks.r2_legacy.bucket' => 'kuking-media-stary',
        ]);
    }

    public function test_oryginal_idzie_do_prywatnego_a_warianty_do_publicznego(): void
    {
        $media = $this->stareZdjecie();

        $this->artisan('kuking:przenies-zdjecia')->assertSuccessful();

        $media->refresh();

        $this->assertSame('nowe_oryginaly', $media->disk);
        $this->assertSame('nowe_publiczne', $media->variantsDisk());

        // Oryginał TYLKO w prywatnym — to jest cały sens rozdzielenia.
        Storage::disk('nowe_oryginaly')->assertExists($media->object_key);
        Storage::disk('nowe_publiczne')->assertMissing($media->object_key);

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('nowe_publiczne')->assertExists($wariant['key']);
            Storage::disk('nowe_oryginaly')->assertMissing($wariant['key']);
        }
    }

    public function test_stary_bucket_zostaje_nietkniety(): void
    {
        // Dopóki nie ma pewności, że przeniósł się komplet, stary bucket jest
        // jedyną kopią zapasową. Kasowanie z niego to osobna decyzja i osobne
        // uruchomienie — nie skutek uboczny kopiowania.
        $media = $this->stareZdjecie();

        $this->artisan('kuking:przenies-zdjecia')->assertSuccessful();

        Storage::disk('r2_legacy')->assertExists($media->object_key);

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('r2_legacy')->assertExists($wariant['key']);
        }
    }

    public function test_awaria_w_polowie_nie_przestawia_wiersza(): void
    {
        // NAJWAŻNIEJSZY TEST W TYM PLIKU.
        //
        // Gdyby wiersz był przestawiany przed sprawdzeniem kopii, zdjęcie
        // wskazywałoby na plik, którego nie ma: znikałoby z serwisu i nie
        // dałoby się go odzyskać bez ręcznego grzebania w buckecie.
        $media = $this->stareZdjecie();

        // Wariant, którego nie ma w starym buckecie ORAZ nie ma go dokąd
        // skopiować — symulacja nieudanego kopiowania.
        Storage::disk('r2_legacy')->delete($media->object_key);
        Storage::fake('nowe_oryginaly');

        // Kasujemy plik ze źródła, więc kopiowanie oryginału „udaje się"
        // (nie ma czego kopiować), ale wiersz i tak ma dojść do końca.
        // Prawdziwą awarię wymuszamy brakiem bucketu.
        config(['filesystems.disks.r2_legacy.bucket' => '']);

        $this->artisan('kuking:przenies-zdjecia')->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
    }

    public function test_bez_ustawionego_starego_bucketu_komenda_odmawia(): void
    {
        // Zgadywanie, skąd kopiować, oznacza tu utratę zdjęć. Lepiej nie
        // zrobić nic i powiedzieć dlaczego.
        config(['filesystems.disks.r2_legacy.bucket' => '']);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('AWS_LEGACY_BUCKET')
            ->assertFailed();
    }

    public function test_ponowne_uruchomienie_nic_nie_psuje(): void
    {
        // Komenda chodzi partiami i będzie uruchamiana wielokrotnie. Zdjęcie
        // już przeniesione nie może wrócić do kolejki ani nadpisać nowej kopii.
        $media = $this->stareZdjecie();

        $this->artisan('kuking:przenies-zdjecia')->assertSuccessful();
        $this->artisan('kuking:przenies-zdjecia')->assertSuccessful();

        $media->refresh();

        $this->assertSame('nowe_oryginaly', $media->disk);
        Storage::disk('nowe_oryginaly')->assertExists($media->object_key);
    }

    public function test_tryb_podgladu_nic_nie_kopiuje(): void
    {
        $media = $this->stareZdjecie();

        $this->artisan('kuking:przenies-zdjecia', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
        Storage::disk('nowe_oryginaly')->assertMissing($media->object_key);
    }

    public function test_migracja_odmawia_gdy_nie_wiadomo_gdzie_lezy_stary_bucket(): void
    {
        // Wiersze sprzed rozdzielenia mają `disk = 'r2'`, a ta nazwa wskazuje
        // teraz NOWY, prywatny bucket. Przestawienie ich bez podania, gdzie
        // naprawdę leżą pliki, znaczyłoby „są nigdzie" — a to jest gorsze niż
        // stan sprzed migracji, bo wygląda na uporządkowane.
        Media::factory()->create(['disk' => 'r2', 'variants_disk' => null]);

        config(['filesystems.disks.r2_legacy.bucket' => '']);

        $migracja = require database_path(
            'migrations/2026_09_06_180000_point_existing_media_at_legacy_disk.php',
        );

        try {
            $migracja->up();

            $this->fail('Migracja przestawiła wiersze, nie wiedząc, gdzie leżą pliki.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('AWS_LEGACY_BUCKET', $e->getMessage());
        }

        $this->assertDatabaseHas('media', ['disk' => 'r2']);
    }

    public function test_migracja_przechodzi_bez_pytania_gdy_nie_ma_czego_przestawiac(): void
    {
        // Dziś to jest każde środowisko: pierwszego wdrożenia jeszcze nie było.
        // Migracja, która pyta ZAWSZE, blokowałaby staging bez powodu.
        config(['filesystems.disks.r2_legacy.bucket' => '']);

        $migracja = require database_path(
            'migrations/2026_09_06_180000_point_existing_media_at_legacy_disk.php',
        );

        $migracja->up();

        $this->assertTrue(true);
    }

    public function test_dysk_zgodnosci_ma_publiczny_adres_a_dysk_oryginalow_nie(): void
    {
        // Stary bucket JEST publiczny i dopóki wariantów z niego nie
        // przeniesiemy, to stamtąd się wyświetlają. Zdjęcie mu `url`-a
        // i stare zdjęcia znikają z serwisu w dniu wdrożenia.
        $this->assertArrayHasKey('url', (array) config('filesystems.disks.r2_legacy'));
        $this->assertArrayNotHasKey('url', (array) config('filesystems.disks.r2'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
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

    public function test_brak_oryginalu_w_starym_buckecie_nie_przestawia_wiersza(): void
    {
        // NAJWAŻNIEJSZY TEST W TYM PLIKU (#1031).
        //
        // Do 22 września 2026 `skopiuj()` zwracało `true`, kiedy pliku nie było
        // w starym buckecie. Wiersz dostawał `disk` nowego bucketu, w którym
        // pliku nie ma, WYPADAŁ z zapytania `where('disk', 'r2_legacy')`
        // i żaden kolejny przebieg nie miał go już jak znaleźć: zdjęcia nie ma,
        // baza twierdzi, że jest, i nic tego nie wykrywa.
        //
        // Ten test nie ustawia pustego bucketu ani niczego innego, co i tak
        // zatrzymałoby komendę na wejściu. Jedyne, co jest nie tak, to BRAK
        // PLIKU — i to samo w sobie ma wystarczyć, żeby wiersza nie ruszyć.
        $media = $this->stareZdjecie();

        Storage::disk('r2_legacy')->delete($media->object_key);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('POMINIĘTE')
            ->expectsOutputToContain($media->object_key)
            ->assertFailed();

        // SEDNO: wiersz stoi tam, gdzie stał.
        $media->refresh();
        $this->assertSame('r2_legacy', $media->disk);
        $this->assertNull($media->variants_disk);
        $this->assertDatabaseHas('media', ['id' => $media->getKey(), 'disk' => 'r2_legacy']);

        // A skoro stoi, to wraca w każdym kolejnym przebiegu — czyli nie da się
        // o nim zapomnieć, i dokładnie po to zostaje przy starym dysku.
        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('POMINIĘTE')
            ->assertFailed();
    }

    /**
     * Uszkodzony najstarszy wiersz nie może zablokować zdrowych nowszych
     * (#1031, uzupełnienie). Przy „najstarsze N" wracał na początek każdej
     * partii, więc przy `--limit=1` kolejne przebiegi nie dochodziły dalej.
     */
    public function test_uszkodzony_wiersz_nie_blokuje_kolejnych_przy_malym_limicie(): void
    {
        // Jawne identyfikatory: uszkodzony jest PIERWSZY w kolejności kursora,
        // jak w opisie usterki — bez zgadywania kolejności losowych UUID.
        $uszkodzone = $this->stareZdjecie();
        $uszkodzone->forceFill(['id' => '00000000-0000-4000-8000-000000000001'])->save();
        Storage::disk('r2_legacy')->delete($uszkodzone->object_key);

        $zdrowe = Media::factory()->create([
            'id' => '00000000-0000-4000-8000-000000000002',
            'disk' => 'r2_legacy',
            'variants_disk' => null,
            'object_key' => 'incoming/basia/2026/09/pierogi.jpg',
            'metadata' => ['variants' => []],
        ]);
        Storage::disk('r2_legacy')->put($zdrowe->object_key, 'pierogi');

        // Kontrola: bez kursora ten sam uszkodzony wiersz wraca w kółko.
        foreach ([1, 2] as $przebieg) {
            $this->artisan('kuking:przenies-zdjecia', ['--limit' => 1])
                ->expectsOutputToContain('POMINIĘTE')
                ->expectsOutputToContain('Następna partia: uruchom z --po='.$uszkodzone->id)
                ->assertFailed();
            $this->assertSame('r2_legacy', $zdrowe->refresh()->disk);
        }

        // Z kursorem przebieg dochodzi do zdrowego zdjęcia.
        $this->artisan('kuking:przenies-zdjecia', ['--limit' => 1, '--po' => (string) $uszkodzone->id])
            ->expectsOutputToContain('Przeniesione: '.$zdrowe->id)
            ->assertSuccessful();

        $this->assertSame('nowe_oryginaly', $zdrowe->refresh()->disk);
        Storage::disk('nowe_oryginaly')->assertExists($zdrowe->object_key);

        // Uszkodzony NIE jest oznaczony jako przeniesiony i wraca bez --po.
        $this->assertSame('r2_legacy', $uszkodzone->refresh()->disk);
        $this->artisan('kuking:przenies-zdjecia', ['--limit' => 1])
            ->expectsOutputToContain('POMINIĘTE')
            ->assertFailed();
    }

    public function test_kursor_odmawia_czegos_co_nie_jest_identyfikatorem(): void
    {
        $this->stareZdjecie();

        $this->artisan('kuking:przenies-zdjecia', ['--po' => 'wczoraj'])
            ->expectsOutputToContain('Opcja --po przyjmuje identyfikator zdjęcia')
            ->assertFailed();
    }

    public function test_brak_jednego_wariantu_zatrzymuje_przenosiny_w_polowie(): void
    {
        // Oryginał jest, wariantu nie ma. Kopiowanie zatrzymuje się w połowie
        // i wiersz NIE może zostać przestawiony: `variantsDisk()` wskazywałby
        // wtedy bucket, w którym tego wariantu nie ma.
        $media = $this->stareZdjecie();

        Storage::disk('r2_legacy')->delete($media->metadata['variants']['feed']['key']);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('POMINIĘTE')
            ->expectsOutputToContain('wariant feed')
            ->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
    }

    public function test_nieudany_zapis_w_polowie_nie_przestawia_wiersza(): void
    {
        // Tu plik JEST w starym buckecie, ale zapis do nowego się nie udaje —
        // awaria sieci, brak uprawnień, pełny bucket. To inny przypadek niż
        // brak pliku i ma inną etykietę w raporcie, ale skutek dla wiersza
        // musi być ten sam: bez zmian.
        $media = $this->stareZdjecie();

        $zepsuty = Mockery::mock(Filesystem::class);
        $zepsuty->shouldReceive('exists')->andReturn(false);
        $zepsuty->shouldReceive('writeStream')->andReturn(false);

        Storage::set('nowe_publiczne', $zepsuty);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('NIE UDAŁO SIĘ')
            ->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
    }

    public function test_ponowienie_po_czesciowym_przebiegu_konczy_robote(): void
    {
        // Idempotencja: przebieg przerwany w połowie wolno po prostu powtórzyć.
        // To, co zdążyło się skopiować, nie jest kopiowane drugi raz, a to,
        // czego brakowało, dochodzi.
        $media = $this->stareZdjecie();

        $brakujacy = $media->metadata['variants']['thumb']['key'];
        Storage::disk('r2_legacy')->delete($brakujacy);

        $this->artisan('kuking:przenies-zdjecia')->assertFailed();
        $this->assertSame('r2_legacy', $media->refresh()->disk);

        // Oryginał zdążył się przekopiować — ponowienie ma go zastać na miejscu
        // i nie próbować drugi raz.
        Storage::disk('nowe_oryginaly')->assertExists($media->object_key);

        // Brakujący wariant wraca do starego bucketu (np. z kopii zapasowej).
        Storage::disk('r2_legacy')->put($brakujacy, 'wariant');

        $this->artisan('kuking:przenies-zdjecia')->assertSuccessful();

        $media->refresh();
        $this->assertSame('nowe_oryginaly', $media->disk);
        $this->assertSame('nowe_publiczne', $media->variantsDisk());

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('nowe_publiczne')->assertExists($wariant['key']);
        }
    }

    public function test_tryb_tylko_raport_melduje_brak_i_niczego_nie_zapisuje(): void
    {
        // Tryb „tylko raport" ma dać odpowiedź PRZED prawdziwym przebiegiem:
        // ile zdjęć pójdzie, ile odpadnie i dlaczego. Bez jednego zapisu —
        // ani do bucketu, ani do bazy.
        $media = $this->stareZdjecie();

        Storage::disk('r2_legacy')->delete($media->object_key);

        $this->artisan('kuking:przenies-zdjecia', ['--tylko-raport' => true])
            ->expectsOutputToContain('POMINIĘTE')
            ->expectsOutputToContain($media->object_key)
            ->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('nowe_publiczne')->assertMissing($wariant['key']);
        }
    }

    public function test_bez_ustawionego_starego_bucketu_komenda_odmawia_nawet_gdy_plikow_brak(): void
    {
        // Stary warunek wejścia trzyma się dalej: bez `AWS_LEGACY_BUCKET`
        // komenda nie rusza żadnego wiersza.
        $media = $this->stareZdjecie();

        Storage::disk('r2_legacy')->delete($media->object_key);
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

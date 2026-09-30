<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji `r2_legacy` nie odłącza przeniesionych zdjęć (issue #2329, D-088).
 *
 * CO SIĘ DZIAŁO
 * `down()` bezwarunkowo przestawiało `r2_legacy` na `r2` i zerowało
 * `variants_disk`. Po `up()` nazwa `r2` znaczy jednak już tylko NOWY,
 * prywatny bucket — mają ją zdjęcia przeniesione przez
 * `kuking:przenies-zdjecia` i dodane po rozdzieleniu bucketów. Cofnięcie
 * zlewało stare wiersze z nowymi w jedną nazwę, a kolejne `migrate` (każdy
 * awaryjny rollback wdrożenia kończy się ponownym `up()`) przestawiało na
 * `r2_legacy` WSZYSTKIE — także te, których plików w starym buckecie nigdy
 * nie było. Zdjęcia znikały, a w bazie nie było śladu błędu.
 *
 * CO PILNUJE TEN TEST
 *  - odmowę po prawdziwych przenosinach (komenda, nie ręcznie ustawiony wiersz),
 *  - odmowę przy zdjęciu dodanym po rozdzieleniu,
 *  - że odmowa niczego nie zmienia,
 *  - kontrole dodatnie: stare zdjęcia bez przenosin i pusta baza — rollback
 *    przechodzi jak dawniej (odmowa ma być WĄSKA).
 *
 * @bez-kontroli-dodatniej plik migracji jest wczytywany i WYKONYWANY na prawdziwej bazie, a nie czytany jako tekst — test sprawdza zachowanie, a jego kontrole dodatnie są w samym teście.
 */
class CofniecieMigracjiStaregoBucketuOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2_legacy');
        Storage::fake('r2');
        Storage::fake('r2_publiczne');

        // Produkcyjny kształt: oryginały na `r2`, warianty na `r2_publiczne`.
        config([
            'kuking.media.disk' => 'r2',
            'kuking.media.public_disk' => 'r2_publiczne',
            'filesystems.disks.r2_legacy.bucket' => 'kuking-media-stary',
        ]);
    }

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_06_180000_point_existing_media_at_legacy_disk.php',
        );
    }

    /** Wiersz po `up()`: stare zdjęcie wskazujące stary bucket, z plikami w nim. */
    private function stareZdjecie(string $nazwa): Media
    {
        $media = Media::factory()->create([
            'disk' => 'r2_legacy',
            'variants_disk' => 'r2_legacy',
            'object_key' => "incoming/basia/2026/09/{$nazwa}.jpg",
            'metadata' => ['variants' => [
                'thumb' => ['key' => "media/basia/2026/09/{$nazwa}_thumb.webp"],
                'feed' => ['key' => "media/basia/2026/09/{$nazwa}_feed.webp"],
            ]],
        ]);

        Storage::disk('r2_legacy')->put($media->object_key, 'oryginal');

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('r2_legacy')->put($wariant['key'], 'wariant');
        }

        return $media;
    }

    private function cofnijOczekujacOdmowy(): RuntimeException
    {
        // Odmowę oceniamy POZA blokiem `try` (D-133).
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło, choć w bazie są zdjęcia wskazujące nowy bucket.');

        return $odmowa;
    }

    #[Test]
    public function test_cofniecie_odmawia_po_przenosinach_i_niczego_nie_rusza(): void
    {
        $this->stareZdjecie('sernik');
        $this->stareZdjecie('makowiec');

        // Przenosiny jednego zdjęcia — prawdziwą komendą. `--limit=1` bierze
        // najmniejsze `id`, więc to, które z dwóch, zależy od UUID.
        $this->artisan('kuking:przenies-zdjecia', ['--limit' => 1])->assertSuccessful();

        $przeniesione = Media::query()->where('disk', 'r2')->sole();
        $czekajace = Media::query()->where('disk', 'r2_legacy')->sole();
        $this->assertSame('r2_publiczne', $przeniesione->variants_disk, 'Przygotowanie: komenda powinna przestawić wiersz na nowe buckety.');

        $odmowa = $this->cofnijOczekujacOdmowy();

        // JEDNO zdjęcie — liczba na końcu, za mianownikiem (D-132).
        $this->assertStringContainsString(
            'Liczba zdjęć, które już wskazują NOWY bucket (`disk = r2`): 1.',
            $odmowa->getMessage(),
        );
        $this->assertStringContainsString('cofnij wdrożenie BEZ cofania tej migracji', $odmowa->getMessage());

        // Odmowa, która zdążyła przepisać wiersze, byłaby tylko ładniejszym
        // komunikatem o stracie. Oba wiersze są dokładnie takie jak przed nią.
        $this->assertSame(['r2', 'r2_publiczne'], $this->dyski($przeniesione));
        $this->assertSame(['r2_legacy', 'r2_legacy'], $this->dyski($czekajace));
    }

    #[Test]
    public function test_cofniecie_odmawia_przy_zdjeciu_dodanym_po_rozdzieleniu(): void
    {
        $stare = $this->stareZdjecie('sernik');

        // Nowe zdjęcie z `StoreUploadedImage`: oryginał od razu w nowym,
        // prywatnym buckecie. Nigdy nie leżało w starym.
        $nowe = Media::factory()->create(['disk' => 'r2', 'variants_disk' => 'r2_publiczne']);

        $this->cofnijOczekujacOdmowy();

        $this->assertSame(['r2_legacy', 'r2_legacy'], $this->dyski($stare));
        $this->assertSame(['r2', 'r2_publiczne'], $this->dyski($nowe));
    }

    #[Test]
    public function test_cofniecie_przechodzi_gdy_nic_nie_zostalo_przeniesione(): void
    {
        // KONTROLA DODATNIA: same stare zdjęcia, bez przenosin i bez nowych.
        // Wtedy rollback jest bezstratny (`up()` odtwarza go w całości),
        // więc ma przejść bez pytania.
        $stare = $this->stareZdjecie('sernik');

        $this->migracja()->down();

        $this->assertSame(['r2', null], $this->dyski($stare));

        // I z powrotem — ponowne `up()` odtwarza stan sprzed cofnięcia.
        $this->migracja()->up();

        $this->assertSame(['r2_legacy', 'r2_legacy'], $this->dyski($stare));
    }

    #[Test]
    public function test_cofniecie_przechodzi_na_bazie_bez_zdjec_r2(): void
    {
        // KONTROLA DODATNIA nr 2: dev/CI mają zdjęcia na dysku `public`.
        $lokalne = Media::factory()->create(['disk' => 'public', 'variants_disk' => 'public']);

        $this->migracja()->down();

        $this->assertSame(['public', 'public'], $this->dyski($lokalne));
    }

    /** @return array{0: string, 1: string|null} */
    private function dyski(Media $media): array
    {
        $wiersz = DB::table('media')->where('id', $media->getKey())->first(['disk', 'variants_disk']);

        return [(string) $wiersz->disk, $wiersz->variants_disk === null ? null : (string) $wiersz->variants_disk];
    }
}

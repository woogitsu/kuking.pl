<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Migracja przestawiająca stare zdjęcia na `r2_legacy` odmawia, gdy nie wie,
 * gdzie te pliki naprawdę leżą — i ma na to test (D-132).
 *
 * DLACZEGO TO JEST GROŹNE BEZ STRAŻNIKA
 * Wiersze z `disk = 'r2'` pochodzą sprzed rozdzielenia bucketów: ich pliki
 * leżą w starym, wspólnym buckecie, a nazwa `r2` wskazuje dziś nowy,
 * prywatny. Przestawienie ich na dysk BEZ bucketu to „pliki są nigdzie" —
 * zdjęcia znikają z serwisu, a w bazie nie ma śladu błędu.
 *
 * Ten strażnik siedzi w `up()`, nie w `down()`, bo to `up()` przepisuje
 * wiersze. Reszta wymagań jest ta sama: odmowa ma podać LICZBĘ w formie
 * odpornej na odmianę przez liczbę i niczego nie ruszyć.
 */
class StareZdjeciaR2OdmawiajaMigracjiTest extends TestCase
{
    use RefreshDatabase;

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_06_180000_point_existing_media_at_legacy_disk.php',
        );
    }

    private function stareZdjecie(): Media
    {
        $zdjecie = Media::factory()->for($this->user('basia'), 'owner')->create([
            'disk' => 'r2',
            'variants_disk' => null,
        ]);

        return $zdjecie;
    }

    #[Test]
    public function test_migracja_odmawia_gdy_nie_zna_starego_bucketu(): void
    {
        // JEDNO zdjęcie, nie pięć. Jeden wiersz jest stanem
        // prawdopodobniejszym niż pięć, a stara forma komunikatu („W bazie
        // jest 1 zdjęć") była błędna po polsku dokładnie przy tej jedynce.
        $zdjecie = $this->stareZdjecie();

        config(['filesystems.disks.r2_legacy.bucket' => '']);

        $odmowa = null;

        try {
            $this->migracja()->up();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Migracja przeszła, choć nie wie, w którym buckecie leżą stare pliki.');

        $this->assertStringContainsString(
            'Liczba zdjęć zapisanych przed rozdzieleniem bucketów R2: 1.',
            $odmowa->getMessage(),
        );

        // Stara, niegramatyczna forma nie ma prawa wrócić.
        $this->assertStringNotContainsString('jest 1 zdjęć', $odmowa->getMessage());

        $this->assertStringContainsString('AWS_LEGACY_BUCKET', $odmowa->getMessage());

        // Odmowa, która zdążyła już przepisać wiersz, byłaby tylko ładniejszym
        // komunikatem o stracie.
        $this->assertSame('r2', (string) DB::table('media')->where('id', $zdjecie->getKey())->value('disk'));
    }

    #[Test]
    public function test_migracja_przechodzi_gdy_stary_bucket_jest_znany(): void
    {
        // KONTROLA DODATNIA: przy ustawionym `AWS_LEGACY_BUCKET` nie ma o co
        // pytać i migracja ma przejść bez słowa. Strażnik, który odmawia
        // zawsze, jest błędem tej samej wagi w drugą stronę.
        $zdjecie = $this->stareZdjecie();

        config(['filesystems.disks.r2_legacy.bucket' => 'kuking-stary-wspolny']);

        $this->migracja()->up();

        $this->assertSame('r2_legacy', (string) DB::table('media')->where('id', $zdjecie->getKey())->value('disk'));

        // I droga powrotna, żeby nie zostawić bazy w połowie dla kolejnych
        // testów w tym samym procesie.
        $this->migracja()->down();

        $this->assertSame('r2', (string) DB::table('media')->where('id', $zdjecie->getKey())->value('disk'));
    }

    #[Test]
    public function test_na_swiezej_bazie_migracja_nie_ma_o_co_pytac(): void
    {
        // Świeże środowisko: żadnego wiersza z `disk = 'r2'`, więc strażnik
        // nie ma prawa się odezwać nawet bez ustawionego bucketu.
        config(['filesystems.disks.r2_legacy.bucket' => '']);

        $this->migracja()->up();

        $this->assertSame(0, (int) DB::table('media')->where('disk', 'r2')->count());
    }
}

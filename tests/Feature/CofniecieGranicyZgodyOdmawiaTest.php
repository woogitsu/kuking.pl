<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TozsamoscZewnetrzna;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji z `zgoda_potwierdzona_at` nie kasuje po cichu granicy
 * ostatniej zgody (issue #1025, D-088).
 *
 * Po `down()` i ponownym `migrate` kolumna wróciłaby pusta, a stare
 * powiadomienia Facebooka o odebraniu dostępu znów mogłyby usypiać
 * powiązania, dla których człowiek już na nowo dał zgodę. Test sprawdza
 * odmowę, przejście przy pustej kolumnie i działającą furtkę.
 *
 * @bez-kontroli-dodatniej Test nie czyta źródeł — uruchamia down()/up() migracji na bazie i sprawdza zachowanie; strażnik łapie tylko `base_path(` wewnątrz `database_path(`.
 */
class CofniecieGranicyZgodyOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private const ZGODA = 'KUKING_ROLLBACK_KASUJ_GRANICE_ZGODY';

    private const PLIK = 'migrations/2026_09_24_120000_dodaj_granice_zgody_dostawcy.php';

    private const TABELA = 'tozsamosci_zewnetrzne';

    private const KOLUMNA = 'zgoda_potwierdzona_at';

    protected function tearDown(): void
    {
        unset($_SERVER[self::ZGODA], $_ENV[self::ZGODA]);
        putenv(self::ZGODA);

        parent::tearDown();
    }

    private function migracja(): object
    {
        return require database_path(self::PLIK);
    }

    private function zZapisanaGranica(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook('10221234567890123');
        $basia->cofnijOdebranieDostepu(TozsamoscZewnetrzna::DOSTAWCA_FACEBOOK);
    }

    public function test_cofniecie_odmawia_gdy_jest_zapisana_granica(): void
    {
        $this->zZapisanaGranica();

        try {
            $this->migracja()->down();
            $this->fail('Cofnięcie miało odmówić — skasowałoby granicę zgody bez śladu.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString(self::ZGODA.'=true', $e->getMessage());
            $this->assertStringContainsString('Liczba powiązań, których to dotyczy: 1.', $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn(self::TABELA, self::KOLUMNA));
    }

    public function test_cofniecie_przechodzi_przy_pustej_kolumnie_i_wraca(): void
    {
        $basia = $this->user('basia');
        $basia->connectFacebook('10221234567890123');

        $this->migracja()->down();
        $this->assertFalse(Schema::hasColumn(self::TABELA, self::KOLUMNA));

        $this->migracja()->up();
        $this->assertTrue(Schema::hasColumn(self::TABELA, self::KOLUMNA));
    }

    public function test_furtka_z_komunikatu_dziala(): void
    {
        $this->zZapisanaGranica();

        putenv(self::ZGODA.'=true');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasColumn(self::TABELA, self::KOLUMNA));

        $this->migracja()->up();
    }
}

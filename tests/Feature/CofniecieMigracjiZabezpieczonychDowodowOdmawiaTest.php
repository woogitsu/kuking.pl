<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Models\ZabezpieczenieDowodu;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\PolecenieArtisanaZOdmowa;
use Tests\TestCase;

/**
 * Rollback migracji ścieżki CSAM nie zdejmuje ochrony z dowodów (D-088,
 * D-333 z 1.10.2026).
 *
 * Dwie migracje, dwa strażniki, każdy z odmową ORAZ kontrolą dodatnią
 * (`PULAPKI_TESTOW.md` #4): odmowa bez dowodu, że rollback na pustych danych
 * przechodzi, byłaby zablokowaniem rollbacku na zawsze.
 *
 * Kontrola ujemna (ręczna): usunięcie `throw` z `down()` którejkolwiek
 * migracji oblewa odpowiedni test odmowy.
 */
class CofniecieMigracjiZabezpieczonychDowodowOdmawiaTest extends TestCase
{
    use RefreshDatabase;

    private const TABELA = 'database/migrations/2026_10_01_150000_create_zabezpieczenia_dowodow_table.php';

    private const STATUS = 'database/migrations/2026_10_01_150100_allow_secured_media_status.php';

    #[Test]
    public function test_cofniecie_rejestru_odmawia_gdy_sa_zabezpieczone_dowody(): void
    {
        $this->dowod();

        $wyjatek = $this->cofnijOczekujacOdmowy(self::TABELA);

        $this->assertStringContainsString('Odmawiam cofnięcia migracji', $wyjatek->getMessage());
        $this->assertStringContainsString('1 zabezpieczonych dowodów', $wyjatek->getMessage());
        $this->assertTrue(Schema::hasTable('zabezpieczenia_dowodow'), 'Odmowa nie może zostawić rollbacku w połowie.');
        $this->assertSame(1, ZabezpieczenieDowodu::count());
    }

    #[Test]
    public function test_cofniecie_rejestru_na_pustej_tabeli_przechodzi(): void
    {
        PolecenieArtisanaZOdmowa::wywolaj('migrate:rollback', ['--path' => self::TABELA, '--realpath' => false]);

        $this->assertFalse(Schema::hasTable('zabezpieczenia_dowodow'));
    }

    #[Test]
    public function test_cofniecie_statusu_odmawia_gdy_jest_zdjecie_secured(): void
    {
        $zdjecie = Media::factory()->create();
        DB::table('media')->where('id', $zdjecie->getKey())->update(['status' => Media::STATUS_SECURED]);

        $wyjatek = $this->cofnijOczekujacOdmowy(self::STATUS);

        $this->assertStringContainsString('Odmawiam cofnięcia migracji', $wyjatek->getMessage());
        $this->assertSame(Media::STATUS_SECURED, $zdjecie->fresh()->status);
    }

    #[Test]
    public function test_cofniecie_statusu_bez_zdjec_secured_przywraca_stary_check(): void
    {
        PolecenieArtisanaZOdmowa::wywolaj('migrate:rollback', ['--path' => self::STATUS, '--realpath' => false]);

        $zdjecie = Media::factory()->create();

        try {
            DB::transaction(fn () => DB::table('media')->where('id', $zdjecie->getKey())->update(['status' => Media::STATUS_SECURED]));
            $this->fail('Stary CHECK powinien odrzucić status secured.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('media_status_check', $e->getMessage());
        }
    }

    #[Test]
    public function test_check_rejestru_wymaga_poprzedniego_statusu_dla_zdjecia_i_zabrania_go_dla_reszty(): void
    {
        $autor = User::factory()->create();
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);
        $zdjecie = Media::factory()->create();

        foreach ([
            'zdjęcie bez poprzedniego statusu' => ['media', $zdjecie->getKey(), null],
            'wpis z poprzednim statusem zdjęcia' => ['post', $wpis->getKey(), 'ready'],
            'zdjęcie ze statusem spoza listy' => ['media', $zdjecie->getKey(), 'deleted'],
        ] as $opis => [$typ, $id, $status]) {
            try {
                // Punkt zapisu: nieudany INSERT zrywa transakcję testu (25P02).
                DB::transaction(fn () => DB::table('zabezpieczenia_dowodow')->insert([
                    'target_type' => $typ,
                    'target_id' => $id,
                    'previous_media_status' => $status,
                ]));
                $this->fail("CHECK przepuścił wiersz: {$opis}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('zabezpieczenia_dowodow_previous_media_status_check', $e->getMessage(), $opis);
            }
        }

        // Kontrola dodatnia: poprawne wiersze przechodzą.
        DB::table('zabezpieczenia_dowodow')->insert(['target_type' => 'media', 'target_id' => $zdjecie->getKey(), 'previous_media_status' => 'ready']);
        DB::table('zabezpieczenia_dowodow')->insert(['target_type' => 'post', 'target_id' => $wpis->getKey()]);
        $this->assertSame(2, DB::table('zabezpieczenia_dowodow')->count());

        // Ten sam obiekt dwa razy — UNIQUE.
        $this->expectException(QueryException::class);
        DB::table('zabezpieczenia_dowodow')->insert(['target_type' => 'post', 'target_id' => $wpis->getKey()]);
    }

    private function dowod(): void
    {
        $wpis = Post::factory()->create();
        (new ZabezpieczenieDowodu)->forceFill(['target_type' => 'post', 'target_id' => $wpis->getKey()])->save();
    }

    private function cofnijOczekujacOdmowy(string $sciezka): RuntimeException
    {
        try {
            PolecenieArtisanaZOdmowa::wywolaj('migrate:rollback', ['--path' => $sciezka, '--realpath' => false]);
        } catch (RuntimeException $e) {
            return $e;
        }

        $this->fail('Cofnięcie migracji przeszło, mimo że chroni dowody.');
    }
}

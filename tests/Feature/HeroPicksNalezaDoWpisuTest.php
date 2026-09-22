<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\DostepDoZdjecia;
use App\Models\Media;
use App\Models\Post;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * `hero_picks` nie może nadać zdjęciu publicznego rodzica, do którego to
 * zdjęcie nigdy nie należało (#955).
 *
 * Kontrola ujemna jest wykonywana osobno przy odbiorze: usunięcie nowego FK
 * ma pozwolić na błędny INSERT i sprawić, że test odmowy pada dokładnie na
 * braku SQLSTATE 23503.
 */
class HeroPicksNalezaDoWpisuTest extends TestCase
{
    use RefreshDatabase;

    private const CONSTRAINT = 'hero_picks_post_media_foreign';

    private const MIGRACJA = 'database/migrations/2026_09_22_100000_powiaz_hero_picks_z_post_media.php';

    #[Test]
    public function test_schemat_wymusza_pare_wpisu_i_zdjecia_z_kaskada(): void
    {
        $definicja = DB::selectOne(
            'SELECT pg_get_constraintdef(oid) AS definicja FROM pg_constraint '
            .'WHERE conrelid = ?::regclass AND conname = ?',
            ['hero_picks', self::CONSTRAINT],
        );

        $this->assertNotNull($definicja, 'Brakuje złożonego klucza obcego dla wyboru kolażu.');
        $this->assertSame(
            'FOREIGN KEY (post_id, media_id) REFERENCES post_media(post_id, media_id) ON DELETE CASCADE',
            $definicja->definicja,
        );
    }

    #[Test]
    public function test_poprawna_para_przechodzi_a_odpiecie_zdjecia_usuwa_wybor(): void
    {
        [$wpis, $zdjecie] = $this->wpisZeZdjeciem(publiczny: true);

        $this->wskaz($wpis, $zdjecie);
        $this->assertDatabaseHas('hero_picks', [
            'post_id' => $wpis->getKey(),
            'media_id' => $zdjecie->getKey(),
        ]);

        DB::table('post_media')
            ->where('post_id', $wpis->getKey())
            ->where('media_id', $zdjecie->getKey())
            ->delete();

        $this->assertDatabaseMissing('hero_picks', ['media_id' => $zdjecie->getKey()]);
        $this->assertDatabaseHas('posts', ['id' => $wpis->getKey()]);
        $this->assertDatabaseHas('media', ['id' => $zdjecie->getKey()]);
    }

    #[Test]
    public function test_obcy_publiczny_wpis_nie_moze_stac_sie_rodzicem_prywatnego_zdjecia(): void
    {
        [$publicznyWpis] = $this->wpisZeZdjeciem(publiczny: true);
        [, $prywatneZdjecie] = $this->wpisZeZdjeciem(publiczny: false);

        try {
            // Osobny savepoint: PostgreSQL po odrzuconym INSERT-cie oznacza
            // bieżącą transakcję jako przerwaną. Cofnięcie do savepointu
            // pozwala sprawdzić stan po odmowie zamiast tylko sam wyjątek.
            DB::transaction(fn () => $this->wskaz($publicznyWpis, $prywatneZdjecie));
            $this->fail('Baza przyjęła zdjęcie, które nie należy do wskazanego wpisu.');
        } catch (QueryException $e) {
            $this->assertSame('23503', $e->errorInfo[0] ?? null);
            $this->assertStringContainsString(self::CONSTRAINT, $e->getMessage());
        }

        $this->assertDatabaseMissing('hero_picks', ['media_id' => $prywatneZdjecie->getKey()]);
        $this->assertFalse(
            app(DostepDoZdjecia::class)->moze(null, $prywatneZdjecie->refresh()),
            'Odrzucona para zostawiła zdjęciu fałszywego publicznego rodzica.',
        );
    }

    #[Test]
    public function test_migracja_odmawia_przy_zastanej_niespojnej_parze_i_niczego_nie_zmienia(): void
    {
        [$publicznyWpis] = $this->wpisZeZdjeciem(publiczny: true);
        [, $prywatneZdjecie] = $this->wpisZeZdjeciem(publiczny: false);

        $migracja = $this->migracja();
        $migracja->down();
        $this->wskaz($publicznyWpis, $prywatneZdjecie);

        try {
            $migracja->up();
            $this->fail('Migracja przeszła mimo zastanej niespójnej pary.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba niespójnych par: 1.', $e->getMessage());
            $this->assertStringContainsString('migracja nie przepina zdjęć i niczego nie kasuje', $e->getMessage());
            $this->assertStringContainsString('SELECT hp.id, hp.post_id, hp.media_id', $e->getMessage());
        }

        $this->assertDatabaseHas('hero_picks', [
            'post_id' => $publicznyWpis->getKey(),
            'media_id' => $prywatneZdjecie->getKey(),
        ]);
        $this->assertFalse($this->constraintIstnieje());

        // Przywrócenie schematu dla dalszych testów w tym samym procesie.
        DB::table('hero_picks')->where('media_id', $prywatneZdjecie->getKey())->delete();
        $migracja->up();
        $this->assertTrue($this->constraintIstnieje());
    }

    #[Test]
    public function test_rollback_zdejmuje_tylko_constraint_i_zostawia_dane(): void
    {
        [$wpis, $zdjecie] = $this->wpisZeZdjeciem(publiczny: true);
        $this->wskaz($wpis, $zdjecie);

        $migracja = $this->migracja();
        $migracja->down();

        $this->assertFalse($this->constraintIstnieje());
        $this->assertDatabaseHas('hero_picks', [
            'post_id' => $wpis->getKey(),
            'media_id' => $zdjecie->getKey(),
        ]);

        $migracja->up();
        $this->assertTrue($this->constraintIstnieje());
    }

    /** @return array{0: Post, 1: Media} */
    private function wpisZeZdjeciem(bool $publiczny): array
    {
        $autor = $this->user('autor_'.Str::lower(Str::random(12)));
        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => $publiczny ? Post::VISIBILITY_PUBLIC : Post::VISIBILITY_PRIVATE,
        ]);
        $zdjecie = Media::factory()->for($autor, 'owner')->create();
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        return [$wpis, $zdjecie];
    }

    private function wskaz(Post $wpis, Media $zdjecie): void
    {
        DB::table('hero_picks')->insert([
            'post_id' => $wpis->getKey(),
            'media_id' => $zdjecie->getKey(),
            'position' => 0,
        ]);
    }

    private function constraintIstnieje(): bool
    {
        return DB::selectOne(
            'SELECT 1 FROM pg_constraint WHERE conrelid = ?::regclass AND conname = ?',
            ['hero_picks', self::CONSTRAINT],
        ) !== null;
    }

    private function migracja(): object
    {
        return require base_path(self::MIGRACJA);
    }
}

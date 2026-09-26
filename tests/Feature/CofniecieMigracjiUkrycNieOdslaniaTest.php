<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Hide;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * D-088 dla tabeli `hides` (issue #1810): rollback ODMAWIA, gdy jest choć jedno
 * aktywne ukrycie — zrzucenie tabeli cicho przywróciłoby ludziom na ekran to,
 * co sami schowali. Odmowa jest wąska: same wygasłe ukrycia i pusta tabela
 * przepuszczają rollback (kontrola dodatnia).
 */
class CofniecieMigracjiUkrycNieOdslaniaTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA = 'database/migrations/2026_09_26_100000_create_hides_table.php';

    public function test_rollback_odmawia_przy_aktywnym_ukryciu_i_niczego_nie_zdejmuje(): void
    {
        $widz = $this->user('widz');
        $wpis = Post::factory()->create(['author_id' => $this->user('autorka')->id]);
        $this->actingAs($widz)->post(route('posts.hide', $wpis))->assertRedirect();
        $ukrycie = Hide::query()->firstOrFail();

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA]);
            $this->fail('Rollback przeszedł mimo aktywnego ukrycia.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 aktywnych ukryć', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
            $this->assertTrue(Schema::hasTable('hides'));
            $this->assertSame(1, Hide::query()->count());
        } finally {
            if (Schema::hasTable('hides')) {
                Hide::query()->whereKey($ukrycie->getKey())->delete();
            }
        }

        $this->assertSame(0, Hide::query()->count());
    }

    public function test_ukrycie_na_stale_tez_blokuje_rollback(): void
    {
        $widz = $this->user('widz');
        $wpis = Post::factory()->create(['author_id' => $this->user('autorka')->id]);
        $this->actingAs($widz)->post(route('posts.hide', $wpis));
        $ukrycie = Hide::query()->firstOrFail();
        $ukrycie->forceFill(['hidden_until' => null])->save();
        $this->travel(400)->days();

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA]);
            $this->fail('Rollback przeszedł mimo ukrycia na stałe.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('1 aktywnych ukryć', $e->getMessage());
            $this->assertTrue(Schema::hasTable('hides'));
        } finally {
            if (Schema::hasTable('hides')) {
                Hide::query()->whereKey($ukrycie->getKey())->delete();
            }
        }

        $this->assertSame(0, Hide::query()->count());
    }

    public function test_same_wygasle_ukrycia_przepuszczaja_rollback(): void
    {
        $widz = $this->user('widz');
        $wpis = Post::factory()->create(['author_id' => $this->user('autorka')->id]);
        $this->actingAs($widz)->post(route('posts.hide', $wpis));
        $this->travel(31)->days();

        $this->assertSame(1, Hide::query()->count());
        $this->assertSame(0, Hide::query()->aktywne()->count());

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA]);
        $this->assertFalse(Schema::hasTable('hides'));

        Artisan::call('migrate', ['--path' => self::SCIEZKA]);
        $this->assertTrue(Schema::hasTable('hides'));
    }
}

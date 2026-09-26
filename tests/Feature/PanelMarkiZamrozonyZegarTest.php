<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyPick;
use App\Models\Post;
use App\Support\Czas;
use App\Support\ZamrozonyCzas;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Issue #1836 — job CI „Panel marki — puste i pełne widoki" oblewał, gdy
 * przebieg przechodził przez północ czasu polskiego.
 *
 * Fixture (`scripts/fixtures/panel-marki.php`) i panel oglądany przez
 * `php artisan serve` to DWA osobne procesy PHP. Fixture zapisywał
 * `DailyPick::shown_on = Czas::dzisiajData()` W CHWILI BUDOWY, a panel liczył
 * „dziś" PONOWNIE, chwilę później, po logowaniu i TOTP. 25.09 o 22:00 UTC
 * (00:00 CEST) ten odstęp wystarczył, żeby oba procesy zobaczyły RÓŻNY dzień.
 *
 * Nie da się tu uruchomić przeglądarki (brak środowiska Playwrighta w tym
 * zestawie testów) — kontrola niżej odtwarza więc SEDNO błędu bez niej:
 * dwa odczyty „dziś" oddzielone realnym upływem czasu, tuż przed północą
 * czasu polskiego, muszą dać TĘ SAMĄ datę, gdy zegar jest zamrożony.
 */
class PanelMarkiZamrozonyZegarTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ZamrozonyCzas::odmroz();

        parent::tearDown();
    }

    public function test_bez_zmiennej_zegar_biegnie_normalnie(): void
    {
        ZamrozonyCzas::zastosuj('');

        $this->assertFalse(Carbon::hasTestNow());
    }

    public function test_zamrozony_czas_tuz_przed_polnoca_nie_przeskakuje_dnia_mimo_uplywu_czasu(): void
    {
        // 23:59:50 czasu polskiego 25 września (CEST, UTC+2) to 21:59:50 UTC
        // — dokładnie ta granica, na której padł prawdziwy przebieg z issue.
        ZamrozonyCzas::zastosuj('2026-09-25T21:59:50+00:00');

        $this->assertSame('2026-09-25', Czas::dzisiajData());

        // Odtwarza realny upływ czasu MIĘDZY zapisem fixture'a a odczytem
        // przez panel: logowanie, TOTP, kilka żądań HTTP. Zegar jest
        // zamrożony, więc ten upływ nie może zmienić wyniku.
        usleep(300_000);

        $this->assertSame(
            '2026-09-25',
            Czas::dzisiajData(),
            'Zamrożony zegar nie może przeskoczyć dnia mimo realnego upływu czasu — to jest dokładnie awaria z issue #1836.',
        );
    }

    public function test_fixture_i_panel_widza_ten_sam_dzien_mimo_realnego_uplywu_czasu(): void
    {
        ZamrozonyCzas::zastosuj('2026-09-25T21:59:59+00:00');

        $gospodarz = $this->moderator();
        $autor = $this->user('autor_1836');
        $post = Post::factory()->create(['author_id' => $autor->getKey(), 'published_at' => now()->subHour()]);

        // Odpowiednik zapisu w `scripts/fixtures/panel-marki.php:224`.
        DailyPick::create([
            'shown_on' => Czas::dzisiajData(),
            'subject_type' => DailyPick::TYPE_POST,
            'subject_id' => $post->getKey(),
            'position' => 0,
            'curator_id' => $gospodarz->getKey(),
        ]);

        // Realny upływ czasu, jak logowanie + TOTP + żądania HTTP w CI —
        // bez zamrożenia to właśnie ten odstęp przeniósłby drugi proces na
        // kolejny dzień polski.
        usleep(300_000);

        $this->assertTrue(
            DailyPick::query()->forDate()->where('subject_id', $post->getKey())->exists(),
            'Panel (drugi odczyt „dziś") nie widzi wyboru zapisanego przez fixture chwilę wcześniej tego samego zamrożonego dnia.',
        );
    }

    public function test_zmienna_poza_local_i_testing_odmawia(): void
    {
        $this->app->instance('env', 'production');

        $this->expectException(RuntimeException::class);
        ZamrozonyCzas::zastosuj('2026-09-25T21:59:50+00:00');
    }

    public function test_niepoprawna_wartosc_zmiennej_konczy_sie_wyjatkiem(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ZamrozonyCzas::zastosuj('nie-jest-data');
    }
}

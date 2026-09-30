<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\LicznikiBazy;
use App\Models\CookedEvent;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `kuking:liczniki-bazy` zastępuje krok 1 ćwiczenia odtworzenia, który szedł
 * przez tinkera (issue #2223, D-333). Liczby mają się zgadzać z tym, co
 * policzy psql na odtworzonej kopii — czyli z WIERSZAMI, także miękko
 * usuniętymi.
 */
class LicznikiBazyTest extends TestCase
{
    use RefreshDatabase;

    public function test_liczy_wiersze_tabel_takze_miekko_usuniete_wpisy(): void
    {
        $usuniety = Post::factory()->create();
        Post::factory()->count(2)->create();
        CookedEvent::factory()->create();
        $usuniety->delete();

        // Kontrola dodatnia założenia: model nie widzi usuniętego wpisu,
        // więc liczba z modelu różniłaby się od psql.
        $this->assertSame(DB::table('posts')->count() - 1, Post::query()->count());

        $oczekiwane = array_map(
            fn (string $tabela) => $tabela.' '.DB::table($tabela)->count(),
            LicznikiBazy::TABELE,
        );

        $polecenie = $this->artisan('kuking:liczniki-bazy');
        foreach ($oczekiwane as $wiersz) {
            $polecenie->expectsOutput($wiersz);
        }
        $polecenie->assertSuccessful();

        $this->assertContains('posts 3', $oczekiwane);
    }
}

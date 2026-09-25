<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Feed\DailyBoard;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Kuking na dziś" nie agreguje całych publicznych `posts` przy każdej
 * odsłonie (audyt B4 W1).
 *
 * Kandydaci niezależni od widza leżą w cache; wykluczenia widza (blokady,
 * obserwowani) odsiewa PHP, a pełne modele dociąga zapytanie z tymi samymi
 * bramkami co zawsze — więc cache nie może pokazać treści, której widz nie
 * powinien zobaczyć.
 */
class TablicaDniaKandydaciWCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_druga_odslona_nie_agreguje_calej_tabeli_wpisow(): void
    {
        foreach (range(1, 4) as $n) {
            $this->autorZWpisem('kandydat'.$n, $n);
        }

        $tablica = app(DailyBoard::class);
        $pierwsza = $tablica->forViewer(null);

        DB::enableQueryLog();
        $druga = $tablica->forViewer($this->user('widzdrugi'));
        $zapytania = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();

        $this->assertStringNotContainsString('max(published_at)', $zapytania, 'Propozycje osób liczone od nowa przy odsłonie.');
        $this->assertStringNotContainsString('DISTINCT ON', $zapytania, 'Dania liczone od nowa przy odsłonie.');

        $this->assertCount(4, $pierwsza['people']);
        $this->assertSame($pierwsza['posts']->modelKeys(), $druga['posts']->modelKeys());
        $this->assertCount(4, $druga['people']);
    }

    public function test_blokada_odsiewa_autora_z_kandydatow_w_cache(): void
    {
        $zablokowany = $this->autorZWpisem('zablokowany', 1);
        $inny = $this->autorZWpisem('innyautor', 2);
        $widz = $this->user('widzblokujacy');

        $tablica = app(DailyBoard::class);
        $tablica->forViewer(null); // rozgrzewa cache z oboma autorami

        app(BlockUser::class)->handle($widz, $zablokowany);

        $wynik = $tablica->forViewer($widz->fresh());

        $this->assertNotContains($zablokowany->getKey(), $wynik['people']->modelKeys());
        $this->assertNotContains($zablokowany->getKey(), $wynik['posts']->pluck('author_id')->all());
        // Kontrola dodatnia: nie odsiewamy wszystkiego.
        $this->assertContains($inny->getKey(), $wynik['people']->modelKeys());
        $this->assertContains($inny->getKey(), $wynik['posts']->pluck('author_id')->all());
    }

    public function test_wpis_schowany_po_zapisaniu_cache_nie_wraca_na_tablice(): void
    {
        $autor = $this->autorZWpisem('schowany', 1);
        $drugi = $this->autorZWpisem('widoczny', 2);

        $tablica = app(DailyBoard::class);
        $this->assertContains($autor->getKey(), $tablica->forViewer(null)['posts']->pluck('author_id')->all());

        Post::query()->where('author_id', $autor->getKey())->update(['status' => Post::STATUS_HIDDEN]);
        $zawieszony = $drugi;
        $zawieszony->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $wynik = $tablica->forViewer(null);

        $this->assertNotContains($autor->getKey(), $wynik['posts']->pluck('author_id')->all(), 'Schowany wpis wrócił z cache.');
        $this->assertNotContains($zawieszony->getKey(), $wynik['people']->modelKeys(), 'Zawieszone konto wróciło z cache.');
        $this->assertNotContains($zawieszony->getKey(), $wynik['posts']->pluck('author_id')->all());
    }

    /**
     * TEST REGRESYJNY: nieaktualny cache nie zostawia pustej tablicy.
     *
     * Dostępność axe (skrypt `fokus-karty-dania.mjs`) stawia dane
     * demonstracyjne od nowa w tej samej bazie, a cache plikowy zostaje —
     * kandydaci wskazywali konta i wpisy, których już nie było, lista
     * kandydatów była „niepełna”, więc rezerwa się nie uruchamiała i Start
     * pokazywał pustą tablicę mimo świeżych wpisów. Ten sam mechanizm
     * w produkcji: usunięte konto chowało nową osobę na pięć minut.
     */
    public function test_kandydaci_ktorych_juz_nie_ma_nie_zostawiaja_pustej_tablicy(): void
    {
        $stary = $this->autorZWpisem('starykandydat', 1);

        $tablica = app(DailyBoard::class);
        $this->assertContains($stary->getKey(), $tablica->forViewer(null)['people']->modelKeys());

        // Baza „od nowa”: kandydata z cache już nie ma, jest ktoś nowy.
        Post::query()->where('author_id', $stary->getKey())->forceDelete();
        $stary->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $nowy = $this->autorZWpisem('nowykandydat', 0);

        $wynik = $tablica->forViewer(null);

        $this->assertContains($nowy->getKey(), $wynik['people']->modelKeys(), 'Nieaktualny cache schował nową osobę.');
        $this->assertContains($nowy->getKey(), $wynik['posts']->pluck('author_id')->all(), 'Nieaktualny cache schował nowe danie.');
        $this->assertNotContains($stary->getKey(), $wynik['people']->modelKeys());
    }

    private function autorZWpisem(string $nazwa, int $godzinTemu): User
    {
        $user = $this->user($nazwa);

        Post::factory()->create([
            'author_id' => $user->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHours($godzinTemu),
        ]);

        return $user;
    }
}

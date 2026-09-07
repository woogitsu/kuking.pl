<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Profile;
use App\Models\Recipe;
use App\Models\User;
use Database\Seeders\TrescZalazkowaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `TrescZalazkowaSeeder` — D-025, `docs/DECISIONS.md`.
 *
 * Trzy rzeczy sprawdzane tutaj wprost z zadania: konta z pliku powstają
 * i są oznaczone, drugi przebieg nic nie zmienia, a konto o tej samej
 * nazwie użytkownika co PRAWDZIWY człowiek zostaje nietknięte razem z całą
 * swoją treścią z pliku.
 */
class TrescZalazkowaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_tworzy_dwanascie_kont_oznaczonych_jako_przykladowe(): void
    {
        $this->seed(TrescZalazkowaSeeder::class);

        $this->assertSame(12, User::query()->where('is_seeded', true)->count());
        // 39, nie 40: przepis p24 („Pomidory we własnym soku do słoików”)
        // został wstrzymany przeglądem bezpieczeństwa żywności
        // (`docs/decyzje/PRZEGLAD_BEZPIECZENSTWA_ZYWNOSCI.md`, jedyna ocena
        // BLOKUJE) i decyzją właściciela usunięty z treści startowej. Że to
        // jest usunięcie, a nie zguba — i że nic nie wskazuje już w próżnię —
        // pilnuje `BezpieczenstwoZywnosciWTresciZalazkowejTest`.
        $this->assertSame(39, Recipe::query()->count());
        $this->assertSame(80, Post::query()->count());
        $this->assertSame(60, Comment::query()->count());

        // Jedna konkretna persona z pliku, sprawdzona po nazwie — nie tylko
        // liczba, ale i to, że to naprawdę TA treść (`dane/tresc-zalazkowa.json`).
        $basia = Profile::query()->where('username', 'basia')->firstOrFail();
        $this->assertTrue($basia->user->isSeeded());
        $this->assertSame('Basia z Podkarpacia', $basia->display_name);
    }

    public function test_drugi_przebieg_nic_nie_zmienia(): void
    {
        $this->seed(TrescZalazkowaSeeder::class);

        $kontaPrzed = User::query()->where('is_seeded', true)->pluck('id')->sort()->values();
        $przepisyPrzed = Recipe::query()->pluck('id')->sort()->values();
        $wpisyPrzed = Post::query()->pluck('id')->sort()->values();
        $komentarzePrzed = Comment::query()->pluck('id')->sort()->values();

        $this->seed(TrescZalazkowaSeeder::class);

        $this->assertSame(12, User::query()->where('is_seeded', true)->count());
        $this->assertSame(39, Recipe::query()->count());
        $this->assertSame(80, Post::query()->count());
        $this->assertSame(60, Comment::query()->count());

        // Nie tylko te same LICZBY — te same WIERSZE. Drugi przebieg, gdyby
        // czegokolwiek nie rozpoznał jako już zaimportowane, dublowałby dane
        // zamiast po prostu nic nie robić.
        $this->assertSame($kontaPrzed->all(), User::query()->where('is_seeded', true)->pluck('id')->sort()->values()->all());
        $this->assertSame($przepisyPrzed->all(), Recipe::query()->pluck('id')->sort()->values()->all());
        $this->assertSame($wpisyPrzed->all(), Post::query()->pluck('id')->sort()->values()->all());
        $this->assertSame($komentarzePrzed->all(), Comment::query()->pluck('id')->sort()->values()->all());
    }

    public function test_prawdziwe_konto_o_tej_samej_nazwie_nie_jest_ruszane(): void
    {
        // Prawdziwy człowiek zdążył zarejestrować „basia" ZANIM treść
        // zalążkowa w ogóle weszła na tę bazę.
        $prawdziwaBasia = $this->user('basia', ['email' => 'prawdziwa-basia@example.com']);

        $this->seed(TrescZalazkowaSeeder::class);

        $prawdziwaBasia->refresh();
        $this->assertFalse($prawdziwaBasia->isSeeded());
        $this->assertSame('prawdziwa-basia@example.com', $prawdziwaBasia->email);

        // Jedenaście pozostałych person z pliku i tak powstaje.
        $this->assertSame(11, User::query()->where('is_seeded', true)->count());

        // Żadna treść z pliku nie została podpięta pod prawdziwe konto —
        // nie mieliśmy pod kogo innego jej podpiąć, więc cała persona
        // „basia" (cztery przepisy, siedem wpisów z pliku) jest pominięta,
        // nie przepisana na kogoś innego.
        $this->assertSame(0, Recipe::query()->where('author_id', $prawdziwaBasia->getKey())->count());
        $this->assertSame(0, Post::query()->where('author_id', $prawdziwaBasia->getKey())->count());
    }
}

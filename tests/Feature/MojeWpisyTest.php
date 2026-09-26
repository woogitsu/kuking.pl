<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Posts\MojeWpisy;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Moje wpisy” w „Moje” (D-328): lista WŁASNYCH wpisów autora — także
 * prywatnych, dla obserwujących, szkiców i ukrytych przez moderację.
 */
class MojeWpisyTest extends TestCase
{
    use RefreshDatabase;

    private function wpis(User $autor, array $atrybuty = [], ?string $stan = null): Post
    {
        $fabryka = Post::factory();
        if ($stan !== null) {
            $fabryka = $fabryka->{$stan}();
        }

        return $fabryka->create(['author_id' => $autor->getKey()] + $atrybuty);
    }

    public function test_gosc_trafia_do_logowania(): void
    {
        $this->get(route('collections.own-posts'))->assertRedirect(route('login'));
    }

    public function test_lista_pokazuje_wszystkie_wlasne_wpisy_z_widocznoscia_i_stanem(): void
    {
        $autor = $this->user('autorka');

        $this->wpis($autor, ['body' => 'Publiczny rosół', 'published_at' => now()->subDays(4)]);
        $this->wpis($autor, ['body' => 'Pierogi dla obserwujących', 'published_at' => now()->subDays(3)], 'followersOnly');
        $this->wpis($autor, ['body' => 'Sekretny bigos', 'published_at' => now()->subDays(2)], 'private');
        $this->wpis($autor, ['body' => 'Szkic sernika', 'created_at' => now()->subDay()], 'draft');
        $this->wpis($autor, ['body' => 'Ukryty gulasz', 'status' => Post::STATUS_HIDDEN, 'published_at' => now()->subHours(2)]);

        $odpowiedz = $this->actingAs($autor)->get(route('collections.own-posts'))->assertOk();

        // Od najnowszego; szkic stoi według chwili założenia.
        $odpowiedz->assertSeeInOrder([
            'Ukryty gulasz', 'Ukryty przez moderację',
            'Szkic sernika', 'Szkic — jeszcze nieopublikowany',
            'Sekretny bigos', 'Tylko dla mnie',
            'Pierogi dla obserwujących', 'Dla obserwujących',
            'Publiczny rosół', 'Publiczny', 'Opublikowany',
        ]);
        $this->assertCount(5, $odpowiedz->viewData('wpisy')->items());
    }

    public function test_inna_osoba_widzi_tylko_swoja_liste_nie_cudza(): void
    {
        $autor = $this->user('autorka');
        $obca = $this->user('obca');

        $this->wpis($autor, ['body' => 'Sekretny bigos autorki'], 'private');
        $this->wpis($autor, ['body' => 'Szkic autorki'], 'draft');
        $this->wpis($obca, ['body' => 'Własny wpis obcej']);

        $odpowiedz = $this->actingAs($obca)->get(route('collections.own-posts'))->assertOk();

        // Kontrola dodatnia: lista obcej osoby działa i ma jej wpis…
        $odpowiedz->assertSee('Własny wpis obcej');
        // …a cudzych wpisów — nawet prywatnych i szkiców — nie ma.
        $odpowiedz->assertDontSee('Sekretny bigos autorki');
        $odpowiedz->assertDontSee('Szkic autorki');

        // Adres nie przyjmuje identyfikatora: podrzucony parametr nie
        // przełącza listy na cudzą.
        $this->actingAs($obca)
            ->get(route('collections.own-posts', ['autor' => $autor->getKey(), 'user' => $autor->getKey()]))
            ->assertOk()
            ->assertDontSee('Sekretny bigos autorki');
    }

    public function test_bez_wpisow_pusty_stan_prowadzi_do_dodawania(): void
    {
        $this->actingAs($this->user('nowa'))
            ->get(route('collections.own-posts'))
            ->assertOk()
            ->assertSee('Nie masz jeszcze żadnego wpisu')
            ->assertSee(route('add'), false);
    }

    public function test_wpis_miekko_usuniety_nie_wchodzi_na_liste(): void
    {
        $autor = $this->user('autorka');
        $this->wpis($autor, ['body' => 'Zostaje na liście']);
        $this->wpis($autor, ['body' => 'Usunięty wpis'])->delete();

        $this->actingAs($autor)->get(route('collections.own-posts'))
            ->assertSee('Zostaje na liście')
            ->assertDontSee('Usunięty wpis');
    }

    public function test_paginacja_ma_odnosnik_do_nastepnej_strony_i_nie_gubi_wpisow(): void
    {
        $autor = $this->user('autorka');
        for ($i = 0; $i < MojeWpisy::NA_STRONE + 1; $i++) {
            $this->wpis($autor, ['body' => "Wpis numer {$i}", 'published_at' => now()->subMinutes($i)]);
        }

        $pierwsza = $this->actingAs($autor)->get(route('collections.own-posts'))->assertOk();
        $this->assertCount(MojeWpisy::NA_STRONE, $pierwsza->viewData('wpisy')->items());
        $pierwsza->assertSee('Następna strona wpisów');

        $druga = $this->actingAs($autor)->get(route('collections.own-posts', ['page' => 2]))->assertOk();
        $this->assertCount(1, $druga->viewData('wpisy')->items());
        $druga->assertSee('Wpis numer '.MojeWpisy::NA_STRONE);
    }

    public function test_zeszyt_prowadzi_do_moich_wpisow(): void
    {
        $this->actingAs($this->user('autorka'))
            ->get(route('collections.index'))
            ->assertOk()
            ->assertSee(route('collections.own-posts'), false)
            ->assertSee('Moje wpisy');
    }

    private function liczbaZapytan(User $autor): int
    {
        $licznik = 0;
        DB::listen(function () use (&$licznik): void {
            $licznik++;
        });

        $this->actingAs($autor)->get(route('collections.own-posts'))->assertOk()->assertSee('Otwórz wpis');

        return $licznik;
    }

    public function test_liczba_zapytan_nie_rosnie_z_liczba_wpisow(): void
    {
        $dwa = $this->user('dwa');
        $dwanascie = $this->user('dwanascie');
        foreach ([[$dwa, 2], [$dwanascie, 12]] as [$autor, $ile]) {
            for ($i = 0; $i < $ile; $i++) {
                // Połowa to zapowiedzi przepisu (bez treści): karta czyta
                // wtedy `recipe` (tytuł, widoczność) — też musi być z góry.
                $atrybuty = ['published_at' => now()->subMinutes($i)];
                if ($i % 2 === 0) {
                    $atrybuty['body'] = null;
                    $atrybuty['recipe_id'] = Recipe::factory()->create(['author_id' => $autor->getKey()])->getKey();
                }
                $this->wpis($autor, $atrybuty);
            }
        }

        $przyDwoch = $this->liczbaZapytan($dwa);
        $przyDwunastu = $this->liczbaZapytan($dwanascie);

        $this->assertSame(
            $przyDwoch,
            $przyDwunastu,
            "Zapytania wzrosły z {$przyDwoch} (2 wpisy) do {$przyDwunastu} (12 wpisów) — karta dociąga relację osobno.",
        );
    }
}

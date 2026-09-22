<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SONDA ODBIOROWA #568 — granice okien wyników.
 *
 * Nie jest to nowa implementacja: mierzy wyłącznie zachowanie kodu
 * scalonego w PR #592 na granicach, których nie dotyka
 * `DalszeWynikiWyszukiwaniaTest` (dokładnie 200, zero wyników, brak frazy,
 * przesunięcie w niewłaściwej sekcji).
 */
class GraniceOkienWyszukiwaniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_dokladnie_dwiescie_wynikow_nie_daje_odnosnika_dalej(): void
    {
        $author = $this->user('autor_granicy_200');
        Recipe::factory()->count(200)->create([
            'author_id' => $author->id,
            'title' => 'Kalarepa pieczona',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        $response = $this->get(route('search', ['q' => 'Kalarepa', 'sekcja' => 'przepisy', 'ile' => 200]))->assertOk();

        $this->assertCount(200, $response->viewData('recipes'));
        $this->assertFalse($response->viewData('jestWiecej'));
        $this->assertSame([], $this->links((string) $response->getContent(), 'Pokaż więcej przepisów'));
        $response->assertSee('Znaleziono 200 przepisów.', false);
        $this->assertSame([], $this->links((string) $response->getContent(), 'Wróć do początku przepisów'));
    }

    public function test_dwiescie_jeden_wynikow_daje_okno_z_jednym_przepisem(): void
    {
        $author = $this->user('autor_granicy_201');
        Recipe::factory()->count(201)->create([
            'author_id' => $author->id,
            'title' => 'Kalarepa pieczona',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        $first = $this->get(route('search', ['q' => 'Kalarepa', 'sekcja' => 'przepisy', 'ile' => 200]))->assertOk();
        $this->assertTrue($first->viewData('jestWiecej'));
        $links = $this->links((string) $first->getContent(), 'Pokaż więcej przepisów');
        $this->assertCount(1, $links);

        $next = $this->get($links[0])->assertOk();
        $this->assertCount(1, $next->viewData('recipes'));
        $next->assertSee('Pokazujemy przepis 201.', false);
        $this->assertSame([], $this->links((string) $next->getContent(), 'Pokaż więcej przepisów'));
        $this->assertCount(1, $this->links((string) $next->getContent(), 'Wróć do początku przepisów'));
    }

    public function test_zero_wynikow_daje_pusty_stan_bez_odnosnikow_okien(): void
    {
        $response = $this->get(route('search', ['q' => 'Kalarepa', 'sekcja' => 'przepisy']))->assertOk();

        $response->assertSee('Nic nie znaleźliśmy', false);
        $this->assertSame([], $this->links((string) $response->getContent(), 'Pokaż więcej przepisów'));
        $this->assertSame([], $this->links((string) $response->getContent(), 'Wróć do początku przepisów'));
    }

    public function test_brak_frazy_z_przesunieciem_nie_mowi_o_zakresie(): void
    {
        $response = $this->get(route('search', ['od_przepisu' => 400, 'od_osoby' => 400]))->assertOk();

        $response->assertSee('Wpisz coś w pole powyżej', false);
        $response->assertDontSee('W tym zakresie nie ma już przepisów', false);
        $response->assertDontSee('W tym zakresie nie ma już osób', false);
        $this->assertSame([], $this->links((string) $response->getContent(), 'Wróć do początku przepisów'));
    }

    public function test_przesuniecie_z_obcej_sekcji_jest_ignorowane(): void
    {
        $author = $this->user('autor_obcej_sekcji');
        $recipe = Recipe::factory()->create(['author_id' => $author->id, 'title' => 'Kalarepa']);
        $this->user('kalarepa_osoba', ['display_name' => 'Kalarepa']);

        $ludzie = $this->get(route('search', ['q' => 'Kalarepa', 'sekcja' => 'ludzie', 'od_przepisu' => 400]))->assertOk();
        $this->assertSame(0, $ludzie->viewData('odPrzepisu'));
        $this->assertCount(1, $ludzie->viewData('people'));
        $this->assertSame([], $this->links((string) $ludzie->getContent(), 'Wróć do początku przepisów'));

        $przepisy = $this->get(route('search', ['q' => 'Kalarepa', 'sekcja' => 'przepisy', 'od_osoby' => 400]))->assertOk();
        $this->assertSame(0, $przepisy->viewData('odOsoby'));
        $this->assertSame([$recipe->id], $przepisy->viewData('recipes')->modelKeys());
        $this->assertSame([], $this->links((string) $przepisy->getContent(), 'Wróć do początku osób'));
    }

    public function test_krotka_fraza_z_przesunieciem_nie_udaje_pustego_zakresu(): void
    {
        $response = $this->get(route('search', ['q' => 'a', 'sekcja' => 'przepisy', 'od_przepisu' => 200]))->assertOk();

        $response->assertSee('jest za krótka', false);
        $response->assertDontSee('W tym zakresie nie ma już przepisów', false);
    }

    /** @return list<string> */
    private function links(string $html, string $label): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $matches = [];
        foreach ((new DOMXPath($dom))->query('//main//a') as $link) {
            if (trim($link->textContent) === $label) {
                $matches[] = $link->getAttribute('href');
            }
        }

        return $matches;
    }
}

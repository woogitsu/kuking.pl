<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Block;
use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DalszeWynikiWyszukiwaniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_przechodzi_przez_trzy_okna_przepisow_bez_powtorzen(): void
    {
        $author = $this->user('autor_dalszych_wynikow');
        $expected = Recipe::factory()->count(401)->create([
            'author_id' => $author->id,
            'title' => 'Kalarepa pieczona',
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ])->modelKeys();

        $url = route('search', ['q' => 'Kalarepa', 'sekcja' => 'przepisy', 'ile' => 200]);
        $seen = [];
        foreach ([200, 200, 1] as $index => $count) {
            $response = $this->get($url)->assertOk();
            $ids = $response->viewData('recipes')->modelKeys();
            $this->assertCount($count, $ids);
            $this->assertSame([], array_values(array_intersect($seen, $ids)), 'Kolejne okno powtarza wcześniejsze przepisy.');
            $seen = array_merge($seen, $ids);
            $links = $this->links((string) $response->getContent(), 'Pokaż więcej przepisów');
            if ($index < 2) {
                $this->assertCount(1, $links);
                $this->assertNotSame($url, $links[0], 'Odnośnik nie może prowadzić do tego samego okna.');
                $url = $links[0];
            } else {
                $this->assertSame([], $links);
            }
        }
        $this->assertEqualsCanonicalizing($expected, $seen);
    }

    public function test_link_przechodzi_od_rosnacej_listy_do_nastepnego_okna(): void
    {
        $author = $this->user('autor_granicy_okna');
        $expected = Recipe::factory()->count(201)->create([
            'author_id' => $author->id, 'title' => 'Kalarepa', 'published_at' => now()->subDay(),
        ])->modelKeys();
        $first = $this->get(route('search', ['q' => 'Kalarepa', 'sekcja' => 'przepisy', 'ile' => 180]))->assertOk();
        $this->assertCount(180, $first->viewData('recipes'));
        $links = $this->links((string) $first->getContent(), 'Pokaż więcej przepisów');
        $this->assertCount(1, $links);
        $expanded = $this->get($links[0])->assertOk();
        $this->assertCount(200, $expanded->viewData('recipes'));
        $this->assertSame($first->viewData('recipes')->modelKeys(), $expanded->viewData('recipes')->take(180)->modelKeys());
        $links = $this->links((string) $expanded->getContent(), 'Pokaż więcej przepisów');
        $this->assertCount(1, $links);
        $next = $this->get($links[0])->assertOk();
        $this->assertCount(1, $next->viewData('recipes'));
        $this->assertEqualsCanonicalizing($expected, array_merge(
            $expanded->viewData('recipes')->modelKeys(), $next->viewData('recipes')->modelKeys(),
        ));
    }

    public function test_link_pokazuje_osobe_poza_pierwszymi_dwustu(): void
    {
        $expected = [];
        for ($i = 0; $i < 201; $i++) {
            $expected[] = $this->user('rozmaryna_'.$i, ['display_name' => 'Rozmaryna'])->profile->getKey();
        }

        $recipe = Recipe::factory()->create(['title' => 'Rozmaryna']);
        $url = route('search', ['q' => 'Rozmaryna', 'sekcja' => 'wszystko', 'ile' => 200]);
        $response = $this->get($url)->assertOk();
        $first = $response->viewData('people')->modelKeys();
        $this->assertCount(200, $first);
        $links = $this->links((string) $response->getContent(), 'Pokaż więcej osób');
        $this->assertCount(1, $links);
        $this->assertNotSame($url, $links[0]);
        $next = $this->get($links[0])->assertOk();
        $last = $next->viewData('people')->modelKeys();
        $this->assertSame([$recipe->id], $next->viewData('recipes')->modelKeys());
        $this->assertCount(1, $last);
        $this->assertSame([], array_values(array_intersect($first, $last)));
        $this->assertEqualsCanonicalizing($expected, array_merge($first, $last));
        $this->assertSame([], $this->links((string) $next->getContent(), 'Pokaż więcej osób'));
        $back = $this->links((string) $next->getContent(), 'Wróć do początku osób');
        $this->assertCount(1, $back);
        $returned = $this->get($back[0])->assertOk();
        $this->assertSame($first, $returned->viewData('people')->modelKeys());
        $this->assertSame([$recipe->id], $returned->viewData('recipes')->modelKeys());
    }

    public function test_dalsze_szybkie_przepisy_zachowuja_filtr_i_prywatnosc(): void
    {
        $author = $this->user('autor_szybkich');
        $visible = Recipe::factory()->count(201)->create([
            'author_id' => $author->id, 'title' => 'Kalarepa',
            'prep_minutes' => 10, 'cook_minutes' => 20, 'visibility' => 'public',
        ]);
        foreach ([['cook_minutes' => 21], ['visibility' => 'private'], ['prep_minutes' => null]] as $change) {
            Recipe::factory()->create(array_replace([
                'author_id' => $author->id, 'title' => 'Kalarepa',
                'prep_minutes' => 10, 'cook_minutes' => 20, 'visibility' => 'public',
            ], $change));
        }
        $response = $this->get(route('search', ['q' => 'Kalarepa', 'sekcja' => 'szybkie', 'ile' => 200]))->assertOk();
        $first = $response->viewData('recipes')->modelKeys();
        $links = $this->links((string) $response->getContent(), 'Pokaż więcej przepisów');
        $this->assertCount(1, $links);
        $next = $this->get($links[0])->assertOk();
        $this->assertSame('szybkie', $next->viewData('section'));
        $this->assertCount(1, $next->viewData('recipes'));
        $this->assertEqualsCanonicalizing($visible->modelKeys(), array_merge($first, $next->viewData('recipes')->modelKeys()));
    }

    public function test_przesuniecie_przepisow_nie_przesuwa_osob_i_pozwala_wrocic(): void
    {
        $author = $this->user('autor_okien');
        Recipe::factory()->count(201)->create(['author_id' => $author->id, 'title' => 'Rozmaryna']);
        $person = $this->user('rozmaryna_osoba', ['display_name' => 'Rozmaryna']);
        $first = $this->get(route('search', ['q' => 'Rozmaryna', 'sekcja' => 'wszystko', 'ile' => 200]))->assertOk();
        $links = $this->links((string) $first->getContent(), 'Pokaż więcej przepisów');
        $this->assertCount(1, $links);
        $next = $this->get($links[0])->assertOk();
        $this->assertSame([$person->id], $next->viewData('people')->modelKeys());
        $this->assertCount(1, $next->viewData('recipes'));
        $back = $this->links((string) $next->getContent(), 'Wróć do początku przepisów');
        $this->assertCount(1, $back);
        $returned = $this->get($back[0])->assertOk();
        $this->assertSame($first->viewData('recipes')->modelKeys(), $returned->viewData('recipes')->modelKeys());
    }

    public function test_puste_dalsze_okno_pozwala_wrocic_do_istniejacych_wynikow(): void
    {
        $author = $this->user('autor_pustego_okna');
        $recipe = Recipe::factory()->create(['author_id' => $author->id, 'title' => 'Kalarepa']);
        $response = $this->get(route('search', [
            'q' => 'Kalarepa', 'sekcja' => 'przepisy', 'ile' => 200, 'od_przepisu' => 200,
        ]))->assertOk();
        $this->assertCount(0, $response->viewData('recipes'));
        $this->assertSame([], $this->links((string) $response->getContent(), 'Pokaż więcej przepisów'));
        $back = $this->links((string) $response->getContent(), 'Wróć do początku przepisów');
        $this->assertCount(1, $back);
        $returned = $this->get($back[0])->assertOk();
        $this->assertSame([$recipe->id], $returned->viewData('recipes')->modelKeys());
    }

    public function test_powrot_osob_zachowuje_dalsze_okno_przepisow(): void
    {
        $author = $this->user('autor_dwoch_okien');
        Recipe::factory()->count(21)->create(['author_id' => $author->id, 'title' => 'Rozmaryna']);
        for ($i = 0; $i < 21; $i++) {
            $this->user('rozmaryna_dwa_'.$i, ['display_name' => 'Rozmaryna']);
        }
        $response = $this->get(route('search', [
            'q' => 'Rozmaryna', 'ile' => 20, 'od_przepisu' => 20, 'od_osoby' => 20,
        ]))->assertOk();
        $this->assertCount(1, $response->viewData('recipes'));
        $this->assertCount(1, $response->viewData('people'));
        $back = $this->links((string) $response->getContent(), 'Wróć do początku osób');
        $this->assertCount(1, $back);
        $returned = $this->get($back[0])->assertOk();
        $this->assertSame($response->viewData('recipes')->modelKeys(), $returned->viewData('recipes')->modelKeys());
        $this->assertCount(20, $returned->viewData('people'));
        $this->assertSame([], array_intersect($response->viewData('people')->modelKeys(), $returned->viewData('people')->modelKeys()));
    }

    public function test_dalsze_osoby_pomijaja_blokady_w_obie_strony(): void
    {
        $viewer = $this->user('widz_osob');
        $expected = [];
        for ($i = 0; $i < 21; $i++) {
            $expected[] = $this->user('rozmaryna_jawna_'.$i, ['display_name' => 'Rozmaryna'])->id;
        }
        foreach ([true, false] as $outgoing) {
            $person = $this->user($outgoing ? 'rozmaryna_blokowana' : 'rozmaryna_blokujaca', ['display_name' => 'Rozmaryna']);
            Block::create([
                'blocker_id' => $outgoing ? $viewer->id : $person->id,
                'blocked_id' => $outgoing ? $person->id : $viewer->id,
            ]);
        }
        $this->actingAs($viewer);
        $first = $this->get(route('search', ['q' => 'Rozmaryna', 'sekcja' => 'ludzie']))->assertOk();
        $next = $this->get(route('search', ['q' => 'Rozmaryna', 'sekcja' => 'ludzie', 'od_osoby' => 20]))->assertOk();
        $this->assertCount(1, $next->viewData('people'));
        $this->assertEqualsCanonicalizing($expected, array_merge(
            $first->viewData('people')->modelKeys(), $next->viewData('people')->modelKeys(),
        ));
    }

    public function test_blokada_w_obie_strony_jest_stosowana_przed_przesunieciem(): void
    {
        $viewer = $this->user('szukajacy_blokady');
        $author = $this->user('widoczny_autor');
        $visible = Recipe::factory()->count(21)->create(['author_id' => $author->id, 'title' => 'Kalarepa']);
        foreach ([true, false] as $outgoing) {
            $blocked = $this->user($outgoing ? 'blokowany_autor' : 'blokujacy_autor');
            Block::create([
                'blocker_id' => $outgoing ? $viewer->id : $blocked->id,
                'blocked_id' => $outgoing ? $blocked->id : $viewer->id,
            ]);
            Recipe::factory()->count(21)->create(['author_id' => $blocked->id, 'title' => 'Kalarepa']);
        }
        $this->actingAs($viewer);
        $first = $this->get(route('search', ['q' => 'Kalarepa', 'sekcja' => 'przepisy', 'ile' => 20]))->assertOk();
        $next = $this->get(route('search', [
            'q' => 'Kalarepa', 'sekcja' => 'przepisy', 'ile' => 20, 'od_przepisu' => 20,
        ]))->assertOk();
        $this->assertCount(1, $next->viewData('recipes'));
        $this->assertEqualsCanonicalizing($visible->modelKeys(), array_merge(
            $first->viewData('recipes')->modelKeys(), $next->viewData('recipes')->modelKeys(),
        ));
    }

    public function test_nieprawidlowe_przesuniecia_nie_powoduja_bledu_ani_utraty_pierwszych_wynikow(): void
    {
        $author = $this->user('autor_parametrow');
        $recipe = Recipe::factory()->create(['author_id' => $author->id, 'title' => 'Kalarepa']);
        foreach ([-1, 'abc', '1.5', (string) PHP_INT_MAX, ['200']] as $offset) {
            $response = $this->get(route('search', [
                'q' => 'Kalarepa', 'sekcja' => 'przepisy', 'ile' => 200, 'od_przepisu' => $offset,
            ]))->assertOk();
            $this->assertSame([$recipe->id], $response->viewData('recipes')->modelKeys());
            $this->assertSame([], $this->links((string) $response->getContent(), 'Wróć do początku przepisów'));
        }
    }

    /**
     * Issue #738 — `q` MUSI być tekstem, zanim trafi do `trim((string) ...)`.
     * `/szukaj?q[]=pierogi` (i zagnieżdżona wersja `q[nazwa]=pierogi`) dają
     * tablicę: bez straży typu PHP rzuca ostrzeżeniem „Array to string
     * conversion", które w tym repo staje się wyjątkiem i kończy się 500 na
     * publicznym, niezalogowanym endpoincie. Kontrola ujemna: przywrócenie
     * gołego `trim((string) $request->query('q', ''))` sprawia, że ten test
     * oblewa odpowiedzią 500 zamiast 200.
     */
    public function test_tablicowe_q_nie_daje_bledu_500_i_nie_uruchamia_wyszukiwania(): void
    {
        foreach ([
            ['q' => ['pierogi']],
            ['q' => ['nazwa' => 'pierogi']],
            ['q' => [['pierogi']]],
        ] as $parametry) {
            $response = $this->get(route('search').'?'.http_build_query($parametry))->assertOk();
            // Tablicowe q jest równoważne brakowi q — pusty ekran „Szukaj",
            // nie fikcyjna fraza „Array" i żadne wyszukiwanie w bazie.
            $this->assertSame('', $response->viewData('phrase'));
            $this->assertFalse($response->viewData('zaKrotka'));
        }
    }

    /**
     * Kontrola dodatnia do testu wyżej: zwykła fraza (w tym z polskim znakiem)
     * nadal działa normalnie i nie jest myląco traktowana jak tablica.
     */
    public function test_zwykla_fraza_i_polskie_znaki_w_q_dzialaja_normalnie(): void
    {
        $author = $this->user('autor_q_tekstowego');
        $recipe = Recipe::factory()->create(['author_id' => $author->id, 'title' => 'Żurek']);

        foreach (['Żurek', 'zurek'] as $fraza) {
            $response = $this->get(route('search', ['q' => $fraza]))->assertOk();
            $this->assertSame($fraza, $response->viewData('phrase'));
            $this->assertSame([$recipe->id], $response->viewData('recipes')->modelKeys());
        }
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

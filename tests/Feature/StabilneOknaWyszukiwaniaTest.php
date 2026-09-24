<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Issue #1023 — dalsze okno wyników (po 200) nie może pomijać ani powtarzać
 * rekordów, gdy ranking zmienił się MIĘDZY kliknięciami.
 *
 * Każdy test przechodzi rzeczywistym odnośnikiem „Pokaż więcej" z pierwszego
 * pełnego okna, a zmianę danych robi dopiero PO narysowaniu tego okna —
 * dokładnie tak, jak dzieje się to u człowieka, który przegląda długą listę,
 * a ktoś inny w tym czasie publikuje, ukrywa albo zmienia tytuł.
 *
 * KONTROLA UJEMNA: przy liczbowym `OFFSET` (bez kursora `po_*`) test
 * dopisania widzi duplikat, a test ukrycia — pominięcie. Wpis mutacji
 * w `scripts/kontrole-negatywne-alfa08.py`.
 */
class StabilneOknaWyszukiwaniaTest extends TestCase
{
    use RefreshDatabase;

    public function test_nowe_wysokie_trafienie_miedzy_oknami_nie_powtarza_przepisu(): void
    {
        $author = $this->user('autor_stabilnych_okien');
        $expected = $this->przepisy($author, 201);

        [$first, $link] = $this->pierwszeOkno('przepisy', 'recipes', 'Pokaż więcej przepisów');
        $this->assertStringContainsString('po_przepisie=', $link);
        $this->assertStringNotContainsString('po_osobie=', $link);

        // Dokładny tytuł wchodzi na pozycję 1 — nad granicą okna.
        $nowy = Recipe::factory()->create([
            'author_id' => $author->id, 'title' => 'Kalarepa',
            'visibility' => 'public', 'published_at' => now(),
        ]);
        $this->assertSame($nowy->id, $this->get(route('search', ['q' => 'Kalarepa', 'sekcja' => 'przepisy']))
            ->viewData('recipes')->first()->id, 'Nowe trafienie musi naprawdę stać nad granicą okna.');

        $next = $this->get($link)->assertOk();
        $ids = $next->viewData('recipes')->modelKeys();
        $this->assertSame([], array_values(array_intersect($first, $ids)), 'Dalsze okno powtórzyło już pokazany przepis.');
        $this->assertEqualsCanonicalizing($expected, array_merge($first, $ids));
        $next->assertSee('Pokazujemy przepis 201.', false);
    }

    public function test_ukrycie_i_zmiana_rankingu_wczesniejszych_nie_pomija_przepisu(): void
    {
        $author = $this->user('autor_znikajacych');
        $expected = $this->przepisy($author, 202);

        [$first, $link] = $this->pierwszeOkno('przepisy', 'recipes', 'Pokaż więcej przepisów');

        // Jeden wcześniejszy wynik znika z wyszukiwarki, drugi awansuje
        // na samą górę. Oba stały PRZED granicą okna.
        Recipe::query()->whereKey($first[10])->update(['visibility' => 'private']);
        Recipe::query()->whereKey($first[150])->update(['title' => 'Kalarepa']);

        $next = $this->get($link)->assertOk();
        $ids = $next->viewData('recipes')->modelKeys();
        $this->assertEqualsCanonicalizing(array_values(array_diff($expected, $first)), $ids,
            'Dalsze okno pominęło przepis, który nie był jeszcze pokazany.');
    }

    public function test_osoby_maja_wlasny_kursor_i_nie_gubia_ani_nie_powtarzaja(): void
    {
        $expected = [];
        for ($i = 0; $i < 202; $i++) {
            $expected[] = $this->user('rozmaryna_st_'.$i, ['display_name' => 'Rozmaryna z ogrodu '.$i])->id;
        }

        [$first, $link] = $this->pierwszeOkno('ludzie', 'people', 'Pokaż więcej osób', 'Rozmaryna');
        $this->assertStringContainsString('po_osobie=', $link);
        $this->assertStringNotContainsString('po_przepisie=', $link);

        // Dwie nowe osoby z dokładną nazwą wchodzą na górę, jedna z pokazanych
        // traci konto — wszystkie zmiany nad granicą okna. Przesunięcie netto
        // to +1: przy równej liczbie dopisanych i zniknięć `OFFSET` przypadkiem
        // trafiłby w dobre miejsce i test niczego by nie dowodził.
        $nowa = $this->user('rozmaryna_nowa', ['display_name' => 'Rozmaryna']);
        $this->user('rozmaryna_nowa_druga', ['display_name' => 'Rozmaryna']);
        User::query()->whereKey($first[5])->update(['status' => User::STATUS_SUSPENDED]);
        $this->assertContains($nowa->id, $this->get(route('search', ['q' => 'Rozmaryna', 'sekcja' => 'ludzie']))
            ->viewData('people')->take(2)->modelKeys(), 'Nowe osoby muszą naprawdę stać nad granicą okna.');

        $next = $this->get($link)->assertOk();
        $ids = $next->viewData('people')->modelKeys();
        $this->assertSame([], array_values(array_intersect($first, $ids)), 'Dalsze okno powtórzyło osobę.');
        $this->assertEqualsCanonicalizing(array_values(array_diff($expected, $first)), $ids, 'Dalsze okno pominęło osobę.');
    }

    public function test_remisy_rozstrzygaja_sie_tak_samo_jak_w_jednym_zapytaniu(): void
    {
        // Ten sam tytuł i ta sama chwila publikacji: o kolejności decyduje
        // wyłącznie `id`. Suma dwóch okien musi być DOKŁADNIE listą z jednego
        // zapytania — w tej samej kolejności, nie tylko tym samym zbiorem.
        $author = $this->user('autor_remisow');
        $chwila = now()->subHour();
        Recipe::factory()->count(205)->create([
            'author_id' => $author->id, 'title' => 'Kalarepa pieczona',
            'visibility' => 'public', 'published_at' => $chwila,
        ]);
        $wzor = Recipe::query()->orderBy('id')->pluck('id')->all();

        [$first, $link] = $this->pierwszeOkno('przepisy', 'recipes', 'Pokaż więcej przepisów');
        $next = $this->get($link)->assertOk();

        $this->assertSame($wzor, array_merge($first, $next->viewData('recipes')->modelKeys()));
    }

    public function test_zly_kursor_nie_daje_bledu_i_wraca_do_liczbowego_okna(): void
    {
        $author = $this->user('autor_zlego_kursora');
        $this->przepisy($author, 201);
        $pelne = $this->get(route('search', ['q' => 'Kalarepa', 'sekcja' => 'przepisy', 'ile' => 200]))
            ->viewData('recipes')->modelKeys();

        foreach (['abc', '2_0_0_x', '0.5_0.5_1_00000000-0000-0000-0000-00000000000g', '9_9_1_'.$pelne[0], ['x']] as $zly) {
            $response = $this->get(route('search', [
                'q' => 'Kalarepa', 'sekcja' => 'przepisy', 'ile' => 200, 'od_przepisu' => 200, 'po_przepisie' => $zly,
            ]))->assertOk();
            $this->assertCount(1, $response->viewData('recipes'));
        }
    }

    public function test_kursor_za_koncem_wynikow_zostawia_droge_powrotu(): void
    {
        $author = $this->user('autor_konca_kursora');
        $this->przepisy($author, 201);

        [, $link] = $this->pierwszeOkno('przepisy', 'recipes', 'Pokaż więcej przepisów');
        Recipe::query()->where('title', 'like', 'Kalarepa%')->update(['visibility' => 'private']);

        $response = $this->get($link)->assertOk();
        $this->assertCount(0, $response->viewData('recipes'));
        $response->assertSee('W tym zakresie nie ma już przepisów', false);
        $back = $this->links((string) $response->getContent(), 'Wróć do początku przepisów');
        $this->assertCount(1, $back);
        $this->assertStringNotContainsString('po_przepisie=', $back[0]);
    }

    /**
     * Przepisy o RÓŻNYCH miarach i RÓŻNYCH chwilach publikacji (z mikrosekundami),
     * żeby kursor sprawdzał każde piętro porządku, nie tylko `id`.
     *
     * @return list<string>
     */
    private function przepisy(User $author, int $ile): array
    {
        $ids = [];
        for ($i = 0; $i < $ile; $i++) {
            $ids[] = Recipe::factory()->create([
                'author_id' => $author->id,
                'title' => $i % 3 === 0 ? 'Kalarepa pieczona' : 'Kalarepa pieczona z masłem i koperkiem',
                'visibility' => 'public',
                'published_at' => now()->subDay()->subMinutes($i % 7)->addMicroseconds(137 * $i),
            ])->id;
        }

        return $ids;
    }

    /** @return array{0: list<string>, 1: string} klucze pierwszego okna i odnośnik dalej */
    private function pierwszeOkno(string $sekcja, string $klucz, string $etykieta, string $q = 'Kalarepa'): array
    {
        $response = $this->get(route('search', ['q' => $q, 'sekcja' => $sekcja, 'ile' => 200]))->assertOk();
        $first = $response->viewData($klucz)->modelKeys();
        $this->assertCount(200, $first);
        $links = $this->links((string) $response->getContent(), $etykieta);
        $this->assertCount(1, $links);

        return [$first, $links[0]];
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
                $matches[] = html_entity_decode($link->getAttribute('href'));
            }
        }

        return $matches;
    }
}

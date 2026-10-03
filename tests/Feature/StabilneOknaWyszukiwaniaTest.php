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
        $this->user('kalarepa_osoba', ['display_name' => 'Kalarepa']);

        // „Wszystko” trzyma dwa niezależne rozmiary (#984). Kursor przepisu
        // nie może przy okazji powiększyć ani przesunąć listy osób.
        [$first, $link] = $this->pierwszeOkno('wszystko', 'recipes', 'Pokaż więcej przepisów', 'Kalarepa',
            ['ile_przepisow' => 200, 'ile_osob' => 20]);
        $this->assertStringContainsString('po_przepisie=', $link);
        $this->assertStringNotContainsString('po_osobie=', $link);
        $this->assertStringContainsString('ile_przepisow=200', $link);
        $this->assertStringContainsString('ile_osob=20', $link);

        // Dokładny tytuł wchodzi na pozycję 1 — nad granicą okna.
        $nowy = Recipe::factory()->create([
            'author_id' => $author->id, 'title' => 'Kalarepa',
            'visibility' => 'public', 'published_at' => now(),
        ]);
        $this->assertSame($nowy->id, $this->get(route('search', ['q' => 'Kalarepa', 'sekcja' => 'przepisy']))
            ->viewData('recipes')->first()->id, 'Nowe trafienie musi naprawdę stać nad granicą okna.');

        $next = $this->get($link)->assertOk();
        $ids = $next->viewData('recipes')->modelKeys();
        $this->assertCount(1, $next->viewData('people'));
        $this->assertSame([], array_values(array_intersect($first, $ids)), 'Dalsze okno powtórzyło już pokazany przepis.');
        $this->assertEqualsCanonicalizing($expected, array_merge($first, $ids));
        $next->assertSee('Pokazujemy przepis 201.', false);
    }

    public function test_kursor_zachowuje_zakres_ceny_w_dalszym_oknie(): void
    {
        $author = $this->user('autor_tanich_okien');
        $tanie = $this->przepisy($author, 201);
        Recipe::query()->whereKey($tanie)->update(['estimated_cost_pln' => 15]);
        $drogi = Recipe::factory()->create([
            'author_id' => $author->id,
            'title' => 'Kalarepa',
            'estimated_cost_pln' => 30,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        [$first, $link] = $this->pierwszeOkno('tanie', 'recipes', 'Pokaż więcej przepisów');
        $this->assertStringContainsString('sekcja=tanie', $link);
        $this->assertStringContainsString('po_przepisie=', $link);
        $this->assertNotContains($drogi->id, $first);

        $next = $this->get($link)->assertOk()->viewData('recipes')->modelKeys();
        $this->assertEqualsCanonicalizing($tanie, array_merge($first, $next));
        $this->assertNotContains($drogi->id, $next);
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

    public function test_miary_kursora_odrzucone_przez_postgresql_real_wracaja_do_wlasciwego_okna_obu_list(): void
    {
        $author = $this->user('autor_kursora_real');
        $this->przepisy($author, 201);
        for ($i = 0; $i < 201; $i++) {
            $this->user('osoba_kursora_real_'.$i, ['display_name' => 'Rozmaryna z ogrodu '.$i]);
        }

        $recipeUrl = ['q' => 'Kalarepa', 'sekcja' => 'przepisy', 'ile' => 200, 'od_przepisu' => 200];
        $peopleUrl = ['q' => 'Rozmaryna', 'sekcja' => 'ludzie', 'ile' => 200, 'od_osoby' => 200];
        $recipeExpected = $this->get(route('search', $recipeUrl))->assertOk()->viewData('recipes')->modelKeys();
        $peopleExpected = $this->get(route('search', $peopleUrl))->assertOk()->viewData('people')->modelKeys();
        $this->assertCount(1, $recipeExpected);
        $this->assertCount(1, $peopleExpected);

        foreach (['1e-99', '1e-999', '7e-46'] as $tooSmall) {
            foreach ([$tooSmall.'_0', '0_'.$tooSmall] as $metrics) {
                $response = $this->get(route('search', $recipeUrl + [
                    'po_przepisie' => $metrics.'_0_'.$recipeExpected[0],
                ]));
                $this->assertSame(200, $response->getStatusCode(), 'KURSOR_REAL_2856_OKNO_PRZEPISOW');
                $result = $response->viewData('recipes')->modelKeys();
                $this->assertSame($recipeExpected, $result, 'KURSOR_REAL_2856_OKNO_PRZEPISOW');
            }

            $response = $this->get(route('search', $peopleUrl + [
                'po_osobie' => $tooSmall.'_'.$peopleExpected[0],
            ]));
            $this->assertSame(200, $response->getStatusCode(), 'KURSOR_REAL_2856_OKNO_OSOB');
            $result = $response->viewData('people')->modelKeys();
            $this->assertSame($peopleExpected, $result, 'KURSOR_REAL_2856_OKNO_OSOB');
        }

        // Prawidłowe dla PostgreSQL 18 wartości `real`, w tym zero i dolna
        // reprezentowalna podnormalna, nadal są kursorem (a nie `offset`).
        foreach (['0', '1e-45', '1.4e-45', '1.17549435e-38'] as $valid) {
            foreach ([$valid.'_0', '0_'.$valid] as $metrics) {
                $result = $this->get(route('search', $recipeUrl + [
                    'po_przepisie' => $metrics.'_0_'.$recipeExpected[0],
                ]))->assertOk()->viewData('recipes')->modelKeys();
                $this->assertSame([], $result, 'KURSOR_REAL_2856_PRAWIDLOWA_MIARA_PRZEPISU');
            }

            $result = $this->get(route('search', $peopleUrl + [
                'po_osobie' => $valid.'_'.$peopleExpected[0],
            ]))->assertOk()->viewData('people')->modelKeys();
            $this->assertSame([], $result, 'KURSOR_REAL_2856_PRAWIDLOWA_MIARA_OSOBY');
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

    /**
     * @param  array<string, int>  $okna
     * @return array{0: list<string>, 1: string} klucze pierwszego okna i odnośnik dalej
     */
    private function pierwszeOkno(string $sekcja, string $klucz, string $etykieta, string $q = 'Kalarepa', array $okna = ['ile' => 200]): array
    {
        $response = $this->get(route('search', ['q' => $q, 'sekcja' => $sekcja] + $okna))->assertOk();
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
                $matches[] = html_entity_decode(self::elementDom($link)->getAttribute('href'));
            }
        }

        return $matches;
    }
}

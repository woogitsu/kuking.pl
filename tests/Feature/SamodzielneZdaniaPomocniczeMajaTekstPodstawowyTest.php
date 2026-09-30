<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * UX-02 z audytu 30.09.2026 (#2287): instrukcja ekranu i pusty stan w `.meta`
 * miały 16 px, choć stoją SAME — `docs/design/DESIGN_SYSTEM.md` §2.1
 * dopuszcza 16 px tylko obok tekstu głównego ≥ 18 px (AGENTS.md §5: tekst
 * podstawowy minimum 18 px). Takie zdania dostają `.meta-samodzielne`,
 * czyli `--text-body`.
 *
 * Test sprawdza dwie rzeczy: że każde z tych zdań na wyrenderowanym ekranie
 * niesie klasę, i że klasa w arkuszu daje tekst podstawowy. Pomiar
 * `getComputedStyle` w przeglądarce opisuje raport audytu; tu pilnujemy,
 * żeby klasa nie zniknęła z widoku ani z arkusza.
 */
final class SamodzielneZdaniaPomocniczeMajaTekstPodstawowyTest extends TestCase
{
    use RefreshDatabase;

    public function test_instrukcje_i_puste_stany_niosa_klase_tekstu_podstawowego(): void
    {
        $ja = $this->user('hania');
        $this->actingAs($ja);

        $this->zdanieMaKlase($this->get(route('planer.show')), 'Przy każdym dniu wyszukasz przepis');
        $this->zdanieMaKlase($this->get(route('planer.show')), 'Nic jeszcze nie zaplanowane.');
        $this->zdanieMaKlase($this->get(route('search')), 'Wpisz coś w pole powyżej');
        $this->zdanieMaKlase($this->get(route('settings.privacy')), 'Nikogo nie blokujesz.');
        $this->zdanieMaKlase($this->get(route('settings.hidden')), 'Nie ukrywasz nikogo.');
        $this->zdanieMaKlase($this->get(route('settings.hidden')), 'Nie ukrywasz żadnego wpisu.');

        $przepis = Recipe::factory()->create();
        RecipeVersion::create([
            'recipe_id' => $przepis->getKey(),
            'editor_id' => $przepis->author_id,
            'version_number' => 1,
            'change_note' => 'Pierwsza publikacja',
            'snapshot' => ['title' => $przepis->title, 'ingredients' => [], 'steps' => []],
        ]);
        $this->zdanieMaKlase($this->get(route('recipes.history', $przepis->slug)), 'Tu są zapisane wersje przepisu');

        $wpis = Post::factory()->create(['author_id' => $ja->getKey(), 'visibility' => 'public', 'body' => 'Rosół na niedzielę']);
        $this->zdanieMaKlase($this->get($wpis->url()), 'Jeszcze nikt tu nic nie napisał.');
    }

    public function test_klasa_w_arkuszu_daje_tekst_podstawowy_18_px(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));
        $tokeny = (string) file_get_contents(resource_path('css/tokens.css'));

        $this->assertMatchesRegularExpression(
            '/\.meta-samodzielne\s*\{[^}]*font-size:\s*var\(--text-body\);/',
            $css,
            'Samodzielne zdanie pomocnicze nie ma rozmiaru tekstu podstawowego.',
        );
        // Kolejność: reguła po `.meta`, inaczej 16 px z `.meta` wygrywa przy tej samej wadze.
        $this->assertGreaterThan(
            (int) strpos($css, "  .meta {\n"),
            (int) strpos($css, '  .meta-samodzielne {'),
            'Reguła .meta-samodzielne stoi przed .meta i przegrywa kaskadę.',
        );
        $this->assertMatchesRegularExpression('/--text-body:\s*calc\(1\.125rem\b/', $tokeny, '--text-body przestał mieć 18 px.');
    }

    private function zdanieMaKlase(TestResponse $odpowiedz, string $poczatek): void
    {
        $html = (string) $odpowiedz->assertOk()->getContent();
        $wzorzec = '/<p class="([^"]*)">\s*'.preg_quote($poczatek, '/').'/u';

        $this->assertMatchesRegularExpression($wzorzec, $html, 'Nie ma zdania „'.$poczatek.'" w akapicie.');
        preg_match($wzorzec, $html, $m);
        $this->assertContains('meta-samodzielne', explode(' ', $m[1]), 'Zdanie „'.$poczatek.'" stoi samo, a ma 16 px zamiast tekstu podstawowego.');
    }
}

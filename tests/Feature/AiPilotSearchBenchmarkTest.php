<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Domain\Social\Actions\BlockUser;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kuking\AiPilots\Pilot;
use Tests\TestCase;

require_once __DIR__.'/../../scripts/ai-pilots/Pilot.php';

/** Pomiar SQL na jawnych fixture. Nie jest pomiarem ruchu ani jakości produkcji. */
class AiPilotSearchBenchmarkTest extends TestCase
{
    use RefreshDatabase;

    public function test_mierzy_zwykle_wyszukiwanie_i_proste_reguly_na_tym_samym_zbiorze(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $author = $this->user('pilot_autor');
        $viewer = $this->user('pilot_widz');
        $names = ['Pierogi ruskie', 'Żurek', 'Sernik', 'Rosół', 'Zupa pomidorowa', 'Zupa z dyni',
            'Naleśniki', 'Placki ziemniaczane', 'Ziemniaki z piekarnika', 'Cukinia', 'Bigos',
            'Sałatka jarzynowa', 'Gołąbki', 'Ciasto z kaszą manną', 'Marlenka', 'Makowiec',
            'Rolada kawowa', 'Chleb na zakwasie', 'Kluski śląskie', 'Kopytka', 'Racuchy z jabłkami',
            'Szarlotka', 'Ogórki kiszone', 'Leczo', 'Krupnik', 'Zupa jarzynowa', 'Ryż z jabłkami',
            'Kluski leniwe', 'Modra kapusta', 'Wodzionka', 'Kapuśniak', 'Grochówka', 'Pasztet',
            'Jajecznica', 'Makaron'];
        foreach ($names as $index => $title) {
            foreach ([20, 60, null] as $time) {
                $recipe = Recipe::factory()->create([
                    'author_id' => $author->id, 'title' => $title, 'summary' => null,
                    'slug' => Str::slug($title).'-'.($time ?? 'brak'),
                    'prep_minutes' => $time === null ? null : 5,
                    'cook_minutes' => $time === null ? null : $time - 5,
                    'published_at' => now()->subDays($index),
                ]);
                RecipeIngredient::create(['recipe_id' => $recipe->id, 'position' => 0, 'ingredient_text' => $title]);
            }
        }
        foreach (['basia', 'marek', 'halina', 'ewa_kuchnia', 'zofia', 'janek', 'anna'] as $name) {
            $person = $this->user($name, ['display_name' => ucfirst($name)]);
            $person->profile->update(['speciality' => $name === 'halina' ? 'kiszonki chleb' : null]);
        }
        $cases = json_decode(file_get_contents(base_path('scripts/ai-pilots/corpus/search.json')), true, 64, JSON_THROW_ON_ERROR);
        $this->assertCount(100, $cases);
        $this->assertSame(105, Recipe::count());
        $search = app(SearchQuery::class);
        $records = [];
        $queryCount = 0;
        DB::listen(function () use (&$queryCount): void {
            $queryCount++;
        });
        foreach ($cases as $case) {
            $variants = [
                'A' => ['q' => $case['text'], 'sekcja' => 'wszystko', 'status' => 'ok'],
                'B' => Pilot::baseline($case['text']),
            ];
            // Opcjonalny odczyt ZAPISANYCH odpowiedzi; nigdy HTTP w tym teście.
            $livePath = getenv('KUKING_AI_SEARCH_RESULTS');
            if (is_string($livePath) && is_file($livePath)) {
                foreach (file($livePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $live = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                    if ($live['id'] === $case['id'] && $live['proposal'] !== null) {
                        $variants['C'.$live['round']] = Pilot::validate('search', $case['text'], $live['proposal']);
                    }
                }
            }
            foreach ($variants as $variant => $parameters) {
                $start = hrtime(true);
                $before = $queryCount;
                $recipes = collect();
                $people = collect();
                if ($parameters['status'] === 'ok') {
                    if ($parameters['sekcja'] !== 'ludzie') {
                        $recipes = $search->recipes($parameters['q'], $viewer, 5, $parameters['sekcja'] === 'szybkie' ? 30 : null, 0);
                    }
                    if (in_array($parameters['sekcja'], ['wszystko', 'ludzie'], true)) {
                        $people = $search->people($parameters['q'], $viewer, 5, 0);
                    }
                }
                $records[] = ['id' => $case['id'], 'variant' => $variant, 'parameters' => $parameters,
                    'latency_ms' => round((hrtime(true) - $start) / 1000000, 3), 'sql_queries' => $queryCount - $before,
                    'recipes' => $recipes->map(fn ($r) => ['slug' => $r->slug, 'title' => $r->title, 'minutes' => $r->prep_minutes === null ? null : $r->prep_minutes + $r->cook_minutes])->all(),
                    'people' => $people->pluck('username')->all()];
            }
        }
        $this->assertGreaterThanOrEqual(200, count($records));
        $this->assertNotEmpty($records[0]['recipes'], 'Kontrola dodatnia: pierogi muszą istnieć i być znalezione.');
        $output = getenv('KUKING_AI_SQL_OUTPUT');
        if (is_string($output) && $output !== '') {
            $this->assertNotFalse(file_put_contents($output, json_encode($records, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)));
        }
    }

    public function test_zatwierdzone_parametry_nie_omijaja_widocznosci_i_blokad(): void
    {
        $viewer = $this->user('widz_pilota');
        $visible = $this->user('widoczny_pilota');
        $hidden = $this->user('ukryty_pilota');
        $normal = Recipe::factory()->create(['author_id' => $visible->id, 'title' => 'Bigos publiczny', 'prep_minutes' => 5, 'cook_minutes' => 10]);
        Recipe::factory()->create(['author_id' => $visible->id, 'title' => 'Bigos prywatny', 'visibility' => 'private', 'prep_minutes' => 5, 'cook_minutes' => 10]);
        Recipe::factory()->draft()->create(['author_id' => $visible->id, 'title' => 'Bigos szkic', 'prep_minutes' => 5, 'cook_minutes' => 10]);
        Recipe::factory()->create(['author_id' => $hidden->id, 'title' => 'Bigos blokada', 'prep_minutes' => 5, 'cook_minutes' => 10]);
        $proposal = Pilot::validate('search', 'szybki bigos', ['status' => 'ok', 'q' => 'bigos', 'sekcja' => 'szybkie']);
        app(BlockUser::class)->handle($viewer, $hidden);
        $this->assertSame([$normal->id], app(SearchQuery::class)->recipes($proposal['q'], $viewer, 5, 30)->modelKeys());
    }
}

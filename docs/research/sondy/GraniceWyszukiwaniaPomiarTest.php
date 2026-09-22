<?php

declare(strict_types=1);

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Sonda opisowa #885/#886, poza zestawem regresyjnym: nie wybiera polityki długości. */
final class GraniceWyszukiwaniaPomiarTest extends TestCase
{
    use RefreshDatabase;

    public function test_zapisz_obserwacje_http_i_sql(): void
    {
        $this->assertSame('127.0.0.1', config('database.connections.pgsql.host'));
        $this->assertSame('55439', (string) config('database.connections.pgsql.port'));
        $this->assertSame('kuking_flota_gpt-wyszukiwanie-granice', config('database.connections.pgsql.database'));
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $basia->profile->update(['speciality' => 'zupy']);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, ' LIKE ?')) {
                $queries[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });
        foreach (['basia', '@basia', ' @basia '] as $phrase) {
            $queries = [];
            $response = $this->get(route('search', ['q' => $phrase, 'sekcja' => 'ludzie']));
            $response->assertOk();
            fwrite(STDOUT, json_encode(['case' => 'prefix', 'q' => $phrase, 'usernames' => $response->viewData('people')->pluck('username'), 'sql' => $queries], JSON_UNESCAPED_UNICODE).PHP_EOL);
        }
        foreach (['a', 'ż'] as $letter) {
            foreach ([119, 120, 121, 150] as $length) {
                $phrase = str_repeat($letter, $length - 1).'Z';
                $queries = [];
                $response = $this->get(route('search', ['q' => $phrase]));
                $response->assertOk();
                $dom = new DOMDocument;
                @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
                $input = (new DOMXPath($dom))->query('//input[@name="q"]')->item(0);
                fwrite(STDOUT, json_encode(['case' => 'length', 'letter' => $letter, 'input_length' => $length, 'visible_length' => mb_strlen($input->getAttribute('value')), 'sql_needles' => array_map(fn ($q) => ['length' => mb_strlen(trim(array_values(array_filter($q['bindings'], fn ($binding) => is_string($binding) && str_starts_with($binding, '%')))[0], '%')), 'last' => mb_substr(trim(array_values(array_filter($q['bindings'], fn ($binding) => is_string($binding) && str_starts_with($binding, '%')))[0], '%'), -1)], $queries)], JSON_UNESCAPED_UNICODE).PHP_EOL);
            }
        }
        $prefix = str_repeat('ż', 120);
        $recipe = Recipe::factory()->create(['author_id' => $basia->getKey(), 'title' => 'Sonda granicy', 'summary' => $prefix]);
        $response = $this->get(route('search', ['q' => $prefix.'XYZ', 'sekcja' => 'przepisy']));
        fwrite(STDOUT, json_encode(['case' => 'omitted_suffix', 'returned_fixture' => $response->viewData('recipes')->contains('id', $recipe->id), 'full_phrase_present_in_summary' => str_contains($recipe->summary, $prefix.'XYZ')], JSON_UNESCAPED_UNICODE).PHP_EOL);
        fwrite(STDOUT, json_encode(['case' => 'schema', 'columns' => DB::select("SELECT table_name, column_name, data_type, character_maximum_length FROM information_schema.columns WHERE table_schema = 'public' AND column_name LIKE '%_search'"), 'long_sql' => DB::selectOne('SELECT length(kuking_normalize(?)) AS normalized_length, similarity(?, ?) AS similarity', [str_repeat('ż', 500), str_repeat('z', 500), str_repeat('z', 500)])], JSON_UNESCAPED_UNICODE).PHP_EOL);
    }
}

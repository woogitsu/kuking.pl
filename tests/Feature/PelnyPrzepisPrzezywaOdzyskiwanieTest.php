<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\RecipeController;
use App\Models\Recipe;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PelnyPrzepisPrzezywaOdzyskiwanieTest extends TestCase
{
    use RefreshDatabase;

    public static function statusy(): array
    {
        return [[419], [429]];
    }

    #[DataProvider('statusy')]
    public function test_maksymalny_formularz_szczegolow_miesci_sie_po_escapowaniu(int $status): void
    {
        $this->actingAs($this->user('maksimum524'));
        $dane = [
            'title' => 'Z'.str_repeat('"', 179), 'visibility' => 'public',
            'summary' => str_repeat('"', 2000), 'source_person' => str_repeat('"', 120),
            'source_note' => str_repeat('"', 2000), 'source_url' => 'https://example.com/'.str_repeat('a', 1980),
            'servings' => '999', 'prep_minutes' => '10080', 'cook_minutes' => '10080',
            'difficulty' => 'medium', 'source_type' => 'own', 'family_since_year' => '2000',
            'ingredients' => array_fill(0, 120, ['text' => str_repeat('"', 239).'ą', 'group_name' => str_repeat('"', 120), 'note' => str_repeat('"', 300), 'no_amount' => '1']),
            'steps' => array_fill(0, 60, ['instruction' => str_repeat('"', 3999).'ą', 'timer_minutes' => '10080', 'remove_photo' => '1']),
        ];
        // Granice kontrolera sprawdzamy bez zapisu. Osobny znany problem:
        // słownik składników ma varchar(160), choć formularz dopuszcza 240.
        // Nie mieszamy naprawy słownika z odzyskiwaniem (#524).
        $validated = (new \ReflectionMethod(RecipeController::class, 'validated'))
            ->invoke(app(RecipeController::class), Request::create('/dodaj/przepis', 'POST', $dane));
        $this->assertCount(60, $validated['steps']);
        $this->assertCount(120, $validated['ingredients']);
        // Kontroler przyjmuje także OBIE reprezentacje naraz. Odzyskiwanie
        // musi zachować oryginał, a nie decydować za późniejszą walidację.
        $dane['skladniki_tekst'] = str_repeat('"', 29999).'ą';
        $dane['przygotowanie_tekst'] = str_repeat('"', 119999).'ą';
        foreach ($dane['steps'] as &$step) {
            $step['id'] = '019a52f0-0000-4000-8000-000000000001';
        }
        unset($step);
        $both = (new \ReflectionMethod(RecipeController::class, 'validated'))
            ->invoke(app(RecipeController::class), Request::create('/dodaj/przepis', 'POST', $dane));
        $this->assertCount(1, $both['steps']);
        $this->assertCount(1, $both['ingredients']);
        if ($status === 429) {
            for ($i = 0; $i < 20; $i++) {
                $this->post(route('recipes.store'), [])->assertStatus(302);
            }
            $response = $this->post(route('recipes.store'), $dane);
        } else {
            $env = $this->app['env'];
            $this->app['env'] = 'production';
            try {
                $response = $this->withSession(['_token' => 'sesja'])->post(route('recipes.store'), $dane + ['_token' => 'stary']);
            } finally {
                $this->app['env'] = $env;
            }
        }
        $response->assertStatus($status)->assertDontSee('nie wszystko udało się przenieść');
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new \DOMXPath($doc);
        $expected = Arr::dot($dane);
        $encoded = [];
        foreach ($xpath->query('//main//form//input[@name] | //main//form//textarea[@name]') as $field) {
            $encoded[] = rawurlencode($field->getAttribute('name')).'='.rawurlencode($field->tagName === 'textarea' ? $field->textContent : $field->getAttribute('value'));
        }
        parse_str(implode('&', $encoded), $actual);
        unset($actual['_token']);
        $this->assertSame($expected, Arr::dot($actual));
        // Mierzymy odpowiedź z markupem, a nie tylko długość odzyskanych wartości.
        $this->assertLessThan(4 * 1024 * 1024, strlen($response->getContent()));
        fwrite(STDERR, "\nMAX524 status=".$status.' html_bytes='.strlen($response->getContent()).' fields='.count($expected)."\n");
    }

    #[DataProvider('statusy')]
    public function test_poprawny_przepis_wraca_bez_utraty_krokow_i_daje_sie_wyslac(int $status): void
    {
        $this->actingAs($this->user('odzyskiwanie524'));
        $dane = ['title' => 'Zupa', 'visibility' => 'public', 'steps' => array_fill(0, 51, ['instruction' => str_repeat('a', 4000)])];
        // Kontrola poprawności sondy: zwykła publikacja przyjmuje CAŁOŚĆ.
        $this->post(route('recipes.store'), $dane)->assertStatus(302)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('recipe_steps', 51);

        if ($status === 429) {
            for ($i = 0; $i < 19; $i++) {
                $this->post(route('recipes.store'), [])->assertStatus(302);
            }
            $response = $this->post(route('recipes.store'), $dane);
        } else {
            $env = $this->app['env'];
            $this->app['env'] = 'production';
            try {
                $response = $this->withSession(['_token' => 'sesja'])->post(route('recipes.store'), $dane + ['_token' => 'stary']);
            } finally {
                $this->app['env'] = $env;
            }
        }
        $response->assertStatus($status)->assertDontSee('nie wszystko udało się przenieść');
        $form = $this->odzyskanyFormularz($response);
        $odzyskane = $form['data'];
        $this->assertSame($dane['steps'], $odzyskane['steps']);
        $this->assertSame($dane['title'], $odzyskane['title']);
        $this->assertSame($dane['visibility'], $odzyskane['visibility']);
        if ($status === 429) {
            $this->travel(((int) $response->headers->get('Retry-After')) + 1)->seconds();
        }
        $this->assertSame(route('recipes.store'), $form['action']);
        $this->assertSame('POST', $form['method']);
        $env = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            // Kontrola dodatnia ochrony: złe CSRF nadal odbija, właściwe pole
            // odczytane z HTML pozwala ponowić żądanie bez podmiany sesji.
            $this->call($form['method'], $form['action'], array_replace($odzyskane, ['_token' => 'bledny-token']))->assertStatus(419);
            $this->assertDatabaseCount('recipes', 1);
            $this->call($form['method'], $form['action'], $odzyskane)->assertStatus(302)->assertSessionHasNoErrors();
        } finally {
            $this->app['env'] = $env;
        }
        $this->assertSame(2, Recipe::count());
        $this->assertSame(102, RecipeStep::count());
    }

    #[DataProvider('statusy')]
    public function test_edycja_zachowuje_uuid_w_formularzu_i_ponawia_put_z_prawdziwym_csrf(int $status): void
    {
        $this->actingAs($this->user('edycja524'));
        $dane = ['title' => 'Zupa', 'visibility' => 'public', 'steps' => []];
        for ($i = 0; $i < 51; $i++) {
            $dane['steps'][] = ['instruction' => str_repeat('a', 3996).sprintf('%04d', $i), 'timer_minutes' => (string) ($i + 1)];
        }
        $this->post(route('recipes.store'), $dane)->assertStatus(302)->assertSessionHasNoErrors();
        $recipe = Recipe::sole();
        $przed = $recipe->steps()->orderBy('position')->get();
        $ids = $przed->pluck('id')->all();
        $this->assertCount(51, $ids);
        $this->assertCount(51, array_unique($ids));
        foreach ($dane['steps'] as $i => &$step) {
            $step['id'] = $ids[$i];
            $step['instruction'] = str_repeat('b', 3996).sprintf('%04d', $i);
        }
        unset($step);
        $url = route('recipes.update', $recipe);
        if ($status === 429) {
            for ($i = 0; $i < 19; $i++) {
                $this->put($url, [])->assertStatus(302);
            }
            $response = $this->put($url, $dane);
        } else {
            $env = $this->app['env'];
            $this->app['env'] = 'production';
            try {
                $response = $this->withSession(['_token' => 'sesja'])->put($url, $dane + ['_token' => 'stary']);
            } finally {
                $this->app['env'] = $env;
            }
        }
        $response->assertStatus($status)->assertDontSee('nie wszystko udało się przenieść');
        $form = $this->odzyskanyFormularz($response);
        $this->assertSame($url, $form['action']);
        $this->assertSame('POST', $form['method']);
        $this->assertArrayHasKey('_method', $form['data']);
        $this->assertSame('PUT', $form['data']['_method']);
        $this->assertSame($dane['steps'], $form['data']['steps']);
        $this->assertSame($ids, $recipe->steps()->orderBy('position')->pluck('id')->all());
        $this->assertSame($przed->pluck('instruction')->all(), $recipe->steps()->orderBy('position')->pluck('instruction')->all());
        if ($status === 429) {
            $this->travel(((int) $response->headers->get('Retry-After')) + 1)->seconds();
        }
        $env = $this->app['env'];
        $this->app['env'] = 'production';
        try {
            $this->call($form['method'], $form['action'], array_replace($form['data'], ['_token' => 'bledny-token']))->assertStatus(419);
            $this->assertSame($ids, $recipe->steps()->orderBy('position')->pluck('id')->all());
            $this->call($form['method'], $form['action'], $form['data'])->assertStatus(302)->assertSessionHasNoErrors();
        } finally {
            $this->app['env'] = $env;
        }
        $this->assertDatabaseCount('recipes', 1);
        $this->assertDatabaseCount('recipe_steps', 51);
        $po = $recipe->steps()->orderBy('position')->get();
        $this->assertSame(array_column($dane['steps'], 'instruction'), $po->pluck('instruction')->all());
        $this->assertSame(range(0, 50), $po->pluck('position')->all());
        $this->assertSame(array_map(fn ($i) => $i * 60, range(1, 51)), $po->pluck('timer_seconds')->all());
        // Zapis domenowy usuwa i tworzy wiersze na nowo. UUID chronimy
        // w payloadzie przed zapisem, nie narzucamy zmiany tej semantyki.
    }

    private function odzyskanyFormularz(TestResponse $response): array
    {
        $doc = new \DOMDocument;
        @$doc->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
        $xpath = new \DOMXPath($doc);
        $forms = $xpath->query('//main//form');
        $this->assertCount(1, $forms);
        $fields = [];
        foreach ($xpath->query('.//input[@name] | .//textarea[@name]', $forms->item(0)) as $field) {
            $value = $field->tagName === 'textarea' ? $field->textContent : $field->getAttribute('value');
            $fields[] = rawurlencode($field->getAttribute('name')).'='.rawurlencode($value);
        }
        parse_str(implode('&', $fields), $data);
        $this->assertNotEmpty($data['_token']);

        return ['action' => $forms->item(0)->getAttribute('action'), 'method' => strtoupper($forms->item(0)->getAttribute('method')), 'data' => $data];
    }
}

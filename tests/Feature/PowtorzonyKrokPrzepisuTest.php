<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Exceptions\BladDlaCzlowieka;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PowtorzonyKrokPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
        Storage::fake('public');
        Queue::fake([ProcessUploadedImage::class]);
    }

    public function test_domena_odmawia_powtorzenia_przed_jakimkolwiek_zapisem(): void
    {
        [$author, $recipe, $steps] = $this->fixture();
        $before = $this->state();
        $writes = [];
        DB::listen(static function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $error = null;

        try {
            app(PublishRecipe::class)->handle($author, ['title' => 'Zmieniony tytuł'], [['text' => 'nowy składnik']], $steps, true, $recipe);
        } catch (BladDlaCzlowieka $exception) {
            $error = $exception;
        }

        $this->assertNotNull($error, 'Domena musi odmówić powtórzonego kroku, zamiast nadpisać pierwszą instrukcję.');
        $this->assertSame([], $writes, 'Odmowa musi poprzedzać zapisy, a nie tylko wycofać je transakcją.');
        $this->assertSame($before, $this->state());
        $this->assertSame('Pierwotny tytuł', $recipe->title);
    }

    public function test_http_wskazuje_instrukcje_i_przywraca_obie_tresci_w_formularzu(): void
    {
        [$author, $recipe, $steps] = $this->fixture();
        $before = $this->state();
        $edit = route('recipes.edit', $recipe);
        $response = $this->actingAs($author)->from($edit)->put(route('recipes.update', $recipe), $this->payload($steps));

        $response->assertRedirect($edit)->assertSessionHasErrors(['steps.1.instruction'])->assertSessionMissing('status');
        $this->assertSame($steps, session()->getOldInput('steps'));
        $this->assertSame('Zmieniony tytuł', session()->getOldInput('title'));
        $this->assertSame($before, $this->state());

        $message = session('errors')->first('steps.1.instruction');
        // Powrót z przekierowania musi nieść ciasteczko tej samej sesji.
        $html = $this->withCookie(config('session.cookie'), session()->getId())->get($edit)->assertOk()->getContent();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);
        foreach ($steps as $index => $row) {
            $field = $xpath->query('//textarea[@name="steps['.$index.'][instruction]"]')->item(0);
            $this->assertNotNull($field);
            $this->assertSame($row['instruction'], trim($field->textContent));
            foreach (['id', 'timer_minutes'] as $name) {
                $input = $xpath->query('//input[@name="steps['.$index.']['.$name.']"]')->item(0);
                $this->assertNotNull($input);
                $this->assertSame($row[$name], $input->getAttribute('value'));
            }
        }
        $field = $xpath->query('//textarea[@name="steps[1][instruction]"]')->item(0);
        $this->assertSame('true', $field->getAttribute('aria-invalid'), $dom->saveHTML($field->parentNode));
        $errorId = $field->getAttribute('aria-describedby');
        $this->assertStringContainsString($message, $xpath->query('//*[@id="'.$errorId.'"]')->item(0)->textContent);
        $this->assertGreaterThan(0, $xpath->query('//a[@href="#'.$field->getAttribute('id').'"]')->length);
    }

    public function test_http_odmawia_przed_uploadem_i_zachowuje_oryginalny_indeks(): void
    {
        [$author, $recipe, $steps] = $this->fixture();
        $before = $this->state();
        Storage::fake('public');
        $steps[1]['photo'] = UploadedFile::fake()->image('krok.jpg');
        $data = $this->payload([2 => $steps[0], 7 => $steps[1]]);
        $data['hero_photo'] = UploadedFile::fake()->image('danie.jpg');
        $data['source_scan'] = UploadedFile::fake()->image('kartka.jpg');

        $response = $this->actingAs($author)->put(route('recipes.update', $recipe), $data);

        $this->assertSame([], Storage::disk('public')->allFiles(), 'Odmowa HTTP musi poprzedzać zapis zdjęcia.');
        Queue::assertNotPushed(ProcessUploadedImage::class);
        $response->assertSessionHasErrors(['steps.7.instruction'])->assertSessionMissing('status');

        $this->assertSame($steps[1]['instruction'], session()->getOldInput('steps.7.instruction'));
        $this->assertSame($before, $this->state());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_livewire_nie_nadpisuje_przepisu_i_zachowuje_tekst_po_odmowie(): void
    {
        [$author, $recipe, $steps] = $this->fixture();
        $before = $this->state();
        Livewire::actingAs($author)->test('recipe-wizard', ['recipeId' => $recipe->id])
            ->set('steps', $steps)
            ->call('saveDraft')
            ->assertSet('saveState', 'error')
            ->assertSet('steps.0.instruction', $steps[0]['instruction'])
            ->assertSet('steps.1.instruction', $steps[1]['instruction']);
        $this->assertSame($before, $this->state());
    }

    public static function wizardActions(): array
    {
        return [['saveDraft'], ['publish']];
    }

    #[DataProvider('wizardActions')]
    public function test_kreator_odmawia_przed_zdjeciem_a_po_poprawieniu_zapisuje(string $action): void
    {
        [$author, $recipe, $steps] = $this->fixture();
        $before = $this->state();
        $component = Livewire::actingAs($author)->test('recipe-wizard', ['recipeId' => $recipe->id])
            ->set('steps', $steps)
            ->set('heroPhoto', UploadedFile::fake()->image('danie.jpg'))
            ->set('steps.1.photo', UploadedFile::fake()->image('krok.jpg'));
        $files = Storage::disk('public')->allFiles();
        $component->call($action);

        $this->assertSame($before, $this->state(), 'Kreator musi odmówić przed zapisem zdjęcia i przepisu.');
        $this->assertSame($files, Storage::disk('public')->allFiles());
        Queue::assertNotPushed(ProcessUploadedImage::class);
        $component->assertHasErrors(['steps.1.instruction'])
            ->assertSet('saveState', 'error')
            ->assertSet('steps.0.instruction', $steps[0]['instruction'])
            ->assertSet('steps.1.instruction', $steps[1]['instruction']);

        $component->set('steps.1.id', null)->call($action)->assertHasNoErrors();
        $saved = $recipe->steps()->orderBy('position')->get();
        $this->assertSame(array_column($steps, 'instruction'), $saved->pluck('instruction')->all());
        $this->assertSame([180, 1200], $saved->pluck('timer_seconds')->all());
        $this->assertNotNull($recipe->fresh()->hero_media_id);
        $this->assertNotNull($saved[1]->media_id);
        Queue::assertPushed(ProcessUploadedImage::class, 2);
    }

    public function test_domena_zachowuje_kolejnosc_usuniecie_i_wszystkie_nowe_instrukcje(): void
    {
        [$author, $recipe] = $this->fixture();
        $ids = $recipe->steps()->orderBy('position')->pluck('id')->all();
        $rows = [
            ['id' => $ids[1], 'instruction' => " \t\n"],
            ['id' => $ids[1], 'instruction' => 'Drugi jako pierwszy.'],
            ['id' => $ids[0], 'instruction' => 'Pierwszy jako drugi.'],
            ['id' => $ids[0], 'instruction' => ''],
            ['instruction' => 'Nowy bez identyfikatora.'],
            ['id' => null, 'instruction' => 'Nowy z null.'],
            ['id' => '', 'instruction' => 'Nowy z pustym identyfikatorem.'],
        ];
        app(PublishRecipe::class)->handle($author, ['title' => 'Nowy tytuł'], steps: $rows, existing: $recipe);
        $saved = $recipe->steps()->orderBy('position')->get();
        $this->assertSame([$ids[1], $ids[0]], $saved->take(2)->pluck('id')->all());
        $this->assertDatabaseMissing('recipe_steps', ['id' => $ids[2]]);
        $this->assertSame(['Drugi jako pierwszy.', 'Pierwszy jako drugi.', 'Nowy bez identyfikatora.', 'Nowy z null.', 'Nowy z pustym identyfikatorem.'], $saved->pluck('instruction')->all());
    }

    public function test_przestawienie_usuniecie_nowe_kroki_i_puste_powtorzenia_sa_dozwolone(): void
    {
        [$author, $recipe] = $this->fixture();
        $ids = $recipe->steps()->orderBy('position')->pluck('id')->all();
        $rows = [
            ['id' => $ids[1], 'instruction' => " \t\n"],
            ['id' => $ids[1], 'instruction' => 'Drugi jako pierwszy.'],
            ['id' => $ids[0], 'instruction' => 'Pierwszy jako drugi.'],
            ['id' => $ids[0], 'instruction' => ''],
            ['instruction' => 'Nowy bez identyfikatora.'],
            ['id' => null, 'instruction' => 'Nowy z null.'],
            ['id' => '', 'instruction' => 'Nowy z pustym identyfikatorem.'],
        ];
        $this->actingAs($author)->put(route('recipes.update', $recipe), $this->payload($rows))->assertSessionHasNoErrors();
        $saved = $recipe->steps()->orderBy('position')->get();
        $this->assertCount(5, $saved);
        $this->assertSame([$ids[1], $ids[0]], $saved->take(2)->pluck('id')->all());
        $this->assertDatabaseMissing('recipe_steps', ['id' => $ids[2]]);
        // Kolejność nowych wierszy bez pola id ma osobną usterkę walidatora HTTP.
        // Tutaj sprawdzamy kompletność, bez utrwalania tej kolejności jako kontraktu.
        $this->assertEqualsCanonicalizing(['Drugi jako pierwszy.', 'Pierwszy jako drugi.', 'Nowy bez identyfikatora.', 'Nowy z null.', 'Nowy z pustym identyfikatorem.'], $saved->pluck('instruction')->all());
    }

    public static function savePaths(): array
    {
        return [['domain'], ['http']];
    }

    #[DataProvider('savePaths')]
    public function test_powtorzone_obce_i_nieznane_id_tworza_nowe_kroki(string $path): void
    {
        [$author, $recipe] = $this->fixture();
        $otherAuthor = $this->user('inny1053');
        $other = app(PublishRecipe::class)->handle($otherAuthor, ['title' => 'Cudzy przepis'], steps: [['instruction' => 'Cudza instrukcja.']], publish: true);
        $foreign = $other->steps()->first();
        $foreignMedia = Media::factory()->create(['owner_id' => $otherAuthor->id]);
        $foreign->update(['media_id' => $foreignMedia->id]);
        $unknown = (string) Str::uuid();
        $rows = [];
        foreach ([$foreign->id, $foreign->id, $unknown, $unknown] as $index => $id) {
            $rows[] = ['id' => $id, 'instruction' => 'Nowy krok '.$index];
        }
        if ($path === 'http') {
            $this->actingAs($author)->put(route('recipes.update', $recipe), $this->payload($rows))->assertSessionHasNoErrors();
        } else {
            app(PublishRecipe::class)->handle($author, ['title' => 'Nowy tytuł'], steps: $rows, existing: $recipe);
        }
        $saved = $recipe->steps()->get();
        $this->assertCount(4, $saved);
        $this->assertSame([], array_intersect([$foreign->id, $unknown], $saved->pluck('id')->all()));
        $this->assertSame('Cudza instrukcja.', $foreign->fresh()->instruction);
        $this->assertSame($other->id, $foreign->fresh()->recipe_id);
        $this->assertSame($foreignMedia->id, $foreign->fresh()->media_id);
        $this->assertEqualsCanonicalizing(array_column($rows, 'instruction'), $saved->pluck('instruction')->all());
        $this->assertSame([null, null, null, null], $saved->pluck('media_id')->all());
    }

    /** @return array{User, Recipe, list<array<string, string>>} */
    private function fixture(): array
    {
        $author = $this->user('autorka1053');
        $media = Media::factory()->create(['owner_id' => $author->id]);
        $recipe = app(PublishRecipe::class)->handle($author, ['title' => 'Pierwotny tytuł', 'hero_media_id' => $media->id], [['text' => 'pierwotny składnik']], [
            ['instruction' => 'Pierwszy krok.', 'media_id' => $media->id], ['instruction' => 'Drugi krok.'], ['instruction' => 'Do usunięcia.'],
        ], true);
        $id = $recipe->steps()->orderBy('position')->value('id');

        return [$author, $recipe, [
            ['id' => $id, 'instruction' => 'Podsmaż przez chwilę.', 'timer_minutes' => '3'],
            ['id' => $id, 'instruction' => 'Zalej wodą i gotuj.', 'timer_minutes' => '20'],
        ]];
    }

    /** @param array<int, array<string, mixed>> $steps */
    private function payload(array $steps): array
    {
        return ['title' => 'Zmieniony tytuł', 'visibility' => 'public', 'action' => 'publish', 'ingredients' => [['text' => 'nowy składnik']], 'steps' => $steps];
    }

    private function state(): array
    {
        $state = [];
        foreach (['recipes', 'recipe_steps', 'recipe_ingredients', 'recipe_versions', 'audit_log', 'media'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $state;
    }
}

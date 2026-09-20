<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\AuditLogEntry;
use App\Models\RecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PublikacjaPrzepisuAtomowaTest extends TestCase
{
    use RefreshDatabase;

    public static function failures(): array
    {
        return ['migawka' => [RecipeVersion::class], 'audyt' => [AuditLogEntry::class]];
    }

    #[DataProvider('failures')]
    public function test_odmowa_cofa_tresc_skladniki_kroki_historie_i_audyt(string $model): void
    {
        $author = $this->user();
        $action = app(PublishRecipe::class);
        $recipe = $action->handle($author, ['title' => 'Przed zmianą'], [['text' => 'mąka']], [['instruction' => 'Wymieszaj.']], true);
        $fail = true;
        $model::creating(function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('Odmowa kontrolna 895');
            }
        });
        try {
            $action->handle($author, ['title' => 'Po zmianie'], [['text' => 'masło']], [['instruction' => 'Upiecz.']], true, $recipe);
            $this->fail('Zapis powinien odmówić.');
        } catch (RuntimeException $e) {
            $this->assertSame('Odmowa kontrolna 895', $e->getMessage());
        } finally {
            $fail = false;
        }
        $recipe->refresh();
        $this->assertSame('Przed zmianą', $recipe->title);
        $this->assertSame(['mąka'], $recipe->ingredients()->pluck('ingredient_text')->all());
        $this->assertSame(['Wymieszaj.'], $recipe->steps()->pluck('instruction')->all());
        $this->assertSame(1, $recipe->versions()->count());
        $this->assertDatabaseCount('posts', 1);
        $this->assertSame(1, AuditLogEntry::where('action', 'recipe.published')->count());
    }

    #[DataProvider('failures')]
    public function test_nowa_publikacja_po_odmowie_moze_byc_ponowiona_z_tym_samym_kluczem(string $model): void
    {
        $author = $this->user();
        $key = (string) Str::uuid();
        $fail = true;
        $model::creating(function () use (&$fail): void {
            if ($fail) {
                throw new RuntimeException('Odmowa kontrolna 895');
            }
        });
        $publish = fn () => app(PublishRecipe::class)->handle($author, ['title' => 'Nowy przepis'], [['text' => 'sól']], [['instruction' => 'Dodaj.']], true, kluczWyslania: $key);
        try {
            $publish();
            $this->fail('Zapis powinien odmówić.');
        } catch (RuntimeException $e) {
            $this->assertSame('Odmowa kontrolna 895', $e->getMessage());
        } finally {
            $fail = false;
        }
        foreach (['recipes', 'recipe_ingredients', 'recipe_steps', 'recipe_versions', 'posts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, AuditLogEntry::where('action', 'recipe.published')->count());
        $recipe = $publish();
        $this->assertSame($recipe->id, $publish()->id);
        $this->assertDatabaseCount('recipes', 1);
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('recipe_versions', 1);
        $this->assertSame(1, AuditLogEntry::where('action', 'recipe.published')->count());
    }
}

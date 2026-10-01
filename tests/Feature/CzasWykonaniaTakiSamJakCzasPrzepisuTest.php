<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\Recipe;
use App\Models\RecipeStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * „Zajęło mi …” pisze się tak samo jak czas przepisu (decyzja właściciela
 * z 1.10.2026, wiersz w D-333): od 90 minut godziny i minuty, zaokrąglone do
 * 5 minut (`Czas::czasPrzepisu`). Dotyczy tekstów dla człowieka — karty
 * wykonania i czasu kroku oraz przygotowania i gotowania w kopii HTML przepisu. Surowe liczby w eksporcie
 * JSON/CSV oraz ISO 8601 w JSON-LD zostają bez zmian.
 */
class CzasWykonaniaTakiSamJakCzasPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{int, string}> */
    public static function czasyWykonania(): array
    {
        return [
            'dwie pełne godziny' => [120, 'Zajęło mi 2 godz.</span>'],
            'godzina i trzydzieści pięć minut' => [95, 'Zajęło mi 1 godz. 35 min</span>'],
            'poniżej progu zostają minuty' => [45, 'Zajęło mi 45 min</span>'],
        ];
    }

    #[DataProvider('czasyWykonania')]
    public function test_karta_wykonania_pisze_czas_jak_czas_przepisu(int $minuty, string $oczekiwane): void
    {
        $kucharz = $this->user();
        $recipe = Recipe::factory()->create([
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
        ]);

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            actualMinutes: $minuty,
        );

        $this->actingAs($kucharz)
            ->get(route('cooked.show', $event))
            ->assertOk()
            ->assertSee($oczekiwane, false);

        // Zapis w bazie zostaje surową liczbą.
        $this->assertSame($minuty, $event->fresh()->actual_minutes);
    }

    public function test_kopia_html_przepisu_pisze_czas_kroku_jak_czas_przepisu(): void
    {
        $przepis = Recipe::factory()->create(['prep_minutes' => 120, 'cook_minutes' => 95]);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 0, 'instruction' => 'Duś.', 'timer_seconds' => 120 * 60]);
        RecipeStep::create(['recipe_id' => $przepis->getKey(), 'position' => 1, 'instruction' => 'Odstaw.', 'timer_seconds' => 45 * 60]);
        $przepis->load('steps.media');

        $html = view('exports.recipe', [
            'recipe' => $przepis,
            'heroPhoto' => null,
            'scanPhoto' => null,
            'stepPhotos' => [],
            'comments' => [],
        ])->render();

        $this->assertStringContainsString('Czas: 2 godz.</p>', $html);
        $this->assertStringContainsString('Czas: 45 min</p>', $html);
        $this->assertStringNotContainsString('Czas: 120 min', $html);
        $this->assertStringContainsString('Przygotowanie: 2 godz.</span>', $html);
        $this->assertStringContainsString('Gotowanie: 1 godz. 35 min</span>', $html);
    }
}

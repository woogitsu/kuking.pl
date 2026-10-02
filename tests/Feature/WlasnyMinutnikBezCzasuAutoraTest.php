<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Własny minutnik przy kroku BEZ czasu autora (issue #2595, decyzja
 * właściciela z 2.10.2026). Strona HTTP: który krok ma blok i jakie atrybuty
 * potrzebuje skrypt. Sam przebieg odliczania sprawdza
 * `resources/js/minutnik-krok.test.mjs` (`node --test`).
 */
class WlasnyMinutnikBezCzasuAutoraTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(): Recipe
    {
        $recipe = Recipe::factory()->create(['author_id' => User::factory()->create()->getKey()]);

        foreach ([0 => 600, 1 => null] as $pozycja => $sekundy) {
            RecipeStep::create([
                'recipe_id' => $recipe->getKey(),
                'position' => $pozycja,
                'instruction' => 'Krok numer '.($pozycja + 1).'.',
                'timer_seconds' => $sekundy,
            ]);
        }

        return $recipe;
    }

    public function test_krok_bez_czasu_autora_ma_blok_wlasnego_minutnika_ukryty_bez_js(): void
    {
        $recipe = $this->przepis();
        $krok = RecipeStep::query()->where('recipe_id', $recipe->getKey())->where('position', 1)->firstOrFail();

        $html = $this->get(route('cooking.show', [$recipe->slug, 'krok' => 2]))->assertOk()->getContent();

        // Blok jest ukryty, dopóki skrypt go nie odsłoni: bez JS nie ma martwych przycisków.
        $this->assertMatchesRegularExpression('/<div class="cook-timer cook-timer-wlasny"[^>]*data-timer-wlasny="1"[^>]*\shidden>/', $html);
        $this->assertStringContainsString('data-timer-step-id="'.$krok->getKey().'"', $html);
        $this->assertStringContainsString('data-timer-fingerprint="'.$krok->timerFingerprint().'"', $html);
        $this->assertStringContainsString('data-timer-krok="2"', $html);
        $this->assertStringNotContainsString('data-timer-sekundy', $html);

        foreach ([5, 10, 15, 20] as $minuty) {
            $this->assertStringContainsString('data-minuty="'.$minuty.'"', $html);
        }

        $this->assertMatchesRegularExpression('/<form class="cook-timer-formularz" novalidate>/', $html);
        $this->assertStringContainsString('<label for="f-minutnik-wlasny">Ile minut?</label>', $html);
        $this->assertStringContainsString('Nastaw własny minutnik', $html);
        $this->assertStringContainsString('Start</button>', $html);
        $this->assertStringContainsString('Anuluj minutnik', $html);
        // Nie ma zdania „Ustaw sobie kuchenny minutnik” z minutnika autora.
        $this->assertStringNotContainsString('Ustaw sobie kuchenny minutnik', $html);
    }

    public function test_krok_z_czasem_autora_nie_ma_wlasnego_minutnika(): void
    {
        $recipe = $this->przepis();

        $html = $this->get(route('cooking.show', [$recipe->slug, 'krok' => 1]))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-timer-wlasny', $html);
        $this->assertStringNotContainsString('Nastaw własny minutnik', $html);
        $this->assertStringContainsString('data-timer-sekundy="600"', $html);
        $this->assertStringContainsString('Ustaw sobie kuchenny minutnik na 10 minut', $html);
    }

    public function test_wlasny_minutnik_nie_dotyka_przepisu(): void
    {
        $recipe = $this->przepis();

        $this->get(route('cooking.show', [$recipe->slug, 'krok' => 2]))->assertOk();

        $this->assertSame(
            [600, null],
            RecipeStep::query()->where('recipe_id', $recipe->getKey())->orderBy('position')->pluck('timer_seconds')->all(),
        );
    }
}

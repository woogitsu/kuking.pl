<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use DOMDocument;
use DOMNode;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CookedCountsUnitsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{int, string}> */
    public static function counts(): array
    {
        return [
            'zero' => [0, ''],
            'jedno' => [1, '1 wykonanie'],
            'dwa' => [2, '2 wykonania'],
            'piec' => [5, '5 wykonań'],
            'dwanascie' => [12, '12 wykonań'],
            'dwadziescia_dwa' => [22, '22 wykonania'],
        ];
    }

    #[DataProvider('counts')]
    public function test_repeated_cooking_counts_events_with_correct_unit(int $count, string $label): void
    {
        $recipe = $this->publishedRecipe();
        $cook = $this->user('kucharz');
        for ($i = 0; $i < $count; $i++) {
            CookedEvent::factory()->create([
                'recipe_id' => $recipe->getKey(),
                'user_id' => $cook->getKey(),
                'would_make_again' => null,
                'cooked_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->get(route('recipes.show', $recipe->slug))->assertOk();
        $this->assertSame($count, $response->viewData('cookedCount'));
        $this->assertSame($count, $response->viewData('cookedEvents')->total());
        $xpath = $this->xpath((string) $response->getContent());
        $summary = $this->text($xpath, '//section[@aria-labelledby="komu-wyszlo"]//p[contains(@class,"pasek-liczb")]');
        $this->assertSame($label, $summary);
        $facts = $this->text($xpath, '//article[contains(@class,"marka-przepis")]/ul[@class="recipe-facts"]');
        if ($count === 0) {
            $this->assertStringContainsString('Jeszcze nikt tego nie gotował', $this->text($xpath, '//section[@aria-labelledby="komu-wyszlo"]'));
            $this->assertStringNotContainsString('Ugotowane', $facts);
        } else {
            $this->assertStringContainsString('Ugotowane '.$count.' ×', $facts);
            $this->assertSame(1, $recipe->cookedEvents()->distinct()->count('user_id'));
        }
        $this->assertStringNotContainsString('odpowiedzi', $facts);
        $this->assertStringNotContainsString('%', $summary);
        if ($count === 22) {
            $this->assertLessThan($count, $response->viewData('cookedEvents')->count());
        }
    }

    public function test_conflicting_answers_from_one_person_remain_separate_answers(): void
    {
        $recipe = $this->publishedRecipe();
        $first = $this->user('pierwszy');
        $second = $this->user('drugi');
        foreach ([$first, $first, $first, $second] as $i => $cook) {
            CookedEvent::factory()->create([
                'recipe_id' => $recipe->getKey(),
                'user_id' => $cook->getKey(),
                'would_make_again' => in_array($i, [0, 3], true),
                'cooked_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->get(route('recipes.show', $recipe->slug))->assertOk();
        $this->assertSame(2, $recipe->cookedEvents()->distinct()->count('user_id'));
        $this->assertCount(4, $response->viewData('cookedEvents'));
        $xpath = $this->xpath((string) $response->getContent());
        $facts = $this->text($xpath, '//article[contains(@class,"marka-przepis")]/ul[@class="recipe-facts"]');
        $this->assertStringContainsString('Ugotowane 4 ×', $facts);
        $this->assertStringContainsString('Zrobię ponownie: 2 z 4 odpowiedzi', $facts);
        $this->assertSame('4 wykonania · 50% odpowiedzi: „Zrobię ponownie”', $this->text($xpath, '//section[@aria-labelledby="komu-wyszlo"]//p[contains(@class,"pasek-liczb")]'));
        $this->assertStringNotContainsString('osób zrobi', (string) $response->getContent());
        $this->assertStringNotContainsString('osoby ugotowały', (string) $response->getContent());
        $response->assertSee('"userInteractionCount":4', false);
    }

    public function test_unanswered_event_does_not_reach_the_three_answer_threshold(): void
    {
        $recipe = $this->publishedRecipe();
        $cook = $this->user('kucharz');
        foreach ([true, true, null] as $answer) {
            CookedEvent::factory()->create([
                'recipe_id' => $recipe->getKey(),
                'user_id' => $cook->getKey(),
                'would_make_again' => $answer,
            ]);
        }

        $response = $this->get(route('recipes.show', $recipe->slug))->assertOk();
        $this->assertSame(3, $response->viewData('cookedCount'));
        $this->assertSame(2, $response->viewData('oceniloWykonanie'));
        $xpath = $this->xpath((string) $response->getContent());
        $this->assertStringNotContainsString('Zrobię ponownie:', $this->text($xpath, '//article[contains(@class,"marka-przepis")]/ul[@class="recipe-facts"]'));
        $this->assertSame('3 wykonania', $this->text($xpath, '//section[@aria-labelledby="komu-wyszlo"]//p[contains(@class,"pasek-liczb")]'));
    }

    private function publishedRecipe(): Recipe
    {
        // Ze zdjęciem: bez niego strona nie wystawia `Recipe` (#1005),
        // a testy sprawdzają też `userInteractionCount` w JSON-LD.
        return Recipe::factory()->zeZdjeciem()->create([
            'author_id' => $this->user('autor')->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function text(DOMXPath $xpath, string $selector): string
    {
        $nodes = $xpath->query($selector);
        $this->assertNotFalse($nodes);

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', array_map(
            static fn (DOMNode $node): string => $node->textContent,
            iterator_to_array($nodes),
        ))));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SzczegolyZachowujaKluczeWierszyTest extends TestCase
{
    use RefreshDatabase;

    public static function keys(): array
    {
        return [[0, 1], [0, 3], [2, 1000000]];
    }

    #[DataProvider('keys')]
    public function test_odrzucone_zadanie_odtwarza_wiersze_i_blad_przy_wlasciwym_kluczu(int $first, int $last): void
    {
        $user = $this->user();
        $recipe = Recipe::factory()->draft()->create(['author_id' => $user->id]);
        $step = $recipe->steps()->create(['position' => 0, 'instruction' => 'Stary tekst.']);
        $url = route('recipes.edit', $recipe->slug);
        $this->actingAs($user)->from($url)->put(route('recipes.update', $recipe->slug), [
            'title' => '', 'visibility' => 'private', 'action' => 'draft',
            'steps' => [
                $first => ['instruction' => 'Pierwszy nowy krok.', 'timer_minutes' => '12'],
                $last => ['id' => $step->id, 'instruction' => 'Ostatni nowy krok.', 'timer_minutes' => '10081'],
            ],
            'ingredients' => [
                $first => ['text' => 'Mąka'],
                $last => ['text' => 'Sól', 'group_name' => 'Do podania', 'no_amount' => '1', 'note' => 'Gruboziarnista'],
            ],
        ])->assertSessionHasErrors(['title', "steps.$last.timer_minutes"])->assertRedirect($url);

        $response = $this->get($url)->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame('Ostatni nowy krok.', $xpath->evaluate("string(//textarea[@name='steps[$last][instruction]'])"));
        $this->assertSame((string) $step->id, $xpath->evaluate("string(//input[@name='steps[$last][id]']/@value)"));
        $this->assertSame('10081', $xpath->evaluate("string(//input[@name='steps[$last][timer_minutes]']/@value)"));
        $this->assertSame('Sól', $xpath->evaluate("string(//input[@name='ingredients[$last][text]']/@value)"));
        $this->assertSame('Do podania', $xpath->evaluate("string(//input[@name='ingredients[$last][group_name]']/@value)"));
        $this->assertSame(1, $xpath->query("//input[@name='ingredients[$last][no_amount]'][@checked]")->length);
        $this->assertSame('Gruboziarnista', $xpath->evaluate("string(//input[@name='ingredients[$last][note]']/@value)"));
        $this->assertSame(1, $xpath->query("//input[@id='f-steps-$last-timer_minutes']")->length);
        $this->assertSame(3, $xpath->query("//textarea[contains(@name, '[instruction]')]")->length);
    }

    public function test_pelny_formularz_nie_dodaje_wiersza_ponad_limit(): void
    {
        $recipe = Recipe::factory()->draft()->create(['author_id' => ($user = $this->user())->id]);
        $response = $this->actingAs($user)->withSession(['_old_input' => [
            'steps' => array_fill(0, Recipe::MAX_STEPS, ['instruction' => 'Gotuj.']),
            'ingredients' => array_fill(0, Recipe::MAX_INGREDIENTS, ['text' => 'Sól']),
        ]])->get(route('recipes.edit', $recipe->slug))->assertOk();
        $this->assertSame(Recipe::MAX_STEPS, substr_count($response->getContent(), '[instruction]"'));
        $this->assertSame(Recipe::MAX_INGREDIENTS, substr_count($response->getContent(), '[text]"'));
    }

    public function test_bledy_skladnikow_i_minutnika_sa_powiazane_z_wlasciwymi_polami(): void
    {
        $user = $this->user();
        $recipe = Recipe::factory()->draft()->create(['author_id' => $user->id]);
        $url = route('recipes.edit', $recipe->slug);

        $this->actingAs($user)->from($url)->put(route('recipes.update', $recipe->slug), [
            'title' => 'Zupa domowa', 'visibility' => 'private', 'action' => 'draft',
            'ingredients' => [
                0 => ['text' => 'Sól', 'group_name' => 'Do podania'],
                3 => ['text' => str_repeat('a', 241), 'group_name' => str_repeat('b', 121)],
            ],
            'steps' => [
                0 => ['instruction' => 'Zagotuj wodę.', 'timer_minutes' => '10'],
                3 => ['instruction' => 'Wymieszaj.', 'timer_minutes' => '10081'],
            ],
        ])->assertSessionHasErrors([
            'ingredients.3.text', 'ingredients.3.group_name', 'steps.3.timer_minutes',
        ])->assertRedirect($url);

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8"'.$this->get($url)->assertOk()->getContent());
        $xpath = new \DOMXPath($dom);

        foreach (['ingredients-3-text', 'ingredients-3-group_name', 'steps-3-timer_minutes'] as $fieldId) {
            $field = $xpath->query("//*[@id='f-$fieldId']")->item(0);
            $error = $xpath->query("//*[@id='f-$fieldId-error']")->item(0);
            $this->assertNotNull($field, $fieldId);
            $this->assertNotNull($error, $fieldId);
            $this->assertSame('true', $field->getAttribute('aria-invalid'), $fieldId);
            $this->assertContains('f-'.$fieldId.'-error', preg_split('/\s+/', trim($field->getAttribute('aria-describedby'))), $fieldId);
            $this->assertNotSame('', trim($error->textContent), $fieldId);
        }

        $timer = $xpath->query("//*[@id='f-steps-3-timer_minutes']")->item(0);
        $this->assertContains('f-steps-3-timer_minutes-help', preg_split('/\s+/', trim($timer->getAttribute('aria-describedby'))));

        foreach (['ingredients-0-text', 'ingredients-0-group_name', 'steps-0-timer_minutes'] as $fieldId) {
            $field = $xpath->query("//*[@id='f-$fieldId']")->item(0);
            $this->assertNotNull($field, $fieldId);
            $this->assertFalse($field->hasAttribute('aria-invalid'), $fieldId);
            $this->assertSame(0, $xpath->query("//*[@id='f-$fieldId-error']")->length, $fieldId);
        }
    }
}

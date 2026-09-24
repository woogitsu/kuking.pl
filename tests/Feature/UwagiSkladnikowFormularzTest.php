<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UwagiSkladnikowFormularzTest extends TestCase
{
    use RefreshDatabase;

    public function test_wyslanie_rzeczywistych_pol_formularza_zachowuje_uwagi(): void
    {
        $author = $this->user();
        $recipe = app(PublishRecipe::class)->handle($author, ['title' => 'Ciasto domowe'], [
            ['text' => '100 g masła', 'note' => 'PROBA_UWAGI — w temperaturze pokojowej'],
            ['text' => 'mąka', 'note' => 'Przesiej dwa razy'],
        ], [['instruction' => 'Wymieszaj.']], true);
        $this->actingAs($author);
        $data = $this->form($this->get(route('recipes.edit', $recipe))->assertOk()->getContent());
        $data['title'] = 'Nowa nazwa ciasta';
        $this->put(route('recipes.update', $recipe), $data)->assertSessionHasNoErrors();
        $this->assertSame(['PROBA_UWAGI — w temperaturze pokojowej', 'Przesiej dwa razy'], $recipe->ingredients()->pluck('note')->all());
        $this->get(route('recipes.show', $recipe))->assertSee('PROBA_UWAGI — w temperaturze pokojowej');
    }

    public function test_uwagi_po_bledzie_nie_zamieniaja_sie_miejscami_i_mozna_je_wyczyscic(): void
    {
        $author = $this->user();
        $recipe = app(PublishRecipe::class)->handle($author, ['title' => 'Ciasto domowe'], [
            ['text' => 'masło', 'note' => 'Pierwsza uwaga'],
            ['text' => 'mąka', 'note' => 'Druga uwaga'],
        ], [['instruction' => 'Wymieszaj.']], true);
        $this->actingAs($author);
        $url = route('recipes.edit', $recipe);
        $data = $this->form($this->get($url)->assertOk()->getContent());
        unset($data['ingredients'][0]);
        $data['ingredients'] = array_values($data['ingredients']);
        $data['ingredients'][0]['note'] = 'Nowa uwaga po usunięciu masła';
        $data['title'] = 'x';
        $this->from($url)->put(route('recipes.update', $recipe), $data)->assertSessionHasErrors('title');
        $data = $this->form($this->get($url)->assertOk()->getContent());
        $this->assertSame('Nowa uwaga po usunięciu masła', $data['ingredients'][0]['note'] ?? null);
        $data['title'] = 'Przepis poprawiony';
        $this->put(route('recipes.update', $recipe), $data)->assertSessionHasNoErrors();
        $this->assertSame(['mąka'], $recipe->ingredients()->pluck('ingredient_text')->all());
        $this->assertSame(['Nowa uwaga po usunięciu masła'], $recipe->ingredients()->pluck('note')->all());
        $data = $this->form($this->get($url)->assertOk()->getContent());
        $data['ingredients'][0]['note'] = '';
        $this->put(route('recipes.update', $recipe), $data)->assertSessionHasNoErrors();
        $this->assertNull($recipe->ingredients()->first()->note);
    }

    public function test_za_dluga_uwaga_wraca_przy_polu_i_w_podsumowaniu(): void
    {
        $author = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $author->id]);
        $this->actingAs($author);
        $url = route('recipes.edit', $recipe);
        $data = ['title' => 'Ciasto domowe', 'visibility' => 'public', 'ingredients' => [['text' => 'masło', 'note' => str_repeat('a', 301)]]];
        $html = $this->followingRedirects()->from($url)->put(route('recipes.update', $recipe), $data)->assertOk()->getContent();
        $this->assertSame(str_repeat('a', 301), $this->form($html)['ingredients'][0]['note'] ?? null);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('href="#f-ingredients-0-note"', $html);
    }

    /** Wysyła tylko kontrolki naprawdę wyrenderowane, nie dopisuje brakującego note. */
    private function form(string $html): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $xpath = new DOMXPath($dom);
        $fields = $xpath->query('//form[.//input[@name="title"]]//*[self::input or self::textarea or self::select][@name]');
        $this->assertGreaterThan(10, $fields->length, 'Parser musi czytać właściwy formularz.');
        $pairs = [];
        foreach ($fields as $field) {
            $type = $field->getAttribute('type');
            if (in_array($type, ['file', 'submit', 'button'], true) || $field->hasAttribute('disabled')) {
                continue;
            }
            if (in_array($type, ['radio', 'checkbox'], true) && ! $field->hasAttribute('checked')) {
                continue;
            }
            $value = $field->tagName === 'textarea' ? $field->textContent : $field->getAttribute('value');
            if ($field->tagName === 'select') {
                $option = $xpath->query('.//option[@selected]', $field)->item(0) ?? $xpath->query('.//option', $field)->item(0);
                $value = $option?->getAttribute('value') ?? '';
            }
            $pairs[] = rawurlencode($field->getAttribute('name')).'='.rawurlencode($value);
        }
        parse_str(implode('&', $pairs), $data);
        $data['action'] = 'publish';

        return $data;
    }
}

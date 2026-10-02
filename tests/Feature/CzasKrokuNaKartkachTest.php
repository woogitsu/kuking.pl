<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #2484: osobny czas kroku zostaje obok właściwej instrukcji na obu kartkach. */
final class CzasKrokuNaKartkachTest extends TestCase
{
    use RefreshDatabase;

    public function test_strona_przepisu_i_jej_wydruk_pokazuja_czasy_przy_wlasciwych_krokach(): void
    {
        [$recipe] = $this->przepisZKrokami();

        foreach ([
            route('recipes.show', $recipe->slug),
            route('recipes.show', ['recipe' => $recipe->slug, 'druk' => 1]),
            route('recipes.show', ['recipe' => $recipe->slug, 'druk' => 1, 'porcje' => 2]),
        ] as $adres) {
            $html = (string) $this->get($adres)->assertOk()->getContent();
            $kroki = $this->kroki($html, '//ol[@class="step-list"]/li');
            $this->sprawdzCzasy($kroki, 'CZAS_KROKU_STRONA');
        }
    }

    public function test_wydruk_zeszytu_pokazuje_czasy_przy_wlasciwych_krokach(): void
    {
        [$recipe, $autor] = $this->przepisZKrokami();
        $zeszyt = Collection::create([
            'owner_id' => $autor->getKey(),
            'name' => 'Książka z czasami',
            'visibility' => 'private',
        ]);
        $zeszyt->recipes()->attach($recipe->getKey(), [
            'created_at' => now(),
            'added_by_id' => $autor->getKey(),
        ]);

        $html = (string) $this->actingAs($autor)
            ->get(route('collections.print', $zeszyt))
            ->assertOk()
            ->getContent();
        $kroki = $this->kroki($html, '//article[@class="zeszyt-przepis"]//ol[@class="step-list"]/li');
        $this->sprawdzCzasy($kroki, 'CZAS_KROKU_ZESZYT');
    }

    /** @return array{Recipe, User} */
    private function przepisZKrokami(): array
    {
        $autor = $this->user('autorka_czasu_kroku');
        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'servings' => 4,
            'prep_minutes' => 10,
            'cook_minutes' => 20,
        ]);

        foreach ([
            ['Duś.', 45 * 60],
            ['Odstaw.', 90],
            ['Podaj.', null],
            ['Wymieszaj.', 0],
        ] as $position => [$instruction, $seconds]) {
            RecipeStep::create([
                'recipe_id' => $recipe->getKey(),
                'position' => $position,
                'instruction' => $instruction,
                'timer_seconds' => $seconds,
            ]);
        }

        return [$recipe, $autor];
    }

    /** @return list<string> */
    private function kroki(string $html, string $query): array
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        $lista = (new DOMXPath($dom))->query($query);
        $this->assertInstanceOf(DOMNodeList::class, $lista);
        $this->assertCount(4, $lista, 'Wydruk powinien mieć cztery odrębne kroki.');

        $wynik = [];
        foreach ($lista as $element) {
            $this->assertInstanceOf(DOMElement::class, $element);
            $wynik[] = trim((string) preg_replace('/\s+/u', ' ', $element->textContent));
        }

        return $wynik;
    }

    /** @param list<string> $kroki */
    private function sprawdzCzasy(array $kroki, string $marker): void
    {
        $this->assertStringContainsString('Duś. Czas kroku: 45 minut', $kroki[0], $marker.': czas pierwszego kroku zniknął.');
        $this->assertStringContainsString('Odstaw. Czas kroku: 1 minuta i 30 sekund', $kroki[1], $marker.': czas drugiego kroku zniknął.');
        $this->assertStringContainsString('Podaj.', $kroki[2]);
        $this->assertStringNotContainsString('Czas kroku:', $kroki[2]);
        $this->assertStringContainsString('Wymieszaj.', $kroki[3]);
        $this->assertStringNotContainsString('Czas kroku:', $kroki[3]);
    }
}

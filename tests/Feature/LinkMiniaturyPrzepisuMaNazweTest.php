<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Karta przepisu ma jeden nazwany link (#2063); w wierszu rozciągnięty na całą kartę (1.10.2026). */
class LinkMiniaturyPrzepisuMaNazweTest extends TestCase
{
    use RefreshDatabase;

    public function test_wiersz_ma_jeden_link_do_przepisu_z_tytulem_a_miniatura_nie_jest_linkiem(): void
    {
        $media = Media::factory()->create(['alt_text' => null]);
        $recipe = Recipe::factory()->create([
            'title' => 'Zupa <babci> & kluski',
            'author_id' => $media->owner_id,
            'hero_media_id' => $media->getKey(),
        ]);

        $xpath = $this->karta($recipe, 'wiersz');

        // Cała karta jest klikalna jednym linkiem tytułu (nakładka `::after`),
        // więc czytnik ekranu nie dostaje drugiego linku z miniatury.
        $this->assertSame(0, $xpath->query('//article//a[contains(concat(" ", normalize-space(@class), " "), " recipe-card-miniatura ")]')->length);
        $this->assertSame(1, $xpath->query('//article//a')->length);
        $this->assertSame(1, $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " recipe-card-wiersz ")]//div[contains(concat(" ", normalize-space(@class), " "), " recipe-card-miniatura ")]//img')->length);
        // Brak opisu zdjęcia = pusty alt (miniatura ozdobna), a nie nazwa pliku ani brak atrybutu.
        $miniatura = '//article//div[contains(concat(" ", normalize-space(@class), " "), " recipe-card-miniatura ")]//img';
        $this->assertSame(1, $xpath->query($miniatura.'[@alt=""]')->length);
        $this->assertSame(route('recipes.show', $recipe->slug), $xpath->evaluate('string(//article//h3/a[contains(concat(" ", normalize-space(@class), " "), " link-tytul ")]/@href)'));
        $this->assertSame($recipe->title, trim($xpath->evaluate('string(//article//h3/a)')));
        $this->assertSame(0, $xpath->query('//a//a')->length);
    }

    public function test_autorski_opis_zdjecia_zostaje_na_miniaturze_wiersza(): void
    {
        $media = Media::factory()->create(['alt_text' => 'Miseczka zupy']);
        $recipe = Recipe::factory()->create([
            'title' => 'Zupa jarzynowa',
            'author_id' => $media->owner_id,
            'hero_media_id' => $media->getKey(),
        ]);

        $xpath = $this->karta($recipe, 'wiersz');

        $this->assertSame(
            'Miseczka zupy',
            $xpath->evaluate('string(//article//div[contains(concat(" ", normalize-space(@class), " "), " recipe-card-miniatura ")]//img/@alt)'),
        );
        $this->assertSame(1, $xpath->query('//article//a')->length);
    }

    public function test_kafel_zachowuje_jeden_nazwany_link_bez_linku_miniatury(): void
    {
        $media = Media::factory()->create(['alt_text' => null]);
        $recipe = Recipe::factory()->create([
            'title' => 'Placki ziemniaczane',
            'author_id' => $media->owner_id,
            'hero_media_id' => $media->getKey(),
        ]);

        $xpath = $this->karta($recipe, 'kafel');

        $this->assertSame(0, $xpath->query('//article//a[contains(concat(" ", normalize-space(@class), " "), " recipe-card-miniatura ")]')->length);
        $this->assertSame('Zobacz przepis: Placki ziemniaczane', $xpath->evaluate('string(//article//a[contains(concat(" ", normalize-space(@class), " "), " recipe-card-otworz ")]/@aria-label)'));
    }

    private function karta(Recipe $recipe, string $uklad): DOMXPath
    {
        $html = Blade::render('<x-recipe-card :recipe="$recipe" :uklad="$uklad" />', [
            'recipe' => $recipe->fresh(),
            'uklad' => $uklad,
        ]);
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }
}

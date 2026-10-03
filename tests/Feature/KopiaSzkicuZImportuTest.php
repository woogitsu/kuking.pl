<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\Actions\ZapiszSzkicZImportu;
use App\Domain\Import\OdczytanyPrzepis;
use App\Domain\Import\StrazImportu;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\Actions\ZrobKopieSzkicu;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\ImportPrzepisu;
use App\Models\Media;
use App\Models\PrzepisZImportu;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** #2800: nowe ID kopii nie może ominąć pochodzenia i bramki odczytu D-300. */
final class KopiaSzkicuZImportuTest extends TestCase
{
    use RefreshDatabase;

    public function test_szkic_z_url_i_pdf_nie_dostaje_kopii_przez_domene_ani_http(): void
    {
        $autor = $this->user('autorka');

        foreach ([PrzepisZImportu::ZRODLO_URL, PrzepisZImportu::ZRODLO_PDF] as $zrodlo) {
            $szkic = app(ZapiszSzkicZImportu::class)->handle(
                $autor,
                $zrodlo,
                $zrodlo === PrzepisZImportu::ZRODLO_URL ? 'json_ld' : 'tekst_pdf',
                new OdczytanyPrzepis('Pierogi domowe', porcje: 4, skladniki: ['500 g mąki'], kroki: ['Zagnieć ciasto.']),
                $zrodlo === PrzepisZImportu::ZRODLO_URL ? 'https://przepisy.example.pl/pierogi' : null,
            );
            $przed = Recipe::query()->count();

            try {
                $kopia = app(ZrobKopieSzkicu::class)->handle($autor, $szkic, (string) Str::uuid7());
                // Fizyczny mutant bez strażnika dochodzi aż do publikacji: nowe ID nie ma pochodzenia.
                $opublikowana = app(PublishRecipe::class)->handle(
                    $autor,
                    ['title' => $kopia->title, 'servings' => 8, 'source_type' => Recipe::SOURCE_OWN, 'visibility' => 'public'],
                    [['text' => '500 g mąki']],
                    [['instruction' => 'Zagnieć ciasto.']],
                    publish: true,
                    existing: $kopia,
                );
                $this->assertSame(Recipe::STATUS_PUBLISHED, $opublikowana->status);
                $this->fail('KOPIA_2800_IMPORT_OMINIETY: szkic z odczytu '.$zrodlo.' został opublikowany bez pochodzenia i sprawdzenia tekstu.');
            } catch (BladDlaCzlowieka $blad) {
                $this->assertSame(ZrobKopieSzkicu::KOMUNIKAT_IMPORT, $blad->getMessage());
            }

            $html = (string) $this->actingAs($autor)->get(route('recipes.drafts.copy', $szkic->getKey()))->assertOk()->getContent();
            $this->assertStringContainsString(ZrobKopieSzkicu::KOMUNIKAT_IMPORT, $html);
            $this->assertStringNotContainsString('action="'.route('recipes.drafts.copy.store', $szkic->getKey()).'"', $html);

            $this->actingAs($autor)->post(route('recipes.drafts.copy.store', $szkic->getKey()), [
                'klucz_kopii' => (string) Str::uuid7(),
            ])->assertRedirect(route('recipes.drafts'))->assertSessionHas('status');

            $this->assertSame($przed, Recipe::query()->count());
            $this->assertSame(0, Recipe::query()->where('kopia_z_id', $szkic->getKey())->count());
            $this->assertNotNull(PrzepisZImportu::query()->find($szkic->getKey()));

            // Oryginał pozostaje edytowalny; porcji nie mylimy ze zgodą na odczyt.
            $zmieniony = app(PublishRecipe::class)->handle(
                $autor,
                ['title' => $szkic->title, 'servings' => 8, 'source_type' => Recipe::SOURCE_OWN, 'visibility' => 'private'],
                [['text' => '500 g mąki']],
                [['instruction' => 'Zagnieć ciasto.']],
                publish: false,
                existing: $szkic->fresh(),
            );
            $this->assertEquals(8, $zmieniony->servings);
            if ($zrodlo === PrzepisZImportu::ZRODLO_URL) {
                $this->assertSame(Recipe::SOURCE_EXTERNAL, $zmieniony->source_type);
                $this->assertSame('https://przepisy.example.pl/pierogi', $zmieniony->source_url);
            }

            try {
                app(PublishRecipe::class)->handle(
                    $autor,
                    ['title' => $zmieniony->title, 'visibility' => 'public'],
                    [['text' => '500 g mąki']],
                    [['instruction' => 'Zagnieć ciasto.']],
                    publish: true,
                    existing: $zmieniony->fresh(),
                );
                $this->fail('KOPIA_2800_IMPORT_OMINIETY: szkic z odczytu wyszedł do ludzi bez sprawdzenia tekstu.');
            } catch (BladDlaCzlowieka $blad) {
                $this->assertSame(StrazImportu::KOMUNIKAT_SPRAWDZ, $blad->getMessage());
            }
        }
    }

    public function test_szkic_z_odczytu_zdjecia_zlecenie_gotowe_tez_odmawia_kopii(): void
    {
        $autor = $this->user('autorka');
        $skan = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $szkic = Recipe::factory()->draft()->create([
            'author_id' => $autor->getKey(), 'visibility' => 'private', 'source_scan_media_id' => $skan->getKey(),
        ]);
        (new ImportPrzepisu)->forceFill([
            'user_id' => $autor->getKey(), 'recipe_id' => $szkic->getKey(),
            'zrodlo' => ImportPrzepisu::ZRODLO_ZDJECIE, 'status' => ImportPrzepisu::STATUS_GOTOWY,
        ])->save();

        try {
            app(ZrobKopieSzkicu::class)->handle($autor, $szkic, (string) Str::uuid7());
            $this->fail('KOPIA_2800_IMPORT_OMINIETY: szkic ze skanu został skopiowany bez zlecenia odczytu.');
        } catch (BladDlaCzlowieka $blad) {
            $this->assertSame(ZrobKopieSzkicu::KOMUNIKAT_IMPORT, $blad->getMessage());
        }

        $this->assertSame(0, Recipe::query()->where('kopia_z_id', $szkic->getKey())->count());
    }

    public function test_zwykly_szkic_nadal_mozna_skopiowac(): void
    {
        $autor = $this->user('autorka');
        $szkic = Recipe::factory()->draft()->create(['author_id' => $autor->getKey(), 'visibility' => 'private']);

        $html = (string) $this->actingAs($autor)->get(route('recipes.drafts.copy', $szkic->getKey()))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('recipes.drafts.copy.store', $szkic->getKey()).'"', $html);

        $this->actingAs($autor)->post(route('recipes.drafts.copy.store', $szkic->getKey()), [
            'klucz_kopii' => (string) Str::uuid7(),
        ])->assertRedirect();

        $this->assertSame(1, Recipe::query()->where('kopia_z_id', $szkic->getKey())->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\ExportFileNames;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Carbon\Carbon;

/** Bieżące przepisy w rzeczywistym ZIP-ie właściciela, nie sam render Blade. */
class EksportArchiwumPrzepisuTest extends EksportWygladStylPaczki
{
    public function test_sam_rok_rodzinny_jest_w_html_i_json_bez_pustej_sekcji_dla_innego_przepisu(): void
    {
        $autor = $this->user('autor_eksportu_roku');
        $rok = Recipe::factory()->for($autor, 'author')->create([
            'title' => 'Pierogi z rodzinnego zeszytu',
            'source_type' => Recipe::SOURCE_FAMILY,
            'source_person' => null,
            'source_note' => null,
            'source_url' => null,
            'family_since_year' => 1974,
        ]);
        $bezZrodla = Recipe::factory()->for($autor, 'author')->create([
            'title' => 'Zupa bez historii źródła',
            'source_person' => null,
            'source_note' => null,
            'source_url' => null,
            'family_since_year' => null,
        ]);

        $paczka = $this->zbudujPaczke($autor);
        $html = $this->zPaczki($paczka, 'przepisy/'.ExportFileNames::recipeFile($rok));
        $this->assertStringContainsString('<h2>Skąd ten przepis</h2>', $html);
        $this->assertStringContainsString('W rodzinie od 1974 roku.', $html, 'EKSPORT_ROK_SAM_W_HTML');
        $this->assertStringNotContainsString('<h2>Skąd ten przepis</h2>', $this->zPaczki(
            $paczka, 'przepisy/'.ExportFileNames::recipeFile($bezZrodla),
        ));

        $dane = json_decode($this->zPaczki($paczka, 'dane.json'), true, 512, JSON_THROW_ON_ERROR);
        $rekord = collect($dane['przepisy'])->sole(fn (array $przepis): bool => $przepis['tytul'] === $rok->title);
        $this->assertSame(1974, $rekord['w_rodzinie_od_roku']);
    }

    public function test_kazde_z_pozostalych_pol_zrodla_samo_otwiera_sekcje(): void
    {
        $autor = $this->user('autor_eksportu_zrodel');
        $przypadki = [
            ['source_person' => 'od mamy', 'oczekiwane' => 'Skąd: od mamy'],
            ['source_note' => 'Zapisane w domowym zeszycie', 'oczekiwane' => 'Zapisane w domowym zeszycie'],
            ['source_url' => 'https://example.org/przepis', 'oczekiwane' => 'Źródło: https://example.org/przepis'],
        ];
        $przepisy = [];

        foreach ($przypadki as $indeks => $przypadek) {
            $przepisy[] = [
                Recipe::factory()->for($autor, 'author')->create([
                    'title' => 'Osobne źródło '.$indeks,
                    'source_person' => $przypadek['source_person'] ?? null,
                    'source_note' => $przypadek['source_note'] ?? null,
                    'source_url' => $przypadek['source_url'] ?? null,
                    'family_since_year' => null,
                ]),
                $przypadek['oczekiwane'],
            ];
        }

        $paczka = $this->zbudujPaczke($autor);
        foreach ($przepisy as [$przepis, $oczekiwane]) {
            $html = $this->zPaczki($paczka, 'przepisy/'.ExportFileNames::recipeFile($przepis));
            $this->assertStringContainsString('<h2>Skąd ten przepis</h2>', $html);
            $this->assertStringContainsString($oczekiwane, $html);
        }
    }

    public function test_ukryty_po_publikacji_nie_jest_szkicem_w_karcie_ani_spisie(): void
    {
        $autor = $this->user('autor_eksportu_stanu');
        $obcy = $this->user('obcy_eksportu_stanu');
        $dataPublikacji = Carbon::parse('2026-09-20 12:00:00', 'UTC');
        $ukryty = Recipe::factory()->for($autor, 'author')->create([
            'title' => 'Ukryty po publikacji', 'published_at' => $dataPublikacji,
        ]);
        $ukryty->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();
        $szkic = Recipe::factory()->for($autor, 'author')->draft()->create(['title' => 'Prawdziwy szkic']);
        $ponownySzkic = Recipe::factory()->for($autor, 'author')->create([
            'title' => 'Szkic po wcześniejszej publikacji',
            'status' => Recipe::STATUS_DRAFT, 'published_at' => $dataPublikacji,
        ]);
        $opublikowany = Recipe::factory()->for($autor, 'author')->create(['title' => 'Publiczny przepis']);
        $prywatny = Recipe::factory()->for($autor, 'author')->create([
            'title' => 'Prywatny opublikowany', 'visibility' => 'private',
        ]);
        $ukrytyBezDaty = Recipe::factory()->for($autor, 'author')->create([
            'title' => 'Ukryty bez historii publikacji',
            'status' => Recipe::STATUS_HIDDEN, 'published_at' => null,
        ]);
        $usuniety = Recipe::factory()->for($autor, 'author')->create(['title' => 'Usunięty przepis']);
        $usuniety->delete();
        $cudzy = Recipe::factory()->for($obcy, 'author')->create(['title' => 'Cudzy przepis']);

        $paczka = $this->zbudujPaczke($autor);
        $strona = $this->zPaczki($paczka, 'przepisy/'.ExportFileNames::recipeFile($ukryty));
        $this->assertStringContainsString('Przepis ukryty', $strona, 'EKSPORT_UKRYTY_NIE_JEST_SZKICEM');
        $this->assertStringContainsString('Opublikowany 20 września 2026.', $strona);
        $this->assertStringNotContainsString('nigdy nie został opublikowany', $strona);

        $spis = $this->zPaczki($paczka, 'index.html');
        $pozycja = $this->elementy($this->dokument($spis), '//li[a[@href="przepisy/'.ExportFileNames::recipeFile($ukryty).'"]]');
        $this->assertCount(1, $pozycja);
        $this->assertStringContainsString('ukryty', $pozycja[0]->textContent, 'EKSPORT_UKRYTY_NIE_JEST_SZKICEM');
        $this->assertStringNotContainsString('szkic', $pozycja[0]->textContent);
        $pozycjaSzkicu = $this->elementy($this->dokument($spis), '//li[a[@href="przepisy/'.ExportFileNames::recipeFile($szkic).'"]]');
        $this->assertCount(1, $pozycjaSzkicu);
        $this->assertStringContainsString('szkic', $pozycjaSzkicu[0]->textContent);

        $htmlSzkicu = $this->zPaczki($paczka, 'przepisy/'.ExportFileNames::recipeFile($szkic));
        $this->assertStringContainsString('To był szkic — nigdy nie został opublikowany', $htmlSzkicu);
        $this->assertStringNotContainsString('Opublikowany ', $htmlSzkicu);
        $htmlPonownegoSzkicu = $this->zPaczki($paczka, 'przepisy/'.ExportFileNames::recipeFile($ponownySzkic));
        $this->assertStringContainsString('Przepis jest teraz szkicem', $htmlPonownegoSzkicu);
        $this->assertStringNotContainsString('nigdy nie został opublikowany', $htmlPonownegoSzkicu);
        $htmlPubliczny = $this->zPaczki($paczka, 'przepisy/'.ExportFileNames::recipeFile($opublikowany));
        $this->assertStringNotContainsString('class="plakietka"', $htmlPubliczny);
        $htmlPrywatny = $this->zPaczki($paczka, 'przepisy/'.ExportFileNames::recipeFile($prywatny));
        $this->assertStringContainsString('Przepis widoczny tylko dla wybranych osób', $htmlPrywatny);
        $this->assertStringContainsString('Opublikowany ', $htmlPrywatny);
        $htmlBezDaty = $this->zPaczki($paczka, 'przepisy/'.ExportFileNames::recipeFile($ukrytyBezDaty));
        $this->assertStringContainsString('Przepis ukryty', $htmlBezDaty);
        $this->assertStringNotContainsString('nigdy nie został opublikowany', $htmlBezDaty);
        $this->assertStringNotContainsString('Opublikowany ', $htmlBezDaty);

        $dane = json_decode($this->zPaczki($paczka, 'dane.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(6, $dane['przepisy']);
        $this->assertNotContains($usuniety->title, array_column($dane['przepisy'], 'tytul'));
        $this->assertNotContains($cudzy->title, array_column($dane['przepisy'], 'tytul'));
    }

    public function test_biezacy_szkic_zachowuje_dwa_rozne_wybory_bez_ilosci_w_json(): void
    {
        $autor = $this->user('autor_eksportu_ilosci');
        $szkic = Recipe::factory()->for($autor, 'author')->draft()->create(['title' => 'Mleko do ciasta']);
        foreach ([true, false] as $pozycja => $bezIlosci) {
            RecipeIngredient::create([
                'recipe_id' => $szkic->getKey(),
                'position' => $pozycja,
                'ingredient_text' => '200 ml mleka',
                'quantity' => null,
                'no_amount' => $bezIlosci,
            ]);
        }
        $this->assertSame(0, $szkic->versions()->count(), 'To ma być szkic bez historycznej migawki.');

        $paczka = $this->zbudujPaczke($autor);
        $dane = json_decode($this->zPaczki($paczka, 'dane.json'), true, 512, JSON_THROW_ON_ERROR);
        $rekord = collect($dane['przepisy'])->sole(fn (array $przepis): bool => $przepis['tytul'] === $szkic->title);
        $this->assertCount(2, $rekord['skladniki']);
        $pierwszy = $rekord['skladniki'][0];
        $drugi = $rekord['skladniki'][1];
        $this->assertSame('200 ml mleka', $pierwszy['zapis']);
        $this->assertSame('200 ml mleka', $drugi['zapis']);
        $this->assertNull($pierwszy['ile']);
        $this->assertNull($drugi['ile']);
        $this->assertArrayHasKey('bez_ilosci', $pierwszy, 'EKSPORT_BIEZACY_BEZ_ILOSCI');
        $this->assertArrayHasKey('bez_ilosci', $drugi, 'EKSPORT_BIEZACY_BEZ_ILOSCI');
        $this->assertSame(true, $pierwszy['bez_ilosci'], 'EKSPORT_BIEZACY_BEZ_ILOSCI');
        $this->assertSame(false, $drugi['bez_ilosci'], 'EKSPORT_BIEZACY_BEZ_ILOSCI');

        // HTML cytuje oba wpisy autora; nie zgaduje za niego „do smaku” (D-232).
        $html = $this->zPaczki($paczka, 'przepisy/'.ExportFileNames::recipeFile($szkic));
        $this->assertSame(2, substr_count($html, '200 ml mleka'));
        $this->assertStringNotContainsString('— do smaku', $html);
    }
}

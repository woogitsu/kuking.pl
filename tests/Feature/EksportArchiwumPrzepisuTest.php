<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\ExportFileNames;
use App\Models\Recipe;

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
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\KreatorPrzepisu\DanePublikacji;
use Tests\TestCase;

/**
 * Issue #1387, krok 5 — mapowanie stanu kreatora na `attributes` dla
 * `PublishRecipe` wydzielone z `persist()` do `DanePublikacji`.
 * Bez renderowania kreatora i bez bazy.
 */
final class DanePublikacjiKreatoraTest extends TestCase
{
    /** @param array<string, mixed> $nadpisz */
    private function atrybuty(array $nadpisz = []): array
    {
        return DanePublikacji::atrybuty(...array_merge([
            'title' => '  Pierogi ruskie  ',
            'summary' => ' Od babci. ',
            'servings' => '4',
            'estimatedCostPln' => '24,50',
            'prepMinutes' => '20',
            'cookMinutes' => '30',
            'difficulty' => 'easy',
            'visibility' => 'followers',
            'sourceType' => 'family',
            'sourcePerson' => ' babcia Zofia ',
            'sourceNote' => ' z zeszytu ',
            'sourceUrl' => ' https://example.com/p ',
            'familySinceYear' => '1975',
            'heroMediaId' => 'hero-1',
            'sourceScanMediaId' => 'scan-1',
            'sprawdzilemOdczyt' => true,
            'odczytSprawdzony' => true,
        ], $nadpisz));
    }

    public function test_mapuje_wszystkie_pola_na_typy_dla_publish_recipe(): void
    {
        $this->assertSame([
            'title' => 'Pierogi ruskie',
            'summary' => 'Od babci.',
            'servings' => 4.0,
            'estimated_cost_pln' => 24.5,
            'prep_minutes' => 20,
            'cook_minutes' => 30,
            'difficulty' => 'easy',
            'visibility' => 'followers',
            'source_type' => 'family',
            'source_person' => 'babcia Zofia',
            'source_note' => 'z zeszytu',
            'source_url' => 'https://example.com/p',
            'family_since_year' => 1975,
            'hero_media_id' => 'hero-1',
            'source_scan_media_id' => 'scan-1',
            'sprawdzilem_odczyt' => true,
            'odczyt_sprawdzony' => true,
        ], $this->atrybuty());
    }

    public function test_puste_i_nieliczbowe_pola_daja_null_a_tytul_zostaje_tekstem(): void
    {
        $a = $this->atrybuty([
            'title' => '   ',
            'summary' => '',
            'servings' => 'kilka',
            'estimatedCostPln' => '',
            'prepMinutes' => 'x',
            'cookMinutes' => ' ',
            'difficulty' => '',
            'sourcePerson' => '  ',
            'sourceNote' => '',
            'sourceUrl' => '',
            'familySinceYear' => '',
            'heroMediaId' => null,
            'sourceScanMediaId' => null,
            'sprawdzilemOdczyt' => false,
            'odczytSprawdzony' => false,
        ]);

        $this->assertSame('', $a['title']);
        foreach (['summary', 'servings', 'estimated_cost_pln', 'prep_minutes', 'cook_minutes', 'difficulty',
            'source_person', 'source_note', 'source_url', 'family_since_year', 'hero_media_id', 'source_scan_media_id'] as $klucz) {
            $this->assertNull($a[$klucz], $klucz);
        }
        $this->assertFalse($a['sprawdzilem_odczyt']);
        $this->assertFalse($a['odczyt_sprawdzony']);
    }

    public function test_porcje_z_przecinkiem_i_widocznosc_bez_zmian(): void
    {
        $a = $this->atrybuty(['servings' => '0,5', 'visibility' => 'zmyslona']);

        $this->assertSame(0.5, $a['servings']);
        // Widoczności nie poprawiamy tutaj — waliduje ją PublishRecipe.
        $this->assertSame('zmyslona', $a['visibility']);
    }
}

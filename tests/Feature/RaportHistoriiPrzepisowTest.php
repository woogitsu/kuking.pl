<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\HistoriePrzepisow;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Historie przepisów” w `kuking:raport` (issue #1045): czy opublikowane
 * przepisy zachowują pochodzenie — licznik, mianownik, procent, bez treści.
 */
class RaportHistoriiPrzepisowTest extends TestCase
{
    use RefreshDatabase;

    private const TERAZ = '2026-09-08 12:00:00';

    private User $autorka;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TERAZ, 'UTC'));
        $this->autorka = $this->user('autorka');
    }

    /** @param  array<string, mixed>  $atrybuty */
    private function przepis(array $atrybuty = [], ?User $autor = null): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => ($autor ?? $this->autorka)->getKey(),
            'published_at' => CarbonImmutable::parse(self::TERAZ, 'UTC')->subDay(),
            ...$atrybuty,
        ]);
    }

    private function skan(string $status = Media::STATUS_READY): string
    {
        return (string) Media::factory()->create([
            'owner_id' => $this->autorka->getKey(),
            'status' => $status,
        ])->getKey();
    }

    /** @return array{przepisy: int, publiczne: int, liczniki: array<string, int>, procenty: array<string, float|null>} */
    private function wynik(): array
    {
        return app(HistoriePrzepisow::class)->policz();
    }

    public function test_kazdy_slad_liczy_sie_osobno(): void
    {
        $this->przepis(['source_person' => 'od cioci Zosi']);
        $this->przepis(['source_note' => 'Na każde imieniny.']);
        $this->przepis(['family_since_year' => 1968]);
        $this->przepis(['source_scan_media_id' => $this->skan()]);
        $this->przepis();

        $w = $this->wynik();

        $this->assertSame(5, $w['przepisy']);
        $this->assertSame(1, $w['liczniki']['od_kogo']);
        $this->assertSame(1, $w['liczniki']['historia']);
        $this->assertSame(1, $w['liczniki']['rok_rodzinny']);
        $this->assertSame(1, $w['liczniki']['skan']);
        $this->assertSame(4, $w['liczniki']['ma_slad']);
        $this->assertSame(0, $w['liczniki']['rodzinny']);
    }

    public function test_puste_napisy_i_same_spacje_nie_sa_historia(): void
    {
        // KONTROLA UJEMNA: `IS NOT NULL` policzyłoby oba te przepisy.
        $this->przepis(['source_person' => '   ', 'source_note' => '']);
        $this->przepis(['source_person' => "\t\n", 'source_note' => "  \n "]);

        $w = $this->wynik();

        $this->assertSame(2, $w['przepisy']);
        $this->assertSame(0, $w['liczniki']['od_kogo']);
        $this->assertSame(0, $w['liczniki']['historia']);
        $this->assertSame(0, $w['liczniki']['ma_slad']);
    }

    public function test_sam_typ_rodzinny_nie_jest_zachowana_historia(): void
    {
        // KONTROLA UJEMNA: samo wybranie „Rodzinny” bez żadnego śladu.
        $this->przepis(['source_type' => Recipe::SOURCE_FAMILY, 'source_person' => ' ']);
        $this->przepis(['source_type' => Recipe::SOURCE_FAMILY, 'family_since_year' => 1980]);
        // Ślad bez typu „Rodzinny” liczy się do `ma_slad`, nie do węższego.
        $this->przepis(['source_type' => Recipe::SOURCE_ADAPTATION, 'source_person' => 'z gazety']);

        $w = $this->wynik();

        $this->assertSame(2, $w['liczniki']['rodzinny']);
        $this->assertSame(2, $w['liczniki']['ma_slad']);
        $this->assertSame(1, $w['liczniki']['rodzinny_ze_sladem']);
    }

    public function test_odlaczony_albo_niegotowy_skan_nie_jest_zachowanym_skanem(): void
    {
        $this->przepis(['source_scan_media_id' => $this->skan()]);
        $this->przepis(['source_scan_media_id' => $this->skan(Media::STATUS_DELETED)]);
        $this->przepis(['source_scan_media_id' => $this->skan(Media::STATUS_PENDING)]);
        $this->przepis(['source_scan_media_id' => $this->skan(Media::STATUS_REJECTED)]);

        // Zdjęcie usunięte z bazy — FK `nullOnDelete` odpina je od przepisu.
        $usuniety = $this->przepis(['source_scan_media_id' => $this->skan()]);
        Media::query()->whereKey($usuniety->source_scan_media_id)->delete();

        $w = $this->wynik();

        $this->assertSame(5, $w['przepisy']);
        $this->assertSame(1, $w['liczniki']['skan']);
        $this->assertSame(1, $w['liczniki']['ma_slad']);
    }

    public function test_szkic_ukryty_usuniety_i_stary_przepis_nie_zawyzaja_wyniku(): void
    {
        $this->przepis(['source_person' => 'od babci']);

        Recipe::factory()->draft()->family()->create(['author_id' => $this->autorka->getKey()]);
        $this->przepis(['status' => Recipe::STATUS_HIDDEN, 'source_person' => 'od babci']);
        $this->przepis(['status' => Recipe::STATUS_REMOVED, 'source_person' => 'od babci']);
        $this->przepis(['source_person' => 'od babci'])->delete();
        $this->przepis([
            'source_person' => 'od babci',
            'published_at' => CarbonImmutable::parse(self::TERAZ, 'UTC')->subDays(HistoriePrzepisow::DNI)->subMinute(),
        ]);

        $w = $this->wynik();

        $this->assertSame(1, $w['przepisy']);
        $this->assertSame(1, $w['liczniki']['od_kogo']);
    }

    public function test_wyklucza_konta_testowe_i_zalazkowe(): void
    {
        config(['kuking.account.test_usernames' => ['qa_wewnetrzne']]);
        $this->przepis(['source_person' => 'od mamy'], $this->user('qa_wewnetrzne'));
        $this->przepis(['source_person' => 'od mamy'], $this->user('persona', ['is_seeded' => true]));
        $this->przepis();

        $w = $this->wynik();

        $this->assertSame(1, $w['przepisy']);
        $this->assertSame(0, $w['liczniki']['od_kogo']);
    }

    public function test_prywatne_i_dla_obserwujacych_naleza_do_miernika(): void
    {
        $this->przepis(['visibility' => 'public']);
        $this->przepis(['visibility' => 'followers', 'source_note' => 'Po dziadku.']);
        $this->przepis(['visibility' => 'private', 'source_person' => 'od babci']);

        $w = $this->wynik();

        $this->assertSame(3, $w['przepisy']);
        $this->assertSame(1, $w['publiczne']);
        $this->assertSame(2, $w['liczniki']['ma_slad']);
    }

    public function test_mala_proba_nie_ma_procentow_a_pelna_ma(): void
    {
        for ($i = 0; $i < HistoriePrzepisow::MINIMUM_PRZEPISOW - 1; $i++) {
            $this->przepis();
        }

        // KONTROLA UJEMNA: 0 z 19 to nie jest „0%” rozstrzygające o produkcie.
        $this->assertNull($this->wynik()['procenty']['ma_slad']);

        $this->przepis(['source_person' => 'od sąsiadki']);
        $w = $this->wynik();

        $this->assertSame(HistoriePrzepisow::MINIMUM_PRZEPISOW, $w['przepisy']);
        $this->assertSame(5.0, $w['procenty']['od_kogo']);
        $this->assertSame(0.0, $w['procenty']['rodzinny']);
    }

    public function test_komenda_pokazuje_liczniki_bez_tresci_pol(): void
    {
        $przepis = $this->przepis([
            'source_type' => Recipe::SOURCE_FAMILY,
            'source_person' => 'od pani Genowefy Kowalskiej',
            'source_note' => 'Sekretny składnik to majeranek.',
        ]);
        $this->przepis();

        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Przepisy w mierniku: 2 (w tym publicznych: 2).')
            ->expectsOutputToContain('Za mało danych na procenty')
            ->expectsOutputToContain('„Od kogo albo skąd masz ten przepis” wypełnione: 1 z 2')
            ->expectsOutputToContain('„Rodzinny” i choć jeden konkretny ślad: 1 z 2')
            ->doesntExpectOutputToContain('Genowefy')
            ->doesntExpectOutputToContain('majeranek')
            ->doesntExpectOutputToContain($przepis->title)
            ->doesntExpectOutputToContain((string) $przepis->getKey())
            ->doesntExpectOutputToContain('autorka');
    }

    public function test_pusta_baza_mowi_to_wprost(): void
    {
        $this->artisan('kuking:raport')
            ->assertSuccessful()
            ->expectsOutputToContain('Brak opublikowanych przepisów w tym okresie')
            ->doesntExpectOutputToContain('0 z 0');
    }
}

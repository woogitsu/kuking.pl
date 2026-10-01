<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Issue #2480: sam skan jest treścią sekcji pochodzenia. */
final class SkanKartkiBezHistoriiNaPrzepisieTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_wlasciciel_widzi_sam_skan_na_zwyklej_stronie_prywatnego_przepisu(): void
    {
        $autor = $this->user('kartka');
        $skan = Media::factory()->create(['owner_id' => $autor->getKey()]);
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'private',
            'source_person' => null,
            'source_note' => null,
            'source_scan_media_id' => $skan->getKey(),
        ]);

        $html = (string) $this->actingAs($autor)->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();
        $historia = $this->sekcjaPochodzenia($html);

        $this->assertNotSame('', $historia, 'Sam skan musi otwierać sekcję pochodzenia przepisu.');
        $this->assertStringContainsString('Skąd ten przepis', $historia);
        $this->assertStringContainsString('Kartka, z której jest ten przepis.', $historia);
        $this->assertStringContainsString($skan->url('feed'), $historia, 'Sekcja ma używać przetworzonego wariantu z x-photo.');
        $this->assertStringNotContainsString($skan->object_key, $html, 'Oryginał zdjęcia nie może trafić do HTML.');

        // Tryb dla pomocnika świadomie pomija historię razem ze skanem.
        $dlaPomocnika = (string) $this->get(route('recipes.show', ['recipe' => $przepis->slug, 'druk' => 1, 'dla' => 'pomocnika']))->assertOk()->getContent();
        $this->assertSame('', $this->sekcjaPochodzenia($dlaPomocnika));

        // Policy przepisu odcina obcego, a Policy zdjęcia odcina jego wariant.
        $this->actingAs($this->user('obcy'))->get(route('recipes.show', $przepis->slug))->assertForbidden();
        $this->get($skan->url('feed'))->assertNotFound();
    }

    #[Test]
    public function test_bez_skanu_i_tekstu_nie_ma_pustej_sekcji_a_sam_tekst_ja_pokazuje(): void
    {
        $autor = $this->user('historia');
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'source_person' => null,
            'source_note' => null,
            'source_scan_media_id' => null,
        ]);

        $this->assertSame('', $this->sekcjaPochodzenia((string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent()));

        $przepis->forceFill(['source_note' => 'Zapisane w starym zeszycie.'])->save();
        $historia = $this->sekcjaPochodzenia((string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent());
        $this->assertStringContainsString('Zapisane w starym zeszycie.', $historia);
        $this->assertStringNotContainsString('Kartka, z której jest ten przepis.', $historia);
    }

    #[Test]
    public function test_sam_skan_bez_wariantu_zachowuje_komunikat_przygotowywania(): void
    {
        $autor = $this->user('czekanie');
        $skan = Media::factory()->pending()->create(['owner_id' => $autor->getKey()]);
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'source_person' => null,
            'source_note' => null,
            'source_scan_media_id' => $skan->getKey(),
        ]);

        $historia = $this->sekcjaPochodzenia((string) $this->actingAs($autor)->get(route('recipes.show', $przepis->slug))->assertOk()->getContent());
        $this->assertStringContainsString('Twoje zdjęcie się jeszcze przygotowuje.', $historia);
        $this->assertStringNotContainsString($skan->object_key, $historia);
    }

    #[Test]
    public function test_odrzucony_skan_bez_wariantu_pokazuje_wlascicielowi_blad_bez_ujawniania_pliku(): void
    {
        $autor = $this->user('odrzucony_skan');
        $skan = Media::factory()->state(fn () => [
            'status' => Media::STATUS_REJECTED,
            'metadata' => ['variants' => []],
        ])->create(['owner_id' => $autor->getKey()]);
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'visibility' => 'private',
            'source_person' => null,
            'source_note' => null,
            'source_scan_media_id' => $skan->getKey(),
        ]);

        $html = (string) $this->actingAs($autor)->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();
        $historia = $this->sekcjaPochodzenia($html);
        $adresWariantu = route('media.show', ['media' => $skan->getKey(), 'wariant' => 'feed']);

        $this->assertStringContainsString('Skąd ten przepis', $historia);
        $this->assertStringContainsString('Nie udało się przygotować tego zdjęcia.', $historia);
        $this->assertStringContainsString('Kartka, z której jest ten przepis.', $historia);
        $this->assertStringNotContainsString('<img', $historia);
        $this->assertStringNotContainsString($adresWariantu, $html);
        $this->assertStringNotContainsString($skan->object_key, $html);

        $dlaPomocnika = (string) $this->get(route('recipes.show', ['recipe' => $przepis->slug, 'druk' => 1, 'dla' => 'pomocnika']))->assertOk()->getContent();
        $this->assertSame('', $this->sekcjaPochodzenia($dlaPomocnika));
        $this->assertStringNotContainsString($adresWariantu, $dlaPomocnika);
        $this->assertStringNotContainsString($skan->object_key, $dlaPomocnika);
    }

    private function sekcjaPochodzenia(string $html): string
    {
        preg_match('~<section class="recipe-story">(.*?)</section>~s', $html, $trafienie);

        return $trafienie[1] ?? '';
    }
}

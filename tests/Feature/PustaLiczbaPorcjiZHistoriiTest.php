<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Jawne „nie podano” porcji i brak klucza to różne stany (#2877, D-333 #2525). */
final class PustaLiczbaPorcjiZHistoriiTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private Recipe $przepis;

    private RecipeVersion $pierwsza;

    private RecipeVersion $druga;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function wersje(?int $dawne, ?int $dzisiejsze, bool $zmianaOpisu = false): void
    {
        $this->autor = $this->user('autorka');
        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(), 'title' => 'Zupa z marchewki',
            'summary' => 'Opis pierwszej wersji', 'servings' => $dawne,
        ]);
        $pierwsza = app(SnapshotRecipeVersion::class)->handle($this->przepis->fresh(), $this->autor, 'start');
        $this->assertInstanceOf(RecipeVersion::class, $pierwsza);
        $this->pierwsza = $pierwsza;
        $this->assertArrayHasKey('servings', $pierwsza->snapshot);
        $this->assertEquals($dawne, $pierwsza->snapshot['servings']);

        $this->przepis->update([
            'servings' => $dzisiejsze,
            'summary' => $zmianaOpisu ? 'Opis drugiej wersji' : 'Opis pierwszej wersji',
        ]);
        $druga = app(SnapshotRecipeVersion::class)->handle($this->przepis->fresh(), $this->autor, 'poprawka');
        $this->assertInstanceOf(RecipeVersion::class, $druga);
        $this->druga = $druga;
    }

    private function podglad(): \DOMXPath
    {
        $przed = $this->stan();
        $odpowiedz = $this->actingAs($this->autor)->get($this->adres())->assertOk();
        $this->assertSame($przed, $this->stan(), 'PORCJE_2877_PODGLAD_ODCZYTOWY: GET zmienił przepis lub wersje.');
        $dom = new \DOMDocument;
        @$dom->loadHTML((string) $odpowiedz->getContent());

        return new \DOMXPath($dom);
    }

    private function adres(): string
    {
        return route('recipes.history.apply', [$this->przepis->slug, 1]);
    }

    private function zastosuj(): TestResponse
    {
        return $this->actingAs($this->autor)->from($this->adres())
            ->post(route('recipes.history.apply.store', [$this->przepis->slug, 1]), [
                'sekcje' => ['dane'], 'rewizja' => $this->przepis->fresh()->content_revision,
            ]);
    }

    /** @return array<string, mixed> */
    private function stan(): array
    {
        return [
            'przepis' => $this->przepis->fresh()->getRawOriginal(),
            'wersje' => RecipeVersion::query()->where('recipe_id', $this->przepis->getKey())
                ->orderBy('version_number')->get()->map(fn (RecipeVersion $wersja): array => $wersja->getRawOriginal())->all(),
        ];
    }

    private function wiersz(\DOMXPath $xpath, string $etykieta): \DOMElement
    {
        $wiersze = $xpath->query('//section[@aria-labelledby="pp-dane"]//dt[starts-with(normalize-space(.), "'.$etykieta.' —")]/parent::div');
        $this->assertNotFalse($wiersze);
        $this->assertCount(1, $wiersze, 'PORCJE_2877_WIERSZ: pomiar musi znaleźć dokładnie jeden wiersz danych.');
        $wiersz = $wiersze->item(0);
        $this->assertInstanceOf(\DOMElement::class, $wiersz);

        return $wiersz;
    }

    private function sprawdzNowaWersje(?int $oczekiwane): void
    {
        $dawne = [$this->pierwsza->fresh()->getRawOriginal(), $this->druga->fresh()->getRawOriginal()];
        $rewizja = $this->przepis->fresh()->content_revision;
        $this->zastosuj()->assertRedirect(route('recipes.history', $this->przepis->slug))
            ->assertSessionHas('status_rodzaj', 'sukces');
        $po = $this->przepis->fresh();
        if ($oczekiwane === null) {
            $this->assertNull($po->servings, 'PORCJE_2877_NULL_ZAPIS: zapis pozostawił dzisiejszą liczbę zamiast jawnego NULL.');
        } else {
            $this->assertEquals($oczekiwane, $po->servings, 'PORCJE_2877_LICZBA: znana dawna liczba nie wróciła.');
        }
        $this->assertSame($rewizja + 1, $po->content_revision);
        $nowa = RecipeVersion::query()->where('recipe_id', $po->getKey())->where('version_number', 3)->sole();
        $this->assertArrayHasKey('servings', $nowa->snapshot);
        if ($oczekiwane === null) {
            $this->assertNull($nowa->snapshot['servings'], 'PORCJE_2877_NULL_MIGAWKA: nowa wersja zgubiła jawne NULL.');
        } else {
            $this->assertEquals($oczekiwane, $nowa->snapshot['servings']);
        }
        $this->assertSame('Przywrócono treść wersji 1 jako nową poprawkę', $nowa->change_note);
        $this->assertSame(3, RecipeVersion::query()->where('recipe_id', $po->getKey())->count());
        $this->assertSame($dawne, [$this->pierwsza->fresh()->getRawOriginal(), $this->druga->fresh()->getRawOriginal()], 'PORCJE_2877_NIEZMIENNE: dawne wersje zostały podmienione.');
    }

    public function test_samo_jawne_null_jest_dostepne_w_podgladzie_i_wraca_przez_post(): void
    {
        $this->wersje(null, 6);
        $xpath = $this->podglad();
        $wiersz = $this->wiersz($xpath, 'Ilość porcji');
        $this->assertStringContainsString('zmieni się', $wiersz->textContent, 'PORCJE_2877_NULL_PODGLAD: jawne NULL nazwano brakiem danych.');
        $this->assertStringContainsString('Dziś: 6', $wiersz->textContent);
        $this->assertStringContainsString('Z wersji 1: nie podano', $wiersz->textContent);
        $this->assertCount(1, $xpath->query('//input[@id="f-sekcja-dane" and @value="dane"]'), 'PORCJE_2877_NULL_PODGLAD: same porcje muszą udostępnić wybór danych.');
        $this->sprawdzNowaWersje(null);
    }

    public function test_zastosowanie_opisu_przywraca_tez_jawne_null_porcji(): void
    {
        $this->wersje(null, 6, true);
        $this->podglad();
        $this->sprawdzNowaWersje(null);
        $this->assertSame('Opis pierwszej wersji', $this->przepis->fresh()->summary);
    }

    public function test_dawna_liczba_przy_dzisiejszym_null_nadal_wraca(): void
    {
        $this->wersje(6, null);
        $xpath = $this->podglad();
        $wiersz = $this->wiersz($xpath, 'Ilość porcji');
        $this->assertStringContainsString('Dziś: nie podano', $wiersz->textContent);
        $this->assertStringContainsString('Z wersji 1: 6', $wiersz->textContent);
        $this->sprawdzNowaWersje(6);
    }

    public function test_brak_klucza_zostawia_dzisiejsze_porcje_przy_przywroceniu_opisu(): void
    {
        $this->wersje(null, 6, true);
        $snapshot = $this->pierwsza->snapshot;
        unset($snapshot['servings']);
        $this->staraMigawka($snapshot);
        $xpath = $this->podglad();
        $this->assertStringContainsString('brak danych w tej wersji', $this->wiersz($xpath, 'Ilość porcji')->textContent);
        $this->sprawdzNowaWersje(6);
        $this->assertSame('Opis pierwszej wersji', $this->przepis->fresh()->summary);
        $this->assertArrayNotHasKey('servings', $this->pierwsza->fresh()->snapshot);
    }

    public function test_sam_brak_klucza_nie_tworzy_zmiany_ani_wersji(): void
    {
        $this->wersje(null, 6);
        $snapshot = $this->pierwsza->snapshot;
        unset($snapshot['servings']);
        $this->staraMigawka($snapshot);
        $this->sprawdzBrakZmiany('brak danych w tej wersji');
    }

    public function test_null_po_obu_stronach_nie_tworzy_zmiany_ani_wersji(): void
    {
        $this->wersje(null, 6);
        $this->przepis->update(['servings' => null]);
        $this->sprawdzBrakZmiany('bez zmian');
    }

    private function sprawdzBrakZmiany(string $stan): void
    {
        $xpath = $this->podglad();
        $this->assertStringContainsString($stan, $this->wiersz($xpath, 'Ilość porcji')->textContent);
        $this->assertCount(0, $xpath->query('//input[@id="f-sekcja-dane"]'));
        $przed = $this->stan();
        $this->zastosuj()->assertRedirect($this->adres())->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame($przed, $this->stan(), 'PORCJE_2877_BEZ_PUSTEJ_WERSJI: brak różnicy zapisał zmianę.');
    }

    public function test_ochrona_pustej_nazwy_zostaje_przy_przywroceniu_null_porcji(): void
    {
        $this->wersje(null, 6, true);
        $snapshot = $this->pierwsza->snapshot;
        $snapshot['title'] = null;
        $this->staraMigawka($snapshot);
        $xpath = $this->podglad();
        $this->assertStringContainsString('brak danych w tej wersji', $this->wiersz($xpath, 'Nazwa')->textContent);
        $this->sprawdzNowaWersje(null);
        $this->assertSame('Zupa z marchewki', $this->przepis->fresh()->title);
        $this->assertSame('Zupa z marchewki', RecipeVersion::query()->where('recipe_id', $this->przepis->getKey())->where('version_number', 3)->sole()->snapshot['title']);
        $this->assertNull($this->pierwsza->fresh()->snapshot['title']);
    }

    /** @param array<string, mixed> $snapshot */
    private function staraMigawka(array $snapshot): void
    {
        // Fixture starszego kształtu danych, poza niezmienną domeną wersji.
        DB::table('recipe_versions')->where('id', $this->pierwsza->getKey())
            ->update(['snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)]);
    }
}

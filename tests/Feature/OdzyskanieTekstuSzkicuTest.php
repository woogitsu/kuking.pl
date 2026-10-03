<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\Odzyskiwanie\PrzedawnionePunktyOdzyskaniaSzkicu;
use App\Domain\Recipes\Odzyskiwanie\PunktOdzyskaniaSzkicu;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\DraftRestorePoint;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Odzyskanie wcześniejszego tekstu prywatnego szkicu po pomyłce (#2512, V2).
 *
 * Każda scena ujemna ma obok kontrolę dodatnią. Testy sprawdzają ZAPISANE DANE
 * (tekst, status, liczbę wierszy), nie tylko komunikat.
 *
 * @bez-kontroli-dodatniej Test uruchamia prawdziwe akcje na bazie i asertuje na zapisanych danych, a nie na treści źródła; migracja i sprzątanie mają własne kontrole dodatnie w tych samych testach.
 */
class OdzyskanieTekstuSzkicuTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_10_07_212512_create_draft_restore_points_table.php';

    private User $basia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basia = $this->user('basia');
    }

    protected function tearDown(): void
    {
        putenv('KUKING_ROLLBACK_KASUJE_PUNKTY_ODZYSKANIA_SZKICU');
        parent::tearDown();
    }

    public function test_otwarcie_szkicu_robi_jeden_punkt_a_ponowne_otwarcie_go_nie_podmienia(): void
    {
        $szkic = $this->szkic();

        $this->actingAs($this->basia)->get(route('recipes.create', ['szkic' => $szkic->getKey()]))->assertOk();
        $this->assertSame(1, DraftRestorePoint::query()->count());
        $this->assertSame('Pierogi babci', DraftRestorePoint::query()->firstOrFail()->snapshot['title']);

        // Pomyłka, zapisana autozapisem, i ponowne otwarcie szkicu.
        $this->zepsuj($szkic);
        $this->actingAs($this->basia)->get(route('recipes.create', ['szkic' => $szkic->getKey()]))->assertOk();

        $punkty = DraftRestorePoint::query()->get();
        $this->assertCount(1, $punkty);
        $this->assertSame('Pierogi babci', $punkty[0]->snapshot['title'], 'Ponowne otwarcie zastąpiło dobrą kopię uszkodzonym stanem.');
        $this->assertSame('Dwa kroki farszu', $punkty[0]->snapshot['steps'][0]['instruction']);
    }

    public function test_autozapis_i_zapis_szkicu_nie_tworza_punktu_ani_wersji(): void
    {
        $szkic = $this->szkic();

        app(PublishRecipe::class)->handle(
            author: $this->basia,
            attributes: ['title' => 'Nowy tytuł'],
            ingredients: [['text' => 'mąka']],
            steps: [['id' => null, 'instruction' => 'Wymieszaj.', 'timer_minutes' => '', 'media_id' => null, 'section_name' => null]],
            publish: false,
            existing: $szkic,
            wersjaPoprawki: false,
        );

        $this->assertSame(0, DraftRestorePoint::query()->count());
        $this->assertSame(0, RecipeVersion::query()->count());
    }

    public function test_podglad_pokazuje_roznice_i_niczego_nie_zmienia(): void
    {
        $szkic = $this->szkic();
        $this->otworz($szkic);
        $this->zepsuj($szkic);
        $przed = $this->stanSzkicu($szkic);

        $html = $this->actingAs($this->basia)->get(route('recipes.drafts.restore.show', $szkic->getKey()))
            ->assertOk()
            ->assertSee('Przywróć tekst z kopii')
            ->assertSee('Nie zawiera zdjęć')
            ->getContent();

        $this->assertStringContainsString('Dwa kroki farszu', $html);
        $this->assertStringContainsString('Pierogi babci', $html);
        $this->assertSame($przed, $this->stanSzkicu($szkic));
        $this->assertSame(1, DraftRestorePoint::query()->count());
    }

    public function test_przywrocenie_oddaje_tekst_zostaje_szkicem_i_mozna_je_cofnac(): void
    {
        $szkic = $this->szkic();
        $this->otworz($szkic);
        $this->zepsuj($szkic);
        $zepsuty = $this->stanSzkicu($szkic);

        $this->przywroc($szkic)->assertRedirect(route('recipes.create', ['szkic' => $szkic->getKey()]));

        $odzyskany = $this->stanSzkicu($szkic);
        $this->assertSame('Pierogi babci', $odzyskany['title']);
        $this->assertSame(['Dwa kroki farszu', 'Ugotuj.'], $odzyskany['kroki']);
        $this->assertSame([300, null], $odzyskany['minutniki']);
        $this->assertSame(['ciasto: mąka', 'ciasto: woda', 'farsz: sól (bez ilości)'], $odzyskany['skladniki']);
        $this->assertSame('od cioci Zosi', $odzyskany['pochodzenie']);

        // Nadal prywatny szkic: bez publikacji, wersji, wykonania i powiadomienia.
        $szkic->refresh();
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->status);
        $this->assertNull($szkic->published_at);
        $this->assertSame(0, RecipeVersion::query()->count());
        $this->assertSame(0, DB::table('cooked_events')->count());
        $this->assertSame(0, DB::table('notifications')->count());

        // Punkt trzyma teraz zastąpiony tekst; to samo przywrócenie wraca do niego.
        $punkt = DraftRestorePoint::query()->firstOrFail();
        $this->assertSame('Pierogi', $punkt->snapshot['title']);
        $this->przywroc($szkic);
        $this->assertSame($zepsuty['title'], $this->stanSzkicu($szkic)['title']);
        $this->assertSame($zepsuty['kroki'], $this->stanSzkicu($szkic)['kroki']);
    }

    public function test_cudze_konto_nie_widzi_ani_nie_przywraca_kopii(): void
    {
        $szkic = $this->szkic();
        $this->otworz($szkic);
        $this->zepsuj($szkic);
        $obca = $this->user('obca');
        $przed = $this->stanSzkicu($szkic);

        $this->actingAs($obca)->get(route('recipes.drafts.restore.show', $szkic->getKey()))->assertForbidden();
        $this->actingAs($obca)->post(route('recipes.drafts.restore', $szkic->getKey()), ['rewizja' => 99, 'znacznik' => 'x'])->assertForbidden();
        $this->assertSame($przed, $this->stanSzkicu($szkic));

        // Kontrola dodatnia: autor tę samą kopię widzi.
        $this->actingAs($this->basia)->get(route('recipes.drafts.restore.show', $szkic->getKey()))->assertOk();
    }

    public function test_zmiana_szkicu_po_otwarciu_podgladu_to_konflikt_a_nowa_praca_zostaje(): void
    {
        $szkic = $this->szkic();
        $this->otworz($szkic);
        $this->zepsuj($szkic);

        $podglad = $this->actingAs($this->basia)->get(route('recipes.drafts.restore.show', $szkic->getKey()))->getContent();
        preg_match('/name="rewizja" value="(\d+)"/', $podglad, $r);
        preg_match('/name="znacznik" value="([^"]+)"/', $podglad, $z);

        // Druga karta zapisuje nowszy tekst.
        $this->zepsuj($szkic, 'Praca z drugiej karty');
        $nowa = $this->stanSzkicu($szkic);

        $this->actingAs($this->basia)
            ->from(route('recipes.drafts.restore.show', $szkic->getKey()))
            ->post(route('recipes.drafts.restore', $szkic->getKey()), ['rewizja' => $r[1], 'znacznik' => $z[1]])
            ->assertRedirect(route('recipes.drafts.restore.show', $szkic->getKey()))
            ->assertSessionHas('status_rodzaj', 'blad');

        $this->assertSame($nowa, $this->stanSzkicu($szkic), 'Stare żądanie nadpisało nowszą pracę.');

        // Kontrola dodatnia: z aktualną rewizją to samo żądanie działa.
        $this->przywroc($szkic);
        $this->assertSame('Pierogi babci', $this->stanSzkicu($szkic)['title']);
    }

    public function test_podmieniona_kopia_to_konflikt(): void
    {
        $szkic = $this->szkic();
        $this->otworz($szkic);
        $this->zepsuj($szkic);

        $this->actingAs($this->basia)->post(route('recipes.drafts.restore', $szkic->getKey()), [
            'rewizja' => $szkic->fresh()->content_revision,
            'znacznik' => '2000-01-01T00:00:00.000000Z',
        ])->assertRedirect(route('recipes.drafts.restore.show', $szkic->getKey()));

        $this->assertSame('Pierogi', $this->stanSzkicu($szkic)['title']);
    }

    public function test_przywrocenie_nie_odpina_aktualnego_zdjecia_kroku_ale_zdjecie_z_kopii_wraca(): void
    {
        $szkic = $this->szkic();
        $zdjecie = Media::factory()->create(['owner_id' => $this->basia->getKey()]);
        $szkic->steps()->where('position', 0)->update(['media_id' => $zdjecie->getKey()]);
        $this->otworz($szkic);

        // Pomyłka zmienia tekst kroku, ale zdjęcie zostaje przy kroku.
        $szkic->steps()->where('position', 0)->update(['instruction' => 'Pusty krok']);
        $szkic->forceFill(['content_revision' => $szkic->content_revision + 1])->save();

        $this->przywroc($szkic);
        $this->assertSame('Dwa kroki farszu', $szkic->steps()->where('position', 0)->value('instruction'));
        $this->assertSame($zdjecie->getKey(), $szkic->steps()->where('position', 0)->value('media_id'), 'Przywrócenie odpięło zdjęcie.');

        // Teraz autor dodaje zdjęcie do kroku 1 — kopia go nie zna.
        $drugie = Media::factory()->create(['owner_id' => $this->basia->getKey()]);
        $szkic->steps()->where('position', 1)->update(['media_id' => $drugie->getKey(), 'instruction' => 'Inny tekst']);
        $szkic->forceFill(['content_revision' => $szkic->content_revision + 1])->save();
        $przed = $this->stanSzkicu($szkic);

        $this->actingAs($this->basia)->get(route('recipes.drafts.restore.show', $szkic->getKey()))
            ->assertOk()
            ->assertSee('Przywrócenie musiałoby je odpiąć')
            ->assertDontSee('Przywróć tekst z kopii');
        $this->przywroc($szkic)->assertRedirect(route('recipes.drafts.restore.show', $szkic->getKey()));
        $this->assertSame($przed, $this->stanSzkicu($szkic));
        $this->assertSame($drugie->getKey(), $szkic->steps()->where('position', 1)->value('media_id'));
    }

    public function test_brak_kopii_wygasla_kopia_i_opublikowany_przepis(): void
    {
        $szkic = $this->szkic();

        // Brak kopii: czytelne przekierowanie, nic się nie dzieje.
        $this->actingAs($this->basia)->get(route('recipes.drafts.restore.show', $szkic->getKey()))
            ->assertRedirect(route('recipes.create', ['szkic' => $szkic->getKey()]));

        $this->otworz($szkic);
        $this->zepsuj($szkic);
        $this->actingAs($this->basia)->get(route('recipes.drafts.restore.show', $szkic->getKey()))->assertOk();

        DraftRestorePoint::query()->update(['taken_at' => now()->subDays(15)]);
        $this->actingAs($this->basia)->get(route('recipes.drafts.restore.show', $szkic->getKey()))
            ->assertRedirect(route('recipes.create', ['szkic' => $szkic->getKey()]));
        $this->przywroc($szkic);
        $this->assertSame('Pierogi', $this->stanSzkicu($szkic)['title'], 'Przywrócono wygasłą kopię.');

        // Wygasła kopia nie blokuje nowej przy kolejnym otwarciu.
        $this->otworz($szkic);
        $this->assertSame('Pierogi', DraftRestorePoint::query()->firstOrFail()->snapshot['title']);

        $szkic->forceFill(['status' => Recipe::STATUS_PUBLISHED, 'published_at' => now()])->save();
        $this->actingAs($this->basia)->get(route('recipes.drafts.restore.show', $szkic->getKey()))->assertForbidden();
    }

    public function test_link_w_kreatorze_pojawia_sie_dopiero_gdy_kopia_sie_roznie(): void
    {
        $szkic = $this->szkic();

        $this->actingAs($this->basia)->get(route('recipes.create', ['szkic' => $szkic->getKey()]))
            ->assertOk()
            ->assertDontSee('Zobacz wcześniejszy tekst');

        $this->zepsuj($szkic);

        $this->actingAs($this->basia)->get(route('recipes.create', ['szkic' => $szkic->getKey()]))
            ->assertOk()
            ->assertSee('Zobacz wcześniejszy tekst')
            ->assertSee(route('recipes.drafts.restore.show', $szkic->getKey()), false);
    }

    public function test_sprzatanie_kasuje_stare_i_nieszkicowe_punkty_a_swieze_zostawia(): void
    {
        $swiezy = $this->szkic('Świeży');
        $stary = $this->szkic('Stary');
        $opublikowany = $this->szkic('Opublikowany');
        foreach ([$swiezy, $stary, $opublikowany] as $szkic) {
            $this->otworz($szkic);
        }
        DraftRestorePoint::query()->where('recipe_id', $stary->getKey())->update(['taken_at' => now()->subDays(15)]);
        $opublikowany->forceFill(['status' => Recipe::STATUS_PUBLISHED, 'published_at' => now()])->save();

        $this->assertSame(2, app(PrzedawnionePunktyOdzyskaniaSzkicu::class)->posprzataj(naSucho: true));
        $this->assertSame(3, DraftRestorePoint::query()->count(), 'Na sucho nic nie wolno kasować.');

        $this->assertSame(2, app(PrzedawnionePunktyOdzyskaniaSzkicu::class)->posprzataj());
        $this->assertSame([$swiezy->getKey()], DraftRestorePoint::query()->pluck('recipe_id')->all());
        // Sprzątanie nie rusza przepisów.
        $this->assertSame(3, Recipe::query()->count());
    }

    public function test_usuniecie_szkicu_i_wymazanie_konta_kasuja_kopie(): void
    {
        $szkic = $this->szkic();
        $this->otworz($szkic);
        $this->assertSame(1, DraftRestorePoint::query()->count());

        $this->basia->markForDeletion();
        app(EraseAccountData::class)->handle($this->basia->fresh());

        $this->assertSame(0, DraftRestorePoint::query()->count());

        // Usunięcie szkicu na stałe kasuje kopię kluczem obcym.
        $inny = $this->user('inny');
        $drugi = $this->szkic('Drugi', $inny);
        app(PunktOdzyskaniaSzkicu::class)->zachowajPrzedEdycja($drugi);
        $this->assertSame(1, DraftRestorePoint::query()->count());
        $drugi->forceDelete();
        $this->assertSame(0, DraftRestorePoint::query()->count());
    }

    public function test_eksport_danych_zawiera_kopie_tekstu_bez_identyfikatorow_zdjec(): void
    {
        $szkic = $this->szkic();
        $zdjecie = Media::factory()->create(['owner_id' => $this->basia->getKey()]);
        $szkic->steps()->where('position', 0)->update(['media_id' => $zdjecie->getKey()]);
        $this->otworz($szkic);

        $paczka = app(CollectUserExportData::class)
            ->handle($this->basia->fresh(), new ExportPhotoPlan($this->basia->fresh()), Carbon::now());

        $this->assertCount(1, $paczka['kopie_tekstu_szkicow']);
        $wiersz = $paczka['kopie_tekstu_szkicow'][0];
        $this->assertSame('Pierogi babci', $wiersz['szkic']);
        $this->assertSame('Pierogi babci', $wiersz['tekst']['title']);
        $this->assertSame('Dwa kroki farszu', $wiersz['tekst']['steps'][0]['instruction']);
        $this->assertStringNotContainsString($zdjecie->getKey(), (string) json_encode($wiersz));
        $this->assertNotNull($wiersz['mozna_odzyskac_do']);
    }

    public function test_cofniecie_migracji_odmawia_przy_kopiach_w_oknie_i_przechodzi_bez_nich(): void
    {
        $szkic = $this->szkic();
        $this->otworz($szkic);

        try {
            $this->migracja()->down();
            $this->fail('Rollback powinien odmówić.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Liczba punktów w oknie odzyskania, które znikną: 1.', $e->getMessage());
        }
        $this->assertTrue(Schema::hasTable('draft_restore_points'));
        $this->assertSame(1, DraftRestorePoint::query()->count());

        // Kontrola dodatnia: przedawniona kopia nie blokuje.
        DraftRestorePoint::query()->update(['taken_at' => now()->subDays(40)]);
        $this->migracja()->down();
        $this->assertFalse(Schema::hasTable('draft_restore_points'));
    }

    public function test_cofniecie_migracji_przechodzi_ze_swiadomym_wymuszeniem(): void
    {
        $this->otworz($this->szkic());
        putenv('KUKING_ROLLBACK_KASUJE_PUNKTY_ODZYSKANIA_SZKICU=1');

        $this->migracja()->down();

        $this->assertFalse(Schema::hasTable('draft_restore_points'));
    }

    // -----------------------------------------------------------------

    private function migracja(): object
    {
        return require base_path(self::SCIEZKA_MIGRACJI);
    }

    private function szkic(string $tytul = 'Pierogi babci', ?User $autor = null): Recipe
    {
        $szkic = Recipe::factory()->draft()->create([
            'author_id' => ($autor ?? $this->basia)->getKey(),
            'title' => $tytul,
            'summary' => 'Niedzielne.',
            'source_type' => Recipe::SOURCE_FAMILY,
            'source_person' => 'od cioci Zosi',
            'servings' => 4,
        ]);
        foreach ([['mąka', 'ciasto', false], ['woda', 'ciasto', false], ['sól', 'farsz', true]] as $i => [$tekst, $grupa, $bez]) {
            $szkic->ingredients()->create(['ingredient_text' => $tekst, 'group_name' => $grupa, 'no_amount' => $bez, 'position' => $i]);
        }
        $szkic->steps()->create(['position' => 0, 'instruction' => 'Dwa kroki farszu', 'timer_seconds' => 300]);
        $szkic->steps()->create(['position' => 1, 'instruction' => 'Ugotuj.']);

        return $szkic;
    }

    private function otworz(Recipe $szkic): void
    {
        $this->actingAs($szkic->author)->get(route('recipes.create', ['szkic' => $szkic->getKey()]))->assertOk();
    }

    /** Pomyłka poprawnie zapisana autozapisem: krótki fragment zamiast długiego kroku, mniej składników. */
    private function zepsuj(Recipe $szkic, string $tytul = 'Pierogi'): void
    {
        $szkic->forceFill(['title' => $tytul, 'source_person' => null])->save();
        $szkic->ingredients()->where('position', '>', 0)->delete();
        $szkic->steps()->where('position', 0)->update(['instruction' => 'Krótko.', 'timer_seconds' => null]);
        $szkic->steps()->where('position', 1)->delete();
        $szkic->forceFill(['content_revision' => $szkic->content_revision + 1])->save();
    }

    private function przywroc(Recipe $szkic): TestResponse
    {
        $szkic = $szkic->fresh();
        $punkt = DraftRestorePoint::query()->where('recipe_id', $szkic->getKey())->firstOrFail();

        return $this->actingAs($this->basia)->post(route('recipes.drafts.restore', $szkic->getKey()), [
            'rewizja' => $szkic->content_revision,
            'znacznik' => $punkt->taken_at->utc()->format(PunktOdzyskaniaSzkicu::FORMAT_ZNACZNIKA),
        ]);
    }

    /** @return array<string, mixed> */
    private function stanSzkicu(Recipe $szkic): array
    {
        $szkic = Recipe::query()->findOrFail($szkic->getKey());

        return [
            'title' => $szkic->title,
            'pochodzenie' => $szkic->source_person,
            'skladniki' => $szkic->ingredients()->get()->map(fn ($i) => $i->group_name.': '.$i->ingredient_text.($i->no_amount ? ' (bez ilości)' : ''))->all(),
            'kroki' => $szkic->steps()->pluck('instruction')->all(),
            'minutniki' => $szkic->steps()->pluck('timer_seconds')->all(),
        ];
    }
}

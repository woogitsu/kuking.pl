<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWersjePrzepisow;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Models\Block;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\PolecenieArtisanaZOdmowa;
use Tests\TestCase;

/**
 * Własne wykonanie pamięta wersję przepisu otwartą podczas gotowania (#2378).
 *
 * Granice, które ten plik pilnuje:
 *  - zapis idzie wyłącznie przez `RecordCookedEvent` (formularz i bezpośrednio),
 *  - wskaźnik czyta tylko kucharz; publiczna karta i historia o nim milczą,
 *  - brak dostępu do wersji daje komunikat BEZ tytułu i treści,
 *  - retencja (#2024) usuwa wersję, a wykonanie zostaje (FK `SET NULL`).
 */
class WykonaniePamietaWersjePrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_10_01_100100_add_recipe_version_id_to_cooked_events.php';

    private User $autor;

    private User $kucharz;

    private Recipe $przepis;

    private RecipeVersion $v1;

    private RecipeVersion $v2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka');
        $this->kucharz = $this->user('kucharz');

        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'title' => 'Zupa Pierwsza',
            'summary' => 'Tajny opis wersji pierwszej',
        ]);
        $this->v1 = app(SnapshotRecipeVersion::class)->handle($this->przepis, $this->autor, 'start');

        $this->przepis->update(['title' => 'Zupa Druga', 'summary' => 'Opis wersji drugiej']);
        $this->v2 = app(SnapshotRecipeVersion::class)->handle($this->przepis->fresh(), $this->autor, 'poprawka');
    }

    private function wykonanie(?string $wersjaId = null, ?User $kto = null): CookedEvent
    {
        return app(RecordCookedEvent::class)->handle(
            cook: $kto ?? $this->kucharz,
            recipe: $this->przepis->fresh(),
            note: 'Wyszło.',
            wersjaPrzepisuId: $wersjaId,
        );
    }

    public function test_formularz_niesie_najnowsza_wersje_a_zapis_przypina_te_z_formularza(): void
    {
        $html = $this->actingAs($this->kucharz)->get(route('cooked.create', $this->przepis->slug))->assertOk()->getContent();
        $this->assertStringContainsString('name="wersja_przepisu" value="'.$this->v2->getKey().'"', $html);

        // Człowiek otworzył formularz przy v1; w międzyczasie powstało v2.
        $this->actingAs($this->kucharz)->post(route('cooked.store', $this->przepis->slug), [
            'wersja_przepisu' => $this->v1->getKey(),
            'note' => 'Z wersji pierwszej',
        ])->assertRedirect();

        $this->assertSame($this->v1->getKey(), CookedEvent::query()->sole()->recipe_version_id);
    }

    public function test_bez_pola_zapis_przypina_najnowsza_wersje(): void
    {
        $this->assertSame($this->v2->getKey(), $this->wykonanie()->recipe_version_id);
    }

    public function test_sprawdzenie_wersji_z_formularza_trzyma_wiersz_for_key_share(): void
    {
        // Test na dwóch połączeniach byłby nieproporcjonalny: sprawdzamy, że
        // zapytanie o wersję z formularza niesie blokadę, która chroni przed
        // skasowaniem wersji przez retencję między sprawdzeniem a INSERT
        // (inaczej klucz obcy rzuca 23503 i człowiek widzi błąd 500).
        $zapytania = [];
        DB::listen(function ($q) use (&$zapytania): void {
            $zapytania[] = strtolower($q->sql);
        });

        $this->wykonanie($this->v1->getKey());

        $zBlokada = array_filter(
            $zapytania,
            fn (string $sql): bool => str_contains($sql, 'from "recipe_versions"') && str_contains($sql, 'for key share'),
        );
        $this->assertNotEmpty($zBlokada, 'Wersja z formularza musi być sprawdzana pod FOR KEY SHARE.');
    }

    public function test_wersja_skasowana_przed_zapisem_schodzi_do_najnowszej_bez_bledu(): void
    {
        $this->v1->delete();

        $this->assertSame($this->v2->getKey(), $this->wykonanie($this->v1->getKey())->recipe_version_id);
    }

    public function test_wersja_cudzego_przepisu_nie_zostaje_przypieta(): void
    {
        $inny = Recipe::factory()->create(['status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']);
        $cudza = app(SnapshotRecipeVersion::class)->handle($inny, $inny->author, 'cudza');

        $wykonanie = $this->wykonanie($cudza->getKey());

        $this->assertNotSame($cudza->getKey(), $wykonanie->recipe_version_id);
        $this->assertSame($this->v2->getKey(), $wykonanie->recipe_version_id);
    }

    public function test_przepis_bez_wersji_daje_null_a_wykonanie_sie_zapisuje(): void
    {
        $bez = Recipe::factory()->create(['status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']);

        $wykonanie = app(RecordCookedEvent::class)->handle(cook: $this->kucharz, recipe: $bez);

        $this->assertNull($wykonanie->recipe_version_id);
    }

    public function test_wskaznika_nie_da_sie_ustawic_masowym_przypisaniem(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new CookedEvent)->fill(['recipe_version_id' => $this->v1->getKey()]);
    }

    public function test_stare_wykonanie_pokazuje_tresc_z_chwili_gotowania_po_kolejnej_edycji(): void
    {
        $wykonanie = $this->wykonanie($this->v1->getKey());

        $this->przepis->update(['title' => 'Zupa Trzecia']);
        app(SnapshotRecipeVersion::class)->handle($this->przepis->fresh(), $this->autor, 'jeszcze raz');

        $html = $this->actingAs($this->kucharz)->get(route('cooked.version', $wykonanie))->assertOk()->getContent();

        $this->assertStringContainsString('Zupa Pierwsza', $html);
        $this->assertStringContainsString('Tajny opis wersji pierwszej', $html);
        $this->assertStringNotContainsString('Zupa Trzecia', $html);

        $karta = $this->actingAs($this->kucharz)->get(route('cooked.show', $wykonanie))->assertOk()->getContent();
        $this->assertStringContainsString(route('cooked.version', $wykonanie), $karta);
    }

    public function test_wersje_widzi_tylko_kucharz(): void
    {
        $wykonanie = $this->wykonanie($this->v1->getKey());
        $obcy = $this->user('obcy');
        $moderator = $this->user('moderatorka', ['role' => 'moderator']);

        // Kontrola dodatnia: kucharz wchodzi.
        $this->actingAs($this->kucharz)->get(route('cooked.version', $wykonanie))->assertOk();

        foreach ([$this->autor, $obcy, $moderator] as $kto) {
            $this->actingAs($kto)->get(route('cooked.version', $wykonanie))->assertNotFound();
        }

        auth()->logout();
        $this->get(route('cooked.version', $wykonanie))->assertRedirect(route('login'));
    }

    public function test_publiczna_karta_wykonania_nie_zdradza_wersji_nikomu_poza_kucharzem(): void
    {
        $wykonanie = $this->wykonanie($this->v1->getKey());

        // Kontrola dodatnia: kucharz dostaje sekcję.
        $this->actingAs($this->kucharz)->get(route('cooked.show', $wykonanie))
            ->assertOk()->assertSee('Z której wersji przepisu');

        foreach ([$this->autor, $this->user('obcy2')] as $kto) {
            $this->actingAs($kto)->get(route('cooked.show', $wykonanie))
                ->assertOk()
                ->assertDontSee('Z której wersji przepisu')
                ->assertDontSee(route('cooked.version', $wykonanie));
        }

        // Publiczna historia (#2024) nadal nie sugeruje wersji wykonania.
        $this->actingAs($this->autor)->get(route('recipes.history', $this->przepis->slug))
            ->assertOk()->assertDontSee('wykonan');
    }

    public function test_wersja_ukryta_przez_autora_nie_wycieka_tresci_kucharzowi(): void
    {
        $wykonanie = $this->wykonanie($this->v1->getKey());
        $this->v1->ukryj(RecipeVersion::UKRYL_AUTOR);

        $html = $this->actingAs($this->kucharz)->get(route('cooked.version', $wykonanie))->assertOk()->getContent();

        $this->assertStringContainsString('nie możemy Ci już pokazać', $html);
        $this->assertStringNotContainsString('Zupa Pierwsza', $html);
        $this->assertStringNotContainsString('Tajny opis wersji pierwszej', $html);
        $this->assertStringNotContainsString('Zupa Druga', $html);

        // Karta wykonania mówi to samo, bez tytułu i treści.
        $this->actingAs($this->kucharz)->get(route('cooked.show', $wykonanie))
            ->assertOk()->assertSee('Wersji przepisu z tego gotowania nie możemy już pokazać')
            ->assertDontSee('Tajny opis wersji pierwszej');

        // Kucharz zachowuje swoje wykonanie.
        $this->assertDatabaseHas('cooked_events', ['id' => $wykonanie->getKey(), 'recipe_version_id' => $this->v1->getKey()]);
    }

    public function test_kucharz_bedacy_autorem_widzi_wlasna_ukryta_wersje(): void
    {
        $wykonanie = $this->wykonanie($this->v1->getKey(), $this->autor);
        $this->v1->ukryj(RecipeVersion::UKRYL_AUTOR);

        $this->actingAs($this->autor)->get(route('cooked.version', $wykonanie))
            ->assertOk()->assertSee('Zupa Pierwsza');
    }

    public function test_przepis_przelaczony_na_prywatny_nie_wycieka_tytulu_ani_tresci(): void
    {
        $wykonanie = $this->wykonanie($this->v1->getKey());
        $this->przepis->update(['visibility' => 'private']);

        $odpowiedz = $this->actingAs($this->kucharz)->get(route('cooked.version', $wykonanie))->assertOk();
        $html = $odpowiedz->getContent();

        $this->assertStringContainsString('nie możemy Ci już pokazać', $html);
        $this->assertStringNotContainsString('Zupa Pierwsza', $html);
        $this->assertStringNotContainsString('Tajny opis', $html);
        $this->assertStringNotContainsString('Zupa Druga', $html);
    }

    public function test_blokada_z_autorem_zamyka_wersje(): void
    {
        $wykonanie = $this->wykonanie($this->v1->getKey());
        Block::create(['blocker_id' => $this->autor->getKey(), 'blocked_id' => $this->kucharz->getKey(), 'created_at' => now()]);

        $html = $this->actingAs($this->kucharz)->get(route('cooked.version', $wykonanie))->assertOk()->getContent();

        $this->assertStringNotContainsString('Zupa Pierwsza', $html);
        $this->assertStringContainsString('nie możemy Ci już pokazać', $html);
    }

    public function test_retencja_kasuje_wersje_a_wykonanie_zostaje_z_komunikatem(): void
    {
        $wykonanie = $this->wykonanie($this->v1->getKey());

        // Cztery nowsze wersje + v1 sprzed lat: v1 wypada z "3 najnowszych" i z 24 miesięcy.
        foreach (['trzecia', 'czwarta', 'piata'] as $nazwa) {
            $this->przepis->update(['title' => 'Zupa '.$nazwa]);
            app(SnapshotRecipeVersion::class)->handle($this->przepis->fresh(), $this->autor, $nazwa);
        }
        DB::table('recipe_versions')->where('id', $this->v1->getKey())->update(['created_at' => now()->subYears(4)]);

        $wynik = (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);

        $this->assertGreaterThanOrEqual(1, $wynik['skasowano']);
        $this->assertDatabaseMissing('recipe_versions', ['id' => $this->v1->getKey()]);
        $this->assertDatabaseHas('cooked_events', ['id' => $wykonanie->getKey(), 'recipe_version_id' => null]);

        $html = $this->actingAs($this->kucharz)->get(route('cooked.version', $wykonanie))->assertOk()->getContent();
        $this->assertStringContainsString('nie możemy Ci już pokazać', $html);

        // Po retencji wskaźnik jest pusty ("nie wiadomo"): karta nie rysuje sekcji
        // i nie obiecuje wersji, której nie ma. Wykonanie jest czytelne jak dotąd.
        $this->actingAs($this->kucharz)->get(route('cooked.show', $wykonanie))
            ->assertOk()->assertSee('Wyszło.')->assertDontSee('Z której wersji przepisu');
    }

    public function test_wykonanie_bez_wskaznika_nie_ma_sekcji_o_wersji(): void
    {
        $wykonanie = CookedEvent::factory()->create(['user_id' => $this->kucharz->getKey(), 'recipe_id' => $this->przepis->getKey()]);

        $this->actingAs($this->kucharz)->get(route('cooked.show', $wykonanie))
            ->assertOk()->assertDontSee('Z której wersji przepisu');
    }

    public function test_cofniecie_migracji_odmawia_gdy_wykonanie_ma_wskaznik(): void
    {
        $wykonanie = $this->wykonanie($this->v1->getKey());

        try {
            PolecenieArtisanaZOdmowa::wywolaj('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
            $this->fail('Cofnięcie przeszło, mimo że wykonanie ma wskaźnik wersji.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(cooked_events.recipe_version_id IS NOT NULL): 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }

        $this->assertSame(1, $this->iloscKolumn());
        $this->assertSame($this->v1->getKey(), $wykonanie->fresh()->recipe_version_id);
    }

    public function test_cofniecie_migracji_przechodzi_gdy_nikt_nie_ma_wskaznika(): void
    {
        CookedEvent::factory()->create();

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(0, $this->iloscKolumn());

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(1, $this->iloscKolumn());
    }

    private function iloscKolumn(): int
    {
        return count(DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'cooked_events' AND column_name = 'recipe_version_id'",
        ));
    }
}

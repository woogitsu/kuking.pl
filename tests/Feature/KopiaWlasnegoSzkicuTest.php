<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\ZrobKopieSzkicu;
use App\Domain\Recipes\MojaWersja;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * „Zrób kopię” własnego szkicu do drugiego wariantu (#2507, V2, D-333 — paczka E).
 */
final class KopiaWlasnegoSzkicuTest extends TestCase
{
    use RefreshDatabase;

    private User $ja;

    private Recipe $szkic;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ja = $this->user('kucharka');
        $this->szkic = Recipe::factory()->draft()->family()->create([
            'author_id' => $this->ja->getKey(), 'title' => 'Rosół babci Zofii', 'summary' => 'Rodzinny', 'servings' => 6,
            'prep_minutes' => 20, 'cook_minutes' => 180, 'difficulty' => 'medium', 'visibility' => 'private',
        ]);
        $gramy = Unit::query()->where('code', 'g')->first() ?? Unit::create(['code' => 'g', 'name' => 'gram']);
        RecipeIngredient::create(['recipe_id' => $this->szkic->getKey(), 'group_name' => 'Wywar', 'ingredient_text' => 'kura rosołowa', 'quantity' => 1500, 'unit_id' => $gramy->getKey(), 'note' => 'cała', 'substitutes' => 'indyk', 'position' => 0]);
        RecipeIngredient::create(['recipe_id' => $this->szkic->getKey(), 'group_name' => 'Dodatki', 'ingredient_text' => 'sól do smaku', 'no_amount' => true, 'position' => 1]);
        RecipeStep::create(['recipe_id' => $this->szkic->getKey(), 'position' => 0, 'instruction' => 'Zalej kurę wodą.', 'timer_seconds' => 10800, 'section_name' => 'Dzień 1']);
        RecipeStep::create(['recipe_id' => $this->szkic->getKey(), 'position' => 1, 'instruction' => 'Przecedź.']);
    }

    protected function tearDown(): void
    {
        RecipeStep::flushEventListeners();
        parent::tearDown();
    }

    private function zrobKopie(?User $kto = null, ?string $klucz = null, ?Recipe $zrodlo = null)
    {
        $zrodlo ??= $this->szkic;

        return $this->actingAs($kto ?? $this->ja)->post(route('recipes.drafts.copy.store', $zrodlo->getKey()), [
            'klucz_kopii' => $klucz ?? (string) Str::uuid7(),
        ]);
    }

    public function test_ekran_potwierdzenia_mowi_co_kopiujemy_i_nic_nie_zapisuje(): void
    {
        $zdjecie = Media::factory()->create(['owner_id' => $this->ja->getKey()]);
        $this->szkic->forceFill(['hero_media_id' => $zdjecie->getKey()])->save();
        $przed = Recipe::query()->count();

        $html = (string) $this->actingAs($this->ja)->get(route('recipes.drafts.copy', $this->szkic->getKey()))->assertOk()->getContent();

        $this->assertSame($przed, Recipe::query()->count(), 'KOPIA_2507_GET_ZAPISUJE: ekran potwierdzenia utworzył kopię.');
        $this->assertStringContainsString('Co się skopiuje', $html);
        $this->assertStringContainsString('Czego nie kopiujemy', $html);
        $this->assertStringContainsString('ten szkic ma zdjęcia', $html);
        $this->assertStringContainsString('ostatnio zapisanego stanu szkicu', $html);
        $this->assertStringContainsString('name="klucz_kopii"', $html);
        $this->assertStringContainsString('noindex', $html);
    }

    public function test_kopia_to_niezalezny_prywatny_szkic_ze_skopiowana_trescia_i_bez_mediow(): void
    {
        $zdjecie = Media::factory()->create(['owner_id' => $this->ja->getKey()]);
        $this->szkic->forceFill(['hero_media_id' => $zdjecie->getKey()])->save();
        RecipeStep::query()->where('recipe_id', $this->szkic->getKey())->where('position', 0)->update(['media_id' => $zdjecie->getKey()]);
        $zrodloPrzed = $this->szkic->fresh()->only(['title', 'summary', 'updated_at', 'status', 'visibility', 'hero_media_id']);
        $idSkladnikowZrodla = $this->szkic->ingredients()->pluck('id')->all();

        $odpowiedz = $this->zrobKopie();

        $kopia = Recipe::query()->where('kopia_z_id', $this->szkic->getKey())->sole();
        $odpowiedz->assertRedirect(route('recipes.create', ['szkic' => $kopia->getKey()]))->assertSessionHas('status');

        $this->assertNotSame($this->szkic->getKey(), $kopia->getKey());
        $this->assertNotSame($this->szkic->slug, $kopia->slug);
        $this->assertSame('Rosół babci Zofii — kopia', $kopia->title);
        $this->assertSame([Recipe::STATUS_DRAFT, 'private', null], [$kopia->status, $kopia->visibility, $kopia->published_at]);
        $this->assertSame($this->ja->getKey(), $kopia->author_id);
        $this->assertEquals([6, 20, 180, 'medium', 'Rodzinny'], [$kopia->servings, $kopia->prep_minutes, $kopia->cook_minutes, $kopia->difficulty, $kopia->summary]);
        $this->assertSame([Recipe::SOURCE_FAMILY, 'od mamy, Haliny', 1974], [$kopia->source_type, $kopia->source_person, $kopia->family_since_year]);
        $this->assertSame(Recipe::ALERGENY_NIESPRAWDZONE, $kopia->allergen_status);

        $skladniki = $kopia->ingredients()->with('unit')->orderBy('position')->get();
        $this->assertSame(['kura rosołowa', 'sól do smaku'], $skladniki->pluck('ingredient_text')->all());
        $this->assertSame(['Wywar', 'Dodatki'], $skladniki->pluck('group_name')->all());
        $this->assertSame(['cała', null], $skladniki->pluck('note')->all());
        $this->assertSame(['indyk', null], $skladniki->pluck('substitutes')->all());
        $this->assertSame([false, true], $skladniki->map(fn ($s): bool => (bool) $s->no_amount)->all());
        $this->assertSame('g', $skladniki[0]->unit?->code);
        $this->assertEquals(1500, $skladniki[0]->quantity);
        $this->assertSame([], array_intersect($idSkladnikowZrodla, $skladniki->pluck('id')->all()), 'Nowe identyfikatory składników.');

        $kroki = $kopia->steps()->orderBy('position')->get();
        $this->assertSame(['Zalej kurę wodą.', 'Przecedź.'], $kroki->pluck('instruction')->all());
        $this->assertSame([10800, null], $kroki->pluck('timer_seconds')->all());
        $this->assertSame(['Dzień 1', null], $kroki->pluck('section_name')->all());

        $this->assertNull($kopia->hero_media_id, 'KOPIA_2507_MEDIA: kopia przejęła zdjęcie główne.');
        $this->assertSame([null, null], $kroki->pluck('media_id')->all(), 'KOPIA_2507_MEDIA: kopia przejęła zdjęcie kroku.');

        // Źródło bez zmian.
        $this->assertEquals($zrodloPrzed, $this->szkic->fresh()->only(['title', 'summary', 'updated_at', 'status', 'visibility', 'hero_media_id']));
        $this->assertSame(2, $this->szkic->ingredients()->count());
        $this->assertSame($zdjecie->getKey(), $this->szkic->steps()->orderBy('position')->first()->media_id);

        // Bez powiadomień, wpisów w strumieniach i wykonań.
        $this->assertSame(0, Notification::query()->count());
        $this->assertSame(0, Post::query()->where('recipe_id', $kopia->getKey())->count());
        $this->assertSame(0, CookedEvent::query()->count());
    }

    public function test_jedno_wyslanie_to_jedna_kopia_a_nowy_formularz_to_kolejny_wariant(): void
    {
        $klucz = (string) Str::uuid7();

        $this->zrobKopie(null, $klucz)->assertRedirect()->assertSessionHas('status_rodzaj', 'sukces');
        $ponowienie = $this->zrobKopie(null, $klucz);

        $this->assertSame(1, Recipe::query()->where('kopia_z_id', $this->szkic->getKey())->count(), 'KOPIA_2507_IDEMPOTENCJA: ponowienie zrobiło drugą kopię.');
        $ponowienie->assertSessionHas('status_rodzaj', 'informacja');
        $this->assertSame(2, $this->szkic->ingredients()->count());

        $this->zrobKopie();
        $this->assertSame(2, Recipe::query()->where('kopia_z_id', $this->szkic->getKey())->count(), 'Nowa świadoma czynność zakłada kolejny wariant.');
    }

    public function test_kopia_adaptacji_zachowuje_podpis_oryginalu_takze_gdy_oryginal_zniknal(): void
    {
        $oryginal = Recipe::factory()->create(['title' => 'Cudzy oryginał']);
        $this->szkic->forceFill(['source_type' => Recipe::SOURCE_ADAPTATION, 'forked_from_id' => $oryginal->getKey(), 'forked_at' => now()->subDays(3)])->save();
        $oryginal->delete();

        $this->zrobKopie();

        $kopia = Recipe::query()->where('kopia_z_id', $this->szkic->getKey())->sole();
        $this->assertSame($oryginal->getKey(), $kopia->forked_from_id, 'KOPIA_2507_ATRYBUCJA: kopia zgubiła podpis oryginału.');
        $this->assertSame($this->szkic->fresh()->forked_at->toIso8601String(), $kopia->forked_at->toIso8601String());
        $this->assertSame(Recipe::SOURCE_ADAPTATION, $kopia->source_type);
        $this->assertNull($kopia->published_at);

        // Ekran potwierdzenia uprzedza o podpisie.
        $this->assertStringContainsString('zachowa podpis', (string) $this->actingAs($this->ja)->get(route('recipes.drafts.copy', $this->szkic->getKey()))->getContent());
    }

    public function test_tylko_wlasciciel_aktywny_wlasnego_szkicu(): void
    {
        $cudza = $this->user('obca');
        $moderator = $this->user('moderatorka', ['role' => User::ROLE_MODERATOR]);
        $opublikowany = Recipe::factory()->create(['author_id' => $this->ja->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']);
        $przed = Recipe::query()->count();

        foreach ([$cudza, $moderator] as $ktos) {
            $this->assertSame(403, $this->actingAs($ktos)->get(route('recipes.drafts.copy', $this->szkic->getKey()))->getStatusCode(), 'KOPIA_2507_WLASCICIEL: cudzy szkic otworzył ekran kopiowania.');
            $odp = $this->zrobKopie($ktos);
            $this->assertSame(403, $odp->getStatusCode(), 'KOPIA_2507_WLASCICIEL: cudzy szkic został skopiowany.');
        }
        $this->actingAs($this->ja)->get(route('recipes.drafts.copy', $opublikowany->getKey()))->assertForbidden();
        $this->zrobKopie(null, null, $opublikowany)->assertForbidden();

        auth()->logout();
        $this->get(route('recipes.drafts.copy', $this->szkic->getKey()))->assertRedirect(route('login'));

        $this->ja->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->actingAs($this->ja->refresh())->get(route('recipes.drafts.copy', $this->szkic->getKey()))->assertForbidden();
        $this->zrobKopie($this->ja->refresh());

        $this->assertSame($przed, Recipe::query()->count());
    }

    public function test_stan_i_prawo_sa_sprawdzane_od_nowa_pod_blokada(): void
    {
        // Akcja dostaje STARY model (szkic), a w bazie przepis jest już opublikowany / konto zawieszone.
        $stary = Recipe::query()->findOrFail($this->szkic->getKey());
        $this->szkic->forceFill(['status' => Recipe::STATUS_PUBLISHED, 'published_at' => now()])->save();

        try {
            app(ZrobKopieSzkicu::class)->handle($this->ja, $stary, (string) Str::uuid7());
            $this->fail('KOPIA_2507_SWIEZY_STAN: opublikowany w międzyczasie przepis został skopiowany jako szkic.');
        } catch (AuthorizationException) {
            $this->assertSame(0, Recipe::query()->where('kopia_z_id', $this->szkic->getKey())->count());
        }

        $this->szkic->forceFill(['status' => Recipe::STATUS_DRAFT, 'published_at' => null])->save();
        $this->ja->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->expectException(AuthorizationException::class);
        app(ZrobKopieSzkicu::class)->handle(User::query()->findOrFail($this->ja->getKey())->forceFill(['status' => User::STATUS_ACTIVE]), $stary, (string) Str::uuid7());
    }

    public function test_awaria_w_polowie_kopiowania_nie_zostawia_polowicznej_kopii(): void
    {
        $this->withoutExceptionHandling();
        RecipeStep::creating(function (): void {
            throw new \RuntimeException('awaria zapisu kroku');
        });
        $przed = Recipe::query()->count();

        try {
            $this->zrobKopie();
            $this->fail('Awaria nie przerwała kopiowania.');
        } catch (\RuntimeException $e) {
            $this->assertSame('awaria zapisu kroku', $e->getMessage());
        }

        $this->assertSame($przed, Recipe::query()->count(), 'KOPIA_2507_ATOMOWOSC: po awarii została częściowa kopia.');
        $this->assertSame(2, RecipeIngredient::query()->count(), 'Składniki kopii cofnięte razem z przepisem.');
    }

    public function test_usuniecie_kopii_nie_rusza_zrodla_i_odwrotnie(): void
    {
        $this->zrobKopie();
        $kopia = Recipe::query()->where('kopia_z_id', $this->szkic->getKey())->sole();

        $kopia->delete();
        $this->assertSame(2, $this->szkic->ingredients()->count());
        $this->assertSame(2, $this->szkic->steps()->count());
        $this->assertNull($this->szkic->fresh()->deleted_at);

        // Twarde skasowanie źródła nie kasuje kopii; wskazanie się zeruje (ON DELETE SET NULL).
        $this->zrobKopie();
        $druga = Recipe::query()->where('kopia_z_id', $this->szkic->getKey())->sole();
        DB::table('recipes')->where('id', $this->szkic->getKey())->delete();
        $this->assertNull($druga->fresh()->kopia_z_id);
        $this->assertSame(2, $druga->ingredients()->count());
    }

    public function test_niezmieniona_kopia_nie_wychodzi_do_ludzi_a_zmieniona_tak(): void
    {
        $this->zrobKopie();
        $kopia = Recipe::query()->where('kopia_z_id', $this->szkic->getKey())->sole();

        $szkicSkladniki = [['text' => 'kura rosołowa', 'group_name' => 'Wywar', 'note' => 'cała', 'substitutes' => 'indyk'], ['text' => 'sól do smaku', 'group_name' => 'Dodatki', 'no_amount' => '1']];
        $kroki = [['instruction' => 'Zalej kurę wodą.', 'timer_minutes' => 180, 'section_name' => 'Dzień 1'], ['instruction' => 'Przecedź.']];

        // Reguła na poziomie domeny: identyczne => odmowa z komunikatem po polsku.
        try {
            MojaWersja::pilnujRoznicyKopii($kopia->fresh()->forceFill(['status' => Recipe::STATUS_PUBLISHED]));
            $this->fail('KOPIA_2507_PUBLIKACJA: niezmieniona kopia przeszła.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertStringContainsString('Ta kopia niczym nie różni się od szkicu', $e->getMessage());
        }

        // Zmiana rzeczy, z których się gotuje, odblokowuje publikację.
        RecipeIngredient::query()->where('recipe_id', $kopia->getKey())->where('position', 1)->update(['ingredient_text' => 'sól morska do smaku']);
        MojaWersja::pilnujRoznicyKopii($kopia->fresh());
        $this->addToAssertionCount(1);

        // Źródło usunięte miękko też jest punktem odniesienia; usunięte twardo (NULL) — nie ma z czym porównać.
        RecipeIngredient::query()->where('recipe_id', $kopia->getKey())->where('position', 1)->update(['ingredient_text' => 'sól do smaku']);
        $this->szkic->delete();
        $this->expectException(BladDlaCzlowieka::class);
        MojaWersja::pilnujRoznicyKopii($kopia->fresh());
        unset($szkicSkladniki, $kroki);
    }

    public function test_publikacja_niezmienionej_kopii_przez_formularz_jest_odrzucona(): void
    {
        $this->zrobKopie();
        $kopia = Recipe::query()->where('kopia_z_id', $this->szkic->getKey())->sole();

        $this->actingAs($this->ja)->put(route('recipes.update', $kopia), [
            'content_revision' => $kopia->content_revision,
            'title' => 'Rosół — wariant',
            'visibility' => 'public',
            'ingredients' => [
                ['text' => 'kura rosołowa', 'group_name' => 'Wywar', 'note' => 'cała', 'substitutes' => 'indyk', 'quantity' => '1500'],
                ['text' => 'sól do smaku', 'group_name' => 'Dodatki', 'no_amount' => '1'],
            ],
            'steps' => [['instruction' => 'Zalej kurę wodą.', 'timer_minutes' => 180, 'section_name' => 'Dzień 1'], ['instruction' => 'Przecedź.']],
            'servings' => 6, 'prep_minutes' => 20, 'cook_minutes' => 180, 'difficulty' => 'medium',
        ]);

        $this->assertNull($kopia->fresh()->published_at, 'KOPIA_2507_PUBLIKACJA: niezmieniona kopia została opublikowana.');
        $this->assertSame(Recipe::STATUS_DRAFT, $kopia->fresh()->status);
    }

    public function test_lista_szkicow_ma_przycisk_kopii(): void
    {
        $html = (string) $this->actingAs($this->ja)->get(route('recipes.drafts'))->assertOk()->getContent();

        $this->assertStringContainsString(route('recipes.drafts.copy', $this->szkic->getKey()), $html);
        $this->assertStringContainsString('Zrób kopię', $html);
    }

    public function test_kolumny_kopii_nie_sa_masowo_przypisywalne_a_baza_pilnuje_klucza(): void
    {
        $this->assertNotContains('kopia_z_id', (new Recipe)->getFillable());
        $this->assertNotContains('forked_from_id', (new Recipe)->getFillable());

        $klucz = (string) Str::uuid7();
        $this->zrobKopie(null, $klucz);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('recipes')->insert([
            'id' => (string) Str::uuid7(), 'author_id' => $this->ja->getKey(), 'title' => 'Dubel', 'slug' => 'dubel-'.Str::random(6),
            'visibility' => 'private', 'status' => 'draft', 'source_type' => 'own', 'klucz_wyslania' => $klucz,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_cofniecie_migracji_odmawia_przy_niepublikowanych_kopiach_i_przechodzi_bez_nich(): void
    {
        $migracja = require base_path('database/migrations/2026_10_07_130000_add_kopia_to_recipes.php');

        // Kontrola dodatnia: bez kopii kolumny schodzą bez pytania i wracają.
        $migracja->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('recipes', 'kopia_z_id'));
        $migracja->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('recipes', 'kopia_z_id'));

        $this->zrobKopie();
        try {
            $migracja->down();
            $this->fail('Rollback zgubił powiązanie niepublikowanej kopii bez pytania.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('recipes.kopia_z_id IS NOT NULL AND published_at IS NULL): 1', $e->getMessage());
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('recipes', 'kopia_z_id'));
    }
}

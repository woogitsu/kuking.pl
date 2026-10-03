<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\RecipeVersion;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Zastosuj jako nową poprawkę” z historii (#2525, V2, D-333 — paczka E).
 *
 * Świat: przepis w wersji 1 („Zupa Pierwsza”, 4 porcje, marchewka, dwa kroki),
 * autor zmienia go w wersję 2 („Zupa Druga”, 8 porcji, seler, inne kroki).
 * Wersja 3 pojawia się dopiero po zastosowaniu wersji 1.
 */
final class PoprawkaZWersjiPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private Recipe $przepis;

    private RecipeVersion $v1;

    private RecipeVersion $v2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka');
        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(), 'title' => 'Zupa Pierwsza', 'summary' => 'Opis pierwszy', 'servings' => 4,
            'prep_minutes' => 10, 'cook_minutes' => 30, 'difficulty' => 'easy',
        ]);
        $gramy = Unit::query()->where('code', 'g')->first() ?? Unit::create(['code' => 'g', 'name' => 'gram']);
        RecipeIngredient::create(['recipe_id' => $this->przepis->getKey(), 'ingredient_text' => 'marchewka z wersji pierwszej', 'quantity' => 500, 'unit_id' => $gramy->getKey(), 'position' => 0]);
        RecipeStep::create(['recipe_id' => $this->przepis->getKey(), 'position' => 0, 'instruction' => 'Obierz marchewkę.', 'timer_seconds' => 120]);
        RecipeStep::create(['recipe_id' => $this->przepis->getKey(), 'position' => 1, 'instruction' => 'Ugotuj zupę.']);
        $this->v1 = app(SnapshotRecipeVersion::class)->handle($this->przepis->fresh(), $this->autor, 'start');

        $this->przepis->ingredients()->delete();
        $this->przepis->steps()->delete();
        RecipeIngredient::create(['recipe_id' => $this->przepis->getKey(), 'ingredient_text' => 'seler z wersji drugiej', 'position' => 0]);
        RecipeStep::create(['recipe_id' => $this->przepis->getKey(), 'position' => 0, 'instruction' => 'Zetrzyj seler.']);
        $this->przepis->update(['title' => 'Zupa Druga', 'summary' => 'Opis drugi', 'servings' => 8, 'cook_minutes' => 45]);
        $this->v2 = app(SnapshotRecipeVersion::class)->handle($this->przepis->fresh(), $this->autor, 'poprawka');
    }

    protected function tearDown(): void
    {
        RecipeVersion::flushEventListeners();
        parent::tearDown();
    }

    /** @param  list<string>  $sekcje */
    private function zastosuj(array $sekcje, ?int $rewizja = null, ?User $kto = null, int $numer = 1)
    {
        return $this->actingAs($kto ?? $this->autor)->post(route('recipes.history.apply.store', [$this->przepis->slug, $numer]), [
            'sekcje' => $sekcje,
            'rewizja' => $rewizja ?? $this->przepis->fresh()->content_revision,
        ]);
    }

    /** @return array<string, mixed> */
    private function stan(): array
    {
        $p = $this->przepis->fresh();

        return [
            'title' => $p->title, 'servings' => $p->servings, 'rev' => $p->content_revision, 'wersje' => RecipeVersion::query()->where('recipe_id', $p->getKey())->count(),
            'skladniki' => $p->ingredients()->orderBy('position')->pluck('ingredient_text')->all(),
            'kroki' => $p->steps()->orderBy('position')->pluck('instruction')->all(),
        ];
    }

    public function test_podglad_jest_odczytowy_i_pokazuje_co_wroci_oraz_czego_nie_przywracamy(): void
    {
        $przed = $this->stan();
        $zapytanie = null;
        DB::listen(function ($q) use (&$zapytanie): void {
            if (preg_match('/^\s*(insert into|update|delete from)\s+"(recipes|recipe_versions|recipe_ingredients|recipe_steps|audit_log_entries|audit_log)"/i', $q->sql) === 1) {
                $zapytanie ??= $q->sql;
            }
        });

        $html = (string) $this->actingAs($this->autor)->get(route('recipes.history.apply', [$this->przepis->slug, 1]))->assertOk()->getContent();

        $this->assertNull($zapytanie, 'PODGLAD_2525_ZAPIS: otwarcie podglądu coś zapisało: '.$zapytanie);
        $this->assertSame($przed, $this->stan());
        $this->assertStringContainsString('To <strong>podgląd</strong>', $html);
        $this->assertStringContainsString('To nie jest cofnięcie czasu', $html);
        $this->assertStringContainsString('Dziś: Zupa Druga', $html);
        $this->assertStringContainsString('Z wersji 1: Zupa Pierwsza', $html);
        $this->assertStringContainsString('marchewka z wersji pierwszej', $html);
        $this->assertStringContainsString('Czego nie przywracamy', $html);
        $this->assertStringContainsString('zdjęć: głównego, przy krokach i skanu kartki', $html);
        $this->assertStringContainsString('name="rewizja" value="'.$this->przepis->fresh()->content_revision.'"', $html);
        $this->assertStringContainsString('Zastosuj zaznaczone jako nową poprawkę', $html);
    }

    public function test_zastosowanie_tworzy_nowa_wersje_i_zostawia_dawne_nietkniete(): void
    {
        $snapshotV1 = $this->v1->fresh()->snapshot;
        $snapshotV2 = $this->v2->fresh()->snapshot;
        $slug = $this->przepis->slug;
        $widocznosc = $this->przepis->visibility;
        $zrodlo = $this->przepis->source_type;
        $rewizja = $this->przepis->fresh()->content_revision;
        $wykonan = CookedEvent::query()->count();

        $this->zastosuj(['dane', 'skladniki', 'kroki'])
            ->assertRedirect(route('recipes.history', $slug))->assertSessionHas('status');

        $po = $this->przepis->fresh();
        $this->assertSame('Zupa Pierwsza', $po->title);
        $this->assertSame('Opis pierwszy', $po->summary);
        $this->assertEquals(4, $po->servings);
        $this->assertSame(30, $po->cook_minutes);
        $this->assertSame(['marchewka z wersji pierwszej'], $po->ingredients()->pluck('ingredient_text')->all());
        $ing = $po->ingredients()->first();
        $this->assertSame('g', $ing->unit?->code);
        $this->assertEquals(500, $ing->quantity);
        $this->assertSame(['Obierz marchewkę.', 'Ugotuj zupę.'], $po->steps()->orderBy('position')->pluck('instruction')->all());
        $this->assertSame(120, $po->steps()->orderBy('position')->first()->timer_seconds);

        $this->assertSame($slug, $po->slug, 'Adres przepisu bez zmian.');
        $this->assertSame($widocznosc, $po->visibility);
        $this->assertSame($zrodlo, $po->source_type);
        $this->assertSame($rewizja + 1, $po->content_revision);
        $this->assertSame($wykonan, CookedEvent::query()->count(), 'Nie powstaje nowe „Ugotowałem”.');

        $v3 = RecipeVersion::query()->where('recipe_id', $po->getKey())->where('version_number', 3)->sole();
        $this->assertSame('Przywrócono treść wersji 1 jako nową poprawkę', $v3->change_note);
        $this->assertSame('Zupa Pierwsza', $v3->snapshot['title']);
        $this->assertSame(3, RecipeVersion::query()->where('recipe_id', $po->getKey())->count());
        $this->assertEquals($snapshotV1, $this->v1->fresh()->snapshot, 'POPRAWKA_2525_NIEZMIENNE: wersja 1 została zmieniona.');
        $this->assertEquals($snapshotV2, $this->v2->fresh()->snapshot, 'POPRAWKA_2525_NIEZMIENNE: wersja 2 została zmieniona.');
        $this->assertSame(1, $this->v1->fresh()->version_number);
    }

    public function test_mozna_zastosowac_sama_czesc_i_reszta_zostaje(): void
    {
        $this->zastosuj(['dane'])->assertRedirect();

        $po = $this->przepis->fresh();
        $this->assertSame('Zupa Pierwsza', $po->title);
        $this->assertSame(['seler z wersji drugiej'], $po->ingredients()->pluck('ingredient_text')->all(), 'Składniki nietknięte.');
        $this->assertSame(['Zetrzyj seler.'], $po->steps()->pluck('instruction')->all(), 'Kroki nietknięte.');
    }

    public function test_kroki_ze_zdjeciami_nie_sa_przywracane_a_zdjecia_zostaja(): void
    {
        $zdjecie = Media::factory()->create(['owner_id' => $this->autor->getKey()]);
        RecipeStep::query()->where('recipe_id', $this->przepis->getKey())->update(['media_id' => $zdjecie->getKey()]);
        $przed = $this->stan();

        $html = (string) $this->actingAs($this->autor)->get(route('recipes.history.apply', [$this->przepis->slug, 1]))->getContent();
        $this->assertStringContainsString('Dzisiejsze kroki mają zdjęcia', $html);
        $this->assertStringNotContainsString('value="kroki"', $html);

        $this->zastosuj(['kroki'])->assertRedirect(route('recipes.history.apply', [$this->przepis->slug, 1]))->assertSessionHas('status_rodzaj', 'blad');

        $this->assertSame($przed, $this->stan(), 'POPRAWKA_2525_ZDJECIA_KROKOW: kroki ze zdjęciami zostały podmienione.');
        $this->assertSame($zdjecie->getKey(), $this->przepis->steps()->first()->media_id);
    }

    public function test_zmiana_przepisu_w_drugiej_karcie_odrzuca_zapis_bez_nadpisania(): void
    {
        $widziana = $this->przepis->fresh()->content_revision;
        $this->przepis->forceFill(['content_revision' => $widziana + 1, 'title' => 'Zmienione w drugiej karcie'])->save();
        $przed = $this->stan();

        $this->zastosuj(['dane'], $widziana)->assertRedirect(route('recipes.history.apply', [$this->przepis->slug, 1]))->assertSessionHas('status_rodzaj', 'blad');

        $this->assertSame($przed, $this->stan(), 'POPRAWKA_2525_KONFLIKT: zapis nadpisał nowszą treść.');
        $this->assertSame('Zmienione w drugiej karcie', $this->przepis->fresh()->title);
    }

    public function test_awaria_zapisu_wersji_cofa_cala_tresc(): void
    {
        $przed = $this->stan();
        RecipeVersion::creating(function (): void {
            throw new \RuntimeException('awaria zapisu migawki');
        });

        $this->withoutExceptionHandling();

        try {
            $this->zastosuj(['dane', 'skladniki', 'kroki']);
            $this->fail('Awaria nie przerwała zapisu.');
        } catch (\RuntimeException $e) {
            $this->assertSame('awaria zapisu migawki', $e->getMessage());
        }

        $this->assertSame($przed, $this->stan(), 'POPRAWKA_2525_ATOMOWOSC: treść zapisała się mimo awarii migawki.');
    }

    public function test_tylko_autor_aktywny_przy_opublikowanym_przepisie_a_nie_obcy_moderator_gosc(): void
    {
        $przed = $this->stan();

        foreach ([$this->user('obca'), $this->user('moderatorka', ['role' => User::ROLE_MODERATOR])] as $ktos) {
            $this->actingAs($ktos)->get(route('recipes.history.apply', [$this->przepis->slug, 1]))->assertForbidden();
            $odpowiedz = $this->zastosuj(['dane'], null, $ktos);
            $this->assertSame(403, $odpowiedz->getStatusCode(), 'POPRAWKA_2525_AUTOR: obca osoba zastosowała wersję.');
        }
        auth()->logout();
        $this->get(route('recipes.history.apply', [$this->przepis->slug, 1]))->assertRedirect(route('login'));

        $this->autor->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->actingAs($this->autor->refresh())->get(route('recipes.history.apply', [$this->przepis->slug, 1]))->assertForbidden();
        $this->assertSame($przed, $this->stan());
    }

    public function test_wersja_ukryta_nieistniejaca_i_najnowsza_nie_daja_zastosowania(): void
    {
        $this->actingAs($this->autor)->get(route('recipes.history.apply', [$this->przepis->slug, 9]))->assertNotFound();

        $this->actingAs($this->autor)->get(route('recipes.history.apply', [$this->przepis->slug, 2]))
            ->assertRedirect(route('recipes.history', $this->przepis->slug))->assertSessionHas('status_rodzaj', 'informacja');

        $this->v1->forceFill(['hidden_at' => now(), 'hidden_by_role' => RecipeVersion::UKRYL_AUTOR])->save();
        $this->zastosuj(['dane'])->assertRedirect(route('recipes.history.apply', [$this->przepis->slug, 1]))->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame('Zupa Druga', $this->przepis->fresh()->title, 'Ukryta wersja nie daje się zastosować.');
    }

    public function test_przepis_nieopublikowany_nie_ma_zastosowania_z_historii(): void
    {
        $this->przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();

        $this->actingAs($this->autor)->get(route('recipes.history.apply', [$this->przepis->slug, 1]))->assertNotFound();
    }

    public function test_stara_migawka_bez_kluczy_to_brak_danych_a_dzisiejsze_wartosci_zostaja(): void
    {
        $snapshot = $this->v1->snapshot;
        unset($snapshot['summary'], $snapshot['ingredients'], $snapshot['steps']);
        DB::table('recipe_versions')->where('id', $this->v1->getKey())->update(['snapshot' => json_encode($snapshot)]);

        $html = (string) $this->actingAs($this->autor)->get(route('recipes.history.apply', [$this->przepis->slug, 1]))->getContent();
        $this->assertStringContainsString('Ta wersja nie ma zapisanych składników', $html);
        $this->assertStringContainsString('Ta wersja nie ma zapisanych kroków', $html);
        $this->assertStringContainsString('brak danych w tej wersji', $html);

        $this->zastosuj(['dane'])->assertRedirect();
        $po = $this->przepis->fresh();
        $this->assertSame('Zupa Pierwsza', $po->title);
        $this->assertSame('Opis drugi', $po->summary, 'POPRAWKA_2525_BRAK_DANYCH: brakujące pole nadpisano.');

        // Składników nie da się zastosować, gdy wersja ich nie zapisała.
        $odpowiedz = $this->zastosuj(['skladniki'])->assertRedirect(route('recipes.history.apply', [$this->przepis->slug, 1]));
        $odpowiedz->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame(['seler z wersji drugiej'], $this->przepis->fresh()->ingredients()->pluck('ingredient_text')->all());
    }

    public function test_zmiana_skladnikow_uniewaznia_potwierdzone_alergeny(): void
    {
        $this->przepis->forceFill(['allergen_status' => Recipe::ALERGENY_ZDEKLAROWANE, 'allergens' => ['gluten'], 'allergens_declared_at' => now()])->save();

        $this->zastosuj(['skladniki'])->assertRedirect();

        $po = $this->przepis->fresh();
        $this->assertSame(Recipe::ALERGENY_DO_PRZEGLADU, $po->allergen_status, 'Po zmianie składników alergeny wymagają ponownego sprawdzenia.');
    }

    public function test_pusty_wybor_i_sekcja_bez_roznicy_daja_blad_po_polsku(): void
    {
        $przed = $this->stan();

        $this->actingAs($this->autor)->from(route('recipes.history.apply', [$this->przepis->slug, 1]))
            ->post(route('recipes.history.apply.store', [$this->przepis->slug, 1]), ['rewizja' => $this->przepis->fresh()->content_revision])
            ->assertSessionHasErrors(['sekcje' => 'Zaznacz, co z tej wersji chcesz zastosować. Nic nie zostało zmienione.']);

        $this->zastosuj(['dane'])->assertRedirect();
        // Drugi raz to samo: dane są już takie jak w wersji 1 — nic do zrobienia.
        $this->zastosuj(['dane'])->assertRedirect(route('recipes.history.apply', [$this->przepis->slug, 1]))->assertSessionHas('status_rodzaj', 'blad');

        $this->assertNotSame($przed, $this->stan());
        $this->assertSame(3, $this->stan()['wersje'], 'Drugie zastosowanie nie mnoży wersji.');
    }

    public function test_historia_wersji_ma_przycisk_tylko_dla_autora_i_nie_przy_najnowszej(): void
    {
        $autor = (string) $this->actingAs($this->autor)->get(route('recipes.history.version', [$this->przepis->slug, 1]))->getContent();
        $this->assertStringContainsString(route('recipes.history.apply', [$this->przepis->slug, 1]), $autor);

        $najnowsza = (string) $this->actingAs($this->autor)->get(route('recipes.history.version', [$this->przepis->slug, 2]))->getContent();
        $this->assertStringNotContainsString('Zastosuj jako nową poprawkę', $najnowsza);

        $obca = (string) $this->actingAs($this->user('obca'))->get(route('recipes.history.version', [$this->przepis->slug, 1]))->getContent();
        $this->assertStringNotContainsString('Zastosuj jako nową poprawkę', $obca);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Planer\Actions\UstawPorcjePlanu;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\PolecenieArtisanaZOdmowa;
use Tests\TestCase;

/**
 * Liczba planowanych porcji przy pozycji dnia w Planerze (V2, #2509).
 *
 * Czas zamrożony na czwartek 1 października 2026 (tydzień 28.09–04.10).
 */
final class PlanerPorcjeTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_10_06_200100_add_planned_servings_to_meal_plan_entries.php';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pozycja(User $kto, string $dzien, ?Recipe $przepis = null, ?string $tekst = null): MealPlanEntry
    {
        $wpis = new MealPlanEntry(['day' => $dzien, 'recipe_id' => $przepis?->getKey(), 'label' => $tekst]);
        $wpis->user_id = $kto->getKey();
        $wpis->save();

        return $wpis;
    }

    private function przepis(User $autor, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'servings' => 4,
            ...$atrybuty,
        ]);
    }

    private function ustaw(User $kto, MealPlanEntry $wpis, ?string $porcje, ?string $stan = null)
    {
        return $this->actingAs($kto)->patch(route('planer.servings', $wpis), [
            '_wiersz' => (string) $wpis->getKey(),
            'porcje' => $porcje,
            'stan' => $stan ?? UstawPorcjePlanu::znacznik($wpis->refresh()),
        ]);
    }

    public function test_ten_sam_przepis_w_dwoch_dniach_ma_niezalezne_porcje_a_receptura_zostaje(): void
    {
        $ja = $this->user('planujaca');
        $zupa = $this->przepis($ja, ['title' => 'Zupa ogorkowa']);
        $sroda = $this->pozycja($ja, '2026-09-30', $zupa);
        $niedziela = $this->pozycja($ja, '2026-10-04', $zupa);

        $this->ustaw($ja, $sroda, '2')->assertRedirect()->assertSessionHas('status');
        $this->ustaw($ja, $niedziela, '6')->assertRedirect();

        $odczyt1 = $sroda->refresh()->planned_servings;
        $this->assertSame(2.0, $odczyt1);
        $odczyt3 = $niedziela->refresh()->planned_servings;
        $this->assertSame(6.0, $odczyt3);
        $this->assertSame(4.0, (float) $zupa->fresh()->servings, 'Receptura autora nie zmienia się.');

        // Zmiana jednej pozycji nie rusza drugiej.
        $this->ustaw($ja, $sroda, '3')->assertRedirect();
        $odczyt2 = $sroda->refresh()->planned_servings;
        $this->assertSame(3.0, $odczyt2);
        $odczyt4 = $niedziela->refresh()->planned_servings;
        $this->assertSame(6.0, $odczyt4);
    }

    public function test_link_z_planera_niesie_porcje_a_brak_wyboru_otwiera_ilosci_autora(): void
    {
        $ja = $this->user('planujaca');
        $zupa = $this->przepis($ja, ['title' => 'Zupa ogorkowa', 'slug' => 'zupa-ogorkowa-plan']);
        $sroda = $this->pozycja($ja, '2026-09-30', $zupa);
        $niedziela = $this->pozycja($ja, '2026-10-04', $zupa);
        $this->ustaw($ja, $niedziela, '6');

        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('recipes.show', ['recipe' => 'zupa-ogorkowa-plan', 'porcje' => '6']).'"', $html);
        $this->assertStringContainsString('href="'.route('recipes.show', 'zupa-ogorkowa-plan').'"', $html);
        $this->assertStringContainsString('Planowane porcje: 6 porcji', $html);

        // Strona przepisu przenosi parametr do istniejącego mechanizmu skalowania.
        $this->actingAs($ja)->get(route('recipes.show', ['recipe' => 'zupa-ogorkowa-plan', 'porcje' => '6']))->assertOk();
        $this->assertNull($sroda->refresh()->planned_servings);
    }

    public function test_wybor_rowny_porcjom_autora_daje_zwykly_adres_bez_parametru(): void
    {
        $ja = $this->user('planujaca');
        $zupa = $this->przepis($ja, ['slug' => 'zupa-cztery']);
        $wpis = $this->pozycja($ja, '2026-10-01', $zupa);
        $this->ustaw($ja, $wpis, '4');

        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->getContent();

        $this->assertStringContainsString('href="'.route('recipes.show', 'zupa-cztery').'"', $html);
        $this->assertStringNotContainsString('porcje=4', $html);
    }

    public function test_wyczyszczenie_wyboru_wraca_do_ilosci_autora(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));
        $this->ustaw($ja, $wpis, '5');

        $this->ustaw($ja, $wpis, '  ')->assertRedirect()->assertSessionHas('status', 'Wybór porcji usunięty. Przepis otworzy się z ilościami autora.');
        $this->assertNull($wpis->refresh()->planned_servings);
    }

    public function test_bledne_wartosci_daja_blad_przy_polu_i_zachowuja_wpis(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));
        $this->ustaw($ja, $wpis, '3');

        foreach (['abc', '0', '0,5', '101', '-2', '2,555', '1e3'] as $zla) {
            $this->ustaw($ja, $wpis, $zla)
                ->assertSessionHasErrors('porcje')
                ->assertSessionHasInput('porcje', $zla);
            $this->assertSame(3.0, $wpis->refresh()->planned_servings, "Wartość „{$zla}” nie może zmienić zapisu.");
        }

        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->assertOk()->getContent();
        $this->assertStringContainsString('Planowane porcje: 3 porcje', $html);
    }

    public function test_przecinek_i_ulamek_sa_przyjmowane(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));

        $this->ustaw($ja, $wpis, '2,5')->assertSessionHasNoErrors();
        $this->assertSame(2.5, $wpis->refresh()->planned_servings);
    }

    public function test_przepis_bez_podanych_porcji_nie_udaje_przeliczenia(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja, ['servings' => null, 'slug' => 'bez-porcji']));

        $this->ustaw($ja, $wpis, '6')->assertSessionHasErrors('porcje');
        $this->assertNull($wpis->refresh()->planned_servings);

        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->getContent();
        $this->assertStringNotContainsString('Ustaw porcje', $html);
    }

    public function test_zmiana_podstawy_przez_autora_nie_wyswietla_falszywego_przeliczenia(): void
    {
        $ja = $this->user('planujaca');
        $przepis = $this->przepis($ja, ['slug' => 'zmienna-podstawa']);
        $wpis = $this->pozycja($ja, '2026-10-01', $przepis);
        $this->ustaw($ja, $wpis, '6');

        // Autor usuwa liczbę porcji: plan zostaje, ale link nie obiecuje przeliczenia.
        $przepis->forceFill(['servings' => null])->save();
        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->getContent();

        $this->assertStringContainsString('ten przepis nie podaje liczby porcji, więc ilości zostają jak u autora', $html);
        $this->assertStringNotContainsString('porcje=6', $html);

        // Autor zmienia podstawę na 8: link niesie plan osoby (6), przeliczany od aktualnej podstawy.
        $przepis->forceFill(['servings' => 8])->save();
        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->getContent();
        $this->assertStringContainsString('porcje=6', $html);
        $this->assertStringContainsString('Przepis jest na 8 porcji.', $html);
    }

    public function test_cudza_pozycja_i_wlasny_wpis_nie_przyjmuja_porcji(): void
    {
        $ja = $this->user('planujaca');
        $obca = $this->user('obca');
        $cudza = $this->pozycja($obca, '2026-10-01', $this->przepis($obca));
        $wlasny = $this->pozycja($ja, '2026-10-01', null, 'obiad u mamy');

        $this->ustaw($ja, $cudza, '4')->assertForbidden();
        $this->assertNull($cudza->refresh()->planned_servings);

        $this->ustaw($ja, $wlasny, '4')->assertRedirect()->assertSessionHas('status');
        $this->assertNull($wlasny->refresh()->planned_servings);
    }

    public function test_przepis_niedostepny_zachowuje_neutralna_pozycje_i_nie_przyjmuje_porcji(): void
    {
        $ja = $this->user('planujaca');
        $autor = $this->user('autor');
        $przepis = $this->przepis($autor, ['title' => 'Tajny-tytul']);
        $wpis = $this->pozycja($ja, '2026-10-01', $przepis);
        $this->ustaw($ja, $wpis, '4');
        $przepis->forceFill(['visibility' => 'private'])->save();

        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Tajny-tytul', $html);
        $this->assertStringContainsString('Przepis jest już niedostępny.', $html);
        $this->assertStringNotContainsString('porcje=', $html, 'Własna liczba nie tworzy furtki do przepisu.');

        $this->ustaw($ja, $wpis, '5')->assertSessionHas('status');
        $this->assertSame(4.0, $wpis->refresh()->planned_servings);
    }

    public function test_konflikt_kart_nie_nadpisuje_nowszego_wyboru(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));
        $this->ustaw($ja, $wpis, '2');
        $stary = UstawPorcjePlanu::znacznik($wpis->refresh());
        $this->ustaw($ja, $wpis, '3');

        $this->ustaw($ja, $wpis, '9', $stary)->assertSessionHasErrors('porcje');
        $this->assertSame(3.0, $wpis->refresh()->planned_servings);
    }

    public function test_ponowne_dodanie_przepisu_w_ten_sam_dzien_nie_zmienia_wybranych_porcji(): void
    {
        $ja = $this->user('planujaca');
        $przepis = $this->przepis($ja);
        $wpis = $this->pozycja($ja, '2026-10-01', $przepis);
        $this->ustaw($ja, $wpis, '6');

        $this->actingAs($ja)->post(route('planer.store'), ['day' => '2026-10-01', 'recipe_id' => $przepis->getKey()])->assertRedirect();

        $this->assertSame(1, MealPlanEntry::query()->count());
        $this->assertSame(6.0, $wpis->refresh()->planned_servings);
    }

    public function test_kopiowanie_poprzedniego_tygodnia_zachowuje_wybrane_porcje(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-09-23', $this->przepis($ja));
        $this->ustaw($ja, $wpis, '7');

        $this->actingAs($ja)->post(route('planer.copy'), ['tydzien' => '2026-09-28'])->assertRedirect();

        $kopia = MealPlanEntry::query()->whereDate('day', '2026-09-30')->sole();
        $this->assertSame(7.0, $kopia->planned_servings);
        $this->assertSame(7.0, $wpis->refresh()->planned_servings);
    }

    public function test_porcje_nie_sa_w_fillable_a_baza_pilnuje_zakresu_i_wlasnych_wpisow(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));

        try {
            $wpis->fill(['planned_servings' => 9]);
            $this->fail('planned_servings nie może być masowo przypisywane.');
        } catch (MassAssignmentException) {
            $this->addToAssertionCount(1);
        }

        foreach ([0, 0.99, 100.01] as $zla) {
            try {
                DB::transaction(fn () => DB::table('meal_plan_entries')->where('id', $wpis->getKey())->update(['planned_servings' => $zla]));
                $this->fail("CHECK przepuścił {$zla}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('meal_plan_entries_planned_servings_check', $e->getMessage());
            }
        }

        $wlasny = $this->pozycja($ja, '2026-10-02', null, 'obiad u mamy');
        $this->expectException(QueryException::class);
        DB::table('meal_plan_entries')->where('id', $wlasny->getKey())->update(['planned_servings' => 4]);
    }

    public function test_eksport_niesie_wybor_bez_tytulu_niedostepnego_przepisu_a_wymazanie_usuwa_dane(): void
    {
        $ja = $this->user('planujaca');
        $autor = $this->user('autor');
        $dostepny = $this->przepis($autor, ['title' => 'Widoczny']);
        $ukryty = $this->przepis($autor, ['title' => 'Ukryty-tytul']);
        $this->ustaw($ja, $this->pozycja($ja, '2026-10-01', $dostepny), '6');
        $this->ustaw($ja, $this->pozycja($ja, '2026-10-02', $ukryty), '3');
        $ukryty->forceFill(['visibility' => 'private'])->save();

        $plan = collect(app(CollectUserExportData::class)->handle($ja->refresh(), new ExportPhotoPlan($ja), now())['planer']);
        $this->assertSame([3.0, 6.0], $plan->pluck('planowane_porcje')->sort()->values()->all());
        $this->assertStringNotContainsString('Ukryty-tytul', (string) json_encode($plan->all(), JSON_UNESCAPED_UNICODE));

        $ja->markForDeletion(User::DELETE_SCOPE_EVERYTHING);
        $this->assertTrue((new EraseAccountData)->handle($ja->refresh()));
        $this->assertSame(0, MealPlanEntry::query()->whereNotNull('planned_servings')->count());
    }

    public function test_cofniecie_migracji_odmawia_gdy_zapisano_porcje_a_kontrola_dodatnia_przechodzi(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));
        $this->ustaw($ja, $wpis, '6');

        try {
            PolecenieArtisanaZOdmowa::wywolaj('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
            $this->fail('Cofnięcie przeszło, mimo że pozycja ma zapisaną liczbę porcji.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(meal_plan_entries.planned_servings IS NOT NULL): 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }
        $this->assertSame(6.0, $wpis->refresh()->planned_servings);

        // Kontrola dodatnia: bez zapisanych liczb rollback i ponowne wdrożenie przechodzą.
        DB::table('meal_plan_entries')->update(['planned_servings' => null]);
        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $odczyt5 = $this->kolumny();
        $this->assertSame(0, $odczyt5);
        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $odczyt6 = $this->kolumny();
        $this->assertSame(1, $odczyt6);
    }

    private function kolumny(): int
    {
        return count(DB::select("SELECT 1 FROM information_schema.columns WHERE table_name = 'meal_plan_entries' AND column_name = 'planned_servings'"));
    }
}

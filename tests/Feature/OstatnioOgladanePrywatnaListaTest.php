<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\OstatnioOgladane;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\RecentRecipeView;
use App\Models\Recipe;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Opcjonalna, prywatna lista ostatnio oglądanych przepisów (#2553, V2).
 *
 * To dane o zachowaniu człowieka, więc najważniejsze kontrole to prywatność:
 * domyślnie nic się nie zapisuje, lista należy do jednego konta, wyłączenie
 * i „Wyczyść” kasują od razu, a przepis, którego osoba już nie widzi, znika
 * z listy razem z tytułem. Kontrola ujemna z issue: zepsuty filtr widoczności
 * ujawnia tytuł prywatnego przepisu (test_przepis_ktory_przestal_byc_widoczny_znika_z_listy_razem_z_tytulem),
 * a zepsute przypisanie właściciela pokazuje listę A na koncie B
 * (test_dwa_konta_nie_widza_nawzajem_swoich_list).
 */
class OstatnioOgladanePrywatnaListaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function przepis(?User $autor = null, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create(['author_id' => ($autor ?? $this->user())->getKey(), ...$atrybuty]);
    }

    private function wlacz(User $osoba): void
    {
        $this->actingAs($osoba)->post(route('settings.ogladane.wlacz'))->assertRedirect(route('settings.ogladane'));
        $osoba->refresh();
    }

    private function otworz(User $osoba, Recipe $przepis): void
    {
        $this->actingAs($osoba)->get($przepis->url())->assertOk();
    }

    private function wiersze(User $osoba): int
    {
        return RecentRecipeView::query()->where('user_id', $osoba->getKey())->count();
    }

    public function test_domyslnie_wylaczone_otwieranie_przepisow_nic_nie_zapisuje(): void
    {
        $osoba = $this->user();
        $przepis = $this->przepis();

        $this->assertFalse($osoba->maWlaczoneOstatnioOgladane());
        $this->otworz($osoba, $przepis);
        $this->otworz($osoba, $przepis);

        $this->assertSame(0, RecentRecipeView::query()->count());
        $this->actingAs($osoba)->get(route('settings.ogladane'))
            ->assertOk()
            ->assertSee('Lista jest wyłączona')
            ->assertSee('Włącz listę')
            ->assertDontSee('Wyczyść listę');
    }

    public function test_wlaczenie_jest_zwyklym_post_i_zapis_zaczyna_sie_od_tej_chwili(): void
    {
        $osoba = $this->user();
        $dawny = $this->przepis();
        $this->otworz($osoba, $dawny);

        $this->wlacz($osoba);

        $this->assertTrue($osoba->maWlaczoneOstatnioOgladane());
        $this->assertSame(0, $this->wiersze($osoba), 'Dawne wizyty nie są odtwarzane.');

        $nowy = $this->przepis();
        $this->otworz($osoba, $nowy);

        $this->assertSame(1, $this->wiersze($osoba));
        $this->actingAs($osoba)->get(route('settings.ogladane'))
            ->assertOk()
            ->assertSee($nowy->title)
            ->assertDontSee($dawny->title);
    }

    public function test_ten_sam_przepis_to_jedna_pozycja_i_przesuwa_sie_na_gore(): void
    {
        $osoba = $this->user();
        $this->wlacz($osoba);
        $pierwszy = $this->przepis();
        $drugi = $this->przepis();

        $this->travelTo(Carbon::parse('2026-10-03 10:00:00', 'UTC'));
        $this->otworz($osoba, $pierwszy);
        $this->travelTo(Carbon::parse('2026-10-03 11:00:00', 'UTC'));
        $this->otworz($osoba, $drugi);
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00', 'UTC'));
        $this->otworz($osoba, $pierwszy);

        $this->assertSame(2, $this->wiersze($osoba), 'Powrót do tego samego przepisu nie dokłada drugiej pozycji.');
        $kolejnosc = app(OstatnioOgladane::class)->lista($osoba)->map(fn (array $p): string => $p['recipe']->title)->all();
        $this->assertSame([$pierwszy->title, $drugi->title], $kolejnosc);
    }

    public function test_gosc_nic_nie_zapisuje_a_publiczny_cache_strony_zostaje(): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $przepis = $this->przepis();

        $odpowiedz = $this->get($przepis->url())->assertOk();

        $this->assertTrue($odpowiedz->headers->hasCacheControlDirective('public'));
        $this->assertSame('120', $odpowiedz->headers->getCacheControlDirective('s-maxage'));
        $this->assertFalse($odpowiedz->headers->hasCacheControlDirective('no-store'));
        $this->assertCount(0, $odpowiedz->headers->getCookies());
        $this->assertSame(0, RecentRecipeView::query()->count());
        $this->get(route('settings.ogladane'))->assertRedirect(route('login'));
    }

    public function test_osoba_z_wlaczona_lista_dostaje_na_stronie_przepisu_i_liscie_private_no_store(): void
    {
        config(['kuking.html_cache.edge_seconds' => 120]);
        $osoba = $this->user();
        $this->wlacz($osoba);
        $przepis = $this->przepis();

        foreach ([$przepis->url(), route('settings.ogladane')] as $adres) {
            $odpowiedz = $this->actingAs($osoba)->get($adres)->assertOk();
            $this->assertTrue($odpowiedz->headers->hasCacheControlDirective('private'), $adres);
            $this->assertTrue($odpowiedz->headers->hasCacheControlDirective('no-store'), $adres);
            $this->assertFalse($odpowiedz->headers->hasCacheControlDirective('public'), $adres);
        }
    }

    public function test_zapis_idzie_po_odpowiedzi_a_nie_w_trakcie_budowania_strony(): void
    {
        $osoba = $this->user();
        $this->wlacz($osoba);
        $przepis = $this->przepis();

        Route::middleware('web')->get('/_2553/zapis', function () use ($przepis) {
            app(OstatnioOgladane::class)->zaplanujZapis(auth()->user(), $przepis);

            return (string) RecentRecipeView::query()->count();
        });

        $odpowiedz = $this->actingAs($osoba)->get('/_2553/zapis')->assertOk();

        $this->assertSame('0', $odpowiedz->getContent(), 'Zapis wizyty wykonał się przed odpowiedzią i spowalnia stronę przepisu.');
        $this->assertSame(1, RecentRecipeView::query()->count(), 'Po odpowiedzi wizyta powinna być zapisana.');
    }

    public function test_wlasny_przepis_nie_jest_zapamietywany(): void
    {
        $osoba = $this->user();
        $this->wlacz($osoba);
        $wlasny = $this->przepis($osoba);

        $this->otworz($osoba, $wlasny);

        $this->assertSame(0, $this->wiersze($osoba));
    }

    public function test_limit_pozycji_zostawia_tylko_najnowsze(): void
    {
        config(['kuking.ostatnio_ogladane.limit' => 3]);
        $osoba = $this->user();
        $this->wlacz($osoba);
        $przepisy = [];

        foreach (range(1, 5) as $i) {
            $this->travelTo(Carbon::parse('2026-10-03 10:00:00', 'UTC')->addMinutes($i));
            $przepisy[$i] = $this->przepis();
            $this->otworz($osoba, $przepisy[$i]);
        }

        $this->assertSame(3, $this->wiersze($osoba), 'Limit pozycji obowiązuje już przy zapisie.');
        $tytuly = app(OstatnioOgladane::class)->lista($osoba)->map(fn (array $p): string => $p['recipe']->title)->all();
        $this->assertSame([$przepisy[5]->title, $przepisy[4]->title, $przepisy[3]->title], $tytuly);
    }

    /**
     * Wiersze wstawione bokiem, jak po zmianie konfiguracji albo awarii zadania sprzątającego.
     *
     * @param  list<array{0: Recipe, 1: CarbonInterface}>  $pozycje
     */
    private function wstawBokiem(User $osoba, array $pozycje): void
    {
        foreach ($pozycje as [$przepis, $kiedy]) {
            DB::table('recent_recipe_views')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $osoba->getKey(),
                'recipe_id' => $przepis->getKey(),
                'viewed_at' => $kiedy,
            ]);
        }
    }

    public function test_stara_pozycja_jest_niewidoczna_przy_odczycie_jeszcze_przed_sprzataniem(): void
    {
        config(['kuking.ostatnio_ogladane.limit' => 10, 'kuking.ostatnio_ogladane.dni' => 7]);
        $osoba = $this->user();
        $this->wlacz($osoba);
        $stary = $this->przepis();
        $swiezy = $this->przepis();
        $this->wstawBokiem($osoba, [[$stary, now()->subDays(8)], [$swiezy, now()->subDays(6)]]);

        $odpowiedz = $this->actingAs($osoba)->get(route('settings.ogladane'))->assertOk();

        $odpowiedz->assertSee($swiezy->title);
        $this->assertStringNotContainsString($stary->title, $odpowiedz->getContent(), 'Pozycja starsza niż czas życia nie może się pokazać, zanim posprząta ją zadanie.');
    }

    public function test_nadliczbowa_pozycja_jest_niewidoczna_przy_odczycie_jeszcze_przed_sprzataniem(): void
    {
        config(['kuking.ostatnio_ogladane.limit' => 2, 'kuking.ostatnio_ogladane.dni' => 7]);
        $osoba = $this->user();
        $this->wlacz($osoba);
        $a = $this->przepis();
        $b = $this->przepis();
        $c = $this->przepis();
        $this->wstawBokiem($osoba, [[$a, now()->subHours(3)], [$b, now()->subHours(2)], [$c, now()->subHour()]]);

        $odpowiedz = $this->actingAs($osoba)->get(route('settings.ogladane'))->assertOk();

        $odpowiedz->assertSee($c->title)->assertSee($b->title);
        $this->assertStringNotContainsString($a->title, $odpowiedz->getContent(), 'Pozycja ponad limitem nie może się pokazać, zanim posprząta ją zadanie.');
    }

    public function test_wyczysc_kasuje_od_razu_jednym_kliknieciem_i_zostawia_funkcje_wlaczona(): void
    {
        $osoba = $this->user();
        $this->wlacz($osoba);
        $this->otworz($osoba, $this->przepis());
        $this->assertSame(1, $this->wiersze($osoba));

        $this->actingAs($osoba)->post(route('settings.ogladane.wyczysc'))
            ->assertRedirect(route('settings.ogladane'))
            ->assertSessionHas('status');

        $this->assertSame(0, $this->wiersze($osoba));
        $this->assertTrue($osoba->fresh()->maWlaczoneOstatnioOgladane());

        $this->otworz($osoba, $this->przepis());
        $this->assertSame(1, $this->wiersze($osoba), 'Po wyczyszczeniu zapamiętywanie działa dalej.');
    }

    public function test_wylaczenie_kasuje_historie_od_razu_i_zatrzymuje_zapis(): void
    {
        $osoba = $this->user();
        $this->wlacz($osoba);
        $this->otworz($osoba, $this->przepis());
        $this->otworz($osoba, $this->przepis());
        $this->assertSame(2, $this->wiersze($osoba));

        $this->actingAs($osoba)->post(route('settings.ogladane.wylacz'))
            ->assertRedirect(route('settings.ogladane'));

        $this->assertSame(0, $this->wiersze($osoba), 'Wyłączenie ma usunąć zapamiętane przepisy od razu.');
        $this->assertFalse($osoba->fresh()->maWlaczoneOstatnioOgladane());

        $this->otworz($osoba, $this->przepis());
        $this->assertSame(0, $this->wiersze($osoba));
    }

    public function test_zapis_po_wylaczeniu_w_trakcie_zadania_nie_odtwarza_historii(): void
    {
        $osoba = $this->user();
        $this->wlacz($osoba);
        $przepis = $this->przepis();

        // Okno uruchomiło zapis, a drugie okno zdążyło wyłączyć funkcję.
        app(OstatnioOgladane::class)->wylacz($osoba);
        app(OstatnioOgladane::class)->zapisz($osoba->fresh(), $przepis);

        $this->assertSame(0, RecentRecipeView::query()->count());
    }

    public function test_dwa_konta_nie_widza_nawzajem_swoich_list(): void
    {
        $a = $this->user();
        $b = $this->user();
        $this->wlacz($a);
        $this->wlacz($b);
        $dlaA = $this->przepis();
        $dlaB = $this->przepis();

        $this->otworz($a, $dlaA);
        $this->otworz($b, $dlaB);

        $listaA = $this->actingAs($a)->get(route('settings.ogladane'))->assertSee($dlaA->title)->getContent();
        $listaB = $this->actingAs($b)->get(route('settings.ogladane'))->assertSee($dlaB->title)->getContent();
        $this->assertStringNotContainsString($dlaB->title, $listaA, 'OSTATNIO_OGLADANE_2553_KONTO_A_WIDZI_LISTE_B');
        $this->assertStringNotContainsString($dlaA->title, $listaB, 'OSTATNIO_OGLADANE_2553_KONTO_B_WIDZI_LISTE_A');

        // „Wyczyść” u A nie rusza listy B.
        $this->actingAs($a)->post(route('settings.ogladane.wyczysc'));
        $this->assertSame(0, $this->wiersze($a));
        $this->assertSame(1, $this->wiersze($b));
    }

    public function test_przepis_ktory_przestal_byc_widoczny_znika_z_listy_razem_z_tytulem(): void
    {
        $osoba = $this->user();
        $this->wlacz($osoba);
        $autor = $this->user();
        $prywatny = $this->przepis($autor, ['title' => 'Sekretny sernik babci Zofii']);
        $ukryty = $this->przepis($autor, ['title' => 'Ukryta zupa moderacji']);
        $usuniety = $this->przepis($autor, ['title' => 'Skasowany bigos autora']);
        $zablokowany = $this->przepis($this->user(), ['title' => 'Przepis zablokowanej osoby']);
        $zbanowany = $this->przepis($this->user(), ['title' => 'Przepis zbanowanego konta']);
        $widoczny = $this->przepis($autor, ['title' => 'Nadal widoczny rosół']);

        $wszystkie = [$prywatny, $ukryty, $usuniety, $zablokowany, $zbanowany, $widoczny];
        foreach ($wszystkie as $p) {
            $this->otworz($osoba, $p);
        }
        $this->assertSame(6, $this->wiersze($osoba));

        $prywatny->forceFill(['visibility' => 'private'])->save();
        $ukryty->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();
        $usuniety->delete();
        $osoba->blocking()->attach($zablokowany->author_id, ['created_at' => now()]);
        $zbanowany->author->ban();

        $odpowiedz = $this->actingAs($osoba)->get(route('settings.ogladane'))->assertOk();

        $odpowiedz->assertSee('Nadal widoczny rosół');
        foreach (['Sekretny sernik babci Zofii', 'Ukryta zupa moderacji', 'Skasowany bigos autora', 'Przepis zablokowanej osoby', 'Przepis zbanowanego konta'] as $tytul) {
            $this->assertStringNotContainsString($tytul, $odpowiedz->getContent(), 'OSTATNIO_OGLADANE_2553_TYTUL_NIEDOSTEPNEGO_PRZEPISU '.$tytul);
        }
        $this->assertSame(['Nadal widoczny rosół'], app(OstatnioOgladane::class)->lista($osoba)->map(fn (array $p): string => $p['recipe']->title)->all());
    }

    public function test_wglad_moderatora_w_ukryty_przepis_nie_zostawia_pozycji(): void
    {
        $moderator = $this->moderator();
        $this->wlacz($moderator);
        $ukryty = $this->przepis(null, ['status' => Recipe::STATUS_HIDDEN]);
        $jawny = $this->przepis();

        $this->actingAs($moderator)->get($ukryty->url())->assertOk();
        $this->assertSame(0, $this->wiersze($moderator), 'Wgląd moderacyjny nie jest wizytą.');

        $this->otworz($moderator, $jawny);
        $this->assertSame(1, $this->wiersze($moderator));

        // Nawet gdyby pozycja ukrytego przepisu powstała bokiem, moderator nie zobaczy go na liście.
        DB::table('recent_recipe_views')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $moderator->getKey(),
            'recipe_id' => $ukryty->getKey(),
            'viewed_at' => now(),
        ]);
        $this->actingAs($moderator)->get(route('settings.ogladane'))->assertOk()->assertDontSee($ukryty->title)->assertSee($jawny->title);
    }

    public function test_lista_nie_zapisuje_nic_do_zeszytu_ani_ugotowalem_ani_powiadomien(): void
    {
        $osoba = $this->user();
        $this->wlacz($osoba);
        $przepis = $this->przepis();

        $this->otworz($osoba, $przepis);
        $this->actingAs($osoba)->get(route('settings.ogladane'))->assertOk();

        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertSame(0, Notification::query()->count());
        $this->assertSame(0, DB::table('collection_items')->count());
        $this->assertSame(0, DB::table('meal_plan_entries')->count());
        $this->assertSame(0, DB::table('cooking_progress')->count());
    }

    public function test_strona_ustawien_ma_cele_dotyku_tekst_bez_plci_i_jest_w_spisie_ustawien(): void
    {
        $osoba = $this->user();

        $this->actingAs($osoba)->get(route('settings.index'))->assertSee(route('settings.ogladane'), false)->assertSee('Ostatnio oglądane');
        $this->actingAs($osoba)->get(route('collections.index'))->assertSee(route('settings.ogladane'), false);

        $html = $this->actingAs($osoba)->get(route('settings.ogladane'))->assertOk()->getContent();
        $this->assertStringContainsString('class="btn btn-primary"', $html);
        $this->assertStringContainsString('najwyżej <strong>10 przepisów</strong>', $html);
        $this->assertStringContainsString('<strong>7 dni</strong>', $html);
    }

    public function test_pole_sterujace_nie_wchodzi_masowym_przypisaniem(): void
    {
        $this->assertNotContains('ostatnio_ogladane_wlaczone_at', (new User)->getFillable());
        $this->assertSame([], (new RecentRecipeView)->getFillable());

        $osoba = $this->user();

        try {
            $osoba->fill(['ostatnio_ogladane_wlaczone_at' => now()])->save();
        } catch (MassAssignmentException) {
            // Laravel w trybie ścisłym odmawia sam — to też jest dowodem.
        }

        $this->assertFalse($osoba->fresh()->maWlaczoneOstatnioOgladane(), 'Zgodę na listę włącza wyłącznie jawny przycisk, nigdy masowe przypisanie.');
    }

    public function test_sprzatanie_kasuje_stare_nadliczbowe_i_osierocone_a_na_sucho_tylko_liczy(): void
    {
        config(['kuking.ostatnio_ogladane.limit' => 2, 'kuking.ostatnio_ogladane.dni' => 7]);
        $wlaczona = $this->user();
        $this->wlacz($wlaczona);
        $wylaczona = $this->user();
        $wstaw = function (User $osoba, Recipe $przepis, $kiedy): void {
            DB::table('recent_recipe_views')->insert([
                'id' => (string) Str::uuid(),
                'user_id' => $osoba->getKey(),
                'recipe_id' => $przepis->getKey(),
                'viewed_at' => $kiedy,
            ]);
        };
        $wstaw($wlaczona, $this->przepis(), now()->subDays(9));   // po terminie
        $wstaw($wlaczona, $this->przepis(), now()->subHours(3));  // nadliczbowa
        $wstaw($wlaczona, $this->przepis(), now()->subHours(2));
        $wstaw($wlaczona, $this->przepis(), now()->subHour());
        $wstaw($wylaczona, $this->przepis(), now()->subHour());   // osierocona

        $this->artisan('kuking:sprzataj-ostatnio-ogladane', ['--na-sucho' => true])->expectsOutputToContain('Do skasowania: 3 ')->assertSuccessful();
        $this->assertSame(5, RecentRecipeView::query()->count());

        $this->artisan('kuking:sprzataj-ostatnio-ogladane')->expectsOutputToContain('Skasowano 3 ')->assertSuccessful();
        $this->assertSame(2, $this->wiersze($wlaczona));
        $this->assertSame(0, $this->wiersze($wylaczona));
    }

    public function test_zadanie_sprzatajace_jest_w_harmonogramie(): void
    {
        $nazwy = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description)->all();

        $this->assertContains('kuking:sprzataj-ostatnio-ogladane', $nazwy);
    }

    public function test_wymazanie_konta_kasuje_liste_i_zgode(): void
    {
        $osoba = $this->user();
        $inna = $this->user();
        $this->wlacz($osoba);
        $this->wlacz($inna);
        $this->otworz($osoba, $this->przepis());
        $this->otworz($inna, $this->przepis());

        $osoba->markForDeletion();
        app(EraseAccountData::class)->handle($osoba->fresh());

        $this->assertSame(0, $this->wiersze($osoba));
        $this->assertFalse($osoba->fresh()->maWlaczoneOstatnioOgladane());
        $this->assertSame(1, $this->wiersze($inna), 'Wymazanie jednego konta nie rusza listy drugiego.');
    }

    public function test_eksport_danych_konta_zawiera_liste_i_date_wlaczenia(): void
    {
        $osoba = $this->user();
        $this->wlacz($osoba);
        $przepis = $this->przepis();
        $this->otworz($osoba, $przepis);

        $dane = app(CollectUserExportData::class)->handle($osoba->fresh(), new ExportPhotoPlan($osoba->fresh()), Carbon::now());

        $this->assertNotNull($dane['konto']['ostatnio_ogladane_wlaczone_od']);
        $this->assertCount(1, $dane['ostatnio_ogladane']);
        $this->assertSame($przepis->title, $dane['ostatnio_ogladane'][0]['przepis']);
        $this->assertArrayHasKey('ostatnio_ogladano', $dane['ostatnio_ogladane'][0]);
        $this->assertSame(['przepis', 'ostatnio_ogladano'], array_keys($dane['ostatnio_ogladane'][0]), 'W paczce tylko przepis i czas, nic więcej.');
    }

    public function test_eksport_nie_podaje_tytulu_przepisu_ktory_przestal_byc_widoczny(): void
    {
        $osoba = $this->user();
        $this->wlacz($osoba);
        $przepis = $this->przepis(null, ['title' => 'Tajny rosół prywatny']);
        $this->otworz($osoba, $przepis);
        $przepis->forceFill(['visibility' => 'private'])->save();

        $dane = app(CollectUserExportData::class)->handle($osoba->fresh(), new ExportPhotoPlan($osoba->fresh()), Carbon::now());

        $this->assertStringNotContainsString('Tajny rosół prywatny', json_encode($dane, JSON_UNESCAPED_UNICODE) ?: '');
    }
}

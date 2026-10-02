<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Planer\Actions\OznaczPozycjePlanu;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\CookedEvent;
use App\Models\MealPlanEntry;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prywatne „Zrobione” przy pozycji planera (#2593, V2).
 *
 * Czas zamrożony na czwartek 1 października 2026 — tydzień 28.09–04.10.
 * Pomiary idą przez HTTP i końcowy HTML; akcja domenowa jest wołana wprost
 * tylko tam, gdzie issue wymaga kontroli niezależnej od trasy.
 */
final class PlanerZrobioneTest extends TestCase
{
    use RefreshDatabase;

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
            ...$atrybuty,
        ]);
    }

    private function znacznik(MealPlanEntry $wpis): string
    {
        return OznaczPozycjePlanu::znacznik($wpis->refresh());
    }

    private function oznacz(User $kto, MealPlanEntry $wpis, bool $zrobione, string $stan = '')
    {
        return $this->actingAs($kto)->patch(route('planer.done', $wpis), [
            'zrobione' => $zrobione ? '1' : '0',
            'stan' => $stan,
        ]);
    }

    public function test_przepis_i_wlasny_wpis_mozna_oznaczyc_i_cofnac_bez_zmiany_dnia_i_kolejnosci(): void
    {
        $ja = $this->user('planujaca');
        $zupa = $this->pozycja($ja, '2026-10-01', $this->przepis($ja, ['title' => 'Zupa ogorkowa']));
        $wlasny = $this->pozycja($ja, '2026-10-01', tekst: 'Obiad u mamy');
        $ciasto = $this->pozycja($ja, '2026-10-01', $this->przepis($ja, ['title' => 'Ciasto drozdzowe']));

        $this->assertNull($zupa->done_at, 'Nowa pozycja ma być nieoznaczona.');

        foreach ([$zupa, $wlasny] as $wpis) {
            $this->oznacz($ja, $wpis, true)
                ->assertRedirect(route('planer.show', ['tydzien' => '2026-10-01']))
                ->assertSessionHas('status', 'Pozycja jest oznaczona jako zrobiona. Widzisz to tylko Ty.');
            $this->assertNotNull($wpis->refresh()->done_at);
        }
        $this->assertNull($ciasto->refresh()->done_at);

        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->assertOk()->getContent();
        preg_match('~<section[^>]*aria-labelledby="dzien-2026-10-01"[^>]*>(.*?)</section>~s', $html, $m);
        $czwartek = $m[1];
        // Stan jest tekstem, a kolejność i dzień zostają.
        $this->assertSame(2, substr_count($czwartek, '<span class="planer-zrobione">Zrobione</span>'));
        $this->assertSame(2, substr_count($czwartek, 'Cofnij oznaczenie'));
        $this->assertSame(1, substr_count($czwartek, 'Oznacz jako zrobione'));
        $this->assertLessThan(strpos($czwartek, 'Obiad u mamy'), strpos($czwartek, 'Zupa ogorkowa'));
        $this->assertLessThan(strpos($czwartek, 'Ciasto drozdzowe'), strpos($czwartek, 'Obiad u mamy'));
        $this->assertSame(3, MealPlanEntry::query()->where('day', '2026-10-01')->count());

        // Cofnięcie przywraca stan nieoznaczony, pozycja zostaje w planie.
        $this->oznacz($ja, $zupa, false, $this->znacznik($zupa))
            ->assertSessionHas('status', 'Oznaczenie cofnięte. Pozycja znów czeka w planie.');
        $zupa->refresh();
        $this->assertNull($zupa->done_at);
        $this->assertSame('2026-10-01', $zupa->day->toDateString());
        $this->assertNotNull($zupa->recipe_id);
    }

    public function test_oznaczenie_nie_tworzy_ugotowania_ani_powiadomienia(): void
    {
        $autorka = $this->user('kucharka');
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($autorka));

        $this->oznacz($ja, $wpis, true);

        $this->assertNotNull($wpis->refresh()->done_at);
        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_cudza_pozycja_nie_zmienia_sie_i_nie_ujawnia_stanu(): void
    {
        $ja = $this->user('planujaca');
        $obca = $this->user('obca');
        $moja = $this->pozycja($ja, '2026-10-01', tekst: 'Tajny obiad');

        $this->oznacz($obca, $moja, true)->assertForbidden();
        $this->assertNull($moja->refresh()->done_at);

        // Własność sprawdza też sama domena, niezależnie od trasy.
        $wynik = app(OznaczPozycjePlanu::class)->handle($obca, (string) $moja->getKey(), true, '');
        $this->assertSame(OznaczPozycjePlanu::BRAK, $wynik, 'Domena musi traktować cudzą pozycję jak nieistniejącą.');
        $this->assertNull($moja->refresh()->done_at, 'Obca osoba nie może oznaczyć cudzej pozycji.');

        $this->oznacz($ja, $moja, true);
        $this->actingAs($obca)->get(route('planer.show'))->assertDontSee('Tajny obiad')->assertDontSee('Zrobione');
    }

    public function test_gosc_nie_oznacza(): void
    {
        $wpis = $this->pozycja($this->user('planujaca'), '2026-10-01', tekst: 'Obiad');

        $this->patch(route('planer.done', $wpis), ['zrobione' => '1'])->assertRedirect(route('login'));
        $this->assertNull($wpis->refresh()->done_at);
    }

    public function test_zawieszone_konto_nie_zapisuje_oznaczenia_przy_wolaniu_domeny(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', tekst: 'Obiad');
        User::query()->whereKey($ja->getKey())->update(['status' => User::STATUS_SUSPENDED]);

        // Obiekt `$ja` jest nieświeży (nadal „active”) — liczy się stan z bazy.
        $this->expectException(AuthorizationException::class);
        try {
            app(OznaczPozycjePlanu::class)->handle($ja, (string) $wpis->getKey(), true, '');
        } finally {
            $this->assertNull($wpis->refresh()->done_at);
        }
    }

    public function test_to_samo_zadanie_dwa_razy_jest_idempotentne(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', tekst: 'Obiad');

        $this->oznacz($ja, $wpis, true);
        $pierwszy = $this->znacznik($wpis);
        $this->assertNotSame('', $pierwszy);

        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', 'UTC'));
        $this->oznacz($ja, $wpis, true)
            ->assertSessionHas('status', 'Ta pozycja już jest oznaczona jako zrobiona.');
        $this->assertSame($pierwszy, $this->znacznik($wpis), 'Powtórka nie może przesunąć znacznika stanu.');

        $this->oznacz($ja, $wpis, false, $pierwszy);
        $this->oznacz($ja, $wpis, false, $pierwszy)
            ->assertSessionHas('status', 'Ta pozycja nie jest oznaczona jako zrobiona.');
        $this->assertNull($wpis->refresh()->done_at);
    }

    public function test_stary_formularz_z_innej_karty_nie_odwraca_nowszej_decyzji(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', tekst: 'Obiad');

        // Karta A pokazała „Cofnij oznaczenie” przy stanie T1.
        $this->oznacz($ja, $wpis, true);
        $stanKartyA = $this->znacznik($wpis);

        // Karta B: cofnięcie i ponowne oznaczenie później (stan T2).
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:00:00', 'UTC'));
        $this->oznacz($ja, $wpis, false, $stanKartyA);
        Carbon::setTestNow(Carbon::parse('2026-10-01 09:05:00', 'UTC'));
        $this->oznacz($ja, $wpis, true);
        $nowszy = $this->znacznik($wpis);
        $this->assertNotSame($stanKartyA, $nowszy);

        // Karta A wysyła stare „Cofnij oznaczenie”.
        $this->oznacz($ja, $wpis, false, $stanKartyA)
            ->assertSessionHas('status', fn ($s) => str_contains((string) $s, 'zmieniło się w innym oknie'));
        $this->assertSame($nowszy, $this->znacznik($wpis), 'Nowsza decyzja musi przetrwać.');
        $this->assertNotNull($wpis->refresh()->done_at);

        // Z aktualnym znacznikiem to samo żądanie przechodzi.
        $this->oznacz($ja, $wpis, false, $nowszy);
        $this->assertNull($wpis->refresh()->done_at);
    }

    public function test_usunieta_pozycja_nie_jest_odtwarzana(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', tekst: 'Obiad');
        $id = (string) $wpis->getKey();
        $wpis->delete();

        $this->actingAs($ja)->patch(route('planer.done', $id), ['zrobione' => '1'])->assertNotFound();
        $this->assertSame(OznaczPozycjePlanu::BRAK, app(OznaczPozycjePlanu::class)->handle($ja, $id, true, ''));
        $this->assertSame(0, MealPlanEntry::query()->count());
    }

    public function test_zly_stan_zadania_mowi_co_zrobic_i_nic_nie_zmienia(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', tekst: 'Obiad');

        $this->actingAs($ja)->patch(route('planer.done', $wpis), ['zrobione' => 'tak'])
            ->assertSessionHasErrors(['zrobione' => 'Nie wiemy, co zrobić z tą pozycją. Odśwież stronę i spróbuj jeszcze raz.']);
        $this->assertNull($wpis->refresh()->done_at);
    }

    public function test_przepis_ktory_przestal_byc_widoczny_zostaje_bez_tytulu_takze_po_oznaczeniu(): void
    {
        $ja = $this->user('planujaca');
        $schowany = $this->przepis($this->user('kucharka'), ['title' => 'Schowany sernik']);
        $wpis = $this->pozycja($ja, '2026-10-01', $schowany);
        $schowany->forceFill(['visibility' => 'private'])->save();

        $this->oznacz($ja, $wpis, true);

        $this->actingAs($ja)->get(route('planer.show'))
            ->assertOk()
            ->assertSee('Przepis jest już niedostępny.')
            ->assertSee('Zrobione')
            ->assertDontSee('Schowany sernik');
    }

    public function test_kopia_tygodnia_tworzy_pozycje_nieoznaczone_i_zachowuje_stan_zrodla_oraz_celu(): void
    {
        $ja = $this->user('planujaca');
        $gulasz = $this->przepis($ja, ['title' => 'Gulasz wegierski']);
        $zrodloPrzepis = $this->pozycja($ja, '2026-09-21', $gulasz);
        $zrodloWlasne = $this->pozycja($ja, '2026-09-23', tekst: 'Obiad u mamy');
        $this->oznacz($ja, $zrodloPrzepis, true);
        $this->oznacz($ja, $zrodloWlasne, true);
        // Cel już ma tę samą pozycję, oznaczoną — kopia jej nie rusza.
        $juzJest = $this->pozycja($ja, '2026-09-28', $gulasz);
        $this->oznacz($ja, $juzJest, true);
        $stanCelu = $this->znacznik($juzJest);
        $stanZrodla = $this->znacznik($zrodloPrzepis);

        $this->actingAs($ja)->post(route('planer.copy'), ['tydzien' => '2026-09-28'])->assertRedirect();

        $skopiowane = MealPlanEntry::query()->where('day', '2026-09-30')->where('label', 'Obiad u mamy')->firstOrFail();
        $this->assertNull($skopiowane->done_at, 'Kopia nie przenosi historii realizacji.');
        $this->assertSame($stanCelu, $this->znacznik($juzJest), 'Istniejąca pozycja celu zachowuje stan.');
        $this->assertSame($stanZrodla, $this->znacznik($zrodloPrzepis), 'Źródło zachowuje stan.');
        $this->assertNotNull($zrodloWlasne->refresh()->done_at);
        $this->assertSame(1, MealPlanEntry::query()->where('day', '2026-09-28')->count());
    }

    public function test_stan_trafia_do_paczki_danych(): void
    {
        $ja = $this->user('planujaca');
        $zrobiona = $this->pozycja($ja, '2026-10-01', tekst: 'Zupa');
        $this->pozycja($ja, '2026-10-02', tekst: 'Ciasto');
        $this->oznacz($ja, $zrobiona, true);

        $plan = app(CollectUserExportData::class)->handle($ja, new ExportPhotoPlan($ja), now())['planer'];

        $this->assertTrue($plan[0]['zrobione']);
        $this->assertNotNull($plan[0]['oznaczono_jako_zrobione']);
        $this->assertFalse($plan[1]['zrobione']);
        $this->assertNull($plan[1]['oznaczono_jako_zrobione']);
    }

    public function test_pole_done_at_nie_jest_masowo_przypisywalne(): void
    {
        $this->assertNotContains('done_at', (new MealPlanEntry)->getFillable());

        // Żądanie nie może ustawić stanu masowym przypisaniem.
        $this->expectException(MassAssignmentException::class);
        new MealPlanEntry(['day' => '2026-10-01', 'label' => 'Obiad', 'done_at' => now()]);
    }

    public function test_cofniecie_migracji_odmawia_przy_oznaczeniach_i_przechodzi_bez_nich(): void
    {
        $migracja = require base_path('database/migrations/2026_10_02_190000_add_done_at_to_meal_plan_entries.php');
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', tekst: 'Obiad');
        $this->oznacz($ja, $wpis, true);

        try {
            $migracja->down();
            $this->fail('Rollback skasował oznaczenia ludzi bez pytania.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SET done_at = NULL', $e->getMessage());
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('meal_plan_entries', 'done_at'));
        $this->assertNotNull($wpis->refresh()->done_at);

        // Kontrola dodatnia: bez oznaczeń rollback przechodzi, a up() wraca.
        DB::table('meal_plan_entries')->update(['done_at' => null]);
        $migracja->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('meal_plan_entries', 'done_at'));
        $migracja->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('meal_plan_entries', 'done_at'));
    }
}

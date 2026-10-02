<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Notifications\Push\KanalPush;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\AuditLogEntry;
use App\Models\Block;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Trzy decyzje właściciela z 1.10.2026 o wskazówkach od gotujących (#2352,
 * D-333): (1) czekająca prośba wygasa po 30 dniach, (2) autor może anulować
 * własną czekającą prośbę, (3) zgoda kucharza powiadamia autora — „Nie” i
 * wycofanie zgody NIGDY. Reszta modułu: `WskazowkiOdGotujacychTest`.
 *
 * @bez-kontroli-dodatniej Testy behawioralne (HTTP, baza, paczka danych), nie strażnik tekstu; kontrole mutacyjne wykonano ręcznie na każdej regule (wiek prośby, limit, Policy, stan, powiadomienie, migracja).
 */
final class WskazowkiWygasanieAnulowanieZgodaTest extends TestCase
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

    private function przepis(User $autor): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function wykonanie(User $kucharz, Recipe $przepis): CookedEvent
    {
        return CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepis->getKey(),
            'note' => 'Dodałem chrzan, wyszło lepiej.',
            'cooked_at' => now()->subDays(3),
        ]);
    }

    private function popros(User $autor, CookedEvent $wykonanie): RecipeHint
    {
        $this->actingAs($autor)->post(route('hints.propose', $wykonanie))->assertRedirect();

        return RecipeHint::query()->where('cooked_event_id', $wykonanie->getKey())->sole();
    }

    /** Postarza prośbę o podaną liczbę dni (tylko `created_at`, jak upływ czasu). */
    private function postarz(RecipeHint $h, int $dni, int $minut = 0): void
    {
        DB::table('recipe_hints')->where('id', $h->getKey())->update(['created_at' => now()->subDays($dni)->subMinutes($minut)]);
    }

    private function powiadomien(User $kto, string $typ): int
    {
        return Notification::query()->where('user_id', $kto->getKey())->where('type', $typ)->count();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  1. Wygaśnięcie po 30 dniach
    // ─────────────────────────────────────────────────────────────────────

    public function test_prosba_wygasa_dokladnie_po_trzydziestu_dniach_od_created_at(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $h = $this->popros($autor, $this->wykonanie($kucharz, $this->przepis($autor)));

        $this->postarz($h, 30, -1); // 29 dni 23 h 59 min
        $zywa = $h->fresh();
        $this->postarz($h, 30);
        $granica = $h->fresh();
        $this->postarz($h, 31);
        $stara = $h->fresh();

        $this->assertSame(
            ['29 dni 23:59' => [true, false], '30 dni' => [false, true], '31 dni' => [false, true]],
            [
                '29 dni 23:59' => [$zywa->czekaNaOdpowiedz(), $zywa->wygasla()],
                '30 dni' => [$granica->czekaNaOdpowiedz(), $granica->wygasla()],
                '31 dni' => [$stara->czekaNaOdpowiedz(), $stara->wygasla()],
            ],
        );
    }

    public function test_na_wygasla_prosbe_nie_da_sie_odpowiedziec_zgadzam_sie_ani_nie_a_nic_sie_nie_publikuje(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $przepis = $this->przepis($autor);
        $h = $this->popros($autor, $this->wykonanie($kucharz, $przepis));
        $this->postarz($h, 31);

        foreach (['hints.accept', 'hints.decline'] as $trasa) {
            $this->actingAs($kucharz)->post(route($trasa, $h))->assertSessionHas('status_rodzaj', 'blad');
        }

        $this->assertSame(RecipeHint::STATUS_PROPOSED, $h->fresh()->status);
        $this->assertNull($h->fresh()->decided_at);
        $this->assertSame(0, $this->powiadomien($autor, Notification::TYPE_HINT_ACCEPTED));
        $this->get(route('recipes.show', $przepis->slug))->assertDontSee('Wskazówki od gotujących');
    }

    public function test_kontrola_dodatnia_dzien_przed_terminem_zgoda_przechodzi(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $h = $this->popros($autor, $this->wykonanie($kucharz, $this->przepis($autor)));
        $this->postarz($h, 29);

        $this->actingAs($kucharz)->post(route('hints.accept', $h))->assertSessionHas('status_rodzaj', 'sukces');

        $this->assertSame(RecipeHint::STATUS_ACCEPTED, $h->fresh()->status);
    }

    public function test_kucharz_widzi_ze_prosba_wygasla_bez_przyciskow_a_autor_widzi_to_samo_co_po_nie(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($autor));
        $h = $this->popros($autor, $wykonanie);
        $this->postarz($h, 40);

        $this->actingAs($kucharz)->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertSee('Ta prośba wygasła')
            ->assertDontSee(route('hints.accept', $h), false)
            ->assertDontSee(route('hints.decline', $h), false);

        $this->actingAs($autor)->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertSee('Ta uwaga nie jest dostępna jako wskazówka.')
            ->assertDontSee('Czeka na odpowiedź')
            ->assertDontSee('Anuluj prośbę')
            ->assertDontSee('Poproś o zgodę');
    }

    public function test_wygasla_prosba_nie_liczy_sie_do_limitu_na_przepis_a_zywa_i_przyjeta_tak(): void
    {
        config(['kuking.wskazowki.na_przepis_max' => 2, 'kuking.wskazowki.na_dobe_max' => 20]);
        $autor = $this->user('autorka');
        $przepis = $this->przepis($autor);
        $kucharze = collect(range(1, 4))->map(fn (int $i) => $this->user("kucharz{$i}"));
        $wykonania = $kucharze->map(fn (User $k) => $this->wykonanie($k, $przepis));

        $wygasla = $this->popros($autor, $wykonania[0]);
        $przyjeta = $this->popros($autor, $wykonania[1]);
        $this->actingAs($kucharze[1])->post(route('hints.accept', $przyjeta))->assertRedirect();
        $this->postarz($wygasla, 31);

        // Przyjęta + wygasła: zajęte jedno miejsce, więc druga prośba przechodzi.
        $poZajetymMiejscu = $this->popros($autor, $wykonania[2]);
        // Teraz przyjęta + żywa = komplet (2): trzecia już nie.
        $this->actingAs($autor)->post(route('hints.propose', $wykonania[3]))->assertSessionHas('status_rodzaj', 'blad');

        $this->assertSame(
            ['hintow' => 3, 'wykonanie_3_ma_prosbe' => false, 'wygasla_nadal_w_bazie' => true],
            [
                'hintow' => RecipeHint::query()->count(),
                'wykonanie_3_ma_prosbe' => RecipeHint::query()->where('cooked_event_id', $wykonania[3]->getKey())->exists(),
                'wygasla_nadal_w_bazie' => $wygasla->fresh()->wygasla() && $poZajetymMiejscu->fresh()->czekaNaOdpowiedz(),
            ],
        );
    }

    public function test_wygasla_prosba_konczy_sprawe_nowej_prosby_o_to_samo_wykonanie_nie_ma(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($autor));
        $h = $this->popros($autor, $wykonanie);
        $this->postarz($h, 45);
        $notatekKucharza = $this->powiadomien($kucharz, Notification::TYPE_HINT_PROPOSED);

        $this->actingAs($autor)->post(route('hints.propose', $wykonanie))->assertSessionHas('status_rodzaj', 'blad');

        $this->assertSame(
            ['wierszy' => 1, 'powiadomien' => $notatekKucharza],
            ['wierszy' => RecipeHint::query()->count(), 'powiadomien' => $this->powiadomien($kucharz, Notification::TYPE_HINT_PROPOSED)],
        );
    }

    public function test_eksport_opisuje_wygasla_prosbe_kucharzowi_a_autorowi_jak_nie(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $h = $this->popros($autor, $this->wykonanie($kucharz, $this->przepis($autor)));
        $this->postarz($h, 40);

        $dla = fn (User $u): array => app(CollectUserExportData::class)->handle($u, new ExportPhotoPlan($u), now());

        $this->assertSame(
            ['kucharz' => 'prośba wygasła bez odpowiedzi', 'autor' => 'nie jest dostępna jako wskazówka'],
            [
                'kucharz' => $dla($kucharz)['wskazowki_z_moich_wykonan'][0]['stan'],
                'autor' => $dla($autor)['wskazowki_do_moich_przepisow'][0]['stan'],
            ],
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  2. Anulowanie własnej czekającej prośby
    // ─────────────────────────────────────────────────────────────────────

    public function test_autor_anuluje_prosbe_zwalnia_miejsce_w_limicie_a_kucharz_nie_dostaje_wiadomosci(): void
    {
        config(['kuking.wskazowki.na_przepis_max' => 1, 'kuking.wskazowki.na_dobe_max' => 20]);
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $inny = $this->user('basia');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->wykonanie($kucharz, $przepis);
        $drugie = $this->wykonanie($inny, $przepis);
        $h = $this->popros($autor, $wykonanie);

        // Komplet: druga prośba odbija się o limit.
        $this->actingAs($autor)->post(route('hints.propose', $drugie))->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame(0, RecipeHint::query()->where('cooked_event_id', $drugie->getKey())->count());
        $przed = $this->powiadomien($kucharz, Notification::TYPE_HINT_PROPOSED);

        $this->actingAs($autor)->post(route('hints.cancel', $h))->assertRedirect(route('cooked.show', $wykonanie).'#wskazowka-autor');
        $h = $h->fresh();

        // Slot wolny: ta sama druga prośba teraz przechodzi.
        $this->popros($autor, $drugie);

        $this->assertSame(
            [RecipeHint::STATUS_CANCELLED, null, null, $przed, 1],
            [
                $h->status,
                $h->decided_at,
                $h->withdrawn_at,
                $this->powiadomien($kucharz, Notification::TYPE_HINT_PROPOSED),
                AuditLogEntry::query()->where('action', 'recipe_hint.cancelled')->where('actor_id', $autor->getKey())->count(),
            ],
        );
        $this->assertSame(0, Notification::query()->where('user_id', $kucharz->getKey())->where('type', '!=', Notification::TYPE_HINT_PROPOSED)->count(), 'Anulowanie nie wysyła kucharzowi niczego.');
    }

    public function test_anulowac_moze_tylko_autor_kucharz_obcy_i_moderator_dostaja_403_a_stan_zostaje(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $h = $this->popros($autor, $this->wykonanie($kucharz, $this->przepis($autor)));

        foreach ([$kucharz, $this->user('obcy'), $this->moderator()] as $ktos) {
            $this->actingAs($ktos)->post(route('hints.cancel', $h))->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->post(route('hints.cancel', $h))->assertRedirect(route('login'));
        $this->assertSame(RecipeHint::STATUS_PROPOSED, $h->fresh()->status);

        // Kontrola dodatnia: ta sama trasa dla autora przechodzi.
        $this->actingAs($autor)->post(route('hints.cancel', $h))->assertRedirect();
        $this->assertSame(RecipeHint::STATUS_CANCELLED, $h->fresh()->status);
    }

    public function test_policy_cancel_tylko_autor_i_tylko_czekajaca_niewygasla(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $h = $this->popros($autor, $this->wykonanie($kucharz, $this->przepis($autor)));

        $this->assertSame(
            ['autor' => true, 'kucharz' => false, 'obcy' => false],
            [
                'autor' => $autor->can('cancel', $h),
                'kucharz' => $kucharz->can('cancel', $h),
                'obcy' => $this->user('obcy')->can('cancel', $h),
            ],
        );

        $this->postarz($h, 31);
        $wygasla = $autor->can('cancel', $h->fresh());
        $h->forceFill(['status' => RecipeHint::STATUS_ACCEPTED, 'decided_at' => now(), 'created_at' => now()])->save();
        $przyjeta = $autor->can('cancel', $h->fresh());

        $this->assertSame(['wygasla' => false, 'przyjeta' => false], ['wygasla' => $wygasla, 'przyjeta' => $przyjeta]);
    }

    public function test_po_odpowiedzi_kucharza_anulowanie_odmawia_bez_zdradzania_powodu(): void
    {
        $autor = $this->user('autorka');
        $przepis = $this->przepis($autor);
        $zgoda = $this->popros($autor, $this->wykonanie($k1 = $this->user('kucharz1'), $przepis));
        $odmowa = $this->popros($autor, $this->wykonanie($k2 = $this->user('kucharz2'), $przepis));
        $this->actingAs($k1)->post(route('hints.accept', $zgoda));
        $this->actingAs($k2)->post(route('hints.decline', $odmowa));

        $this->actingAs($autor)->post(route('hints.cancel', $zgoda))->assertSessionHas('status_rodzaj', 'blad');
        $komunikatPoZgodzie = session('status');
        $this->actingAs($autor)->post(route('hints.cancel', $odmowa))->assertSessionHas('status_rodzaj', 'blad');
        $komunikatPoOdmowie = session('status');

        $this->assertSame(
            [RecipeHint::STATUS_ACCEPTED, RecipeHint::STATUS_DECLINED, true],
            [$zgoda->fresh()->status, $odmowa->fresh()->status, $komunikatPoZgodzie === $komunikatPoOdmowie],
            'Stan zostaje, a komunikat nie zdradza, czy kucharz odmówił.',
        );
    }

    public function test_po_anulowaniu_kucharz_nie_moze_sie_zgodzic_a_ponowna_prosba_nie_istnieje(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->wykonanie($kucharz, $przepis);
        $h = $this->popros($autor, $wykonanie);
        $this->actingAs($autor)->post(route('hints.cancel', $h));

        $this->actingAs($kucharz)->post(route('hints.accept', $h))->assertSessionHas('status_rodzaj', 'blad');
        $this->actingAs($autor)->post(route('hints.propose', $wykonanie))->assertSessionHas('status_rodzaj', 'blad');
        $this->get(route('recipes.show', $przepis->slug))->assertDontSee('Wskazówki od gotujących');

        $this->assertSame([RecipeHint::STATUS_CANCELLED, 1], [$h->fresh()->status, RecipeHint::query()->count()]);
    }

    public function test_podwojne_anulowanie_jest_idempotentne_i_pisze_jeden_wpis_audytu(): void
    {
        $autor = $this->user('autorka');
        $h = $this->popros($autor, $this->wykonanie($this->user('marek'), $this->przepis($autor)));

        $this->actingAs($autor)->post(route('hints.cancel', $h))->assertSessionHas('status_rodzaj', 'informacja');
        $this->actingAs($autor)->post(route('hints.cancel', $h))->assertSessionHas('status_rodzaj', 'informacja');

        $this->assertSame(1, AuditLogEntry::query()->where('action', 'recipe_hint.cancelled')->count());
    }

    public function test_ekran_autora_ma_przycisk_anuluj_z_potwierdzeniem_a_kucharz_go_nie_widzi_i_dostaje_zdanie_o_wycofaniu(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($autor));
        $h = $this->popros($autor, $wykonanie);

        $this->actingAs($autor)->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertSee('Anuluj prośbę')
            ->assertSee(route('hints.cancel', $h), false)
            ->assertSee('Anulować prośbę?')
            ->assertSee('wygasa po 30 dniach');
        $this->actingAs($kucharz)->get(route('cooked.show', $wykonanie))
            ->assertDontSee(route('hints.cancel', $h), false);

        $this->actingAs($autor)->post(route('hints.cancel', $h));

        $this->actingAs($autor)->get(route('cooked.show', $wykonanie))
            ->assertSee('Ta prośba została anulowana.')
            ->assertDontSee('Anuluj prośbę');
        $this->actingAs($kucharz)->get(route('cooked.show', $wykonanie))
            ->assertSee('Prośba została wycofana')
            ->assertDontSee(route('hints.accept', $h), false)
            ->assertDontSee(route('hints.decline', $h), false);
    }

    public function test_eksport_opisuje_anulowana_prosbe_obu_stronom(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $h = $this->popros($autor, $this->wykonanie($kucharz, $this->przepis($autor)));
        $this->actingAs($autor)->post(route('hints.cancel', $h));

        $dla = fn (User $u): array => app(CollectUserExportData::class)->handle($u, new ExportPhotoPlan($u), now());

        $this->assertSame(
            ['kucharz' => 'autor przepisu wycofał prośbę', 'autor' => 'anulowana przeze mnie'],
            [
                'kucharz' => $dla($kucharz)['wskazowki_z_moich_wykonan'][0]['stan'],
                'autor' => $dla($autor)['wskazowki_do_moich_przepisow'][0]['stan'],
            ],
        );
    }

    public function test_anulowanie_dziala_mimo_blokady_miedzy_osobami(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $h = $this->popros($autor, $this->wykonanie($kucharz, $this->przepis($autor)));
        Block::query()->create(['blocker_id' => $kucharz->getKey(), 'blocked_id' => $autor->getKey()]);

        $this->actingAs($autor)->post(route('hints.cancel', $h))->assertRedirect();

        $this->assertSame(RecipeHint::STATUS_CANCELLED, $h->fresh()->status);
    }

    public function test_zawieszony_autor_anuluje_wlasna_prosbe_bo_to_wycofanie_a_nie_pisanie(): void
    {
        $autor = $this->user('autorka');
        $przepis = $this->przepis($autor);
        $h = $this->popros($autor, $this->wykonanie($this->user('marek'), $przepis));
        $drugie = $this->wykonanie($this->user('basia'), $przepis);
        $autor->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        // Kontrola ujemna: nowa prośba zawieszonego nadal jest zatrzymana.
        $this->actingAs($autor)->post(route('hints.propose', $drugie));
        $nowychProsb = RecipeHint::query()->where('cooked_event_id', $drugie->getKey())->count();

        $this->actingAs($autor)->post(route('hints.cancel', $h));

        $this->assertSame(
            ['nowa_prosba_zawieszonego' => 0, 'stan_po_anulowaniu' => RecipeHint::STATUS_CANCELLED],
            ['nowa_prosba_zawieszonego' => $nowychProsb, 'stan_po_anulowaniu' => $h->fresh()->status],
        );
    }

    public function test_baza_zna_stan_anulowana_ale_nie_pozwala_mu_miec_dat_decyzji(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->wykonanie($kucharz, $przepis);
        $baza = fn (array $nad = []) => array_merge([
            'id' => (string) Str::uuid(),
            'recipe_id' => $przepis->getKey(), 'cooked_event_id' => $wykonanie->getKey(),
            'author_id' => $autor->getKey(), 'cook_id' => $kucharz->getKey(),
            'status' => 'cancelled', 'decided_at' => null, 'withdrawn_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ], $nad);

        foreach (['decided_at', 'withdrawn_at'] as $kolumna) {
            try {
                DB::transaction(fn () => DB::table('recipe_hints')->insert($baza([$kolumna => now()])));
                $this->fail("Baza przyjęła anulowaną prośbę z {$kolumna}.");
            } catch (QueryException $e) {
                $this->assertStringContainsString('recipe_hints_stan_spojny_check', $e->getMessage());
            }
        }

        // Kontrola dodatnia: czysta anulowana prośba wchodzi.
        DB::table('recipe_hints')->insert($baza());
        $this->assertSame(1, DB::table('recipe_hints')->where('status', 'cancelled')->count());
    }

    public function test_cofniecie_migracji_anulowania_odmawia_gdy_sa_anulowane_i_przechodzi_gdy_ich_nie_ma(): void
    {
        $migracja = require base_path('database/migrations/2026_10_01_190000_add_cancelled_status_to_recipe_hints.php');
        $autor = $this->user('autorka');
        $h = $this->popros($autor, $this->wykonanie($this->user('marek'), $this->przepis($autor)));
        $this->actingAs($autor)->post(route('hints.cancel', $h));

        try {
            $migracja->down();
            $this->fail('Rollback przeszedł mimo anulowanych próśb w tabeli.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('w tabeli recipe_hints są takie wiersze: 1', $e->getMessage());
        }
        $this->assertSame(RecipeHint::STATUS_CANCELLED, $h->fresh()->status, 'Odmowa nie ruszyła danych.');

        // Kontrola dodatnia: bez anulowanych wierszy cofnięcie przechodzi, stary CHECK
        // nie zna już „cancelled", a ponowne `up()` go przywraca.
        DB::table('recipe_hints')->where('id', $h->getKey())->delete();
        $migracja->down();
        $this->assertSame(
            ['definicja_bez_cancelled' => true],
            ['definicja_bez_cancelled' => ! str_contains((string) DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'recipe_hints_status_check'"), 'cancelled')],
        );
        $migracja->up();
        $this->assertStringContainsString('cancelled', (string) DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'recipe_hints_status_check'"));
        $this->assertTrue((bool) DB::scalar("SELECT convalidated FROM pg_constraint WHERE conname = 'recipe_hints_stan_spojny_check'"));
    }

    public function test_wymazanie_konta_autora_usuwa_tez_jego_anulowane_prosby(): void
    {
        $odchodzi = $this->user('autorka');
        $h = $this->popros($odchodzi, $this->wykonanie($this->user('marek'), $this->przepis($odchodzi)));
        $this->actingAs($odchodzi)->post(route('hints.cancel', $h));
        $odchodzi->forceFill(['status' => User::STATUS_PENDING_DELETE, 'delete_requested_at' => now()->subDays(40)])->save();

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertNull($h->fresh());
    }

    // ─────────────────────────────────────────────────────────────────────
    //  3. Powiadomienie autora o zgodzie kucharza
    // ─────────────────────────────────────────────────────────────────────

    public function test_zgoda_kucharza_powiadamia_autora_raz_a_podwojne_klikniecie_nie_dubluje(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->wykonanie($kucharz, $przepis);
        $h = $this->popros($autor, $wykonanie);
        $this->assertSame(0, $this->powiadomien($autor, Notification::TYPE_HINT_ACCEPTED));

        $this->actingAs($kucharz)->post(route('hints.accept', $h));
        $this->actingAs($kucharz)->post(route('hints.accept', $h));

        $powiadomienie = Notification::query()->where('type', Notification::TYPE_HINT_ACCEPTED)->sole();
        $this->assertSame(
            [$autor->getKey(), $kucharz->getKey(), (string) $h->getKey(), (string) $wykonanie->getKey(), (string) $przepis->getKey(), $przepis->title, 'recipe_hint.accepted'],
            [$powiadomienie->user_id, $powiadomienie->actor_id, $powiadomienie->data['hint_id'], $powiadomienie->data['cooked_event_id'], $powiadomienie->data['recipe_id'], $powiadomienie->data['recipe_title'], $powiadomienie->type],
        );
        $this->assertSame(0, $this->powiadomien($kucharz, Notification::TYPE_HINT_ACCEPTED), 'Kucharz nie dostaje wiadomości o własnej zgodzie.');
    }

    public function test_nie_wycofanie_i_anulowanie_nigdy_nie_powiadamiaja_autora_o_zgodzie(): void
    {
        $autor = $this->user('autorka');
        $przepis = $this->przepis($autor);
        $k1 = $this->user('kucharz1');
        $k2 = $this->user('kucharz2');
        $k3 = $this->user('kucharz3');
        $odmowa = $this->popros($autor, $this->wykonanie($k1, $przepis));
        $wycofanie = $this->popros($autor, $this->wykonanie($k2, $przepis));
        $anulowana = $this->popros($autor, $this->wykonanie($k3, $przepis));
        $this->actingAs($k1)->post(route('hints.decline', $odmowa));
        $this->actingAs($k2)->post(route('hints.accept', $wycofanie));
        $poZgodzie = Notification::query()->where('user_id', $autor->getKey())->count();
        $this->actingAs($k2)->post(route('hints.withdraw', $wycofanie));
        $this->actingAs($autor)->post(route('hints.cancel', $anulowana));

        $this->assertSame(
            ['po zgodzie' => 1, 'po wycofaniu, nie i anulowaniu' => 1],
            ['po zgodzie' => $poZgodzie, 'po wycofaniu, nie i anulowaniu' => Notification::query()->where('user_id', $autor->getKey())->count()],
            'Poza jedną zgodą autor nie dostaje niczego.',
        );
    }

    public function test_zgoda_odrzucona_przez_policy_nie_wysyla_powiadomienia(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $h = $this->popros($autor, $this->wykonanie($kucharz, $this->przepis($autor)));
        Block::query()->create(['blocker_id' => $autor->getKey(), 'blocked_id' => $kucharz->getKey()]);

        $this->actingAs($kucharz)->post(route('hints.accept', $h))->assertSessionHas('status_rodzaj', 'blad');

        $this->assertSame([RecipeHint::STATUS_PROPOSED, 0], [$h->fresh()->status, $this->powiadomien($autor, Notification::TYPE_HINT_ACCEPTED)]);
    }

    public function test_powiadomienie_o_zgodzie_jest_na_liscie_autora_prowadzi_na_strone_przepisu_i_nie_idzie_pushem(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek', ['display_name' => 'Marek']);
        $przepis = $this->przepis($autor);
        $przepis->forceFill(['title' => 'Rosół babci'])->save();
        $h = $this->popros($autor, $this->wykonanie($kucharz, $przepis));
        $this->actingAs($kucharz)->post(route('hints.accept', $h));

        $this->actingAs($autor)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Marek: zgoda na wskazówkę')
            ->assertSee('Rosół babci')
            ->assertSee('Uwaga pojawia się na stronie Twojego przepisu, dopóki zgoda trwa.');

        $powiadomienie = Notification::query()->where('type', Notification::TYPE_HINT_ACCEPTED)->sole();
        $this->actingAs($autor)->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('recipes.show', $przepis->slug).'#wskazowki-gotujacych');
        $this->assertFalse(KanalPush::dotyczy(Notification::TYPE_HINT_ACCEPTED, $powiadomienie->data), 'Zgoda na wskazówkę jest tylko w serwisie.');

        // Przepis usunięty po zgodzie: uczciwa karta, bez „Zobacz" na 404.
        $przepis->delete();
        $this->assertNull(Notification::query()->findOrFail($powiadomienie->getKey())->adresDocelowy());
    }

    public function test_karta_zgody_nie_klamie_po_wycofaniu_zgody_i_nie_zdradza_wycofania(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek', ['display_name' => 'Marek']);
        $h = $this->popros($autor, $this->wykonanie($kucharz, $this->przepis($autor)));
        $this->actingAs($kucharz)->post(route('hints.accept', $h));

        $przed = (string) $this->actingAs($autor)->get(route('notifications.index'))->assertOk()->getContent();
        $this->actingAs($kucharz)->post(route('hints.withdraw', $h))->assertRedirect();
        $po = (string) $this->actingAs($autor)->get(route('notifications.index'))->assertOk()->getContent();

        // Kontrola dodatnia: karta jest na liście przed i po; po wycofaniu nie obiecuje
        // stanu i nie wspomina o wycofaniu (autor nie dowiaduje się o nim z karty).
        $this->assertStringContainsString('Marek: zgoda na wskazówkę', $przed);
        $this->assertStringContainsString('Marek: zgoda na wskazówkę', $po);
        $this->assertSame(RecipeHint::STATUS_WITHDRAWN, $h->fresh()->status);
        // Wycinek samej karty (strona ma poza nią inne teksty o wycofywaniu).
        $karta = mb_strtolower(mb_substr($po, (int) mb_strpos($po, 'Marek: zgoda na wskazówkę'), 600));
        $this->assertStringContainsString('dopóki zgoda trwa', $karta);
        $this->assertStringNotContainsString('stoi teraz', $karta);
        $this->assertStringNotContainsString('wycof', $karta);
    }

    public function test_karta_prosby_nie_obiecuje_stanu_po_odmowie_anulowaniu_i_wygasnieciu(): void
    {
        $autor = $this->user('autorka', ['display_name' => 'Halina']);
        $przepis = $this->przepis($autor);
        $kucharz = $this->user('marek');
        $odmowa = $this->popros($autor, $this->wykonanie($kucharz, $przepis));
        $this->actingAs($kucharz)->post(route('hints.decline', $odmowa))->assertRedirect();
        $kucharz2 = $this->user('basia');
        $anulowana = $this->popros($autor, $this->wykonanie($kucharz2, $przepis));
        $this->actingAs($autor)->post(route('hints.cancel', $anulowana))->assertRedirect();
        $kucharz3 = $this->user('celina');
        $wygasla = $this->popros($autor, $this->wykonanie($kucharz3, $przepis));
        $this->postarz($wygasla, 45);

        foreach ([$kucharz, $kucharz2, $kucharz3] as $odbiorca) {
            $strona = (string) $this->actingAs($odbiorca)->get(route('notifications.index'))->assertOk()->getContent();

            $this->assertStringContainsString('Halina prosi o zgodę na wskazówkę', $strona);
            $this->assertStringContainsString('Bez Twojej zgody uwaga nie pojawi się przy przepisie.', $strona);
            $this->assertStringNotContainsString('pokaże się tam tylko wtedy', $strona);
        }
    }

    public function test_zawieszony_autor_dostaje_powiadomienie_o_zgodzie_bo_zawieszenie_odcina_od_pisania_nie_od_wiadomosci(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $h = $this->popros($autor, $this->wykonanie($kucharz, $this->przepis($autor)));
        $autor->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->actingAs($kucharz)->post(route('hints.accept', $h));

        $this->assertSame(1, $this->powiadomien($autor, Notification::TYPE_HINT_ACCEPTED));
    }
}

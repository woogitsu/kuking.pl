<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Planer\Actions\ZapiszDopisekPlanu;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\CookedEvent;
use App\Models\MealPlanEntry;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Krótki prywatny dopisek przy przepisie w Planerze (#2549, V2).
 *
 * Czas zamrożony na czwartek 1 października 2026 — tydzień 28.09–04.10.
 * Pomiary idą przez HTTP i końcowy HTML; akcja domenowa jest wołana wprost
 * tylko tam, gdzie issue wymaga kontroli niezależnej od trasy.
 */
final class PlanerDopisekTest extends TestCase
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

    private function zapisz(User $kto, MealPlanEntry $wpis, ?string $tekst, ?string $stan = null)
    {
        return $this->actingAs($kto)->patch(route('planer.note', $wpis), [
            '_wiersz' => (string) $wpis->getKey(),
            'note' => $tekst,
            'stan' => $stan ?? ZapiszDopisekPlanu::znacznik($wpis->refresh()),
        ]);
    }

    public function test_dopisek_mozna_dodac_poprawic_i_wyczyscic_bez_zmiany_przepisu_dnia_i_liczby_pozycji(): void
    {
        $ja = $this->user('planujaca');
        $zupa = $this->pozycja($ja, '2026-10-01', $this->przepis($ja, ['title' => 'Zupa ogorkowa']));
        $ciasto = $this->pozycja($ja, '2026-10-01', $this->przepis($ja, ['title' => 'Ciasto drozdzowe']));
        $this->assertNull($zupa->note, 'Nowa pozycja nie ma dopisku.');

        $this->zapisz($ja, $zupa, 'Kolacja')
            ->assertRedirect(route('planer.show', ['tydzien' => '2026-10-01']))
            ->assertSessionHas('status', 'Dopisek zapisany. Widzisz go tylko Ty.');
        $this->assertSame('Kolacja', $zupa->refresh()->note);
        $this->assertNull($ciasto->refresh()->note, 'Dopisek nie przecieka na sąsiednią pozycję.');

        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->assertOk()->getContent();
        preg_match('~<section[^>]*aria-labelledby="dzien-2026-10-01"[^>]*>(.*?)</section>~s', $html, $m);
        $czwartek = $m[1];
        $this->assertStringContainsString('Dopisek: Kolacja', $czwartek);
        $this->assertSame(1, substr_count($czwartek, 'Zmień dopisek'));
        $this->assertSame(1, substr_count($czwartek, 'Dodaj dopisek'));
        $this->assertSame(2, substr_count($czwartek, 'Zapisz dopisek'));
        // Etykieta pola jest widoczna, a formularz ma novalidate.
        $this->assertStringContainsString('Dopisek (np. kolacja)', $czwartek);

        // Poprawka.
        $this->zapisz($ja, $zupa, '  Na   niedzielę z rodziną ')
            ->assertSessionHas('status', 'Dopisek zapisany. Widzisz go tylko Ty.');
        $this->assertSame('Na niedzielę z rodziną', $zupa->refresh()->note);

        // Wyczyszczenie pustym tekstem — pozycja zostaje.
        $this->zapisz($ja, $zupa, '')
            ->assertSessionHas('status', 'Dopisek usunięty. Pozycja zostaje w planie.');
        $zupa->refresh();
        $this->assertNull($zupa->note);
        $this->assertSame('2026-10-01', $zupa->day->toDateString());
        $this->assertNotNull($zupa->recipe_id);
        $this->assertSame(2, MealPlanEntry::query()->where('day', '2026-10-01')->count());
        $this->assertSame('Zupa ogorkowa', Recipe::query()->findOrFail($zupa->recipe_id)->title, 'Tytuł przepisu nie jest dopiskiem.');
    }

    public function test_ten_sam_przepis_w_dwoch_dniach_ma_rozne_dopiski(): void
    {
        $ja = $this->user('planujaca');
        $gulasz = $this->przepis($ja, ['title' => 'Gulasz wegierski']);
        $wtorek = $this->pozycja($ja, '2026-09-29', $gulasz);
        $sobota = $this->pozycja($ja, '2026-10-03', $gulasz);

        $this->zapisz($ja, $wtorek, 'Obiad');
        $this->zapisz($ja, $sobota, 'Kolacja');

        $this->assertSame('Obiad', $wtorek->refresh()->note);
        $this->assertSame('Kolacja', $sobota->refresh()->note);
    }

    public function test_zapis_dopisku_nie_tworzy_ugotowania_powiadomienia_ani_oznaczenia(): void
    {
        $autorka = $this->user('kucharka');
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($autorka));

        $this->zapisz($ja, $wpis, 'Kolacja');

        $this->assertSame(0, CookedEvent::query()->count());
        $this->assertSame(0, Notification::query()->count());
        $this->assertNull($wpis->refresh()->done_at);
    }

    public function test_cudza_pozycja_nie_zmienia_sie_i_nie_ujawnia_dopisku(): void
    {
        $ja = $this->user('planujaca');
        $obca = $this->user('obca');
        $moja = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));
        $this->zapisz($ja, $moja, 'Tajna kolacja');

        $this->zapisz($obca, $moja, 'Przejęte', ZapiszDopisekPlanu::znacznik($moja->refresh()))->assertForbidden();
        $this->assertSame('Tajna kolacja', $moja->refresh()->note, 'Obca osoba nie może zmienić cudzego dopisku.');

        // Własność sprawdza też sama domena, niezależnie od trasy.
        $wynik = app(ZapiszDopisekPlanu::class)->handle($obca, (string) $moja->getKey(), null, ZapiszDopisekPlanu::znacznik($moja));
        $this->assertSame(ZapiszDopisekPlanu::BRAK, $wynik, 'Domena musi traktować cudzą pozycję jak nieistniejącą.');
        $this->assertSame('Tajna kolacja', $moja->refresh()->note, 'Obca osoba nie może wyczyścić cudzego dopisku.');

        $this->actingAs($obca)->get(route('planer.show'))->assertDontSee('Tajna kolacja');
    }

    public function test_gosc_nie_zapisuje_dopisku(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));

        $this->patch(route('planer.note', $wpis), ['note' => 'Kolacja'])->assertRedirect(route('login'));
        $this->assertNull($wpis->refresh()->note);
    }

    public function test_za_dlugi_tekst_zostaje_w_polu_i_ma_polski_komunikat_przy_polu_i_w_podsumowaniu(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja, ['title' => 'Zupa ogorkowa']));
        $za_dlugi = str_repeat('a', 81);

        $this->zapisz($ja, $wpis, $za_dlugi)
            ->assertSessionHasErrors(['note' => 'Dopisek może mieć najwyżej 80 znaków. Skróć go i zapisz jeszcze raz.']);
        $this->assertNull($wpis->refresh()->note);

        // Po przekierowaniu strona pokazuje błąd i wpisany tekst.
        $html = (string) $this->actingAs($ja)->from(route('planer.show'))->followingRedirects()
            ->patch(route('planer.note', $wpis), ['_wiersz' => (string) $wpis->getKey(), 'note' => $za_dlugi, 'stan' => ''])
            ->assertOk()->getContent();
        $komunikat = 'Dopisek może mieć najwyżej 80 znaków. Skróć go i zapisz jeszcze raz.';
        // Wpisany tekst nie znika, a pole jest otwarte, oznaczone i opisane.
        $this->assertStringContainsString('value="'.$za_dlugi.'"', $html);
        $this->assertMatchesRegularExpression('~<details class="planer-dopisek"\s+open\s*>~', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('<span class="field-error" id="f-note-'.$wpis->getKey().'-error">'.$komunikat.'</span>', $html);
        $this->assertStringContainsString('<a href="#f-note-'.$wpis->getKey().'">'.$komunikat.'</a>', $html);
        $this->assertStringContainsString('id="f-note-'.$wpis->getKey().'"', $html);

        // Dokładnie 80 znaków (także wielobajtowych) przechodzi.
        $this->zapisz($ja, $wpis, str_repeat('ż', 80));
        $this->assertSame(str_repeat('ż', 80), $wpis->refresh()->note);
    }

    public function test_domena_odrzuca_za_dlugi_tekst_i_wiele_wierszy_zwija_do_jednego(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));

        $this->assertSame(ZapiszDopisekPlanu::ZA_DLUGI, app(ZapiszDopisekPlanu::class)->handle($ja, (string) $wpis->getKey(), str_repeat('a', 81), ''));
        $this->assertNull($wpis->refresh()->note);

        $this->actingAs($ja)->patch(route('planer.note', $wpis), ['note' => "Kolacja\nz mamą", 'stan' => ''])
            ->assertSessionHasErrors(['note' => 'Wpisz dopisek w jednym wierszu, bez nowych linii, i zapisz jeszcze raz.']);
        $this->assertNull($wpis->refresh()->note);
        $this->assertSame('Kolacja z mamą', ZapiszDopisekPlanu::normalizuj("Kolacja\n\n z   mamą "));
    }

    public function test_tekst_z_kodem_html_jest_wypisany_jako_tekst(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));
        $this->zapisz($ja, $wpis, '<b>x</b>');

        $this->actingAs($ja)->get(route('planer.show'))->assertOk()
            ->assertSee('Dopisek: &lt;b&gt;x&lt;/b&gt;', false)
            ->assertDontSee('Dopisek: <b>x</b>', false);
    }

    public function test_powtorzone_to_samo_zadanie_jest_idempotentne(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));

        $this->zapisz($ja, $wpis, 'Kolacja', '');
        $this->zapisz($ja, $wpis, 'Kolacja', '')
            ->assertSessionHas('status', 'Ten dopisek już jest zapisany.');
        $this->assertSame('Kolacja', $wpis->refresh()->note);

        $this->zapisz($ja, $wpis, null);
        $this->zapisz($ja, $wpis, null, '')
            ->assertSessionHas('status', 'Ta pozycja nie ma dopisku.');
        $this->assertNull($wpis->refresh()->note);
    }

    public function test_stara_karta_nie_nadpisuje_nowszego_dopisku_i_nie_traci_wpisanego_tekstu(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));
        $this->zapisz($ja, $wpis, 'Obiad');
        $stanKartyA = ZapiszDopisekPlanu::znacznik($wpis->refresh());

        // Karta B zmienia dopisek.
        $this->zapisz($ja, $wpis, 'Kolacja');
        $this->assertSame('Kolacja', $wpis->refresh()->note);

        // Karta A wysyła swoją wersję ze starym znacznikiem.
        $this->actingAs($ja)->patch(route('planer.note', $wpis), ['_wiersz' => (string) $wpis->getKey(), 'note' => 'Sniadanie', 'stan' => $stanKartyA])
            ->assertSessionHasErrors('note');
        $this->assertStringContainsString('zmienił się w innym oknie', (string) session('errors')->first('note'));
        $this->assertSame('Kolacja', $wpis->refresh()->note, 'Nowszy dopisek musi przetrwać.');

        // Wpisany tekst wraca do pola.
        $html = (string) $this->actingAs($ja)->from(route('planer.show'))->followingRedirects()
            ->patch(route('planer.note', $wpis), ['_wiersz' => (string) $wpis->getKey(), 'note' => 'Sniadanie', 'stan' => $stanKartyA])
            ->getContent();
        $this->assertStringContainsString('value="Sniadanie"', $html);

        // Z aktualnym znacznikiem to samo żądanie przechodzi.
        $this->zapisz($ja, $wpis, 'Sniadanie');
        $this->assertSame('Sniadanie', $wpis->refresh()->note);
    }

    public function test_stary_znacznik_nie_wyczysci_nowszego_dopisku(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));
        $this->zapisz($ja, $wpis, 'Obiad');
        $stanKartyA = ZapiszDopisekPlanu::znacznik($wpis->refresh());
        $this->zapisz($ja, $wpis, 'Kolacja');

        $this->assertSame(
            ZapiszDopisekPlanu::KONFLIKT,
            app(ZapiszDopisekPlanu::class)->handle($ja, (string) $wpis->getKey(), null, $stanKartyA),
        );
        $this->assertSame('Kolacja', $wpis->refresh()->note);
    }

    public function test_wlasny_wpis_nie_przyjmuje_dopisku(): void
    {
        $ja = $this->user('planujaca');
        $wlasny = $this->pozycja($ja, '2026-10-01', tekst: 'Obiad u mamy');

        $this->zapisz($ja, $wlasny, 'Kolacja', '')
            ->assertSessionHas('status', fn ($s) => str_contains((string) $s, 'tylko do pozycji z przepisem'));
        $this->assertNull($wlasny->refresh()->note);

        $html = (string) $this->actingAs($ja)->get(route('planer.show'))->getContent();
        $this->assertStringNotContainsString('Dodaj dopisek', $html);

        // Baza też tego pilnuje, nie tylko akcja.
        $this->expectException(QueryException::class);
        DB::table('meal_plan_entries')->where('id', $wlasny->getKey())->update(['note' => 'Kolacja']);
    }

    public function test_baza_odrzuca_pusty_i_za_dlugi_dopisek(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));

        // Pusty (same spacje) łapie więz `meal_plan_entries_note_check`, za długi — typ `varchar(80)`.
        foreach (['   ' => 'meal_plan_entries_note_check', str_repeat('a', 81) => 'varying(80)'] as $zly => $przyczyna) {
            try {
                DB::transaction(fn () => DB::table('meal_plan_entries')->where('id', $wpis->getKey())->update(['note' => $zly]));
                $this->fail('Baza przyjęła niepoprawny dopisek.');
            } catch (QueryException $e) {
                $this->assertStringContainsString($przyczyna, $e->getMessage());
            }
        }
        $this->assertNull($wpis->refresh()->note);
    }

    public function test_przepis_niedostepny_zostaje_neutralny_a_wlasny_dopisek_jest_widoczny_i_nie_kopiuje_tytulu(): void
    {
        $ja = $this->user('planujaca');
        $schowany = $this->przepis($this->user('kucharka'), ['title' => 'Schowany sernik']);
        $wpis = $this->pozycja($ja, '2026-10-01', $schowany);
        $this->zapisz($ja, $wpis, 'Kolacja');
        $schowany->forceFill(['visibility' => 'private'])->save();

        $this->actingAs($ja)->get(route('planer.show'))
            ->assertOk()
            ->assertSee('Przepis jest już niedostępny.')
            ->assertSee('Dopisek: Kolacja')
            ->assertDontSee('Schowany sernik');
        $this->assertSame('Kolacja', $wpis->refresh()->note);

        // Dopisek da się wyczyścić także wtedy.
        $this->zapisz($ja, $wpis, '');
        $this->assertNull($wpis->refresh()->note);
    }

    public function test_dopisek_zostaje_po_twardym_usunieciu_przepisu_a_kasowanie_przepisu_sie_udaje(): void
    {
        $ja = $this->user('planujaca');
        $przepis = $this->przepis($ja, ['title' => 'Do usuniecia']);
        $wpis = $this->pozycja($ja, '2026-10-01', $przepis);
        $this->zapisz($ja, $wpis, 'Kolacja');

        DB::table('recipes')->where('id', $przepis->getKey())->delete();

        $wpis->refresh();
        $this->assertNull($wpis->recipe_id);
        $this->assertSame('Kolacja', $wpis->note);
        $this->actingAs($ja)->get(route('planer.show'))->assertOk()
            ->assertSee('Przepis został usunięty.')
            ->assertSee('Dopisek: Kolacja');
    }

    public function test_kopia_tygodnia_przenosi_dopisek_i_nie_nadpisuje_istniejacego(): void
    {
        $ja = $this->user('planujaca');
        $gulasz = $this->przepis($ja, ['title' => 'Gulasz wegierski']);
        $zupa = $this->przepis($ja, ['title' => 'Zupa pomidorowa']);
        $zrodloGulasz = $this->pozycja($ja, '2026-09-21', $gulasz);
        $zrodloZupa = $this->pozycja($ja, '2026-09-22', $zupa);
        $zrodloBez = $this->pozycja($ja, '2026-09-23', $this->przepis($ja, ['title' => 'Salatka']));
        $this->zapisz($ja, $zrodloGulasz, 'Kolacja');
        $this->zapisz($ja, $zrodloZupa, 'Obiad dla dzieci');
        // Cel już ma zupę z własnym, ręcznym dopiskiem — kopia go nie rusza i nie dubluje.
        $juzJest = $this->pozycja($ja, '2026-09-29', $zupa);
        $this->zapisz($ja, $juzJest, 'Moj reczny tekst');

        $this->actingAs($ja)->post(route('planer.copy'), ['tydzien' => '2026-09-28'])->assertRedirect();

        $this->assertSame('Kolacja', MealPlanEntry::query()->where('day', '2026-09-28')->where('recipe_id', $gulasz->getKey())->firstOrFail()->note, 'Kopia ma zachować dopisek.');
        $this->assertNull(MealPlanEntry::query()->where('day', '2026-09-30')->firstOrFail()->note);
        $this->assertSame(1, MealPlanEntry::query()->where('day', '2026-09-29')->where('recipe_id', $zupa->getKey())->count());
        $this->assertSame('Moj reczny tekst', $juzJest->refresh()->note, 'Kopia nie nadpisuje ręcznego tekstu.');
        // Źródło bez zmian.
        $this->assertSame('Kolacja', $zrodloGulasz->refresh()->note);
        $this->assertNull($zrodloBez->refresh()->note);
    }

    public function test_dopisek_trafia_do_paczki_danych_a_wymazanie_konta_go_kasuje(): void
    {
        $ja = $this->user('planujaca');
        $inna = $this->user('inna');
        $a = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));
        $b = $this->pozycja($ja, '2026-10-02', $this->przepis($ja));
        $cudza = $this->pozycja($inna, '2026-10-01', $this->przepis($inna));
        $this->zapisz($ja, $a, 'Kolacja');
        $this->zapisz($inna, $cudza, 'Cudza kolacja');

        $plan = app(CollectUserExportData::class)->handle($ja, new ExportPhotoPlan($ja), now())['planer'];
        $this->assertSame('Kolacja', $plan[0]['dopisek']);
        $this->assertNull($plan[1]['dopisek']);
        $this->assertArrayHasKey('dopisek', $plan[1]);
        $this->assertNotNull($b->refresh());

        $ja->markForDeletion();
        app(EraseAccountData::class)->handle($ja->fresh());

        $this->assertSame(0, DB::table('meal_plan_entries')->where('user_id', $ja->getKey())->count(), 'Wymazanie konta ma usunąć dopiski.');
        $this->assertSame('Cudza kolacja', $cudza->refresh()->note, 'Cudze dopiski zostają.');
    }

    public function test_zawieszone_konto_nie_zapisuje_dopisku_przy_wolaniu_domeny(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));
        User::query()->whereKey($ja->getKey())->update(['status' => User::STATUS_SUSPENDED]);

        $this->expectException(AuthorizationException::class);
        try {
            app(ZapiszDopisekPlanu::class)->handle($ja, (string) $wpis->getKey(), 'Kolacja', '');
        } finally {
            $this->assertNull($wpis->refresh()->note);
        }
    }

    public function test_usunieta_pozycja_nie_jest_odtwarzana(): void
    {
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));
        $id = (string) $wpis->getKey();
        $wpis->delete();

        $this->actingAs($ja)->patch(route('planer.note', $id), ['note' => 'Kolacja'])->assertNotFound();
        $this->assertSame(ZapiszDopisekPlanu::BRAK, app(ZapiszDopisekPlanu::class)->handle($ja, $id, 'Kolacja', ''));
        $this->assertSame(0, MealPlanEntry::query()->count());
    }

    public function test_pole_note_nie_jest_masowo_przypisywalne(): void
    {
        $this->assertNotContains('note', (new MealPlanEntry)->getFillable());

        $this->expectException(MassAssignmentException::class);
        new MealPlanEntry(['day' => '2026-10-01', 'note' => 'Kolacja']);
    }

    public function test_cofniecie_migracji_odmawia_przy_dopiskach_i_przechodzi_bez_nich(): void
    {
        $migracja = require base_path('database/migrations/2026_10_03_130000_add_note_to_meal_plan_entries.php');
        $ja = $this->user('planujaca');
        $wpis = $this->pozycja($ja, '2026-10-01', $this->przepis($ja));
        $this->zapisz($ja, $wpis, 'Kolacja');

        try {
            $migracja->down();
            $this->fail('Rollback skasował dopiski ludzi bez pytania.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('SET note = NULL', $e->getMessage());
        }
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('meal_plan_entries', 'note'));
        $this->assertSame('Kolacja', $wpis->refresh()->note);

        // Kontrola dodatnia: bez dopisków rollback przechodzi, a up() wraca.
        DB::table('meal_plan_entries')->update(['note' => null]);
        $migracja->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('meal_plan_entries', 'note'));
        $migracja->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('meal_plan_entries', 'note'));
    }
}

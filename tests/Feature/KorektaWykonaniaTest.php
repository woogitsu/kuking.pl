<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Recipes\TypowyCzasPrzepisu;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Support\PolecenieArtisanaZOdmowa;
use Tests\TestCase;

/**
 * Korekta własnej uwagi, opisu zmian i czasu wykonania „Ugotowałem"
 * (issue #2459, decyzja właściciela z 2.10.2026, D-333).
 *
 * Granice, które ten plik pilnuje:
 *  - to samo wykonanie: id, kucharz, przepis, wersja, `cooked_at`,
 *    `created_at`, odpowiedzi i rozmowa zostają; nie ma nowego wykonania
 *    ani nowego powiadomienia autora przepisu;
 *  - poprawiać może wyłącznie aktywny kucharz — nie obcy, nie autor
 *    przepisu, nie moderator, nie zawieszony;
 *  - stary formularz nie nadpisuje nowszej korekty (konflikt, nic nie ginie);
 *  - uwaga zgodzona jako wskazówka i tekst pod otwartym zgłoszeniem
 *    zostają nietknięte;
 *  - poprawiony czas wchodzi do istniejącego `TypowyCzasPrzepisu`.
 */
class KorektaWykonaniaTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_10_05_200000_add_poprawiono_at_to_cooked_events.php';

    private User $autor;

    private User $kucharz;

    private Recipe $przepis;

    private CookedEvent $wykonanie;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-05 12:00:00', 'UTC'));

        $this->autor = $this->user('autorka2459');
        $this->kucharz = $this->user('kucharz2459');
        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'visibility' => 'public',
            'status' => Recipe::STATUS_PUBLISHED,
            'title' => 'Zupa dnia',
        ]);

        $this->wykonanie = app(RecordCookedEvent::class)->handle(
            cook: $this->kucharz,
            recipe: $this->przepis,
            note: 'Wyszło z literówką w opisie',
            wouldMakeAgain: true,
            perceivedDifficulty: 'medium',
            actualMinutes: 120,
            changesNote: 'Dałem mniej soli',
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $pola
     */
    private function popraw(array $pola, ?User $kto = null, ?CookedEvent $wykonanie = null): TestResponse
    {
        $wykonanie ??= $this->wykonanie;
        $wersja = $wykonanie->fresh()->wersjaPolKorekty();

        return $this->actingAs($kto ?? $this->kucharz)
            ->from(route('cooked.edit', $wykonanie))
            ->put(route('cooked.update', $wykonanie), ['wersja' => $wersja] + $pola);
    }

    private function powiadomienia(): int
    {
        return Notification::query()
            ->where('user_id', $this->autor->getKey())
            ->where('type', Notification::TYPE_COOKED)
            ->count();
    }

    public function test_kucharz_poprawia_uwage_opis_i_czas_a_wykonanie_zostaje_to_samo(): void
    {
        $przed = $this->wykonanie->fresh();
        $this->assertNotNull($przed);
        $this->assertNull($przed->poprawiono_at);
        $this->assertSame(1, $this->powiadomienia());

        Comment::factory()->create([
            'post_id' => null,
            'cooked_event_id' => $this->wykonanie->getKey(),
            'author_id' => $this->autor->getKey(),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-07 09:30:00', 'UTC'));

        $this->popraw(['note' => 'Wyszło dobrze', 'changes_note' => 'Dałem mniej soli i pieprzu', 'actual_minutes' => '20'])
            ->assertRedirect(route('cooked.show', $this->wykonanie))
            ->assertSessionHasNoErrors();

        $po = $this->wykonanie->fresh();
        $this->assertNotNull($po);
        $this->assertSame('Wyszło dobrze', $po->note);
        $this->assertSame('Dałem mniej soli i pieprzu', $po->changes_note);
        $this->assertSame(20, $po->actual_minutes);
        $this->assertSame('2026-10-07 09:30:00', $po->poprawiono_at?->format('Y-m-d H:i:s'));

        // Tożsamość i dane, których korekta nie dotyka.
        $this->assertSame(1, CookedEvent::query()->count(), 'korekta nie tworzy drugiego wykonania');
        $this->assertSame($przed->getKey(), $po->getKey());
        $this->assertSame($przed->user_id, $po->user_id);
        $this->assertSame($przed->recipe_id, $po->recipe_id);
        $this->assertSame($przed->recipe_version_id, $po->recipe_version_id);
        $this->assertSame($przed->klucz_wyslania, $po->klucz_wyslania);
        $this->assertSame($przed->cooked_at->toIso8601String(), $po->cooked_at->toIso8601String());
        $this->assertSame($przed->created_at?->toIso8601String(), $po->created_at?->toIso8601String());
        $this->assertTrue($po->would_make_again);
        $this->assertSame('medium', $po->perceived_difficulty);
        $this->assertSame(1, Comment::query()->where('cooked_event_id', $po->getKey())->count(), 'rozmowa zostaje');

        // Bez ponownej celebracji: nadal jedno powiadomienie dla autora.
        $this->assertSame(1, $this->powiadomienia());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'cooked_event.created')->count(), 'korekta nie dopisuje drugiego zdarzenia publikacji');
        $this->assertSame(1, DB::table('audit_log')->where('action', 'cooked_event.edited')->count());
    }

    public function test_wpis_dziennika_niesie_nazwy_pol_a_nie_tresc(): void
    {
        $this->popraw(['note' => 'Tajna nowa uwaga', 'changes_note' => 'Dałem mniej soli', 'actual_minutes' => '120']);

        $wpis = DB::table('audit_log')->where('action', 'cooked_event.edited')->sole();
        $this->assertStringContainsString('note', (string) $wpis->metadata);
        $this->assertStringNotContainsString('Tajna nowa uwaga', (string) $wpis->metadata);
    }

    public function test_mozna_swiadomie_wyczyscic_opcjonalne_pola(): void
    {
        $this->popraw(['note' => '', 'changes_note' => '', 'actual_minutes' => ''])->assertSessionHasNoErrors();

        $po = $this->wykonanie->fresh();
        $this->assertNotNull($po);
        $this->assertNull($po->note);
        $this->assertNull($po->changes_note);
        $this->assertNull($po->actual_minutes);
        $this->assertNotNull($po->poprawiono_at);
    }

    public function test_zapis_bez_zmian_nie_dodaje_sladu_korekty(): void
    {
        $this->popraw(['note' => 'Wyszło z literówką w opisie', 'changes_note' => 'Dałem mniej soli', 'actual_minutes' => '120'])
            ->assertRedirect(route('cooked.show', $this->wykonanie));

        $this->assertNull($this->wykonanie->fresh()->poprawiono_at);
        $this->assertSame(0, DB::table('audit_log')->where('action', 'cooked_event.edited')->count());
    }

    public function test_formularz_ma_wypelnione_pola_novalidate_etykiety_i_wyjasnia_co_wolno(): void
    {
        $html = $this->actingAs($this->kucharz)->get(route('cooked.edit', $this->wykonanie))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/<form[^>]*novalidate/', $html);
        $this->assertStringContainsString('Wyszło z literówką w opisie', $html);
        $this->assertStringContainsString('Dałem mniej soli', $html);
        $this->assertStringContainsString('value="120"', $html);
        $this->assertStringContainsString('Co możesz poprawić?', $html);
        $this->assertStringContainsString('Zapisz poprawkę', $html);
        $this->assertStringContainsString('Zdjęcia', $html);
        $this->assertStringContainsString('Zupa dnia', $html);
    }

    public function test_bledna_wartosc_daje_blad_przy_polu_i_w_podsumowaniu_a_wpisany_tekst_zostaje(): void
    {
        $html = $this->followingRedirects()->actingAs($this->kucharz)
            ->from(route('cooked.edit', $this->wykonanie))
            ->put(route('cooked.update', $this->wykonanie), [
                'wersja' => $this->wykonanie->wersjaPolKorekty(),
                'note' => 'Nowa dobra uwaga',
                'actual_minutes' => '120 minut',
            ])->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertSame(1, substr_count($html, 'class="field-error"'), 'błąd przy polu');
        $this->assertStringContainsString('Wpisz sam czas w minutach', $html);
        $this->assertStringContainsString('Nowa dobra uwaga', $html, 'poprawnie wpisany tekst nie znika');
        $this->assertSame(120, $this->wykonanie->fresh()->actual_minutes, 'nic nie zapisano');
        $this->assertNull($this->wykonanie->fresh()->poprawiono_at);
    }

    public function test_za_dluga_uwaga_ma_ten_sam_limit_i_komunikat_co_przy_zapisie(): void
    {
        $this->popraw(['note' => str_repeat('a', 2001)])
            ->assertSessionHasErrors(['note' => 'Ta uwaga jest za długa. Zmieść się w 2000 znakach.']);
    }

    public function test_obcy_autor_przepisu_moderator_i_gosc_nie_poprawiaja_cudzego_wykonania(): void
    {
        $obcy = $this->user('obca2459');
        $moderator = $this->moderator();

        foreach ([$obcy, $this->autor, $moderator] as $kto) {
            $this->actingAs($kto)->get(route('cooked.edit', $this->wykonanie))->assertForbidden();
            $this->popraw(['note' => 'Podmiana'], $kto)->assertForbidden();
        }

        auth()->logout();
        $this->put(route('cooked.update', $this->wykonanie), ['note' => 'Podmiana'])->assertRedirect(route('login'));

        $po = $this->wykonanie->fresh();
        $this->assertSame('Wyszło z literówką w opisie', $po->note);
        $this->assertNull($po->poprawiono_at);
    }

    public function test_zawieszone_konto_czyta_ale_nie_poprawia(): void
    {
        $this->kucharz->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->actingAs($this->kucharz)->get(route('cooked.show', $this->wykonanie))->assertOk()->assertDontSee('Popraw uwagę lub czas');
        $this->actingAs($this->kucharz)->get(route('cooked.edit', $this->wykonanie))->assertForbidden();
        // Zapis zatrzymuje już bramka zawieszonego konta (przekierowanie z
        // komunikatem), zanim dojdzie do Policy.
        $this->popraw(['note' => 'Podmiana'])->assertRedirect();

        $this->assertSame('Wyszło z literówką w opisie', $this->wykonanie->fresh()->note);
        $this->assertNull($this->wykonanie->fresh()->poprawiono_at);
    }

    public function test_przycisk_poprawy_widzi_tylko_kucharz(): void
    {
        $this->actingAs($this->kucharz)->get(route('cooked.show', $this->wykonanie))->assertOk()->assertSee('Popraw uwagę lub czas');
        $this->actingAs($this->autor)->get(route('cooked.show', $this->wykonanie))->assertOk()->assertDontSee('Popraw uwagę lub czas');
    }

    public function test_formularz_nie_ujawnia_tytulu_przepisu_ktory_stal_sie_niedostepny(): void
    {
        $this->przepis->forceFill(['visibility' => 'private'])->save();

        $html = $this->actingAs($this->kucharz)->get(route('cooked.edit', $this->wykonanie))->assertOk()->getContent();

        $this->assertIsString($html);
        $this->assertStringNotContainsString('Zupa dnia', $html);
        $this->assertStringContainsString('Wyszło z literówką w opisie', $html, 'własny tekst zostaje do poprawy');
    }

    public function test_stary_formularz_nie_nadpisuje_nowszej_korekty(): void
    {
        $staraWersja = $this->wykonanie->wersjaPolKorekty();

        // Druga karta zapisała poprawkę.
        $this->popraw(['actual_minutes' => '25'])->assertSessionHasNoErrors();

        $odpowiedz = $this->actingAs($this->kucharz)
            ->from(route('cooked.edit', $this->wykonanie))
            ->put(route('cooked.update', $this->wykonanie), ['wersja' => $staraWersja, 'note' => 'Tekst ze starej karty', 'actual_minutes' => '99']);

        $odpowiedz->assertRedirect(route('cooked.edit', $this->wykonanie))->assertSessionHasErrors('wersja');
        $po = $this->wykonanie->fresh();
        $this->assertSame(25, $po->actual_minutes, 'nowsza korekta zostaje');
        $this->assertSame('Wyszło z literówką w opisie', $po->note);

        // Tekst człowieka wraca do pól, a ekran pokazuje, jak jest zapisane teraz.
        $html = $this->followingRedirects()->actingAs($this->kucharz)
            ->from(route('cooked.edit', $this->wykonanie))
            ->put(route('cooked.update', $this->wykonanie), ['wersja' => $staraWersja, 'note' => 'Tekst ze starej karty', 'actual_minutes' => '99'])
            ->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('Tekst ze starej karty', $html);
        $this->assertStringContainsString('Tak jest zapisane teraz', $html);
        $this->assertStringContainsString('zmieniło się w innej karcie', $html);

        // Po odświeżeniu (nowy odcisk) ta sama poprawka przechodzi.
        $this->popraw(['note' => 'Tekst ze starej karty'])->assertSessionHasNoErrors();
        $this->assertSame('Tekst ze starej karty', $this->wykonanie->fresh()->note);
    }

    public function test_formularz_bez_odcisku_nie_nadpisuje_po_cichu(): void
    {
        $this->actingAs($this->kucharz)
            ->from(route('cooked.edit', $this->wykonanie))
            ->put(route('cooked.update', $this->wykonanie), ['note' => 'Bez odcisku'])
            ->assertSessionHasErrors('wersja');

        $this->assertSame('Wyszło z literówką w opisie', $this->wykonanie->fresh()->note);
    }

    public function test_ponowione_zadanie_z_ta_sama_trescia_to_sukces_bez_konfliktu(): void
    {
        $staraWersja = $this->wykonanie->wersjaPolKorekty();
        $this->popraw(['note' => 'Nowa uwaga'])->assertSessionHasNoErrors();
        $poprawiono = $this->wykonanie->fresh()->poprawiono_at;

        $this->actingAs($this->kucharz)
            ->put(route('cooked.update', $this->wykonanie), ['wersja' => $staraWersja, 'note' => 'Nowa uwaga'])
            ->assertSessionHasNoErrors();

        $this->assertEquals($poprawiono, $this->wykonanie->fresh()->poprawiono_at);
    }

    public function test_pola_spoza_zakresu_sa_ignorowane(): void
    {
        $przed = $this->wykonanie->fresh();

        $this->popraw([
            'note' => 'Nowa uwaga',
            'would_make_again' => '0',
            'perceived_difficulty' => 'hard',
            'cooked_at' => '2020-01-01 00:00:00',
            'user_id' => $this->autor->getKey(),
            'recipe_id' => Recipe::factory()->create()->getKey(),
            'dzien_gotowania' => '2026-01-01',
        ])->assertSessionHasNoErrors();

        $po = $this->wykonanie->fresh();
        $this->assertTrue($po->would_make_again);
        $this->assertSame('medium', $po->perceived_difficulty);
        $this->assertSame($przed->cooked_at->toIso8601String(), $po->cooked_at->toIso8601String());
        $this->assertSame($przed->user_id, $po->user_id);
        $this->assertSame($przed->recipe_id, $po->recipe_id);
        $this->assertNull($po->dzien_gotowania);
    }

    public function test_uwaga_zgodzona_jako_wskazowka_nie_zmienia_sie_a_czas_tak(): void
    {
        RecipeHint::factory()->dlaWykonania($this->wykonanie, RecipeHint::STATUS_ACCEPTED)->create();

        $html = $this->actingAs($this->kucharz)->get(route('cooked.edit', $this->wykonanie))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('Ta uwaga jest teraz wskazówką', $html);
        $this->assertStringNotContainsString('name="note"', $html, 'pole uwagi nie jest wysyłane');
        $this->assertStringContainsString('name="changes_note"', $html);

        $this->popraw(['note' => 'Podmieniona uwaga', 'changes_note' => 'Nowy opis', 'actual_minutes' => '20'])
            ->assertRedirect(route('cooked.edit', $this->wykonanie))
            ->assertSessionHasErrors('wersja');

        $po = $this->wykonanie->fresh();
        $this->assertSame('Wyszło z literówką w opisie', $po->note, 'zatwierdzona treść zostaje');
        $this->assertSame('Nowy opis', $po->changes_note, 'reszta poprawki zapisana');
        $this->assertSame(20, $po->actual_minutes);
        $this->assertSame(RecipeHint::STATUS_ACCEPTED, RecipeHint::query()->sole()->status, 'korekta nie udaje nowego zatwierdzenia');
    }

    public function test_prosba_o_wskazowke_czekajaca_na_odpowiedz_tez_blokuje_uwage(): void
    {
        RecipeHint::factory()->dlaWykonania($this->wykonanie, RecipeHint::STATUS_PROPOSED)->create();

        $this->popraw(['note' => 'Podmieniona uwaga'])->assertSessionHasErrors('wersja');
        $this->assertSame('Wyszło z literówką w opisie', $this->wykonanie->fresh()->note);
    }

    public function test_po_odmowie_albo_wycofaniu_wskazowki_uwage_znow_mozna_poprawic(): void
    {
        RecipeHint::factory()->dlaWykonania($this->wykonanie, RecipeHint::STATUS_WITHDRAWN)->create();

        $this->popraw(['note' => 'Poprawiona uwaga'])->assertSessionHasNoErrors();
        $this->assertSame('Poprawiona uwaga', $this->wykonanie->fresh()->note);
    }

    public function test_otwarte_zgloszenie_blokuje_teksty_ale_nie_czas(): void
    {
        Report::create([
            'reporter_id' => $this->autor->getKey(),
            'target_type' => 'cooked_event',
            'target_id' => $this->wykonanie->getKey(),
            'reason' => 'other',
            'status' => Report::STATUS_OPEN,
        ]);

        $this->popraw(['note' => 'Zmieniona w trakcie sprawy', 'changes_note' => 'Zmieniony opis', 'actual_minutes' => '20'])
            ->assertSessionHasErrors('wersja');

        $po = $this->wykonanie->fresh();
        $this->assertSame('Wyszło z literówką w opisie', $po->note);
        $this->assertSame('Dałem mniej soli', $po->changes_note);
        $this->assertSame(20, $po->actual_minutes);
    }

    public function test_rozstrzygniete_zgloszenie_nie_blokuje(): void
    {
        Report::create([
            'reporter_id' => $this->autor->getKey(),
            'target_type' => 'cooked_event',
            'target_id' => $this->wykonanie->getKey(),
            'reason' => 'other',
            'status' => Report::STATUS_REJECTED,
            'resolved_at' => now(),
        ]);

        $this->popraw(['note' => 'Poprawiona uwaga'])->assertSessionHasNoErrors();
        $this->assertSame('Poprawiona uwaga', $this->wykonanie->fresh()->note);
    }

    public function test_poprawiony_czas_wchodzi_do_typowego_czasu_przepisu(): void
    {
        $this->wykonanie->forceFill(['actual_minutes' => 120])->save();

        foreach ([20, 30, 40, 50] as $minuty) {
            CookedEvent::factory()->create([
                'recipe_id' => $this->przepis->getKey(),
                'user_id' => $this->user()->getKey(),
                'actual_minutes' => $minuty,
            ]);
        }

        // Mediana median pięciu osób: 20, 30, 40, 50, 120 -> 40.
        $this->assertSame(40, TypowyCzasPrzepisu::dla($this->przepis, null)?->minuty);

        // Literówka 120 zamiast 10: [10, 20, 30, 40, 50] -> 30.
        $this->popraw(['actual_minutes' => '10'])->assertSessionHasNoErrors();

        $this->assertSame(30, TypowyCzasPrzepisu::dla($this->przepis, null)?->minuty);
    }

    public function test_karta_pokazuje_napis_poprawiono_wszystkim_ale_tylko_po_korekcie(): void
    {
        $this->get(route('cooked.show', $this->wykonanie))->assertOk()->assertDontSee('Poprawiono');

        Carbon::setTestNow(Carbon::parse('2026-10-07 09:30:00', 'UTC'));
        $this->popraw(['note' => 'Poprawiona uwaga'])->assertSessionHasNoErrors();

        auth()->logout();
        $this->get(route('cooked.show', $this->wykonanie))->assertOk()->assertSee('Poprawiono')->assertSee('7 października 2026');
    }

    public function test_cofniecie_migracji_odmawia_gdy_jest_slad_korekty_a_po_wyczyszczeniu_przechodzi(): void
    {
        $this->popraw(['note' => 'Poprawiona uwaga']);

        try {
            PolecenieArtisanaZOdmowa::wywolaj('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
            $this->fail('Cofnięcie przeszło, mimo że wykonanie ma ślad korekty.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('(cooked_events.poprawiono_at IS NOT NULL): 1.', $e->getMessage());
            $this->assertStringContainsString('CO ZROBIĆ', $e->getMessage());
        }

        $this->assertSame(1, $this->iloscKolumn());
        $this->assertNotNull($this->wykonanie->fresh()->poprawiono_at);

        DB::table('cooked_events')->update(['poprawiono_at' => null]);

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(0, $this->iloscKolumn());

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertSame(1, $this->iloscKolumn());
    }

    private function iloscKolumn(): int
    {
        return count(DB::select(
            "SELECT column_name FROM information_schema.columns WHERE table_name = 'cooked_events' AND column_name = 'poprawiono_at'",
        ));
    }
}

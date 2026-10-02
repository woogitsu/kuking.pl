<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\Block;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * „Przywróć wskazówkę” (#2352, decyzja właściciela z 1.10.2026): moderator
 * cofa ukrycie wskazówki od gotujących BEZ odwołania kucharza, wzorem
 * przywracania wersji przepisu z historii zmian. Do tego drugie rozstrzygnięcie
 * tego dnia: ukryta wskazówka zwalnia miejsce w limicie przepisu, a przywrócenie
 * (ręczne i po uznanym odwołaniu) może ten limit przekroczyć.
 *
 * Każda asercja „nie ma” ma obok kontrolę dodatnią (`docs/PULAPKI_TESTOW.md` §4).
 */
final class PrzywracanieWskazowekTest extends TestCase
{
    use RefreshDatabase;

    private const UWAGA = 'Dodałem chrzan, wyszło lepiej.';

    private User $autor;

    private User $kucharz;

    private Recipe $przepis;

    private RecipeHint $wskazowka;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka');
        $this->kucharz = $this->user('marek', ['display_name' => 'Marek']);
        $this->przepis = $this->nowyPrzepis($this->autor, 'Rosół babci Jadwigi');
        $this->wskazowka = $this->nowaWskazowka($this->kucharz, $this->przepis);
    }

    private function nowyPrzepis(User $autor, string $tytul = 'Przepis'): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => $tytul,
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
    }

    private function nowaWskazowka(User $kucharz, Recipe $przepis, string $uwaga = self::UWAGA, string $status = RecipeHint::STATUS_ACCEPTED): RecipeHint
    {
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepis->getKey(),
            'note' => $uwaga,
            'cooked_at' => now()->subDays(3),
        ]);

        return RecipeHint::factory()->dlaWykonania($wykonanie, $status)->create();
    }

    /** Ukrycie zapisane tak, jak zapisuje je decyzja moderacji: znacznik i wiersz `hide` w rejestrze. */
    private function ukryj(RecipeHint $wskazowka, User $moderator): ModerationAction
    {
        $wskazowka->forceFill(['moderation_hidden_at' => now()])->save();

        return ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'report_id' => null,
            'target_type' => 'recipe_hint',
            'target_id' => $wskazowka->getKey(),
            'subject_user_id' => $wskazowka->cook_id,
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'cudze-dane-osobowe',
            'user_message' => 'W uwadze jest numer telefonu.',
        ]);
    }

    /** Zgłoszenie wskazówki już rozstrzygnięte — karta, na której stoi „Przywróć wskazówkę”. */
    private function rozstrzygnieteZgloszenie(RecipeHint $wskazowka): Report
    {
        return Report::create([
            'reporter_id' => $this->user()->getKey(),
            'target_type' => 'recipe_hint',
            'target_id' => $wskazowka->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_RESOLVED,
            'resolved_by' => $this->moderator()->getKey(),
            'resolved_at' => now(),
        ]);
    }

    /** @return array{reason_code: string, user_message: string} */
    private function dane(array $zmiany = []): array
    {
        return array_merge(['reason_code' => 'pomylka_moderacji', 'user_message' => 'Sprawdziliśmy ponownie, numer jest firmowy.'], $zmiany);
    }

    private function przywroc(User $kto, ?RecipeHint $wskazowka = null, array $dane = []): TestResponse
    {
        return $this->actingAs($kto)->post(route('admin.hints.restore', $wskazowka ?? $this->wskazowka), $this->dane($dane));
    }

    private function sekcjaPrzepisu(?User $kto = null): string
    {
        $odpowiedz = $kto === null
            ? $this->get(route('recipes.show', $this->przepis->slug))
            : $this->actingAs($kto)->get(route('recipes.show', $this->przepis->slug));
        $html = (string) $odpowiedz->assertOk()->getContent();
        $od = strpos($html, 'id="wskazowki-gotujacych"');

        if ($od === false) {
            return '';
        }

        $do = strpos($html, '</section>', $od);

        return $do === false ? substr($html, $od) : substr($html, $od, $do - $od);
    }

    // ─── RĘCZNE PRZYWRÓCENIE ─────────────────────────────────────────────

    public function test_moderator_przywraca_wskazowke_bez_odwolania_z_decyzja_unhide_audytem_i_powiadomieniem(): void
    {
        $moderator = $this->moderator();
        $this->ukryj($this->wskazowka, $moderator);
        $obcy = $this->user('obcy');

        // Kontrola dodatnia: ukryta wskazówka NIE stoi w sekcji.
        $this->assertStringNotContainsString('Dodałem chrzan', $this->sekcjaPrzepisu($obcy));

        $this->przywroc($this->moderator())->assertSessionHasNoErrors()->assertSessionHas('status_rodzaj', 'sukces');

        $wskazowka = $this->wskazowka->fresh();
        $this->assertNull($wskazowka->moderation_hidden_at);
        $this->assertSame(RecipeHint::STATUS_ACCEPTED, $wskazowka->status);
        $this->assertStringContainsString('Dodałem chrzan', $this->sekcjaPrzepisu($obcy));

        $decyzja = ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->sole();
        $this->assertSame(
            [null, 'recipe_hint', $this->wskazowka->getKey(), $this->kucharz->getKey(), 'pomylka_moderacji'],
            [$decyzja->report_id, $decyzja->target_type, $decyzja->target_id, $decyzja->subject_user_id, $decyzja->reason_code],
        );
        $this->assertStringContainsString('jest znowu widoczna', (string) $decyzja->user_message);
        $this->assertStringContainsString('Rosół babci Jadwigi', (string) $decyzja->user_message);
        $this->assertStringContainsString('numer jest firmowy', (string) $decyzja->user_message);

        $powiadomienie = Notification::where('user_id', $this->kucharz->getKey())->where('type', Notification::TYPE_MODERATION)->sole();
        $this->assertSame([ModerationAction::ACTION_UNHIDE, false, $decyzja->getKey()], [$powiadomienie->data['decision'], $powiadomienie->data['appeal'], $powiadomienie->data['action_id']]);
        $this->assertStringContainsString('jest znowu widoczna', (string) $powiadomienie->data['message']);
        $this->assertSame(0, Notification::where('user_id', $this->autor->getKey())->where('type', Notification::TYPE_MODERATION)->count(), 'Autor przepisu nie dostaje wiadomości moderacyjnej o przywróceniu.');

        $audyt = AuditLogEntry::where('action', 'moderation.restored')->sole();
        $this->assertSame(['recipe_hint', (string) $this->wskazowka->getKey(), true], [$audyt->metadata['target_type'], $audyt->metadata['target_id'], $audyt->metadata['widoczna']]);
    }

    public function test_wiadomosc_o_przywroceniu_nie_wymienia_tytulu_przepisu_przy_blokadzie_z_autorem(): void
    {
        $this->ukryj($this->wskazowka, $this->moderator());
        Block::query()->create(['blocker_id' => $this->autor->getKey(), 'blocked_id' => $this->kucharz->getKey()]);

        $this->przywroc($this->moderator())->assertSessionHasNoErrors();

        $decyzja = ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->sole();
        $powiadomienie = Notification::where('user_id', $this->kucharz->getKey())->where('type', Notification::TYPE_MODERATION)->sole();
        foreach ([(string) $decyzja->user_message, (string) $powiadomienie->data['message']] as $tekst) {
            $this->assertStringNotContainsString('Rosół babci Jadwigi', $tekst);
            $this->assertStringContainsString('jest znowu widoczna', $tekst);
            $this->assertStringContainsString('numer jest firmowy', $tekst);
        }
    }

    public function test_powod_jest_obowiazkowy_a_bez_niego_nic_sie_nie_zmienia(): void
    {
        $this->ukryj($this->wskazowka, $this->moderator());

        $this->przywroc($this->moderator(), null, ['reason_code' => ''])->assertSessionHasErrors('reason_code');

        $this->assertNotNull($this->wskazowka->fresh()->moderation_hidden_at);
        $this->assertSame(0, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->count());
        $this->assertSame(0, Notification::where('type', Notification::TYPE_MODERATION)->count());

        // Kontrola dodatnia: ten sam formularz z powodem działa.
        $this->przywroc($this->moderator())->assertSessionHasNoErrors();
        $this->assertNull($this->wskazowka->fresh()->moderation_hidden_at);
    }

    public function test_po_wycofaniu_zgody_nic_nie_wraca_publicznie_i_kucharz_nie_dostaje_wiadomosci(): void
    {
        $this->ukryj($this->wskazowka, $this->moderator());
        $this->actingAs($this->kucharz)->post(route('hints.withdraw', $this->wskazowka))->assertRedirect();
        $this->assertSame(RecipeHint::STATUS_WITHDRAWN, $this->wskazowka->fresh()->status);

        $this->przywroc($this->moderator())->assertSessionHasNoErrors()->assertSessionHas('status_rodzaj', 'informacja');

        $wskazowka = $this->wskazowka->fresh();
        $this->assertSame(
            ['status' => RecipeHint::STATUS_WITHDRAWN, 'ukryta' => false, 'pokazywana' => false],
            ['status' => $wskazowka->status, 'ukryta' => $wskazowka->jestUkrytaPrzezModeracje(), 'pokazywana' => $wskazowka->jestPokazywana()],
        );
        $this->assertSame('', $this->sekcjaPrzepisu($this->user('obcy')));
        $this->assertSame(0, Notification::where('user_id', $this->kucharz->getKey())->where('type', Notification::TYPE_MODERATION)->count());
        // Decyzja zostaje w rejestrze: ślad, że moderacja zdjęła swoje ukrycie.
        $this->assertSame(1, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->count());
    }

    public function test_wskazowki_nieukrytej_nie_przywraca_sie_drugi_raz_ani_bez_decyzji_w_rejestrze(): void
    {
        $moderator = $this->moderator();
        $this->ukryj($this->wskazowka, $moderator);
        $this->przywroc($this->moderator())->assertSessionHasNoErrors();

        // Drugie kliknięcie (druga karta): komunikat, bez drugiej decyzji i drugiego powiadomienia.
        $this->przywroc($this->moderator())->assertSessionHasErrors('reason_code');
        $this->assertSame(1, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->count());
        $this->assertSame(1, Notification::where('user_id', $this->kucharz->getKey())->where('type', Notification::TYPE_MODERATION)->count());

        // Ukryta „ręcznie”, bez decyzji w rejestrze: tej moderacja nie cofa.
        $reczna = $this->nowaWskazowka($this->user('basia'), $this->przepis, 'Druga uwaga.');
        $reczna->forceFill(['moderation_hidden_at' => now()])->save();
        $this->przywroc($this->moderator(), $reczna)->assertSessionHasErrors('reason_code');
        $this->assertNotNull($reczna->fresh()->moderation_hidden_at);
    }

    public function test_przywrocenie_w_panelu_ma_przycisk_i_dziala_z_karty_zgloszenia(): void
    {
        $zgloszenie = Report::create([
            'reporter_id' => $this->user('widz')->getKey(),
            'target_type' => 'recipe_hint',
            'target_id' => $this->wskazowka->getKey(),
            'reason' => 'personal_data',
            'status' => Report::STATUS_OPEN,
        ]);
        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), [
                'action' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'cudze-dane-osobowe',
                'user_message' => 'W uwadze jest numer telefonu.',
            ])
            ->assertSessionHasNoErrors();
        auth()->logout();
        $this->assertNotNull($this->wskazowka->fresh()->moderation_hidden_at);

        $panel = (string) $this->actingAs($this->moderator())->get(route('admin.reports', ['status' => Report::STATUS_RESOLVED]))->assertOk()->getContent();
        $this->assertStringContainsString('Przywróć wskazówkę', $panel);
        $this->assertStringContainsString(route('admin.hints.restore', $this->wskazowka), $panel);

        $this->przywroc($this->moderator())->assertSessionHasNoErrors();

        // Po przywróceniu przycisk znika (kontrola dodatnia była wyżej).
        $po = (string) $this->actingAs($this->moderator())->get(route('admin.reports', ['status' => Report::STATUS_RESOLVED]))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('admin.hints.restore', $this->wskazowka), $po);
    }

    // ─── KTO MOŻE ────────────────────────────────────────────────────────

    public function test_nie_moderator_i_gosc_nie_przywracaja_a_ukrycie_zostaje(): void
    {
        $this->ukryj($this->wskazowka, $this->moderator());

        // Panel moderacji nie przyznaje się do istnienia (404) osobom bez roli.
        foreach ([$this->kucharz, $this->autor, $this->user('obcy')] as $kto) {
            $this->assertContains($this->przywroc($kto)->getStatusCode(), [403, 404]);
        }
        auth()->logout();
        $this->post(route('admin.hints.restore', $this->wskazowka), $this->dane())->assertRedirect();

        $this->assertNotNull($this->wskazowka->fresh()->moderation_hidden_at);
        $this->assertSame(0, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->count());

        // Kontrola dodatnia: moderator przechodzi.
        $this->przywroc($this->moderator())->assertSessionHasNoErrors();
        $this->assertNull($this->wskazowka->fresh()->moderation_hidden_at);
    }

    public function test_moderator_nie_przywraca_wskazowki_w_sprawie_w_ktorej_jest_strona(): void
    {
        // Kucharz-moderator i autor-moderator: obaj są stroną sprawy.
        $moderatorKucharz = $this->moderator();
        $moderatorAutor = $this->moderator();
        $przepisModeratora = $this->nowyPrzepis($moderatorAutor, 'Przepis moderatora');
        $wlasnaUwaga = $this->nowaWskazowka($moderatorKucharz, $this->przepis, 'Uwaga moderatora.');
        $naPrzepisieModeratora = $this->nowaWskazowka($this->kucharz, $przepisModeratora, 'Uwaga pod przepisem moderatora.');
        $this->ukryj($wlasnaUwaga, $this->moderator());
        $this->ukryj($naPrzepisieModeratora, $this->moderator());

        $this->przywroc($moderatorKucharz, $wlasnaUwaga)->assertForbidden();
        $this->przywroc($moderatorAutor, $naPrzepisieModeratora)->assertForbidden();
        $this->assertNotNull($wlasnaUwaga->fresh()->moderation_hidden_at);
        $this->assertNotNull($naPrzepisieModeratora->fresh()->moderation_hidden_at);

        // Karta zgłoszenia mówi to słowami, bez martwego przycisku.
        $this->rozstrzygnieteZgloszenie($wlasnaUwaga);
        $panel = (string) $this->actingAs($moderatorKucharz)->get(route('admin.reports', ['status' => Report::STATUS_RESOLVED]))->assertOk()->getContent();
        $this->assertStringContainsString('przywrócić ją może inny moderator', $panel);
        $this->assertStringNotContainsString(route('admin.hints.restore', $wlasnaUwaga), $panel);

        // Kontrola dodatnia: inny moderator przywraca obie.
        $this->przywroc($this->moderator(), $wlasnaUwaga)->assertSessionHasNoErrors();
        $this->przywroc($this->moderator(), $naPrzepisieModeratora)->assertSessionHasNoErrors();
        $this->assertNull($wlasnaUwaga->fresh()->moderation_hidden_at);
        $this->assertNull($naPrzepisieModeratora->fresh()->moderation_hidden_at);
    }

    public function test_ukrycie_administratora_cofa_tylko_administrator(): void
    {
        $admin = $this->admin();
        $this->ukryj($this->wskazowka, $admin);

        $this->przywroc($this->moderator())->assertSessionHasErrors('reason_code');
        $this->assertNotNull($this->wskazowka->fresh()->moderation_hidden_at);
        $this->assertSame(0, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->count());

        $this->rozstrzygnieteZgloszenie($this->wskazowka);
        $panel = (string) $this->actingAs($this->moderator())->get(route('admin.reports', ['status' => Report::STATUS_RESOLVED]))->assertOk()->getContent();
        $this->assertStringContainsString('Ukrył administrator', $panel);
        $this->assertStringNotContainsString('Przywróć wskazówkę', $panel);

        // Kontrola dodatnia: administrator przywraca.
        $this->przywroc($this->admin())->assertSessionHasNoErrors();
        $this->assertNull($this->wskazowka->fresh()->moderation_hidden_at);
    }

    // ─── LIMIT 10 NA PRZEPIS (decyzja właściciela z 1.10.2026) ───────────

    public function test_ukryta_wskazowka_zwalnia_miejsce_w_limicie_a_przywrocona_je_zajmuje(): void
    {
        config(['kuking.wskazowki.na_przepis_max' => 2, 'kuking.wskazowki.na_dobe_max' => 20]);
        $przepis = $this->nowyPrzepis($this->autor, 'Limit');
        $pierwsza = $this->nowaWskazowka($this->user('kucharz1'), $przepis, 'Pierwsza.');
        $this->nowaWskazowka($this->user('kucharz2'), $przepis, 'Druga.');
        $trzeciKucharz = $this->user('kucharz3');
        $trzecieWykonanie = CookedEvent::factory()->create(['user_id' => $trzeciKucharz->getKey(), 'recipe_id' => $przepis->getKey(), 'note' => 'Trzecia.']);

        $obserwacje = [];
        $obserwacje['komplet'] = RecipeHint::query()->where('recipe_id', $przepis->getKey())->zajmujaceMiejsce()->count();
        $this->actingAs($this->autor)->post(route('hints.propose', $trzecieWykonanie))->assertSessionHas('status_rodzaj', 'blad');
        $obserwacje['prosb_przy_komplecie'] = RecipeHint::query()->where('cooked_event_id', $trzecieWykonanie->getKey())->count();

        $this->ukryj($pierwsza, $this->moderator());
        $obserwacje['zajete_po_ukryciu'] = RecipeHint::query()->where('recipe_id', $przepis->getKey())->zajmujaceMiejsce()->count();
        $this->actingAs($this->autor)->post(route('hints.propose', $trzecieWykonanie))->assertSessionHasNoErrors();
        $obserwacje['prosb_po_ukryciu'] = RecipeHint::query()->where('cooked_event_id', $trzecieWykonanie->getKey())->count();

        $this->assertSame(
            ['komplet' => 2, 'prosb_przy_komplecie' => 0, 'zajete_po_ukryciu' => 1, 'prosb_po_ukryciu' => 1],
            $obserwacje,
        );
    }

    public function test_reczne_przywrocenie_wolno_ponad_limit_ale_nowe_prosby_czekaja_az_liczba_spadnie(): void
    {
        config(['kuking.wskazowki.na_przepis_max' => 2, 'kuking.wskazowki.na_dobe_max' => 20]);
        $przepis = $this->nowyPrzepis($this->autor, 'Limit');
        $ukryta = $this->nowaWskazowka($this->user('kucharz1'), $przepis, 'Ukryta uwaga.');
        $this->nowaWskazowka($this->user('kucharz2'), $przepis, 'Druga uwaga.');
        $this->ukryj($ukryta, $this->moderator());

        // Autor zapełnia zwolnione miejsce nową prośbą (czekającą).
        $kucharzNowy = $this->user('kucharz3');
        $nowe = CookedEvent::factory()->create(['user_id' => $kucharzNowy->getKey(), 'recipe_id' => $przepis->getKey(), 'note' => 'Nowa uwaga.']);
        $this->actingAs($this->autor)->post(route('hints.propose', $nowe))->assertSessionHasNoErrors();
        $this->assertSame(2, RecipeHint::query()->where('recipe_id', $przepis->getKey())->zajmujaceMiejsce()->count());

        // Przywrócenie: komplet był pełny, a przywrócenie i tak przechodzi.
        $this->przywroc($this->moderator(), $ukryta)->assertSessionHasNoErrors();
        $this->assertNull($ukryta->fresh()->moderation_hidden_at);

        $obserwacje = ['zajete' => RecipeHint::query()->where('recipe_id', $przepis->getKey())->zajmujaceMiejsce()->count()];

        // Zajęte są trzy miejsca (dwie przyjęte i jedna czekająca) przy limicie 2 — nowa prośba się odbija.
        $this->actingAs($this->autor)->post(route('hints.propose', CookedEvent::factory()->create(['user_id' => $this->user('kucharz4')->getKey(), 'recipe_id' => $przepis->getKey(), 'note' => 'Czwarta.'])))
            ->assertSessionHas('status_rodzaj', 'blad');
        $obserwacje['prosba_ponad_limit'] = RecipeHint::query()->where('recipe_id', $przepis->getKey())->where('status', RecipeHint::STATUS_PROPOSED)->count();

        // Kucharz wycofuje zgodę: 3 -> 2 zajęte. Nadal komplet.
        $this->actingAs($ukryta->cook)->post(route('hints.withdraw', $ukryta))->assertRedirect();
        $obserwacje['zajete_po_wycofaniu_jednej'] = RecipeHint::query()->where('recipe_id', $przepis->getKey())->zajmujaceMiejsce()->count();

        $this->assertSame(['zajete' => 3, 'prosba_ponad_limit' => 1, 'zajete_po_wycofaniu_jednej' => 2], $obserwacje);
    }

    public function test_nowa_prosba_przechodzi_dopiero_gdy_liczba_spadnie_ponizej_limitu(): void
    {
        config(['kuking.wskazowki.na_przepis_max' => 2, 'kuking.wskazowki.na_dobe_max' => 20]);
        $przepis = $this->nowyPrzepis($this->autor, 'Limit');
        $ukryta = $this->nowaWskazowka($this->user('kucharz1'), $przepis, 'Ukryta uwaga.');
        $this->nowaWskazowka($this->user('kucharz2'), $przepis, 'Druga uwaga.');
        $trzecia = $this->nowaWskazowka($this->user('kucharz3'), $przepis, 'Trzecia uwaga.');
        $this->ukryj($ukryta, $this->moderator());
        $this->przywroc($this->moderator(), $ukryta)->assertSessionHasNoErrors();

        // Trzy przyjęte przy limicie 2: ponad limit, nowa prośba odbija się.
        $kolejne = CookedEvent::factory()->create(['user_id' => $this->user('kucharz4')->getKey(), 'recipe_id' => $przepis->getKey(), 'note' => 'Kolejna.']);
        $this->actingAs($this->autor)->post(route('hints.propose', $kolejne))->assertSessionHas('status_rodzaj', 'blad');
        $poPierwszej = RecipeHint::query()->where('cooked_event_id', $kolejne->getKey())->count();

        // Jedno wycofanie: 2 zajęte = komplet, nadal nie. Drugie: 1 zajęte, miejsce jest.
        $this->actingAs($trzecia->cook)->post(route('hints.withdraw', $trzecia))->assertRedirect();
        $this->actingAs($this->autor)->post(route('hints.propose', $kolejne))->assertSessionHas('status_rodzaj', 'blad');
        $poDrugiej = RecipeHint::query()->where('cooked_event_id', $kolejne->getKey())->count();

        $this->actingAs($ukryta->cook)->post(route('hints.withdraw', $ukryta))->assertRedirect();
        $this->actingAs($this->autor)->post(route('hints.propose', $kolejne))->assertSessionHasNoErrors();
        $poTrzeciej = RecipeHint::query()->where('cooked_event_id', $kolejne->getKey())->count();

        $this->assertSame([0, 0, 1], [$poPierwszej, $poDrugiej, $poTrzeciej]);
    }

    public function test_strona_przepisu_pokazuje_wskazowki_ponad_limit_prosb_ale_nie_ponad_sufit_wyswietlania(): void
    {
        config(['kuking.wskazowki.na_przepis_max' => 2, 'kuking.wskazowki.na_dobe_max' => 20, 'kuking.wskazowki.na_stronie_max' => 4]);
        $przepis = $this->nowyPrzepis($this->autor, 'Limit');
        $uwagi = ['Uwaga alfa.', 'Uwaga beta.', 'Uwaga gamma.', 'Uwaga delta.', 'Uwaga epsilon.'];
        foreach ($uwagi as $numer => $uwaga) {
            $this->nowaWskazowka($this->user('kucharz'.$numer), $przepis, $uwaga);
        }
        $this->przepis = $przepis;

        $sekcja = $this->sekcjaPrzepisu($this->user('obcy'));
        $widoczne = array_map(fn (string $u): bool => str_contains($sekcja, $u), $uwagi);

        // Ponad limit próśb (2) strona pokazuje wszystkie przywrócone, ale nie więcej niż sufit (4);
        // kolejność to kolejność zgód, więc odpada ostatnia.
        $this->assertSame([true, true, true, true, false], $widoczne);
    }

    public function test_uznane_odwolanie_przywraca_wskazowke_takze_ponad_limit(): void
    {
        config(['kuking.wskazowki.na_przepis_max' => 2, 'kuking.wskazowki.na_dobe_max' => 20]);
        $przepis = $this->nowyPrzepis($this->autor, 'Limit');
        $odwolywana = $this->nowaWskazowka($this->kucharz, $przepis, 'Odwołana uwaga.');
        $this->nowaWskazowka($this->user('kucharz2'), $przepis, 'Druga.');
        $decyzja = $this->ukryj($odwolywana, $this->moderator());
        $this->nowaWskazowka($this->user('kucharz3'), $przepis, 'Trzecia.');
        $this->assertSame(2, RecipeHint::query()->where('recipe_id', $przepis->getKey())->zajmujaceMiejsce()->count());

        $this->actingAs($this->kucharz)
            ->post(route('appeals.store', $decyzja), ['body' => 'To numer do naszej rodzinnej pracowni, podałem go świadomie.'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', Appeal::sole()), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Masz rację, wskazówka wraca.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($odwolywana->fresh()->moderation_hidden_at, 'Uznane odwołanie nie przywróciło wskazówki ponad limit.');
        $this->assertSame(3, RecipeHint::query()->where('recipe_id', $przepis->getKey())->zajmujaceMiejsce()->count());
    }
}

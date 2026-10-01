<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\ModeratedContent;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Appeal;
use App\Models\Block;
use App\Models\CookedEvent;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\PolecenieArtisanaZOdmowa;
use Tests\TestCase;

/**
 * Osobne ukrycie WSKAZÓWKI od gotujących przez moderację (#2352, decyzja
 * właściciela z 1.10.2026): nowy cel zgłoszenia `recipe_hint`, decyzja `hide`
 * zdejmuje samą wskazówkę z sekcji przy przepisie, a wykonanie zostaje
 * nietknięte. Autorem uwagi (adresatem decyzji i odwołania) jest KUCHARZ,
 * nie autor przepisu, który o zgodę prosił.
 *
 * Każda asercja „nie ma” ma obok kontrolę dodatnią (`docs/PULAPKI_TESTOW.md`
 * §4), a asercje o obecności elementu czytają wycinek strony (§1).
 */
final class ModeracjaWskazowekTest extends TestCase
{
    use RefreshDatabase;

    private const UWAGA = 'Dodałem chrzan, zadzwoń na 600 100 200.';

    private User $autor;

    private User $kucharz;

    private Recipe $przepis;

    private CookedEvent $wykonanie;

    private RecipeHint $wskazowka;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka');
        $this->kucharz = $this->user('marek', ['display_name' => 'Marek']);
        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'title' => 'Rosół babci Jadwigi',
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);
        $this->wykonanie = CookedEvent::factory()->create([
            'user_id' => $this->kucharz->getKey(),
            'recipe_id' => $this->przepis->getKey(),
            'note' => self::UWAGA,
            'cooked_at' => now()->subDays(3),
        ]);
        $this->wskazowka = RecipeHint::factory()->dlaWykonania($this->wykonanie, RecipeHint::STATUS_ACCEPTED)->create();
    }

    private function formularz(): string
    {
        return route('reports.create', ['type' => 'recipe_hint', 'id' => $this->wskazowka->getKey()]);
    }

    private function zglos(User $kto, string $powod = 'personal_data'): void
    {
        $this->actingAs($kto)
            ->post(route('reports.store', ['type' => 'recipe_hint', 'id' => $this->wskazowka->getKey()]), ['reason' => $powod])
            ->assertSessionHasNoErrors();
        auth()->logout();
    }

    /** @return array<string, string> */
    private function decyzjaUkryj(array $zmiany = []): array
    {
        return array_merge([
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'cudze-dane-osobowe',
            'user_message' => 'W uwadze jest numer telefonu.',
            'note' => 'Notatka tylko dla moderacji.',
        ], $zmiany);
    }

    private function wycinekSekcji(string $html): string
    {
        $od = strpos($html, 'id="wskazowki-gotujacych"');

        if ($od === false) {
            return '';
        }

        // Do końca TEJ sekcji: niżej na stronie jest galeria wykonań z własnymi „Zgłoś”.
        $do = strpos($html, '</section>', $od);

        return $do === false ? substr($html, $od) : substr($html, $od, $do - $od);
    }

    private function stronaPrzepisu(?User $kto = null): string
    {
        $odpowiedz = $kto === null ? $this->get(route('recipes.show', $this->przepis->slug)) : $this->actingAs($kto)->get(route('recipes.show', $this->przepis->slug));

        return (string) $odpowiedz->assertOk()->getContent();
    }

    /** Zgłoszenie złożone i ukryte decyzją moderatora; zwraca zgłoszenie i decyzję. */
    private function ukryjPrzezModeracje(): ModerationAction
    {
        $this->zglos($this->user('widz'));

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', Report::sole()), $this->decyzjaUkryj())
            ->assertSessionHasNoErrors();
        auth()->logout();

        return ModerationAction::sole();
    }

    // ─── PRZYCISK „ZGŁOŚ” PRZY WSKAZÓWCE ─────────────────────────────────

    #[Test]
    public function test_zglos_przy_wskazowce_prowadzi_do_zgloszenia_wskazowki_a_nie_wykonania(): void
    {
        $widz = $this->user('widz');

        $html = $this->wycinekSekcji($this->stronaPrzepisu($widz));
        $this->assertStringContainsString($this->formularz(), $html);
        $this->assertStringNotContainsString(route('reports.create', ['type' => 'cooked_event', 'id' => $this->wykonanie->getKey()]), $html);
        // Kontrola dodatnia: sekcja w ogóle się wyrenderowała (z odnośnikiem do wykonania).
        $this->assertStringContainsString(route('cooked.show', $this->wykonanie), $html);
    }

    #[Test]
    public function test_autor_przepisu_zglasza_a_kucharz_nie_ma_przycisku_przy_wlasnej_wskazowce(): void
    {
        $widzaZgloszenie = [
            'autor przepisu' => str_contains($this->wycinekSekcji($this->stronaPrzepisu($this->autor)), $this->formularz()),
            'kucharz' => str_contains($this->wycinekSekcji($this->stronaPrzepisu($this->kucharz)), $this->formularz()),
            'obca osoba' => str_contains($this->wycinekSekcji($this->stronaPrzepisu($this->user('obca'))), $this->formularz()),
        ];

        $this->assertSame(['autor przepisu' => true, 'kucharz' => false, 'obca osoba' => true], $widzaZgloszenie);
    }

    #[Test]
    public function test_gosc_widzi_zglos_po_zalogowaniu_i_trafia_na_logowanie(): void
    {
        $html = $this->wycinekSekcji($this->stronaPrzepisu());

        $this->assertStringContainsString('data-zglos-goscia', $html);
        $this->assertStringContainsString($this->formularz(), $html);

        $this->get($this->formularz())->assertRedirect(route('login'));
    }

    // ─── KTO MOŻE ZGŁOSIĆ (Policy `report`) ──────────────────────────────

    #[Test]
    public function test_formularz_i_zapis_zgloszenia_dzialaja_i_cytuja_uwage(): void
    {
        $widz = $this->user('widz');

        $this->actingAs($widz)->get($this->formularz())->assertOk()
            ->assertSee('wskazówka od gotujących przy przepisie', false)
            ->assertSee('Rosół babci Jadwigi')
            ->assertSee('Dodałem chrzan', false);

        $this->zglos($widz);

        $zgloszenie = Report::sole();
        $this->assertSame(['recipe_hint', $this->wskazowka->getKey()], [$zgloszenie->target_type, $zgloszenie->target_id]);
        $this->assertSame($widz->getKey(), $zgloszenie->reporter_id);
        // Ponowne kliknięcie nie robi drugiej sprawy.
        $this->zglos($widz);
        $this->assertSame(1, Report::count());
    }

    #[Test]
    public function test_wskazowki_niewidocznej_w_sekcji_nie_da_sie_zglosic(): void
    {
        $widz = $this->user('widz');
        $wyniki = [];

        // Kontrola dodatnia: przyjęta i nieukryta przechodzi.
        $wyniki['przyjęta'] = $this->actingAs($widz)->get($this->formularz())->getStatusCode();

        $stany = [
            'ukryta przez moderację' => fn () => $this->wskazowka->forceFill(['moderation_hidden_at' => now()])->save(),
            'wycofana' => fn () => $this->wskazowka->forceFill(['moderation_hidden_at' => null, 'status' => RecipeHint::STATUS_WITHDRAWN, 'withdrawn_at' => now()])->save(),
            'odrzucona' => fn () => $this->wskazowka->forceFill(['status' => RecipeHint::STATUS_DECLINED, 'withdrawn_at' => null])->save(),
            'czekająca' => fn () => $this->wskazowka->forceFill(['status' => RecipeHint::STATUS_PROPOSED, 'decided_at' => null])->save(),
            'anulowana' => fn () => $this->wskazowka->forceFill(['status' => RecipeHint::STATUS_CANCELLED])->save(),
        ];
        foreach ($stany as $nazwa => $ustaw) {
            $ustaw();
            $wyniki[$nazwa] = $this->actingAs($widz)->get($this->formularz())->getStatusCode();
            // Zapis też: bramka stoi w akcji, nie tylko w formularzu.
            $wyniki[$nazwa.' (zapis)'] = $this->actingAs($widz)
                ->post(route('reports.store', ['type' => 'recipe_hint', 'id' => $this->wskazowka->getKey()]), ['reason' => 'spam'])
                ->getStatusCode();
        }

        $this->assertSame([
            'przyjęta' => 200,
            'ukryta przez moderację' => 404, 'ukryta przez moderację (zapis)' => 404,
            'wycofana' => 404, 'wycofana (zapis)' => 404,
            'odrzucona' => 404, 'odrzucona (zapis)' => 404,
            'czekająca' => 404, 'czekająca (zapis)' => 404,
            'anulowana' => 404, 'anulowana (zapis)' => 404,
        ], $wyniki);
        $this->assertSame(0, Report::count());
    }

    #[Test]
    public function test_wskazowki_przy_przepisie_ktorego_zgloszacy_nie_widzi_nie_da_sie_zglosic(): void
    {
        $widz = $this->user('widz');
        $wyniki = ['widoczny przepis' => $this->actingAs($widz)->get($this->formularz())->getStatusCode()];

        $this->przepis->forceFill(['visibility' => 'private'])->save();
        $wyniki['prywatny przepis'] = $this->actingAs($widz)->get($this->formularz())->getStatusCode();
        $this->przepis->forceFill(['visibility' => 'public', 'status' => Recipe::STATUS_HIDDEN])->save();
        $wyniki['ukryty przepis'] = $this->actingAs($widz)->get($this->formularz())->getStatusCode();
        $this->przepis->forceFill(['status' => Recipe::STATUS_PUBLISHED])->save();

        Block::query()->create(['blocker_id' => $widz->getKey(), 'blocked_id' => $this->kucharz->getKey()]);
        $wyniki['zgłaszający zablokował kucharza'] = $this->actingAs($widz)->get($this->formularz())->getStatusCode();
        Block::query()->delete();
        Block::query()->create(['blocker_id' => $this->kucharz->getKey(), 'blocked_id' => $widz->getKey()]);
        $wyniki['kucharz zablokował zgłaszającego'] = $this->actingAs($widz)->get($this->formularz())->getStatusCode();
        Block::query()->delete();

        $this->kucharz->forceFill(['status' => User::STATUS_BANNED])->save();
        $wyniki['kucharz zbanowany'] = $this->actingAs($widz)->get($this->formularz())->getStatusCode();

        $this->assertSame([
            'widoczny przepis' => 200,
            'prywatny przepis' => 404,
            'ukryty przepis' => 404,
            'zgłaszający zablokował kucharza' => 404,
            'kucharz zablokował zgłaszającego' => 404,
            'kucharz zbanowany' => 404,
        ], $wyniki);
    }

    #[Test]
    public function test_zly_identyfikator_wskazowki_to_404(): void
    {
        $widz = $this->user('widz');

        $this->actingAs($widz)->get(route('reports.create', ['type' => 'recipe_hint', 'id' => 'nie-uuid']))->assertNotFound();
        $this->actingAs($widz)->get(route('reports.create', ['type' => 'recipe_hint', 'id' => (string) Str::uuid()]))->assertNotFound();
    }

    // ─── KOLEJKA I DECYZJA ───────────────────────────────────────────────

    #[Test]
    public function test_moderator_widzi_w_kolejce_uwage_przepis_i_odnosnik(): void
    {
        $this->zglos($this->user('widz'));

        $this->actingAs($this->moderator())->get(route('admin.reports'))->assertOk()
            ->assertSee('wskazówka od gotujących')
            ->assertSee('Dodałem chrzan', false)
            ->assertSee('Rosół babci Jadwigi')
            ->assertSee('Marek')
            ->assertSee(route('recipes.show', $this->przepis).'#wskazowki-gotujacych', false)
            ->assertSee('Ukryj treść');
    }

    #[Test]
    public function test_decyzje_dla_wskazowki_to_brak_ostrzezenie_ukrycie_zawieszenie_i_ban(): void
    {
        $this->assertSame(
            [ModerationAction::ACTION_NONE, ModerationAction::ACTION_HIDE, ModerationAction::ACTION_WARN, ModerationAction::ACTION_SUSPEND, ModerationAction::ACTION_BAN],
            array_values(array_intersect(
                [ModerationAction::ACTION_NONE, ModerationAction::ACTION_HIDE, ModerationAction::ACTION_WARN, ModerationAction::ACTION_SUSPEND, ModerationAction::ACTION_BAN, ModerationAction::ACTION_REMOVE],
                array_keys(ModerationAction::dozwoloneDla('recipe_hint')),
            )),
        );
        // Kontrola: `remove` istnieje w słowniku, tylko nie dla wskazówki.
        $this->assertArrayHasKey(ModerationAction::ACTION_REMOVE, ModerationAction::dozwoloneDla('recipe'));
        $this->assertArrayNotHasKey(ModerationAction::ACTION_REMOVE, ModerationAction::dozwoloneDla('recipe_hint'));
    }

    #[Test]
    public function test_remove_dla_wskazowki_jest_odrzucone(): void
    {
        $this->zglos($this->user('widz'));

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', Report::sole()), $this->decyzjaUkryj(['action' => ModerationAction::ACTION_REMOVE]))
            ->assertSessionHasErrors('action');

        $this->assertSame([1, 1, 0], [RecipeHint::count(), CookedEvent::count(), ModerationAction::count()]);
    }

    #[Test]
    public function test_ukryj_tresc_zdejmuje_sama_wskazowke_a_wykonanie_zostaje(): void
    {
        $zglaszajacy = $this->user('widz');
        $this->zglos($zglaszajacy);
        $zgloszenie = Report::sole();

        // Kontrola dodatnia: przed decyzją wskazówka stoi w sekcji.
        $przed = $this->wycinekSekcji($this->stronaPrzepisu($zglaszajacy));
        $this->assertStringContainsString('Dodałem chrzan', $przed);

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', $zgloszenie), $this->decyzjaUkryj())
            ->assertSessionHasNoErrors();
        auth()->logout();

        // Skutek: wskazówka ukryta, zgoda kucharza nietknięta, wykonanie i uwaga na miejscu.
        $wskazowka = $this->wskazowka->fresh();
        $this->assertTrue($wskazowka->jestUkrytaPrzezModeracje());
        $this->assertSame(RecipeHint::STATUS_ACCEPTED, $wskazowka->status);
        $this->assertSame(self::UWAGA, $this->wykonanie->fresh()->note);

        // Sekcja znika (jedyna wskazówka), a strona wykonania nadal działa.
        $po = $this->stronaPrzepisu($zglaszajacy);
        $this->assertStringNotContainsString('id="wskazowki-gotujacych"', $po);
        $this->get(route('cooked.show', $this->wykonanie))->assertOk()->assertSee('Dodałem chrzan', false);

        // Decyzja: jedna, z report_id, na wskazówkę, wobec KUCHARZA (nie autora przepisu).
        $decyzja = ModerationAction::sole();
        $this->assertSame(
            [$zgloszenie->getKey(), 'recipe_hint', $this->wskazowka->getKey(), ModerationAction::ACTION_HIDE, $this->kucharz->getKey()],
            [$decyzja->report_id, $decyzja->target_type, $decyzja->target_id, $decyzja->action, $decyzja->subject_user_id],
        );
        $this->assertStringStartsWith('Dotyczy wskazówki od gotujących, którą pokazywaliśmy przy przepisie „Rosół babci Jadwigi”.', (string) $decyzja->user_message);
        $this->assertStringContainsString('Twoja uwaga zostaje pod Twoim wykonaniem.', (string) $decyzja->user_message);
        $this->assertStringContainsString('W uwadze jest numer telefonu.', (string) $decyzja->user_message);
        $this->assertTrue($decyzja->isAppealable());
        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->fresh()->status);

        // Powiadomienie dostaje kucharz, z drogą odwołania; autor przepisu nie dostaje nic.
        $dlaKucharza = Notification::where('user_id', $this->kucharz->getKey())->where('type', Notification::TYPE_MODERATION)->sole();
        $this->assertTrue($dlaKucharza->data['appeal']);
        $this->assertSame($decyzja->getKey(), $dlaKucharza->data['action_id']);
        $this->assertSame(0, Notification::where('user_id', $this->autor->getKey())->where('type', Notification::TYPE_MODERATION)->count());
        $this->assertSame(1, Notification::where('user_id', $zglaszajacy->getKey())->where('type', Notification::TYPE_REPORT_DECIDED)->count());
    }

    #[Test]
    public function test_ukrycie_jednej_wskazowki_nie_rusza_pozostalych_przy_przepisie(): void
    {
        $inny = $this->user('basia', ['display_name' => 'Basia']);
        $drugie = CookedEvent::factory()->create([
            'user_id' => $inny->getKey(), 'recipe_id' => $this->przepis->getKey(), 'note' => 'Ja dałam mniej soli.', 'cooked_at' => now()->subDays(2),
        ]);
        RecipeHint::factory()->dlaWykonania($drugie, RecipeHint::STATUS_ACCEPTED)->create();
        $this->ukryjPrzezModeracje();

        $html = $this->wycinekSekcji($this->stronaPrzepisu($this->user('widz2')));

        $this->assertStringContainsString('Ja dałam mniej soli.', $html);
        $this->assertStringNotContainsString('Dodałem chrzan', $html);
    }

    #[Test]
    public function test_sankcja_trafia_w_kucharza_a_nie_w_autora_przepisu(): void
    {
        $this->zglos($this->user('widz'));

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', Report::sole()), $this->decyzjaUkryj(['action' => ModerationAction::ACTION_SUSPEND, 'suspend_days' => '7']))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['kucharz' => User::STATUS_SUSPENDED, 'autor przepisu' => User::STATUS_ACTIVE],
            ['kucharz' => $this->kucharz->fresh()->status, 'autor przepisu' => $this->autor->fresh()->status],
        );
        $this->assertNull($this->wskazowka->fresh()->moderation_hidden_at, 'Zawieszenie konta nie jest ukryciem wskazówki.');
    }

    #[Test]
    public function test_ostrzezenie_i_bez_dzialania_nie_ukrywaja_wskazowki(): void
    {
        $this->zglos($this->user('widz'));
        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', Report::sole()), $this->decyzjaUkryj(['action' => ModerationAction::ACTION_WARN]))
            ->assertSessionHasNoErrors();
        auth()->logout();

        $ostrzezenie = ModerationAction::sole();
        $this->assertNull($this->wskazowka->fresh()->moderation_hidden_at);
        $this->assertStringStartsWith('Dotyczy wskazówki od gotujących', (string) $ostrzezenie->user_message);
        $this->assertStringNotContainsString('zniknęła', (string) $ostrzezenie->user_message, 'Ostrzeżenie nie zdejmuje wskazówki, więc nie może tego twierdzić.');

        // Bez działania: ani wskazówka, ani kucharz niczego nie dostają.
        $drugie = $this->user('drugie');
        $this->zglos($drugie, 'spam');
        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', Report::where('reporter_id', $drugie->getKey())->sole()), ['action' => ModerationAction::ACTION_NONE, 'reason_code' => 'brak_naruszenia'])
            ->assertSessionHasNoErrors();

        $bezDzialania = ModerationAction::where('action', ModerationAction::ACTION_NONE)->sole();
        $this->assertNull($bezDzialania->user_message);
        $this->assertNull($this->wskazowka->fresh()->moderation_hidden_at);
    }

    #[Test]
    public function test_wskazowka_ktora_przestala_stac_przy_przepisie_nie_zamyka_zgloszenia_po_cichu(): void
    {
        $this->zglos($this->user('widz'));
        $zgloszenie = Report::sole();
        $moderator = $this->moderator();
        $wyniki = [];

        // Kucharz wycofał zgodę przed decyzją — „Ukryj treść” nie udaje sukcesu.
        $this->wskazowka->forceFill(['status' => RecipeHint::STATUS_WITHDRAWN, 'withdrawn_at' => now()])->save();
        $this->actingAs($moderator)->post(route('admin.reports.decide', $zgloszenie), $this->decyzjaUkryj())->assertSessionHasErrors('action');
        $wyniki['po wycofaniu'] = [ModerationAction::count(), $zgloszenie->fresh()->status, $this->wskazowka->fresh()->moderation_hidden_at === null];

        // Już ukryta (np. przez inne zgłoszenie).
        $this->wskazowka->forceFill(['status' => RecipeHint::STATUS_ACCEPTED, 'withdrawn_at' => null, 'moderation_hidden_at' => now()])->save();
        $this->actingAs($moderator)->post(route('admin.reports.decide', $zgloszenie), $this->decyzjaUkryj())->assertSessionHasErrors('action');
        $wyniki['po ukryciu'] = [ModerationAction::count(), $zgloszenie->fresh()->status, Notification::where('type', Notification::TYPE_MODERATION)->count()];

        $this->assertSame([
            'po wycofaniu' => [0, Report::STATUS_OPEN, true],
            'po ukryciu' => [0, Report::STATUS_OPEN, 0],
        ], $wyniki);

        // Kontrola dodatnia: wskazówka znów stoi przy przepisie — ta sama decyzja przechodzi.
        $this->wskazowka->forceFill(['moderation_hidden_at' => null])->save();
        $this->actingAs($moderator)->post(route('admin.reports.decide', $zgloszenie), $this->decyzjaUkryj())->assertSessionHasNoErrors();
        $this->assertTrue($this->wskazowka->fresh()->jestUkrytaPrzezModeracje());
    }

    #[Test]
    public function test_wiadomosc_dla_kucharza_miesci_sie_w_kolumnie_razem_ze_zdaniem_o_wskazowce(): void
    {
        $this->zglos($this->user('widz'));
        $moderator = $this->moderator();
        $zgloszenie = Report::sole();

        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $zgloszenie), $this->decyzjaUkryj(['user_message' => str_repeat('a', 2000)]))
            ->assertSessionHasErrors('user_message');
        $this->assertSame(0, ModerationAction::count());

        $zdanie = 'Dotyczy wskazówki od gotujących, którą pokazywaliśmy przy przepisie „Rosół babci Jadwigi”. '
            .'Wskazówka zniknęła ze strony przepisu, a Twoja uwaga zostaje pod Twoim wykonaniem.';
        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $zgloszenie), $this->decyzjaUkryj(['user_message' => str_repeat('a', 2000 - mb_strlen($zdanie) - 1)]))
            ->assertSessionHasNoErrors();
        $this->assertSame(2000, mb_strlen((string) ModerationAction::sole()->user_message));
    }

    /** @return array<string, array{bool}> */
    public static function kierunkiBlokady(): array
    {
        return ['kucharz zablokował autora przepisu' => [true], 'autor przepisu zablokował kucharza' => [false]];
    }

    #[Test]
    #[DataProvider('kierunkiBlokady')]
    public function test_wiadomosc_dla_kucharza_nie_wymienia_tytulu_przepisu_przy_blokadzie_z_autorem(bool $kucharzBlokuje): void
    {
        Block::query()->create($kucharzBlokuje
            ? ['blocker_id' => $this->kucharz->getKey(), 'blocked_id' => $this->autor->getKey()]
            : ['blocker_id' => $this->autor->getKey(), 'blocked_id' => $this->kucharz->getKey()]);
        $this->zglos($this->user('widz'));

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', Report::sole()), $this->decyzjaUkryj())
            ->assertSessionHasNoErrors();

        $decyzja = ModerationAction::sole();
        $powiadomienie = Notification::where('user_id', $this->kucharz->getKey())->where('type', Notification::TYPE_MODERATION)->sole();
        foreach ([(string) $decyzja->user_message, (string) $powiadomienie->data['message']] as $tekst) {
            $this->assertStringNotContainsString('Rosół babci Jadwigi', $tekst, 'Tytuł przepisu osoby w blokadzie wyciekł do wiadomości dla kucharza.');
            $this->assertStringContainsString('Dotyczy wskazówki od gotujących, którą pokazywaliśmy przy jednym z przepisów.', $tekst);
            // Kontrola dodatnia: reszta wiadomości (uzasadnienie moderatora) dociera.
            $this->assertStringContainsString('W uwadze jest numer telefonu.', $tekst);
        }
        $this->assertTrue($this->wskazowka->fresh()->jestUkrytaPrzezModeracje());
    }

    #[Test]
    public function test_moderator_nie_rozstrzyga_zgloszenia_wlasnej_wskazowki(): void
    {
        $kucharzModerator = $this->moderator();
        $this->wykonanie->forceFill(['user_id' => $kucharzModerator->getKey()])->save();
        $this->wskazowka->forceFill(['cook_id' => $kucharzModerator->getKey()])->save();
        $this->zglos($this->user('widz'));

        $this->actingAs($kucharzModerator)
            ->post(route('admin.reports.decide', Report::sole()), $this->decyzjaUkryj())
            ->assertSessionHasErrors('action');

        $this->assertNull($this->wskazowka->fresh()->moderation_hidden_at);
        $this->assertSame(0, ModerationAction::count());
    }

    #[Test]
    public function test_autorem_wskazowki_dla_moderacji_jest_kucharz_a_nie_autor_przepisu(): void
    {
        $wskazowka = RecipeHint::query()->with(['cook', 'recipe'])->findOrFail($this->wskazowka->getKey());

        $this->assertSame($this->kucharz->getKey(), ModeratedContent::osoba($wskazowka)?->getKey());
        // Kontrola: relacja `author` wskazówki to autor PRZEPISU — to właśnie jej nie wolno brać za autora uwagi.
        $this->assertSame($this->autor->getKey(), $wskazowka->author->getKey());
    }

    // ─── ODWOŁANIE KUCHARZA ──────────────────────────────────────────────

    #[Test]
    public function test_kucharz_odwoluje_sie_a_uznane_odwolanie_przywraca_wskazowke(): void
    {
        $decyzja = $this->ukryjPrzezModeracje();

        $this->actingAs($this->kucharz)->get(route('appeals.show', $decyzja))->assertOk();
        // Autor przepisu nie odwołuje się od cudzej decyzji.
        $this->actingAs($this->autor)->get(route('appeals.show', $decyzja))->assertForbidden();
        $this->actingAs($this->kucharz)
            ->post(route('appeals.store', $decyzja), ['body' => 'To numer do naszej rodzinnej pracowni, podałem go świadomie.'])
            ->assertSessionHasNoErrors();
        auth()->logout();

        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', Appeal::sole()), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Masz rację, wskazówka wraca.',
            ])
            ->assertSessionHasNoErrors();
        auth()->logout();

        $this->assertNull($this->wskazowka->fresh()->moderation_hidden_at, 'Uznane odwołanie nie przywróciło wskazówki.');
        $przywrocenie = ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->sole();
        $this->assertSame(['recipe_hint', $this->wskazowka->getKey(), $this->kucharz->getKey()], [$przywrocenie->target_type, $przywrocenie->target_id, $przywrocenie->subject_user_id]);
        $this->assertStringContainsString('Dodałem chrzan', $this->wycinekSekcji($this->stronaPrzepisu($this->user('widz2'))));
    }

    #[Test]
    public function test_podtrzymane_odwolanie_zostawia_wskazowke_ukryta(): void
    {
        $decyzja = $this->ukryjPrzezModeracje();
        $this->actingAs($this->kucharz)
            ->post(route('appeals.store', $decyzja), ['body' => 'Proszę o ponowne rozpatrzenie, to nic groźnego.'])
            ->assertSessionHasNoErrors();
        auth()->logout();

        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', Appeal::sole()), [
                'outcome' => Appeal::STATUS_UPHELD,
                'decision_note' => 'Numer telefonu zostaje poza serwisem.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($this->wskazowka->fresh()->jestUkrytaPrzezModeracje());
        $this->assertSame(0, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->count());
    }

    #[Test]
    public function test_uznane_odwolanie_nie_wskrzesza_wskazowki_ktorej_kucharz_zgode_wycofal(): void
    {
        $decyzja = $this->ukryjPrzezModeracje();
        $this->actingAs($this->kucharz)
            ->post(route('appeals.store', $decyzja), ['body' => 'Ukrycie było niesłuszne, ale i tak wolę ją wycofać.'])
            ->assertSessionHasNoErrors();
        // Kucharz wycofuje zgodę na UKRYTĄ wskazówkę (RODO art. 7 ust. 3 — zawsze wolno).
        $this->actingAs($this->kucharz)->post(route('hints.withdraw', $this->wskazowka))->assertRedirect();
        auth()->logout();

        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', Appeal::sole()), ['outcome' => Appeal::STATUS_OVERTURNED, 'decision_note' => 'Cofam ukrycie.'])
            ->assertSessionHasNoErrors();

        $wskazowka = $this->wskazowka->fresh();
        $this->assertSame([RecipeHint::STATUS_WITHDRAWN, null], [$wskazowka->status, $wskazowka->moderation_hidden_at]);
        $this->assertStringNotContainsString('id="wskazowki-gotujacych"', $this->stronaPrzepisu());
    }

    #[Test]
    public function test_uznane_odwolanie_zglaszajacego_od_bez_dzialania_moze_ukryc_wskazowke(): void
    {
        $zglaszajacy = $this->user('widz');
        $this->zglos($zglaszajacy);
        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', Report::sole()), ['action' => ModerationAction::ACTION_NONE, 'reason_code' => 'brak_naruszenia'])
            ->assertSessionHasNoErrors();
        auth()->logout();

        $pierwotna = ModerationAction::sole();
        $odwolanie = Appeal::create([
            'moderation_action_id' => $pierwotna->getKey(),
            'report_id' => $pierwotna->report_id,
            'appellant' => Appeal::APPELLANT_REPORTER,
            'body' => 'W tej uwadze dalej jest cudzy numer telefonu, proszę sprawdzić jeszcze raz.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Zgłoszenie było zasadne.',
                'nowa_decyzja' => ModerationAction::ACTION_HIDE,
                'reason_code' => 'cudze-dane-osobowe',
                'user_message' => 'W uwadze jest numer telefonu.',
            ])
            ->assertSessionHasNoErrors();
        auth()->logout();

        $nowa = ModerationAction::where('appeal_id', $odwolanie->getKey())->sole();
        $this->assertTrue($this->wskazowka->fresh()->jestUkrytaPrzezModeracje(), 'Nowa decyzja „ukryj” zapisała się, ale wskazówka dalej stoi.');
        $this->assertSame($this->kucharz->getKey(), $nowa->subject_user_id);
        $this->assertStringStartsWith('Dotyczy wskazówki od gotujących', (string) $nowa->user_message);
        $this->assertSame(1, Notification::where('user_id', $this->kucharz->getKey())->where('type', Notification::TYPE_MODERATION)->count());
    }

    // ─── CO WIDZĄ KUCHARZ I AUTOR PRZEPISU ───────────────────────────────

    #[Test]
    public function test_kucharz_widzi_na_stronie_wykonania_ze_wskazowka_ukryto_a_autor_nie_dowiaduje_sie_dlaczego(): void
    {
        // Kontrola dodatnia: przed ukryciem obie strony widzą swój stan.
        $przedKucharz = (string) $this->actingAs($this->kucharz)->get(route('cooked.show', $this->wykonanie))->assertOk()->getContent();
        $przedAutor = (string) $this->actingAs($this->autor)->get(route('cooked.show', $this->wykonanie))->assertOk()->getContent();
        $this->assertStringContainsString('Ta uwaga jest wskazówką przy przepisie', $przedKucharz);
        $this->assertStringContainsString('stoi przy przepisie jako wskazówka', $przedAutor);

        $this->ukryjPrzezModeracje();

        $poKucharz = (string) $this->actingAs($this->kucharz)->get(route('cooked.show', $this->wykonanie))->assertOk()->getContent();
        $poAutor = (string) $this->actingAs($this->autor)->get(route('cooked.show', $this->wykonanie))->assertOk()->getContent();

        $this->assertStringContainsString('Ta wskazówka została ukryta przez moderację', $poKucharz);
        $this->assertStringContainsString(route('hints.withdraw', $this->wskazowka), $poKucharz);
        $this->assertStringNotContainsString('Ta uwaga jest wskazówką przy przepisie', $poKucharz);
        // Autor przepisu widzi neutralne zdanie jak przy „Nie” i wycofaniu zgody.
        $this->assertStringContainsString('Ta uwaga nie jest dostępna jako wskazówka.', $poAutor);
        $this->assertStringNotContainsString('moderac', $poAutor);
    }

    #[Test]
    public function test_kucharz_wycofuje_zgode_takze_na_ukryta_wskazowke(): void
    {
        $this->ukryjPrzezModeracje();

        $this->actingAs($this->kucharz)->post(route('hints.withdraw', $this->wskazowka))->assertRedirect();

        $this->assertSame(RecipeHint::STATUS_WITHDRAWN, $this->wskazowka->fresh()->status);
    }

    #[Test]
    public function test_eksport_kucharza_ma_ukrycie_a_eksport_autora_go_nie_ujawnia(): void
    {
        $this->ukryjPrzezModeracje();

        $kucharzDane = $this->kucharz->fresh();
        $autorDane = $this->autor->fresh();
        $kucharz = app(CollectUserExportData::class)->handle($kucharzDane, new ExportPhotoPlan($kucharzDane), now());
        $autor = app(CollectUserExportData::class)->handle($autorDane, new ExportPhotoPlan($autorDane), now());

        $this->assertNotNull($kucharz['wskazowki_z_moich_wykonan'][0]['ukryta_przez_moderacje_dnia']);
        $this->assertSame('nie jest dostępna jako wskazówka', $autor['wskazowki_do_moich_przepisow'][0]['stan']);
    }

    // ─── BAZA, POLA STERUJĄCE, ROLLBACK, RAPORT ──────────────────────────

    #[Test]
    public function test_ukrycie_jest_polem_sterujacym_poza_fillable(): void
    {
        $this->assertSame([], (new RecipeHint)->getFillable());

        $this->expectException(MassAssignmentException::class);
        (new RecipeHint)->fill(['moderation_hidden_at' => now()]);
    }

    #[Test]
    public function test_baza_przyjmuje_cel_wskazowka_a_typ_spoza_listy_odbija_sie_o_check(): void
    {
        $this->zglos($this->user('widz'));
        $this->assertSame('recipe_hint', Report::sole()->target_type);

        // Kontrola: CHECK nie został zdjęty, tylko poszerzony.
        $this->expectException(QueryException::class);
        DB::table('reports')->insert([
            'id' => (string) Str::uuid(),
            'source' => Report::SOURCE_COMMUNITY,
            'reporter_id' => $this->user('x')->getKey(),
            'target_type' => 'wskazowka-w-tle',
            'target_id' => (string) Str::uuid(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function test_baza_nie_pozwala_ukryc_wskazowki_na_ktora_kucharz_sie_nie_zgodzil(): void
    {
        $wykonanie = CookedEvent::factory()->create([
            'user_id' => $this->kucharz->getKey(), 'recipe_id' => $this->przepis->getKey(), 'note' => 'Druga uwaga.',
        ]);
        $czekajaca = RecipeHint::factory()->dlaWykonania($wykonanie)->create();

        // Kontrola dodatnia: przyjętą ukryć wolno.
        $this->wskazowka->forceFill(['moderation_hidden_at' => now()])->save();
        $this->assertNotNull($this->wskazowka->fresh()->moderation_hidden_at);

        try {
            $czekajaca->forceFill(['moderation_hidden_at' => now()])->save();
            $blad = null;
        } catch (QueryException $e) {
            $blad = $e->getMessage();
        }

        $this->assertNotNull($blad, 'Baza przyjęła ukrycie wskazówki, na którą nikt się nie zgodził.');
        $this->assertStringContainsString('recipe_hints_ukrycie_check', $blad);
    }

    #[Test]
    public function test_rollback_odmawia_gdy_jest_zgloszenie_albo_ukryta_wskazowka_a_bez_nich_przechodzi(): void
    {
        $migracja = 'database/migrations/2026_10_01_200000_wskazowka_jako_cel_zgloszenia.php';

        // Kontrola dodatnia: bez zgłoszeń i ukryć rollback przechodzi i da się go powtórzyć.
        Artisan::call('migrate:rollback', ['--path' => $migracja]);
        $poRollbacku = DB::getSchemaBuilder()->hasColumn('recipe_hints', 'moderation_hidden_at');
        Artisan::call('migrate', ['--path' => $migracja]);
        $this->assertSame(
            ['po rollbacku' => false, 'po ponownej migracji' => true],
            ['po rollbacku' => $poRollbacku, 'po ponownej migracji' => DB::getSchemaBuilder()->hasColumn('recipe_hints', 'moderation_hidden_at')],
        );

        // Odmowa 1: ukryta wskazówka (zdjęcie kolumny odsłoniłoby ją).
        $this->wskazowka->forceFill(['moderation_hidden_at' => now()])->save();
        $odmowaUkrycie = $this->odmowaRollbacku($migracja);
        $this->assertNotNull($odmowaUkrycie, 'Rollback przeszedł, choć wskazówka jest ukryta przez moderację.');
        $this->assertStringContainsString('Liczba wskazówek ukrytych przez moderację (`recipe_hints.moderation_hidden_at`): 1.', $odmowaUkrycie->getMessage());

        // Odmowa 2: zgłoszenie wskazówki w `reports`.
        $this->wskazowka->forceFill(['moderation_hidden_at' => null])->save();
        $this->zglos($this->user('widz'));
        $odmowaZgloszenie = $this->odmowaRollbacku($migracja);
        $this->assertNotNull($odmowaZgloszenie, 'Rollback przeszedł, choć w bazie jest zgłoszenie wskazówki.');
        $this->assertStringContainsString('Liczba zgłoszeń wskazówek od gotujących (`target_type = recipe_hint`) w `reports`: 1.', $odmowaZgloszenie->getMessage());
        $this->assertSame(1, Report::where('target_type', 'recipe_hint')->count());
    }

    private function odmowaRollbacku(string $migracja): ?RuntimeException
    {
        // Odmowę odkładamy do zmiennej i oceniamy poza blokiem (D-133).
        $odmowa = null;
        try {
            PolecenieArtisanaZOdmowa::wywolaj('migrate:rollback', ['--path' => $migracja, '--realpath' => false]);
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        return $odmowa;
    }

    #[Test]
    public function test_raport_przejrzystosci_liczy_zgloszenia_wskazowek_osobno(): void
    {
        $this->zglos($this->user('widz'));

        Artisan::call('kuking:raport-przejrzystosci');
        $wyjscie = Artisan::output();

        $this->assertMatchesRegularExpression('/wskazówka od gotujących\s*\|\s*1\s*\|/u', $wyjscie);
        // Kontrola dodatnia: wykonanie przepisu (osobny wiersz) ma zero zgłoszeń.
        $this->assertMatchesRegularExpression('/\|\s*wykonanie przepisu\s*\|\s*0\s*\|/u', $wyjscie);
    }

    #[Test]
    public function test_zgloszenie_wykonania_dziala_dalej_osobno(): void
    {
        $widz = $this->user('widz');

        $this->actingAs($widz)
            ->post(route('reports.store', ['type' => 'cooked_event', 'id' => $this->wykonanie->getKey()]), ['reason' => 'spam'])
            ->assertSessionHasNoErrors();

        $this->assertSame('cooked_event', Report::sole()->target_type);
    }
}

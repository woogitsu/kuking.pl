<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWersjePrzepisow;
use App\Domain\Recipes\Historia\UkrywanieWersji;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Ukrycie wersji przepisu PRZEZ MODERACJĘ jako decyzja moderacyjna w rozumieniu
 * DSA (decyzja właściciela z 30.09.2026, D-333, wiersz #2270).
 *
 * Dowód problemu (przed zmianą, na gałęzi #2270): moderator ukrywał wersję
 * jednym przyciskiem — bez podstawy, bez uzasadnienia, bez wiersza
 * `moderation_actions`, bez powiadomienia autora i bez drogi odwołania.
 * Autor widział tylko „Ukryta przez moderację” i nie miał od czego się
 * odwołać. `test_ukrycie_przez_moderacje_to_decyzja_z_urzedu_z_powiadomieniem`
 * jest testem regresyjnym tego stanu.
 *
 * Kontrole dodatnie (PULAPKI_TESTOW.md §4): obok każdego „nic się nie stało”
 * stoi „stało się” — ukrycie przez autora dalej działa (tylko bez decyzji),
 * a podtrzymane odwołanie zostawia wersję ukrytą, gdy uznane ją przywraca.
 */
final class UkrywanieWersjiDecyzjaModeracyjnaTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFON = 'tel. 600 100 200';

    private const UZASADNIENIE = 'W opisie tej wersji jest cudzy numer telefonu.';

    private function przepis(): Recipe
    {
        $przepis = Recipe::factory()->create(['title' => 'Rosół babci Jadwigi']);
        for ($n = 1; $n <= 3; $n++) {
            RecipeVersion::create([
                'recipe_id' => $przepis->getKey(),
                'editor_id' => $przepis->author_id,
                'version_number' => $n,
                'change_note' => 'Wersja '.$n,
                'snapshot' => [
                    'title' => $przepis->title,
                    'summary' => $n === 1 ? 'Od babci, '.self::TELEFON.'.' : 'Od babci, wersja '.$n.'.',
                    'ingredients' => [],
                    'steps' => [['position' => 0, 'instruction' => 'Krok '.$n, 'timer_seconds' => null]],
                ],
            ]);
        }

        return $przepis;
    }

    private function wersja(Recipe $przepis, int $numer = 1): RecipeVersion
    {
        return RecipeVersion::where('recipe_id', $przepis->getKey())->where('version_number', $numer)->firstOrFail();
    }

    /** @return array<string, string> */
    private function podstawa(array $zmiany = []): array
    {
        return array_merge([
            'reason_code' => 'cudze-dane-osobowe',
            'user_message' => self::UZASADNIENIE,
            'note' => 'Notatka tylko dla moderacji.',
        ], $zmiany);
    }

    private function ukryjJakoModeracja(User $kto, Recipe $przepis, int $numer = 1): void
    {
        $this->actingAs($kto)
            ->post(route('recipes.history.hide.store', [$przepis->slug, $numer]), $this->podstawa())
            ->assertRedirect(route('recipes.history', $przepis->slug))
            ->assertSessionHasNoErrors();
        auth()->logout();
    }

    private function odwolajSie(Recipe $przepis): Appeal
    {
        $decyzja = ModerationAction::where('action', ModerationAction::ACTION_HIDE)->sole();

        $this->actingAs($przepis->author)
            ->post(route('appeals.store', $decyzja), ['body' => 'To numer do mojej własnej pracowni, podałam go świadomie.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return Appeal::sole();
    }

    public function test_ukrycie_przez_moderacje_to_decyzja_z_urzedu_z_powiadomieniem(): void
    {
        $przepis = $this->przepis();
        $moderator = $this->moderator();

        $this->actingAs($moderator)
            ->get(route('recipes.history.hide', [$przepis->slug, 1]))
            ->assertOk()
            ->assertSee('Podstawa decyzji')
            ->assertSee('Uzasadnienie dla autora')
            ->assertSee('To decyzja moderacyjna');

        $this->ukryjJakoModeracja($moderator, $przepis);

        $wersja = $this->wersja($przepis);
        $this->assertSame(RecipeVersion::UKRYLA_MODERACJA, $wersja->hidden_by_role);

        $decyzja = ModerationAction::sole();
        $this->assertSame('recipe_version', $decyzja->target_type);
        $this->assertSame($wersja->getKey(), $decyzja->target_id);
        $this->assertSame(ModerationAction::ACTION_HIDE, $decyzja->action);
        $this->assertNull($decyzja->report_id, 'Decyzja z urzędu: nikt wersji nie zgłosił.');
        $this->assertSame($przepis->author_id, $decyzja->subject_user_id);
        $this->assertSame($moderator->getKey(), $decyzja->moderator_id);
        $this->assertSame('cudze-dane-osobowe', $decyzja->reason_code);
        $this->assertSame('Notatka tylko dla moderacji.', $decyzja->note);
        $this->assertSame('Dotyczy wersji 1 przepisu „Rosół babci Jadwigi” w historii zmian. '.self::UZASADNIENIE, $decyzja->user_message);
        $this->assertTrue($decyzja->isAppealable());

        // Powiadomienie autora z przyciskiem odwołania (art. 17 + art. 20).
        $powiadomienie = Notification::where('user_id', $przepis->author_id)->where('type', Notification::TYPE_MODERATION)->sole();
        $this->assertSame(ModerationAction::ACTION_HIDE, $powiadomienie->data['decision']);
        $this->assertTrue($powiadomienie->data['appeal']);
        $this->assertSame($decyzja->getKey(), $powiadomienie->data['action_id']);
        $this->assertStringContainsString('wersji 1 przepisu', $powiadomienie->data['message']);

        // Ślad w dzienniku: decyzja z urzędu i ukrycie wersji wskazujące decyzję.
        $this->assertSame($decyzja->getKey(), AuditLogEntry::where('action', 'moderation.ex_officio')->sole()->subject_id);
        $this->assertSame($decyzja->getKey(), AuditLogEntry::where('action', 'recipe_version.hidden')->sole()->metadata['moderation_action_id']);

        // Autor otwiera formularz odwołania od tej decyzji.
        $this->actingAs($przepis->author)->get(route('appeals.show', $decyzja))->assertOk();
    }

    public function test_bez_podstawy_albo_z_za_krotkim_uzasadnieniem_moderacja_niczego_nie_ukrywa(): void
    {
        $przepis = $this->przepis();
        $moderator = $this->moderator();
        $ekran = route('recipes.history.hide', [$przepis->slug, 1]);

        foreach ([
            ['reason_code' => ''],
            ['reason_code' => 'nie-ma-takiej'],
            ['user_message' => 'Krótko'],
            ['user_message' => ''],
        ] as $zle) {
            $this->actingAs($moderator)
                ->from($ekran)
                ->post(route('recipes.history.hide.store', [$przepis->slug, 1]), $this->podstawa($zle))
                ->assertRedirect($ekran)
                ->assertSessionHasErrors();
        }

        $this->assertNull($this->wersja($przepis)->hidden_at);
        $this->assertSame(0, ModerationAction::count());
        $this->assertSame(0, Notification::where('type', Notification::TYPE_MODERATION)->count());

        // Poprawne dane nie znikają: ekran po błędzie oddaje wpisane uzasadnienie.
        $this->actingAs($moderator)
            ->from($ekran)
            ->post(route('recipes.history.hide.store', [$przepis->slug, 1]), $this->podstawa(['reason_code' => '']));
        $this->actingAs($moderator)->get($ekran)->assertOk()->assertSee(self::UZASADNIENIE);
    }

    public function test_ukrycie_przez_autora_zostaje_bez_decyzji_dsa(): void
    {
        $przepis = $this->przepis();

        $this->actingAs($przepis->author)
            ->get(route('recipes.history.hide', [$przepis->slug, 1]))
            ->assertOk()
            ->assertDontSee('Podstawa decyzji');
        $this->actingAs($przepis->author)
            ->post(route('recipes.history.hide.store', [$przepis->slug, 1]))
            ->assertRedirect(route('recipes.history', $przepis->slug))
            ->assertSessionHasNoErrors();

        $this->assertSame(RecipeVersion::UKRYL_AUTOR, $this->wersja($przepis)->hidden_by_role);
        $this->assertSame(0, ModerationAction::count());
        $this->assertSame(0, Notification::where('type', Notification::TYPE_MODERATION)->count());

        $this->actingAs($przepis->author)
            ->post(route('recipes.history.restore.store', [$przepis->slug, 1]))
            ->assertRedirect(route('recipes.history', $przepis->slug));
        $this->assertNull($this->wersja($przepis)->hidden_at);
        $this->assertSame(0, ModerationAction::count());
    }

    public function test_uznane_odwolanie_przywraca_wersje(): void
    {
        $przepis = $this->przepis();
        $this->ukryjJakoModeracja($this->moderator(), $przepis);
        $this->get(route('recipes.history.version', [$przepis->slug, 1]))->assertNotFound();

        $odwolanie = $this->odwolajSie($przepis);

        $this->actingAs($this->admin())
            ->get(route('admin.appeals'))
            ->assertOk()
            ->assertSee('wersja przepisu (historia zmian)');
        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Masz rację, to numer pracowni. Wersja wraca do historii.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        auth()->logout();

        $this->assertSame(Appeal::STATUS_OVERTURNED, $odwolanie->fresh()->status);
        $this->assertNull($this->wersja($przepis)->hidden_at);
        $this->assertNull($this->wersja($przepis)->hidden_by_role);

        $cofniecie = ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->sole();
        $this->assertSame('recipe_version', $cofniecie->target_type);
        $this->assertSame($this->wersja($przepis)->getKey(), $cofniecie->target_id);
        $this->assertSame('appeal_overturned', $cofniecie->reason_code);

        // Gość znowu widzi wersję — razem z treścią, którą miała.
        $this->get(route('recipes.history.version', [$przepis->slug, 1]))->assertOk()->assertSee(self::TELEFON);
    }

    public function test_podtrzymane_odwolanie_zostawia_wersje_ukryta(): void
    {
        $przepis = $this->przepis();
        $this->ukryjJakoModeracja($this->moderator(), $przepis);
        $odwolanie = $this->odwolajSie($przepis);

        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_UPHELD,
                'decision_note' => 'Numer telefonu prywatnej osoby nie może być publiczny.',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Appeal::STATUS_UPHELD, $odwolanie->fresh()->status);
        $this->assertTrue($this->wersja($przepis)->czyUkrytaPrzezModeracje());
        $this->assertSame(0, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->count());
    }

    public function test_przywrocenie_przez_moderacje_jest_decyzja_z_powodem_i_powiadomieniem(): void
    {
        $przepis = $this->przepis();
        $moderator = $this->moderator();
        $this->ukryjJakoModeracja($moderator, $przepis);
        $ekran = route('recipes.history.restore', [$przepis->slug, 1]);

        $this->actingAs($moderator)->get($ekran)->assertOk()->assertSee('Powód przywrócenia');

        // Bez powodu nic się nie dzieje.
        $this->actingAs($moderator)
            ->from($ekran)
            ->post(route('recipes.history.restore.store', [$przepis->slug, 1]), ['reason_code' => ''])
            ->assertRedirect($ekran)
            ->assertSessionHasErrors('reason_code');
        $this->assertNotNull($this->wersja($przepis)->hidden_at);

        $this->actingAs($moderator)
            ->post(route('recipes.history.restore.store', [$przepis->slug, 1]), ['reason_code' => 'autor usunął numer telefonu'])
            ->assertRedirect(route('recipes.history', $przepis->slug))
            ->assertSessionHasNoErrors();

        $this->assertNull($this->wersja($przepis)->hidden_at);
        $cofniecie = ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->sole();
        $this->assertSame('autor usunął numer telefonu', $cofniecie->reason_code);
        $this->assertSame(
            ModerationAction::ACTION_UNHIDE,
            Notification::where('user_id', $przepis->author_id)->where('type', Notification::TYPE_MODERATION)->latest('created_at')->orderByDesc('id')->first()->data['decision'],
        );
    }

    public function test_moderator_nie_cofa_ukrycia_administratora(): void
    {
        $przepis = $this->przepis();
        $this->ukryjJakoModeracja($this->admin(), $przepis);

        $this->actingAs($this->moderator())
            ->post(route('recipes.history.restore.store', [$przepis->slug, 1]), ['reason_code' => 'pomyłka'])
            ->assertSessionHasErrors('reason_code');

        $this->assertTrue($this->wersja($przepis)->czyUkrytaPrzezModeracje());
        $this->assertSame(0, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->count());
    }

    public function test_strona_moderacji_bez_decyzji_nie_ukrywa_ani_nie_odslania(): void
    {
        $przepis = $this->przepis();
        $moderator = $this->moderator();

        try {
            app(UkrywanieWersji::class)->ukryj($moderator, $przepis, 1);
            $this->fail('Ukrycie przez moderację bez decyzji moderacyjnej musi odmówić.');
        } catch (LogicException $e) {
            $this->assertSame(UkrywanieWersji::BEZ_DECYZJI, $e->getMessage());
        }
        $this->assertNull($this->wersja($przepis)->hidden_at);

        $this->ukryjJakoModeracja($moderator, $przepis);

        try {
            app(UkrywanieWersji::class)->przywroc($moderator, $przepis, 1);
            $this->fail('Cofnięcie ukrycia moderacji bez decyzji musi odmówić.');
        } catch (LogicException $e) {
            $this->assertSame(UkrywanieWersji::BEZ_DECYZJI, $e->getMessage());
        }
        $this->assertTrue($this->wersja($przepis)->czyUkrytaPrzezModeracje());
    }

    public function test_raport_przejrzystosci_liczy_ukrycie_wersji_i_cofniecie_po_odwolaniu(): void
    {
        $przepis = $this->przepis();
        $this->ukryjJakoModeracja($this->moderator(), $przepis);
        $odwolanie = $this->odwolajSie($przepis);
        $this->actingAs($this->admin())->post(route('admin.appeals.resolve', $odwolanie), [
            'outcome' => Appeal::STATUS_OVERTURNED,
            'decision_note' => 'Masz rację, wersja wraca.',
        ])->assertSessionHasNoErrors();

        // Ukrycie przez autora innej wersji nie jest decyzją i raport go nie liczy.
        auth()->logout();
        $this->actingAs($przepis->author)->post(route('recipes.history.hide.store', [$przepis->slug, 2]));

        Artisan::call('kuking:raport-przejrzystosci');
        $wyjscie = Artisan::output();

        $this->assertWiersz($wyjscie, '3. Decyzje z urzędu', 'Ukryj treść', '1');
        $this->assertWiersz($wyjscie, '3. Decyzje z urzędu', 'RAZEM', '1');
        $this->assertMatchesRegularExpression('/4\. Przywrócenia treści.*: 1\b/u', $wyjscie);
        $this->assertWiersz($wyjscie, '5. Odwołania', 'autor treści (od sankcji)', '0 1');
    }

    public function test_retencja_nie_kasuje_wersji_z_decyzja_moderacji_ale_kasuje_sasiednia(): void
    {
        $przepis = $this->przepis();
        for ($n = 4; $n <= 5; $n++) {
            RecipeVersion::create([
                'recipe_id' => $przepis->getKey(),
                'editor_id' => $przepis->author_id,
                'version_number' => $n,
                'snapshot' => ['title' => $przepis->title, 'summary' => 'Wersja '.$n, 'ingredients' => [], 'steps' => []],
            ]);
        }
        $this->ukryjJakoModeracja($this->moderator(), $przepis, 1);
        DB::table('recipe_versions')->where('recipe_id', $przepis->getKey())->where('version_number', '<=', 2)
            ->update(['created_at' => Carbon::parse('2020-01-10 10:00:00', 'UTC')]);

        $wynik = (new PrzedawnioneWersjePrzepisow)->posprzataj(24, 3);

        // Wersja 2 (bez decyzji) znika, wersja 1 (w sprawie moderacyjnej) zostaje.
        $this->assertSame(1, $wynik['skasowano']);
        $this->assertSame([5, 4, 3, 1], RecipeVersion::where('recipe_id', $przepis->getKey())->orderByDesc('version_number')->pluck('version_number')->all());
    }

    /** Wiersz tabeli Symfony: `| etykieta | liczba |` (jak w `RaportPrzejrzystosciTest`). */
    private function assertWiersz(string $wyjscie, string $sekcja, string $etykieta, string $liczby): void
    {
        $poczatek = strpos($wyjscie, $sekcja);
        $this->assertNotFalse($poczatek, "Brak sekcji „{$sekcja}” w raporcie.");
        $nastepna = strpos($wyjscie, "\n\n", $poczatek + strlen($sekcja) + 1);
        $fragment = substr($wyjscie, $poczatek, $nastepna === false ? null : $nastepna - $poczatek);

        $wzor = '/\|\s*'.preg_quote($etykieta, '/').'\s*\|\s*'.implode('\s*\|\s*', array_map(fn ($l) => preg_quote($l, '/'), explode(' ', $liczby))).'\s*\|/u';
        $this->assertMatchesRegularExpression($wzor, $fragment, "Sekcja „{$sekcja}”: wiersz „{$etykieta}” nie ma liczb {$liczby}.\n{$fragment}");
    }
}

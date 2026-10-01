<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\CelZAdresuZgloszenia;
use App\Models\Appeal;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\PolecenieArtisanaZOdmowa;
use Tests\TestCase;

/**
 * Zgłaszanie KONKRETNEJ WERSJI przepisu z historii zmian (#2390, decyzja
 * właściciela z 1.10.2026): osoba trzecia — także gość, jak przy zwykłym
 * zgłoszeniu treści — zgłasza wersję, a moderacja ukrywa ją w całości tą samą
 * drogą co ukrycie z historii. Redakcji fragmentu migawki nie ma.
 *
 * Asercje „nie ma” mają obok kontrolę dodatnią (`docs/PULAPKI_TESTOW.md` §4),
 * a asercje o obecności odnośnika czytają wycinek strony (§1).
 */
final class ZgloszenieWersjiPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const TELEFON = 'tel. 600 100 200';

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

    private function wersja(Recipe $przepis, int $numer): RecipeVersion
    {
        return RecipeVersion::where('recipe_id', $przepis->getKey())->where('version_number', $numer)->firstOrFail();
    }

    private function formularz(RecipeVersion $wersja): string
    {
        return route('reports.create', ['type' => 'recipe_version', 'id' => $wersja->getKey()]);
    }

    private function zglos(User $kto, RecipeVersion $wersja, string $powod = 'personal_data'): void
    {
        $this->actingAs($kto)
            ->post(route('reports.store', ['type' => 'recipe_version', 'id' => $wersja->getKey()]), ['reason' => $powod])
            ->assertSessionHasNoErrors();
        auth()->logout();
    }

    /** @return array<string, string> */
    private function decyzjaUkryj(array $zmiany = []): array
    {
        return array_merge([
            'action' => ModerationAction::ACTION_HIDE,
            'reason_code' => 'cudze-dane-osobowe',
            'user_message' => 'W opisie tej wersji jest cudzy numer telefonu.',
            'note' => 'Notatka tylko dla moderacji.',
        ], $zmiany);
    }

    private function wycinekGlownej(string $html): string
    {
        $od = strpos($html, '<main');
        $this->assertNotFalse($od, 'Kontrola: strona nie ma <main>.');

        return substr($html, $od);
    }

    #[Test]
    public function test_ekran_starej_wersji_ma_zglos_a_najnowszej_nie(): void
    {
        $przepis = $this->przepis();
        $widz = $this->user('widz');
        $stara = $this->wersja($przepis, 1);
        $najnowsza = $this->wersja($przepis, 3);

        $html = $this->wycinekGlownej((string) $this->actingAs($widz)
            ->get(route('recipes.history.version', [$przepis->slug, 1]))->assertOk()->getContent());
        $this->assertStringContainsString($this->formularz($stara), $html);
        $this->assertStringContainsString('Zgłoś wersję 1', $html);

        // Kontrola ujemna: na najnowszej wersji, czyli na przepisie, przycisku nie ma.
        $html = $this->wycinekGlownej((string) $this->actingAs($widz)
            ->get(route('recipes.history.version', [$przepis->slug, 3]))->assertOk()->getContent());
        $this->assertStringContainsString('To najnowsza zapisana wersja.', $html, 'Kontrola: to jest ekran najnowszej wersji.');
        $this->assertStringNotContainsString($this->formularz($najnowsza), $html);
        $this->assertStringNotContainsString('Zgłoś wersję', $html);
    }

    #[Test]
    public function test_autor_nie_widzi_zglos_przy_wlasnej_wersji(): void
    {
        $przepis = $this->przepis();
        $adres = route('recipes.history.version', [$przepis->slug, 1]);

        $this->assertStringNotContainsString('Zgłoś wersję', $this->wycinekGlownej(
            (string) $this->actingAs($przepis->author)->get($adres)->assertOk()->getContent(),
        ));
        // Kontrola dodatnia: ktoś obcy na tym samym ekranie go widzi.
        $this->assertStringContainsString('Zgłoś wersję 1', $this->wycinekGlownej(
            (string) $this->actingAs($this->user('obca'))->get($adres)->assertOk()->getContent(),
        ));
    }

    #[Test]
    public function test_gosc_widzi_zglos_po_zalogowaniu_i_trafia_na_logowanie(): void
    {
        $przepis = $this->przepis();
        $stara = $this->wersja($przepis, 1);

        $html = $this->wycinekGlownej((string) $this->get(route('recipes.history.version', [$przepis->slug, 1]))->assertOk()->getContent());
        $this->assertStringContainsString($this->formularz($stara), $html);
        $this->assertStringContainsString('Zgłoś wersję 1 (po zalogowaniu)', $html);
        $this->assertStringContainsString('treść niezgodną z prawem', $html);

        $this->get($this->formularz($stara))->assertRedirect(route('login'));
        $this->assertSame(0, Report::count());
    }

    #[Test]
    public function test_formularz_i_zapis_zgloszenia_wersji_dzialaja(): void
    {
        $przepis = $this->przepis();
        $stara = $this->wersja($przepis, 1);
        $widz = $this->user('widz');

        $this->actingAs($widz)->get($this->formularz($stara))->assertOk()
            ->assertSee('Zgłoś: wersja 1 przepisu «Rosół babci Jadwigi» (historia zmian)')
            ->assertSee(route('recipes.history.version', [$przepis->slug, 1]));

        $this->actingAs($widz)
            ->post(route('reports.store', ['type' => 'recipe_version', 'id' => $stara->getKey()]), ['reason' => 'personal_data'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $zgloszenie = Report::sole();
        $this->assertSame('recipe_version', $zgloszenie->target_type);
        $this->assertSame($stara->getKey(), $zgloszenie->target_id);
        $this->assertSame($widz->getKey(), $zgloszenie->reporter_id);
        $this->assertSame('wersja przepisu (historia zmian)', $zgloszenie->targetLabel());

        // Powtórzone zgłoszenie tej samej wersji to ta sama sprawa.
        $this->actingAs($widz)
            ->post(route('reports.store', ['type' => 'recipe_version', 'id' => $stara->getKey()]), ['reason' => 'personal_data']);
        $this->assertSame(1, Report::count());
    }

    #[Test]
    public function test_najnowsza_wersja_odsyla_do_zgloszenia_przepisu_a_zapis_jej_nie_przyjmuje(): void
    {
        $przepis = $this->przepis();
        $najnowsza = $this->wersja($przepis, 3);
        $widz = $this->user('widz');

        $this->actingAs($widz)->get($this->formularz($najnowsza))
            ->assertRedirect(route('reports.create', ['type' => 'recipe', 'id' => $przepis->slug]));

        $this->actingAs($widz)
            ->post(route('reports.store', ['type' => 'recipe_version', 'id' => $najnowsza->getKey()]), ['reason' => 'spam'])
            ->assertNotFound();
        $this->assertSame(0, Report::count());

        // Kontrola dodatnia: starsza wersja tego samego przepisu przyjmuje zgłoszenie.
        $this->zglos($widz, $this->wersja($przepis, 2), 'spam');
        $this->assertSame(1, Report::count());
    }

    #[Test]
    public function test_wersji_ukrytej_nie_zglosi_obcy_a_autor_i_moderacja_tak(): void
    {
        $przepis = $this->przepis();
        $ukryta = $this->wersja($przepis, 1);
        $ukryta->ukryj(RecipeVersion::UKRYL_AUTOR);
        $obcy = $this->user('obcy');

        $this->actingAs($obcy)->get($this->formularz($ukryta))->assertNotFound();
        $this->actingAs($obcy)
            ->post(route('reports.store', ['type' => 'recipe_version', 'id' => $ukryta->getKey()]), ['reason' => 'spam'])
            ->assertNotFound();
        $this->assertSame(0, Report::count());

        // Kontrola dodatnia: ta sama wersja, gdy ją odkryć, daje formularz.
        $ukryta->odkryj();
        $this->actingAs($obcy)->get($this->formularz($ukryta))->assertOk();

        // Moderacja widzi wersje ukryte, więc może je zgłosić (np. po sygnale z zewnątrz).
        $ukryta->ukryj(RecipeVersion::UKRYL_AUTOR);
        $this->actingAs($this->moderator())->get($this->formularz($ukryta))->assertOk();
    }

    #[Test]
    public function test_wersji_ukrytego_lub_niewidocznego_przepisu_nie_da_sie_zglosic(): void
    {
        $przepis = $this->przepis();
        $stara = $this->wersja($przepis, 1);
        $obcy = $this->user('obcy');

        $przepis->forceFill(['status' => Recipe::STATUS_HIDDEN])->save();
        $this->actingAs($obcy)->get($this->formularz($stara))->assertNotFound();

        // Kontrola dodatnia: po przywróceniu przepisu formularz wraca.
        $przepis->forceFill(['status' => Recipe::STATUS_PUBLISHED])->save();
        $this->actingAs($obcy)->get($this->formularz($stara))->assertOk();
    }

    #[Test]
    public function test_nieistniejaca_wersja_i_zly_identyfikator_to_404(): void
    {
        $obcy = $this->user('obcy');

        $this->actingAs($obcy)->get(route('reports.create', ['type' => 'recipe_version', 'id' => '00000000-0000-4000-8000-000000000000']))->assertNotFound();
        $this->actingAs($obcy)->get(route('reports.create', ['type' => 'recipe_version', 'id' => 'rosol']))->assertNotFound();
    }

    #[Test]
    public function test_decyzje_dla_wersji_to_brak_ostrzezenie_ukrycie_zawieszenie_i_ban(): void
    {
        $this->assertSame(
            [ModerationAction::ACTION_NONE, ModerationAction::ACTION_WARN, ModerationAction::ACTION_HIDE, ModerationAction::ACTION_SUSPEND, ModerationAction::ACTION_BAN],
            ModerationAction::DOZWOLONE['recipe_version'],
        );
        $this->assertArrayNotHasKey(ModerationAction::ACTION_REMOVE, ModerationAction::dozwoloneDla('recipe_version'));
        // Po uznanym odwołaniu ukrycie wersji idzie z ekranu historii, nie z tego formularza.
        $this->assertArrayNotHasKey(ModerationAction::ACTION_HIDE, ModerationAction::dozwolonePoOdwolaniu('recipe_version'));
        // Kontrola dodatnia: dla przepisu „ukryj” po odwołaniu zostaje.
        $this->assertArrayHasKey(ModerationAction::ACTION_HIDE, ModerationAction::dozwolonePoOdwolaniu('recipe'));
    }

    #[Test]
    public function test_moderator_widzi_w_kolejce_wersje_z_odnosnikiem(): void
    {
        $przepis = $this->przepis();
        $this->zglos($this->user('widz'), $this->wersja($przepis, 2));

        $this->actingAs($this->moderator())->get(route('admin.reports'))->assertOk()
            ->assertSee('Zgłoszona jest')
            ->assertSee('wersja 2')
            ->assertSee('Rosół babci Jadwigi')
            ->assertSee(route('recipes.history.version', [$przepis->slug, 2]), false)
            ->assertSee('Ukryj treść');
    }

    #[Test]
    public function test_ukryj_tresc_ukrywa_wersje_i_zamyka_zgloszenie_z_odpowiedzia_dla_zglaszajacego(): void
    {
        $przepis = $this->przepis();
        $wersja = $this->wersja($przepis, 1);
        $zglaszajacy = $this->user('widz');
        $moderator = $this->moderator();
        $this->zglos($zglaszajacy, $wersja);
        $zgloszenie = Report::sole();

        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $zgloszenie), $this->decyzjaUkryj())
            ->assertSessionHasNoErrors();
        auth()->logout();

        // Skutek: cała wersja ukryta przez moderację, przepis i inne wersje nietknięte.
        $wersja->refresh();
        $this->assertTrue($wersja->czyUkryta());
        $this->assertSame(RecipeVersion::UKRYLA_MODERACJA, $wersja->hidden_by_role);
        $this->assertNull($this->wersja($przepis, 2)->hidden_at);
        $this->assertSame(Recipe::STATUS_PUBLISHED, $przepis->fresh()->status);

        // Decyzja ze zgłoszenia: jedna, z report_id, na wersję, z podstawą i uzasadnieniem.
        $decyzja = ModerationAction::sole();
        $this->assertSame($zgloszenie->getKey(), $decyzja->report_id);
        $this->assertSame('recipe_version', $decyzja->target_type);
        $this->assertSame($wersja->getKey(), $decyzja->target_id);
        $this->assertSame(ModerationAction::ACTION_HIDE, $decyzja->action);
        $this->assertSame($przepis->author_id, $decyzja->subject_user_id);
        $this->assertSame('cudze-dane-osobowe', $decyzja->reason_code);
        $this->assertSame(
            'Dotyczy wersji 1 przepisu „Rosół babci Jadwigi” w historii zmian. W opisie tej wersji jest cudzy numer telefonu.',
            $decyzja->user_message,
        );
        $this->assertTrue($decyzja->isAppealable());

        // Zgłoszenie rozstrzygnięte.
        $this->assertSame(Report::STATUS_RESOLVED, $zgloszenie->fresh()->status);

        // Gość i osoba trzecia nie widzą wersji; autor tak, z oznaczeniem.
        $this->get(route('recipes.history.version', [$przepis->slug, 1]))->assertNotFound();
        $this->actingAs($this->user('inna'))->get(route('recipes.history.version', [$przepis->slug, 1]))->assertNotFound();
        $this->actingAs($przepis->author)->get(route('recipes.history.version', [$przepis->slug, 1]))->assertOk();
        auth()->logout();

        // Autor dostał powiadomienie z drogą odwołania, zgłaszający — odpowiedź tą samą drogą co dziś.
        $dlaAutora = Notification::where('user_id', $przepis->author_id)->where('type', Notification::TYPE_MODERATION)->sole();
        $this->assertTrue($dlaAutora->data['appeal']);
        $this->assertSame($decyzja->getKey(), $dlaAutora->data['action_id']);
        $this->assertStringContainsString('wersji 1 przepisu', $dlaAutora->data['message']);

        $dlaZglaszajacego = Notification::where('user_id', $zglaszajacy->getKey())->where('type', Notification::TYPE_REPORT_DECIDED)->sole();
        $this->assertNotNull($dlaZglaszajacego);

        // Karta sprawy zgłaszającego mówi, czego dotyczyło zgłoszenie.
        $this->actingAs($zglaszajacy)->get(route('reports.mine.show', $zgloszenie))->assertOk()
            ->assertSee('wersja przepisu (historia zmian)');
    }

    #[Test]
    public function test_autor_odwoluje_sie_jak_od_ukrycia_wersji_a_uznane_odwolanie_przywraca_ja(): void
    {
        $przepis = $this->przepis();
        $wersja = $this->wersja($przepis, 1);
        $this->zglos($this->user('widz'), $wersja);

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', Report::sole()), $this->decyzjaUkryj())
            ->assertSessionHasNoErrors();
        auth()->logout();

        $decyzja = ModerationAction::sole();
        $this->actingAs($przepis->author)->get(route('appeals.show', $decyzja))->assertOk();
        $this->actingAs($przepis->author)
            ->post(route('appeals.store', $decyzja), ['body' => 'To numer do mojej własnej pracowni, podałam go świadomie.'])
            ->assertSessionHasNoErrors();
        auth()->logout();

        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', Appeal::sole()), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Masz rację, to numer pracowni. Wersja wraca do historii.',
            ])
            ->assertSessionHasNoErrors();
        auth()->logout();

        $this->assertNull($wersja->fresh()->hidden_at, 'Uznane odwołanie nie przywróciło wersji.');
        $this->assertSame(1, ModerationAction::where('action', ModerationAction::ACTION_UNHIDE)->where('target_type', 'recipe_version')->count());
        $this->get(route('recipes.history.version', [$przepis->slug, 1]))->assertOk();
    }

    #[Test]
    public function test_ukrycie_przejmuje_wersje_ukryta_wczesniej_przez_autora(): void
    {
        $przepis = $this->przepis();
        $wersja = $this->wersja($przepis, 1);
        $wersja->ukryj(RecipeVersion::UKRYL_AUTOR);
        $this->zglos($this->moderator(), $wersja);
        // Moderator nie rozstrzyga własnej sprawy — rozstrzyga inny.
        $drugi = $this->moderator();

        $this->actingAs($drugi)
            ->post(route('admin.reports.decide', Report::sole()), $this->decyzjaUkryj())
            ->assertSessionHasNoErrors();

        $this->assertSame(RecipeVersion::UKRYLA_MODERACJA, $wersja->fresh()->hidden_by_role);
        $this->assertSame(RecipeVersion::STAN_PRZED_PRZEJECIEM, ModerationAction::sole()->previous_status);
    }

    #[Test]
    public function test_bez_dzialania_nie_rusza_wersji_ani_nie_powiadamia_autora(): void
    {
        $przepis = $this->przepis();
        $wersja = $this->wersja($przepis, 1);
        $zglaszajacy = $this->user('widz');
        $this->zglos($zglaszajacy, $wersja);

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', Report::sole()), [
                'action' => ModerationAction::ACTION_NONE,
                'reason_code' => 'cudze-dane-osobowe',
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull($wersja->fresh()->hidden_at);
        $this->assertSame(Report::STATUS_REJECTED, Report::sole()->status);
        $this->assertNull(ModerationAction::sole()->user_message, '„Bez działania” nie ma dopisywać zdania o wersji.');
        // Autor nie dostaje nic, ale zgłaszający dostaje odpowiedź.
        $this->assertSame(0, Notification::where('user_id', $przepis->author_id)->where('type', Notification::TYPE_MODERATION)->count());
        $this->assertSame(1, Notification::where('user_id', $zglaszajacy->getKey())->where('type', Notification::TYPE_REPORT_DECIDED)->count());
    }

    #[Test]
    public function test_ostrzezenie_autora_nie_ukrywa_wersji_ale_wskazuje_ktorej_dotyczy(): void
    {
        $przepis = $this->przepis();
        $wersja = $this->wersja($przepis, 1);
        $this->zglos($this->user('widz'), $wersja);

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', Report::sole()), $this->decyzjaUkryj(['action' => ModerationAction::ACTION_WARN]))
            ->assertSessionHasNoErrors();

        $this->assertNull($wersja->fresh()->hidden_at);
        $decyzja = ModerationAction::sole();
        $this->assertSame(ModerationAction::ACTION_WARN, $decyzja->action);
        $this->assertStringStartsWith('Dotyczy wersji 1 przepisu', (string) $decyzja->user_message);
    }

    #[Test]
    public function test_remove_dla_wersji_jest_odrzucone(): void
    {
        $przepis = $this->przepis();
        $wersja = $this->wersja($przepis, 1);
        $this->zglos($this->user('widz'), $wersja);

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', Report::sole()), $this->decyzjaUkryj(['action' => ModerationAction::ACTION_REMOVE]))
            ->assertSessionHasErrors('action');

        $this->assertNotNull(RecipeVersion::find($wersja->getKey()));
        $this->assertSame(0, ModerationAction::count());
    }

    #[Test]
    public function test_wersja_ktora_przestala_byc_ukrywalna_nie_zamyka_zgloszenia_po_cichu(): void
    {
        $przepis = $this->przepis();
        $wersja = $this->wersja($przepis, 2);
        $this->zglos($this->user('widz'), $wersja);
        $zgloszenie = Report::sole();
        $moderator = $this->moderator();

        // Już ukryta przez moderację (np. z historii) — „Ukryj treść” nie udaje sukcesu.
        $wersja->ukryj(RecipeVersion::UKRYLA_MODERACJA);
        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $zgloszenie), $this->decyzjaUkryj())
            ->assertSessionHasErrors('action');
        $this->assertSame(0, ModerationAction::count());
        $this->assertSame(Report::STATUS_OPEN, $zgloszenie->fresh()->status);
        $this->assertSame(0, Notification::where('type', Notification::TYPE_MODERATION)->count());

        // Wersja 2 zostaje najnowszą po usunięciu 3 (np. retencja) — nie da się jej ukryć.
        $wersja->odkryj();
        $this->wersja($przepis, 3)->delete();
        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $zgloszenie), $this->decyzjaUkryj())
            ->assertSessionHasErrors('action');
        $this->assertNull($wersja->fresh()->hidden_at);
        $this->assertSame(Report::STATUS_OPEN, $zgloszenie->fresh()->status);

        // Kontrola dodatnia: gdy powstanie nowsza wersja, ta sama decyzja przechodzi.
        RecipeVersion::create([
            'recipe_id' => $przepis->getKey(),
            'editor_id' => $przepis->author_id,
            'version_number' => 4,
            'snapshot' => ['title' => $przepis->title],
        ]);
        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $zgloszenie), $this->decyzjaUkryj())
            ->assertSessionHasNoErrors();
        $this->assertTrue($wersja->fresh()->czyUkryta());
    }

    #[Test]
    public function test_wiadomosc_dla_autora_mieści_sie_w_kolumnie_razem_ze_zdaniem_o_wersji(): void
    {
        $przepis = $this->przepis();
        $this->zglos($this->user('widz'), $this->wersja($przepis, 1));
        $moderator = $this->moderator();
        $zgloszenie = Report::sole();

        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $zgloszenie), $this->decyzjaUkryj(['user_message' => str_repeat('a', 2000)]))
            ->assertSessionHasErrors('user_message');
        $this->assertSame(0, ModerationAction::count());

        // Kontrola dodatnia: wiadomość o długości równej limitowi przechodzi i mieści się w kolumnie.
        $limit = 2000 - mb_strlen('Dotyczy wersji 1 przepisu „Rosół babci Jadwigi” w historii zmian.') - 1;
        $this->actingAs($moderator)
            ->post(route('admin.reports.decide', $zgloszenie), $this->decyzjaUkryj(['user_message' => str_repeat('a', $limit)]))
            ->assertSessionHasNoErrors();
        $this->assertSame(2000, mb_strlen((string) ModerationAction::sole()->user_message));
    }

    #[Test]
    public function test_moderator_nie_rozstrzyga_zgloszenia_wlasnej_wersji(): void
    {
        $przepis = $this->przepis();
        $autorModerator = $this->moderator();
        $przepis->forceFill(['author_id' => $autorModerator->getKey()])->save();
        $this->zglos($this->user('widz'), $this->wersja($przepis, 1));

        $this->actingAs($autorModerator)
            ->post(route('admin.reports.decide', Report::sole()), $this->decyzjaUkryj())
            ->assertSessionHasErrors('action');

        $this->assertNull($this->wersja($przepis, 1)->hidden_at);
        $this->assertSame(0, ModerationAction::count());
    }

    #[Test]
    public function test_zgloszenie_prawne_z_adresem_wersji_dotyczy_tej_wersji_a_najnowszej_przepisu(): void
    {
        $przepis = $this->przepis();
        $stara = $this->wersja($przepis, 1);
        $adres = rtrim((string) config('app.url'), '/').'/przepisy/'.$przepis->slug;

        $this->assertSame(['recipe_version', $stara->getKey()], CelZAdresuZgloszenia::rozpoznaj($adres.'/historia/1'));
        $this->assertSame(['recipe_version', $stara->getKey()], CelZAdresuZgloszenia::rozpoznaj($adres.'/historia/1/zmiany'));
        // Najnowsza wersja, numer spoza historii i śmieci zostają przepisem.
        $this->assertSame(['recipe', $przepis->getKey()], CelZAdresuZgloszenia::rozpoznaj($adres.'/historia/3'));
        $this->assertSame(['recipe', $przepis->getKey()], CelZAdresuZgloszenia::rozpoznaj($adres.'/historia/99'));
        $this->assertSame(['recipe', $przepis->getKey()], CelZAdresuZgloszenia::rozpoznaj($adres.'/historia/abc'));
        $this->assertSame(['recipe', $przepis->getKey()], CelZAdresuZgloszenia::rozpoznaj($adres));
        // Obcy host nie przypina się do naszej wersji.
        $this->assertSame([null, null], CelZAdresuZgloszenia::rozpoznaj('https://obcy.example/przepisy/'.$przepis->slug.'/historia/1'));
    }

    #[Test]
    public function test_formularz_prawny_przyjmuje_adres_wersji_bez_konta(): void
    {
        $przepis = $this->przepis();
        $stara = $this->wersja($przepis, 1);

        $this->post(route('zglos.nielegalna.store'), [
            'target_url' => rtrim((string) config('app.url'), '/').'/przepisy/'.$przepis->slug.'/historia/1',
            'illegality_explanation' => 'W tej wersji jest cudzy numer telefonu opublikowany bez zgody.',
            'reason' => 'personal_data',
            'good_faith' => '1',
        ]);

        $zgloszenie = Report::query()->where('source', Report::SOURCE_LEGAL_NOTICE)->first();
        $this->assertNotNull($zgloszenie, 'Zgłoszenie prawne nie powstało.');
        $this->assertSame('recipe_version', $zgloszenie->target_type);
        $this->assertSame($stara->getKey(), $zgloszenie->target_id);
    }

    #[Test]
    public function test_baza_przyjmuje_wersje_a_typ_spoza_listy_odbija_sie_o_check(): void
    {
        $przepis = $this->przepis();
        $this->zglos($this->user('widz'), $this->wersja($przepis, 1));
        $this->assertSame('recipe_version', Report::sole()->target_type);

        // Kontrola: CHECK nie został zdjęty, tylko poszerzony.
        $this->expectException(QueryException::class);
        DB::table('reports')->insert([
            'id' => (string) Str::uuid(),
            'source' => Report::SOURCE_COMMUNITY,
            'reporter_id' => $this->user('x')->getKey(),
            'target_type' => 'wersja-w-tle',
            'target_id' => (string) Str::uuid(),
            'reason' => 'spam',
            'status' => Report::STATUS_OPEN,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function test_rollback_odmawia_gdy_jest_zgloszenie_wersji_a_bez_niego_przechodzi(): void
    {
        $migracja = 'database/migrations/2026_10_01_100200_wersja_przepisu_jako_cel_zgloszenia.php';

        // Kontrola dodatnia: bez zgłoszeń wersji rollback przechodzi i da się go powtórzyć.
        Artisan::call('migrate:rollback', ['--path' => $migracja]);
        Artisan::call('migrate', ['--path' => $migracja]);

        $przepis = $this->przepis();
        $this->zglos($this->user('widz'), $this->wersja($przepis, 1));

        // Odmowę odkładamy do zmiennej i oceniamy poza blokiem (D-133).
        $odmowa = null;
        try {
            PolecenieArtisanaZOdmowa::wywolaj('migrate:rollback', ['--path' => $migracja, '--realpath' => false]);
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Rollback przeszedł, choć w bazie jest zgłoszenie wersji.');
        $this->assertStringContainsString('Liczba zgłoszeń wersji przepisu (`target_type = recipe_version`) w `reports`: 1.', $odmowa->getMessage());
        $this->assertSame(1, Report::where('target_type', 'recipe_version')->count());
    }

    #[Test]
    public function test_raport_przejrzystosci_liczy_zgloszenia_wersji_osobno(): void
    {
        $przepis = $this->przepis();
        $this->zglos($this->user('widz'), $this->wersja($przepis, 1));
        $this->zglos($this->user('inny'), $this->wersja($przepis, 2));

        Artisan::call('kuking:raport-przejrzystosci');
        $wyjscie = Artisan::output();

        $this->assertStringContainsString('1a. Zgłoszenia według rodzaju treści', $wyjscie);
        $this->assertMatchesRegularExpression('/wersja przepisu \(historia zmian\)\s*\|\s*2\s*\|/', $wyjscie);
        // Kontrola dodatnia: przepis (osobny wiersz) ma zero zgłoszeń.
        $this->assertMatchesRegularExpression('/\|\s*przepis\s*\|\s*0\s*\|/', $wyjscie);
    }

    #[Test]
    public function test_uznane_odwolanie_zglaszajacego_wykonuje_nowa_decyzje_wobec_autora_wersji(): void
    {
        $przepis = $this->przepis();
        $wersja = $this->wersja($przepis, 1);
        $this->zglos($this->user('widz'), $wersja);

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', Report::sole()), [
                'action' => ModerationAction::ACTION_NONE,
                'reason_code' => 'brak_naruszenia',
            ])
            ->assertSessionHasNoErrors();
        auth()->logout();

        $pierwotna = ModerationAction::sole();
        $odwolanie = Appeal::create([
            'moderation_action_id' => $pierwotna->getKey(),
            'report_id' => $pierwotna->report_id,
            'appellant' => Appeal::APPELLANT_REPORTER,
            'body' => 'W tej wersji dalej jest cudzy numer telefonu, proszę sprawdzić jeszcze raz.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        $this->actingAs($this->admin())
            ->post(route('admin.appeals.resolve', $odwolanie), [
                'outcome' => Appeal::STATUS_OVERTURNED,
                'decision_note' => 'Zgłoszenie było zasadne.',
                'nowa_decyzja' => ModerationAction::ACTION_WARN,
                'reason_code' => 'cudze-dane-osobowe',
                'user_message' => 'W opisie jest cudzy numer telefonu.',
            ])
            ->assertSessionHasNoErrors();
        auth()->logout();

        $nowa = ModerationAction::where('appeal_id', $odwolanie->getKey())->sole();
        $this->assertStringContainsString('wersji 1', (string) $nowa->user_message, 'Autor nie dowie się, której wersji dotyczy ostrzeżenie.');
    }

    #[Test]
    public function test_wersja_usunietego_przepisu_nie_przyjmuje_sankcji_po_cichu(): void
    {
        $przepis = $this->przepis();
        $this->zglos($this->user('widz'), $this->wersja($przepis, 1));
        $przepis->delete();

        $this->actingAs($this->moderator())
            ->post(route('admin.reports.decide', Report::sole()), [
                'action' => ModerationAction::ACTION_WARN,
                'reason_code' => 'cudze-dane-osobowe',
                'user_message' => 'W opisie jest cudzy numer telefonu.',
            ])
            ->assertSessionHasErrors('action');

        $this->assertSame(0, ModerationAction::count(), 'Ostrzeżenie zapisano wobec wersji, której autora nie da się ustalić.');
        $this->assertSame(Report::STATUS_OPEN, Report::sole()->status);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Domain\Recipes\Gotowanie\WersjaWykonania;
use App\Domain\Recipes\Historia\UkrywanieWersji;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookedEvent;
use App\Models\Media;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * „Gotuj z tej wersji” (#2491, V2, D-333 — paczka E): krokowy tryb z migawki
 * wersji przypiętej do WŁASNEGO wykonania i osobne zakończenie z jawnie
 * wskazaną wersją historyczną.
 *
 * Świat: przepis w wersji 1 ma dwa kroki (z minutnikiem w pierwszym), autor
 * zmienia go w wersję 2 (inne kroki, minutnik w drugim). Kucharz ugotował z v1.
 */
final class GotowanieZWersjiWykonaniaTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    private User $kucharz;

    private Recipe $przepis;

    private RecipeVersion $v1;

    private RecipeVersion $v2;

    private CookedEvent $proba;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autorka');
        $this->kucharz = $this->user('kucharz');
        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(), 'visibility' => 'public', 'status' => Recipe::STATUS_PUBLISHED, 'title' => 'Zupa Stara',
        ]);

        RecipeIngredient::create(['recipe_id' => $this->przepis->getKey(), 'ingredient_text' => '1 kg marchewki wersji pierwszej', 'position' => 0]);
        RecipeStep::create(['recipe_id' => $this->przepis->getKey(), 'position' => 0, 'instruction' => 'Obierz marchewkę wersji pierwszej.', 'timer_seconds' => 600]);
        RecipeStep::create(['recipe_id' => $this->przepis->getKey(), 'position' => 1, 'instruction' => 'Gotuj całość do miękkości.', 'timer_seconds' => null]);
        $this->v1 = app(SnapshotRecipeVersion::class)->handle($this->przepis->fresh(), $this->autor, 'start');

        $this->proba = app(RecordCookedEvent::class)->handle(cook: $this->kucharz, recipe: $this->przepis->fresh(), note: 'Pyszne.');

        $this->przepis->steps()->delete();
        $this->przepis->ingredients()->delete();
        RecipeIngredient::create(['recipe_id' => $this->przepis->getKey(), 'ingredient_text' => '2 kg selera wersji drugiej', 'position' => 0]);
        RecipeStep::create(['recipe_id' => $this->przepis->getKey(), 'position' => 0, 'instruction' => 'Zetrzyj seler wersji drugiej.', 'timer_seconds' => null]);
        RecipeStep::create(['recipe_id' => $this->przepis->getKey(), 'position' => 1, 'instruction' => 'Smaż całość.', 'timer_seconds' => 300]);
        $this->przepis->update(['title' => 'Zupa Nowa']);
        $this->v2 = app(SnapshotRecipeVersion::class)->handle($this->przepis->fresh(), $this->autor, 'poprawka');
    }

    public function test_kroki_skladniki_i_minutnik_pochodza_z_migawki_a_nie_z_dzisiejszego_przepisu(): void
    {
        $this->assertSame($this->v1->getKey(), $this->proba->recipe_version_id, 'Warunek wstępny: próba przypięta do v1.');

        $krok1 = (string) $this->actingAs($this->kucharz)->get(route('cooked.version.cook', $this->proba))->assertOk()->getContent();

        $this->assertStringContainsString('Obierz marchewkę wersji pierwszej.', $krok1);
        $this->assertStringContainsString('1 kg marchewki wersji pierwszej', $krok1);
        $this->assertStringNotContainsString('seler wersji drugiej', $krok1, 'GOTUJ_2491_KROKI_Z_MIGAWKI: pojawiła się treść dzisiejszej wersji.');
        $this->assertStringContainsString('Gotujesz według starszej wersji 1', $krok1);
        $this->assertStringContainsString('Autor mógł później zmienić przepis', $krok1);
        $this->assertStringContainsString('Krok 1 z 2', $krok1);
        $this->assertStringContainsString('data-timer-sekundy="600"', $krok1);
        $this->assertStringContainsString('data-timer-recipe="wersja-'.$this->proba->getKey().'"', $krok1);
        $this->assertStringContainsString(route('recipes.show', $this->przepis->slug), $krok1, 'Jest jednoznaczny powrót do dzisiejszego przepisu.');

        // Krok 2 w v1 nie ma minutnika — dzisiejszy krok 2 ma 300 s i NIE wolno go podstawić.
        $krok2 = (string) $this->actingAs($this->kucharz)->get(route('cooked.version.cook', ['cookedEvent' => $this->proba, 'krok' => 2]))->assertOk()->getContent();
        $this->assertStringContainsString('Gotuj całość do miękkości.', $krok2);
        $this->assertStringNotContainsString('class="cook-timer"', $krok2, 'GOTUJ_2491_MINUTNIK_Z_MIGAWKI: brakujący minutnik dopełniono dzisiejszym.');
        $this->assertStringNotContainsString('data-timer-sekundy="300"', $krok2);
        $this->assertStringContainsString(route('cooked.version.finish', $this->proba), $krok2, 'Ostatni krok prowadzi do zakończenia z wersją historyczną.');
    }

    public function test_numer_kroku_poza_zakresem_nie_psuje_ekranu(): void
    {
        foreach (['0', '99', 'abc', '-3'] as $krok) {
            $this->actingAs($this->kucharz)->get(route('cooked.version.cook', ['cookedEvent' => $this->proba, 'krok' => $krok]))->assertOk();
        }
    }

    public function test_wersja_ma_przycisk_gotuj_z_tej_wersji_a_zwykly_tryb_zostaje(): void
    {
        $ekran = (string) $this->actingAs($this->kucharz)->get(route('cooked.version', $this->proba))->assertOk()->getContent();

        $this->assertStringContainsString('Gotuj z tej wersji', $ekran);
        $this->assertStringContainsString(route('cooked.version.cook', $this->proba), $ekran);
        $this->assertStringContainsString('Zobacz dzisiejszy przepis', $ekran);

        $dzisiejszy = (string) $this->actingAs($this->kucharz)->get(route('cooking.show', $this->przepis->slug))->assertOk()->getContent();
        $this->assertStringContainsString('Zetrzyj seler wersji drugiej.', $dzisiejszy);
        $this->assertStringNotContainsString('marchewkę wersji pierwszej', $dzisiejszy);
    }

    public function test_obcy_autor_i_gosc_nie_wchodza_a_stare_rekordy_bez_wskaznika_nie_zgaduja_wersji(): void
    {
        foreach ([$this->user('obca'), $this->autor] as $ktos) {
            $this->actingAs($ktos)->get(route('cooked.version.cook', $this->proba))->assertNotFound();
            $this->actingAs($ktos)->post(route('cooked.version.cook.mark', $this->proba), ['krok' => 1, 'zrobiono' => 1])->assertNotFound();
            $this->actingAs($ktos)->get(route('cooked.version.finish', $this->proba))->assertNotFound();
        }

        auth()->logout();
        $this->get(route('cooked.version.cook', $this->proba))->assertRedirect(route('login'));

        CookedEvent::query()->whereKey($this->proba->getKey())->update(['recipe_version_id' => null]);
        $html = (string) $this->actingAs($this->kucharz)->get(route('cooked.version.cook', $this->proba))->assertOk()->getContent();
        $this->assertStringContainsString('Tej wersji przepisu nie możemy Ci już pokazać', $html);
        $this->assertStringNotContainsString('wersji pierwszej', $html);
        $this->assertStringNotContainsString('wersji drugiej', $html);
    }

    public function test_postep_wersji_jest_osobny_od_postepu_biezacego_przepisu(): void
    {
        $this->actingAs($this->kucharz)->post(route('cooked.version.cook.mark', $this->proba), ['krok' => 1, 'zrobiono' => 1])
            ->assertRedirect(route('cooked.version.cook', ['cookedEvent' => $this->proba, 'krok' => 1]));

        $html = (string) $this->actingAs($this->kucharz)->get(route('cooked.version.cook', $this->proba))->getContent();
        $this->assertStringContainsString('Zrobione ✓', $html);

        $this->assertTrue(session()->has('gotowanie_wersja'), 'Postęp wersji historycznej zapisany w sesji.');
        $this->assertFalse(session()->has('gotowanie'), 'GOTUJ_2491_POSTEP_IZOLOWANY: ruszono postęp bieżącego gotowania.');

        // Bieżący tryb gotowania nie widzi odhaczeń z wersji historycznej.
        $dzis = (string) $this->actingAs($this->kucharz)->get(route('cooking.show', $this->przepis->slug))->getContent();
        $this->assertStringNotContainsString('Zrobione ✓', $dzis);

        // Restart usuwa tylko odhaczenia tej wersji.
        $this->actingAs($this->kucharz)->post(route('cooked.version.cook.restart', $this->proba))->assertRedirect();
        $this->assertStringNotContainsString('Zrobione ✓', (string) $this->actingAs($this->kucharz)->get(route('cooked.version.cook', $this->proba))->getContent());
    }

    public function test_krok_spoza_migawki_nie_zapisuje_postepu(): void
    {
        $this->actingAs($this->kucharz)->post(route('cooked.version.cook.mark', $this->proba), ['krok' => 9, 'zrobiono' => 1])
            ->assertRedirect(route('cooked.version.cook', $this->proba))->assertSessionHas('status_rodzaj', 'blad');
        $this->assertFalse(session()->has('gotowanie_wersja'));
    }

    public function test_wersja_bez_krokow_daje_instrukcje_zamiast_martwej_akcji(): void
    {
        $pusty = Recipe::factory()->create(['author_id' => $this->autor->getKey(), 'visibility' => 'public', 'status' => Recipe::STATUS_PUBLISHED]);
        app(SnapshotRecipeVersion::class)->handle($pusty->fresh(), $this->autor, 'bez kroków');
        $wykonanie = app(RecordCookedEvent::class)->handle(cook: $this->kucharz, recipe: $pusty->fresh());

        $html = (string) $this->actingAs($this->kucharz)->get(route('cooked.version.cook', $wykonanie))->assertOk()->getContent();

        $this->assertStringContainsString('Ta wersja nie ma opisanego przygotowania', $html);
        $this->assertStringNotContainsString('Oznacz krok jako zrobiony', $html);
        $this->assertStringNotContainsString('class="cook-timer"', $html);
    }

    public function test_wersja_usunieta_retencja_albo_przepis_prywatny_zamykaja_ekran_zapis_i_zakonczenie(): void
    {
        $this->przepis->forceFill(['visibility' => 'private'])->save();
        $this->assertStringContainsString('Tej wersji przepisu nie możemy Ci już pokazać', (string) $this->actingAs($this->kucharz)->get(route('cooked.version.cook', $this->proba))->getContent());
        $this->actingAs($this->kucharz)->post(route('cooked.version.cook.mark', $this->proba), ['krok' => 1, 'zrobiono' => 1])
            ->assertRedirect(route('cooked.version.cook', $this->proba))->assertSessionHas('status_rodzaj', 'blad');
        $this->przepis->forceFill(['visibility' => 'public'])->save();

        $this->v1->delete();
        $this->assertStringContainsString('Tej wersji przepisu nie możemy Ci już pokazać', (string) $this->actingAs($this->kucharz)->get(route('cooked.version.cook', $this->proba))->getContent());
        $this->actingAs($this->kucharz)->get(route('cooked.version.finish', $this->proba))
            ->assertRedirect(route('cooked.version', $this->proba))->assertSessionHas('status_rodzaj', 'blad');
    }

    public function test_zakonczenie_tworzy_nowe_wykonanie_z_wersja_historyczna_i_powiadamia_autora(): void
    {
        $formularz = (string) $this->actingAs($this->kucharz)->get(route('cooked.version.finish', $this->proba))->assertOk()->getContent();
        $this->assertStringContainsString('name="z_proby" value="'.$this->proba->getKey().'"', $formularz);
        $this->assertStringContainsString('Zapisujesz gotowanie według starszej wersji przepisu', $formularz);
        $this->assertStringContainsString('name="wersja_przepisu" value="'.$this->v1->getKey().'"', $formularz);

        $this->actingAs($this->kucharz)->post(route('cooked.store', $this->przepis->slug), [
            'z_proby' => (string) $this->proba->getKey(),
            'klucz_wyslania' => '0192f1a0-0000-7000-8000-000000000001',
            'note' => 'Znowu z wersji pierwszej',
        ])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(2, CookedEvent::query()->count());
        $nowe = CookedEvent::query()->where('note', 'Znowu z wersji pierwszej')->sole();
        $this->assertSame($this->v1->getKey(), $nowe->recipe_version_id, 'GOTUJ_2491_ZAPIS_WERSJI: nowe wykonanie nie dostało wersji historycznej.');
        $this->assertSame($this->v1->getKey(), $this->proba->fresh()?->recipe_version_id, 'Poprzednie wykonanie zostaje bez zmian.');
        $this->assertSame(1, Notification::query()->where('type', Notification::TYPE_COOKED)->where('user_id', $this->autor->getKey())->count() - 1, 'Autor dostał powiadomienie o nowym gotowaniu.');

        // Ponowienie tego samego wysłania nie tworzy trzeciego wykonania.
        $this->actingAs($this->kucharz)->post(route('cooked.store', $this->przepis->slug), [
            'z_proby' => (string) $this->proba->getKey(),
            'klucz_wyslania' => '0192f1a0-0000-7000-8000-000000000001',
            'note' => 'Znowu z wersji pierwszej',
        ])->assertRedirect();
        $this->assertSame(2, CookedEvent::query()->count());
    }

    public function test_zwykla_sciezka_nadal_przypina_wersje_z_otwarcia_formularza(): void
    {
        $html = (string) $this->actingAs($this->kucharz)->get(route('cooked.create', $this->przepis->slug))->getContent();
        $this->assertStringContainsString('name="wersja_przepisu" value="'.$this->v2->getKey().'"', $html);
        $this->assertStringNotContainsString('name="z_proby"', $html);

        $this->actingAs($this->kucharz)->post(route('cooked.store', $this->przepis->slug), ['wersja_przepisu' => (string) $this->v2->getKey(), 'note' => 'Zwykłe'])->assertRedirect();
        $this->assertSame($this->v2->getKey(), CookedEvent::query()->where('note', 'Zwykłe')->sole()->recipe_version_id);
    }

    public function test_cudza_proba_proba_innego_przepisu_i_wersja_usunieta_po_otwarciu_formularza_to_odmowa_bez_podmiany(): void
    {
        $obca = $this->user('obca');
        $cudzaProba = app(RecordCookedEvent::class)->handle(cook: $obca, recipe: $this->przepis->fresh());
        $innyPrzepis = Recipe::factory()->create(['author_id' => $this->autor->getKey(), 'visibility' => 'public', 'status' => Recipe::STATUS_PUBLISHED]);
        app(SnapshotRecipeVersion::class)->handle($innyPrzepis->fresh(), $this->autor, 'inny');
        $probaInnego = app(RecordCookedEvent::class)->handle(cook: $this->kucharz, recipe: $innyPrzepis->fresh());
        $przed = CookedEvent::query()->count();

        foreach ([[$cudzaProba, $this->przepis], [$probaInnego, $this->przepis], [$this->proba, $innyPrzepis]] as [$proba, $przepis]) {
            $odpowiedz = $this->actingAs($this->kucharz)->post(route('cooked.store', $przepis->slug), ['z_proby' => (string) $proba->getKey(), 'note' => 'Podmiana']);
            $odpowiedz->assertRedirect()->assertSessionHas('status_rodzaj', 'blad');
            $this->assertSame($przed, CookedEvent::query()->count(), 'GOTUJ_2491_ZAPIS_WERSJI: zapisano wykonanie z cudzą albo niezgodną próbą.');
        }
        $this->actingAs($this->kucharz)->post(route('cooked.store', $this->przepis->slug), ['z_proby' => 'nie-uuid', 'note' => 'Podmiana'])
            ->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame($przed, CookedEvent::query()->count());

        // Wersja zniknęła (retencja) PO otwarciu formularza: odmowa, nie ciche przypięcie v2.
        $this->v1->delete();
        $this->actingAs($this->kucharz)->post(route('cooked.store', $this->przepis->slug), ['z_proby' => (string) $this->proba->getKey(), 'note' => 'Po retencji'])
            ->assertRedirect(route('cooked.version', $this->proba))->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame(0, CookedEvent::query()->where('note', 'Po retencji')->count());
    }

    public function test_wersja_zniknieta_miedzy_kontrola_a_zapisem_nie_przypina_dzisiejszej(): void
    {
        // Akcja domenowa wprost, ze ścisłą wersją, której już nie ma (wyścig z retencją).
        $this->v1->delete();

        $this->expectException(BladDlaCzlowieka::class);
        try {
            app(RecordCookedEvent::class)->handle(
                cook: $this->kucharz, recipe: $this->przepis->fresh(), note: 'Wyścig',
                wersjaPrzepisuId: (string) $this->v1->getKey(), wersjaScisla: true,
            );
        } finally {
            $this->assertSame(0, CookedEvent::query()->where('note', 'Wyścig')->count(), 'GOTUJ_2491_ZAPIS_WERSJI: ścisła wersja ustąpiła dzisiejszej.');
        }
    }

    public function test_ukrycie_wersji_po_wstepnej_kontroli_odmawia_scislego_zapisu_bez_zdjecia_i_powiadomienia(): void
    {
        $this->assertSame($this->v1->getKey(), WersjaWykonania::dla($this->proba, $this->kucharz)?->getKey());
        $zdjecie = Media::factory()->create(['owner_id' => $this->kucharz->getKey()]);
        $przed = CookedEvent::query()->count();
        $powiadomienia = Notification::query()->where('type', Notification::TYPE_COOKED)->count();

        $this->assertSame(UkrywanieWersji::UKRYTO, app(UkrywanieWersji::class)->ukryj($this->autor, $this->przepis, 1));
        $this->assertNotNull($this->v1->fresh()?->hidden_at);
        $this->assertNull(WersjaWykonania::dla($this->proba, $this->kucharz));

        try {
            app(RecordCookedEvent::class)->handle(
                cook: $this->kucharz, recipe: $this->przepis->fresh(), note: 'Po ukryciu',
                mediaIds: [(string) $zdjecie->getKey()],
                wersjaPrzepisuId: (string) $this->v1->getKey(), wersjaScisla: true,
            );
            $this->fail('GOTUJ_2808_UKRYTA_WERSJA: ukryta wersja nie może dostać nowego wykonania.');
        } catch (BladDlaCzlowieka) {
            $this->assertSame($przed, CookedEvent::query()->count(), 'GOTUJ_2808_UKRYTA_WERSJA: zapisano wykonanie ukrytej wersji.');
            $this->assertSame($powiadomienia, Notification::query()->where('type', Notification::TYPE_COOKED)->count());
            $this->assertSame(0, DB::table('cooked_event_media')->where('media_id', $zdjecie->getKey())->count());
        }
    }

    public function test_autor_i_moderator_moga_scisle_zapisac_wlasne_wykonanie_z_ukryta_wersja(): void
    {
        $this->assertSame(UkrywanieWersji::UKRYTO, app(UkrywanieWersji::class)->ukryj($this->autor, $this->przepis, 1));

        foreach ([$this->autor, $this->moderator()] as $widz) {
            $wykonanie = app(RecordCookedEvent::class)->handle(
                cook: $widz, recipe: $this->przepis->fresh(),
                wersjaPrzepisuId: (string) $this->v1->getKey(), wersjaScisla: true,
            );
            $this->assertSame($this->v1->getKey(), $wykonanie->recipe_version_id);
        }
    }

    public function test_zwykly_zapis_zachowuje_dotychczasowe_przypiecie_ukrytej_wersji(): void
    {
        $this->assertSame(UkrywanieWersji::UKRYTO, app(UkrywanieWersji::class)->ukryj($this->autor, $this->przepis, 1));

        $wykonanie = app(RecordCookedEvent::class)->handle(
            cook: $this->kucharz, recipe: $this->przepis->fresh(),
            wersjaPrzepisuId: (string) $this->v1->getKey(),
        );
        $this->assertSame($this->v1->getKey(), $wykonanie->recipe_version_id);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\PostepGotowania;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\CookingProgress;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Opcjonalna synchronizacja postępu gotowania między urządzeniami (#2016).
 *
 * „Drugie urządzenie” to w tych testach `flushSession()`: to samo konto,
 * pusta sesja przeglądarki. Kontrola ujemna prywatności to testy z „obcą
 * osobą” — jej wejście nie może pokazać ani zmienić cudzego wiersza.
 */
class SynchronizacjaPostepuGotowaniaTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /** @return array{0: Recipe, 1: list<RecipeStep>} */
    private function przepis(?User $autor = null, string $widocznosc = 'public'): array
    {
        $recipe = Recipe::factory()->create([
            'author_id' => ($autor ?? $this->user())->getKey(),
            'visibility' => $widocznosc,
        ]);
        $kroki = [];
        foreach ([0, 1, 2] as $i) {
            $kroki[] = RecipeStep::create([
                'recipe_id' => $recipe->getKey(),
                'position' => $i,
                'instruction' => 'Krok numer '.($i + 1).'.',
            ]);
        }

        return [$recipe, $kroki];
    }

    private function odhacz(User $osoba, Recipe $recipe, RecipeStep $krok, bool $zrobiono = true, array $dodatkowe = [])
    {
        return $this->actingAs($osoba)->post(route('cooking.zaznacz', $recipe->slug), [
            'krok' => $krok->position + 1,
            'krok_id' => $krok->getKey(),
            'zrobiono' => $zrobiono ? '1' : '0',
        ] + $dodatkowe);
    }

    private function wlacz(User $osoba, Recipe $recipe)
    {
        return $this->actingAs($osoba)->post(route('cooking.sync.wlacz', $recipe->slug), ['krok' => 1]);
    }

    private function postep(User $osoba, Recipe $recipe): ?CookingProgress
    {
        return CookingProgress::query()->where('user_id', $osoba->getKey())->where('recipe_id', $recipe->getKey())->first();
    }

    public function test_domyslnie_postep_zostaje_w_sesji_i_nic_nie_trafia_do_bazy(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis();

        $this->odhacz($osoba, $recipe, $kroki[0])->assertRedirect();

        $this->assertSame(0, CookingProgress::query()->count());
        $this->assertSame([$kroki[0]->getKey()], session('gotowanie.'.$recipe->getKey().'.zrobione'));
        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertSee('Zapamiętuj postęp na moim koncie')
            ->assertDontSee('data-postep-synchronizacja', false);
    }

    public function test_gosc_zostaje_przy_sesji_i_nie_widzi_ani_nie_wlaczy_synchronizacji(): void
    {
        [$recipe, $kroki] = $this->przepis();

        $this->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertDontSee('Zapamiętuj postęp na moim koncie');
        $this->post(route('cooking.sync.wlacz', $recipe->slug))->assertRedirect(route('login'));
        $this->post(route('cooking.zaznacz', $recipe->slug), [
            'krok_id' => $kroki[0]->getKey(), 'zrobiono' => '1',
        ])->assertRedirect();

        $this->assertSame(0, CookingProgress::query()->count());
        $this->assertSame([$kroki[0]->getKey()], session('gotowanie.'.$recipe->getKey().'.zrobione'));
    }

    public function test_wlaczenie_bierze_odhaczenia_z_sesji_a_drugie_urzadzenie_je_widzi(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis();

        $this->odhacz($osoba, $recipe, $kroki[0]);
        $this->wlacz($osoba, $recipe)->assertRedirect()->assertSessionHas('status_rodzaj', 'sukces');

        $wiersz = $this->postep($osoba, $recipe);
        $this->assertSame([$kroki[0]->getKey()], $wiersz->done_step_ids);

        $this->odhacz($osoba, $recipe, $kroki[1]);
        $this->assertEqualsCanonicalizing(
            [$kroki[0]->getKey(), $kroki[1]->getKey()],
            $this->postep($osoba, $recipe)->done_step_ids,
        );

        // Drugie urządzenie: to samo konto, zupełnie pusta sesja.
        $this->flushSession();
        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug).'?krok=2')
            ->assertOk()
            ->assertSee('Zrobione ✓ — kliknij, żeby cofnąć')
            ->assertSee('data-postep-synchronizacja', false)
            ->assertSee('Wyłącz zapamiętywanie na koncie i usuń zapis');
    }

    public function test_pas_zmiany_z_innego_urzadzenia_jest_domyslnie_ukryty(): void
    {
        $osoba = $this->user();
        [$recipe] = $this->przepis();
        $this->wlacz($osoba, $recipe);

        $html = $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))->assertOk()->getContent();

        // Odkrywa go wyłącznie skrypt; bez niego żaden martwy przycisk (D-053).
        $this->assertMatchesRegularExpression('/<div class="cook-sync-zmiana[^>]*\bhidden\b[^>]*data-postep-synchronizacja/s', $html);
        $this->assertStringContainsString('data-postep-rewizja="1"', $html);
    }

    public function test_kazda_zmiana_podbija_rewizje_a_drugie_urzadzenie_z_ta_sama_rewizja_dostaje_komunikat(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis();
        $this->wlacz($osoba, $recipe);
        $this->assertSame(1, $this->postep($osoba, $recipe)->revision);

        $this->odhacz($osoba, $recipe, $kroki[0], true, ['rewizja' => 1])
            ->assertSessionMissing('status');
        $this->assertSame(2, $this->postep($osoba, $recipe)->revision);

        // Drugie urządzenie klika, wciąż widząc rewizję 1: konflikt zgłoszony, zapis przyjęty.
        $this->odhacz($osoba, $recipe, $kroki[1], true, ['rewizja' => 1])
            ->assertSessionHas('status_rodzaj', 'informacja')
            ->assertSessionHas('status', fn (string $tresc): bool => str_contains($tresc, 'innym urządzeniu'));
        $wiersz = $this->postep($osoba, $recipe);
        $this->assertSame(3, $wiersz->revision);
        $this->assertEqualsCanonicalizing([$kroki[0]->getKey(), $kroki[1]->getKey()], $wiersz->done_step_ids);
    }

    public function test_dwa_urzadzenia_na_ten_sam_krok_ostatni_zapis_wygrywa(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis();
        $this->wlacz($osoba, $recipe);

        $this->odhacz($osoba, $recipe, $kroki[0], true, ['rewizja' => 1]);
        $this->odhacz($osoba, $recipe, $kroki[0], false, ['rewizja' => 1]);

        $this->assertSame([], $this->postep($osoba, $recipe)->done_step_ids);
    }

    public function test_pytanie_skryptu_o_rewizje_zwraca_tylko_numer(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis();

        $this->actingAs($osoba)->getJson(route('cooking.sync.postep', $recipe->slug))
            ->assertOk()->assertExactJson(['aktywna' => false, 'rewizja' => null]);

        $this->wlacz($osoba, $recipe);
        $this->odhacz($osoba, $recipe, $kroki[0]);

        $this->actingAs($osoba)->getJson(route('cooking.sync.postep', $recipe->slug))
            ->assertOk()
            ->assertExactJson(['aktywna' => true, 'rewizja' => 2])
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_zacznij_od_poczatku_czysci_zapis_ale_zostawia_synchronizacje(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis();
        $this->wlacz($osoba, $recipe);
        $this->odhacz($osoba, $recipe, $kroki[0]);

        $this->actingAs($osoba)->post(route('cooking.restart', $recipe->slug))->assertRedirect();

        $wiersz = $this->postep($osoba, $recipe);
        $this->assertNotNull($wiersz);
        $this->assertSame([], $wiersz->done_step_ids);
    }

    public function test_wylaczenie_kasuje_zapis_z_konta_a_odhaczenia_zostaja_w_sesji_urzadzenia(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis();
        $this->wlacz($osoba, $recipe);
        $this->odhacz($osoba, $recipe, $kroki[0]);

        $this->actingAs($osoba)->post(route('cooking.sync.wylacz', $recipe->slug), ['krok' => 1])->assertRedirect();

        $this->assertNull($this->postep($osoba, $recipe));
        $this->assertSame([$kroki[0]->getKey()], session('gotowanie.'.$recipe->getKey().'.zrobione'));

        // Drugie urządzenie znów zaczyna od zera, jak zawsze bez synchronizacji.
        $this->flushSession();
        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))
            ->assertOk()->assertSee('Oznacz krok jako zrobiony');
    }

    public function test_wygasly_postep_jest_niewidoczny_i_zostaje_skasowany_przez_sprzatanie(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis();
        $this->wlacz($osoba, $recipe);
        $this->odhacz($osoba, $recipe, $kroki[0]);
        $this->assertSame(1, CookingProgress::query()->count());

        // Doba bez zmian + chwila: wiersz wygasł.
        Carbon::setTestNow(now()->addHours(25));
        $this->flushSession();

        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertSee('Oznacz krok jako zrobiony')
            ->assertSee('Zapamiętuj postęp na moim koncie');
        $this->actingAs($osoba)->getJson(route('cooking.sync.postep', $recipe->slug))
            ->assertExactJson(['aktywna' => false, 'rewizja' => null]);

        $this->artisan('kuking:sprzataj-postep-gotowania', ['--na-sucho' => true])
            ->expectsOutputToContain('Do skasowania: 1 ')->assertSuccessful();
        $this->assertSame(1, CookingProgress::query()->count(), 'Na sucho niczego nie kasuje.');

        $this->artisan('kuking:sprzataj-postep-gotowania')->expectsOutputToContain('Skasowano 1 ')->assertSuccessful();
        $this->assertSame(0, CookingProgress::query()->count());
    }

    public function test_sprzatanie_nie_rusza_niewygaslego_postepu(): void
    {
        $osoba = $this->user();
        [$recipe] = $this->przepis();
        $this->wlacz($osoba, $recipe);

        $this->artisan('kuking:sprzataj-postep-gotowania')->expectsOutputToContain('Skasowano 0 ')->assertSuccessful();

        $this->assertSame(1, CookingProgress::query()->count());
    }

    public function test_kazda_zmiana_przedluza_wazny_zapis(): void
    {
        config(['kuking.cooking_progress.retention_hours' => 2]);
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis();
        $this->wlacz($osoba, $recipe);

        Carbon::setTestNow(now()->addHour());
        $this->odhacz($osoba, $recipe, $kroki[0]);
        Carbon::setTestNow(now()->addHours(2)->subMinute());

        // Od pierwszego zapisu minęły ponad 2 godziny, od ostatniej zmiany — mniej.
        $this->assertNotNull(app(PostepGotowania::class)->aktywny($osoba, $recipe));
    }

    public function test_identyfikatory_usunietych_krokow_nie_wracaja_a_obcy_krok_nic_nie_zapisuje(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis();
        [, $obceKroki] = $this->przepis();
        $this->wlacz($osoba, $recipe);
        $this->odhacz($osoba, $recipe, $kroki[0]);
        $this->odhacz($osoba, $recipe, $kroki[1]);

        // Autor usuwa krok 2 — jego identyfikator znika z odczytu.
        $kroki[1]->delete();
        $wiersz = $this->postep($osoba, $recipe);
        $krokiPrzepisu = $recipe->steps()->pluck('id')->all();
        $this->assertSame([$kroki[0]->getKey()], app(PostepGotowania::class)->zrobione($wiersz, $krokiPrzepisu));

        // Krok z INNEGO przepisu nie zapisuje się w tym postępie.
        $this->odhacz($osoba, $recipe, $obceKroki[0])->assertSessionHas('status_rodzaj', 'blad');
        $this->assertNotContains($obceKroki[0]->getKey(), $this->postep($osoba, $recipe)->done_step_ids);

        // Następny zapis pozbywa się martwego identyfikatora z bazy.
        $this->odhacz($osoba, $recipe, $kroki[2]);
        $this->assertEqualsCanonicalizing(
            [$kroki[0]->getKey(), $kroki[2]->getKey()],
            $this->postep($osoba, $recipe)->done_step_ids,
        );
    }

    // --- KONTROLA UJEMNA: cudzy postęp jest niedostępny -------------------------

    public function test_obca_osoba_nie_widzi_ani_nie_zmienia_cudzego_postepu(): void
    {
        $wlascicielka = $this->user();
        $obca = $this->user();
        [$recipe, $kroki] = $this->przepis();
        $this->wlacz($wlascicielka, $recipe);
        $this->odhacz($wlascicielka, $recipe, $kroki[0]);
        $rewizja = $this->postep($wlascicielka, $recipe)->revision;

        // Widok: obca osoba ma czysty ekran i propozycję włączenia, nie cudzy stan.
        $this->flushSession();
        $this->actingAs($obca)->get(route('cooking.show', $recipe->slug))
            ->assertOk()
            ->assertSee('Oznacz krok jako zrobiony')
            ->assertDontSee('Zrobione ✓')
            ->assertDontSee('data-postep-synchronizacja', false);
        $this->actingAs($obca)->getJson(route('cooking.sync.postep', $recipe->slug))
            ->assertExactJson(['aktywna' => false, 'rewizja' => null]);

        // Zapis, wyłączenie i „od początku” obcej osoby dotyczą jej własnej sesji, nie cudzego wiersza.
        $this->odhacz($obca, $recipe, $kroki[1]);
        $this->actingAs($obca)->post(route('cooking.restart', $recipe->slug));
        $this->actingAs($obca)->post(route('cooking.sync.wylacz', $recipe->slug));

        $wiersz = $this->postep($wlascicielka, $recipe);
        $this->assertNotNull($wiersz, 'Wyłączenie obcej osoby skasowało cudzy zapis.');
        $this->assertSame($rewizja, $wiersz->revision);
        $this->assertSame([$kroki[0]->getKey()], $wiersz->done_step_ids);
        $this->assertNull($this->postep($obca, $recipe));
    }

    public function test_policy_wiersza_zna_tylko_wlasciciela_nawet_moderatora(): void
    {
        $wlascicielka = $this->user();
        $obca = $this->user();
        $moderator = $this->moderator();
        [$recipe] = $this->przepis();
        $wiersz = app(PostepGotowania::class)->wlacz($wlascicielka, $recipe, [], []);

        foreach (['view', 'update', 'delete'] as $akcja) {
            $this->assertTrue(Gate::forUser($wlascicielka)->allows($akcja, $wiersz), "właściciel: {$akcja}");
            $this->assertFalse(Gate::forUser($obca)->allows($akcja, $wiersz), "obca osoba: {$akcja}");
            $this->assertFalse(Gate::forUser($moderator)->allows($akcja, $wiersz), "moderator: {$akcja}");
        }
    }

    public function test_konto_ktore_stracilo_dostep_do_przepisu_nie_odtworzy_postepu(): void
    {
        $autor = $this->user();
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis($autor);
        $this->wlacz($osoba, $recipe);
        $this->odhacz($osoba, $recipe, $kroki[0]);

        // Autor chowa przepis.
        $recipe->forceFill(['visibility' => 'private'])->save();

        $this->actingAs($osoba)->get(route('cooking.show', $recipe->slug))->assertForbidden();
        $this->actingAs($osoba)->getJson(route('cooking.sync.postep', $recipe->slug))->assertForbidden();
        $this->odhacz($osoba, $recipe, $kroki[1])->assertForbidden();
        $this->assertSame([$kroki[0]->getKey()], $this->postep($osoba, $recipe)->done_step_ids);
    }

    public function test_synchronizacji_nie_wlaczysz_na_prywatnym_cudzym_przepisie(): void
    {
        $osoba = $this->user();
        [$recipe] = $this->przepis(null, 'private');

        $this->wlacz($osoba, $recipe)->assertForbidden();

        $this->assertSame(0, CookingProgress::query()->count());
    }

    public function test_zawieszone_konto_czyta_ale_nie_wlacza_synchronizacji(): void
    {
        $zawieszona = $this->user(null, ['status' => User::STATUS_SUSPENDED]);
        [$recipe] = $this->przepis();

        $this->actingAs($zawieszona)->get(route('cooking.show', $recipe->slug))
            ->assertOk()->assertDontSee('Zapamiętuj postęp na moim koncie');
        $this->wlacz($zawieszona, $recipe)->assertRedirect(); // middleware zawieszenia zatrzymuje zapis przed kontrolerem
        $this->assertSame(0, CookingProgress::query()->count());
    }

    public function test_dwukrotne_wlaczenie_nie_kasuje_zapisu_ani_nie_dubluje_wiersza(): void
    {
        $osoba = $this->user();
        [$recipe, $kroki] = $this->przepis();
        $this->wlacz($osoba, $recipe);
        $this->odhacz($osoba, $recipe, $kroki[0]);

        $this->flushSession();
        $this->wlacz($osoba, $recipe)->assertRedirect();

        $this->assertSame(1, CookingProgress::query()->count());
        $this->assertSame([$kroki[0]->getKey()], $this->postep($osoba, $recipe)->done_step_ids);
    }

    // --- Eksport i wymazanie --------------------------------------------------

    public function test_paczka_danych_zawiera_postep_gotowania_bez_cudzego(): void
    {
        $osoba = $this->user();
        $inna = $this->user();
        [$recipe, $kroki] = $this->przepis();
        $this->wlacz($osoba, $recipe);
        $this->odhacz($osoba, $recipe, $kroki[1]);
        $this->wlacz($inna, $recipe);
        $this->odhacz($inna, $recipe, $kroki[2]);

        $dane = app(CollectUserExportData::class)->handle($osoba, new ExportPhotoPlan($osoba), Carbon::now());

        $this->assertCount(1, $dane['postep_gotowania']);
        $this->assertSame($recipe->title, $dane['postep_gotowania'][0]['przepis']);
        $this->assertSame([2], $dane['postep_gotowania'][0]['odhaczone_kroki']);
    }

    public function test_paczka_nie_zdradza_tytulu_przepisu_niedostepnego_juz_dla_osoby(): void
    {
        $autor = $this->user();
        $osoba = $this->user();
        [$recipe] = $this->przepis($autor);
        $this->wlacz($osoba, $recipe);
        $recipe->forceFill(['visibility' => 'private'])->save();

        $dane = app(CollectUserExportData::class)->handle($osoba, new ExportPhotoPlan($osoba), Carbon::now());

        $this->assertSame(CollectUserExportData::TRESC_NIEDOSTEPNA, $dane['postep_gotowania'][0]['przepis']);
    }

    public function test_postep_znika_przy_wymazaniu_konta_a_cudzy_zostaje(): void
    {
        $osoba = $this->user();
        $inna = $this->user();
        [$recipe] = $this->przepis();
        $this->wlacz($osoba, $recipe);
        $this->wlacz($inna, $recipe);

        $osoba->markForDeletion();
        app(EraseAccountData::class)->handle($osoba->fresh());

        $this->assertNull($this->postep($osoba, $recipe));
        $this->assertNotNull($this->postep($inna, $recipe));
    }
}

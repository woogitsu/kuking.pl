<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\Wspolne\SesjaWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\ZaproszenieDoGotowania;
use App\Models\CookingSession;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Wspólne gotowanie (#2385): droga powrotna uczestnika, wyjście z ekranu
 * „przepis niedostępny”, koniec sesji widoczny dla skryptu i gospodarz z
 * zamkniętym kontem. Poprawki znalezisk recenzji PR #2415.
 *
 * Każdy test ma kontrolę dodatnią tej samej drogi (osoba z dostępem), więc
 * zielony wynik nie bierze się stąd, że ścieżka jest martwa.
 */
class WspolneGotowanieDrogaPowrotnaTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    /** @return array{0: User, 1: User, 2: Recipe, 3: CookingSession, 4: string} gospodarz, pomocnik, przepis, sesja, token */
    private function sesjaZPomocnikiem(): array
    {
        $gospodarz = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $gospodarz->getKey(), 'visibility' => 'public']);
        foreach ([0, 1] as $i) {
            RecipeStep::create(['recipe_id' => $recipe->getKey(), 'position' => $i, 'instruction' => 'Krok numer '.($i + 1).'.']);
        }
        $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $recipe);
        $token = app(ZaproszenieDoGotowania::class)->utworz($gospodarz, $sesja)[1];
        $pomocnik = $this->user();
        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $token))->assertRedirect(route('wspolne-gotowanie.show', $sesja));

        return [$gospodarz, $pomocnik, $recipe, $sesja->refresh(), $token];
    }

    // ---------------------------------------------------------------------
    // 1. Droga powrotna po zamknięciu karty
    // ---------------------------------------------------------------------

    public function test_pomocnik_otwierajacy_przyjety_link_trafia_do_sesji_takze_gdy_link_wygasl(): void
    {
        [, $pomocnik, , $sesja, $token] = $this->sesjaZPomocnikiem();
        $obserwacje = [];

        $odp = $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.link.show', $token));
        $obserwacje['żywy link'] = $odp->headers->get('Location');

        DB::table('cooking_session_invitations')->update(['expires_at' => now()->subMinute()]);
        $odp = $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.link.show', $token));
        $obserwacje['wygasły link'] = $odp->headers->get('Location');

        $this->assertSame([
            'żywy link' => route('wspolne-gotowanie.show', $sesja),
            'wygasły link' => route('wspolne-gotowanie.show', $sesja),
        ], $obserwacje);
    }

    public function test_wygasly_link_nie_wpuszcza_obcego_a_pomocnik_dalej_ma_droge_przez_post(): void
    {
        [, $pomocnik, , $sesja, $token] = $this->sesjaZPomocnikiem();
        DB::table('cooking_session_invitations')->update(['expires_at' => now()->subMinute()]);
        $obcy = $this->user();

        $this->actingAs($obcy)->get(route('wspolne-gotowanie.link.show', $token))->assertStatus(410);
        $this->actingAs($obcy)->post(route('wspolne-gotowanie.link.accept', $token))->assertStatus(410);
        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $token))->assertRedirect(route('wspolne-gotowanie.show', $sesja));

        $this->assertSame(1, DB::table('cooking_session_participants')->count(), 'obcy nie wszedł');
    }

    public function test_start_pokazuje_wiersz_gotujesz_razem_gospodarzowi_i_pomocnikowi_ale_nie_obcemu(): void
    {
        [$gospodarz, $pomocnik, $recipe, $sesja] = $this->sesjaZPomocnikiem();
        $obcy = $this->user();
        $wiersz = 'Gotujesz razem: '.e($recipe->title).' — wróć';
        $obserwacje = [];

        foreach (['gospodarz' => $gospodarz, 'pomocnik' => $pomocnik, 'obcy' => $obcy] as $nazwa => $osoba) {
            $tresc = $this->actingAs($osoba)->get(route('home'))->assertOk()->getContent();
            $obserwacje[$nazwa] = str_contains($tresc, $wiersz) && str_contains($tresc, route('wspolne-gotowanie.show', $sesja));
        }

        $this->assertSame(['gospodarz' => true, 'pomocnik' => true, 'obcy' => false], $obserwacje);
    }

    public function test_start_nie_zdradza_tytulu_sesji_ktorej_przepisu_osoba_juz_nie_widzi_ani_wygaslej(): void
    {
        [, $pomocnik, $recipe, $sesja] = $this->sesjaZPomocnikiem();
        $wiersz = 'Gotujesz razem: '.e($recipe->title);
        $obserwacje = ['przepis publiczny' => str_contains($this->actingAs($pomocnik)->get(route('home'))->getContent(), $wiersz)];

        $recipe->forceFill(['visibility' => 'private'])->save();
        $obserwacje['przepis prywatny'] = str_contains($this->actingAs($pomocnik)->get(route('home'))->getContent(), $wiersz);

        $recipe->forceFill(['visibility' => 'public'])->save();
        DB::table('cooking_sessions')->update(['created_at' => now()->subDays(2), 'expires_at' => now()->subMinute()]);
        $obserwacje['sesja wygasła'] = str_contains($this->actingAs($pomocnik)->get(route('home'))->getContent(), $wiersz);

        $this->assertSame(['przepis publiczny' => true, 'przepis prywatny' => false, 'sesja wygasła' => false], $obserwacje);
    }

    // ---------------------------------------------------------------------
    // 2. Ekran „przepis niedostępny” dotrzymuje obietnicy
    // ---------------------------------------------------------------------

    public function test_pomocnik_na_ekranie_przepis_niedostepny_ma_formularz_wyjscia_z_sesji(): void
    {
        [, $pomocnik, $recipe, $sesja] = $this->sesjaZPomocnikiem();
        $recipe->forceFill(['visibility' => 'private'])->save();

        $tresc = $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertStatus(403)->getContent();

        $this->assertStringContainsString('Wyjdź z sesji', $tresc);
        $this->assertStringContainsString(route('wspolne-gotowanie.leave', $sesja), $tresc);
        $this->assertStringNotContainsString($recipe->title, $tresc, 'ani słowa z przepisu');
        $this->assertStringNotContainsString('action="'.route('wspolne-gotowanie.destroy', $sesja).'"', $tresc);

        // Obietnica jest prawdziwa: ten formularz naprawdę wyprowadza z sesji.
        $this->actingAs($pomocnik)->delete(route('wspolne-gotowanie.leave', $sesja))->assertRedirect();
        $this->assertSame(0, DB::table('cooking_session_participants')->count());
    }

    public function test_gospodarz_na_ekranie_przepis_niedostepny_ma_formularz_zakonczenia_sesji(): void
    {
        [$gospodarz, , $recipe, $sesja] = $this->sesjaZPomocnikiem();
        $recipe->delete();

        $tresc = $this->actingAs($gospodarz)->get(route('wspolne-gotowanie.show', $sesja))->assertStatus(403)->getContent();

        $this->assertStringContainsString('Zakończ sesję', $tresc);
        $this->assertStringContainsString(route('wspolne-gotowanie.destroy', $sesja), $tresc);
        $this->assertStringNotContainsString(route('wspolne-gotowanie.leave', $sesja), $tresc);

        $this->actingAs($gospodarz)->delete(route('wspolne-gotowanie.destroy', $sesja))->assertRedirect();
        $this->assertSame(0, CookingSession::query()->count());
    }

    // ---------------------------------------------------------------------
    // 3. Skrypt dostaje odpowiedź, z której da się zbudować zdanie o końcu
    // ---------------------------------------------------------------------

    public function test_stan_czlonka_ktory_stracil_przepis_to_200_nieaktywna_a_obcy_dostaje_404(): void
    {
        [, $pomocnik, $recipe, $sesja] = $this->sesjaZPomocnikiem();
        $obcy = $this->user();
        $obserwacje = [];

        $obserwacje['z dostępem'] = $this->actingAs($pomocnik)->getJson(route('wspolne-gotowanie.stan', $sesja))->assertOk()->json();

        $recipe->forceFill(['visibility' => 'private'])->save();
        $odp = $this->actingAs($pomocnik)->getJson(route('wspolne-gotowanie.stan', $sesja));
        $obserwacje['bez dostępu do przepisu'] = [$odp->status(), $odp->json()];
        $obserwacje['obcy'] = $this->actingAs($obcy)->getJson(route('wspolne-gotowanie.stan', $sesja))->status();

        $this->assertSame([
            'z dostępem' => ['aktywna' => true, 'rewizja' => $sesja->revision],
            'bez dostępu do przepisu' => [200, ['aktywna' => false]],
            'obcy' => 404,
        ], $obserwacje);
    }

    public function test_ekran_sesji_ma_staly_pusty_region_live_a_pas_z_przyciskiem_jest_ukryty(): void
    {
        [$gospodarz, , , $sesja] = $this->sesjaZPomocnikiem();

        $tresc = $this->actingAs($gospodarz)->get(route('wspolne-gotowanie.show', $sesja))->assertOk()->getContent();

        // Region live: w DOM od początku, pusty, poza ukrytym pasem.
        $this->assertMatchesRegularExpression('~<p class="m-0" id="wg-zmiana-tekst" role="status" data-postep-tekst></p>~', $tresc);
        $this->assertMatchesRegularExpression('~<div class="cook-sync-zmiana stack" hidden\s+data-postep-synchronizacja~', $tresc);
        $this->assertStringContainsString('data-postep-region="wg-zmiana-tekst"', $tresc);
        $this->assertDoesNotMatchRegularExpression('~<div[^>]*role="status"[^>]*hidden~', $tresc, 'role="status" nie może stać na ukrytym elemencie');
    }

    // ---------------------------------------------------------------------
    // 5. Zakończenie sesji przepisu usuniętego miękko
    // ---------------------------------------------------------------------

    public function test_zakonczenie_sesji_przepisu_usunietego_miekko_prowadzi_na_start_a_zwyklego_do_przepisu(): void
    {
        [$gospodarz, , $recipe, $sesja] = $this->sesjaZPomocnikiem();
        $recipe->delete();

        $this->actingAs($gospodarz)->delete(route('wspolne-gotowanie.destroy', $sesja))->assertRedirect(route('home'));

        // Kontrola dodatnia: przepis nieusunięty — powrót do trybu gotowania.
        [$gospodarz2, , $recipe2, $sesja2] = $this->sesjaZPomocnikiem();
        $this->actingAs($gospodarz2)->delete(route('wspolne-gotowanie.destroy', $sesja2))->assertRedirect(route('cooking.show', $recipe2->slug));
    }

    // ---------------------------------------------------------------------
    // 7. Gospodarz z zamkniętym kontem
    // ---------------------------------------------------------------------

    public function test_gdy_gospodarz_ma_zamkniete_konto_pomocnik_nie_czyta_ani_nie_zapisuje_postepu(): void
    {
        [$gospodarz, $pomocnik, $recipe, $sesja] = $this->sesjaZPomocnikiem();
        $krok = RecipeStep::query()->where('recipe_id', $recipe->getKey())->orderBy('position')->firstOrFail();
        $obserwacje = [];

        // Kontrola dodatnia: zawieszony gospodarz nie odcina pomocnika.
        $gospodarz->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $obserwacje['gospodarz zawieszony: ekran'] = $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja->fresh()))->status();

        $gospodarz->forceFill(['status' => User::STATUS_BANNED])->save();
        $obserwacje['gospodarz zbanowany: ekran'] = $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja->fresh()))->status();
        $obserwacje['gospodarz zbanowany: stan'] = $this->actingAs($pomocnik)->getJson(route('wspolne-gotowanie.stan', $sesja->fresh()))->status();
        $obserwacje['gospodarz zbanowany: zapis kroku'] = $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.krok', $sesja->fresh()), [
            'krok_id' => $krok->getKey(),
            'zrobiono' => '1',
        ])->status();
        $obserwacje['kroki zapisane'] = DB::table('cooking_session_steps')->count();

        $this->assertSame([
            'gospodarz zawieszony: ekran' => 200,
            'gospodarz zbanowany: ekran' => 404,
            'gospodarz zbanowany: stan' => 404,
            'gospodarz zbanowany: zapis kroku' => 404,
            'kroki zapisane' => 0,
        ], $obserwacje);
    }
}

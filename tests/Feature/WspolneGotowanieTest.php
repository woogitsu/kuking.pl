<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Gotowanie\Wspolne\PostepWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\SesjaWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\ZaproszenieDoGotowania;
use App\Domain\Social\Actions\BlockUser;
use App\Domain\Social\Actions\FollowUser;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookingSession;
use App\Models\CookingSessionInvitation;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\WycinaObudoweEkranu;
use Tests\TestCase;

/**
 * Wspólne gotowanie dwóch osób (#2385). Projekt i autoryzacja:
 * `docs/product/PROJEKT_WSPOLNE_GOTOWANIE_2385.md`.
 *
 * Testy autoryzacji mają kontrolę ujemną po obu stronach: dla każdej odmowy
 * jest przypadek, w którym TA SAMA droga się udaje (pomocnik z dostępem,
 * link ważny, przepis publiczny), więc zielony wynik nie bierze się stąd,
 * że ścieżka jest po prostu martwa.
 */
class WspolneGotowanieTest extends TestCase
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

    /** @return array{0: User, 1: Recipe, 2: list<RecipeStep>, 3: CookingSession} */
    private function sesja(string $widocznosc = 'public'): array
    {
        $gospodarz = $this->user();
        [$recipe, $kroki] = $this->przepis($gospodarz, $widocznosc);
        $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $recipe);

        return [$gospodarz, $recipe, $kroki, $sesja];
    }

    private function token(User $gospodarz, CookingSession $sesja): string
    {
        return app(ZaproszenieDoGotowania::class)->utworz($gospodarz, $sesja)[1];
    }

    /** Sesja z pomocnikiem, który dołączył linkiem przez HTTP. */
    private function sesjaZPomocnikiem(): array
    {
        [$gospodarz, $recipe, $kroki, $sesja] = $this->sesja();
        $pomocnik = $this->user();
        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $this->token($gospodarz, $sesja)))->assertRedirect(route('wspolne-gotowanie.show', $sesja));

        return [$gospodarz, $pomocnik, $recipe, $kroki, $sesja->refresh()];
    }

    private function krok(User $osoba, CookingSession $sesja, RecipeStep $krok, bool $zrobiono = true, array $dodatkowe = [])
    {
        return $this->actingAs($osoba)->post(route('wspolne-gotowanie.krok', $sesja), [
            'krok_id' => $krok->getKey(),
            'zrobiono' => $zrobiono ? '1' : '0',
        ] + $dodatkowe);
    }

    // ---------------------------------------------------------------------
    // Zakładanie sesji
    // ---------------------------------------------------------------------

    public function test_gospodarz_zaklada_sesje_dla_widocznego_przepisu_i_widzi_ekran(): void
    {
        $gospodarz = $this->user();
        [$recipe] = $this->przepis();

        $odp = $this->actingAs($gospodarz)->post(route('wspolne-gotowanie.zaloz', $recipe->slug));

        $sesja = CookingSession::query()->firstOrFail();
        $odp->assertRedirect(route('wspolne-gotowanie.show', $sesja));
        $this->assertSame($gospodarz->getKey(), $sesja->host_id);
        $this->assertSame($recipe->getKey(), $sesja->recipe_id);
        $this->assertTrue($sesja->expires_at->between(now()->addHours(23), now()->addHours(25)));

        $ekran = $this->actingAs($gospodarz)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
        $tresc = $this->trescEkranu($ekran->getContent());
        $this->assertStringContainsString($recipe->title, $tresc);
        $this->assertStringContainsString('Krok numer 1.', $tresc);
        $this->assertStringContainsString('Utwórz link', $tresc);
    }

    public function test_ponowne_zalozenie_oddaje_ta_sama_sesje(): void
    {
        $gospodarz = $this->user();
        [$recipe] = $this->przepis();

        $this->actingAs($gospodarz)->post(route('wspolne-gotowanie.zaloz', $recipe->slug));
        $this->actingAs($gospodarz)->post(route('wspolne-gotowanie.zaloz', $recipe->slug));

        $this->assertSame(1, CookingSession::query()->count());
    }

    public function test_gosc_jest_odsylany_do_logowania(): void
    {
        [$recipe] = $this->przepis();

        $this->post(route('wspolne-gotowanie.zaloz', $recipe->slug))->assertRedirect(route('login'));
        $this->assertSame(0, CookingSession::query()->count());
    }

    public function test_nie_zalozy_sesji_dla_przepisu_ktorego_nie_widzi(): void
    {
        [$recipe] = $this->przepis(null, 'private');

        $this->actingAs($this->user())->post(route('wspolne-gotowanie.zaloz', $recipe->slug))->assertForbidden();

        $this->assertSame(0, CookingSession::query()->count());
    }

    public function test_zawieszone_konto_nie_zaklada_sesji(): void
    {
        [$recipe] = $this->przepis();
        $osoba = $this->user();
        $osoba->suspend();

        $this->assertFalse(Gate::forUser($osoba->fresh())->allows('create', [CookingSession::class, $recipe]));
        $this->assertTrue(Gate::forUser($this->user())->allows('create', [CookingSession::class, $recipe]), 'kontrola dodatnia: aktywne konto zakłada');
    }

    public function test_limit_jednoczesnych_sesji_gospodarza(): void
    {
        $gospodarz = $this->user();
        config(['kuking.wspolne_gotowanie.max_sesji_gospodarza' => 2]);

        foreach ([1, 2] as $_) {
            [$recipe] = $this->przepis();
            app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $recipe);
        }

        [$trzeci] = $this->przepis();
        $this->actingAs($gospodarz)->post(route('wspolne-gotowanie.zaloz', $trzeci->slug))
            ->assertRedirect(route('cooking.show', $trzeci->slug))
            ->assertSessionHas('status');

        $this->assertSame(2, CookingSession::query()->count());
    }

    public function test_przepis_bez_krokow_nie_ma_sesji(): void
    {
        $gospodarz = $this->user();
        $recipe = Recipe::factory()->create(['author_id' => $gospodarz->getKey(), 'visibility' => 'public']);

        $this->actingAs($gospodarz)->post(route('wspolne-gotowanie.zaloz', $recipe->slug));

        $this->assertSame(0, CookingSession::query()->count());
    }

    // ---------------------------------------------------------------------
    // Link: utworzenie, hasz, odwołanie
    // ---------------------------------------------------------------------

    public function test_link_ma_w_bazie_tylko_skrot_tokenu_a_jawny_trafia_do_odpowiedzi(): void
    {
        [$gospodarz, , , $sesja] = $this->sesja();

        $odp = $this->actingAs($gospodarz)->post(route('wspolne-gotowanie.link.store', $sesja));

        $link = session('link_zaproszenia');
        $this->assertNotNull($link);
        $token = basename((string) $link);
        $this->assertSame(40, strlen($token));
        $odp->assertRedirect(route('wspolne-gotowanie.show', $sesja));

        $zaproszenie = CookingSessionInvitation::query()->firstOrFail();
        $this->assertSame(hash('sha256', $token), $zaproszenie->token_hash);
        $this->assertNotSame($token, $zaproszenie->token_hash);
        $this->assertSame(0, DB::table('cooking_session_invitations')->where('token_hash', $token)->count());
        $this->assertArrayNotHasKey('token_hash', $zaproszenie->toArray());
        $this->assertTrue($zaproszenie->expires_at->between(now()->addHours(23), now()->addHours(25)));
    }

    public function test_nowy_link_uniewaznia_poprzedni(): void
    {
        [$gospodarz, , , $sesja] = $this->sesja();
        $stary = $this->token($gospodarz, $sesja);
        $nowy = $this->token($gospodarz, $sesja);
        $pomocnik = $this->user();

        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $stary))->assertStatus(410);
        $this->assertSame(0, DB::table('cooking_session_participants')->count());

        // Kontrola dodatnia: nowy link działa.
        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $nowy))->assertRedirect();
        $this->assertSame(1, DB::table('cooking_session_participants')->count());
    }

    public function test_odwolany_link_nie_wpuszcza(): void
    {
        [$gospodarz, , , $sesja] = $this->sesja();
        $token = $this->token($gospodarz, $sesja);

        $this->actingAs($gospodarz)->delete(route('wspolne-gotowanie.link.destroy', $sesja))->assertRedirect();

        $this->actingAs($this->user())->post(route('wspolne-gotowanie.link.accept', $token))->assertStatus(410);
        $this->assertSame(0, DB::table('cooking_session_participants')->count());
    }

    public function test_pomocnik_nie_tworzy_i_nie_odwoluje_linku(): void
    {
        [$gospodarz, $pomocnik, , , $sesja] = $this->sesjaZPomocnikiem();

        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.store', $sesja))->assertForbidden();
        $this->actingAs($pomocnik)->delete(route('wspolne-gotowanie.link.destroy', $sesja))->assertForbidden();

        // Kontrola dodatnia: gospodarz ten sam link odwoła (po usunięciu pomocnika zrobi nowy).
        $this->actingAs($gospodarz)->delete(route('wspolne-gotowanie.link.destroy', $sesja))->assertRedirect();
    }

    // ---------------------------------------------------------------------
    // Dołączanie: kto może, kto nie
    // ---------------------------------------------------------------------

    public function test_wejscie_na_link_niczego_nie_zuzywa_a_przycisk_dolacza(): void
    {
        [$gospodarz, $recipe, , $sesja] = $this->sesja();
        $token = $this->token($gospodarz, $sesja);
        $pomocnik = $this->user();

        $podglad = $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.link.show', $token))->assertOk();
        $this->assertStringContainsString($recipe->title, $podglad->getContent());
        $this->assertSame('no-referrer', $podglad->headers->get('Referrer-Policy'));
        $this->assertSame(CookingSessionInvitation::STATUS_PENDING, CookingSessionInvitation::query()->firstOrFail()->status);
        $this->assertSame(0, DB::table('cooking_session_participants')->count());

        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $token))->assertRedirect(route('wspolne-gotowanie.show', $sesja));

        $this->assertSame(1, DB::table('cooking_session_participants')->where('user_id', $pomocnik->getKey())->count());
        $this->assertSame(CookingSessionInvitation::STATUS_ACCEPTED, CookingSessionInvitation::query()->firstOrFail()->status);
        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
    }

    public function test_podwojne_klikniecie_dolaczam_to_jedno_czlonkostwo_i_sukces(): void
    {
        [$gospodarz, , , $sesja] = $this->sesja();
        $token = $this->token($gospodarz, $sesja);
        $pomocnik = $this->user();

        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $token))->assertRedirect();
        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $token))->assertRedirect(route('wspolne-gotowanie.show', $sesja));

        $this->assertSame(1, DB::table('cooking_session_participants')->count());
    }

    public function test_link_zuzyty_przez_jedna_osobe_nie_wpuszcza_drugiej(): void
    {
        [$gospodarz, , , $sesja] = $this->sesja();
        $token = $this->token($gospodarz, $sesja);
        $pierwsza = $this->user();
        $druga = $this->user();

        $this->actingAs($pierwsza)->post(route('wspolne-gotowanie.link.accept', $token))->assertRedirect();
        $this->actingAs($druga)->post(route('wspolne-gotowanie.link.accept', $token))->assertStatus(410);

        $this->assertSame([$pierwsza->getKey()], DB::table('cooking_session_participants')->pluck('user_id')->all());
        $this->actingAs($druga)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();
    }

    public function test_wygasly_link_nie_wpuszcza(): void
    {
        [$gospodarz, , , $sesja] = $this->sesja();
        $token = $this->token($gospodarz, $sesja);
        $pomocnik = $this->user();

        $this->travel(25)->hours();

        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.link.show', $token))->assertStatus(410);
        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $token))->assertStatus(410);
        $this->assertSame(0, DB::table('cooking_session_participants')->count());
    }

    public function test_link_wygasa_wczesniej_niz_zywa_sesja(): void
    {
        config(['kuking.wspolne_gotowanie.link_godziny' => 1]);
        [$gospodarz, , , $sesja] = $this->sesja();
        $token = $this->token($gospodarz, $sesja);
        $pomocnik = $this->user();

        $this->travel(2)->hours();

        $this->assertTrue($sesja->fresh()->trwa(), 'sesja nadal trwa');
        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.link.show', $token))->assertStatus(410);
        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $token))->assertStatus(410);
        $this->assertSame(0, DB::table('cooking_session_participants')->count());

        // Kontrola dodatnia: świeży link do tej samej sesji działa.
        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $this->token($gospodarz, $sesja)))->assertRedirect();
        $this->assertSame(1, DB::table('cooking_session_participants')->count());
    }

    public function test_nieznany_i_cudzy_token_daja_ten_sam_ekran_co_wygasly(): void
    {
        [$gospodarz, , , $sesja] = $this->sesja();
        $token = $this->token($gospodarz, $sesja);
        $osoba = $this->user();

        $this->travel(25)->hours();
        $wygasly = $this->actingAs($osoba)->get(route('wspolne-gotowanie.link.show', $token));
        $nieznany = $this->actingAs($osoba)->get(route('wspolne-gotowanie.link.show', str_repeat('x', 40)));
        $zlaDlugosc = $this->actingAs($osoba)->get(route('wspolne-gotowanie.link.show', 'krotki'));

        foreach ([$wygasly, $nieznany, $zlaDlugosc] as $odp) {
            $odp->assertStatus(410);
        }
        $this->assertSame($this->trescEkranu($wygasly->getContent()), $this->trescEkranu($nieznany->getContent()));
        $this->assertSame($this->trescEkranu($wygasly->getContent()), $this->trescEkranu($zlaDlugosc->getContent()));
    }

    public function test_link_do_prywatnego_przepisu_nie_otwiera_tresci_osobie_bez_uprawnien(): void
    {
        [$gospodarz, $recipe, , $sesja] = $this->sesja('private');
        $token = $this->token($gospodarz, $sesja);
        $pomocnik = $this->user();

        $podglad = $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.link.show', $token))->assertStatus(410);
        $this->assertStringNotContainsString($recipe->title, $podglad->getContent());
        $this->assertStringNotContainsString('Krok numer 1.', $podglad->getContent());

        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $token))->assertStatus(410);
        $this->assertSame(0, DB::table('cooking_session_participants')->count());
        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();

        // Kontrola dodatnia: gospodarz własną sesję na prywatnym przepisie widzi.
        $this->actingAs($gospodarz)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
    }

    public function test_przepis_dla_obserwujacych_wymaga_obserwowania_autora(): void
    {
        [$gospodarz, , , $sesja] = $this->sesja('followers');
        $token = $this->token($gospodarz, $sesja);
        $obcy = $this->user();
        $obserwujacy = $this->user();
        app(FollowUser::class)->handle($obserwujacy, $gospodarz);

        $this->actingAs($obcy)->post(route('wspolne-gotowanie.link.accept', $token))->assertStatus(410);
        $this->assertSame(0, DB::table('cooking_session_participants')->count());

        $this->actingAs($obserwujacy)->post(route('wspolne-gotowanie.link.accept', $token))->assertRedirect();
        $this->assertSame(1, DB::table('cooking_session_participants')->count());
    }

    public function test_blokada_z_gospodarzem_w_obie_strony_odcina_link(): void
    {
        foreach (['pomocnik-blokuje', 'gospodarz-blokuje'] as $kierunek) {
            [$gospodarz, , , $sesja] = $this->sesja();
            $token = $this->token($gospodarz, $sesja);
            $pomocnik = $this->user();

            $kierunek === 'pomocnik-blokuje'
                ? app(BlockUser::class)->handle($pomocnik, $gospodarz)
                : app(BlockUser::class)->handle($gospodarz, $pomocnik);

            $odp = $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $token));
            $this->assertSame(410, $odp->getStatusCode(), $kierunek);
            $this->assertSame(0, DB::table('cooking_session_participants')->where('session_id', $sesja->getKey())->count(), $kierunek);
        }
    }

    public function test_blokada_z_gospodarzem_niebedacym_autorem_tez_odcina_link(): void
    {
        // Gospodarz gotuje CUDZY publiczny przepis — bramka widoczności przepisu
        // nie widzi blokady między gospodarzem a pomocnikiem, więc musi ją
        // wyłapać sama akcja dołączania.
        foreach (['pomocnik-blokuje', 'gospodarz-blokuje'] as $kierunek) {
            [$recipe] = $this->przepis();
            $gospodarz = $this->user();
            $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $recipe);
            $token = $this->token($gospodarz, $sesja);
            $pomocnik = $this->user();

            $kierunek === 'pomocnik-blokuje'
                ? app(BlockUser::class)->handle($pomocnik, $gospodarz)
                : app(BlockUser::class)->handle($gospodarz, $pomocnik);

            $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $token))->assertStatus(410);
            $this->assertSame(0, DB::table('cooking_session_participants')->where('session_id', $sesja->getKey())->count(), $kierunek);
        }

        // Kontrola dodatnia: bez blokady ta sama droga wpuszcza.
        [$recipe] = $this->przepis();
        $gospodarz = $this->user();
        $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $recipe);
        $this->actingAs($this->user())->post(route('wspolne-gotowanie.link.accept', $this->token($gospodarz, $sesja)))->assertRedirect();
    }

    public function test_blokada_po_dolaczeniu_gdy_gospodarz_nie_jest_autorem_tez_konczy_udzial(): void
    {
        [$recipe, $kroki] = $this->przepis();
        $gospodarz = $this->user();
        $pomocnik = $this->user();
        $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $recipe);
        app(ZaproszenieDoGotowania::class)->dolacz($pomocnik, $this->token($gospodarz, $sesja));
        $this->krok($pomocnik, $sesja, $kroki[0])->assertRedirect();

        app(BlockUser::class)->handle($gospodarz, $pomocnik);

        $this->assertSame(0, DB::table('cooking_session_participants')->count());
        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();
        $this->krok($pomocnik, $sesja, $kroki[1])->assertNotFound();
        $this->assertSame(1, DB::table('cooking_session_steps')->count());
    }

    public function test_blokada_z_autorem_przepisu_innym_niz_gospodarz_odcina_link(): void
    {
        $autor = $this->user();
        [$recipe] = $this->przepis($autor);
        $gospodarz = $this->user();
        $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $recipe);
        $token = $this->token($gospodarz, $sesja);
        $pomocnik = $this->user();
        app(BlockUser::class)->handle($autor, $pomocnik);

        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $token))->assertStatus(410);
        $this->assertSame(0, DB::table('cooking_session_participants')->count());
    }

    public function test_zawieszone_konto_nie_dolaczy(): void
    {
        [$gospodarz, , , $sesja] = $this->sesja();
        $token = $this->token($gospodarz, $sesja);
        $pomocnik = $this->user();
        $pomocnik->suspend();

        // HTTP: zawieszone konto jest odcinane od zapisów już w warstwie ogólnej...
        $this->actingAs($pomocnik->fresh())->post(route('wspolne-gotowanie.link.accept', $token))->assertRedirect();
        $this->assertSame(0, DB::table('cooking_session_participants')->count());

        // ...a akcja domenowa odmawia sama, gdyby ktoś zawołał ją inną drogą.
        try {
            app(ZaproszenieDoGotowania::class)->dolacz($pomocnik->fresh(), $token);
            $this->fail('Zawieszone konto dołączyło.');
        } catch (BladDlaCzlowieka) {
            $this->assertSame(0, DB::table('cooking_session_participants')->count());
        }

        // Kontrola dodatnia: to samo wywołanie dla aktywnego konta się udaje.
        app(ZaproszenieDoGotowania::class)->dolacz($this->user(), $token);
        $this->assertSame(1, DB::table('cooking_session_participants')->count());
    }

    public function test_gospodarz_otwierajacy_wlasny_link_nie_dolacza_do_siebie(): void
    {
        [$gospodarz, , , $sesja] = $this->sesja();
        $token = $this->token($gospodarz, $sesja);

        $this->actingAs($gospodarz)->get(route('wspolne-gotowanie.link.show', $token))->assertOk()->assertSee('To jest Twoja sesja');
        $this->actingAs($gospodarz)->post(route('wspolne-gotowanie.link.accept', $token))->assertStatus(410);

        $this->assertSame(0, DB::table('cooking_session_participants')->count());
        $this->assertSame(CookingSessionInvitation::STATUS_PENDING, CookingSessionInvitation::query()->firstOrFail()->status);
    }

    public function test_gosc_na_linku_trafia_na_logowanie(): void
    {
        [$gospodarz, , , $sesja] = $this->sesja();
        $token = $this->token($gospodarz, $sesja);

        $this->get(route('wspolne-gotowanie.link.show', $token))->assertRedirect(route('login'));
        $this->post(route('wspolne-gotowanie.link.accept', $token))->assertRedirect(route('login'));
        $this->assertSame(CookingSessionInvitation::STATUS_PENDING, CookingSessionInvitation::query()->firstOrFail()->status);
    }

    public function test_drugi_pomocnik_nie_zmiesci_sie_przy_limicie_jednego(): void
    {
        [$gospodarz, , , , $sesja] = $this->sesjaZPomocnikiem();

        $this->actingAs($gospodarz)->post(route('wspolne-gotowanie.link.store', $sesja))->assertSessionHasErrors('link');
        // Kontrola dodatnia: po podniesieniu limitu link powstaje.
        config(['kuking.wspolne_gotowanie.max_pomocnikow' => 2]);
        $this->actingAs($gospodarz)->post(route('wspolne-gotowanie.link.store', $sesja))->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------------------------
    // Dostęp do sesji: UUID to nie autoryzacja
    // ---------------------------------------------------------------------

    public function test_obca_zalogowana_osoba_dostaje_404_na_kazdej_trasie_sesji(): void
    {
        [$gospodarz, $pomocnik, , $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $obca = $this->user();
        $moderator = $this->moderator();

        foreach ([$obca, $moderator] as $kto) {
            $this->actingAs($kto)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();
            $this->actingAs($kto)->getJson(route('wspolne-gotowanie.stan', $sesja))->assertNotFound();
            $this->krok($kto, $sesja, $kroki[0])->assertNotFound();
            $this->actingAs($kto)->post(route('wspolne-gotowanie.od-poczatku', $sesja))->assertNotFound();
            $this->actingAs($kto)->post(route('wspolne-gotowanie.link.store', $sesja))->assertNotFound();
            $this->actingAs($kto)->delete(route('wspolne-gotowanie.link.destroy', $sesja))->assertNotFound();
            $this->actingAs($kto)->delete(route('wspolne-gotowanie.pomocnik.destroy', ['cookingSession' => $sesja, 'user' => $pomocnik->getKey()]))->assertNotFound();
            $this->actingAs($kto)->delete(route('wspolne-gotowanie.leave', $sesja))->assertNotFound();
            $this->actingAs($kto)->delete(route('wspolne-gotowanie.destroy', $sesja))->assertNotFound();
        }

        $this->assertSame(0, DB::table('cooking_session_steps')->count());
        $this->assertSame(1, DB::table('cooking_session_participants')->count());
        $this->assertNotNull(CookingSession::query()->find($sesja->getKey()));

        // Kontrola dodatnia: obie strony sesji widzą ją i jej stan.
        foreach ([$gospodarz, $pomocnik] as $uczestnik) {
            $this->actingAs($uczestnik)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
            $this->actingAs($uczestnik)->getJson(route('wspolne-gotowanie.stan', $sesja))->assertOk()->assertJson(['aktywna' => true, 'rewizja' => $sesja->revision]);
        }
    }

    public function test_pomocnik_nie_moze_zarzadzac_ani_konczyc_sesji(): void
    {
        [$gospodarz, $pomocnik, , $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $this->krok($gospodarz, $sesja, $kroki[0])->assertRedirect();

        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.od-poczatku', $sesja))->assertForbidden();
        $this->actingAs($pomocnik)->delete(route('wspolne-gotowanie.pomocnik.destroy', ['cookingSession' => $sesja, 'user' => $pomocnik->getKey()]))->assertForbidden();
        $this->actingAs($pomocnik)->delete(route('wspolne-gotowanie.destroy', $sesja))->assertNotFound();

        $this->assertSame(1, DB::table('cooking_session_steps')->count(), 'odhaczenie gospodarza przetrwało');
        $this->assertNotNull(CookingSession::query()->find($sesja->getKey()));

        // Kontrola dodatnia: to samo wyczyszczenie robi gospodarz.
        $this->actingAs($gospodarz)->post(route('wspolne-gotowanie.od-poczatku', $sesja))->assertRedirect();
        $this->assertSame(0, DB::table('cooking_session_steps')->count());
    }

    public function test_wygasla_sesja_jest_niewidoczna_dla_obu_stron(): void
    {
        [$gospodarz, $pomocnik, , $kroki, $sesja] = $this->sesjaZPomocnikiem();

        $this->travel(25)->hours();

        foreach ([$gospodarz, $pomocnik] as $kto) {
            $this->actingAs($kto)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();
            $this->actingAs($kto)->getJson(route('wspolne-gotowanie.stan', $sesja))->assertNotFound();
            $this->krok($kto, $sesja, $kroki[0])->assertNotFound();
        }
        $this->assertSame(0, DB::table('cooking_session_steps')->count());
    }

    public function test_pomocnik_traci_tresc_gdy_autor_zmieni_przepis_na_prywatny(): void
    {
        [$gospodarz, $pomocnik, $recipe, $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();

        $recipe->forceFill(['visibility' => 'private'])->save();

        $odp = $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertForbidden();
        $this->assertStringNotContainsString($recipe->title, $odp->getContent());
        $this->assertStringNotContainsString('Krok numer 1.', $odp->getContent());
        $this->actingAs($pomocnik)->getJson(route('wspolne-gotowanie.stan', $sesja))->assertForbidden();
        $this->krok($pomocnik, $sesja, $kroki[0])->assertForbidden();
        $this->assertSame(0, DB::table('cooking_session_steps')->count());

        // Kontrola dodatnia: gospodarz (autor) dalej widzi i odhacza.
        $this->actingAs($gospodarz)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
        $this->krok($gospodarz, $sesja, $kroki[0])->assertRedirect();
        $this->assertSame(1, DB::table('cooking_session_steps')->count());
    }

    public function test_blokada_po_dolaczeniu_konczy_udzial_pomocnika(): void
    {
        foreach (['pomocnik-blokuje', 'gospodarz-blokuje'] as $kierunek) {
            [$gospodarz, $pomocnik, , $kroki, $sesja] = $this->sesjaZPomocnikiem();
            $this->krok($pomocnik, $sesja, $kroki[0])->assertRedirect();

            $kierunek === 'pomocnik-blokuje'
                ? app(BlockUser::class)->handle($pomocnik, $gospodarz)
                : app(BlockUser::class)->handle($gospodarz, $pomocnik);

            $this->assertSame(0, DB::table('cooking_session_participants')->where('session_id', $sesja->getKey())->count(), $kierunek);
            $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();
            $this->krok($pomocnik, $sesja, $kroki[1])->assertNotFound();
            // Gospodarz i jego sesja zostają; odhaczenie sprzed blokady też.
            $this->actingAs($gospodarz)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
            $this->assertSame(1, DB::table('cooking_session_steps')->where('session_id', $sesja->getKey())->count());
        }
    }

    public function test_zawieszone_konto_czyta_ale_nie_odhacza(): void
    {
        [$gospodarz, $pomocnik, , $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $pomocnik->suspend();
        $pomocnik = $pomocnik->fresh();

        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertOk()->assertSee('możesz tylko czytać');
        $this->krok($pomocnik, $sesja, $kroki[0])->assertRedirect();
        $this->assertSame(0, DB::table('cooking_session_steps')->count());

        // Akcja domenowa i Policy odmawiają same, niezależnie od warstwy ogólnej.
        $this->assertFalse(Gate::forUser($pomocnik)->allows('update', $sesja));
        try {
            app(PostepWspolnegoGotowania::class)->ustaw($pomocnik, $sesja, (string) $kroki[0]->getKey(), true);
            $this->fail('Zawieszone konto odhaczyło krok.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('cooking_session_steps')->count());
        }

        $this->krok($gospodarz, $sesja, $kroki[0])->assertRedirect();
        $this->assertSame(1, DB::table('cooking_session_steps')->count());
    }

    // ---------------------------------------------------------------------
    // Wspólny postęp
    // ---------------------------------------------------------------------

    public function test_pomocnik_odhacza_krok_a_gospodarz_go_widzi_z_podpisem(): void
    {
        [$gospodarz, $pomocnik, , $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $rewizja = $sesja->revision;

        $this->krok($pomocnik, $sesja, $kroki[1])->assertRedirect();

        $wiersz = DB::table('cooking_session_steps')->firstOrFail();
        $this->assertSame($kroki[1]->getKey(), $wiersz->step_id);
        $this->assertSame($pomocnik->getKey(), $wiersz->done_by_id);
        $this->assertSame($rewizja + 1, $sesja->fresh()->revision);

        $ekran = $this->actingAs($gospodarz)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
        $tresc = $this->trescEkranu($ekran->getContent());
        $this->assertStringContainsString('odhaczone przez: '.$pomocnik->displayName(), $tresc);
        $this->assertStringContainsString('1 z 3', $tresc);
    }

    public function test_odhaczenie_jest_idempotentne_i_nie_podbija_rewizji_drugi_raz(): void
    {
        [$gospodarz, $pomocnik, , $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $rewizja = $sesja->revision;

        $this->krok($gospodarz, $sesja, $kroki[0])->assertRedirect();
        $this->krok($pomocnik, $sesja, $kroki[0])->assertRedirect();

        $this->assertSame(1, DB::table('cooking_session_steps')->count());
        $this->assertSame($rewizja + 1, $sesja->fresh()->revision);
        $this->assertSame($gospodarz->getKey(), DB::table('cooking_session_steps')->value('done_by_id'), 'zostaje pierwszy podpis');
    }

    public function test_cofniecie_kroku_i_cofniecie_niezrobionego(): void
    {
        [$gospodarz, , , $kroki, $sesja] = $this->sesjaZPomocnikiem();

        $this->krok($gospodarz, $sesja, $kroki[0])->assertRedirect();
        $rewizja = $sesja->fresh()->revision;
        $this->krok($gospodarz, $sesja, $kroki[0], false)->assertRedirect();

        $this->assertSame(0, DB::table('cooking_session_steps')->count());
        $this->assertSame($rewizja + 1, $sesja->fresh()->revision);

        // Cofnięcie kroku, który nie jest zrobiony, niczego nie zmienia.
        $this->krok($gospodarz, $sesja, $kroki[0], false)->assertRedirect();
        $this->assertSame($rewizja + 1, $sesja->fresh()->revision);
    }

    public function test_krok_z_innego_przepisu_nie_zostaje_zapisany(): void
    {
        [$gospodarz, , , , $sesja] = $this->sesjaZPomocnikiem();
        [, $obceKroki] = $this->przepis();

        $this->krok($gospodarz, $sesja, $obceKroki[0])->assertRedirect()->assertSessionHas('status');

        $this->assertSame(0, DB::table('cooking_session_steps')->count());
    }

    public function test_rozbiezna_rewizja_daje_jedno_zdanie_a_podwojne_klikniecie_nie(): void
    {
        [$gospodarz, $pomocnik, , $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $widziana = $sesja->revision;

        // Druga osoba zmienia inny krok, zanim gospodarz kliknie.
        $this->krok($pomocnik, $sesja, $kroki[1])->assertRedirect();
        $odp = $this->krok($gospodarz, $sesja, $kroki[0], true, ['rewizja' => $widziana]);
        $this->assertStringContainsString('Druga osoba zmieniła postęp', (string) session('status'));
        $this->assertSame(2, DB::table('cooking_session_steps')->count(), 'kliknięcie gospodarza też zapisane');
        $odp->assertRedirect();

        // Podwójne kliknięcie tego samego formularza: bez ostrzeżenia.
        $this->flushSession();
        $widziana2 = $sesja->fresh()->revision;
        $this->krok($gospodarz, $sesja, $kroki[2], true, ['rewizja' => $widziana2])->assertRedirect();
        $this->flushSession();
        $this->krok($gospodarz, $sesja, $kroki[2], true, ['rewizja' => $widziana2]);
        $this->assertStringNotContainsString('Druga osoba', (string) session('status'));
    }

    public function test_tylko_gospodarz_czysci_odhaczenia(): void
    {
        [$gospodarz, , , $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $this->krok($gospodarz, $sesja, $kroki[0])->assertRedirect();
        $this->krok($gospodarz, $sesja, $kroki[1])->assertRedirect();

        $this->actingAs($gospodarz)->post(route('wspolne-gotowanie.od-poczatku', $sesja))->assertRedirect();

        $this->assertSame(0, DB::table('cooking_session_steps')->count());
    }

    // ---------------------------------------------------------------------
    // Koniec sesji, wyjście, usunięcie pomocnika
    // ---------------------------------------------------------------------

    public function test_zakonczenie_kasuje_wszystko_i_odcina_pomocnika(): void
    {
        [$gospodarz, $pomocnik, $recipe, $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $this->krok($pomocnik, $sesja, $kroki[0])->assertRedirect();

        $this->actingAs($gospodarz)->delete(route('wspolne-gotowanie.destroy', $sesja))->assertRedirect(route('cooking.show', $recipe->slug));

        foreach (['cooking_sessions', 'cooking_session_participants', 'cooking_session_steps', 'cooking_session_invitations'] as $tabela) {
            $this->assertSame(0, DB::table($tabela)->count(), $tabela);
        }
        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();
        // Przepis nietknięty.
        $this->assertNotNull(Recipe::query()->find($recipe->getKey()));
    }

    public function test_pomocnik_wychodzi_odhaczenia_zostaja_a_gospodarz_moze_zaprosic_nowa_osobe(): void
    {
        [$gospodarz, $pomocnik, , $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $this->krok($pomocnik, $sesja, $kroki[0])->assertRedirect();

        $this->actingAs($pomocnik)->delete(route('wspolne-gotowanie.leave', $sesja))->assertRedirect(route('home'));

        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();
        $this->assertSame(1, DB::table('cooking_session_steps')->count());

        $nowy = $this->user();
        $this->actingAs($nowy)->post(route('wspolne-gotowanie.link.accept', $this->token($gospodarz, $sesja)))->assertRedirect();
        $this->assertSame([$nowy->getKey()], DB::table('cooking_session_participants')->pluck('user_id')->all());
    }

    public function test_gospodarz_usuwa_pomocnika(): void
    {
        [$gospodarz, $pomocnik, , , $sesja] = $this->sesjaZPomocnikiem();

        $this->actingAs($gospodarz)->delete(route('wspolne-gotowanie.pomocnik.destroy', ['cookingSession' => $sesja, 'user' => $pomocnik->getKey()]))->assertRedirect();

        $this->assertSame(0, DB::table('cooking_session_participants')->count());
        $this->actingAs($pomocnik)->get(route('wspolne-gotowanie.show', $sesja))->assertNotFound();
    }

    public function test_usuniety_pomocnik_nie_wraca_zuzytym_linkiem(): void
    {
        [$gospodarz, , , $sesja] = $this->sesja();
        $token = $this->token($gospodarz, $sesja);
        $pomocnik = $this->user();
        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $token))->assertRedirect();
        app(SesjaWspolnegoGotowania::class)->usunPomocnika($gospodarz, $sesja, (string) $pomocnik->getKey());

        $this->actingAs($pomocnik)->post(route('wspolne-gotowanie.link.accept', $token))->assertStatus(410);
        $this->assertSame(0, DB::table('cooking_session_participants')->count());
    }

    // ---------------------------------------------------------------------
    // Konto, eksport, sprzątanie
    // ---------------------------------------------------------------------

    public function test_wymazanie_konta_gospodarza_kasuje_sesje(): void
    {
        [$gospodarz, , , $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $this->krok($gospodarz, $sesja, $kroki[0])->assertRedirect();

        $gospodarz->markForDeletion();
        app(EraseAccountData::class)->handle($gospodarz->fresh());

        foreach (['cooking_sessions', 'cooking_session_participants', 'cooking_session_steps', 'cooking_session_invitations'] as $tabela) {
            $this->assertSame(0, DB::table($tabela)->count(), $tabela);
        }
    }

    public function test_wymazanie_konta_pomocnika_zdejmuje_udzial_i_podpis_a_sesja_zostaje(): void
    {
        [$gospodarz, $pomocnik, , $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $this->krok($pomocnik, $sesja, $kroki[0])->assertRedirect();

        $pomocnik->markForDeletion();
        app(EraseAccountData::class)->handle($pomocnik->fresh());

        $this->assertSame(0, DB::table('cooking_session_participants')->count());
        $this->assertNull(DB::table('cooking_session_steps')->value('done_by_id'), 'podpis zdjęty');
        $this->assertSame(1, DB::table('cooking_session_steps')->count(), 'krok zostaje zrobiony');
        $this->assertNotNull(CookingSession::query()->find($sesja->getKey()));
        $this->assertNull(DB::table('cooking_session_invitations')->value('accepted_by_id'));
        $ekran = $this->actingAs($gospodarz)->get(route('wspolne-gotowanie.show', $sesja))->assertOk();
        $this->assertStringContainsString('osobę, która usunęła konto', $ekran->getContent());
    }

    public function test_paczka_danych_zawiera_sesje_bez_tokenow_i_bez_drugiej_osoby(): void
    {
        [$gospodarz, $pomocnik, $recipe, $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $this->krok($pomocnik, $sesja, $kroki[2])->assertRedirect();
        $this->krok($gospodarz, $sesja, $kroki[0])->assertRedirect();

        $paczkaPomocnika = app(CollectUserExportData::class)->handle($pomocnik, new ExportPhotoPlan($pomocnik), Carbon::now());
        $this->assertCount(1, $paczkaPomocnika['wspolne_gotowanie']);
        $this->assertSame('pomocnik', $paczkaPomocnika['wspolne_gotowanie'][0]['rola']);
        $this->assertSame($recipe->title, $paczkaPomocnika['wspolne_gotowanie'][0]['przepis']);
        $this->assertSame([3], $paczkaPomocnika['wspolne_gotowanie'][0]['kroki_odhaczone_przeze_mnie']);

        $paczkaGospodarza = app(CollectUserExportData::class)->handle($gospodarz, new ExportPhotoPlan($gospodarz), Carbon::now());
        $this->assertSame('gospodarz', $paczkaGospodarza['wspolne_gotowanie'][0]['rola']);
        $this->assertSame([1], $paczkaGospodarza['wspolne_gotowanie'][0]['kroki_odhaczone_przeze_mnie']);

        $json = json_encode($paczkaGospodarza, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) DB::table('cooking_session_invitations')->value('token_hash'), $json);
        $this->assertStringNotContainsString($pomocnik->displayName(), json_encode($paczkaGospodarza['wspolne_gotowanie'], JSON_THROW_ON_ERROR));

        // Obca osoba nie ma w paczce niczego.
        $obca = $this->user();
        $this->assertSame([], app(CollectUserExportData::class)->handle($obca, new ExportPhotoPlan($obca), Carbon::now())['wspolne_gotowanie']);
    }

    public function test_paczka_nie_zdradza_tytulu_przepisu_niedostepnego_juz_dla_osoby(): void
    {
        [, $pomocnik, $recipe] = $this->sesjaZPomocnikiem();
        $recipe->forceFill(['visibility' => 'private'])->save();

        $paczka = app(CollectUserExportData::class)->handle($pomocnik, new ExportPhotoPlan($pomocnik), Carbon::now());

        $this->assertSame(CollectUserExportData::TRESC_NIEDOSTEPNA, $paczka['wspolne_gotowanie'][0]['przepis']);
    }

    public function test_sprzatanie_kasuje_wygasle_sesje_i_zostawia_zywe(): void
    {
        [, , , , $zywa] = $this->sesjaZPomocnikiem();
        [, , , , $wygasla] = $this->sesjaZPomocnikiem();
        DB::table('cooking_sessions')->where('id', $wygasla->getKey())->update(['expires_at' => now()->subMinute(), 'created_at' => now()->subDay()]);

        $this->artisan('kuking:sprzataj-wspolne-gotowanie', ['--na-sucho' => true])->expectsOutputToContain('Do skasowania: 1')->assertSuccessful();
        $this->assertSame(2, CookingSession::query()->count());

        $this->artisan('kuking:sprzataj-wspolne-gotowanie')->expectsOutputToContain('Skasowano 1')->assertSuccessful();

        $this->assertNull(CookingSession::query()->find($wygasla->getKey()));
        $this->assertNotNull(CookingSession::query()->find($zywa->getKey()));
        $this->assertSame(1, DB::table('cooking_session_participants')->count(), 'potomne wygasłej zniknęły, żywej zostały');
    }

    public function test_wygasla_sesja_nie_blokuje_zalozenia_nowej_dla_tego_samego_przepisu(): void
    {
        [$gospodarz, $recipe, , $stara] = $this->sesja();
        DB::table('cooking_sessions')->where('id', $stara->getKey())->update(['expires_at' => now()->subMinute(), 'created_at' => now()->subDay()]);

        $nowa = app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $recipe);

        $this->assertNotSame($stara->getKey(), $nowa->getKey());
        $this->assertTrue($nowa->trwa());
    }

    // ---------------------------------------------------------------------
    // Polityka i modele
    // ---------------------------------------------------------------------

    public function test_macierz_polityki(): void
    {
        [$gospodarz, $pomocnik, , , $sesja] = $this->sesjaZPomocnikiem();
        $obca = $this->user();
        $moderator = $this->moderator();

        $oczekiwane = [
            'view' => [$gospodarz->getKey() => true, $pomocnik->getKey() => true, $obca->getKey() => false, $moderator->getKey() => false],
            'update' => [$gospodarz->getKey() => true, $pomocnik->getKey() => true, $obca->getKey() => false, $moderator->getKey() => false],
            'manage' => [$gospodarz->getKey() => true, $pomocnik->getKey() => false, $obca->getKey() => false, $moderator->getKey() => false],
            'end' => [$gospodarz->getKey() => true, $pomocnik->getKey() => false, $obca->getKey() => false, $moderator->getKey() => false],
            'leave' => [$gospodarz->getKey() => false, $pomocnik->getKey() => true, $obca->getKey() => false, $moderator->getKey() => false],
        ];

        foreach ($oczekiwane as $akcja => $wedlugOsob) {
            foreach ([$gospodarz, $pomocnik, $obca, $moderator] as $kto) {
                $this->assertSame(
                    $wedlugOsob[$kto->getKey()],
                    Gate::forUser($kto)->allows($akcja, $sesja),
                    "{$akcja}: ".$kto->getKey(),
                );
            }
        }
    }

    public function test_modele_nie_przyjmuja_pol_sterujacych_masowo(): void
    {
        $this->assertSame([], (new CookingSession)->getFillable());
        $this->assertSame([], (new CookingSessionInvitation)->getFillable());
    }

    public function test_akcja_domenowa_sama_odmawia_obcemu_mimo_ze_kontroler_by_jej_nie_zawolal(): void
    {
        [, , , $kroki, $sesja] = $this->sesjaZPomocnikiem();
        $obca = $this->user();

        $this->expectException(AuthorizationException::class);

        app(PostepWspolnegoGotowania::class)->ustaw($obca, $sesja, (string) $kroki[0]->getKey(), true);
    }
}

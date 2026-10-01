<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Models\Block;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * „Wskazówki od gotujących" (#2352, D-333, decyzja właściciela z 1.10.2026).
 *
 * Autor przepisu PROPONUJE, kucharz odpowiada („Zgadzam się" / „Nie"), brak
 * odpowiedzi = brak publikacji, zgodę można wycofać. Każdy pomiar idzie
 * przez HTTP i końcowy HTML (albo przez prawdziwą paczkę danych i prawdziwą
 * akcję wymazania); kontrole ujemne stoją obok dodatnich.
 *
 * @bez-kontroli-dodatniej Testy behawioralne (HTTP, baza, paczka danych), nie strażnik tekstu; kontrole mutacyjne wykonano ręcznie na każdej regule (Policy, akcje, widoczność, wymazanie, eksport, rollback).
 */
final class WskazowkiOdGotujacychTest extends TestCase
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
        putenv('KUKING_ROLLBACK_KASUJE_WSKAZOWKI');
        parent::tearDown();
    }

    private function przepis(User $autor, array $atrybuty = []): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
            ...$atrybuty,
        ]);
    }

    private function wykonanie(User $kucharz, Recipe $przepis, ?string $notatka = 'Dodałem chrzan, wyszło lepiej.'): CookedEvent
    {
        return CookedEvent::factory()->create([
            'user_id' => $kucharz->getKey(),
            'recipe_id' => $przepis->getKey(),
            'note' => $notatka,
            'cooked_at' => now()->subDays(3),
        ]);
    }

    /** Prośba, którą autor naprawdę wysłał przez ekran. */
    private function popros(User $autor, CookedEvent $wykonanie): RecipeHint
    {
        $this->actingAs($autor)->post(route('hints.propose', $wykonanie))->assertRedirect();

        return RecipeHint::query()->where('cooked_event_id', $wykonanie->getKey())->sole();
    }

    private function zgodz(User $kucharz, RecipeHint $h): void
    {
        $this->actingAs($kucharz)->post(route('hints.accept', $h))->assertRedirect();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Prośba: tylko autor, tylko z notatki, bez blokady
    // ─────────────────────────────────────────────────────────────────────

    public function test_autor_prosi_o_zgode_kucharz_dostaje_powiadomienie_a_przepis_nic_nie_pokazuje(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek', ['display_name' => 'Marek']);
        $przepis = $this->przepis($autor);
        $wykonanie = $this->wykonanie($kucharz, $przepis);

        $h = $this->popros($autor, $wykonanie);

        $this->assertSame(RecipeHint::STATUS_PROPOSED, $h->status);
        $this->assertSame([$autor->getKey(), $kucharz->getKey(), $przepis->getKey()], [$h->author_id, $h->cook_id, $h->recipe_id]);

        $powiadomienie = Notification::query()->where('type', Notification::TYPE_HINT_PROPOSED)->sole();
        $this->assertSame($kucharz->getKey(), $powiadomienie->user_id);
        $this->assertSame($autor->getKey(), $powiadomienie->actor_id);
        $this->assertSame((string) $wykonanie->getKey(), $powiadomienie->data['cooked_event_id']);

        // Brak odpowiedzi = brak publikacji: ani gość, ani autor nie widzą sekcji.
        foreach ([null, $autor, $kucharz] as $widz) {
            $strona = $widz === null ? $this->get(route('recipes.show', $przepis->slug)) : $this->actingAs($widz)->get(route('recipes.show', $przepis->slug));
            $strona->assertOk()->assertDontSee('Wskazówki od gotujących');
        }
    }

    public function test_kucharz_widzi_prosbe_z_tekstem_i_dwoma_przyciskami_a_obcy_i_autor_nie_maja_przyciskow(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $obcy = $this->user('obcy');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($autor));
        $h = $this->popros($autor, $wykonanie);

        $this->actingAs($kucharz)->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertSee('Prośba o zgodę na wskazówkę')
            ->assertSee('Dodałem chrzan, wyszło lepiej.')
            ->assertSee('Zgadzam się')
            ->assertSee(route('hints.accept', $h), false)
            ->assertSee(route('hints.decline', $h), false)
            ->assertSee('Jeśli nie odpowiesz, nic się nie stanie');

        $this->actingAs($autor)->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertSee('Czeka na odpowiedź osoby, która ugotowała')
            ->assertDontSee(route('hints.accept', $h), false)
            ->assertDontSee('Poproś o zgodę');

        $this->actingAs($obcy)->get(route('cooked.show', $wykonanie))
            ->assertOk()
            ->assertDontSee(route('hints.accept', $h), false)
            ->assertDontSee('Czeka na odpowiedź osoby');
    }

    public function test_autor_widzi_przycisk_prosby_tylko_przy_wykonaniu_z_notatka(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $przepis = $this->przepis($autor);
        $zNotatka = $this->wykonanie($kucharz, $przepis);
        $bezNotatki = $this->wykonanie($this->user('basia'), $przepis, null);

        $this->actingAs($autor)->get(route('cooked.show', $zNotatka))->assertSee('Poproś o zgodę');
        $this->actingAs($autor)->get(route('cooked.show', $bezNotatki))->assertDontSee('Poproś o zgodę');

        $this->actingAs($autor)->post(route('hints.propose', $bezNotatki))->assertForbidden();
        $this->assertSame(0, RecipeHint::query()->count());
    }

    public function test_tylko_autor_przepisu_proponuje_obcy_moderator_i_sam_kucharz_dostaja_403(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($autor));

        foreach ([$this->user('obcy'), $this->moderator(), $kucharz] as $ktos) {
            $this->actingAs($ktos)->post(route('hints.propose', $wykonanie))->assertForbidden();
        }
        $this->app['auth']->forgetGuards();
        $this->post(route('hints.propose', $wykonanie))->assertRedirect(route('login'));

        $this->assertSame(0, RecipeHint::query()->count());
        $this->assertSame(0, Notification::query()->where('type', Notification::TYPE_HINT_PROPOSED)->count());

        // Kontrola dodatnia: ta sama trasa dla autora przechodzi.
        $this->popros($autor, $wykonanie);
        $this->assertSame(1, RecipeHint::query()->count());
    }

    public function test_blokada_miedzy_autorem_a_kucharzem_w_obie_strony_nie_pozwala_prosic(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($autor));

        Block::query()->create(['blocker_id' => $autor->getKey(), 'blocked_id' => $kucharz->getKey()]);
        $this->actingAs($autor)->post(route('hints.propose', $wykonanie))->assertForbidden();

        Block::query()->delete();
        Block::query()->create(['blocker_id' => $kucharz->getKey(), 'blocked_id' => $autor->getKey()]);
        $this->actingAs($autor)->post(route('hints.propose', $wykonanie))->assertForbidden();

        $this->assertSame(0, RecipeHint::query()->count());
    }

    public function test_jedno_wykonanie_to_jedna_prosba_i_odmowa_nie_wyglada_inaczej_niz_czekanie(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($autor));
        $h = $this->popros($autor, $wykonanie);

        $this->actingAs($autor)->post(route('hints.propose', $wykonanie))->assertSessionHas('status_rodzaj', 'blad');
        $komunikatCzekajacej = session('status');

        $this->actingAs($kucharz)->post(route('hints.decline', $h))->assertRedirect();
        $this->actingAs($autor)->post(route('hints.propose', $wykonanie))->assertSessionHas('status_rodzaj', 'blad');
        $komunikatPoOdmowie = session('status');

        $this->assertSame(1, RecipeHint::query()->count());
        $this->assertNotNull($komunikatCzekajacej);
        $this->assertSame($komunikatCzekajacej, $komunikatPoOdmowie, 'Autor nie może poznać po komunikacie, że kucharz odmówił.');
    }

    public function test_limit_prosb_na_przepis_i_na_dobe(): void
    {
        config(['kuking.wskazowki.na_przepis_max' => 2, 'kuking.wskazowki.na_dobe_max' => 10]);
        $autor = $this->user('autorka');
        $przepis = $this->przepis($autor);
        $wykonania = collect(range(1, 3))->map(fn (int $i) => $this->wykonanie($this->user("kucharz{$i}"), $przepis));

        $this->popros($autor, $wykonania[0]);
        $this->popros($autor, $wykonania[1]);
        $this->actingAs($autor)->post(route('hints.propose', $wykonania[2]))->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame(2, RecipeHint::query()->count());

        config(['kuking.wskazowki.na_przepis_max' => 10, 'kuking.wskazowki.na_dobe_max' => 2]);
        $this->actingAs($autor)->post(route('hints.propose', $wykonania[2]))->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame(2, RecipeHint::query()->count(), 'Dobowy limit próśb jednego autora.');
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Odpowiedź: tylko kucharz; „Zgadzam się" publikuje, „Nie" nie
    // ─────────────────────────────────────────────────────────────────────

    public function test_zgoda_kucharza_publikuje_wskazowke_z_nazwa_i_linkiem_do_wykonania(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek', ['display_name' => 'Marek']);
        $przepis = $this->przepis($autor);
        $wykonanie = $this->wykonanie($kucharz, $przepis);
        $h = $this->popros($autor, $wykonanie);

        $this->zgodz($kucharz, $h);

        $this->assertSame(RecipeHint::STATUS_ACCEPTED, $h->fresh()->status);
        $this->assertNotNull($h->fresh()->decided_at);

        foreach ([null, $autor, $kucharz, $this->user('obcy')] as $widz) {
            $strona = $widz === null ? $this->get(route('recipes.show', $przepis->slug)) : $this->actingAs($widz)->get(route('recipes.show', $przepis->slug));
            $strona->assertOk()
                ->assertSee('Wskazówki od gotujących')
                ->assertSee('Dodałem chrzan, wyszło lepiej.')
                ->assertSee('Marek')
                ->assertSee(route('cooked.show', $wykonanie), false);
        }
    }

    public function test_odpowiedziec_moze_tylko_kucharz_autor_obcy_i_moderator_dostaja_403_a_stan_sie_nie_zmienia(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $h = $this->popros($autor, $this->wykonanie($kucharz, $this->przepis($autor)));

        foreach ([$autor, $this->user('obcy'), $this->moderator()] as $ktos) {
            foreach (['hints.accept', 'hints.decline', 'hints.withdraw'] as $trasa) {
                $this->actingAs($ktos)->post(route($trasa, $h))->assertForbidden();
            }
        }
        $this->assertSame(RecipeHint::STATUS_PROPOSED, $h->fresh()->status);

        $this->zgodz($kucharz, $h);
        foreach ([$autor, $this->user('obcy2'), $this->moderator()] as $ktos) {
            $this->actingAs($ktos)->post(route('hints.withdraw', $h))->assertForbidden();
        }
        $this->assertSame(RecipeHint::STATUS_ACCEPTED, $h->fresh()->status, 'Autor nie wycofuje zgody za kucharza.');
    }

    public function test_odpowiedz_nie_jest_ostateczna_nic_nie_publikuje_i_nie_powiadamia_autora(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->wykonanie($kucharz, $przepis);
        $h = $this->popros($autor, $wykonanie);
        $powiadomienDlaAutoraPrzed = Notification::query()->where('user_id', $autor->getKey())->count();

        $this->actingAs($kucharz)->post(route('hints.decline', $h))->assertRedirect();

        $this->assertSame(RecipeHint::STATUS_DECLINED, $h->fresh()->status);
        $this->assertSame($powiadomienDlaAutoraPrzed, Notification::query()->where('user_id', $autor->getKey())->count());
        $this->get(route('recipes.show', $przepis->slug))->assertDontSee('Wskazówki od gotujących');

        // Po odmowie nie da się już zgodzić (prośba wygasła) ani poprosić ponownie.
        $this->actingAs($kucharz)->post(route('hints.accept', $h))->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame(RecipeHint::STATUS_DECLINED, $h->fresh()->status);
        $this->actingAs($autor)->post(route('hints.propose', $wykonanie));
        $this->assertSame(1, RecipeHint::query()->count());

        // Autor widzi to samo zdanie co przy wycofanej zgodzie.
        $this->actingAs($autor)->get(route('cooked.show', $wykonanie))
            ->assertSee('Ta uwaga nie jest dostępna jako wskazówka.')
            ->assertDontSee('Poproś o zgodę');
    }

    public function test_wycofanie_zgody_usuwa_wskazowke_ze_strony_przepisu_i_jest_ostateczne(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->wykonanie($kucharz, $przepis);
        $h = $this->popros($autor, $wykonanie);
        $this->zgodz($kucharz, $h);
        $this->get(route('recipes.show', $przepis->slug))->assertSee('Wskazówki od gotujących');

        $this->actingAs($kucharz)->post(route('hints.withdraw', $h))->assertRedirect();

        $h = $h->fresh();
        $this->assertSame(RecipeHint::STATUS_WITHDRAWN, $h->status);
        $this->assertNotNull($h->withdrawn_at);
        $this->get(route('recipes.show', $przepis->slug))
            ->assertDontSee('Wskazówki od gotujących')
            ->assertDontSee('class="wskazowka-cytat', false);
        // Samo wykonanie (dorobek kucharza) zostaje.
        $this->assertNotNull($wykonanie->fresh());

        // Ostateczne: ani ponowna zgoda, ani ponowna prośba.
        $this->actingAs($kucharz)->post(route('hints.accept', $h))->assertSessionHas('status_rodzaj', 'blad');
        $this->actingAs($autor)->post(route('hints.propose', $wykonanie));
        $this->assertSame(RecipeHint::STATUS_WITHDRAWN, $h->fresh()->status);
        $this->assertSame(1, RecipeHint::query()->count());
    }

    public function test_podwojne_klikniecia_sa_idempotentne_a_odpowiedz_przeciwna_do_udzielonej_odmawia(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $h = $this->popros($autor, $this->wykonanie($kucharz, $this->przepis($autor)));

        $this->zgodz($kucharz, $h);
        $decyzja = $h->fresh()->decided_at;
        Carbon::setTestNow(now()->addHour());
        $this->zgodz($kucharz, $h);
        $this->assertEquals($decyzja, $h->fresh()->decided_at, 'Drugie „Zgadzam się" niczego nie zmienia.');

        // „Nie" po zgodzie nie unieważnia jej po cichu — od tego jest „Wycofaj zgodę".
        $this->actingAs($kucharz)->post(route('hints.decline', $h))->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame(RecipeHint::STATUS_ACCEPTED, $h->fresh()->status);

        $this->actingAs($kucharz)->post(route('hints.withdraw', $h))->assertRedirect();
        $wycofano = $h->fresh()->withdrawn_at;
        Carbon::setTestNow(now()->addHour());
        $this->actingAs($kucharz)->post(route('hints.withdraw', $h))->assertRedirect();
        $this->assertEquals($wycofano, $h->fresh()->withdrawn_at);
    }

    public function test_blokada_po_prosbie_zabiera_zgode_ale_zostawia_kucharzowi_nie_i_wycofanie(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($autor));
        $h = $this->popros($autor, $wykonanie);
        Block::query()->create(['blocker_id' => $autor->getKey(), 'blocked_id' => $kucharz->getKey()]);

        $this->actingAs($kucharz)->post(route('hints.accept', $h))->assertSessionHas('status_rodzaj', 'blad');
        $this->assertSame(RecipeHint::STATUS_PROPOSED, $h->fresh()->status, 'Blokada między nimi = brak zgody.');

        $this->actingAs($kucharz)->post(route('hints.decline', $h))->assertRedirect();
        $this->assertSame(RecipeHint::STATUS_DECLINED, $h->fresh()->status, 'Odmowa nic nie publikuje, więc zostaje.');
    }

    public function test_wycofanie_zgody_dziala_mimo_blokady_rod_o_art_7_ust_3(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $h = $this->popros($autor, $this->wykonanie($kucharz, $this->przepis($autor)));
        $this->zgodz($kucharz, $h);
        Block::query()->create(['blocker_id' => $kucharz->getKey(), 'blocked_id' => $autor->getKey()]);

        $this->actingAs($kucharz)->post(route('hints.withdraw', $h))->assertRedirect();

        $this->assertSame(RecipeHint::STATUS_WITHDRAWN, $h->fresh()->status);
    }

    public function test_zawieszony_autor_nie_prosi_a_zawieszony_kucharz_nie_zgadza_sie_ale_moze_odmowic_i_wycofac(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $przepis = $this->przepis($autor);
        $h = $this->popros($autor, $this->wykonanie($kucharz, $przepis));
        $drugie = $this->wykonanie($this->user('basia'), $przepis);
        $trzecie = $this->wykonanie($this->user('celina'), $przepis);
        $hTrzecia = $this->popros($autor, $trzecie);

        $autor->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->actingAs($autor)->post(route('hints.propose', $drugie))->assertRedirect();
        $this->assertSame(2, RecipeHint::query()->count(), 'Zawieszony autor nie wysyła nowych próśb.');
        $autor->forceFill(['status' => User::STATUS_ACTIVE])->save();

        $kucharz->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->actingAs($kucharz)->post(route('hints.accept', $h))->assertRedirect();
        $this->assertSame(RecipeHint::STATUS_PROPOSED, $h->fresh()->status, 'Zawieszony kucharz nie publikuje.');
        $this->actingAs($kucharz)->post(route('hints.decline', $h))->assertRedirect();
        $this->assertSame(RecipeHint::STATUS_DECLINED, $h->fresh()->status);

        // Wycofanie zgody zostaje zawieszonemu (RODO art. 7 ust. 3).
        $kucharz->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->zgodz(User::query()->findOrFail($hTrzecia->cook_id), $hTrzecia);
        $celina = User::query()->findOrFail($hTrzecia->cook_id);
        $celina->forceFill(['status' => User::STATUS_SUSPENDED])->save();
        $this->actingAs($celina)->post(route('hints.withdraw', $hTrzecia))->assertRedirect();
        $this->assertSame(RecipeHint::STATUS_WITHDRAWN, $hTrzecia->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Widoczność wskazówki na stronie przepisu
    // ─────────────────────────────────────────────────────────────────────

    public function test_wskazowka_znika_widzowi_zablokowanemu_z_kucharzem_i_gdy_kucharz_jest_zbanowany(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek', ['display_name' => 'Marek']);
        $widz = $this->user('widz');
        $przepis = $this->przepis($autor);
        $h = $this->popros($autor, $this->wykonanie($kucharz, $przepis));
        $this->zgodz($kucharz, $h);

        $this->actingAs($widz)->get(route('recipes.show', $przepis->slug))->assertSee('class="wskazowka-cytat', false);

        Block::query()->create(['blocker_id' => $widz->getKey(), 'blocked_id' => $kucharz->getKey()]);
        $this->actingAs($widz)->get(route('recipes.show', $przepis->slug))
            ->assertDontSee('class="wskazowka-cytat', false)
            ->assertDontSee('Wskazówki od gotujących');
        // Inni widzą dalej.
        $this->actingAs($this->user('inny'))->get(route('recipes.show', $przepis->slug))->assertSee('class="wskazowka-cytat', false);
        Block::query()->delete();

        $kucharz->forceFill(['status' => User::STATUS_BANNED])->save();
        $this->get(route('recipes.show', $przepis->slug))->assertDontSee('class="wskazowka-cytat', false);

        // Zdjęcie bana przywraca wskazówkę samo — dane nie były ruszane.
        $kucharz->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $this->get(route('recipes.show', $przepis->slug))->assertSee('class="wskazowka-cytat', false);
    }

    public function test_wskazowki_ida_w_kolejnosci_zgod_a_nie_wedlug_popularnosci(): void
    {
        $autor = $this->user('autorka');
        $przepis = $this->przepis($autor);
        $a = $this->user('kucharz_a', ['display_name' => 'Anna']);
        $b = $this->user('kucharz_b', ['display_name' => 'Bogdan']);
        $hA = $this->popros($autor, $this->wykonanie($a, $przepis, 'Uwaga Anny.'));
        $hB = $this->popros($autor, $this->wykonanie($b, $przepis, 'Uwaga Bogdana.'));

        // Bogdan zgadza się PIERWSZY, Anna godzinę później — kolejność idzie za zgodą.
        $this->zgodz($b, $hB);
        Carbon::setTestNow(now()->addHour());
        $this->zgodz($a, $hA);

        $html = $this->get(route('recipes.show', $przepis->slug))->getContent();
        $this->assertLessThan(strpos($html, 'Uwaga Anny.'), strpos($html, 'Uwaga Bogdana.'));
    }

    public function test_przepis_zmieniony_po_prosbie_dostaje_adnotacje_przy_wskazowce(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $przepis = $this->przepis($autor);
        RecipeVersion::query()->create(['recipe_id' => $przepis->getKey(), 'editor_id' => $autor->getKey(), 'version_number' => 1, 'snapshot' => []]);
        $h = $this->popros($autor, $this->wykonanie($kucharz, $przepis));
        $this->assertSame(1, $h->recipe_version_number);
        $this->zgodz($kucharz, $h);

        $this->get(route('recipes.show', $przepis->slug))->assertDontSee('Przepis był zmieniany po tej wskazówce.');

        RecipeVersion::query()->create(['recipe_id' => $przepis->getKey(), 'editor_id' => $autor->getKey(), 'version_number' => 2, 'snapshot' => []]);
        $this->get(route('recipes.show', $przepis->slug))->assertSee('Przepis był zmieniany po tej wskazówce.');
    }

    public function test_usuniecie_wykonania_zabiera_wskazowke_z_przepisu(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->wykonanie($kucharz, $przepis);
        $h = $this->popros($autor, $wykonanie);
        $this->zgodz($kucharz, $h);

        $this->actingAs($kucharz)->delete(route('cooked.destroy', $wykonanie))->assertRedirect();

        $this->assertNull($h->fresh());
        $this->get(route('recipes.show', $przepis->slug))->assertDontSee('Wskazówki od gotujących');
    }

    public function test_koszt_zapytan_strony_przepisu_nie_rosnie_z_liczba_wskazowek(): void
    {
        $autor = $this->user('autorka');
        $przepis = $this->przepis($autor);
        $zapytania = function () use ($przepis): int {
            $licznik = 0;
            DB::listen(function () use (&$licznik): void {
                $licznik++;
            });
            $this->get(route('recipes.show', $przepis->slug))->assertOk();

            return $licznik;
        };
        $dodaj = function (int $ile) use ($autor, $przepis): void {
            for ($i = 0; $i < $ile; $i++) {
                $kucharz = $this->user('kucharz'.uniqid());
                $h = $this->popros($autor, $this->wykonanie($kucharz, $przepis, "Uwaga numer {$i}."));
                $this->zgodz($kucharz, $h);
            }
        };

        $dodaj(1);
        $this->app['auth']->forgetGuards();
        $jedna = $zapytania();
        $dodaj(6);
        $this->app['auth']->forgetGuards();
        $siedem = $zapytania();

        $this->assertSame(7, RecipeHint::query()->where('status', RecipeHint::STATUS_ACCEPTED)->count());
        $this->assertLessThanOrEqual($jedna + 1, $siedem, "Wachlarz zapytań: 1 wskazówka = {$jedna}, 7 wskazówek = {$siedem}.");
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Powiadomienie
    // ─────────────────────────────────────────────────────────────────────

    public function test_powiadomienie_o_prosbie_jest_na_liscie_kucharza_i_prowadzi_na_strone_wykonania(): void
    {
        $autor = $this->user('autorka', ['display_name' => 'Halina']);
        $kucharz = $this->user('marek');
        $przepis = $this->przepis($autor, ['title' => 'Rosół babci']);
        $wykonanie = $this->wykonanie($kucharz, $przepis);
        $this->popros($autor, $wykonanie);

        $this->actingAs($kucharz)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Halina prosi o zgodę na wskazówkę')
            ->assertSee('Rosół babci')
            ->assertSee('Twoja uwaga pokaże się tam tylko wtedy, gdy się zgodzisz.');

        $powiadomienie = Notification::query()->where('type', Notification::TYPE_HINT_PROPOSED)->sole();
        $this->actingAs($kucharz)->post(route('notifications.open', $powiadomienie))
            ->assertRedirect(route('cooked.show', $wykonanie).'#wskazowka');

        // Wykonanie usunięte po prośbie: uczciwy tekst, bez „Zobacz" na 404.
        $wykonanie->delete();
        $this->actingAs($kucharz)->get(route('notifications.index'))->assertSee('To wykonanie zostało usunięte.');
        $this->assertNull($powiadomienie->fresh()->adresDocelowy());
    }

    public function test_powiadomienia_nie_dostaje_ten_kto_jest_zablokowany_z_autorem_ani_konto_zamkniete(): void
    {
        // Prośba nie powstaje przy blokadzie (test wyżej); tu — konto kucharza zamknięte po prośbie
        // nie ma jak jej przeczytać, a strażnik NotifyUser nie wysyła nic na zamknięte konto.
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek', ['status' => User::STATUS_BANNED]);
        $wykonanie = $this->wykonanie($kucharz, $this->przepis($autor));

        $this->actingAs($autor)->post(route('hints.propose', $wykonanie))->assertForbidden();
        $this->assertSame(0, Notification::query()->where('type', Notification::TYPE_HINT_PROPOSED)->count());
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Wymazanie konta i eksport
    // ─────────────────────────────────────────────────────────────────────

    public function test_wymazanie_konta_kucharza_usuwa_jego_wskazowki_mimo_zakresu_minimum_a_cudze_zostaja(): void
    {
        $autor = $this->user('autorka');
        $odchodzi = $this->user('odchodzi', ['status' => User::STATUS_PENDING_DELETE, 'delete_requested_at' => now()->subDays(40)]);
        $zostaje = $this->user('zostaje');
        $przepis = $this->przepis($autor);
        // Wskazówki powstają przed zamknięciem konta (status ustawiamy na chwilę aktywny).
        $odchodzi->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $hOdchodzi = $this->popros($autor, $this->wykonanie($odchodzi, $przepis, 'Uwaga odchodzącego.'));
        $this->zgodz($odchodzi, $hOdchodzi);
        $hZostaje = $this->popros($autor, $this->wykonanie($zostaje, $przepis, 'Uwaga zostającego.'));
        $this->zgodz($zostaje, $hZostaje);
        $odchodzi->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertNull($hOdchodzi->fresh(), 'Wskazówka z zgodą wymazanego konta znika.');
        $this->assertNotNull($hZostaje->fresh());
        // Uwaga odchodzącego zostaje w galerii „Komu wyszło" (zakres minimum),
        // ale nie jako wskazówka: w sekcji wskazówek stoi dokładnie jeden cytat.
        $html = $this->get(route('recipes.show', $przepis->slug))->getContent();
        $this->assertSame(1, substr_count($html, 'class="wskazowka-cytat'));
    }

    public function test_wymazanie_konta_autora_usuwa_jego_czekajace_prosby_a_przyjete_zostaja(): void
    {
        $odchodzi = $this->user('autorka', ['status' => User::STATUS_PENDING_DELETE, 'delete_requested_at' => now()->subDays(40)]);
        $odchodzi->forceFill(['status' => User::STATUS_ACTIVE])->save();
        $przepis = $this->przepis($odchodzi);
        $a = $this->user('kucharz_a');
        $b = $this->user('kucharz_b');
        $czekajaca = $this->popros($odchodzi, $this->wykonanie($a, $przepis));
        $przyjeta = $this->popros($odchodzi, $this->wykonanie($b, $przepis));
        $this->zgodz($b, $przyjeta);
        $odchodzi->forceFill(['status' => User::STATUS_PENDING_DELETE])->save();

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertNull($czekajaca->fresh(), 'Kucharz nie ma odpowiadać osobie, której już nie ma.');
        $this->assertNotNull($przyjeta->fresh(), 'Zgoda kucharza zostaje, dopóki sam jej nie wycofa.');
    }

    public function test_eksport_obu_stron_nie_zawiera_cudzych_danych(): void
    {
        $autor = $this->user('autorka', ['display_name' => 'Halina Autorka']);
        $kucharz = $this->user('marek', ['display_name' => 'Marek Kucharz']);
        $przepis = $this->przepis($autor, ['title' => 'Rosół babci']);
        $wykonanie = $this->wykonanie($kucharz, $przepis, 'Dodałem chrzan do wywaru.');
        $h = $this->popros($autor, $wykonanie);
        $inne = $this->wykonanie($this->user('basia'), $przepis, 'Uwaga Basi.');
        $odrzucona = $this->popros($autor, $inne);
        $this->actingAs(User::query()->findOrFail($odrzucona->cook_id))->post(route('hints.decline', $odrzucona));
        $this->zgodz($kucharz, $h);

        $kucharzDane = app(CollectUserExportData::class)->handle($kucharz, new ExportPhotoPlan($kucharz), now());
        $this->assertCount(1, $kucharzDane['wskazowki_z_moich_wykonan']);
        $this->assertSame('Dodałem chrzan do wywaru.', $kucharzDane['wskazowki_z_moich_wykonan'][0]['moja_uwaga']);
        $this->assertSame('zgoda udzielona, wskazówka stoi przy przepisie', $kucharzDane['wskazowki_z_moich_wykonan'][0]['stan']);
        $this->assertSame('Rosół babci', $kucharzDane['wskazowki_z_moich_wykonan'][0]['przepis']);
        $this->assertSame([], $kucharzDane['wskazowki_do_moich_przepisow']);

        $autorDane = app(CollectUserExportData::class)->handle($autor, new ExportPhotoPlan($autor), now());
        $this->assertCount(2, $autorDane['wskazowki_do_moich_przepisow']);
        $this->assertSame([], $autorDane['wskazowki_z_moich_wykonan']);
        $json = json_encode($autorDane['wskazowki_do_moich_przepisow'], JSON_UNESCAPED_UNICODE);
        foreach (['Marek Kucharz', 'Dodałem chrzan', 'Uwaga Basi.', 'declined', 'withdrawn'] as $cudze) {
            $this->assertStringNotContainsString($cudze, $json, "Do paczki autora wyciekło: {$cudze}");
        }
        $stany = array_column($autorDane['wskazowki_do_moich_przepisow'], 'stan');
        sort($stany);
        $this->assertSame(['nie jest dostępna jako wskazówka', 'stoi przy przepisie jako wskazówka'], $stany);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Baza: CHECK-i, unikalność, pola sterujące, rollback
    // ─────────────────────────────────────────────────────────────────────

    public function test_pola_sterujace_nie_wchodza_masowym_przypisaniem(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');

        $h = null;
        try {
            $h = new RecipeHint([
                'status' => RecipeHint::STATUS_ACCEPTED,
                'author_id' => $autor->getKey(),
                'cook_id' => $kucharz->getKey(),
                'recipe_id' => $this->przepis($autor)->getKey(),
                'decided_at' => now(),
            ]);
        } catch (MassAssignmentException) {
            // Tryb ścisły odmawia głośno; poza nim pola giną po cichu.
        }

        $this->assertSame([], (new RecipeHint)->getFillable());
        $this->assertSame([null, null, null, null, null], [
            $h?->status, $h?->author_id, $h?->cook_id, $h?->recipe_id, $h?->decided_at,
        ]);
    }

    public function test_baza_pilnuje_spojnosci_stanu_unikalnosci_i_autora_innego_niz_kucharz(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('marek');
        $przepis = $this->przepis($autor);
        $wykonanie = $this->wykonanie($kucharz, $przepis);
        $baza = fn (array $nad = []) => array_merge([
            'id' => (string) Str::uuid(),
            'recipe_id' => $przepis->getKey(), 'cooked_event_id' => $wykonanie->getKey(),
            'author_id' => $autor->getKey(), 'cook_id' => $kucharz->getKey(),
            'status' => 'proposed', 'decided_at' => null, 'withdrawn_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ], $nad);

        foreach ([
            'recipe_hints_s' => ['status' => 'inny'],
            'recipe_hints_stan_spojny_check' => ['status' => 'accepted'],
            'recipe_hints_stan_spojny_check ' => ['status' => 'proposed', 'decided_at' => now()],
            'recipe_hints_stan_spojny_check  ' => ['status' => 'withdrawn', 'decided_at' => now()],
            'recipe_hints_autor_nie_kucharz_check' => ['cook_id' => $autor->getKey()],
            'recipe_hints_wersja_check' => ['recipe_version_number' => 0],
        ] as $nazwa => $wiersz) {
            try {
                DB::transaction(fn () => DB::table('recipe_hints')->insert($baza($wiersz)));
                $this->fail('Baza przyjęła wiersz łamiący '.trim($nazwa));
            } catch (QueryException $e) {
                $this->assertStringContainsString(trim($nazwa), $e->getMessage());
            }
        }

        // Kontrola dodatnia + unikalność wykonania.
        DB::table('recipe_hints')->insert($baza());
        try {
            DB::transaction(fn () => DB::table('recipe_hints')->insert($baza()));
            $this->fail('Baza przyjęła drugą wskazówkę do tego samego wykonania.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('recipe_hints_cooked_event_unique', $e->getMessage());
        }
    }

    public function test_cofniecie_migracji_odmawia_przy_odpowiedziach_ludzi_i_przechodzi_na_pustej(): void
    {
        $migracja = require base_path('database/migrations/2026_10_01_160000_create_recipe_hints_table.php');
        $autor = $this->user('autorka');
        $this->popros($autor, $this->wykonanie($this->user('marek'), $this->przepis($autor)));

        try {
            $migracja->down();
            $this->fail('Rollback skasował odpowiedzi ludzi bez pytania.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Liczba wierszy, które znikną: 1.', $e->getMessage());
            $this->assertStringContainsString('KUKING_ROLLBACK_KASUJE_WSKAZOWKI=1', $e->getMessage());
        }
        $this->assertSame(1, DB::table('recipe_hints')->count());

        // Kontrola dodatnia: świadome wymuszenie przechodzi, a pusta tabela — bez pytania.
        putenv('KUKING_ROLLBACK_KASUJE_WSKAZOWKI=1');
        $migracja->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('recipe_hints'));
        putenv('KUKING_ROLLBACK_KASUJE_WSKAZOWKI');

        $migracja->up();
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('recipe_hints'));
        $migracja->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('recipe_hints'), 'Na pustej tabeli rollback ma przechodzić bez pytania.');
        $migracja->up();
    }
}

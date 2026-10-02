<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Planer\Actions\DodajZestawDoPlanu;
use App\Domain\Planer\PlanerTygodnia;
use App\Models\Block;
use App\Models\Collection;
use App\Models\CookedEvent;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\ShoppingListItem;
use App\Models\User;
use App\Support\Czas;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * „Zaplanuj wybrane przepisy" z własnego zeszytu na jeden dzień Planera
 * (V2, #2483): wybór -> podgląd -> jawne zatwierdzenie.
 */
class ZeszytDoPlaneraTest extends TestCase
{
    use RefreshDatabase;

    private User $wlasciciel;

    private User $autor;

    private Collection $zeszyt;

    /** @var list<Recipe> */
    private array $przepisy = [];

    private string $dzien;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wlasciciel = $this->user('planujaca');
        $this->autor = $this->user('autor_zupy');
        $this->zeszyt = Collection::create(['owner_id' => $this->wlasciciel->getKey(), 'name' => 'Niedzielny obiad', 'visibility' => 'private']);
        $this->dzien = CarbonImmutable::parse(Czas::dzisiajData())->addDays(3)->toDateString();

        foreach (['Zupa pomidorowa', 'Pieczeń z sosem', 'Szarlotka'] as $tytul) {
            $this->przepisy[] = $this->wDoZeszytu($tytul);
        }
    }

    private function wDoZeszytu(string $tytul, array $atrybuty = []): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $this->autor->getKey(),
            'title' => $tytul,
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            ...$atrybuty,
        ]);
        $this->zeszyt->recipes()->attach($przepis->getKey(), ['added_by_id' => $this->wlasciciel->getKey()]);

        return $przepis;
    }

    /** @param  list<Recipe>  $przepisy */
    private function ids(array $przepisy): array
    {
        return array_map(fn (Recipe $p): string => (string) $p->getKey(), $przepisy);
    }

    private function podglad(?array $przepisy = null, ?string $dzien = null, ?User $kto = null)
    {
        return $this->actingAs($kto ?? $this->wlasciciel)->post(route('collections.planer.podglad', $this->zeszyt), [
            'dzien' => $dzien ?? $this->dzien,
            'przepisy' => $przepisy ?? $this->ids($this->przepisy),
        ]);
    }

    private function zatwierdz(string $odcisk, ?array $przepisy = null, ?string $dzien = null, ?User $kto = null)
    {
        return $this->actingAs($kto ?? $this->wlasciciel)->post(route('collections.planer.store', $this->zeszyt), [
            'dzien' => $dzien ?? $this->dzien,
            'przepisy' => $przepisy ?? $this->ids($this->przepisy),
            'odcisk' => $odcisk,
        ]);
    }

    private function odcisk(?array $przepisy = null, ?string $dzien = null): string
    {
        $html = (string) $this->podglad($przepisy, $dzien)->assertOk()->getContent();
        preg_match('/name="odcisk" value="([0-9a-f]{64})"/', $html, $m);

        return $m[1] ?? '';
    }

    private function wPlanie(): int
    {
        return MealPlanEntry::query()->where('user_id', $this->wlasciciel->getKey())->count();
    }

    public function test_formularz_pokazuje_przepisy_zeszytu_ze_zdefiniowanym_zakresem_i_bez_domyslnej_daty(): void
    {
        $this->actingAs($this->wlasciciel)->get(route('collections.planer', $this->zeszyt))
            ->assertOk()
            ->assertSee('Zupa pomidorowa')
            ->assertSee('Pieczeń z sosem')
            ->assertSee('Szarlotka')
            ->assertSee('Wybierasz spośród przepisów widocznych na tej stronie:')
            ->assertSee('1–3 z 3')
            ->assertSee('name="dzien"', false)
            ->assertDontSee('value="'.$this->dzien.'"', false);
    }

    public function test_przy_paginacji_wybor_dotyczy_widocznej_strony_i_nie_ma_wybierz_wszystkie(): void
    {
        foreach (range(1, 12) as $i) {
            $this->wDoZeszytu('Dodatkowy przepis '.$i);
        }

        $strona1 = $this->actingAs($this->wlasciciel)->get(route('collections.planer', $this->zeszyt))->assertOk();
        $strona1->assertSee('1–12 z 15')->assertDontSee('Wybierz wszystkie');
        $this->assertSame(12, substr_count((string) $strona1->getContent(), 'name="przepisy[]"'));

        $strona2 = $this->actingAs($this->wlasciciel)->get(route('collections.planer', ['collection' => $this->zeszyt, 'page' => 2]))->assertOk();
        $strona2->assertSee('13–15 z 15');
        $this->assertSame(3, substr_count((string) $strona2->getContent(), 'name="przepisy[]"'));

        // Dopisanie przepisu do zeszytu po wyświetleniu formularza nie wchodzi do wyboru.
        $this->wDoZeszytu('Dopisany po wyświetleniu');
        $this->podglad($this->ids([$this->przepisy[0]]))->assertOk()->assertSee('Dodamy 1 przepis')->assertDontSee('Dopisany po wyświetleniu');
    }

    public function test_przycisk_na_stronie_zeszytu_widzi_tylko_wlasciciel(): void
    {
        $adres = route('collections.planer', $this->zeszyt);

        $this->actingAs($this->wlasciciel)->get(route('collections.show', $this->zeszyt))->assertOk()->assertSee($adres, false);

        $publiczny = Collection::create(['owner_id' => $this->wlasciciel->getKey(), 'name' => 'Publiczny', 'visibility' => 'public']);
        $this->actingAs($this->user('obca'))->get(route('collections.show', $publiczny))->assertOk()->assertDontSee('Zaplanuj wybrane przepisy');
    }

    public function test_cudzy_zeszyt_i_wspolpracownik_dostaja_odmowe_na_wszystkich_krokach(): void
    {
        $obca = $this->user('obca_planerka');

        $this->actingAs($obca)->get(route('collections.planer', $this->zeszyt))->assertForbidden();
        $this->podglad(null, null, $obca)->assertForbidden();
        $this->zatwierdz(str_repeat('a', 64), null, null, $obca)->assertForbidden();
        $this->assertSame(0, MealPlanEntry::query()->count());
    }

    public function test_podglad_pokazuje_nazwy_i_liczbe_nowych_pozycji_i_niczego_nie_zapisuje(): void
    {
        $this->podglad()
            ->assertOk()
            ->assertSee('Dodamy 3 przepisy')
            ->assertSee('Zupa pomidorowa')
            ->assertSee('Szarlotka')
            ->assertSee('Jeszcze nic nie zapisaliśmy.')
            ->assertSee(PlanerTygodnia::naDzien(CarbonImmutable::parse($this->dzien)));

        $this->assertSame(0, $this->wPlanie());
    }

    public function test_zatwierdzenie_dodaje_wybrane_na_wspolny_dzien_i_nie_rusza_zeszytu(): void
    {
        $this->wDoZeszytu('Czwarty przepis niewybrany');
        $wybrane = [$this->przepisy[0], $this->przepisy[2]];
        $odcisk = $this->odcisk($this->ids($wybrane));

        $this->zatwierdz($odcisk, $this->ids($wybrane))
            ->assertRedirect(route('planer.show', ['tydzien' => $this->dzien]));

        $wpisy = MealPlanEntry::query()->where('user_id', $this->wlasciciel->getKey())->get();
        $this->assertCount(2, $wpisy);
        $this->assertEqualsCanonicalizing($this->ids($wybrane), $wpisy->pluck('recipe_id')->all());
        $this->assertSame([$this->dzien], $wpisy->map(fn ($w) => $w->day->toDateString())->unique()->values()->all());
        $this->assertNull($wpisy->first()->label);
        $this->assertSame(4, $this->zeszyt->recipes()->count(), 'Zeszyt zostaje bez zmian.');
    }

    public function test_komunikat_po_zapisie_rozroznia_dodane_i_juz_obecne(): void
    {
        $wpis = new MealPlanEntry(['day' => $this->dzien, 'recipe_id' => $this->przepisy[0]->getKey()]);
        $wpis->user_id = $this->wlasciciel->getKey();
        $wpis->save();

        $odcisk = $this->odcisk();

        $this->zatwierdz($odcisk)
            ->assertRedirect()
            ->assertSessionHas('status', fn ($tekst) => str_contains((string) $tekst, 'Dodane do planu na')
                && str_contains((string) $tekst, '2 przepisy')
                && str_contains((string) $tekst, 'Już były w planie i zostały bez zmian: 1.'));
    }

    public function test_przepis_juz_zaplanowany_jest_pomijany_bez_resetu_pozycji(): void
    {
        $istniejaca = new MealPlanEntry(['day' => $this->dzien, 'recipe_id' => $this->przepisy[0]->getKey()]);
        $istniejaca->user_id = $this->wlasciciel->getKey();
        $istniejaca->save();
        $istniejaca->forceFill(['note' => 'kolacja', 'done_at' => now()])->save();

        $odcisk = $this->odcisk();
        $this->podglad()->assertSee('Dodamy 2 przepisy')->assertSee('Już są w tym dniu (1) — zostaną bez zmian');

        $this->zatwierdz($odcisk)->assertRedirect();
        $this->assertSame(3, $this->wPlanie());

        $po = $istniejaca->fresh();
        $this->assertSame('kolacja', $po->note);
        $this->assertNotNull($po->done_at);
        $this->assertSame($istniejaca->created_at?->toIso8601String(), $po->created_at?->toIso8601String());
    }

    public function test_ponowne_wyslanie_nie_mnozy_wpisow(): void
    {
        $odcisk = $this->odcisk();

        $this->zatwierdz($odcisk)->assertRedirect();
        $this->assertSame(3, $this->wPlanie());

        // Stary formularz wysłany drugi raz: zestaw jest już w planie, odcisk się zmienił.
        $this->zatwierdz($odcisk)->assertOk()->assertSee('nic nie zostało dodane');
        $this->assertSame(3, $this->wPlanie());

        // Świeży podgląd i zatwierdzenie: nic nowego.
        $odcisk2 = $this->odcisk();
        $this->zatwierdz($odcisk2)->assertRedirect();
        $this->assertSame(3, $this->wPlanie());
    }

    public function test_przepis_usuniety_z_zeszytu_po_podgladzie_zatrzymuje_zapis_i_nie_zdradza_tytulu(): void
    {
        $odcisk = $this->odcisk();
        $this->zeszyt->recipes()->detach($this->przepisy[1]->getKey());

        $odpowiedz = $this->zatwierdz($odcisk)->assertOk();

        $odpowiedz->assertSee('Zestaw albo dostęp do przepisów zmienił się od chwili podglądu')
            ->assertSee('Pominięte, bo nie są już dostępne w tym zeszycie: 1.')
            ->assertDontSee('Pieczeń z sosem');
        $this->assertSame(0, $this->wPlanie());

        // Ponowne zatwierdzenie świeżego podglądu zapisuje dwa pozostałe.
        $html = (string) $odpowiedz->getContent();
        preg_match('/name="odcisk" value="([0-9a-f]{64})"/', $html, $m);
        $this->zatwierdz($m[1])->assertRedirect();
        $this->assertSame(2, $this->wPlanie());
    }

    public function test_utrata_widocznosci_przepisu_blokuje_zapis_zmienionego_zestawu(): void
    {
        $odcisk = $this->odcisk();
        $this->przepisy[0]->forceFill(['visibility' => 'private'])->save();

        $this->zatwierdz($odcisk)->assertOk()->assertDontSee('Zupa pomidorowa');
        $this->assertSame(0, $this->wPlanie());
    }

    public function test_przepis_za_blokada_i_szkic_nie_trafiaja_do_planu(): void
    {
        $zablokowany = $this->user('zablokowana_autorka');
        Block::create(['blocker_id' => $this->wlasciciel->getKey(), 'blocked_id' => $zablokowany->getKey()]);
        $cudzy = $this->wDoZeszytu('Przepis zablokowanej', ['author_id' => $zablokowany->getKey()]);
        $szkic = $this->wDoZeszytu('Mój szkic', ['author_id' => $this->wlasciciel->getKey(), 'status' => Recipe::STATUS_DRAFT]);

        $this->actingAs($this->wlasciciel)->get(route('collections.planer', $this->zeszyt))
            ->assertDontSee('Przepis zablokowanej')
            ->assertDontSee('Mój szkic');

        $this->podglad($this->ids([$cudzy, $szkic, $this->przepisy[0]]))
            ->assertOk()
            ->assertSee('Dodamy 1 przepis')
            ->assertSee('Pominięte, bo nie są już dostępne w tym zeszycie: 2.')
            ->assertDontSee('Przepis zablokowanej')
            ->assertDontSee('Mój szkic');
    }

    public function test_przepis_spoza_zeszytu_nie_wchodzi_przez_podmieniony_identyfikator(): void
    {
        $obcy = Recipe::factory()->create(['author_id' => $this->autor->getKey(), 'title' => 'Poza zeszytem', 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']);

        $odcisk = $this->odcisk([...$this->ids([$this->przepisy[0]]), (string) $obcy->getKey()]);
        $this->zatwierdz($odcisk, [...$this->ids([$this->przepisy[0]]), (string) $obcy->getKey()])->assertRedirect();

        $this->assertSame([(string) $this->przepisy[0]->getKey()], MealPlanEntry::query()->pluck('recipe_id')->all());
    }

    public function test_limit_dnia_liczy_caly_istniejacy_dzien_i_nie_zapisuje_pierwszych_kilku(): void
    {
        $limit = PlanerTygodnia::wpisowNaDzien();
        for ($i = 0; $i < $limit - 1; $i++) {
            $wpis = new MealPlanEntry(['day' => $this->dzien, 'label' => 'wpis '.$i]);
            $wpis->user_id = $this->wlasciciel->getKey();
            $wpis->save();
        }

        $this->podglad()->assertRedirect(route('collections.planer', $this->zeszyt))->assertSessionHasErrors('przepisy');
        $this->zatwierdz(str_repeat('0', 64))->assertRedirect(route('collections.planer', $this->zeszyt))->assertSessionHasErrors('przepisy');
        $this->assertSame($limit - 1, $this->wPlanie());

        // Jeden nowy mieści się.
        $this->podglad([(string) $this->przepisy[0]->getKey()])->assertOk();
    }

    public function test_blad_zachowuje_date_i_zaznaczenia(): void
    {
        $this->podglad([], $this->dzien)->assertSessionHasErrors('przepisy');

        $this->actingAs($this->wlasciciel)->from(route('collections.planer', $this->zeszyt))
            ->post(route('collections.planer.podglad', $this->zeszyt), ['dzien' => 'zla-data', 'przepisy' => $this->ids($this->przepisy)])
            ->assertSessionHasErrors('dzien');

        $this->actingAs($this->wlasciciel)->withSession(['_old_input' => ['dzien' => $this->dzien, 'przepisy' => [(string) $this->przepisy[1]->getKey()]]])
            ->get(route('collections.planer', $this->zeszyt))
            ->assertSee('value="'.$this->dzien.'"', false)
            ->assertSee('value="'.$this->przepisy[1]->getKey().'" checked', false);
    }

    public function test_dzien_poza_zakresem_planera_jest_odrzucony(): void
    {
        $za_daleko = CarbonImmutable::parse(Czas::dzisiajData())->addDays(400)->toDateString();

        $this->podglad(null, $za_daleko)->assertSessionHasErrors('dzien');
        $this->podglad(null, '2020-01-01')->assertSessionHasErrors('dzien');
        $this->assertSame(0, $this->wPlanie());
    }

    public function test_zmien_wybor_wraca_do_formularza_z_zachowanym_wyborem(): void
    {
        $this->actingAs($this->wlasciciel)->post(route('collections.planer.podglad', $this->zeszyt), [
            'akcja' => 'zmien',
            'dzien' => $this->dzien,
            'przepisy' => $this->ids($this->przepisy),
        ])->assertRedirect(route('collections.planer', $this->zeszyt))->assertSessionHasInput('dzien', $this->dzien);
    }

    public function test_nie_kopiuje_notatek_zeszytu_i_nie_tworzy_zakupow_ani_wykonan(): void
    {
        $this->zeszyt->recipes()->updateExistingPivot($this->przepisy[0]->getKey(), ['note' => 'TAJNA-NOTATKA-ZESZYTU']);
        $odcisk = $this->odcisk();
        $this->zatwierdz($odcisk)->assertRedirect();

        $wpis = MealPlanEntry::query()->where('recipe_id', $this->przepisy[0]->getKey())->sole();
        $this->assertNull($wpis->label);
        $this->assertNull($wpis->getAttribute('note'));
        $this->assertSame(0, ShoppingListItem::query()->count());
        $this->assertSame(0, CookedEvent::query()->count());
    }

    public function test_miejsce_zajete_miedzy_podgladem_a_zatwierdzeniem_cofa_caly_zestaw(): void
    {
        $limit = PlanerTygodnia::wpisowNaDzien();
        for ($i = 0; $i < $limit - 3; $i++) {
            $wpis = new MealPlanEntry(['day' => $this->dzien, 'label' => 'wpis '.$i]);
            $wpis->user_id = $this->wlasciciel->getKey();
            $wpis->save();
        }
        $odcisk = $this->odcisk();

        // Drugi, niezależny zapis zajmuje miejsce między podglądem a zatwierdzeniem.
        $inny = new MealPlanEntry(['day' => $this->dzien, 'label' => 'zajmuje miejsce']);
        $inny->user_id = $this->wlasciciel->getKey();
        $inny->save();

        $this->zatwierdz($odcisk)->assertSessionHasErrors('przepisy');
        $this->assertSame($limit - 2, $this->wPlanie(), 'Transakcja cofnęła cały zestaw, nic nie dopisano.');
    }

    public function test_akcja_domenowa_odmawia_nie_wlascicielowi(): void
    {
        $this->expectException(AuthorizationException::class);

        app(DodajZestawDoPlanu::class)->podglad($this->autor, $this->zeszyt, CarbonImmutable::parse($this->dzien), $this->ids($this->przepisy));
    }
}

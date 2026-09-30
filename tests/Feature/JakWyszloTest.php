<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Domain\Social\Actions\BlockUser;
use App\Models\ProductSignal;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WzorceRodzaju;
use Tests\TestCase;

/**
 * „Jak wyszło?” na Starcie po trybie gotowania (F1, research 30.09.2026, D-333).
 *
 * CO MIERZY
 *  - zdanie pojawia się TYLKO po otwarciu ostatniego kroku (kontrola: krok
 *    przedostatni go nie wywołuje) i tylko u tej osoby, która gotowała;
 *  - znika po „Pokaż zdjęcie”, po „Nie teraz”, po zapisaniu wykonania
 *    (formularzem albo gdziekolwiek indziej) i po trzech dniach, a dla tego
 *    samego gotowania nie wraca;
 *  - przepis, którego osoba nie może już ugotować (blokada), nie wyskakuje;
 *  - sygnały do `kuking:raport` nie niosą konta ani przepisu.
 *
 * Asercje „widać” czytają samą sekcję `jak-wyszlo` (PULAPKI_TESTOW.md §1),
 * asercje „nie widać” — cały dokument, każda z kontrolą dodatnią obok (§4).
 */
final class JakWyszloTest extends TestCase
{
    use RefreshDatabase;

    private const SEKCJA = '~<section class="ramka-pomocnicza jak-wyszlo".*?</section>~s';

    public function test_ostatni_krok_bez_ugotowalem_pyta_na_starcie_raz_na_gotowanie(): void
    {
        [$basia, $rosol] = $this->gotowanie();

        // Kontrola: przedostatni krok niczego nie zapamiętuje.
        $this->actingAs($basia)->get(route('cooking.show', [$rosol->slug, 'krok' => 1]))->assertOk();
        $this->assertNull($this->sekcja($this->start($basia)));

        $this->actingAs($basia)->get(route('cooking.show', [$rosol->slug, 'krok' => 2]))->assertOk();
        $sekcja = $this->sekcja($this->start($basia));

        $this->assertNotNull($sekcja, 'Po ostatnim kroku bez „Ugotowałem” Start nie zapytał, jak wyszło.');
        $this->assertStringContainsString('„Rosół Marka” — jak wyszło?', $sekcja);
        $this->assertStringContainsString(route('jak_wyszlo.pokaz', $rosol->slug), $sekcja);
        $this->assertStringContainsString(route('jak_wyszlo.zamknij', $rosol->slug), $sekcja);
        $this->assertStringContainsString('>Pokaż zdjęcie</button>', $sekcja);
        $this->assertStringContainsString('>Nie teraz</button>', $sekcja);
        // Bez licznika dni i bez presji.
        $this->assertDoesNotMatchRegularExpression('~\d|zapomnij|!~iu', strip_tags($sekcja));

        // Odświeżenie ostatniego kroku i drugi Start: to samo gotowanie — jeden sygnał.
        $this->actingAs($basia)->get(route('cooking.show', [$rosol->slug, 'krok' => 2]))->assertOk();
        $this->assertNotNull($this->sekcja($this->start($basia)));
        $this->assertSame(1, $this->ile(ZapiszSygnal::COOKING_LAST_STEP_REACHED));
        $this->assertSame(1, $this->ile(ZapiszSygnal::COOKING_FOLLOWUP_SHOWN));
    }

    public function test_nie_teraz_zamyka_pytanie_i_nie_wraca_dla_tego_gotowania(): void
    {
        [$basia, $rosol] = $this->gotowanie();
        $this->ostatniKrok($basia, $rosol);
        $this->assertNotNull($this->sekcja($this->start($basia)));

        $this->actingAs($basia)->post(route('jak_wyszlo.zamknij', $rosol->slug))->assertRedirect(route('home'));

        $this->assertNull($this->sekcja($this->start($basia)));
        $this->ostatniKrok($basia, $rosol);
        $this->assertNull($this->sekcja($this->start($basia)), '„Nie teraz” wróciło przy tym samym gotowaniu.');
        $this->assertSame(1, $this->ile(ZapiszSygnal::COOKING_FOLLOWUP_DISMISSED));
        $this->assertSame(1, $this->ile(ZapiszSygnal::COOKING_LAST_STEP_REACHED));
    }

    public function test_pokaz_zdjecie_prowadzi_do_ugotowalem_a_zapis_liczy_sie_jako_po_pytaniu(): void
    {
        [$basia, $rosol] = $this->gotowanie();
        $this->ostatniKrok($basia, $rosol);
        $this->start($basia);

        $this->actingAs($basia)->post(route('jak_wyszlo.pokaz', $rosol->slug))
            ->assertRedirect(route('cooked.create', $rosol->slug));
        $this->assertNull($this->sekcja($this->start($basia)), 'Po „Pokaż zdjęcie” pytanie zostało na Starcie.');

        $this->actingAs($basia)->post(route('cooked.store', $rosol->slug), ['note' => 'Wyszedł klarowny.'])->assertRedirect();

        $sygnal = ProductSignal::query()->where('signal_name', ZapiszSygnal::COOKING_LAST_STEP_COOKED)->sole();
        $this->assertTrue($sygnal->properties['po_pytaniu']);
    }

    public function test_ugotowalem_zaraz_po_ostatnim_kroku_nie_pyta_wcale(): void
    {
        [$basia, $rosol] = $this->gotowanie();
        $this->ostatniKrok($basia, $rosol);

        $this->actingAs($basia)->post(route('cooked.store', $rosol->slug), ['note' => 'Wyszło.'])->assertRedirect();

        $this->assertNull($this->sekcja($this->start($basia)));
        $sygnal = ProductSignal::query()->where('signal_name', ZapiszSygnal::COOKING_LAST_STEP_COOKED)->sole();
        $this->assertFalse($sygnal->properties['po_pytaniu']);
        $this->assertSame(0, $this->ile(ZapiszSygnal::COOKING_FOLLOWUP_SHOWN));
    }

    public function test_wykonanie_zapisane_gdzie_indziej_tez_zamyka_pytanie(): void
    {
        [$basia, $rosol] = $this->gotowanie();
        $this->ostatniKrok($basia, $rosol);
        $this->assertNotNull($this->sekcja($this->start($basia)));

        // Np. z telefonu, w innej sesji — tędy idzie każda droga zapisu.
        $this->travel(1)->minutes();
        app(RecordCookedEvent::class)->handle($basia, $rosol);

        $this->assertNull($this->sekcja($this->start($basia)));
        $this->assertSame(1, $this->ile(ZapiszSygnal::COOKING_LAST_STEP_COOKED));
    }

    public function test_po_trzech_dniach_pytanie_znika(): void
    {
        [$basia, $rosol] = $this->gotowanie();
        $this->ostatniKrok($basia, $rosol);

        $this->travel(3)->days();
        $this->travel(-1)->minutes();
        $this->assertNotNull($this->sekcja($this->start($basia)), 'Kontrola dodatnia: tuż przed trzecią dobą pytanie jeszcze jest.');

        $this->travel(2)->minutes();
        $this->assertNull($this->sekcja($this->start($basia)));
    }

    public function test_nowe_gotowanie_po_ugotowalem_pyta_od_nowa(): void
    {
        [$basia, $rosol] = $this->gotowanie();
        $this->ostatniKrok($basia, $rosol);
        $this->actingAs($basia)->post(route('cooked.store', $rosol->slug), ['note' => 'Pierwszy raz.'])->assertRedirect();

        $this->travel(1)->days();
        $this->ostatniKrok($basia, $rosol);

        $this->assertNotNull($this->sekcja($this->start($basia)));
        $this->assertSame(2, $this->ile(ZapiszSygnal::COOKING_LAST_STEP_REACHED));
    }

    public function test_cudze_gotowanie_z_tej_samej_sesji_nie_wyskakuje_innej_osobie(): void
    {
        [$basia, $rosol] = $this->gotowanie();
        $this->ostatniKrok($basia, $rosol);
        $halina = $this->user('halina');

        // Ta sama sesja testu, inne konto — jak wspólny tablet bez wylogowania.
        $this->assertNull($this->sekcja($this->start($halina)));
        $this->assertNotNull($this->sekcja($this->start($basia)), 'Kontrola dodatnia: gotująca osoba nadal widzi pytanie.');
    }

    public function test_przepis_po_blokadzie_nie_wyskakuje(): void
    {
        [$basia, $rosol, $marek] = $this->gotowanie();
        $this->ostatniKrok($basia, $rosol);
        $this->assertNotNull($this->sekcja($this->start($basia)));

        app(BlockUser::class)->handle($marek, $basia);

        $this->assertNull($this->sekcja($this->start($basia)));
    }

    public function test_gosc_na_ostatnim_kroku_niczego_nie_zapisuje(): void
    {
        [, $rosol] = $this->gotowanie();

        $this->get(route('cooking.show', [$rosol->slug, 'krok' => 2]))->assertOk();

        $this->assertSame(0, ProductSignal::query()->count());
    }

    public function test_sygnaly_nie_nosza_konta_ani_przepisu(): void
    {
        [$basia, $rosol] = $this->gotowanie();
        $this->ostatniKrok($basia, $rosol);
        $this->start($basia);
        $this->actingAs($basia)->post(route('jak_wyszlo.zamknij', $rosol->slug));

        $sygnaly = ProductSignal::query()->get();

        $this->assertCount(3, $sygnaly);
        foreach ($sygnaly as $sygnal) {
            $this->assertNull($sygnal->user_id, $sygnal->signal_name.' niesie konto.');
            $this->assertSame([], (array) $sygnal->properties, $sygnal->signal_name.' niesie właściwości.');
        }
    }

    public function test_przyciski_nie_przepuszczaja_przepisu_ktorego_osoba_nie_widzi(): void
    {
        [$basia, $rosol] = $this->gotowanie();
        $rosol->forceFill(['visibility' => 'private'])->save();

        $this->actingAs($basia)->post(route('jak_wyszlo.zamknij', $rosol->slug))->assertForbidden();
        $this->actingAs($basia)->post(route('jak_wyszlo.pokaz', $rosol->slug))->assertForbidden();
    }

    public function test_zdanie_jest_bez_rodzaju(): void
    {
        [$basia, $rosol] = $this->gotowanie();
        $this->ostatniKrok($basia, $rosol);
        $sekcja = (string) $this->sekcja($this->start($basia));

        $this->assertNotSame('', $sekcja);
        $this->assertSame([], WzorceRodzaju::trafienia(strip_tags($sekcja)));
    }

    /** @return array{0: User, 1: Recipe, 2: User} */
    private function gotowanie(): array
    {
        $marek = $this->user('marek', ['display_name' => 'Marek']);
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        $rosol = Recipe::factory()->create(['author_id' => $marek->getKey(), 'title' => 'Rosół Marka']);
        RecipeStep::create(['recipe_id' => $rosol->getKey(), 'position' => 0, 'instruction' => 'Zalej mięso wodą.']);
        RecipeStep::create(['recipe_id' => $rosol->getKey(), 'position' => 1, 'instruction' => 'Gotuj na małym ogniu.']);

        return [$basia, $rosol, $marek];
    }

    private function ostatniKrok(User $osoba, Recipe $przepis): void
    {
        $this->actingAs($osoba)->get(route('cooking.show', [$przepis->slug, 'krok' => 2]))->assertOk();
    }

    private function start(User $osoba): string
    {
        return (string) $this->actingAs($osoba)->get(route('home'))->assertOk()->getContent();
    }

    private function sekcja(string $html): ?string
    {
        return preg_match(self::SEKCJA, $html, $m) === 1 ? $m[0] : null;
    }

    private function ile(string $nazwa): int
    {
        return ProductSignal::query()->where('signal_name', $nazwa)->count();
    }
}

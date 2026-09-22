<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sekcja „Komu wyszło" w układzie z UI kit v2 (ekrany 02/06, etap C).
 *
 * Trzy rzeczy, które ten plik pilnuje osobno od `KomuWyszloWydajnoscTest`
 * (tamten mierzy zapytania i paginację, ten mierzy TEKST i UCZCIWOŚĆ liczb):
 *
 *  1. nagłówek zostaje „Komu wyszło" (COPY_STYLE.md §6), nie „Jak wyszło
 *     innym?" z samej makiety kitu (konflikt rozstrzygnięty 7 IX 2026);
 *  2. pasek liczb pokazuje PRAWDZIWY stan całego przepisu, nie stan jednej
 *     strony — inaczej przepis z 30 wykonaniami, z których strona pokazuje
 *     12, kłamałby liczbą tuż nad tymi 12 kartami;
 *  3. pusty stan C3 (SOUL 4.2) zostaje, kiedy wykonań jest zero.
 */
class KomuWyszloUkladTest extends TestCase
{
    use RefreshDatabase;

    private function opublikowanyPrzepis(): Recipe
    {
        $autor = $this->user('kucharka');

        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);
    }

    private function dodajWykonanie(Recipe $przepis, ?bool $zrobiPonownie, int $i): void
    {
        CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $this->user('gosc'.$i)->getKey(),
            'note' => 'Notatka numer '.$i,
            'would_make_again' => $zrobiPonownie,
            'cooked_at' => now()->subMinutes($i),
        ]);
    }

    public function test_naglowek_zostaje_komu_wyszlo_nie_jak_wyszlo_innym(): void
    {
        $przepis = $this->opublikowanyPrzepis();
        $this->dodajWykonanie($przepis, true, 1);

        $odpowiedz = $this->get(route('recipes.show', $przepis->slug))->assertOk();

        $odpowiedz->assertSee('Komu wyszło', escape: false);
        $odpowiedz->assertDontSee('Jak wyszło innym', escape: false);
    }

    /** C3 (SOUL 4.2): zero wykonań NIE usuwa sekcji, tylko pokazuje ten tekst. */
    public function test_zero_wykonan_pokazuje_pusty_stan_c3(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        $odpowiedz = $this->get(route('recipes.show', $przepis->slug))->assertOk();

        $odpowiedz->assertSee('Komu wyszło', escape: false);
        $odpowiedz->assertSee('Jeszcze nikt tego nie gotował', escape: false);
        $odpowiedz->assertSee('Twoje wykonanie będzie pierwsze.', escape: false);
        // Bez paska liczb, kiedy nie ma czego liczyć — „0 osób ugotowało"
        // byłoby tym samym rozminięciem się z prawdą, przed którym ostrzega
        // C3, tylko w innej formie.
        $odpowiedz->assertDontSee('osób ugotowało', escape: false);
    }

    /**
     * PASEK LICZB MÓWI PRAWDĘ O CAŁOŚCI, NIE O STRONIE.
     *
     * Trzydzieści wykonań w bazie, strona pokazuje 12 (paginacja) —
     * pasek liczb musi mówić „30", nie „12". Dokładnie ten sam wymóg,
     * który `KomentarzeStronamiTest` sprawdza dla nagłówka komentarzy.
     */
    public function test_pasek_liczb_liczy_wszystkie_wykonania_nie_tylko_strone(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        for ($i = 0; $i < 30; $i++) {
            $this->dodajWykonanie($przepis, true, $i);
        }

        $tresc = (string) $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/30\s+wykonań/u',
            $tresc,
            'Pasek liczb ma mówić o WSZYSTKICH 30 wykonaniach, nie o 12 pokazanych na stronie.',
        );
    }

    /** Odmiana liczebnika idzie przez `App\Support\Odmiana` (issue #86) — jedno wykonanie. */
    public function test_pasek_liczb_odmienia_liczebnik_dla_jednego_wykonania(): void
    {
        $przepis = $this->opublikowanyPrzepis();
        $this->dodajWykonanie($przepis, true, 1);

        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('1 wykonanie', escape: false);
    }

    /** ...i dla kilku wykonań (2-4) — inna forma niż „5+". */
    public function test_pasek_liczb_odmienia_liczebnik_dla_kilku_wykonan(): void
    {
        $przepis = $this->opublikowanyPrzepis();
        $this->dodajWykonanie($przepis, true, 1);
        $this->dodajWykonanie($przepis, true, 2);
        $this->dodajWykonanie($przepis, true, 3);

        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('3 wykonania', escape: false);
    }

    /**
     * PROCENT DOPIERO OD TRZECH OCEN — ten sam próg, co znaczek nad zdjęciem
     * („SOUL 4.2: poniżej trzech jedna opinia waży za dużo").
     */
    public function test_procent_zrobi_ponownie_nie_pokazuje_sie_ponizej_trzech_ocen(): void
    {
        $przepis = $this->opublikowanyPrzepis();
        $this->dodajWykonanie($przepis, true, 1);
        $this->dodajWykonanie($przepis, true, 2);

        $tresc = (string) $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Zrobię ponownie:', $tresc);
        $this->assertStringNotContainsString('odpowiedzi: „Zrobię ponownie”', $tresc);
    }

    /** Od trzech ocen procent MUSI być tym, co naprawdę wyszło z policzenia. */
    public function test_procent_zrobi_ponownie_jest_prawdziwie_policzony(): void
    {
        $przepis = $this->opublikowanyPrzepis();
        // 2 z 3 zrobi ponownie → 66.67%, zaokrąglone do 67%.
        $this->dodajWykonanie($przepis, true, 1);
        $this->dodajWykonanie($przepis, true, 2);
        $this->dodajWykonanie($przepis, false, 3);

        $tresc = (string) $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('67% odpowiedzi: „Zrobię ponownie”', $tresc);
    }

    /**
     * Blokady (issue #41) nadal działają na paginowanej liście wykonań —
     * ten sam wymóg co `KomentarzeStronamiTest::test_paginacja_nie_gubi_filtra_blokad`,
     * tylko dla galerii „Komu wyszło".
     */
    public function test_paginacja_wykonan_nie_gubi_filtra_blokad(): void
    {
        $przepis = $this->opublikowanyPrzepis();

        $blokujacy = $this->user('blokujaca');
        $niechciany = $this->user('niechciana');

        CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $niechciany->getKey(),
            'note' => 'Wykonanie od kogoś zablokowanego',
        ]);
        CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $this->user('zwykla_osoba')->getKey(),
            'note' => 'Zwykłe wykonanie',
        ]);

        $blokujacy->blocking()->attach($niechciany->getKey(), ['created_at' => now()]);

        $odpowiedz = $this->actingAs($blokujacy)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk();

        $odpowiedz->assertDontSee('Wykonanie od kogoś zablokowanego', escape: false);
        // KONTROLA: pozostałe wykonanie nadal widoczne — filtr nie wyciął
        // całej galerii.
        $odpowiedz->assertSee('Zwykłe wykonanie', escape: false);
        // I LICZBA w pasku liczb zgadza się z tym, co widz naprawdę widzi
        // (jedno wykonanie), nie z tym, co jest w bazie (dwa) — ten sam
        // wymóg, który komentarz w kontrolerze nazywa „oracle istnienia".
        $odpowiedz->assertSee('1 wykonanie', escape: false);
    }
}

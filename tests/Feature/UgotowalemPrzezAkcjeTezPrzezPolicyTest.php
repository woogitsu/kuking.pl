<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Wejście domenowe „Ugotowałem" przechodzi przez tę samą Policy co żądanie
 * HTTP — znalezisko G12 z audytu zewnętrznego.
 *
 * `AGENTS.md` §7: „UUID w adresie to nie autoryzacja — każde wejście przez
 * Policy". Litera tej zasady mówi o adresie, ale sens jest szerszy: to
 * `RecipePolicy` rozstrzyga, kto może ugotować dany przepis, a nie ten,
 * kto akurat wywołuje akcję.
 *
 * CO BYŁO ZMIERZONE PRZED ZMIANĄ
 * `RecordCookedEvent::handle()` sprawdzał dwie rzeczy — czy przepis jest
 * opublikowany i czy między ludźmi nie ma blokady — a nie sprawdzał
 * WIDOCZNOŚCI ani statusu konta. Wywołane wprost, zapisywało wykonanie
 * cudzego przepisu `private`, mimo że `RecipePolicy::cook` mówiło `false`.
 *
 * TO NIE BYŁ PUBLICZNY IDOR i nie udaję, że był. Kontroler autoryzuje
 * żądanie osobno, więc przez HTTP nikt tędy nie wszedł. Usterka polega na
 * tym, że reguła stała w JEDNYM miejscu zamiast w warstwie, do której
 * sięgnie następny wywołujący — nowe polecenie konsolowe, zadanie w
 * kolejce albo import. Test kontrolny niżej pilnuje właśnie tego: że
 * ścieżka HTTP zachowuje się tak samo jak przedtem.
 */
class UgotowalemPrzezAkcjeTezPrzezPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(User $autor, string $widocznosc): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => $widocznosc,
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * Kontrola: przepis publiczny nadal się zapisuje.
     *
     * Bez tego „odmowa" niżej mogłaby znaczyć, że akcja przestała działać
     * w ogóle, i test przechodziłby z najgorszego możliwego powodu.
     */
    public function test_kontrola_przepis_publiczny_nadal_sie_zapisuje(): void
    {
        $autor = $this->user('autorpubl');
        $kucharz = $this->user('kucharzpubl');
        $przepis = $this->przepis($autor, 'public');

        $this->assertTrue(Gate::forUser($kucharz)->allows('cook', $przepis));

        app(RecordCookedEvent::class)->handle(cook: $kucharz, recipe: $przepis);

        $this->assertSame(1, CookedEvent::query()->count());
    }

    public function test_cudzy_przepis_prywatny_nie_zapisuje_sie_przez_akcje(): void
    {
        $autor = $this->user('autorpryw');
        $obcy = $this->user('obcypryw');
        $przepis = $this->przepis($autor, 'private');

        // Najpierw dowód, że Policy jest tu na „nie" — inaczej test nie
        // mówiłby nic o rozjeździe między warstwami.
        $this->assertFalse(Gate::forUser($obcy)->allows('cook', $przepis));

        $this->expectException(BladDlaCzlowieka::class);

        try {
            app(RecordCookedEvent::class)->handle(cook: $obcy, recipe: $przepis);
        } finally {
            $this->assertSame(
                0,
                CookedEvent::query()->count(),
                'Akcja zapisała wykonanie cudzego przepisu prywatnego mimo odmowy Policy (G12).',
            );
        }
    }

    /**
     * Przepis „tylko dla obserwujących" to ta sama granica, innym wejściem.
     */
    public function test_przepis_dla_obserwujacych_nie_zapisuje_sie_komus_z_zewnatrz(): void
    {
        $autor = $this->user('autorobs');
        $obcy = $this->user('obcyobs');
        $przepis = $this->przepis($autor, 'followers');

        $this->assertFalse(Gate::forUser($obcy)->allows('cook', $przepis));

        try {
            app(RecordCookedEvent::class)->handle(cook: $obcy, recipe: $przepis);
            $this->fail('Akcja wpuściła obcego na przepis dla obserwujących.');
        } catch (BladDlaCzlowieka) {
            // O to chodzi.
        }

        $this->assertSame(0, CookedEvent::query()->count());
    }

    /**
     * A obserwujący — przechodzi. Naprawa nie może zamknąć drzwi tym,
     * dla których są otwarte.
     */
    public function test_obserwujacy_zapisuje_wykonanie_przepisu_dla_obserwujacych(): void
    {
        $autor = $this->user('autorobs2');
        $obserwujacy = $this->user('obserwujacy');
        $przepis = $this->przepis($autor, 'followers');

        $obserwujacy->following()->attach($autor->getKey());

        $this->assertTrue(Gate::forUser($obserwujacy->fresh())->allows('cook', $przepis));

        app(RecordCookedEvent::class)->handle(cook: $obserwujacy->fresh(), recipe: $przepis);

        $this->assertSame(1, CookedEvent::query()->count());
    }

    /**
     * Autor własnego przepisu prywatnego nadal może zapisać wykonanie —
     * `RecipePolicy::view` wpuszcza właściciela niezależnie od widoczności,
     * a D-005 mówi wprost, że gotowanie własnego przepisu jest dozwolone.
     */
    public function test_autor_moze_ugotowac_wlasny_przepis_prywatny(): void
    {
        $autor = $this->user('autorsam');
        $przepis = $this->przepis($autor, 'private');

        app(RecordCookedEvent::class)->handle(cook: $autor, recipe: $przepis);

        $this->assertSame(1, CookedEvent::query()->count());
    }
}

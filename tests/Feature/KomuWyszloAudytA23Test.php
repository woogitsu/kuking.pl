<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\RecordCookedEvent;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `resources/views/components/cooked-card.blade.php` jest współdzielony
 * między sekcją „Komu wyszło" (ten ekran, `showRecipe=false`) i zakładką
 * „Ugotowane" na profilu (`showRecipe=true`). Praca nad tym ekranem czyta
 * i przebudowuje sekcję DOOKOŁA tego komponentu — ten test jest kontrolą, że
 * decyzja z audytu A23 w SAMYM komponencie nadal działa po tej przebudowie,
 * niezależnie od tego, że jej gałąź `showRecipe` nie uruchamia się na stronie
 * przepisu (tu wykonanie zawsze należy do WŁAŚNIE oglądanego, istniejącego
 * przepisu — inaczej strona w ogóle by nie odpowiedziała 200).
 *
 * Pełne pokrycie A23 (usuwanie osieroconego wykonania, 403 dla obcych, itd.)
 * ma `UsunietyPrzepisAWykonanieTest` — ten plik NIE go powiela, sprawdza
 * wyłącznie to, co jest w bezpośrednim zasięgu tej zmiany: sam tekst karty.
 */
class KomuWyszloAudytA23Test extends TestCase
{
    use RefreshDatabase;

    public function test_karta_wykonania_nie_ujawnia_tytulu_usunietego_przepisu(): void
    {
        $autor = $this->user('autorka');
        $kucharz = $this->user('kucharka');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Bigos Wandy sprzed lat',
        ]);

        $event = app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            note: 'Notatka, ktora ma zostac na karcie.',
        );

        $recipe->delete();

        $odpowiedz = $this->actingAs($kucharz)
            ->get(route('profile.show', $kucharz->profile->username).'?zakladka=ugotowane')
            ->assertOk();

        // Zdjęcie i notatka to treść kucharza — zostają.
        $odpowiedz->assertSee('Notatka, ktora ma zostac na karcie', escape: false);
        // Tytuł przepisu, który autor sam skasował, nie wraca bocznymi drzwiami.
        $odpowiedz->assertDontSee('Bigos Wandy sprzed lat', escape: false);
        // Zdanie z A23, dosłownie — nie zastąpione czymś innym przy okazji.
        $odpowiedz->assertSee('Przepisu, z którego to powstało, już nie ma.', escape: false);

        $this->assertNotNull($event->fresh());
    }

    /**
     * KONTROLA: to samo wykonanie, ale wobec ISTNIEJĄCEGO przepisu — tytuł
     * i odnośnik są na miejscu. Bez tego test wyżej mógłby przechodzić,
     * gdyby karta w ogóle przestała pokazywać tytuł przepisu.
     */
    public function test_kontrola_karta_wykonania_pokazuje_tytul_gdy_przepis_istnieje(): void
    {
        $autor = $this->user('autorka2');
        $kucharz = $this->user('kucharka2');

        $recipe = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Kotlet schabowy jak u mamy',
        ]);

        app(RecordCookedEvent::class)->handle(
            cook: $kucharz,
            recipe: $recipe,
            note: 'To wykonanie ma widoczny przepis.',
        );

        $this->actingAs($kucharz)
            ->get(route('profile.show', $kucharz->profile->username).'?zakladka=ugotowane')
            ->assertOk()
            ->assertSee('Kotlet schabowy jak u mamy', escape: false);
    }
}

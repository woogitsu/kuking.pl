<?php

declare(strict_types=1);

namespace Tests\Feature\Visibility;

use App\Domain\Social\Actions\BlockUser;
use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Droga 2 wycieku: LISTA (issue #41, audyt A4).
 *
 * Galeria „Komu wyszło" pod przepisem pokazuje wykonania OD WIELU RÓŻNYCH
 * OSÓB naraz — więc, tak jak profil i temat, potrzebuje własnego filtra
 * w zapytaniu, a nie polegania na `CookedEventPolicy`, która pilnuje tylko
 * wejścia na adres POJEDYNCZEGO wykonania (`/ugotowane/{id}`).
 */
class GaleriaUgotowanychTest extends TestCase
{
    use RefreshDatabase;

    private User $autorPrzepisu;

    private Recipe $przepis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autorPrzepisu = $this->user('autorkaprzepisu');
        $this->przepis = Recipe::factory()->create([
            'author_id' => $this->autorPrzepisu->getKey(),
            'visibility' => 'public',
            'title' => 'Rosol na galerie',
            'slug' => 'rosol-na-galerie',
        ]);
    }

    private function wykonanie(User $kucharz, string $notatka): CookedEvent
    {
        return CookedEvent::factory()->create([
            'recipe_id' => $this->przepis->getKey(),
            'user_id' => $kucharz->getKey(),
            'note' => $notatka,
        ]);
    }

    // -----------------------------------------------------------------
    // Kontrola: bez żadnej blokady galeria pokazuje wszystko
    // -----------------------------------------------------------------

    public function test_bez_blokady_galeria_pokazuje_wykonanie(): void
    {
        $kucharka = $this->user('kucharka');
        $this->wykonanie($kucharka, 'Wyszło idealnie, dzięki!');

        $this->actingAs($this->user('widz'))
            ->get(route('recipes.show', $this->przepis->slug))
            ->assertOk()
            ->assertSee('Wyszło idealnie, dzięki!');
    }

    public function test_gosc_widzi_wykonania_publicznego_przepisu(): void
    {
        $kucharka = $this->user('kucharka');
        $this->wykonanie($kucharka, 'Zrobiłam to dla całej rodziny');

        $this->get(route('recipes.show', $this->przepis->slug))
            ->assertOk()
            ->assertSee('Zrobiłam to dla całej rodziny');
    }

    // -----------------------------------------------------------------
    // Blokada — w OBIE strony
    // -----------------------------------------------------------------

    public function test_widz_nie_widzi_wykonania_osoby_ktora_zablokowal(): void
    {
        $widz = $this->user('widz');
        $zablokowana = $this->user('zablokowana');
        $this->wykonanie($zablokowana, 'Notatka zablokowanej kucharki');

        app(BlockUser::class)->handle($widz, $zablokowana);

        $this->actingAs($widz)
            ->get(route('recipes.show', $this->przepis->slug))
            ->assertOk()
            ->assertDontSee('Notatka zablokowanej kucharki');
    }

    public function test_widz_nie_widzi_wykonania_osoby_ktora_jego_zablokowala(): void
    {
        $widz = $this->user('widz');
        $blokujaca = $this->user('blokujaca');
        $this->wykonanie($blokujaca, 'Notatka blokującej kucharki');

        // Blokada działa w obie strony (AGENTS.md §4) — tu to KUCHARKA
        // zablokowała widza, nie odwrotnie.
        app(BlockUser::class)->handle($blokujaca, $widz);

        $this->actingAs($widz)
            ->get(route('recipes.show', $this->przepis->slug))
            ->assertOk()
            ->assertDontSee('Notatka blokującej kucharki');
    }

    // -----------------------------------------------------------------
    // Naprawa zbyt szeroka: blokada NIEZWIĄZANEJ osoby nie może ukryć
    // wykonań, których blokada nie dotyczy.
    // -----------------------------------------------------------------

    public function test_blokada_kogos_innego_nie_ukrywa_niepowiazanego_wykonania(): void
    {
        $widz = $this->user('widz');
        $niepowiazana = $this->user('niepowiazana');
        $spam = $this->user('spam');

        $this->wykonanie($niepowiazana, 'Wykonanie osoby niepowiązanej blokadą');

        // Widz blokuje ZUPEŁNIE inną osobę — filtr nie może przez pomyłkę
        // (np. brak `whereColumn` i porównanie do złej kolumny) wyciąć
        // wszystkich wierszy tabeli `blocks` naraz.
        app(BlockUser::class)->handle($widz, $spam);

        $this->actingAs($widz)
            ->get(route('recipes.show', $this->przepis->slug))
            ->assertOk()
            ->assertSee('Wykonanie osoby niepowiązanej blokadą');
    }

    public function test_wlasne_wykonanie_widza_zostaje_mimo_niepowiazanej_blokady(): void
    {
        $widz = $this->user('widz');
        $spam = $this->user('spam');

        $this->wykonanie($widz, 'Moje własne wykonanie');
        app(BlockUser::class)->handle($widz, $spam);

        $this->actingAs($widz)
            ->get(route('recipes.show', $this->przepis->slug))
            ->assertOk()
            ->assertSee('Moje własne wykonanie');
    }

    public function test_zablokowanie_autora_przepisu_nie_ukrywa_cudzych_wykonan_w_galerii(): void
    {
        // Widz zablokował AUTORA PRZEPISU, nie osobę, która ugotowała.
        // Sama strona przepisu jest wtedy niedostępna (RecipePolicy) —
        // ale to sprawdza inny test. Tu liczy się to, że filtr galerii patrzy
        // na `cooked_events.user_id`, a nie myli go z `recipes.author_id`.
        $widz = $this->user('widz');
        $kucharka = $this->user('kucharkaniezalezna');
        $this->wykonanie($kucharka, 'Wykonanie niepowiązane z autorem przepisu');

        app(BlockUser::class)->handle($widz, $this->autorPrzepisu);

        // Strona przepisu i tak jest zablokowana przez RecipePolicy — 403.
        $this->actingAs($widz)
            ->get(route('recipes.show', $this->przepis->slug))
            ->assertStatus(403);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Zgłaszanie przepisu (audyt A29).
 *
 * `recipes.id` jest kolumną `uuid`, a widok przepisu przekazuje do formularza
 * zgłoszenia SLUG. Zapytanie
 *
 *     Recipe::where('slug', $id)->orWhere('id', $id)
 *
 * padało w Postgresie zawsze — także dla poprawnego sluga — bo baza musi
 * rzutować parametr na uuid, żeby wykonać drugie porównanie, niezależnie od
 * tego, czy pierwsze pasuje:
 *
 *     SQLSTATE[22P02]: invalid input syntax for type uuid: "rosol-babci"
 *
 * Czyli przycisk „Zgłoś" pod KAŻDYM przepisem zwracał 500. Zgłaszanie treści
 * to obowiązek z DSA art. 16, więc to nie była usterka wygody.
 */
class ZglaszaniePrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $this->user('autorka')->getKey(),
            'visibility' => 'public',
            'title' => 'Rosół babci',
            'slug' => 'rosol-babci-'.Str::lower(Str::random(6)),
        ]);
    }

    public function test_formularz_zgloszenia_otwiera_sie_po_slugu(): void
    {
        $przepis = $this->przepis();

        // Dokładnie ten adres, który generuje przycisk „Zgłoś" na stronie
        // przepisu (pages/recipes/show.blade.php).
        $this->actingAs($this->user('zglaszajaca'))
            ->get(route('reports.create', ['type' => 'recipe', 'id' => $przepis->slug]))
            ->assertOk();
    }

    public function test_formularz_zgloszenia_otwiera_sie_po_uuid(): void
    {
        $przepis = $this->przepis();

        // Druga droga zostaje działająca — kod ją przewidywał, tylko robił to
        // tak, że wywracał obie naraz.
        $this->actingAs($this->user('zglaszajaca'))
            ->get(route('reports.create', ['type' => 'recipe', 'id' => $przepis->getKey()]))
            ->assertOk();
    }

    public function test_zgloszenie_przepisu_po_slugu_zapisuje_sie(): void
    {
        $przepis = $this->przepis();
        $zglaszajaca = $this->user('zglaszajaca');

        $this->actingAs($zglaszajaca)
            ->post(route('reports.store', ['type' => 'recipe', 'id' => $przepis->slug]), [
                'reason' => 'copyright',
                'details' => 'Przepis przepisany z książki bez podania źródła.',
            ])
            ->assertRedirectContains('/zgloszenia/');

        // Zgłoszenie ma wskazywać na przepis przez jego KLUCZ, nie przez slug —
        // slug może się zmienić, a zgłoszenie musi dalej wskazywać tę treść.
        $this->assertDatabaseHas('reports', [
            'reporter_id' => $zglaszajaca->getKey(),
            'target_type' => 'recipe',
            'target_id' => $przepis->getKey(),
        ]);
    }

    public function test_nieistniejacy_przepis_daje_404_a_nie_500(): void
    {
        // Ani slug, ani uuid. Wcześniej dowolna z tych wartości wywalała
        // zapytanie; teraz brak treści to zwykłe „nie znaleziono".
        $this->actingAs($this->user('zglaszajaca'))
            ->get(route('reports.create', ['type' => 'recipe', 'id' => 'nie-ma-takiego-przepisu']))
            ->assertNotFound();
    }

    public function test_nieistniejacy_uuid_tez_daje_404(): void
    {
        $this->actingAs($this->user('zglaszajaca'))
            ->get(route('reports.create', ['type' => 'recipe', 'id' => Str::uuid()->toString()]))
            ->assertNotFound();
    }
}

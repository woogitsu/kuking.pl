<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dwa kliknięcia „Zapisuję” mają dać dokładnie ten sam skutek co jedno
 * (issue #43).
 *
 * Podwójne kliknięcie nie jest w grupie 50+ pomyłką, tylko sposobem obsługi
 * komputera: strona myśli chwilę, więc klika się drugi raz. Przycisk, który
 * przy drugim kliknięciu robi coś innego niż przy pierwszym, jest usterką.
 *
 * CO SIĘ DZIAŁO
 * Klucz główny `collection_items (collection_id, recipe_id)` chronił bazę
 * przed drugim wierszem, więc problem nie był widoczny w schemacie. Widać go
 * było dopiero od strony człowieka, bo `syncWithoutDetaching` na istniejącej
 * parze robi UPDATE:
 *
 *  1. nadpisywało `created_at` w zeszycie — a zeszyt jest ułożony od
 *     najnowszego zapisu, więc przepis skakał na górę listy;
 *  2. autor przepisu dostawał DRUGIE powiadomienie „ktoś zapisał Twój
 *     przepis” — od jednej osoby, za jedno zapisanie.
 */
class IdempotentnyZapisDoZeszytuTest extends TestCase
{
    use RefreshDatabase;

    public function test_dwa_klikniecia_nie_daja_bledu_ani_drugiego_wiersza(): void
    {
        $osoba = $this->user('klikajaca');
        $przepis = Recipe::factory()->create();

        $this->actingAs($osoba)->post(route('collections.save', $przepis->slug));
        $drugie = $this->actingAs($osoba)->post(route('collections.save', $przepis->slug));

        $drugie->assertRedirect();
        $drugie->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('collection_items')->count());
    }

    public function test_drugie_klikniecie_nie_wysyla_drugiego_powiadomienia(): void
    {
        $autor = $this->user('autorka');
        $osoba = $this->user('zapisujaca2');
        $przepis = Recipe::factory()->create(['author_id' => $autor->getKey()]);

        $this->actingAs($osoba)->post(route('collections.save', $przepis->slug));
        $this->actingAs($osoba)->post(route('collections.save', $przepis->slug));

        $this->assertSame(
            1,
            Notification::query()
                ->where('user_id', $autor->getKey())
                ->where('type', Notification::TYPE_SAVED)
                ->count(),
        );
    }

    public function test_drugie_klikniecie_nie_przestawia_przepisu_na_gore_zeszytu(): void
    {
        $osoba = $this->user('kolejnosciowa');
        $starszy = Recipe::factory()->create();
        $nowszy = Recipe::factory()->create();

        $this->actingAs($osoba)->post(route('collections.save', $starszy->slug));
        $this->travel(2)->minutes();
        $this->actingAs($osoba)->post(route('collections.save', $nowszy->slug));
        $this->travel(2)->minutes();

        // Ponowne kliknięcie przy STARSZYM przepisie. Zeszyt jest ułożony od
        // najnowszego zapisu, więc nadpisanie `created_at` przestawiłoby
        // kolejność bez żadnej akcji ze strony człowieka.
        $this->actingAs($osoba)->post(route('collections.save', $starszy->slug));

        $zeszyt = $osoba->defaultCollection();

        $this->assertSame(
            [$nowszy->getKey(), $starszy->getKey()],
            $zeszyt->recipes()->pluck('recipes.id')->all(),
        );
    }
}

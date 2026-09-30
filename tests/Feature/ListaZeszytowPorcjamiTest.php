<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\CollectionController;
use App\Models\Collection;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `/zeszyt` pokazuje zeszyty porcjami, a nie całą bibliotekę naraz (#2321).
 *
 * Do poprawki kontroler robił `->get()` na wszystkich własnych i wszystkich
 * udostępnionych zeszytach, z licznikami — koszt strony rósł liniowo z liczbą
 * zeszytów, której nic nie ogranicza. Test mierzy obie listy: pierwsza porcja
 * ma dokładnie `ZESZYTOW_NA_STRONE` kart i odnośnik do następnej, druga —
 * resztę, z poprawnymi liczbami przepisów (liczone już tylko dla porcji).
 */
final class ListaZeszytowPorcjamiTest extends TestCase
{
    use RefreshDatabase;

    public function test_wlasne_i_udostepnione_zeszyty_ida_porcjami_z_poprawnymi_licznikami(): void
    {
        $naStrone = CollectionController::ZESZYTOW_NA_STRONE;
        $ja = $this->user('ja_porcje_2321');
        $inna = $this->user('inna_porcje_2321');
        $autor = $this->user('autor_porcje_2321');

        for ($i = 1; $i <= $naStrone + 4; $i++) {
            Collection::create(['owner_id' => $ja->getKey(), 'name' => sprintf('Moj %03d', $i), 'visibility' => 'private']);
            $cudzy = Collection::create(['owner_id' => $inna->getKey(), 'name' => sprintf('Cudzy %03d', $i), 'visibility' => 'private']);
            DB::table('collection_members')->insert(['collection_id' => $cudzy->getKey(), 'user_id' => $ja->getKey()]);
        }

        // Ostatni własny zeszyt (na drugiej porcji) ma jeden widoczny przepis.
        $ostatni = Collection::query()->where('owner_id', $ja->getKey())->where('name', sprintf('Moj %03d', $naStrone + 4))->firstOrFail();
        $przepis = Recipe::factory()->for($autor, 'author')->create();
        DB::table('collection_items')->insert(['collection_id' => $ostatni->getKey(), 'recipe_id' => $przepis->getKey(), 'created_at' => now()]);

        $pierwsza = $this->actingAs($ja)->get(route('collections.index'))->assertOk();

        $this->assertCount($naStrone, $pierwsza->viewData('collections'), 'Lista własnych zeszytów nie jest ograniczona do porcji.');
        $this->assertCount($naStrone, $pierwsza->viewData('udostepnione'), 'Lista udostępnionych zeszytów nie jest ograniczona do porcji.');
        $pierwsza->assertSee('Następna strona zeszytów');
        $pierwsza->assertSee('Następna strona udostępnionych zeszytów');
        $pierwsza->assertDontSee(sprintf('Moj %03d', $naStrone + 1));

        $druga = $this->actingAs($ja)->get(route('collections.index', ['zeszyty' => 2, 'udostepnione' => 2]))->assertOk();

        $this->assertSame(
            array_map(fn (int $i) => sprintf('Moj %03d', $i), range($naStrone + 1, $naStrone + 4)),
            $druga->viewData('collections')->pluck('name')->all(),
        );
        $this->assertCount(4, $druga->viewData('udostepnione'));
        $this->assertSame(1, (int) $druga->viewData('collections')->firstWhere('id', $ostatni->getKey())->recipes_count);
        $druga->assertDontSee('Następna strona zeszytów');
    }
}

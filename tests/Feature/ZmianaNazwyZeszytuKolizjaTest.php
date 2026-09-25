<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #1339 — zmiana nazwy zeszytu przy równoczesnej kolizji.
 *
 * `CollectionNameNotTaken` robi SELECT przed zapisem, gwarancją jest dopiero
 * indeks `collections_owner_name_lower_unique`. `store()` łapało spóźnioną
 * kolizję, `update()` nie — druga karta, która zajęła nazwę między walidacją
 * a UPDATE-em, kończyła edycję błędem 500.
 *
 * Kolizję podstawiamy dokładnie w tym oknie: zaraz po zapytaniu reguły
 * (`lower(name) = ?`), przed UPDATE-em.
 */
final class ZmianaNazwyZeszytuKolizjaTest extends TestCase
{
    use RefreshDatabase;

    public function test_nazwa_zajeta_w_oknie_wyscigu_wraca_z_bledem_i_wpisana_nazwa(): void
    {
        $osoba = $this->user('dwiekarty');
        $zeszyt = Collection::create([
            'owner_id' => $osoba->getKey(),
            'name' => 'Obiady',
            'visibility' => 'private',
        ]);
        $wstawiono = false;

        DB::listen(function ($query) use ($osoba, &$wstawiono): void {
            if ($wstawiono || ! str_contains($query->sql, 'lower(name) = ?')) {
                return;
            }

            $wstawiono = true;
            DB::table('collections')->insert([
                'id' => fake()->uuid(),
                'owner_id' => $osoba->getKey(),
                'name' => 'Na święta',
                'visibility' => 'private',
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $odpowiedz = $this->actingAs($osoba)
            ->from(route('collections.edit', $zeszyt))
            ->patch(route('collections.update', $zeszyt), [
                'name' => 'Na święta',
                'visibility' => 'private',
            ]);

        $this->assertTrue($wstawiono, 'Okno wyścigu się nie otworzyło — test stracił przedmiot.');

        $odpowiedz->assertRedirect(route('collections.edit', $zeszyt))
            ->assertSessionHasErrors(['name' => 'Masz już zeszyt o tej nazwie. Wybierz inną.'])
            ->assertSessionHasInput('name', 'Na święta');

        // Baza dalej odpowiada (savepoint, nie zepsuta transakcja),
        // a zeszyt zachował dotychczasową nazwę.
        $this->assertSame('Obiady', $zeszyt->fresh()->name);
    }

    /** Kontrola dodatnia: bez kolizji zmiana nazwy przechodzi. */
    public function test_wolna_nazwa_zmienia_sie_bez_bledu(): void
    {
        $osoba = $this->user('jednakarta');
        $zeszyt = Collection::create([
            'owner_id' => $osoba->getKey(),
            'name' => 'Obiady',
            'visibility' => 'private',
        ]);

        $this->actingAs($osoba)
            ->patch(route('collections.update', $zeszyt), [
                'name' => 'Na święta',
                'visibility' => 'private',
            ])
            ->assertRedirect(route('collections.show', $zeszyt))
            ->assertSessionHasNoErrors();

        $this->assertSame('Na święta', $zeszyt->fresh()->name);
    }
}

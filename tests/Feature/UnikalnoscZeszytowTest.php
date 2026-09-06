<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Collection;
use App\Models\Media;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Issue #43 — brakujące ograniczenia UNIQUE.
 *
 * Zgłoszenie wskazywało trzy tabele. Sprawdzenie schematu pokazało, że tylko
 * JEDNA z nich naprawdę pozwala na duplikat:
 *
 *  1. `collection_items` — MA `PRIMARY KEY (collection_id, recipe_id)` od
 *     migracji tworzącej tabelę. Duplikat jest niemożliwy. Zgłoszenie w tym
 *     punkcie jest nieaktualne. Test poniżej to pilnuje, żeby klucz nie
 *     zniknął przy okazji jakiejś przyszłej zmiany.
 *
 *  2. `collections` — NIE MA żadnego ograniczenia na `(owner_id, name)`.
 *     Jedna osoba zakłada dwa zeszyty „Obiady” i nie odróżni ich na liście.
 *     To jest jedyny prawdziwy punkt zgłoszenia.
 *
 *  3. `post_media` — MA `PRIMARY KEY (post_id, media_id)`. Duplikat jest
 *     niemożliwy. Zgłoszenie w tym punkcie jest nieaktualne. Test pilnuje.
 *
 * Przy okazji wyszło, że przycisk „Zapisuję” NIE był idempotentny, mimo że
 * klucz główny chronił bazę przed drugim wierszem — to osobna sprawa i osobny
 * plik: `IdempotentnyZapisDoZeszytuTest`.
 */
class UnikalnoscZeszytowTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // 1 i 3: punkty zgłoszenia obalone — dowód wprost ze schematu bazy
    // -----------------------------------------------------------------

    public function test_collection_items_nie_przyjmuje_duplikatu(): void
    {
        $osoba = $this->user('zapisujaca');
        $przepis = Recipe::factory()->create();

        $zeszyt = Collection::create([
            'owner_id' => $osoba->getKey(),
            'name' => 'Obiady',
            'visibility' => 'private',
        ]);

        DB::table('collection_items')->insert([
            'collection_id' => $zeszyt->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('collection_items')->insert([
            'collection_id' => $zeszyt->getKey(),
            'recipe_id' => $przepis->getKey(),
        ]);
    }

    public function test_post_media_nie_przyjmuje_duplikatu(): void
    {
        $osoba = $this->user('publikujaca');

        $wpis = Post::create([
            'author_id' => $osoba->getKey(),
            'body' => 'Rosół jak co niedziela.',
            'visibility' => 'public',
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $zdjecie = Media::create([
            'owner_id' => $osoba->getKey(),
            'disk' => 'local',
            'object_key' => 'incoming/test/rosol.jpg',
            'mime_type' => 'image/jpeg',
            'bytes' => 1024,
            'width' => 800,
            'height' => 600,
            'status' => Media::STATUS_READY,
        ]);

        DB::table('post_media')->insert([
            'post_id' => $wpis->getKey(),
            'media_id' => $zdjecie->getKey(),
            'position' => 0,
        ]);

        $this->expectException(QueryException::class);

        DB::table('post_media')->insert([
            'post_id' => $wpis->getKey(),
            'media_id' => $zdjecie->getKey(),
            'position' => 1,
        ]);
    }

    // -----------------------------------------------------------------
    // 2: prawdziwa usterka — dwa zeszyty o tej samej nazwie
    // -----------------------------------------------------------------

    public function test_dwa_zeszyty_o_tej_samej_nazwie_nie_powstaja(): void
    {
        $osoba = $this->user('gotujaca');

        $this->actingAs($osoba)->post(route('collections.store'), [
            'name' => 'Obiady',
            'visibility' => 'private',
        ]);

        $druga = $this->actingAs($osoba)
            ->from(route('collections.index'))
            ->post(route('collections.store'), [
                'name' => 'Obiady',
                'visibility' => 'private',
            ]);

        $druga->assertRedirect(route('collections.index'));
        $druga->assertSessionHasErrors('name');

        $this->assertSame(1, $osoba->collections()->where('name', 'Obiady')->count());
    }

    public function test_wielkosc_liter_nie_robi_z_obiadow_drugiego_zeszytu(): void
    {
        $osoba = $this->user('gotujaca2');

        $this->actingAs($osoba)->post(route('collections.store'), [
            'name' => 'Obiady',
            'visibility' => 'private',
        ]);

        $druga = $this->actingAs($osoba)
            ->from(route('collections.index'))
            ->post(route('collections.store'), [
                'name' => 'obiady',
                'visibility' => 'private',
            ]);

        $druga->assertSessionHasErrors('name');

        $this->assertSame(1, $osoba->collections()->count());
    }

    public function test_komunikat_mowi_co_zrobic(): void
    {
        $osoba = $this->user('gotujaca3');

        $this->actingAs($osoba)->post(route('collections.store'), [
            'name' => 'Na święta',
            'visibility' => 'private',
        ]);

        $druga = $this->actingAs($osoba)
            ->from(route('collections.index'))
            ->post(route('collections.store'), [
                'name' => 'Na święta',
                'visibility' => 'private',
            ]);

        $komunikat = 'Masz już zeszyt o tej nazwie. Wybierz inną.';

        // Komunikat, którego nie widać, nie jest komunikatem. Formularz siedzi
        // w zwiniętym <details>, więc sprawdzamy, że po powrocie na stronę
        // błąd jest naprawdę na ekranie, formularz jest rozwinięty, a wpisana
        // nazwa nie zniknęła (docs/UX_50_PLUS.md).
        //
        // (Sam fakt, że błąd trafia do sesji, sprawdzają testy wyżej. Tutaj
        // celowo NIE wołamy `assertSessionHasErrors` — zużywa ono błędy
        // z sesji i strona poniżej wyrenderowałaby się już bez nich.)
        $druga->assertRedirect(route('collections.index'));

        $strona = $this->actingAs($osoba)->get(route('collections.index'));

        $strona->assertSee($komunikat, escape: false);
        $strona->assertSee('value="Na święta"', escape: false);
        $strona->assertSee('class="error-summary"', escape: false);
        $strona->assertSee('--spacing-8);" open>', escape: false);

        // D-009: gra słowem kuKING nigdy w komunikacie błędu.
        $this->assertStringNotContainsStringIgnoringCase('kuking', $komunikat);
    }

    public function test_dwie_osoby_moga_miec_zeszyt_o_tej_samej_nazwie(): void
    {
        $pierwsza = $this->user('ania');
        $druga = $this->user('basia');

        $this->actingAs($pierwsza)->post(route('collections.store'), [
            'name' => 'Obiady',
            'visibility' => 'private',
        ]);

        $odpowiedz = $this->actingAs($druga)->post(route('collections.store'), [
            'name' => 'Obiady',
            'visibility' => 'private',
        ]);

        $odpowiedz->assertSessionHasNoErrors();
        $this->assertSame(1, $druga->collections()->where('name', 'Obiady')->count());
    }

    public function test_baza_odrzuca_duplikat_nazwy_nawet_z_konsoli(): void
    {
        // Walidacja w kontrolerze ma okno między SELECT-em a INSERT-em i nie
        // obowiązuje seedera ani konsoli. Prawdziwa gwarancja stoi w bazie
        // (AGENTS.md §6).
        $osoba = $this->user('konsolowa');

        Collection::create([
            'owner_id' => $osoba->getKey(),
            'name' => 'Zupy',
            'visibility' => 'private',
        ]);

        $this->expectException(QueryException::class);

        Collection::create([
            'owner_id' => $osoba->getKey(),
            'name' => 'ZUPY',
            'visibility' => 'private',
        ]);
    }

    public function test_zapis_przepisu_dziala_gdy_nazwa_zapisane_jest_zajeta(): void
    {
        // Domyślny zeszyt nazywa się „Zapisane”. Jeśli ktoś sam założył zeszyt
        // o tej nazwie ZANIM cokolwiek zapisał, pierwsze „Zapisuję” nie może
        // skończyć się błędem serwera.
        $osoba = $this->user('kolizyjna');
        $przepis = Recipe::factory()->create();

        $this->actingAs($osoba)->post(route('collections.store'), [
            'name' => 'Zapisane',
            'visibility' => 'private',
        ]);

        $odpowiedz = $this->actingAs($osoba)
            ->from(route('recipes.show', $przepis->slug))
            ->post(route('collections.save', $przepis->slug));

        $odpowiedz->assertRedirect();
        $odpowiedz->assertSessionHasNoErrors();

        $this->assertSame(2, $osoba->collections()->count());
        $this->assertSame(1, DB::table('collection_items')->count());
    }
}

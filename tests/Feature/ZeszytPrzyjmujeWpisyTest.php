<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zeszyt przyjmuje też wpisy (UI kit v2, ekran 01 — decyzja właściciela).
 *
 * PO CO, SKORO JEST ZESZYT PRZEPISÓW
 * To dwie różne potrzeby. Zapisany przepis znaczy „chcę to ugotować i mam
 * listę składników". Zapisane zdjęcie — „chcę kiedyś zrobić coś TAKIEGO",
 * a przy wpisie żadnego przepisu zwykle nie ma.
 *
 * GDZIE TA FUNKCJA MOŻE ZROBIĆ KRZYWDĘ
 * Zeszyt jest POJEMNIKIEM NA CUDZE TREŚCI. Bez filtra widoczności zapisany
 * wpis „tylko dla obserwujących" zostawałby czytelny po tym, jak autor
 * przestał być obserwowany — czyli cudzy pojemnik obchodziłby ustawienie,
 * które autor sobie wybrał. Dokładnie ten błąd wyszedł już raz przy
 * przepisach (audyt A04).
 */
class ZeszytPrzyjmujeWpisyTest extends TestCase
{
    use RefreshDatabase;

    public function test_wpis_da_sie_zapisac_z_karty(): void
    {
        $basia = $this->user('basia');
        $autor = $this->user('autor');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $this->actingAs($basia)->post(route('collections.save-post', $wpis))->assertRedirect();

        $this->assertTrue($basia->defaultCollection()->posts()->whereKey($wpis->getKey())->exists());
    }

    public function test_drugie_klikniecie_nie_dubluje_i_nie_przesuwa_pozycji(): void
    {
        // Podwójne kliknięcie w grupie 50+ to norma, nie pomyłka (issue #43).
        // Zeszyt jest ułożony od najnowszego zapisu, więc nadpisanie
        // `created_at` przesunęłoby pozycję na górę listy — człowiek nie zrobił
        // nic poza powtórzeniem tej samej akcji, a kolejność mu się zmieniła.
        $basia = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $this->actingAs($basia)->post(route('collections.save-post', $wpis))->assertRedirect();

        $pierwszy = $basia->defaultCollection()->posts()->first()->pivot->created_at;

        Carbon::setTestNow(Carbon::now()->addHour());
        $this->actingAs($basia)->post(route('collections.save-post', $wpis))->assertRedirect();
        Carbon::setTestNow();

        $this->assertSame(1, $basia->defaultCollection()->posts()->count());
        $this->assertEquals(
            $pierwszy,
            $basia->defaultCollection()->posts()->first()->pivot->created_at,
            'Powtórzony zapis przesunął wpis na górę zeszytu.',
        );
    }

    public function test_nie_da_sie_zapisac_cudzego_wpisu_prywatnego(): void
    {
        // UUID w adresie to nie autoryzacja (AGENTS.md §7). Bez Policy przed
        // zapisem dałoby się odłożyć do zeszytu cudzy wpis prywatny, znając
        // sam jego identyfikator — i mieć go tam na zawsze.
        $basia = $this->user('basia');
        $wpis = Post::factory()->create([
            'author_id' => $this->user('autor')->getKey(),
            'visibility' => Post::VISIBILITY_PRIVATE,
        ]);

        $this->actingAs($basia)->post(route('collections.save-post', $wpis))->assertForbidden();

        $this->assertSame(0, $basia->defaultCollection()->posts()->count());
    }

    public function test_wpis_ktory_przestal_byc_widoczny_nie_pokazuje_tresci_ale_nie_znika_po_cichu(): void
    {
        $basia = $this->user('basia');
        $autor = $this->user('autor');

        $wpis = Post::factory()->create([
            'author_id' => $autor->getKey(),
            'body' => 'Tajny rosół tylko dla obserwujących.',
            'visibility' => Post::VISIBILITY_FOLLOWERS,
        ]);

        $basia->following()->attach($autor->getKey());
        $this->actingAs($basia)->post(route('collections.save-post', $wpis))->assertRedirect();

        // Basia przestaje obserwować autora — wpis nie jest już dla niej.
        $basia->following()->detach($autor->getKey());

        $odpowiedz = $this->actingAs($basia)
            ->get(route('collections.show', $basia->defaultCollection()))
            ->assertOk();

        // TREŚCI NIE MA — cudzy pojemnik nie obchodzi ustawienia autora.
        $odpowiedz->assertDontSee('Tajny rosół tylko dla obserwujących.', escape: false);

        // ALE NIE ZNIKA PO CICHU. Ciche zniknięcie wygląda jak utrata danych
        // („miałam to tu wczoraj"), więc mówimy ILE, nie mówiąc CZEGO.
        $odpowiedz->assertSee('nie jest', escape: false);
        $odpowiedz->assertSee('Te zapisy nadal są w tym zeszycie.', escape: false);

        // Wiersz naprawdę został w bazie — komunikat nie kłamie.
        $this->assertSame(1, $basia->defaultCollection()->posts()->count());
    }

    public function test_wpis_da_sie_wyjac_z_zeszytu(): void
    {
        $basia = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $this->actingAs($basia)->post(route('collections.save-post', $wpis))->assertRedirect();
        $this->actingAs($basia)->delete(route('collections.unsave-post', $wpis))->assertRedirect();

        $this->assertSame(0, $basia->defaultCollection()->posts()->count());
    }

    public function test_skasowany_wpis_nie_zostawia_martwego_wiersza(): void
    {
        // Klucz obcy z `cascadeOnDelete` zamiast polimorfizmu: skasowany wpis
        // NIE zostawia w zeszycie wiersza wskazującego w próżnię. Przy
        // `item_type`/`item_id` baza nie miałaby jak tego zauważyć.
        $basia = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);

        $this->actingAs($basia)->post(route('collections.save-post', $wpis))->assertRedirect();

        $wpis->forceDelete();

        $this->assertSame(0, $basia->defaultCollection()->posts()->count());
    }

    public function test_baza_odrzuca_wiersz_bedacy_naraz_przepisem_i_wpisem(): void
    {
        // CHECK `num_nonnulls(recipe_id, post_id) = 1` — ten sam wzorzec co
        // `comments_single_target_check`. Baza jest ostatnim miejscem, które
        // może tego pilnować, gdy dane wchodzą inną drogą niż formularz.
        $basia = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $this->user('autor')->getKey()]);
        $przepis = Recipe::factory()->create(['author_id' => $this->user('kucharz')->getKey()]);

        $this->expectException(QueryException::class);

        DB::table('collection_items')->insert([
            'collection_id' => $basia->defaultCollection()->getKey(),
            'recipe_id' => $przepis->getKey(),
            'post_id' => $wpis->getKey(),
        ]);
    }
}

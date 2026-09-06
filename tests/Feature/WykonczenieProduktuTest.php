<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grupa C z raportu: rzeczy, które człowiek widzi od razu.
 *
 * Żadna z nich nie jest awarią. Każda jest miejscem, w którym serwis albo
 * milczy tam, gdzie powinien coś powiedzieć, albo mówi coś, czego nie robi.
 */
class WykonczenieProduktuTest extends TestCase
{
    use RefreshDatabase;

    private function przepis(User $autor, string $tytul = 'Kartacze'): Recipe
    {
        return Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => $tytul,
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------
    // C2 — potwierdzenie kasowania bez JavaScriptu
    // ---------------------------------------------------------------

    public function test_potwierdzenie_kasowania_nie_wymaga_javascriptu(): void
    {
        $basia = $this->user('basia');
        $wpis = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $html = $this->actingAs($basia)->get(route('posts.show', $wpis))->assertOk()->getContent();

        // TO JEST NAJWAŻNIEJSZA ASERCJA W TYM PLIKU.
        //
        // Wcześniej potwierdzenie było atrybutem `onsubmit="return confirm(...)"`.
        // Bez JavaScriptu kliknięcie „Usuń ten wpis" kasowało wpis OD RAZU,
        // bez pytania — a AGENTS.md §5 wymaga i potwierdzenia przy akcji
        // destrukcyjnej, i działania bez skryptu.
        //
        // Drugi powód jest cichszy: polityka CSP w wersji docelowej ma
        // `script-src 'self'` bez `unsafe-inline`, co blokuje atrybuty zdarzeń.
        // W dniu domknięcia #12 wszystkie potwierdzenia przestałyby działać
        // po cichu — bez błędu, po prostu kasując bez pytania.
        $this->assertStringNotContainsString('onsubmit', $html);
        $this->assertStringNotContainsString('confirm(', $html);

        // Pytanie musi być na stronie, a nie w oknie przeglądarki.
        $this->assertStringContainsString('Na pewno usunąć ten wpis?', $html);
    }

    public function test_kasowanie_dalej_dziala(): void
    {
        $basia = $this->user('basia');
        $wpis = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        // Druga strona reguły: „naprawa" polegająca na wyłączeniu przycisku
        // też by przeszła test wyżej.
        $this->actingAs($basia)->delete(route('posts.destroy', $wpis))->assertRedirect();

        // `Post` używa miękkiego kasowania, więc wiersz zostaje w bazie —
        // sprawdzamy, że wpis zniknął z normalnego zapytania, a nie że
        // zniknął fizycznie.
        $this->assertSame(0, Post::query()->whereKey($wpis->getKey())->count());
    }

    // ---------------------------------------------------------------
    // C3 — przepis bez wykonań
    // ---------------------------------------------------------------

    public function test_przepis_bez_wykonan_nie_jest_niemy(): void
    {
        $przepis = $this->przepis($this->user('basia'));

        // Sekcja „Komu wyszło" po prostu znikała ze strony, więc przepis
        // z zerem wykonań wyglądał jak odrzucony. SOUL 4.2 wymienia to jako
        // ryzyko wprost.
        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('Jeszcze nikt tego nie gotował')
            ->assertSee('Będziesz pierwsza albo pierwszy?', escape: false);
    }

    // ---------------------------------------------------------------
    // C4 — „X z Y osób zrobi to ponownie"
    // ---------------------------------------------------------------

    public function test_zrobie_ponownie_jest_pokazywane_od_trzech_ocen(): void
    {
        $przepis = $this->przepis($this->user('basia'));

        foreach ([true, true, false] as $numer => $ponownie) {
            CookedEvent::factory()->create([
                'recipe_id' => $przepis->getKey(),
                'user_id' => $this->user('gotujaca'.$numer)->getKey(),
                'would_make_again' => $ponownie,
                'cooked_at' => now(),
            ]);
        }

        // Odpowiedź była zbierana od początku i wyrzucana — nigdzie nie
        // agregowana. To jedyna miara jakości przepisu, na jaką się
        // zgodziliśmy: gwiazdek nie ma i nie będzie.
        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('2 z 3 osób zrobi to ponownie', escape: false);
    }

    public function test_ponizej_trzech_ocen_nie_pokazujemy_werdyktu(): void
    {
        $przepis = $this->przepis($this->user('basia'), 'Rosol');

        CookedEvent::factory()->create([
            'recipe_id' => $przepis->getKey(),
            'user_id' => $this->user('jedyna')->getKey(),
            'would_make_again' => false,
            'cooked_at' => now(),
        ]);

        // Przy jednej opinii zdanie brzmi jak werdykt, którym nie jest —
        // „0 z 1 osoby zrobi to ponownie" potrafiłoby zabić przepis.
        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertDontSee('zrobi to ponownie', escape: false);
    }

    // ---------------------------------------------------------------
    // C6 — propozycja kolejnego zdjęcia
    // ---------------------------------------------------------------

    public function test_po_publikacji_proponujemy_kolejne_zdjecie(): void
    {
        $basia = $this->user('basia');
        $wpis = Post::factory()->create([
            'author_id' => $basia->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        // „Człowiek ma w telefonie czterdzieści zdjęć obiadów i jest w trybie
        // »już wiem, jak to działa«" (COLD_START.md). To jedyny moment,
        // w którym opór przed publikacją jest zerowy.
        $this->actingAs($basia)->get(route('posts.show', $wpis))
            ->assertOk()
            ->assertSee('To Twój pierwszy wpis', escape: false)
            ->assertSee('Dodaj kolejne zdjęcie', escape: false);
    }

    public function test_obcy_nie_widzi_zachety_do_publikacji_pod_cudzym_wpisem(): void
    {
        $wpis = Post::factory()->create([
            'author_id' => $this->user('basia')->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $this->actingAs($this->user('obcy'))->get(route('posts.show', $wpis))
            ->assertOk()
            ->assertDontSee('Dodaj kolejne zdjęcie', escape: false);
    }

    // ---------------------------------------------------------------
    // C9 — odmowa dla zawieszonego konta
    // ---------------------------------------------------------------

    public function test_odmowa_dla_zawieszonego_konta_nie_gubi_wpisanego_tekstu(): void
    {
        $halina = $this->user('halina');
        $wpis = Post::factory()->create([
            'author_id' => $this->user('basia')->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        $halina->status = User::STATUS_SUSPENDED;
        $halina->save();

        $tresc = 'Robilam to wczoraj, wyszlo znakomicie, dodalam wiecej majeranku.';

        $this->actingAs($halina->fresh())
            ->from(route('posts.show', $wpis))
            ->post(route('posts.comment', $wpis), ['body' => $tresc])
            ->assertRedirect(route('posts.show', $wpis))
            ->assertSessionHasErrors('konto');

        // Odmowa jest tu ZAMIERZONA — ale to nie jest powód, żeby karać
        // człowieka utratą tego, co napisał. Ta sama reguła co przy wygasłej
        // sesji (#81), tylko inna przyczyna.
        $this->assertSame($tresc, session()->getOldInput('body'));
    }
}

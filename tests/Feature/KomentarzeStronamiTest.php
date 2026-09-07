<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Komentarze pod wpisem i pod przepisem idą STRONAMI, nie wszystkie naraz.
 *
 * CO BYŁO ZŁE
 * `RecipeController::show()` i `PostController::show()` ładowały komentarze
 * przez `->load(['comments', 'comments.replies', ...])`, bez żadnego limitu.
 * Nie wyszło to w audycie zapytań bez limitu (T20), bo tam szukano `->get()` —
 * a to jest ten sam kształt ryzyka pod inną nazwą: zbiór rośnie
 * z popularnością treści i nic go nie przycina. Znalezione przy tamtym
 * audycie, przy okazji, i zapisane jako osobne zadanie.
 *
 * CO TO ZNACZY DLA CZŁOWIEKA
 * Przepis, pod którym uzbierało się dwieście komentarzy, ładował dwieście
 * komentarzy razem z ich odpowiedziami i awatarami — na telefonie
 * z wolniejszym łączem to jest różnica między stroną, która się otwiera,
 * i stroną, którą się porzuca. A osoba 50+ nie powie „strona wolno działa",
 * tylko przestanie tam wracać.
 *
 * DLACZEGO ODPOWIEDZI ZOSTAJĄ W CAŁOŚCI
 * Wątek ma tylko JEDEN poziom odpowiedzi (`comment-thread.blade.php`), więc
 * ich liczba jest ograniczona liczbą osób, które weszły w JEDNĄ rozmowę —
 * a nie popularnością całego przepisu. Paginowanie odpowiedzi wymagałoby
 * osobnej strony dla każdego wątku i dawałoby przycisk „Pokaż więcej"
 * w środku listy, czyli dokładnie ten rodzaj nieprzewidywalności, przed
 * którym ostrzega `docs/UX_50_PLUS.md`.
 */
class KomentarzeStronamiTest extends TestCase
{
    use RefreshDatabase;

    private function przepisZKomentarzami(int $ile): Recipe
    {
        $autor = $this->user('kucharka');

        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
        ]);

        for ($i = 0; $i < $ile; $i++) {
            Comment::create([
                'recipe_id' => $przepis->getKey(),
                'author_id' => $this->user('gosc'.$i)->getKey(),
                'body' => 'Komentarz numer '.($i + 1),
                'status' => Comment::STATUS_PUBLISHED,
            ]);
        }

        return $przepis;
    }

    /** Nagłówek „Komentarze (N)" — z tolerancją na odstępy, które wstawia Blade. */
    private function assertNaglowekMowi(int $ile, string $html): void
    {
        $this->assertMatchesRegularExpression(
            '/Komentarze\s*\('.$ile.'\)/u',
            $html,
            "Nagłówek nie mówi o {$ile} komentarzach.",
        );
    }

    /**
     * KONTROLA. Przy kilku komentarzach nie ma żadnej paginacji i wszystkie
     * są widoczne — bez tego pomiar niżej mógłby przechodzić dlatego, że
     * strona nie pokazuje komentarzy w ogóle.
     */
    public function test_kontrola_kilka_komentarzy_widac_wszystkie_bez_paginacji(): void
    {
        $przepis = $this->przepisZKomentarzami(5);

        $odpowiedz = $this->get(route('recipes.show', $przepis->slug))->assertOk();

        $odpowiedz->assertSee('Komentarz numer 1', escape: false);
        $odpowiedz->assertSee('Komentarz numer 5', escape: false);
        $this->assertNaglowekMowi(5, (string) $odpowiedz->getContent());
        $odpowiedz->assertDontSee('Pokaż więcej komentarzy', escape: false);
    }

    /** WŁAŚCIWY POMIAR. Trzydzieści komentarzy → jedna strona, nie trzydzieści wierszy. */
    public function test_przepis_z_wieloma_komentarzami_pokazuje_jedna_strone(): void
    {
        $limit = (int) config('kuking.comments.page_size');
        $this->assertGreaterThan(0, $limit, 'Kontrola: limit komentarzy na stronę musi być liczbą dodatnią.');

        $przepis = $this->przepisZKomentarzami(30);

        $wiersze = 0;
        DB::listen(function ($zapytanie) use (&$wiersze): void {
            if (str_contains($zapytanie->sql, 'from "comments"') && ! str_contains($zapytanie->sql, 'count(*)')) {
                $wiersze++;
            }
        });

        $odpowiedz = $this->get(route('recipes.show', $przepis->slug))->assertOk();

        $tresc = (string) $odpowiedz->getContent();

        // Na stronie jest DOKŁADNIE tyle komentarzy, ile mówi konfiguracja.
        $ile = preg_match_all('/Komentarz numer \d+/', $tresc);
        $this->assertSame($limit, $ile, "Strona pokazała {$ile} komentarzy zamiast {$limit}.");

        // Nagłówek mówi prawdę o CAŁOŚCI, nie o stronie.
        $this->assertNaglowekMowi(30, $tresc);

        // I jest droga do reszty.
        $odpowiedz->assertSee('Pokaż więcej komentarzy', escape: false);
    }

    /** Druga strona pokazuje dalsze komentarze, a nie te same. */
    public function test_druga_strona_pokazuje_dalsze_komentarze(): void
    {
        $przepis = $this->przepisZKomentarzami(30);

        $druga = $this->get(route('recipes.show', $przepis->slug).'?komentarze=2')->assertOk();

        $druga->assertDontSee('Komentarz numer 1<', escape: false);
        $druga->assertSee('Komentarz numer 13', escape: false);
    }

    /** Odpowiedzi w wątku zostają widoczne — paginujemy wątki, nie rozmowy. */
    public function test_odpowiedzi_w_watku_zostaja_widoczne(): void
    {
        $przepis = $this->przepisZKomentarzami(1);
        $watek = Comment::query()->firstOrFail();

        Comment::create([
            'recipe_id' => $przepis->getKey(),
            'parent_id' => $watek->getKey(),
            'author_id' => $this->user('odpowiadajaca')->getKey(),
            'body' => 'Odpowiedź w wątku',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $this->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('Odpowiedź w wątku', escape: false);
    }

    /** To samo pod WPISEM — te same dwa kontrolery mają tę samą regułę. */
    public function test_wpis_z_wieloma_komentarzami_tez_paginuje(): void
    {
        $limit = (int) config('kuking.comments.page_size');

        $wpis = Post::factory()->create([
            'author_id' => $this->user('autorka')->getKey(),
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now(),
        ]);

        for ($i = 0; $i < 20; $i++) {
            Comment::create([
                'post_id' => $wpis->getKey(),
                'author_id' => $this->user('ktos'.$i)->getKey(),
                'body' => 'Komentarz numer '.($i + 1),
                'status' => Comment::STATUS_PUBLISHED,
            ]);
        }

        $odpowiedz = $this->get(route('posts.show', $wpis))->assertOk();

        $ile = preg_match_all('/Komentarz numer \d+/', (string) $odpowiedz->getContent());

        $this->assertSame($limit, $ile);
        $this->assertNaglowekMowi(20, (string) $odpowiedz->getContent());
        $odpowiedz->assertSee('Pokaż więcej komentarzy', escape: false);
    }

    /**
     * Blokady nadal działają na paginowanej liście (issue #41). To jest
     * asercja, którą najłatwiej zgubić przy przepisywaniu zapytania:
     * `widoczneDla()` musi zostać na NOWYM zapytaniu, nie tylko w starym
     * `->load()`.
     */
    public function test_paginacja_nie_gubi_filtra_blokad(): void
    {
        $przepis = $this->przepisZKomentarzami(3);

        $blokujacy = $this->user('blokujaca');
        $niechciany = $this->user('niechciana');

        Comment::create([
            'recipe_id' => $przepis->getKey(),
            'author_id' => $niechciany->getKey(),
            'body' => 'Komentarz od kogoś zablokowanego',
            'status' => Comment::STATUS_PUBLISHED,
        ]);

        $blokujacy->blocking()->attach($niechciany->getKey(), ['created_at' => now()]);

        $this->actingAs($blokujacy)
            ->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertDontSee('Komentarz od kogoś zablokowanego', escape: false)
            // KONTROLA: pozostałe komentarze nadal są widoczne, czyli filtr
            // nie wyciął całej listy.
            ->assertSee('Komentarz numer 1', escape: false);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Wyszukiwarka MVP: PostgreSQL + pg_trgm + unaccent.
 *
 * Te testy MUSZĄ chodzić na PostgreSQL — SQLite nie ma ani unaccent,
 * ani similarity(), więc przechodziłyby na zielono nic nie sprawdzając.
 */
class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_znajduje_przepis_mimo_braku_polskich_znakow_w_zapytaniu(): void
    {
        $basia = $this->user('basia');
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Żurek z jajkiem',
            'slug' => 'zurek-z-jajkiem',
        ]);

        // Osoba 60+ na telefonie często nie przełącza się na polską klawiaturę.
        $wyniki = app(SearchQuery::class)->recipes('zurek');

        $this->assertCount(1, $wyniki);
        $this->assertSame('Żurek z jajkiem', $wyniki->first()->title);
    }

    public function test_znajduje_przepis_po_skladniku(): void
    {
        $basia = $this->user('basia');
        $recipe = Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Coś zupełnie inaczej nazwane',
            'slug' => 'cos-zupelnie-inaczej-nazwane',
        ]);

        RecipeIngredient::create([
            'recipe_id' => $recipe->getKey(),
            'ingredient_text' => 'kiszona kapusta',
            'position' => 0,
        ]);

        $wyniki = app(SearchQuery::class)->recipes('kapusta');

        $this->assertCount(1, $wyniki);
    }

    public function test_nie_pokazuje_szkicow_ani_tresci_prywatnych(): void
    {
        $basia = $this->user('basia');

        Recipe::factory()->draft()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Sekretny żurek',
            'slug' => 'sekretny-zurek',
        ]);

        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Prywatny żurek',
            'slug' => 'prywatny-zurek',
            'visibility' => 'private',
        ]);

        $this->assertCount(0, app(SearchQuery::class)->recipes('zurek'));
    }

    public function test_znajduje_osobe_po_imieniu_i_po_specjalnosci(): void
    {
        $basia = $this->user('basia_z_podkarpacia', ['display_name' => 'Basia']);
        $basia->profile->update(['speciality' => 'zupy i kiszonki']);

        $this->assertCount(1, app(SearchQuery::class)->people('Basia'));
        $this->assertCount(1, app(SearchQuery::class)->people('kiszonki'));
    }

    public function test_bardzo_krotkie_zapytanie_nie_obciaza_bazy(): void
    {
        $this->assertCount(0, app(SearchQuery::class)->recipes('a'));
        $this->assertCount(0, app(SearchQuery::class)->people(''));
    }

    /**
     * Domyślny zakres „Wszystko" (UI kit v2, ekran 03).
     *
     * Człowiek, który wpisał „Basia", nie zadeklarował, czy szuka osoby,
     * czy jej przepisów. Przed tym zakresem strona bez parametru `sekcja`
     * pokazywała WYŁĄCZNIE ludzi — przepisy istniały, ale były nieosiągalne
     * bez kliknięcia zakładki, o której nikt nie wiedział, że jest potrzebna.
     */
    public function test_bez_wybranego_zakresu_widac_i_przepisy_i_ludzi(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Rosół Basi',
            'slug' => 'rosol-basi',
        ]);

        $this->get(route('search', ['q' => 'basi']))
            ->assertOk()
            ->assertSee('Rosół Basi')
            ->assertSee('Basia');
    }

    /**
     * Zakres „Do 30 minut" — i przepis BEZ podanych czasów, który do niego
     * nie wpada.
     *
     * Brak danych nie znaczy „szybki". Obiecanie, że coś zajmie pół godziny,
     * gdy nikt tego nie zmierzył, jest gorsze niż nieujęcie przepisu w wynikach.
     */
    public function test_do_30_minut_pomija_dlugie_i_te_bez_czasow(): void
    {
        $basia = $this->user('basia');

        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Rosół szybki',
            'slug' => 'rosol-szybki',
            'prep_minutes' => 10,
            'cook_minutes' => 15,
        ]);
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Rosół całodniowy',
            'slug' => 'rosol-calodniowy',
            'prep_minutes' => 20,
            'cook_minutes' => 180,
        ]);
        Recipe::factory()->create([
            'author_id' => $basia->getKey(),
            'title' => 'Rosół bez czasów',
            'slug' => 'rosol-bez-czasow',
            'prep_minutes' => null,
            'cook_minutes' => null,
        ]);

        $wyniki = app(SearchQuery::class)->recipes('rosol', null, 20, 30);

        $this->assertCount(1, $wyniki);
        $this->assertSame('Rosół szybki', $wyniki->first()->title);
    }

    /**
     * Który zakres jest włączony, musi być SŁYSZALNE, nie tylko widoczne —
     * sam kolor chipa nie istnieje dla czytnika ekranu (AGENTS.md, UX 50+).
     */
    public function test_wlaczony_zakres_ma_aria_current(): void
    {
        $html = $this->get(route('search', ['q' => 'rosol', 'sekcja' => 'szybkie']))
            ->assertOk()
            ->assertSee('Do 30 minut')
            ->getContent();

        // Wzorzec luźny co do białych znaków i kolejności atrybutów: Blade
        // łamie długie znaczniki na kilka linii, a test przypięty do jednej
        // konkretnej postaci HTML-a psuje się przy każdym przeformatowaniu
        // widoku, nie mówiąc nic o tym, co miał pilnować.
        $this->assertMatchesRegularExpression(
            '~sekcja=szybkie"\s[^>]*aria-current="page"~',
            (string) $html,
        );

        // I odwrotnie: zakres, który NIE jest włączony, nie może się tak ogłaszać.
        $this->assertDoesNotMatchRegularExpression(
            '~sekcja=ludzie"\s[^>]*aria-current="page"~',
            (string) $html,
        );
    }

    public function test_strona_wyszukiwania_nie_jest_indeksowana(): void
    {
        $this->get(route('search', ['q' => 'rosol']))
            ->assertOk()
            ->assertSee('noindex', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\GrupySkladnikow;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Grupy składników — „Ciasto", „Farsz", „Do podania" (D-033, część pierwsza).
 *
 * SKĄD TO ZADANIE
 * Strona przepisu w systemie projektowym v3.1 grupuje składniki pod
 * śródtytułami. Kolumna `recipe_ingredients.group_name` stała w schemacie od
 * pierwszej migracji przepisów i zapisywał ją kreator Livewire — ale
 * formularz BEZ JavaScriptu nie miał pola grupy (czyli edycja tą drogą
 * kasowała grupy wpisane w kreatorze), strona przepisu wypisywała jedną
 * płaską listę, a `docs/DATABASE.md` nie wspominał o tej kolumnie ani słowem.
 *
 * CO JEST TU SPRAWDZANE
 *
 *  1. MIGRACJA W OBIE STRONY. CHECK zakłada się i zdejmuje, a cofnięcie nie
 *     zabiera ani jednej nazwy grupy napisanej przez człowieka.
 *  2. ZAPIS Z OBU DRÓG. Formularz jednostronicowy (zwykły POST) i kreator
 *     Livewire zapisują te same grupy — a edycja bez JavaScriptu ich nie
 *     kasuje. To jest właściwa regresja: droga bez skryptu jest w tym
 *     repozytorium wymagana, nie opcjonalna (AGENTS.md §5).
 *  3. WYŚWIETLENIE. Składniki bez grupy na górze i bez nagłówka, grupy
 *     w kolejności autora, nagłówek to prawdziwy `<h3>`.
 *  4. PRZEPIS BEZ GRUP WYGLĄDA JAK DOTĄD. To jest zdecydowana większość
 *     przepisów i najłatwiejsza rzecz do zepsucia przy dokładaniu grup:
 *     jeden pusty nagłówek albo jedna dodatkowa lista i cały serwis wygląda
 *     na formularz z niewypełnionym polem.
 */
class GrupySkladnikowTest extends TestCase
{
    use RefreshDatabase;

    private const COMPONENT = 'recipe-wizard';

    private const MIGRACJA = 'migrations/2026_09_08_100000_add_group_name_check_to_recipe_ingredients.php';

    private const CHECK = 'recipe_ingredients_group_name_check';

    // -----------------------------------------------------------------
    // 1. Migracja — obie strony
    // -----------------------------------------------------------------

    public function test_migracja_zaklada_check_ktory_odrzuca_nazwe_grupy_bez_tresci(): void
    {
        $this->assertTrue($this->checkIstnieje(), 'CHECK nie założył się przy migracji bazy testowej.');

        $przepis = $this->przepisZeSkladnikami([['tekst' => 'mąka', 'grupa' => 'Ciasto']]);

        $this->expectException(QueryException::class);

        // Wprost przez `DB`, z pominięciem modelu i akcji domenowej — bo to
        // jest dokładnie ta droga, przed którą CHECK ma bronić: import,
        // konsola, seeder (AGENTS.md §6).
        DB::table('recipe_ingredients')->insert([
            'recipe_id' => $przepis->getKey(),
            'group_name' => '   ',
            'ingredient_text' => 'sól',
            'position' => 99,
        ]);
    }

    public function test_migracja_zamienia_puste_nazwy_grup_na_null_i_nie_rusza_prawdziwych(): void
    {
        $przepis = $this->przepisZeSkladnikami([
            ['tekst' => 'mąka', 'grupa' => 'Ciasto'],
            ['tekst' => 'twaróg', 'grupa' => 'Farsz'],
        ]);

        // Stan SPRZED reguły: pusta nazwa grupy w bazie. Żeby ją tam wstawić,
        // CHECK musi na moment zniknąć — inaczej test odtwarzałby stan,
        // którego migracja nigdy nie zobaczy.
        $this->migracja()->down();
        DB::table('recipe_ingredients')->insert([
            'recipe_id' => $przepis->getKey(),
            'group_name' => '',
            'ingredient_text' => 'sól',
            'position' => 99,
        ]);

        $this->migracja()->up();

        $grupy = DB::table('recipe_ingredients')
            ->where('recipe_id', $przepis->getKey())
            ->orderBy('position')
            ->pluck('group_name', 'ingredient_text');

        // POZYTYWNA: nagłówek bez treści zniknął.
        $this->assertNull($grupy['sól']);

        // KONTROLNA: nazwy napisane przez człowieka nietknięte.
        $this->assertSame('Ciasto', $grupy['mąka']);
        $this->assertSame('Farsz', $grupy['twaróg']);
    }

    public function test_cofniecie_migracji_zdejmuje_check_i_zostawia_nazwy_grup(): void
    {
        $przepis = $this->przepisZeSkladnikami([
            ['tekst' => 'mąka', 'grupa' => 'Ciasto'],
            ['tekst' => 'twaróg', 'grupa' => 'Farsz'],
            ['tekst' => 'sól', 'grupa' => null],
        ]);

        $this->migracja()->down();

        $this->assertFalse($this->checkIstnieje(), 'Cofnięcie migracji nie zdjęło CHECK-a.');

        // NAJWAŻNIEJSZE ZDANIE TEGO TESTU: cofnięcie nie jest utratą danych.
        // Ta migracja nie dokłada kolumny, więc nie ma czego skasować.
        $this->assertSame(
            ['Ciasto', 'Farsz', null],
            $przepis->fresh()->ingredients->pluck('group_name')->all(),
        );

        // I z powrotem — migracja musi dać się założyć drugi raz.
        $this->migracja()->up();

        $this->assertTrue($this->checkIstnieje());
        $this->assertSame(
            ['Ciasto', 'Farsz', null],
            $przepis->fresh()->ingredients->pluck('group_name')->all(),
        );
    }

    // -----------------------------------------------------------------
    // 2. Zapis — obie drogi
    // -----------------------------------------------------------------

    public function test_formularz_bez_javascriptu_zapisuje_grupy_skladnikow(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Pierogi ruskie',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [
                ['text' => 'mąka', 'group_name' => 'Ciasto'],
                ['text' => 'gorąca woda', 'group_name' => 'Ciasto'],
                ['text' => 'ziemniaki', 'group_name' => 'Farsz'],
                // Składnik BEZ grupy w tym samym przepisie — normalny
                // przypadek, nie błąd walidacji.
                ['text' => 'sól', 'group_name' => ''],
            ],
            'steps' => [['instruction' => 'Ulep i ugotuj.']],
        ])->assertSessionHasNoErrors();

        $skladniki = Recipe::where('title', 'Pierogi ruskie')->sole()->ingredients;

        $this->assertSame(
            ['Ciasto', 'Ciasto', 'Farsz', null],
            $skladniki->pluck('group_name')->all(),
        );

        // KONTROLNA: kolejność wierszy zostaje taka, jak wpisał autor —
        // grupowanie NIE przestawia składników w bazie.
        $this->assertSame(
            ['mąka', 'gorąca woda', 'ziemniaki', 'sól'],
            $skladniki->pluck('ingredient_text')->all(),
        );
    }

    public function test_edycja_bez_javascriptu_nie_kasuje_grup_wpisanych_w_kreatorze(): void
    {
        $basia = $this->user('basia');

        // Przepis powstaje w kreatorze — z grupami.
        Livewire::actingAs($basia)
            ->test(self::COMPONENT)
            ->set('title', 'Sernik z kruszonką')
            ->set('ingredients.0.text', 'mąka')
            ->set('ingredients.0.group_name', 'Ciasto')
            ->set('ingredients.1.text', 'twaróg')
            ->set('ingredients.1.group_name', 'Nadzienie')
            ->set('steps.0.instruction', 'Upiec.')
            ->call('publish');

        $przepis = Recipe::where('title', 'Sernik z kruszonką')->sole();

        // TA SAMA OSOBA POPRAWIA LITERÓWKĘ NA STRONIE BEZ JAVASCRIPTU.
        // Formularz odsyła to, co widzi w polach — więc gdyby pola grupy
        // tam nie było, ten zapis skasowałby grupy i nikt by tego nie
        // zauważył aż do otwarcia przepisu.
        $this->actingAs($basia)->put(route('recipes.update', $przepis->slug), [
            'action' => 'publish',
            'title' => 'Sernik z kruszonką',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [
                ['text' => 'mąka tortowa', 'group_name' => 'Ciasto'],
                ['text' => 'twaróg', 'group_name' => 'Nadzienie'],
            ],
            'steps' => [['instruction' => 'Upiec.']],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['Ciasto', 'Nadzienie'],
            $przepis->fresh()->ingredients->pluck('group_name')->all(),
        );
    }

    public function test_zapis_ujednolica_pisownie_nazwy_grupy_w_obrebie_przepisu(): void
    {
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Pierogi z farszem',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [
                ['text' => 'twaróg', 'group_name' => 'Farsz'],
                // Ta sama grupa, inna wielkość liter i spacja z brzegu.
                // Dla autora to jedno słowo — i tak ma to wyglądać w bazie.
                ['text' => 'ziemniaki', 'group_name' => ' farsz '],
                ['text' => 'cebula', 'group_name' => 'FARSZ'],
            ],
            'steps' => [['instruction' => 'Ulep.']],
        ])->assertSessionHasNoErrors();

        // Wygrywa PIERWSZA pisownia autora, nie „ładniejsza".
        $this->assertSame(
            ['Farsz', 'Farsz', 'Farsz'],
            Recipe::where('title', 'Pierogi z farszem')->sole()->ingredients->pluck('group_name')->all(),
        );
    }

    // -----------------------------------------------------------------
    // 3. Wyświetlenie
    // -----------------------------------------------------------------

    public function test_strona_przepisu_pokazuje_grupy_w_kolejnosci_autora_a_bez_grupy_na_gorze(): void
    {
        $przepis = $this->przepisZeSkladnikami([
            ['tekst' => 'mąka', 'grupa' => 'Ciasto'],
            ['tekst' => 'gorąca woda', 'grupa' => 'Ciasto'],
            ['tekst' => 'ziemniaki', 'grupa' => 'Farsz'],
            // Autor dopisał sól na końcu i nie przypisał jej do niczego.
            ['tekst' => 'sól', 'grupa' => null],
        ]);

        $sekcja = $this->sekcjaSkladnikow($przepis);

        // Bez grupy NA GÓRZE i bez nagłówka, potem grupy w kolejności autora.
        $this->assertLessThan(
            $this->pozycja($sekcja, 'Ciasto'),
            $this->pozycja($sekcja, 'sól'),
            'Składnik bez grupy ma stać na górze, przed pierwszym nagłówkiem.',
        );
        $this->assertLessThan(
            $this->pozycja($sekcja, 'ziemniaki'),
            $this->pozycja($sekcja, 'gorąca woda'),
            'Grupy mają iść w kolejności, w jakiej podał je autor.',
        );
        $this->assertLessThan(
            $this->pozycja($sekcja, 'Farsz'),
            $this->pozycja($sekcja, 'Ciasto'),
        );

        // NAGŁÓWEK JEST NAGŁÓWKIEM, nie pogrubionym akapitem — po nagłówkach
        // czytnik ekranu nawiguje, po pogrubionym `<p>` nie.
        $this->assertStringContainsString('<h3 class="naglowek-grupy">Ciasto</h3>', $sekcja);
        $this->assertStringContainsString('<h3 class="naglowek-grupy">Farsz</h3>', $sekcja);
    }

    public function test_przepis_bez_grup_wyglada_jak_dotad(): void
    {
        $przepis = $this->przepisZeSkladnikami([
            ['tekst' => 'kurczak', 'grupa' => null],
            ['tekst' => 'włoszczyzna', 'grupa' => null],
        ]);

        $sekcja = $this->sekcjaSkladnikow($przepis);

        $this->assertLessThan(
            $this->pozycja($sekcja, 'włoszczyzna'),
            $this->pozycja($sekcja, 'kurczak'),
        );

        // Ani jednego nagłówka grupy i DOKŁADNIE JEDNA lista: przepis bez
        // grup nie może wyglądać na przepis z niewypełnionym polem.
        $this->assertStringNotContainsString('naglowek-grupy', $sekcja);
        $this->assertSame(1, substr_count($sekcja, 'class="ingredient-list"'));
    }

    public function test_strona_przepisu_scala_grupe_rozrzucona_po_liscie(): void
    {
        // Stan, którego baza nie zabrania (CHECK nie widzi sąsiednich
        // wierszy) i który potrafi zrobić import albo konsola:
        // „Ciasto, Farsz, Ciasto" plus literówka w wielkości liter.
        $przepis = $this->przepisZeSkladnikami([
            ['tekst' => 'mąka', 'grupa' => 'Ciasto'],
            ['tekst' => 'ziemniaki', 'grupa' => 'Farsz'],
            ['tekst' => 'gorąca woda', 'grupa' => 'ciasto'],
        ]);

        $sekcja = $this->sekcjaSkladnikow($przepis);

        // Jeden nagłówek „Ciasto", nie dwa — i w pisowni autora z PIERWSZEGO
        // wystąpienia. Dwa takie same nagłówki na jednej stronie nie wyglądają
        // na dane do poprawienia, tylko na usterkę serwisu.
        $this->assertSame(1, substr_count($sekcja, '<h3 class="naglowek-grupy">Ciasto</h3>'));
        $this->assertSame(0, substr_count($sekcja, '<h3 class="naglowek-grupy">ciasto</h3>'));

        // Woda wraca pod „Ciasto", czyli pod nagłówek, który stoi WYŻEJ niż
        // „Farsz" — scalenie nie przenosi grupy na koniec listy.
        $this->assertLessThan(
            $this->pozycja($sekcja, 'Farsz'),
            $this->pozycja($sekcja, 'gorąca woda'),
        );
    }

    // -----------------------------------------------------------------
    // 4. Sama reguła układu — bez HTTP i bez bazy
    // -----------------------------------------------------------------

    public function test_uklad_grup_bez_grupy_na_gorze_i_w_kolejnosci_pierwszego_wystapienia(): void
    {
        $ulozone = GrupySkladnikow::ulozyc([
            ['group_name' => 'Ciasto', 'ingredient_text' => 'mąka'],
            ['group_name' => 'Farsz', 'ingredient_text' => 'twaróg'],
            ['group_name' => null, 'ingredient_text' => 'sól'],
            ['group_name' => 'ciasto', 'ingredient_text' => 'woda'],
        ]);

        $this->assertSame([null, 'Ciasto', 'Farsz'], array_column($ulozone, 'nazwa'));
        $this->assertSame(['sól'], array_column($ulozone[0]['skladniki'], 'ingredient_text'));
        $this->assertSame(['mąka', 'woda'], array_column($ulozone[1]['skladniki'], 'ingredient_text'));
        $this->assertSame(['twaróg'], array_column($ulozone[2]['skladniki'], 'ingredient_text'));
    }

    public function test_uklad_grup_bez_zadnej_grupy_to_jedna_lista_bez_naglowka(): void
    {
        $ulozone = GrupySkladnikow::ulozyc([
            ['group_name' => null, 'ingredient_text' => 'kurczak'],
            // Pusty ciąg i same spacje znaczą „bez grupy", nie „grupa
            // o pustej nazwie": nagłówek bez tekstu to w czytniku ekranu
            // „nagłówek poziomu trzeciego" i cisza.
            ['group_name' => '', 'ingredient_text' => 'włoszczyzna'],
            ['group_name' => '   ', 'ingredient_text' => 'sól'],
        ]);

        $this->assertCount(1, $ulozone);
        $this->assertNull($ulozone[0]['nazwa']);
        $this->assertCount(3, $ulozone[0]['skladniki']);
    }

    // -----------------------------------------------------------------
    // Narzędzia
    // -----------------------------------------------------------------

    private function migracja(): object
    {
        return require database_path(self::MIGRACJA);
    }

    /**
     * Sam kawałek strony ze składnikami.
     *
     * Kolejność mierzymy TYLKO tutaj, bo w `<head>` stoi structured data
     * (`recipeIngredient`) z tą samą listą w kolejności zapisu — pomiar na
     * całej stronie trafiałby najpierw w nią i mówiłby o czymś innym, niż
     * widzi człowiek.
     */
    private function sekcjaSkladnikow(Recipe $przepis): string
    {
        $html = (string) $this->get(route('recipes.show', $przepis->slug))->assertOk()->getContent();
        $poczatek = strpos($html, '<h2>Składniki</h2>');

        $this->assertNotFalse($poczatek, 'Na stronie przepisu nie ma nagłówka „Składniki".');

        return substr($html, (int) $poczatek);
    }

    /** Pozycja fragmentu w tekście — z jasnym komunikatem, gdy go nie ma. */
    private function pozycja(string $tekst, string $szukane): int
    {
        $pozycja = strpos($tekst, $szukane);

        $this->assertNotFalse($pozycja, 'Na stronie nie ma tego fragmentu: '.$szukane);

        return (int) $pozycja;
    }

    private function checkIstnieje(): bool
    {
        return DB::select('SELECT conname FROM pg_constraint WHERE conname = ?', [self::CHECK]) !== [];
    }

    /** @param  list<array{tekst: string, grupa: ?string}>  $skladniki */
    private function przepisZeSkladnikami(array $skladniki): Recipe
    {
        $przepis = Recipe::factory()->for($this->user(), 'author')->create([
            'title' => 'Przepis z grupami',
            'status' => Recipe::STATUS_PUBLISHED,
            'visibility' => 'public',
            'published_at' => now()->subDay(),
        ]);

        foreach ($skladniki as $position => $skladnik) {
            RecipeIngredient::create([
                'recipe_id' => $przepis->getKey(),
                'position' => $position,
                'group_name' => $skladnik['grupa'],
                'ingredient_text' => $skladnik['tekst'],
            ]);
        }

        RecipeStep::create([
            'recipe_id' => $przepis->getKey(),
            'position' => 0,
            'instruction' => 'Wymieszaj i gotuj.',
        ]);

        return $przepis;
    }
}

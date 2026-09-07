<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ilość i jednostka składnika — BRAMKA DOMENOWA, nie pole formularza.
 *
 * CO TU JEST, A CZEGO TU NIE MA (audyt zewnętrzny T12/T24)
 * Audyt zauważył, że `recipe_ingredients.quantity` i `unit_id` istnieją od
 * pierwszej migracji, a żadna droga zapisu ich nie ustawia. Nie ustawia ich
 * ŚWIADOMIE: D-017 (obowiązuje, 6 września 2026) rozstrzygnął, że składnik
 * zostaje jednym polem wolnego tekstu, bo „szczypta soli" i „tyle, żeby
 * ciasto było miękkie" nie mają pola na ilość — a formularz, który każe je
 * rozbić, każe też zdecydować, czego NIE zapisać. Ta decyzja mówi wprost,
 * czego wymagałaby zmiana: danych z realnego użycia albo parsera/AI, który
 * proponuje rozbicie JAKO PODPOWIEDŹ DO POTWIERDZENIA.
 *
 * Prawdziwy błąd był więc inny i jest naprawiony niżej: wartość PRZEKAZANA
 * do akcji domenowej szła do bazy BEZ ŻADNEGO SPRAWDZENIA. Ujemna trafiała
 * w CHECK, nieznana jednostka w klucz obcy, tekst w błąd rzutowania
 * Postgresa, a zbyt duża liczba w „numeric field overflow" — czyli w błąd
 * 500 i utratę pracy autora, nie w zdanie mówiące, co poprawić. Drogi, które
 * tam wchodzą, są prawdziwe: konsola, fabryka, przyszły import i przyszła
 * podpowiedź AI (AGENTS.md §9).
 */
class IloscIJednostkaSkladnikaTest extends TestCase
{
    use RefreshDatabase;

    public function test_ilosc_i_jednostka_przekazane_do_akcji_domenowej_zapisuja_sie(): void
    {
        $jednostka = $this->jednostka('ml', 'mililitr');

        $przepis = $this->publikuj([
            ['text' => 'mleko', 'quantity' => '200', 'unit_id' => (string) $jednostka->getKey()],
            // Polski zapis liczby dziesiętnej — „1,5 kg" to nie błąd człowieka.
            ['text' => 'mąka', 'quantity' => '1,5'],
            ['text' => 'sól'],
        ]);

        $skladniki = $przepis->ingredients()->orderBy('position')->get();

        // POZYTYWNA: liczba i jednostka doszły do bazy.
        $this->assertSame(200.0, $skladniki[0]->quantity);
        $this->assertSame($jednostka->getKey(), $skladniki[0]->unit_id);

        // POZYTYWNA: przecinek dziesiętny przeliczony, nie odrzucony.
        $this->assertSame(1.5, $skladniki[1]->quantity);

        // KONTROLNA: składnik bez ilości zostaje bez ilości — tak wygląda
        // KAŻDY składnik z formularza przepisu (D-017), więc to jest wciąż
        // najczęstszy przypadek w tabeli.
        $this->assertNull($skladniki[2]->quantity);
        $this->assertNull($skladniki[2]->unit_id);
    }

    public function test_formularz_przepisu_nadal_o_ilosc_nie_pyta(): void
    {
        // D-017 jest decyzją O PRODUKCIE, więc pilnuje jej test, a nie tylko
        // dokument: gdyby pole ilości pojawiło się w formularzu „przy okazji",
        // ten test upadnie i każe wrócić do decyzji.
        $basia = $this->user('basia');

        $strona = $this->actingAs($basia)->get(route('recipes.create.simple'))->assertOk();

        $strona->assertDontSee('name="ingredients[0][quantity]"', false);
        $strona->assertDontSee('name="ingredients[0][unit_id]"', false);

        // KONTROLNA: pole składnika w ogóle na tej stronie jest — inaczej test
        // nie sprawdzałby niczego.
        $strona->assertSee('name="ingredients[0][text]"', false);
    }

    /**
     * Każda wartość ma PRZYPISANE zdanie, a nie „jakiś błąd": „to nie jest
     * liczba" przy wartości ujemnej byłoby nieprawdą i nie mówiłoby, co
     * poprawić.
     *
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function bezsensowneIlosci(): array
    {
        $nieLiczba = 'Ilość składnika musi być liczbą, na przykład 1,5. '
            .'Jeśli składnik nie ma wymiernej ilości, zaznacz „Bez ilości”.';

        return [
            'ujemna' => ['-1', 'Ilość składnika nie może być ujemna. Wpisz na przykład 1,5.'],
            'nierealnie duża' => ['1000000000', 'Ta ilość jest nierealna. Wpisz mniejszą liczbę.'],
            'tekst' => ['szklanka', $nieLiczba],
            'tablica' => [['1'], $nieLiczba],
            'prawda' => [true, $nieLiczba],
        ];
    }

    #[DataProvider('bezsensowneIlosci')]
    public function test_bezsensowna_ilosc_konczy_sie_komunikatem_a_nie_bledem_bazy(mixed $ilosc, string $komunikat): void
    {
        try {
            $this->publikuj([['text' => 'mleko', 'quantity' => $ilosc]]);
            $this->fail('Bezsensowna ilość przeszła przez bramkę domenową.');
        } catch (BladDlaCzlowieka $e) {
            // NEGATYWNA: zdanie po polsku, mówiące co zrobić — nie wyjątek
            // SQL-a, nie 500.
            $this->assertSame($komunikat, $e->getMessage());
        }

        // NEGATYWNA: nic nie zostało zapisane.
        $this->assertSame(0, RecipeIngredient::count());
    }

    public function test_nieznana_jednostka_konczy_sie_komunikatem_a_nie_bledem_klucza_obcego(): void
    {
        try {
            $this->publikuj([
                ['text' => 'mleko', 'quantity' => '200', 'unit_id' => (string) Str::uuid()],
            ]);
            $this->fail('Nieznana jednostka przeszła przez bramkę domenową.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(
                'Nie znam tej jednostki miary. Zostaw ilość bez jednostki.',
                $e->getMessage(),
            );
        }

        // NEGATYWNA: nic nie zostało zapisane.
        $this->assertSame(0, RecipeIngredient::count());

        // KONTROLNA: jednostka ZE SŁOWNIKA przechodzi tą samą drogą.
        $jednostka = $this->jednostka('szkl', 'szklanka');

        $przepis = $this->publikuj([
            ['text' => 'mleko', 'quantity' => '1', 'unit_id' => (string) $jednostka->getKey()],
        ]);

        $this->assertSame($jednostka->getKey(), $przepis->ingredients()->firstOrFail()->unit_id);
    }

    public function test_baza_odrzuca_ujemna_ilosc_wpisana_wprost(): void
    {
        // CHECK w bazie to ostatnia linia obrony — dla drogi, która ominie
        // i formularz, i akcję domenową (ręczne SQL, migracja danych).
        $przepis = Recipe::factory()->create(['author_id' => $this->user('basia')->getKey()]);

        $this->expectException(QueryException::class);

        RecipeIngredient::create([
            'recipe_id' => $przepis->getKey(),
            'ingredient_text' => 'mleko',
            'quantity' => -1,
            'position' => 0,
        ]);
    }

    public function test_bez_ilosci_nadal_wygrywa_z_wpisana_iloscia(): void
    {
        // Regresja na issue #44: „bez ilości" i wpisana ilość nie kłócą się —
        // ilość odpada. Nowa bramka nie ma prawa tego zmienić, bo wtedy
        // wiersz trafiłby w CHECK `no_amount = false OR quantity IS NULL`.
        $przepis = $this->publikuj([
            ['text' => 'sól', 'no_amount' => true, 'quantity' => '200'],
        ]);

        $sol = $przepis->ingredients()->firstOrFail();

        $this->assertTrue($sol->no_amount);
        $this->assertNull($sol->quantity);
        $this->assertNull($sol->unit_id);
    }

    public function test_bez_ilosci_nie_sprawdza_wartosci_ktora_i_tak_wyrzuca(): void
    {
        // KONTROLNA dla kolejności bramek: przy „bez ilości" ilość jest
        // kasowana, więc jej sprawdzanie byłoby proszeniem człowieka
        // o poprawienie wartości, której nikt nie zapisze.
        $przepis = $this->publikuj([
            ['text' => 'sól', 'no_amount' => true, 'quantity' => 'do smaku'],
        ]);

        $this->assertNull($przepis->ingredients()->firstOrFail()->quantity);
    }

    /**
     * @param  list<array<string, mixed>>  $ingredients
     */
    private function publikuj(array $ingredients): Recipe
    {
        return app(PublishRecipe::class)->handle(
            author: $this->user('basia'.Str::random(4)),
            attributes: ['title' => 'Przepis '.Str::random(6), 'visibility' => 'public', 'source_type' => 'own'],
            ingredients: $ingredients,
            steps: [['instruction' => 'Wymieszaj.']],
            publish: true,
        );
    }

    private function jednostka(string $code, string $name): Unit
    {
        return Unit::query()->where('code', $code)->first()
            ?? Unit::create(['code' => $code, 'name' => $name]);
    }
}

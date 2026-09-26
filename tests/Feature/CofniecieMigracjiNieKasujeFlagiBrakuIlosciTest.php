<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * `migrate:rollback` nie kasuje po cichu oznaczenia „bez wymiernej ilości".
 *
 * SKĄD TO SIĘ WZIĘŁO
 * Migracja `2026_09_06_130000_add_no_amount_to_recipe_ingredients` dokłada
 * kolumnę `no_amount` (issue #44) i jej `down()` zdejmowało CHECK i kolumnę
 * bez żadnego pytania. Dopóki nic tej flagi nie czytało, było to bezstratne.
 * Od skalowania porcji (V2, PR #1884) `no_amount` rozstrzyga, których
 * składników NIE mnożyć przy przeliczeniu — a tego rozstrzygnięcia nie da
 * się odtworzyć z samego tekstu składnika, bo „sól do smaku" i „sól 5 g"
 * wyglądają w `ingredient_text` identycznie. Strażnik w `down()` (D-088)
 * odmawia cofnięcia, gdy w tabeli są już takie składniki.
 *
 * Test sprawdza OBIE strony i furtkę, tak jak
 * `CofniecieMigracjiNumerSprawyTest`: sama odmowa nie wystarczy, bo migracja,
 * która nigdy się nie cofa, blokowałaby staging i lokalne bazy bez nic do
 * stracenia.
 */
class CofniecieMigracjiNieKasujeFlagiBrakuIlosciTest extends TestCase
{
    use RefreshDatabase;

    private const FURTKA = 'KUKING_ROLLBACK_KASUJE_SKLADNIKI_BEZ_ILOSCI';

    private function migracja(): object
    {
        return require database_path(
            'migrations/2026_09_06_130000_add_no_amount_to_recipe_ingredients.php',
        );
    }

    private function przepisZeSkladnikiemBezIlosci(): Recipe
    {
        return app(PublishRecipe::class)->handle(
            author: $this->user('basia'.Str::random(6)),
            attributes: ['title' => 'Rosół '.Str::random(6), 'visibility' => 'public', 'source_type' => 'own'],
            ingredients: [
                ['text' => 'sól', 'no_amount' => true],
            ],
            steps: [['instruction' => 'Wymieszaj.']],
            publish: true,
        );
    }

    private function kolumnaIstnieje(): bool
    {
        return DB::select(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['recipe_ingredients', 'no_amount'],
        ) !== [];
    }

    public function test_cofniecie_odmawia_gdy_sa_skladniki_bez_ilosci(): void
    {
        $przepis = $this->przepisZeSkladnikiemBezIlosci();
        $skladnik = $przepis->ingredients()->firstOrFail();

        $this->assertTrue($skladnik->no_amount, 'Składnik nie ma `no_amount = true` — test sprawdzałby pustkę.');

        // ODMOWĘ ODKŁADAMY DO ZMIENNEJ, A OCENIAMY POZA BLOKIEM (D-133):
        // `$this->fail()` rzuca `AssertionFailedError`, a ta dziedziczy przez
        // `PHPUnit\Framework\Exception` po `RuntimeException`, więc
        // postawiona wewnątrz `try` wpadłaby do własnego `catch`.
        $odmowa = null;

        try {
            $this->migracja()->down();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie przeszło i skasowało kolumnę `no_amount`.');

        // JEDEN składnik, nie pięć — rzeczownik w mianowniku przed liczbą na
        // końcu zdania, więc jedynka jest tu zdaniem poprawnym po polsku
        // (D-132) i wolno ją zamrozić w teście.
        $this->assertStringContainsString(
            'Liczba składników oznaczonych jako „bez wymiernej ilości" (`no_amount = true`) '
            .'w tabeli `recipe_ingredients`: 1.',
            $odmowa->getMessage(),
        );

        $this->assertStringContainsString('kopię tabeli', $odmowa->getMessage());
        $this->assertStringContainsString(self::FURTKA, $odmowa->getMessage());

        // ASERCJA KONTROLNA: odmowa, która i tak zdążyła skasować kolumnę,
        // byłaby tylko ładniejszym komunikatem o stracie.
        $this->assertTrue($this->kolumnaIstnieje(), 'Kolumna `no_amount` zniknęła mimo odmowy.');
        $this->assertTrue(
            $skladnik->refresh()->no_amount,
            'Wartość `no_amount` zmieniła się mimo odmowy cofnięcia.',
        );
    }

    public function test_na_swiezym_srodowisku_cofniecie_dziala_bez_pytania(): void
    {
        // Nie ma żadnego składnika „bez ilości", więc nie ma o co pytać.
        $this->assertSame(
            0,
            DB::table('recipe_ingredients')->where('no_amount', true)->count(),
            'Test startuje z niepustą tabelą — mierzyłby co innego, niż zakłada.',
        );

        $this->assertTrue($this->kolumnaIstnieje(), 'Kolumny nie było już przed cofnięciem.');

        $this->migracja()->down();

        $this->assertFalse($this->kolumnaIstnieje(), 'Cofnięcie nie zdjęło kolumny `no_amount`.');
    }

    public function test_furtka_ze_srodowiska_procesu_przepuszcza_cofniecie(): void
    {
        $this->przepisZeSkladnikiemBezIlosci();

        // Furtkę podaje się w środowisku procesu:
        // `KUKING_ROLLBACK_KASUJE_SKLADNIKI_BEZ_ILOSCI=1 php artisan migrate:rollback`.
        // Strażnik czyta ją przez `getenv()`, tak samo jak pozostałe migracje
        // z furtką — gdyby ktoś wrócił do odczytu przez warstwę konfiguracji,
        // ta droga przestałaby działać dokładnie na produkcji, gdzie jest
        // potrzebna.
        $poprzednia = getenv(self::FURTKA);
        putenv(self::FURTKA.'=1');

        try {
            $this->migracja()->down();

            $this->assertFalse(
                $this->kolumnaIstnieje(),
                'Furtka nie zadziałała: kolumna została mimo jawnej zgody.',
            );
        } finally {
            if ($poprzednia === false) {
                putenv(self::FURTKA);
            } else {
                putenv(self::FURTKA.'='.$poprzednia);
            }
        }

        // ASERCJA KONTROLNA: furtka ma przepuszczać TYLKO wtedy, gdy jest
        // ustawiona — bez sprzątnięcia po sobie kolejny test w tym samym
        // procesie dostałby cofnięcie bez pytania i nie zauważyłby tego.
        $this->assertNotSame('1', getenv(self::FURTKA), 'Zmienna furtki wyciekła poza ten test.');
    }
}

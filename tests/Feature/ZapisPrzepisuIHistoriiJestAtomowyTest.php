<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Recipes\Actions\PublishRecipe;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Zapis przepisu i zapis jego HISTORII są jedną operacją (audyt A01, P1).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  CO BYŁO ZMIERZONE PRZED POPRAWKĄ
 * ══════════════════════════════════════════════════════════════════════
 *
 * Audyt z 11.09.2026 postawił A01 na podstawie „analiza kodu; bez testu
 * awarii w pełnej aplikacji" — a `findings.json` tej paczki ma
 * `full_local_application_tests_run: false`. Czyli oba jego P1 były
 * ROZUMOWANIEM. Ten plik zamienia jedno z nich w pomiar.
 *
 * `PublishRecipe::handle()` zamykał transakcję, a DOPIERO POTEM wołał
 * `SnapshotRecipeVersion`:
 *
 *     $recipe = DB::transaction(function () { … });   // linia 255
 *
 *     if ($publish) {
 *         $this->snapshots->handle($recipe, …);        // linia 258 — POZA
 *         AuditLogEntry::record('recipe.published', …); //            transakcją
 *     }
 *
 * Zmierzone tym testem przeciwko kodowi sprzed poprawki (awaria wymuszona
 * przez `DB::beforeExecuting()` na `insert into "recipe_versions"`):
 *
 *     przepis w bazie: 1     wersji w historii: 0     wpisów w audycie: 0
 *
 * Czyli dokładnie to, co audyt przewidział: **człowiek dostaje błąd, a treść
 * mimo to zmieniła się publicznie** — i nie ma wersji, z której dałoby się ją
 * odtworzyć. Przy PIERWSZEJ publikacji zostaje opublikowany przepis, którego
 * historia jest pusta; przy edycji — nowa treść bez śladu poprzedniej.
 *
 * Dlaczego `DB::beforeExecuting()`, a nie atrapa snapshotu: `SnapshotRecipeVersion`
 * jest `final`, a przede wszystkim atrapa mierzyłaby atrapę. Ten hook przerywa
 * PRAWDZIWY zapis PRAWDZIWEJ klasy, w prawdziwym miejscu — przed wykonaniem
 * `INSERT`-a, nie po nim (`DB::listen()` odpala się PO zapytaniu, więc wiersz
 * byłby już wstawiony i test mierzyłby inną awarię niż ta z audytu).
 *
 * ── CZEGO TEN PLIK NIE DOWODZI ──
 *
 * Nie dowodzi niczego o DWÓCH POŁĄCZENIACH (`docs/PULAPKI_TESTOW.md` §6).
 * `RefreshDatabase` trzyma cały test w niezatwierdzonej transakcji, więc
 * kolizji numerów wersji między dwoma równoległymi edycjami tu nie zobaczysz
 * — na to jest `tests/Dwa/NumerWersjiPrzepisuNieKolidujeTest.php` z grupy
 * `dwa-polaczenia` (D-105). Ten plik mierzy ATOMOWOŚĆ, tamten SERIALIZACJĘ.
 */
class ZapisPrzepisuIHistoriiJestAtomowyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Przerywa PIERWSZY zapis do `recipe_versions` — czyli dokładnie to
     * miejsce, o którym audyt pisze „wyjątek podczas tworzenia historii".
     */
    private function zepsujZapisHistorii(): void
    {
        DB::beforeExecuting(function (string $zapytanie): void {
            if (str_contains($zapytanie, 'insert into "recipe_versions"')) {
                throw new RuntimeException('awaria zapisu historii wymuszona testem');
            }
        });
    }

    /**
     * @param  array<string, mixed>  $nadpisania
     */
    private function publikuj(array $nadpisania = []): void
    {
        $this->post(route('recipes.store'), [
            'action' => 'publish',
            'title' => 'Rosół babci Zofii',
            'visibility' => 'public',
            'source_type' => 'own',
            'ingredients' => [['text' => '1 kurczak'], ['text' => 'włoszczyzna']],
            'steps' => [['instruction' => 'Zalej wodą i gotuj trzy godziny.']],
            ...$nadpisania,
        ]);
    }

    /**
     * KONTROLA DODATNIA CAŁEGO PLIKU (`docs/PULAPKI_TESTOW.md` §4).
     *
     * Trzy testy niżej sprawdzają, że po awarii CZEGOŚ NIE MA. Przeszłyby
     * także wtedy, gdyby publikacja nie działała nigdy i nic nigdy nie
     * powstawało. Ten test mówi, że mechanizm w ogóle pracuje.
     */
    public function test_zwykla_publikacja_tworzy_przepis_i_jego_pierwsza_wersje(): void
    {
        $this->actingAs($this->user('autorka'));

        $this->publikuj();

        $przepis = Recipe::where('title', 'Rosół babci Zofii')->firstOrFail();

        $this->assertTrue($przepis->isPublished());
        $this->assertSame(1, $przepis->versions()->count());
        $this->assertSame(1, (int) $przepis->versions()->max('version_number'));
        $this->assertDatabaseHas('audit_log', ['action' => 'recipe.published']);
    }

    public function test_awaria_historii_nie_zostawia_opublikowanego_przepisu_bez_wersji(): void
    {
        $this->actingAs($this->user('autorka'));

        // BEZ TEGO TEST MIERZY CO INNEGO. Laravel w testach HTTP renderuje
        // wyjątek jako 500 i nie wypuszcza go do testu — a wtedy `catch`
        // łapie nie awarię historii, tylko `AssertionFailedError` z `fail()`,
        // bo bazowy wyjątek PHPUnita dziedziczy po `RuntimeException`. Tak
        // przeszła pierwsza wersja tego testu i „mierzyła" sama siebie.
        $this->withoutExceptionHandling();
        $this->zepsujZapisHistorii();

        $zlapany = null;

        try {
            $this->publikuj();
        } catch (RuntimeException $e) {
            $zlapany = $e;
        }

        $this->assertNotNull($zlapany, 'Awaria zapisu historii nie przerwała publikacji — test nie zmierzył tego, co miał.');
        $this->assertStringContainsString('awaria zapisu historii', $zlapany->getMessage());

        // TO JEST CAŁE ZNALEZISKO A01. Przed poprawką stało tu „1 / 0 / 0":
        // przepis widoczny publicznie, historia pusta, audyt pusty.
        $this->assertSame(0, Recipe::query()->count(), 'Przepis został zapisany, mimo że historia nie powstała.');
        $this->assertSame(0, RecipeVersion::query()->count());
        $this->assertDatabaseMissing('audit_log', ['action' => 'recipe.published']);
    }

    public function test_awaria_historii_nie_zostawia_zmienionej_tresci_opublikowanego_przepisu(): void
    {
        $autorka = $this->user('autorka');
        $this->actingAs($autorka);

        $this->publikuj();

        $przepis = Recipe::where('title', 'Rosół babci Zofii')->firstOrFail();
        $this->assertSame(1, $przepis->versions()->count());

        $this->withoutExceptionHandling();
        $this->zepsujZapisHistorii();

        $zlapany = null;

        try {
            $this->put(route('recipes.update', $przepis), [
                'action' => 'publish',
                'title' => 'Rosół babci Zofii — poprawiony',
                'visibility' => 'public',
                'source_type' => 'own',
                'ingredients' => [['text' => '1 kurczak'], ['text' => 'włoszczyzna'], ['text' => 'lubczyk']],
                'steps' => [['instruction' => 'Zalej wodą i gotuj trzy godziny.']],
            ]);
        } catch (RuntimeException $e) {
            $zlapany = $e;
        }

        $this->assertNotNull($zlapany, 'Awaria zapisu historii nie przerwała edycji — test nie zmierzył tego, co miał.');

        $przepis->refresh();

        // Treść PUBLICZNA musi wrócić do stanu sprzed nieudanej edycji —
        // razem ze składnikami, bo `syncIngredients()` kasuje je i odtwarza.
        $this->assertSame('Rosół babci Zofii', $przepis->title);
        $this->assertSame(2, $przepis->ingredients()->count());
        $this->assertSame(1, $przepis->versions()->count());
    }

    /**
     * Asercja KONSTRUKCYJNA, nie skutkowa: snapshot wykonuje się wewnątrz
     * transakcji zapisu, a nie po niej.
     *
     * Poziom transakcji jest tu lepszą miarą niż stan bazy, bo nie zależy od
     * tego, jaka awaria akurat się przydarzy. Pod `RefreshDatabase` cały test
     * siedzi na poziomie 1 — więc zapis historii WEWNĄTRZ transakcji akcji
     * musi go widzieć wyższym niż ten, który widać z testu.
     */
    public function test_historia_zapisuje_sie_wewnatrz_transakcji_zapisu_przepisu(): void
    {
        $this->actingAs($this->user('autorka'));

        $poziomWTescie = DB::transactionLevel();
        $poziomPrzyHistorii = null;

        DB::beforeExecuting(function (string $zapytanie) use (&$poziomPrzyHistorii): void {
            if ($poziomPrzyHistorii === null && str_contains($zapytanie, 'insert into "recipe_versions"')) {
                $poziomPrzyHistorii = DB::transactionLevel();
            }
        });

        $this->publikuj();

        $this->assertNotNull($poziomPrzyHistorii, 'Historia się nie zapisała — test nie zmierzył niczego.');
        $this->assertGreaterThan(
            $poziomWTescie,
            $poziomPrzyHistorii,
            'Snapshot wykonał się POZA transakcją zapisu przepisu — to jest znalezisko A01.',
        );
    }

    /**
     * REWALIDACJA POD BLOKADĄ (D-079 §2: „blokada bez rewalidacji pod nią nie
     * pilnuje niczego").
     *
     * Przeplot wymuszony tak, jak dopuszcza `docs/PULAPKI_TESTOW.md` §6:
     * model wczytany, stan w bazie zmieniony obok modelu, dopiero potem zapis.
     * Odpowiada temu, co robi moderator ukrywający przepis w chwili, w której
     * autor ma otwarty formularz edycji — a to nie jest przypadek brzegowy,
     * bo decyzja moderacyjna ma być trwała (`RecipeStatusTransitions`).
     */
    public function test_ukrycie_przepisu_w_trakcie_zapisu_zatrzymuje_zapis(): void
    {
        $autorka = $this->user('autorka');
        $this->actingAs($autorka);

        $this->publikuj();

        $przepis = Recipe::where('title', 'Rosół babci Zofii')->firstOrFail();

        // Model w PHP nadal mówi `published`; baza mówi już `hidden`.
        Recipe::query()->whereKey($przepis->getKey())->update(['status' => Recipe::STATUS_HIDDEN]);
        $this->assertSame(Recipe::STATUS_PUBLISHED, $przepis->status);

        $zlapany = null;

        try {
            app(PublishRecipe::class)->handle(
                author: $autorka,
                attributes: ['title' => 'Rosół babci Zofii — poprawiony', 'visibility' => 'public', 'source_type' => 'own'],
                ingredients: [['text' => '1 kurczak']],
                steps: [['instruction' => 'Zalej wodą.']],
                publish: true,
                existing: $przepis,
            );
        } catch (BladDlaCzlowieka $e) {
            $zlapany = $e;
        }

        $this->assertNotNull($zlapany, 'Zapis przeszedł na przepisie ukrytym przez moderację — rewalidacja pod blokadą nie działa.');
        $this->assertStringContainsString('ukryty przez moderację', $zlapany->getMessage());

        $przepis->refresh();

        $this->assertSame('Rosół babci Zofii', $przepis->title);
        $this->assertSame(Recipe::STATUS_HIDDEN, $przepis->status);
        $this->assertSame(1, $przepis->versions()->count());
    }
}

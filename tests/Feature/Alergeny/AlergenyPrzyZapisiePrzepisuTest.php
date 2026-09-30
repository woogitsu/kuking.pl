<?php

declare(strict_types=1);

namespace Tests\Feature\Alergeny;

use App\Domain\Import\Actions\ZapiszSzkicZImportu;
use App\Domain\Import\OdczytanyPrzepis;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Domain\Recipes\Actions\ZrobWlasnaWersje;
use App\Domain\Recipes\Alergeny\DeklaracjaAlergenow;
use App\Domain\Recipes\TrescPrzepisu;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\PrzepisZImportu;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Oznaczenie alergenów przy zapisie przepisu (#1902): przejście `declared` → `needs_review`,
 * brak fałszywego `declared`, migawka i odcisk treści.
 *
 * KONTROLA UJEMNA (ręcznie): (1) zdjęcie wywołania `uzgodnijAlergeny()` z `PublishRecipe`
 * oblewa `test_zmiana_tekstu_skladnika_po_potwierdzeniu_przenosi_do_przegladu`; (2) zamiana
 * `Ingredient::normalize()` na surowy tekst w `odciskSkladnikowDlaAlergenow()` oblewa
 * `test_sama_zmiana_wielkosci_liter_i_spacji_nie_uniewaznia`; (3) usunięcie `allergen_status`
 * z migawki oblewa `test_migawka_niesie_stan_i_liste_razem`.
 */
final class AlergenyPrzyZapisiePrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private User $autor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->autor = $this->user('autor_zapisu_alergenow');
    }

    private function publish(): PublishRecipe
    {
        return app(PublishRecipe::class);
    }

    /**
     * @param  list<string>  $skladniki
     */
    private function zapisz(array $skladniki, ?Recipe $istniejacy = null, ?DeklaracjaAlergenow $deklaracja = null, bool $publikuj = true): Recipe
    {
        return $this->publish()->handle(
            author: $this->autor,
            attributes: ['title' => 'Naleśniki babci', 'visibility' => 'public'],
            ingredients: array_map(static fn (string $t): array => ['text' => $t], $skladniki),
            steps: [['instruction' => 'Usmaż na patelni.']],
            publish: $publikuj,
            existing: $istniejacy,
            deklaracjaAlergenow: $deklaracja,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $skladniki
     */
    private function zapiszWiersze(array $skladniki, ?Recipe $istniejacy = null, ?DeklaracjaAlergenow $deklaracja = null): Recipe
    {
        return $this->publish()->handle(
            author: $this->autor,
            attributes: ['title' => 'Naleśniki babci', 'visibility' => 'public'],
            ingredients: $skladniki,
            steps: [['instruction' => 'Usmaż na patelni.']],
            publish: true,
            existing: $istniejacy,
            deklaracjaAlergenow: $deklaracja,
        );
    }

    private function zdeklarowany(): Recipe
    {
        return $this->zapisz(
            ['200 g mąki pszennej', '2 jajka', 'szklanka mleka'],
            deklaracja: new DeklaracjaAlergenow(['gluten', 'eggs', 'milk'], true),
        );
    }

    public function test_nowy_przepis_bez_deklaracji_jest_niesprawdzony(): void
    {
        $przepis = $this->zapisz(['mąka', 'mleko'])->refresh();

        $this->assertSame('unchecked', $przepis->allergen_status);
        $this->assertSame([], $przepis->allergens);
    }

    public function test_deklaracja_w_zapisie_ustawia_declared(): void
    {
        $przepis = $this->zdeklarowany()->refresh();

        $this->assertSame('declared', $przepis->allergen_status);
        $this->assertSame(['gluten', 'eggs', 'milk'], $przepis->allergens);
    }

    public function test_zaznaczenie_bez_potwierdzenia_w_zapisie_jest_odrzucone_i_nic_nie_zapisuje(): void
    {
        $this->expectException(BladDlaCzlowieka::class);

        try {
            $this->zapisz(['mąka'], deklaracja: new DeklaracjaAlergenow(['gluten'], false));
        } finally {
            $this->assertSame(0, Recipe::query()->count(), 'Odrzucony zapis zostawił przepis — transakcja się nie cofnęła.');
        }
    }

    public function test_zmiana_tekstu_skladnika_po_potwierdzeniu_przenosi_do_przegladu(): void
    {
        $przepis = $this->zdeklarowany();

        $przepis = $this->zapisz(['200 g mąki pszennej', '2 jajka', 'szklanka mleka sojowego'], $przepis)->refresh();

        $this->assertSame('needs_review', $przepis->allergen_status);
        // Lista zostaje zapisana (autor ją zobaczy przy ponownym potwierdzeniu),
        // ale stan nie jest `declared`, więc czytelnik jej nie dostaje.
        $this->assertSame(['gluten', 'eggs', 'milk'], $przepis->allergens);
        $this->assertFalse($przepis->alergenyZdeklarowane());
    }

    public function test_sama_zmiana_wielkosci_liter_i_spacji_nie_uniewaznia(): void
    {
        $przepis = $this->zdeklarowany();

        $przepis = $this->zapisz(['200 G MĄKI   PSZENNEJ', '2 jajka ', '  szklanka mleka'], $przepis)->refresh();

        $this->assertSame('declared', $przepis->allergen_status);
    }

    public function test_zmiana_uwagi_przy_skladniku_po_potwierdzeniu_przenosi_do_przegladu(): void
    {
        $przepis = $this->zapiszWiersze(
            [['text' => '200 g mąki pszennej'], ['text' => 'pół szklanki oleju', 'note' => 'do smażenia']],
            deklaracja: new DeklaracjaAlergenow(['gluten'], true),
        );
        $this->assertSame('declared', $przepis->refresh()->allergen_status);

        $przepis = $this->zapiszWiersze(
            [['text' => '200 g mąki pszennej'], ['text' => 'pół szklanki oleju', 'note' => 'posyp startym serem']],
            $przepis,
        )->refresh();

        $this->assertSame('needs_review', $przepis->allergen_status);
        $this->assertFalse($przepis->alergenyZdeklarowane());
    }

    public function test_zmiana_nazwy_grupy_skladnikow_nie_uniewaznia(): void
    {
        $przepis = $this->zapiszWiersze(
            [['text' => '200 g mąki pszennej', 'group_name' => 'Ciasto'], ['text' => '2 jajka', 'group_name' => 'Ciasto']],
            deklaracja: new DeklaracjaAlergenow(['gluten', 'eggs'], true),
        );

        $przepis = $this->zapiszWiersze(
            [['text' => '200 g mąki pszennej', 'group_name' => 'Masa'], ['text' => '2 jajka', 'group_name' => 'Masa']],
            $przepis,
        )->refresh();

        $this->assertSame('declared', $przepis->allergen_status);
    }

    public function test_zmiana_kolejnosci_skladnikow_nie_uniewaznia(): void
    {
        $przepis = $this->zdeklarowany();

        $przepis = $this->zapisz(['szklanka mleka', '2 jajka', '200 g mąki pszennej'], $przepis)->refresh();

        $this->assertSame('declared', $przepis->allergen_status);
    }

    public function test_dopisanie_skladnika_uniewaznia(): void
    {
        $przepis = $this->zdeklarowany();

        $przepis = $this->zapisz(['200 g mąki pszennej', '2 jajka', 'szklanka mleka', 'łyżka masła'], $przepis)->refresh();

        $this->assertSame('needs_review', $przepis->allergen_status);
    }

    public function test_zapis_bez_zmiany_skladnikow_zostawia_declared_takze_przy_autozapisie(): void
    {
        $przepis = $this->zdeklarowany();

        $przepis = $this->zapisz(['200 g mąki pszennej', '2 jajka', 'szklanka mleka'], $przepis, publikuj: false)->refresh();

        $this->assertSame('declared', $przepis->allergen_status);
    }

    public function test_autozapis_nie_tworzy_fałszywego_declared_ze_stanu_do_przegladu(): void
    {
        $przepis = $this->zdeklarowany();
        $przepis = $this->zapisz(['zupełnie inny składnik'], $przepis);

        // Kolejne zapisy bez deklaracji (autozapis kreatora) nie wracają do declared.
        $przepis = $this->zapisz(['zupełnie inny składnik'], $przepis, publikuj: false)->refresh();

        $this->assertSame('needs_review', $przepis->allergen_status);
    }

    public function test_dawna_deklaracja_ponownie_przyslana_obok_zmienionego_skladnika_nie_jest_swieza(): void
    {
        // Formularz bez JS wysyła stan pól przy każdym zapisie. Zostawione
        // zaznaczone „Składniki sprawdzone” obok zmienionego składnika nie może
        // unieważnić mechanizmu `needs_review`.
        $przepis = $this->zdeklarowany();

        $przepis = $this->zapisz(
            ['200 g mąki pszennej', '2 jajka', 'szklanka mleka sojowego'],
            $przepis,
            new DeklaracjaAlergenow(['gluten', 'eggs', 'milk'], true),
        )->refresh();

        $this->assertSame('needs_review', $przepis->allergen_status);
    }

    public function test_swieza_deklaracja_w_tym_samym_zapisie_wygrywa_ze_zmiana_skladnikow(): void
    {
        $przepis = $this->zdeklarowany();

        $przepis = $this->zapisz(
            ['200 g mąki pszennej', '2 jajka', 'szklanka mleka sojowego'],
            $przepis,
            new DeklaracjaAlergenow(['gluten', 'eggs', 'soy'], true),
        )->refresh();

        $this->assertSame('declared', $przepis->allergen_status);
        $this->assertSame(['gluten', 'eggs', 'soy'], $przepis->allergens);
    }

    public function test_przepis_w_stanie_do_przegladu_moze_byc_potwierdzony_przy_zapisie(): void
    {
        $przepis = $this->zapisz(['a'], $this->zdeklarowany());
        $this->assertSame('needs_review', $przepis->refresh()->allergen_status);

        $przepis = $this->zapisz(['a'], $przepis, new DeklaracjaAlergenow(['gluten', 'eggs', 'milk'], true))->refresh();

        $this->assertSame('declared', $przepis->allergen_status);
    }

    public function test_import_zawsze_zaczyna_jako_niesprawdzony(): void
    {
        $szkic = app(ZapiszSzkicZImportu::class)->handle(
            $this->autor,
            PrzepisZImportu::ZRODLO_PDF,
            'tekst_pdf',
            new OdczytanyPrzepis('Sernik', null, null, null, null, ['ser', 'jajka', 'mleko'], ['Upiecz.']),
        )->refresh();

        $this->assertSame('unchecked', $szkic->allergen_status);
        $this->assertSame([], $szkic->allergens);
    }

    public function test_moja_wersja_zaczyna_jako_niesprawdzona_mimo_ze_oryginal_ma_deklaracje(): void
    {
        $oryginal = $this->zdeklarowany();
        $kopiujaca = $this->user('kopiujaca_alergenow');

        $wersja = app(ZrobWlasnaWersje::class)->handle($kopiujaca, $oryginal)->refresh();

        $this->assertSame('unchecked', $wersja->allergen_status);
        $this->assertSame([], $wersja->allergens);
        $this->assertSame('declared', $oryginal->refresh()->allergen_status, 'Kopia ruszyła oznaczenie oryginału.');
    }

    public function test_migawka_niesie_stan_i_liste_razem(): void
    {
        $przepis = $this->zdeklarowany();

        $wersja = RecipeVersion::query()->where('recipe_id', $przepis->getKey())->orderByDesc('version_number')->firstOrFail();

        $this->assertSame('declared', $wersja->snapshot['allergen_status']);
        $this->assertSame(['gluten', 'eggs', 'milk'], $wersja->snapshot['allergens']);
    }

    public function test_pierwsze_zapisz_zmiany_po_wdrozeniu_nie_mnozy_wersji_przepisu_niesprawdzonego(): void
    {
        $przepis = $this->zapisz(['mąka', 'mleko']);

        // Migawka „sprzed wdrożenia”: bez dwóch nowych kluczy.
        $stara = RecipeVersion::query()->where('recipe_id', $przepis->getKey())->firstOrFail();
        $snapshot = $stara->snapshot;
        unset($snapshot['allergen_status'], $snapshot['allergens']);
        // Wersji nie wolno zmieniać modelem (#1316) — stan sprzed wdrożenia udajemy zapytaniem.
        DB::table('recipe_versions')->where('id', $stara->getKey())->update(['snapshot' => json_encode($snapshot)]);

        $this->assertNull(app(SnapshotRecipeVersion::class)->poprawka($przepis->refresh(), $this->autor));
        $this->assertSame(1, $przepis->versions()->count());
    }

    public function test_zmiana_oznaczenia_to_zmiana_tresci_i_daje_nowa_wersje(): void
    {
        $przepis = $this->zapisz(['mąka', 'mleko']);
        $przepis->forceFill(['tresc_zmieniona_at' => now()->subYear()])->save();
        $odciskPrzed = TrescPrzepisu::odcisk((string) $przepis->getKey());

        $przepis = $this->zapisz(['mąka', 'mleko'], $przepis, new DeklaracjaAlergenow(['gluten', 'milk'], true))->refresh();

        $this->assertNotSame($odciskPrzed, TrescPrzepisu::odcisk((string) $przepis->getKey()));
        $this->assertTrue($przepis->tresc_zmieniona_at->isToday(), 'Zmiana oznaczenia alergenów nie przesunęła daty zmiany treści.');
        $this->assertSame(2, $przepis->versions()->count());
    }
}

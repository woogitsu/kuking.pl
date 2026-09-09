<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Wspomnienia\Wspomnienia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Dokumenty produktowe nie opisują jako „do zrobienia" tego, co już działa
 * (audyt zewnętrzny, G16).
 *
 * CO BYŁO NIE TAK
 * Cztery funkcje stały w kodzie i były dostępne dla człowieka, a dokumentacja
 * odkładała je na V1 albo wymieniała wśród „świadomie odsuniętych":
 *
 *   tryb gotowania      `/przepisy/{przepis}/gotuj`, `CookingModeController`
 *   minutnik kroku      w widoku trybu gotowania i w kreatorze
 *   wspomnienia         `app/Domain/Wspomnienia`, kafel na `/home`
 *   źródło przepisu     `recipes.source_url`, w kreatorze i w wydruku
 *
 * Najgorsza była lista „**Świadomie odsunięte:** (…) tryb gotowania" w
 * `SOUL.md`. Taka lista ma znaczyć „postanowiliśmy tego NIE robić" — i jest
 * czytana jako decyzja. Jedna nieprawdziwa pozycja psuje ją całą: następna
 * osoba albo zbuduje funkcję drugi raz, albo uzna, że skoro jedno jest
 * nieaktualne, to lista nie znaczy nic.
 *
 * Dokumenty rozjeżdżały się też MIĘDZY SOBĄ: `SOUL.md` odkładało pole źródła
 * na V1, a `RETENTION_LOOPS.md` w tej samej sprawie („Pętla 6") pisało „tak".
 *
 * CZEGO TEN TEST PILNUJE
 * Nie treści dokumentów — od tego jest czytanie. Pilnuje JEDNEJ własności,
 * która jest sprawdzalna maszynowo: jeżeli funkcja jest dostępna dla
 * człowieka, to żaden dokument nie ma prawa nazywać jej odsuniętą.
 *
 * Kierunek jest ważny. Test NIE mówi „ta funkcja musi istnieć" — produkt może
 * zdecydować, że tryb gotowania wypada, i wtedy znika i kod, i te asercje.
 * Mówi: „dopóki trasa odpowiada, dokument ma o tym wiedzieć".
 */
class DokumentyNieOdkladajaIstniejacychFunkcjiTest extends TestCase
{
    private function dokument(string $sciezka): string
    {
        return (string) file_get_contents(base_path($sciezka));
    }

    /**
     * Kontrola metody pomiaru, ustawiona PRZED resztą: cztery funkcje naprawdę
     * są dostępne.
     *
     * Bez tego wszystkie asercje niżej („dokument nie nazywa ich odsuniętymi")
     * przechodziłyby również wtedy, gdyby funkcje w ogóle nie istniały — i test
     * broniłby nieprawdy w drugą stronę.
     */
    public function test_kontrola_cztery_funkcje_sa_naprawde_dostepne(): void
    {
        $trasy = collect(Route::getRoutes())->map(fn ($t): string => $t->uri())->all();

        $this->assertContains(
            'przepisy/{recipe}/gotuj',
            $trasy,
            'Zniknęła trasa trybu gotowania. Jeśli funkcja została WYCOFANA, to '
            .'poprawka jest odwrotna niż zwykle: usuń ten test i wpisz tryb '
            .'gotowania z powrotem na listę odsuniętych w `docs/product/SOUL.md`.',
        );

        $this->assertTrue(
            class_exists(Wspomnienia::class),
            'Zniknęły wspomnienia „rok temu". Patrz uwaga wyżej — dokument trzeba '
            .'wtedy cofnąć, nie dopisywać.',
        );

        $this->assertTrue(
            $this->kolumnaIstnieje('recipes', 'source_url'),
            'Zniknęła kolumna `recipes.source_url` (pole „skąd jest ten przepis").',
        );

        $this->assertStringContainsString(
            'minutnik',
            mb_strtolower($this->dokument('resources/views/pages/recipes/cooking.blade.php')),
            'Minutnik kroku zniknął z widoku trybu gotowania.',
        );
    }

    /**
     * Lista „świadomie odsunięte" ma wymieniać wyłącznie rzeczy, których
     * NIE ZROBIONO.
     */
    public function test_lista_swiadomie_odsunietych_nie_wymienia_trybu_gotowania(): void
    {
        $soul = $this->dokument('docs/product/SOUL.md');

        $this->assertSame(
            1,
            preg_match('/\*\*Świadomie odsunięte:\*\*(.+)/u', $soul, $dopasowanie),
            'Nie znaleziono listy „Świadomie odsunięte" w `docs/product/SOUL.md`. '
            .'Jeśli sekcję przemianowano, popraw ten test razem z nią.',
        );

        $this->assertStringNotContainsString(
            'tryb gotowania',
            mb_strtolower($dopasowanie[1]),
            'Lista „świadomie odsunięte" znowu wymienia tryb gotowania, a on stoi '
            .'pod `/przepisy/{przepis}/gotuj`. Ta lista jest czytana jako DECYZJA '
            .'produktowa — jedna nieprawdziwa pozycja psuje wiarygodność całej.',
        );
    }

    /**
     * Żaden z trzech dokumentów nie odkłada tych funkcji na V1.
     *
     * Szukamy wiersza tabeli, w którym obok opisu funkcji stoi komórka `V1` —
     * nie samego słowa „V1", bo te dokumenty mówią o V1 także w zdaniach
     * o planach i to jest w porządku.
     */
    public function test_zadna_z_czterech_funkcji_nie_jest_odlozona_na_v1(): void
    {
        $przypadki = [
            ['docs/product/SOUL.md', 'Nostalgia + praktyczna podpowiedź', 'wspomnienia „rok temu”'],
            ['docs/product/SOUL.md', 'można pokazać jako zaletę autora', 'pole źródła przepisu'],
            ['docs/product/SOUL.md', 'najbardziej fizyczny problem w tym produkcie', 'tryb gotowania'],
            ['docs/product/RETENTION_LOOPS.md', 'Pętla 7 (wspomnienia', 'pętla wspomnień'],
        ];

        foreach ($przypadki as [$plik, $kotwica, $nazwa]) {
            $tresc = $this->dokument($plik);

            $wiersz = collect(explode("\n", $tresc))
                ->first(fn (string $w): bool => str_contains($w, $kotwica));

            $this->assertNotNull($wiersz, "Nie znaleziono w `{$plik}` wiersza o „{$nazwa}” (kotwica: „{$kotwica}”).");

            $this->assertDoesNotMatchRegularExpression(
                '/\|\s*V1\s*\|/u',
                $wiersz,
                "`{$plik}` znowu odkłada „{$nazwa}” na V1, a ta funkcja jest dostępna "
                .'dla człowieka. Bramka V1 z `docs/ROADMAP.md` ma kierować pracą — '
                .'a nie da się jej użyć, jeśli po jej drugiej stronie stoją rzeczy '
                .'już zrobione.',
            );
        }
    }

    /**
     * `FEATURES.md` nie trzyma na liście V1 dwóch pozycji, które są w MVP.
     */
    public function test_features_nie_ma_trybu_gotowania_ani_timerow_na_liscie_v1(): void
    {
        $features = $this->dokument('docs/FEATURES.md');

        $this->assertSame(
            1,
            preg_match('/^## V1$(.*?)^## /msu', $features, $dopasowanie),
            'Nie znaleziono sekcji „## V1" w `docs/FEATURES.md`.',
        );

        $v1 = mb_strtolower($dopasowanie[1]);

        foreach (['cooking mode', 'timery'] as $pozycja) {
            $this->assertStringNotContainsString(
                $pozycja,
                $v1,
                "Sekcja V1 w `docs/FEATURES.md` znowu wymienia „{$pozycja}”, a to stoi "
                .'w kodzie od dawna: `/przepisy/{przepis}/gotuj` plus minutnik kroku.',
            );
        }
    }

    private function kolumnaIstnieje(string $tabela, string $kolumna): bool
    {
        return DB::select(
            'SELECT 1 FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            [$tabela, $kolumna],
        ) !== [];
    }
}

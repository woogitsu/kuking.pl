<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWersjePrzepisow;
use App\Domain\Recipes\Actions\SnapshotRecipeVersion;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RETENCJA `recipe_versions` — 24 miesiące, decyzja właściciela z 2026-09-20.
 *
 * Ten plik mierzy ZACHOWANIE, nie kształt kodu: co znika, co zostaje i ile
 * dokładnie. Cztery rzeczy, z których każda już kiedyś w tym repozytorium
 * była źródłem cichej straty danych:
 *
 *  1. Wersja starsza niż próg znika, a młodsza NIE — czyli automat pilnuje
 *     PROGU, a nie „dowolnej dużej różnicy".
 *  2. PIERWSZA wersja przepisu zostaje zawsze, także gdy jest jedyną i ma
 *     dwadzieścia lat. Bez tego przepis edytowany raz na dwa lata zostawałby
 *     bez historii w ogóle.
 *  3. Przepis bez ani jednej wersji nie wywraca komendy.
 *  4. PIERWSZE URUCHOMIENIE NA STARYCH DANYCH nie kasuje więcej, niż
 *     zadeklarował dry-run — i drugie uruchomienie nie dobiera już nic.
 *     To jest moment największego ryzyka: retencja włączona po raz pierwszy
 *     na tabeli, która rosła bez żadnego sprzątania, kasuje jednym
 *     zapytaniem dorobek lat.
 *
 * Migawki powstają przez PRAWDZIWĄ akcję domenową `SnapshotRecipeVersion`,
 * nie przez `RecipeVersion::create()` z ręki — inaczej test mierzyłby własną
 * fikcję zamiast danych, które w tej tabeli naprawdę leżą.
 */
final class RetencjaWersjiPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    private const PROG_MIESIECY = 24;

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.przepisy.version_retention_months' => self::PROG_MIESIECY]);
    }

    /**
     * Migawka przepisu z podstawioną datą powstania.
     *
     * `created_at` przestawiamy zapytaniem, a nie atrybutem modelu: kolumna
     * ma `useCurrent()` i jest jedyną datą, od której liczy się retencja.
     */
    private function wersja(Recipe $przepis, User $edytor, \DateTimeInterface $kiedy, ?string $notatka = null): RecipeVersion
    {
        $wersja = app(SnapshotRecipeVersion::class)->handle($przepis, $edytor, $notatka);

        DB::table('recipe_versions')->where('id', $wersja->getKey())->update(['created_at' => $kiedy]);

        return $wersja->refresh();
    }

    private function przepis(User $autor): Recipe
    {
        return Recipe::factory()->for($autor, 'author')->create();
    }

    public function test_wersja_starsza_niz_prog_znika_pierwsza_zostaje_a_mlodsza_jest_nietknieta(): void
    {
        $autor = User::factory()->create();
        $przepis = $this->przepis($autor);

        // Wyraźnie po obu stronach granicy, nie „bardzo stara" i „świeża" —
        // inaczej test przeszedłby także przy progu policzonym o rząd
        // wielkości obok.
        $pierwsza = $this->wersja($przepis, $autor, now()->subYears(5), 'Pierwsza publikacja');
        $stara = $this->wersja($przepis, $autor, now()->subMonths(self::PROG_MIESIECY)->subDay(), 'Poprawka sprzed progu');
        $mloda = $this->wersja($przepis, $autor, now()->subMonths(self::PROG_MIESIECY)->addDay(), 'Poprawka zza progu');

        $wynik = (new PrzedawnioneWersjePrzepisow)->posprzataj(self::PROG_MIESIECY);

        $this->assertSame(1, $wynik['skasowano']);
        $this->assertSame(1, $wynik['niekasowalne'], 'Pierwsza wersja jest starsza niż próg, więc musi być POLICZONA jako pominięta.');

        $this->assertDatabaseMissing('recipe_versions', ['id' => $stara->getKey()]);
        // Obie asercje kontrolne. Bez nich test przeszedłby też wtedy, gdyby
        // automat skasował WSZYSTKO — a to jest dokładnie ta awaria, której
        // przy retencji nie da się cofnąć.
        $this->assertDatabaseHas('recipe_versions', ['id' => $pierwsza->getKey()]);
        $this->assertDatabaseHas('recipe_versions', ['id' => $mloda->getKey()]);
    }

    /**
     * PRZEPIS EDYTOWANY RAZ NA DWA LATA — powód, dla którego wyjątek dla
     * pierwszej wersji w ogóle istnieje. Obie migawki są starsze niż próg;
     * bez wyjątku człowiek zostałby bez historii w ogóle.
     */
    public function test_pierwsza_wersja_zostaje_nawet_gdy_cala_historia_jest_starsza_niz_prog(): void
    {
        $autor = User::factory()->create();
        $przepis = $this->przepis($autor);

        $pierwsza = $this->wersja($przepis, $autor, now()->subYears(20), 'Pierwsza publikacja');
        $druga = $this->wersja($przepis, $autor, now()->subYears(18), 'Aktualizacja przepisu');

        $wynik = (new PrzedawnioneWersjePrzepisow)->posprzataj(self::PROG_MIESIECY);

        $this->assertSame(1, $wynik['skasowano']);
        $this->assertSame(1, $wynik['niekasowalne']);
        $this->assertDatabaseHas('recipe_versions', ['id' => $pierwsza->getKey()]);
        $this->assertDatabaseMissing('recipe_versions', ['id' => $druga->getKey()]);

        // I to jest sedno: przepis NIE zostaje bez historii.
        $this->assertSame(1, RecipeVersion::where('recipe_id', $przepis->getKey())->count());
    }

    /**
     * „Pierwsza" znaczy NAJNIŻSZY NUMER W OBRĘBIE TEGO PRZEPISU, a nie
     * „najstarsza, która akurat została" i nie „pierwsza w całym serwisie".
     * Gdyby chroniony wiersz był wybierany po tym, co zostało, każdy kolejny
     * przebieg chroniłby inny — i po kilku przebiegach z historii zostałaby
     * ostatnia wersja, bez jednej zmiany w kodzie.
     */
    public function test_ochrona_dotyczy_kazdego_przepisu_z_osobna(): void
    {
        $autor = User::factory()->create();
        $pierwszyPrzepis = $this->przepis($autor);
        $drugiPrzepis = $this->przepis($autor);

        $p1 = $this->wersja($pierwszyPrzepis, $autor, now()->subYears(10));
        $p2 = $this->wersja($pierwszyPrzepis, $autor, now()->subYears(9));
        $d1 = $this->wersja($drugiPrzepis, $autor, now()->subYears(8));
        $d2 = $this->wersja($drugiPrzepis, $autor, now()->subYears(7));

        $wynik = (new PrzedawnioneWersjePrzepisow)->posprzataj(self::PROG_MIESIECY);

        $this->assertSame(2, $wynik['skasowano']);
        $this->assertSame(2, $wynik['niekasowalne']);
        $this->assertDatabaseHas('recipe_versions', ['id' => $p1->getKey()]);
        $this->assertDatabaseHas('recipe_versions', ['id' => $d1->getKey()]);
        $this->assertDatabaseMissing('recipe_versions', ['id' => $p2->getKey()]);
        $this->assertDatabaseMissing('recipe_versions', ['id' => $d2->getKey()]);
    }

    public function test_przepis_bez_ani_jednej_wersji_nie_wywala_komendy(): void
    {
        $autor = User::factory()->create();
        $przepis = $this->przepis($autor);

        $this->artisan('kuking:sprzataj-wersje-przepisow')->assertSuccessful();

        // Sam przepis ma zostać nietknięty — retencja dotyczy migawek,
        // nie treści, której migawki opisują.
        $this->assertDatabaseHas('recipes', ['id' => $przepis->getKey()]);
        $this->assertSame(0, RecipeVersion::count());
    }

    /**
     * PIERWSZE URUCHOMIENIE NA STARYCH DANYCH — moment największego ryzyka.
     *
     * Dry-run i przebieg prawdziwy liczą DOKŁADNIE ten sam predykat, więc
     * liczba z `--na-sucho` jest obietnicą, a nie szacunkiem. Gdyby się
     * rozjeżdżały, człowiek podejmowałby decyzję o nieodwracalnym kasowaniu
     * na podstawie liczby, która nic nie znaczy.
     */
    public function test_na_sucho_liczy_dokladnie_tyle_ile_skasuje_przebieg_prawdziwy_i_niczego_nie_rusza(): void
    {
        $autor = User::factory()->create();

        // Sześć przepisów po cztery migawki: trzy stare (w tym pierwsza)
        // i jedna świeża. Razem 24 wiersze, z czego skasowalne są dokładnie
        // dwa na przepis: 12.
        for ($i = 0; $i < 6; $i++) {
            $przepis = $this->przepis($autor);
            $this->wersja($przepis, $autor, now()->subYears(6), 'Pierwsza publikacja');
            $this->wersja($przepis, $autor, now()->subYears(5));
            $this->wersja($przepis, $autor, now()->subYears(4));
            $this->wersja($przepis, $autor, now()->subMonth());
        }

        $this->assertSame(24, RecipeVersion::count());

        $naSucho = (new PrzedawnioneWersjePrzepisow)->posprzataj(self::PROG_MIESIECY, naSucho: true);

        $this->assertSame(12, $naSucho['skasowano']);
        $this->assertSame(6, $naSucho['niekasowalne']);
        // Dry-run NIE KASUJE. Asercja na liczbie wierszy, nie na zwróconej
        // liczbie — to drugie mówiłoby tylko, co funkcja o sobie twierdzi.
        $this->assertSame(24, RecipeVersion::count());

        $naprawde = (new PrzedawnioneWersjePrzepisow)->posprzataj(self::PROG_MIESIECY);

        $this->assertSame($naSucho['skasowano'], $naprawde['skasowano'], 'Przebieg prawdziwy skasował inną liczbę, niż zapowiedział dry-run.');
        $this->assertSame(12, RecipeVersion::count());
        $this->assertSame(6, RecipeVersion::where('version_number', 1)->count());
    }

    /**
     * Drugie uruchomienie z rzędu nie dobiera już NICZEGO. Predykat jest
     * idempotentny: chroniona jest wersja o najniższym numerze, a nie
     * „najstarsza, która została", więc automat nie zjada historii przebieg
     * po przebiegu.
     */
    public function test_drugie_uruchomienie_z_rzedu_nie_kasuje_juz_nic(): void
    {
        $autor = User::factory()->create();
        $przepis = $this->przepis($autor);

        $this->wersja($przepis, $autor, now()->subYears(6), 'Pierwsza publikacja');
        $this->wersja($przepis, $autor, now()->subYears(5));
        $this->wersja($przepis, $autor, now()->subYears(4));

        $pierwszy = (new PrzedawnioneWersjePrzepisow)->posprzataj(self::PROG_MIESIECY);
        $drugi = (new PrzedawnioneWersjePrzepisow)->posprzataj(self::PROG_MIESIECY);

        $this->assertSame(2, $pierwszy['skasowano']);
        $this->assertSame(0, $drugi['skasowano'], 'Drugi przebieg dobrał wiersze, których pierwszy nie zadeklarował — predykat nie jest idempotentny.');
        $this->assertSame(1, RecipeVersion::where('recipe_id', $przepis->getKey())->count());
    }

    public function test_komenda_respektuje_na_sucho_i_wlasny_prog_z_opcji(): void
    {
        $autor = User::factory()->create();
        $przepis = $this->przepis($autor);

        $this->wersja($przepis, $autor, now()->subYears(4), 'Pierwsza publikacja');
        $this->wersja($przepis, $autor, now()->subMonths(6));

        $this->artisan('kuking:sprzataj-wersje-przepisow', ['--na-sucho' => true])->assertSuccessful();
        $this->assertSame(2, RecipeVersion::count(), 'Dry-run komendy skasował wiersze.');

        // Przy domyślnych 24 miesiącach druga wersja (6 miesięcy) jest młoda
        // i ma zostać; pierwszej nie wolno ruszać nigdy — czyli nic nie ginie.
        $this->artisan('kuking:sprzataj-wersje-przepisow')->assertSuccessful();
        $this->assertSame(2, RecipeVersion::count());

        // Ten sam zestaw danych przy progu trzech miesięcy: druga wersja
        // przekracza próg i znika, pierwsza dalej zostaje.
        $this->artisan('kuking:sprzataj-wersje-przepisow', ['--miesiace' => 3])->assertSuccessful();
        $this->assertSame(1, RecipeVersion::count());
        $this->assertSame(1, RecipeVersion::where('version_number', 1)->count());
    }

    /**
     * STAN FAKTYCZNY, NIE ZAŁOŻENIE: migawka niesie treść napisaną przez
     * człowieka — tytuł, opis, składniki i kroki — a nie sam identyfikator
     * ze znacznikiem czasu. Gdyby niosła mniej, cała ta retencja dotyczyłaby
     * czegoś innego, niż mówi jej uzasadnienie.
     */
    public function test_migawka_niesie_tresc_czlowieka_wiec_retencja_dotyczy_tresci(): void
    {
        $autor = User::factory()->create();
        $przepis = $this->przepis($autor);

        $wersja = $this->wersja($przepis, $autor, now()->subYears(3), 'Pierwsza publikacja');

        $this->assertSame($przepis->title, $wersja->snapshot['title']);
        $this->assertArrayHasKey('summary', $wersja->snapshot);
        $this->assertArrayHasKey('ingredients', $wersja->snapshot);
        $this->assertArrayHasKey('steps', $wersja->snapshot);
        // Zdjęć migawka NIE zapisuje — i dlatego retencja nie dotyka storage
        // ani jednym bajtem (uzasadnienie masowego `DELETE` w klasie domenowej).
        $this->assertArrayNotHasKey('hero_media_id', $wersja->snapshot);
    }
}

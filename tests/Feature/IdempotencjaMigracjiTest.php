<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Recipe;
use App\Models\Report;
use App\Support\NumerSprawy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Migracje idempotencji: co robią, czemu odmawiają i co zostaje po cofnięciu
 * (ADR `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md` §8.2 i §8.4).
 *
 * DWIE STRONY, OBIE SPRAWDZONE. Sama odmowa nie wystarczy: migracja, która
 * nigdy się nie zakłada, jest równie zła, tylko w drugą stronę. A cofnięcie,
 * które kasuje treść, byłoby utratą danych bez związku z tym, co się właśnie
 * psuło (audyt F-02, `CofniecieMigracjiNieKasujeZeszytowTest`).
 */
class IdempotencjaMigracjiTest extends TestCase
{
    use RefreshDatabase;

    private const INDEKS_PARY = 'reports_one_open_per_pair';

    private function migracjaZgloszen(): object
    {
        return require database_path('migrations/2026_09_07_900000_one_open_report_per_pair.php');
    }

    private function migracjaWpisow(): object
    {
        return require database_path('migrations/2026_09_07_900200_add_klucz_wyslania_to_posts.php');
    }

    private function migracjaPrzepisow(): object
    {
        return require database_path('migrations/2026_09_12_600000_add_klucz_wyslania_to_recipes.php');
    }

    private function dwaOtwarteZgloszeniaTejSamejPary(): string
    {
        $zglaszajacy = $this->user('zglaszajacymigracja');
        $wpis = Post::factory()->for($this->user('autormigracja'), 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);

        // Indeks musi zniknąć, żeby dało się w ogóle odtworzyć stan sprzed
        // migracji — czyli bazę, w której duplikaty już leżą.
        DB::statement('DROP INDEX IF EXISTS '.self::INDEKS_PARY);

        foreach ([1, 2] as $ktore) {
            DB::table('reports')->insert([
                'id' => (string) Str::uuid7(),
                // Surowy `INSERT` omija model, a `reports.numer_sprawy` jest
                // `NOT NULL` (D-029) — numer trzeba więc podać tutaj, tak
                // samo jak identyfikator. Osobny dla każdego wiersza, bo
                // inaczej odbiłby się indeks numeru, a nie ten, o który
                // w tym teście chodzi.
                'numer_sprawy' => NumerSprawy::wygeneruj(),
                'reporter_id' => $zglaszajacy->getKey(),
                'target_type' => 'post',
                'target_id' => $wpis->getKey(),
                'reason' => 'spam',
                'status' => Report::STATUS_OPEN,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $zglaszajacy->getKey();
    }

    public function test_migracja_odmawia_gdy_duplikaty_juz_sa_w_bazie(): void
    {
        $zglaszajacyId = $this->dwaOtwarteZgloszeniaTejSamejPary();

        // ODMOWĘ ODKŁADAMY DO ZMIENNEJ, A OCENIAMY POZA BLOKIEM — i to nie
        // jest stylistyka. `$this->fail()` rzuca `AssertionFailedError`, a ta
        // dziedziczy przez `PHPUnit\Framework\Exception` po `RuntimeException`,
        // więc postawiona wewnątrz `try` wpadłaby do własnego `catch`. Tutaj
        // wyłapują ją dziś asercje na treść komunikatu, ale przy `catch` bez
        // asercji ten sam kształt daje test-atrapę: zielony także wtedy, gdyby
        // strażnika nie było wcale. Odmowa dotyczy `up()`, nie `down()` — to ta
        // sama pułapka, bo decyduje kształt bloku, a nie kierunek migracji.
        $odmowa = null;

        try {
            $this->migracjaZgloszen()->up();
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Migracja przeszła, mimo że w bazie leżą dwie otwarte sprawy tej samej pary.');

        // Komunikat ma powiedzieć, KTÓRA para jest sporna i CO ZROBIĆ.
        // „Coś jest nie tak" nie pomaga o drugiej w nocy.
        $this->assertStringContainsString($zglaszajacyId, $odmowa->getMessage());
        $this->assertStringContainsString('zamknij pozostałe', $odmowa->getMessage());
        $this->assertStringContainsString('DSA art. 16', $odmowa->getMessage());

        // NIC nie zostało skasowane: to są sprawy moderacyjne z terminem
        // odpowiedzi, a nie śmieci do sprzątnięcia przez migrację.
        $this->assertSame(2, Report::query()->count(), 'Migracja usunęła sprawę moderacyjną.');
    }

    public function test_migracja_zaklada_indeks_na_bazie_bez_duplikatow(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEKS_PARY);

        $this->assertSame(0, $this->ileIndeksow(self::INDEKS_PARY));

        $this->migracjaZgloszen()->up();

        $this->assertSame(1, $this->ileIndeksow(self::INDEKS_PARY));
    }

    public function test_cofniecie_migracji_wpisow_nie_kasuje_ani_jednego_wpisu(): void
    {
        // Plan wycofania z §8.4: `DROP INDEX`, potem `DROP COLUMN`.
        // `down()` nie ma tu czego odmawiać i to jest teza tego testu:
        // w kolumnie nie ma ani jednego słowa napisanego przez człowieka,
        // więc cofnięcie zabiera ochronę, a nie treść.
        $osoba = $this->user('cofajaca');

        $wpis = Post::factory()->for($osoba, 'author')->create([
            'body' => 'Rosół, którego nie wolno stracić przy cofaniu schematu.',
            'klucz_wyslania' => (string) Str::uuid7(),
        ]);

        $this->migracjaWpisow()->down();

        $this->assertSame(0, $this->ileIndeksow('posts_one_per_klucz_wyslania'));
        $this->assertSame(
            'Rosół, którego nie wolno stracić przy cofaniu schematu.',
            DB::table('posts')->where('id', $wpis->getKey())->value('body'),
        );
        $this->assertSame(
            0,
            DB::table('information_schema.columns')
                ->where('table_name', 'posts')
                ->where('column_name', 'klucz_wyslania')
                ->count(),
        );
    }

    public function test_cofniecie_migracji_przepisow_nie_kasuje_ani_jednego_przepisu(): void
    {
        // Plan wycofania z `docs/DATABASE.md`: `DROP INDEX`, potem
        // `DROP COLUMN`. `down()` nie ma tu czego odmawiać (D-088 dotyczy
        // wartości SEMANTYCZNYCH) i to jest teza tego testu: w kolumnie nie
        // ma ani jednego słowa napisanego przez człowieka ani jednej jego
        // decyzji, więc cofnięcie zabiera ochronę, a nie treść.
        $osoba = $this->user('cofajacaprzepis');

        $przepis = Recipe::factory()->for($osoba, 'author')->create([
            'title' => 'Rosół, którego nie wolno stracić przy cofaniu schematu',
            'klucz_wyslania' => (string) Str::uuid7(),
        ]);

        $this->migracjaPrzepisow()->down();

        $this->assertSame(0, $this->ileIndeksow('recipes_one_per_klucz_wyslania'));
        $this->assertSame(
            'Rosół, którego nie wolno stracić przy cofaniu schematu',
            DB::table('recipes')->where('id', $przepis->getKey())->value('title'),
        );
        $this->assertSame(
            0,
            DB::table('information_schema.columns')
                ->where('table_name', 'recipes')
                ->where('column_name', 'klucz_wyslania')
                ->count(),
        );
    }

    public function test_migracja_przepisow_zaklada_sie_od_nowa_po_cofnieciu(): void
    {
        // KONTROLA DODATNIA do testu wyżej: migracja, której nie da się
        // założyć ponownie, jest równie zła co taka, której nie da się
        // cofnąć — tylko w drugą stronę. Po `down()` i `up()` kolumna
        // i indeks wracają, a przepis dalej jest.
        $osoba = $this->user('odtwarzajacaprzepis');
        $przepis = Recipe::factory()->for($osoba, 'author')->create();

        $migracja = $this->migracjaPrzepisow();
        $migracja->down();
        $migracja->up();

        $this->assertSame(1, $this->ileIndeksow('recipes_one_per_klucz_wyslania'));
        $this->assertSame(
            1,
            DB::table('information_schema.columns')
                ->where('table_name', 'recipes')
                ->where('column_name', 'klucz_wyslania')
                ->count(),
        );
        $this->assertSame(1, DB::table('recipes')->where('id', $przepis->getKey())->count());
    }

    private function ileIndeksow(string $nazwa): int
    {
        return DB::table('pg_indexes')->where('indexname', $nazwa)->count();
    }
}

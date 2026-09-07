<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Report;
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

        try {
            $this->migracjaZgloszen()->up();

            $this->fail('Migracja przeszła, mimo że w bazie leżą dwie otwarte sprawy tej samej pary.');
        } catch (RuntimeException $e) {
            // Komunikat ma powiedzieć, KTÓRA para jest sporna i CO ZROBIĆ.
            // „Coś jest nie tak" nie pomaga o drugiej w nocy.
            $this->assertStringContainsString($zglaszajacyId, $e->getMessage());
            $this->assertStringContainsString('zamknij pozostałe', $e->getMessage());
            $this->assertStringContainsString('DSA art. 16', $e->getMessage());
        }

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

    private function ileIndeksow(string $nazwa): int
    {
        return DB::table('pg_indexes')->where('indexname', $nazwa)->count();
    }
}

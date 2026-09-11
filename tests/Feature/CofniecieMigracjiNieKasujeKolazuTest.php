<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Cofnięcie migracji `hero_picks` nie kasuje po cichu wyboru gospodarza
 * (D-088).
 *
 * DLACZEGO TA TABELA W OGÓLE POTRZEBUJE STRAŻNIKA
 * `DROP TABLE` to nie jest „kolumna wróci pusta" — to jest utrata całego
 * wyboru. A po `down()` prawie zawsze idzie kolejny `migrate`
 * (`migrate:refresh` w CI, awaryjny rollback wdrożenia), więc tabela wraca
 * i NIE MA BŁĘDU DO ZAUWAŻENIA: kolaż na stronie powitalnej przechodzi
 * w tryb automatyczny i pokazuje gościom cztery zdjęcia, których nikt nie
 * wybierał. Dokładnie ten kształt choroby, który D-088 opisuje.
 *
 * Przy tej akurat tabeli różnica „człowiek to obejrzał" kontra „maszyna
 * dobrała sama" nie jest kosmetyczna, dopóki `resources/legal/` nie
 * rozstrzyga użycia promocyjnego cudzych zdjęć.
 *
 * ODMOWA MUSI BYĆ WĄSKA — stąd dwie kontrole dodatnie niżej
 * (`PULAPKI_TESTOW.md` #4). Zablokowanie rollbacku na zawsze jest błędem tej
 * samej wagi w drugą stronę: na pustej tabeli i na świeżej bazie cofnięcie
 * ma przechodzić bez pytania.
 *
 * KONTROLA UJEMNA (wykonana ręcznie, opis w raporcie): usunięcie `throw`
 * z `down()` oblewa `test_cofniecie_odmawia_gdy_gospodarz_cos_wybral`
 * komunikatem „Cofnięcie migracji przeszło, mimo że…", a nie cichym
 * zielonym wynikiem. Po sabotażu sprawdzone `grep`-em, że zmiana naprawdę
 * jest w pliku migracji.
 */
class CofniecieMigracjiNieKasujeKolazuTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_09_11_800000_utworz_hero_picks.php';

    #[Test]
    public function test_cofniecie_odmawia_gdy_gospodarz_cos_wybral(): void
    {
        // PRAWDZIWA DROGA, nie ręczny INSERT: ten sam formularz, którym
        // gospodarz zapisuje kolaż. Gdyby wybór dało się zapisać tylko
        // z testu, ten test nie mówiłby nic o produkcji.
        [$zdjecie] = $this->publiczneZdjecie();

        $this->actingAs($this->moderator())
            ->put(route('admin.hero-kolaz'), ['zdjecia' => [$zdjecie->getKey()]])
            ->assertRedirect();

        $this->assertSame(1, $this->ileWierszy(), 'Wybór się nie zapisał — test mierzyłby nie to.');

        $wyjatek = $this->cofnijOczekujacOdmowy();

        $this->assertStringContainsString('1 zdjęć', $wyjatek->getMessage());
        $this->assertStringContainsString('CO ZROBIĆ ZAMIAST TEGO', $wyjatek->getMessage());

        // NAJWAŻNIEJSZA ASERCJA: odmowa, która zdążyła już skasować tabelę,
        // byłaby tylko ładniejszym komunikatem o tej samej utracie.
        $this->assertTrue($this->tabelaIstnieje(), 'Tabela zniknęła mimo odmowy rollbacku.');
        $this->assertSame(1, $this->ileWierszy(), 'Wybór gospodarza zniknął mimo odmowy rollbacku.');
    }

    #[Test]
    public function test_cofniecie_przechodzi_gdy_nikt_niczego_nie_wybral(): void
    {
        // Kontrola dodatnia: w serwisie SĄ publiczne zdjęcia (czyli tabela
        // mogłaby mieć wiersze), ale gospodarz nie wskazał żadnego.
        // Kolaż chodzi wtedy w trybie automatycznym i nie ma czego stracić.
        $this->publiczneZdjecie();

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->assertFalse($this->tabelaIstnieje(), 'Rollback nie przeszedł, choć nikt niczego nie wybrał.');

        // Odtwarzamy schemat, żeby nie zostawić bazy w połowie drogi dla
        // kolejnych testów w tym samym procesie.
        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertTrue($this->tabelaIstnieje());
    }

    #[Test]
    public function test_cofniecie_przechodzi_na_swiezej_bazie(): void
    {
        // Kontrola dodatnia numer dwa: świeży staging po `migrate:fresh` nie
        // może zostać zablokowany pustą tabelą.
        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->assertFalse($this->tabelaIstnieje());

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        $this->assertTrue($this->tabelaIstnieje());
    }

    #[Test]
    public function test_jawna_zgoda_ze_srodowiska_odblokowuje_cofniecie(): void
    {
        // Strażnik ma być do przejścia, gdy człowiek powie wprost, że wie,
        // co kasuje. Inaczej byłby blokadą na zawsze, a nie ostrzeżeniem.
        [$zdjecie] = $this->publiczneZdjecie();

        $this->actingAs($this->moderator())
            ->put(route('admin.hero-kolaz'), ['zdjecia' => [$zdjecie->getKey()]]);

        $this->assertSame(1, $this->ileWierszy());

        putenv('KUKING_ROLLBACK_KASUJ_KOLAZ_POWITALNY=true');

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
            $this->assertFalse($this->tabelaIstnieje(), 'Jawna zgoda nie odblokowała cofnięcia.');
        } finally {
            putenv('KUKING_ROLLBACK_KASUJ_KOLAZ_POWITALNY');
        }

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
    }

    /** @return array{0: Media, 1: Post} */
    private function publiczneZdjecie(): array
    {
        $autor = $this->user('autor_kolazu');

        $wpis = Post::create([
            'author_id' => $autor->getKey(),
            'body' => 'Rosół jak zawsze.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now()->subHour(),
        ]);

        $zdjecie = Media::factory()->for($autor, 'owner')->create();
        $wpis->media()->attach($zdjecie->getKey(), ['position' => 0]);

        return [$zdjecie, $wpis];
    }

    private function cofnijOczekujacOdmowy(): RuntimeException
    {
        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        } catch (RuntimeException $e) {
            return $e;
        }

        $this->fail('Cofnięcie migracji przeszło, mimo że w bazie stoi wybór człowieka, którego nic nie odtworzy.');
    }

    private function tabelaIstnieje(): bool
    {
        return DB::select(
            "SELECT 1 FROM information_schema.tables WHERE table_name = 'hero_picks' AND table_schema = current_schema()",
        ) !== [];
    }

    private function ileWierszy(): int
    {
        return $this->tabelaIstnieje()
            ? (int) DB::table('hero_picks')->count()
            : 0;
    }
}

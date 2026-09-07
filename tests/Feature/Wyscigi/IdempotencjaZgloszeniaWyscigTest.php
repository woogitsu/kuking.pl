<?php

declare(strict_types=1);

namespace Tests\Feature\Wyscigi;

use App\Domain\Moderation\Actions\ReportContent;
use App\Models\Post;
use App\Models\Report;
use App\Support\NumerSprawy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WYŚCIG POTWIERDZONY I NAPRAWIONY (audyt wyścigów z 7 września 2026,
 * ADR `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md` §1.3).
 *
 * `ReportContent::handle()` szukał otwartego zgłoszenia tej pary i tworzył
 * nowe, gdy go nie było. Między tym `SELECT`-em a `INSERT`-em jest okno.
 * Zmierzone na DWÓCH niezależnych połączeniach PDO (izolacja
 * `read committed`): oba widziały „zgłoszeń: 0", oba wstawiły wiersz, żadne
 * nie czekało — dwie sprawy moderacyjne z osobnymi terminami odpowiedzi
 * z DSA art. 16 dla jednej sprawy.
 *
 * `lockForUpdate()` tego NIE naprawia i nie został dodany: `SELECT ... FOR
 * UPDATE`, który nie zwrócił wiersza, nie blokuje niczego (POMIAR 3d).
 * Naprawą jest częściowy indeks UNIQUE `reports_one_open_per_pair` plus
 * odczyt wiersza, który wygrał wyścig.
 *
 * METODA: jak w `IngredientRaceTest` i `ResolveTagsForPostRaceTest` —
 * konkurencyjny wiersz wchodzi DOKŁADNIE między `SELECT`-em a `INSERT`-em,
 * przez `DB::listen()`. Prawdziwe dwa połączenia nie przejdą tu przez
 * `RefreshDatabase` (dane testu żyją w nieznanej drugiemu połączeniu
 * transakcji), a dowód „baza sama odrzuca wiersz" stoi osobno,
 * w `IdempotencjaZgloszeniaTest::test_baza_odbija_drugie_otwarte_zgloszenie_tej_samej_pary`.
 */
class IdempotencjaZgloszeniaWyscigTest extends TestCase
{
    use RefreshDatabase;

    public function test_dwa_niemal_jednoczesne_zgloszenia_tej_samej_pary_daja_jedna_sprawe(): void
    {
        $zglaszajacy = $this->user('zglaszajacywyscig');
        $wpis = Post::factory()->for($this->user('autorwyscig'), 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);

        $konkurencyjneId = (string) Str::uuid7();
        $wstawione = false;

        DB::listen(function ($query) use (&$wstawione, $zglaszajacy, $wpis, $konkurencyjneId): void {
            if ($wstawione) {
                return;
            }

            $sql = mb_strtolower($query->sql);

            if (! str_starts_with(trim($sql), 'select') || ! str_contains($sql, '"reports"') || ! str_contains($sql, 'target_type')) {
                return;
            }

            $wstawione = true;

            // „Drugie żądanie" wygrywa wyścig: wstawia zgłoszenie w chwili,
            // gdy pierwsze już sprawdziło, że go nie ma.
            DB::table('reports')->insert([
                'id' => $konkurencyjneId,
                // Surowy `INSERT` omija model, a `numer_sprawy` jest
                // `NOT NULL` (D-029) — patrz komentarz w
                // `IdempotencjaZgloszeniaTest::identyfikatory()`.
                'numer_sprawy' => NumerSprawy::wygeneruj(),
                'reporter_id' => $zglaszajacy->getKey(),
                'target_type' => 'post',
                'target_id' => $wpis->getKey(),
                'reason' => 'spam',
                'status' => Report::STATUS_OPEN,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $wynik = app(ReportContent::class)->handle(
            reporter: $zglaszajacy,
            target: $wpis,
            reason: 'harassment',
            details: 'Zgłoszenie, które przegrało wyścig.',
        );

        $this->assertTrue($wstawione, 'Konkurencyjny wiersz nie wszedł — test nie zmierzył wyścigu.');
        $this->assertSame(1, Report::query()->count(), 'Wyścig utworzył drugą sprawę moderacyjną.');
        $this->assertSame(
            $konkurencyjneId,
            $wynik->getKey(),
            'Zgłaszający ma dostać sprawę, która wygrała wyścig, a nie wyjątek ani drugi wiersz.',
        );
    }
}

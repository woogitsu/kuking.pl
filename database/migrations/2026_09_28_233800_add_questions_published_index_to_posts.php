<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * INDEKS CZĘŚCIOWY OPUBLIKOWANYCH PYTAŃ (#372, pomiar przed włączeniem flagi).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO
 * ────────────────────────────────────────────────────────────────────────
 *
 * `/pytania` przy każdym wejściu liczy „Czeka na odpowiedź (N)” osobnym
 * COUNT-em po całym zbiorze pytań (`QuestionController::index`). Pomiar
 * `scripts/pomiar-pytan-372.py` (200 000 wpisów, 5% pytań; wyniki
 * w docs/product/WLACZENIE_PYTAN_372.md) pokazał, że bez tego indeksu:
 *
 *   • gość: `Parallel Seq Scan on posts` — pełny skan, żeby znaleźć 5% pytań;
 *   • zalogowany: `Bitmap Index Scan on posts_author_published_idx` po
 *     ~193 000 pozycjach (w praktyce też pełny przegląd), a zawyżony koszt
 *     planu włączał JIT, który sam kosztował ~280 ms z ~480 ms.
 *
 * Z indeksem strona `posts` to kilka tysięcy pozycji indeksu, licznik
 * zalogowanego spada do ~110 ms (koszt planu poniżej progu optymalizacji
 * JIT), a lista „Najnowsze” i „Czeka na odpowiedź” może czytać indeks
 * w kolejności `published_at DESC, id DESC` — tej samej, której używa kursor.
 *
 * Predykat to warunki, które KAŻDE zapytanie listy i licznika ma zawsze:
 * `kind = 'question'` (QuestionList), `deleted_at IS NULL` (SoftDeletes),
 * `status = 'published'` (published()). Widoczności nie ma w predykacie
 * celowo: gość pyta o `public`, zalogowany o `public OR followers OR własne`.
 *
 * Czego ten indeks NIE załatwia: licznik gościa nadal robi `Hash Anti Join`
 * z pełnym skanem `comments` (planista wybiera go przy domyślnym
 * `random_page_cost = 4`). Opisane w docs/product/WLACZENIE_PYTAN_372.md
 * razem z progiem, przy którym trzeba wrócić do tematu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CONCURRENTLY, POZA TRANSAKCJĄ — jak 2026_09_25_100000_indeksy_kluczy_obcych…
 * ────────────────────────────────────────────────────────────────────────
 *
 * `posts` to gorąca tabela; zwykłe `CREATE INDEX` wstrzymałoby zapisy na czas
 * budowy. Nieudane `CONCURRENTLY` zostawia indeks INVALID pod tą nazwą —
 * zdejmujemy go przed ponowną budową. W otwartej transakcji (test wołający
 * `up()`/`down()` wprost) budujemy zwykłym `CREATE INDEX`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK
 * ────────────────────────────────────────────────────────────────────────
 *
 * `down()` zdejmuje indeks. Bezstratnie: indeks nie niesie danych ani decyzji
 * człowieka, więc D-088 nie ma tu czego chronić i `down()` nie odmawia.
 * Skutkiem cofnięcia jest powrót planów sprzed migracji.
 *
 * `INDEKS` i `DEFINICJA` czyta też `scripts/pomiar-pytan-372.py` — pomiar
 * zakłada dokładnie ten indeks, który zakłada migracja.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEKS = 'posts_questions_published_idx';

    private const DEFINICJA = "ON posts (published_at DESC, id DESC) WHERE kind = 'question' AND deleted_at IS NULL AND status = 'published'";

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $wspolbieznie = $this->wspolbieznie();

        if ($this->jestNiedokonczony()) {
            DB::statement('DROP INDEX '.$wspolbieznie.'IF EXISTS '.self::INDEKS);
        }

        DB::statement('CREATE INDEX '.$wspolbieznie.'IF NOT EXISTS '.self::INDEKS.' '.self::DEFINICJA);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX '.$this->wspolbieznie().'IF EXISTS '.self::INDEKS);
    }

    private function wspolbieznie(): string
    {
        return DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }

    private function jestNiedokonczony(): bool
    {
        return DB::selectOne(
            'SELECT 1 AS jest FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid '
            .'WHERE c.relname = ? AND NOT i.indisvalid',
            [self::INDEKS],
        ) !== null;
    }
};

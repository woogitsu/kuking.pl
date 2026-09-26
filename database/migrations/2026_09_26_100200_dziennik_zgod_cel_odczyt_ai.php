<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Druga zgoda w `dziennik_zgod`: `odczyt_ai` (D-296) i nowe źródło
 * `ekran_importu`.
 *
 * `dziennik_zgod_cel_check` był zamknięty na jednej wartości celowo: „druga
 * zgoda to nowa migracja i nowa recenzja, a nie dowolny napis wpisany przez
 * kod z przyszłości” (migracja `2026_09_10_400000_create_dziennik_zgod_table`).
 * To jest ta migracja. Zgoda „odczyt AI” pozwala wysłać ZDJĘCIE KARTKI tej
 * osoby do OpenAI — wyjątek od D-240, uzasadniony w D-296.
 *
 * `ekran_importu` — zgodę daje się przed pierwszym odczytem, na ekranie
 * „Przepisz z kartki”, a nie tylko w ustawieniach. „Gdzie człowiek wtedy był”
 * jest częścią dowodu (D-072), więc dostaje własne źródło.
 *
 * ISTNIEJĄCA TABELA → `NOT VALID` + `VALIDATE` poza jedną transakcją
 * (AGENTS.md §6). Nowy CHECK jest nadzbiorem starego, więc walidacja nie
 * może trafić na wiersz, który go łamie.
 *
 * `down()` ODMAWIA, gdy w dzienniku są wiersze z nowym celem albo źródłem
 * (D-088): dziennik jest append-only (wyzwalacz blokuje `DELETE`), więc
 * przywrócenie węższego CHECK-a nie ma jak się udać bez skasowania dowodu
 * zgody, a skasowanie dowodu to decyzja człowieka, nie skutek rollbacku.
 * Na świeżej bazie i bez takich wierszy cofnięcie przechodzi bez pytania.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const CELE_NOWE = "('tygodniowy_digest', 'odczyt_ai')";

    private const CELE_STARE = "('tygodniowy_digest')";

    private const ZRODLA_NOWE = "('ustawienia', 'link_wypisania', 'link_powrotny', 'usuniecie_konta', 'ekran_importu')";

    private const ZRODLA_STARE = "('ustawienia', 'link_wypisania', 'link_powrotny', 'usuniecie_konta')";

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $this->podmien('dziennik_zgod_cel_check', 'cel IN '.self::CELE_NOWE);
        $this->podmien('dziennik_zgod_zrodlo_check', 'zrodlo IN '.self::ZRODLA_NOWE);
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $wiersze = (int) DB::table('dziennik_zgod')
            ->where('cel', 'odczyt_ai')
            ->orWhere('zrodlo', 'ekran_importu')
            ->count();

        if ($wiersze > 0) {
            throw new RuntimeException(
                'Cofnięcie tej migracji zwęziłoby dziennik zgód do jednego celu, a w dzienniku jest '
                .$wiersze.' zapisów zgody na odczyt zdjęć kartek przez AI (cel odczyt_ai albo źródło '
                ."ekran_importu). Dziennik jest tylko do dopisywania — tych zapisów nie da się usunąć bez zdjęcia wyzwalacza, a to są dowody zgody (RODO art. 7 ust. 1, D-072, D-296).\n\n"
                ."CO ZROBIĆ ZAMIAST TEGO\n"
                .'Zostaw tę migrację. Żeby wyłączyć odczyt przepisów, usuń OPENAI_IMPORT_KEY — '
                .'zgody w dzienniku niczego wtedy nie uruchamiają. Jeśli dowody mają naprawdę '
                .'zniknąć, najpierw wyeksportuj je (\\copy (SELECT * FROM dziennik_zgod WHERE cel = '
                ."'odczyt_ai' OR zrodlo = 'ekran_importu') to 'zgody_odczyt_ai.csv' csv header) i zdecyduj o tym jako człowiek, poza migracją.",
            );
        }

        $this->podmien('dziennik_zgod_zrodlo_check', 'zrodlo IN '.self::ZRODLA_STARE);
        $this->podmien('dziennik_zgod_cel_check', 'cel IN '.self::CELE_STARE);
    }

    private function podmien(string $nazwa, string $warunek): void
    {
        DB::statement("ALTER TABLE dziennik_zgod DROP CONSTRAINT IF EXISTS {$nazwa}");
        DB::statement("ALTER TABLE dziennik_zgod ADD CONSTRAINT {$nazwa} CHECK ({$warunek}) NOT VALID");
        DB::statement("ALTER TABLE dziennik_zgod VALIDATE CONSTRAINT {$nazwa}");
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

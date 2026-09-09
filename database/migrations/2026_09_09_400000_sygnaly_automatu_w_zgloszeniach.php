<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TRZECIE ŹRÓDŁO ZGŁOSZENIA: `automat` (D-052).
 *
 * DLACZEGO W `reports`, A NIE W NOWEJ TABELI
 * Bo koniec drogi jest ten sam: decyzja moderatora, wiersz
 * w `moderation_actions`, ścieżka odwołania, wspólna retencja
 * (`kuking:sprzataj-sprawy-moderacyjne`). Druga tabela znaczyłaby drugą
 * kolejkę, drugi ekran do utrzymania i drugą okazję, żeby jedna z nich
 * została z tyłu — dokładnie ten argument, którym `docs/DATABASE.md`
 * uzasadnia trzymanie drogi społecznościowej i prawnej razem.
 *
 * CZYM `automat` RÓŻNI SIĘ OD POZOSTAŁYCH DWÓCH
 *  - nie ma zgłaszającego (`reporter_id IS NULL`) i nie ma komu odpowiedzieć,
 *    więc nie uruchamia obowiązków z DSA art. 16 ust. 4 i 5 — nikt nic nie
 *    zgłosił;
 *  - MUSI mieć cel: automat ogląda konkretną treść, więc wiersz bez celu
 *    znaczyłby błąd w kodzie, a nie sytuację życiową (ta sama zasada, co
 *    `reports_community_target_check`);
 *  - powstaje najwyżej RAZ na treść. To nie jest optymalizacja, tylko
 *    obietnica złożona moderatorowi: „to nic takiego" ma zamknąć sprawę
 *    NA ZAWSZE. Automat, który wraca z tym samym po ponownej analizie,
 *    kłóci się z człowiekiem w kółko (`docs/research/repos/discourse-discourse.md`
 *    §4.5 — Discourse rozwiązał to tym samym warunkiem).
 *
 * `autor_tresci_id` — KOLUMNA POD GRUPOWANIE KOLEJKI
 * Dziesięć wpisów tego samego spamera ma być JEDNĄ pozycją do przejrzenia,
 * nie dziesięcioma. Bez tej kolumny autora trzeba by odczytywać z czterech
 * różnych tabel dla każdego wiersza osobno — czyli grupować w PHP po
 * pobraniu wszystkiego, co przy tysiącu kont przestaje działać dokładnie
 * wtedy, kiedy grupowanie jest najbardziej potrzebne.
 *
 * Wypełniamy ją WYŁĄCZNIE dla `source = 'automat'`. Dla zgłoszeń od ludzi
 * zostaje pusta i to jest świadome: tamte kolejki nie grupują po autorze
 * (zgłoszenie dotyczy sprawy, nie osoby), a wypełnianie kolumny „na zapas"
 * dokładałoby zapytanie do każdego zgłoszenia bez jednego odbiorcy tej
 * wiedzy.
 */
return new class extends Migration
{
    private const INDEKS_JEDEN_NA_TRESC = 'reports_jeden_automat_na_tresc';

    private const INDEKS_GRUPOWANIE = 'reports_automat_autor_idx';

    /** Statusy, w których sprawa jest jeszcze otwarta — ten sam zbiór co w `Report::isOpen()`. */
    private const OTWARTE = "'open','triage','reviewing'";

    /** Świadome wymuszenie kasowania rozstrzygniętych oznaczeń — patrz `down()`. */
    private const FURTKA = 'KUKING_ROLLBACK_KASUJE_SYGNALY_AUTOMATU';

    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            // `nullOnDelete`, tak samo jak `reporter_id`: skasowanie konta nie
            // może kasować sprawy moderacyjnej, bo decyzja i jej uzasadnienie
            // muszą przeżyć autora treści (DSA art. 17, retencja z ADR §5.3).
            $table->foreignUuid('autor_tresci_id')->nullable()->after('reporter_id')
                ->constrained('users')->nullOnDelete();
        });

        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_source_check');
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_source_check CHECK (source IN ('community','legal_notice','automat'))");

        // Automat zawsze wie, na co patrzy — inaczej nie miałby czego analizować.
        DB::statement(<<<'SQL'
            ALTER TABLE reports ADD CONSTRAINT reports_automat_target_check CHECK (
                source <> 'automat'
                OR (target_type IS NOT NULL AND target_type <> 'unknown' AND target_id IS NOT NULL)
            )
        SQL);

        // Jedno oznaczenie na treść — NA ZAWSZE, także po odrzuceniu.
        // Warunek celowo NIE zawęża się do spraw otwartych (inaczej niż
        // `reports_one_open_per_pair`): tam nowe zgłoszenie po zamknięciu
        // poprzedniego jest normalnym życiem, bo składa je człowiek, który
        // widzi coś nowego. Tu wraca ten sam automat z tym samym powodem.
        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEKS_JEDEN_NA_TRESC.' ON reports (target_type, target_id) '
            ."WHERE source = 'automat'",
        );

        // Kolejka automatu: otwarte pozycje jednego autora, najnowsze pierwsze.
        DB::statement(
            'CREATE INDEX '.self::INDEKS_GRUPOWANIE.' ON reports (autor_tresci_id, created_at DESC) '
            ."WHERE source = 'automat' AND status IN (".self::OTWARTE.')',
        );
    }

    /**
     * WYCOFANIE.
     *
     * Kolejność jest odwrotna do `up()` i ma jeden warunek, którego nie da się
     * obejść: przywrócenie starego `reports_source_check` nie przejdzie,
     * dopóki w tabeli stoi choć jeden wiersz z `source = 'automat'`.
     *
     * Dlatego kasujemy oznaczenia OTWARTE — przy nich nikt niczego nie
     * postanowił, więc nie ma czego stracić — a przy oznaczeniach już
     * ROZSTRZYGNIĘTYCH przerywamy z komunikatem. Tam zapadła decyzja
     * człowieka: wiersz niesie powód, dla którego moderator coś ukrył albo
     * kogoś zawiesił, i jest jedynym miejscem, w którym da się to odtworzyć
     * przy odwołaniu.
     *
     * Świadome wymuszenie (najpierw kopia tabeli):
     * `KUKING_ROLLBACK_KASUJE_SYGNALY_AUTOMATU=1`.
     *
     * Wiersz w `moderation_actions` przeżywa takie skasowanie —
     * `report_id` ma `nullOnDelete`, więc decyzja zostaje, traci tylko
     * odnośnik do sprawy.
     */
    public function down(): void
    {
        if ($this->isPostgres()) {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEKS_GRUPOWANIE);
            DB::statement('DROP INDEX IF EXISTS '.self::INDEKS_JEDEN_NA_TRESC);
            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_automat_target_check');

            // STRAŻNIK STOI PRZED PIERWSZYM `DELETE`, nie po nim. Odmowa,
            // która i tak zdążyła coś skasować, jest tylko ładniejszym
            // komunikatem o stracie — a poleganie na tym, że wyjątek wycofa
            // transakcję migracji, przenosi bezpieczeństwo danych do
            // szczegółu konfiguracji sterownika.
            //
            // `getenv()`, nie `env()` — tak samo jak trzy pozostałe migracje
            // z furtką w tym repozytorium. Furtkę podaje się w środowisku
            // procesu (`KUKING_…=1 php artisan migrate:rollback`), a odczyt
            // przez warstwę konfiguracji przestałby ją widzieć dokładnie
            // tam, gdzie jest potrzebna: na produkcji.
            $rozstrzygniete = DB::table('reports')
                ->where('source', 'automat')
                ->whereIn('status', ['resolved', 'rejected'])
                ->count();

            if ($rozstrzygniete > 0 && getenv(self::FURTKA) !== '1') {
                throw new RuntimeException(
                    'W bazie jest '.$rozstrzygniete.' rozstrzygniętych oznaczeń automatu. '
                    .'Ich skasowanie zabrałoby powód, dla którego moderator podjął decyzję — '
                    .'a to jest dokument potrzebny przy odwołaniu. Zrób kopię tabeli `reports`, '
                    .'a potem powtórz z '.self::FURTKA.'=1.',
                );
            }

            // Oznaczenia OTWARTE giną bez pytania: nikt niczego przy nich nie
            // postanowił, a automat postawi je z powrotem, gdy migracja
            // wróci.
            DB::table('reports')->where('source', 'automat')->delete();

            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_source_check');
            DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_source_check CHECK (source IN ('community','legal_notice'))");
        }

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('autor_tresci_id');
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

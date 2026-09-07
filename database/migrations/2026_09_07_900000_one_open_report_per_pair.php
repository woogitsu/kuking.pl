<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Jedno OTWARTE zgłoszenie na parę (osoba, treść) — egzekwowane w bazie.
 *
 * Decyzja właściciela z 7 września 2026, ADR
 * `docs/decyzje/ADR_IDEMPOTENCJA_FORMULARZY.md` §3.4 i §4.2 (krok 1 z §8.1).
 *
 * PO CO OGRANICZENIE W BAZIE, SKORO DEDUP W PHP DZIAŁA
 * `ReportContent::handle()` szuka otwartego zgłoszenia tej pary i zwraca je
 * zamiast tworzyć drugie. Zmierzone (ADR, POMIAR 3): przy zwykłym podwójnym
 * kliknięciu to DZIAŁA — powstaje jeden wiersz. Ale:
 *
 *   * ręczny `INSERT` identycznego, otwartego zgłoszenia przechodził
 *     (POMIAR 3b), więc seeder, komenda konsolowa i przyszły endpoint nie są
 *     chronione — a to jest prawdopodobniejsze z dwóch wejść do tej dziury;
 *   * dwa równoległe połączenia widziały „brak zgłoszenia" jednocześnie
 *     i wstawiały dwa wiersze bez czekania (POMIAR 3, izolacja
 *     `read committed`).
 *
 * `AGENTS.md` §6: „prawdziwe klucze obce i prawdziwe CHECK-i w bazie —
 * walidacja w PHP jest dodatkiem, nie zamiennikiem". Tutaj było odwrotnie.
 * Ten sam argument i ten sam wzorzec, co
 * `2026_09_06_190000_one_decision_per_report.php`.
 *
 * `lockForUpdate()` NIE JEST TU ROZWIĄZANIEM I NIE ZOSTAŁ DODANY.
 * Zmierzone (ADR §1.4.2, POMIAR 3d): `SELECT ... FOR UPDATE`, który nie
 * zwrócił żadnego wiersza, nie blokuje niczego — oba połączenia przechodzą do
 * `INSERT` bez czekania. To jest wstawienie fantomu, a nie konflikt na
 * wierszu. Dopisanie tej blokady i napisanie „naprawione" byłoby obietnicą
 * bez pokrycia w kodzie.
 *
 * DLACZEGO INDEKS CZĘŚCIOWY, A NIE ZWYKŁY UNIQUE
 * Dwa powody, oba zmierzone:
 *
 *   1. Zgłoszenie tej samej pary PO ZAMKNIĘCIU poprzedniego musi przejść —
 *      ktoś zgłosił, dostał decyzję, treść znów jest nie w porządku
 *      (POMIAR 3f, wiersz 2). Dlatego warunek na `status`.
 *   2. `reporter_id` jest `NULL` przy zgłoszeniach bez konta (DSA art. 16
 *      ust. 2 lit. c, migracja `allow_anonymous_legal_notices`). W PostgreSQL
 *      zwykły UNIQUE przepuszcza dowolnie wiele `NULL`-i, więc technicznie
 *      zadziałałby — ale indeks częściowy mówi to wprost i nie każe
 *      czytelnikowi pamiętać o tej właściwości.
 *
 * CZEGO TEN INDEKS NIE ŁAPIE: zgłoszeń bez konta. Te są objęte osobno,
 * kluczem wysłania (migracja `add_klucz_wyslania_to_reports`).
 *
 * MIGRACJA ODMAWIA, GDY DUPLIKATY JUŻ SĄ W BAZIE. Ciche skasowanie
 * „nadmiarowego" zgłoszenia byłoby skasowaniem sprawy z DSA art. 16, na którą
 * ktoś mógł się już powołać i której należy się osobna odpowiedź. Który
 * wiersz obowiązuje, rozstrzyga człowiek.
 *
 * ROLLBACK: `DROP INDEX IF EXISTS`, bezstratnie — indeks nie przechowuje
 * niczego, czego nie ma w tabeli. Po cofnięciu wraca dzisiejszy stan: dedup
 * w PHP działa, baza nie broni niczego. `down()` nie ma więc czego odmawiać.
 */
return new class extends Migration
{
    private const INDEKS = 'reports_one_open_per_pair';

    /**
     * Stany otwarte wypisane WPROST, nie przez stałe z `App\Models\Report`.
     * Migracja opisuje schemat z dnia, w którym powstała — gdyby model kiedyś
     * zmienił słownik stanów, ta migracja i tak musi dać się odtworzyć od
     * zera bez zmiany znaczenia. Żadna inna migracja w tym repozytorium nie
     * importuje klas z `app/`.
     *
     * @var list<string>
     */
    private const STANY_OTWARTE = ['open', 'triage', 'reviewing'];

    public function up(): void
    {
        $duplikaty = DB::table('reports')
            ->selectRaw('reporter_id, target_type, target_id, count(*) as ile')
            ->whereNotNull('reporter_id')
            ->whereIn('status', self::STANY_OTWARTE)
            ->groupBy('reporter_id', 'target_type', 'target_id')
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplikaty->isNotEmpty()) {
            $opis = $duplikaty
                ->map(fn ($wiersz): string => $wiersz->reporter_id.' → '.$wiersz->target_type.':'.$wiersz->target_id.' ('.$wiersz->ile.')')
                ->implode('; ');

            throw new RuntimeException(
                'W `reports` są już pary (zgłaszający, treść) z więcej niż jednym otwartym zgłoszeniem: '
                .$opis.'. '
                .'Rozstrzygnij, która sprawa obowiązuje, zamknij pozostałe i uruchom migrację ponownie. '
                .'Nie robię tego automatycznie: to są sprawy moderacyjne z terminem odpowiedzi '
                .'z DSA art. 16, a każdej z nich należy się osobna decyzja.',
            );
        }

        $stany = "'".implode("','", self::STANY_OTWARTE)."'";

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEKS.' ON reports (reporter_id, target_type, target_id) '
            .'WHERE reporter_id IS NOT NULL AND status IN ('.$stany.')',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS '.self::INDEKS);
    }
};

<?php

declare(strict_types=1);

use App\Domain\Moderation\PriorytetSprawy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRIORYTET W DANYCH, A NIE W GŁOWIE MODERATORA (D-070).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  STAN SPRZED TEJ MIGRACJI
 * ────────────────────────────────────────────────────────────────────────
 *
 * `docs/legal/MODERATION_PLAYBOOK.md` §3 dzielił sprawy na P0–P3 i sam
 * przyznawał, że to „porządek w głowie moderatora i nic go nie wymusza":
 * `reports` nie miało kolumny priorytetu, a `/admin/zgloszenia` sortowało
 * `ORDER BY created_at DESC`. Skutek dosłowny: zgłoszenie CSAM sprzed dwóch
 * dni leżało w kolejce NIŻEJ niż spam sprzed godziny, a podręcznik radził
 * moderatorowi „przeglądaj całą zakładkę, a nie tylko jej pierwszy ekran".
 * Przy jednym–dwóch moderatorach to nie jest rada, tylko przyznanie, że
 * narzędzie nie pomaga.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO KOLUMNA NAZYWA SIĘ `priorytet`, A NIE `priority`
 * ────────────────────────────────────────────────────────────────────────
 *
 * `AGENTS.md` §11 każe pisać kod po angielsku, a teksty i dokumentację po
 * polsku. Ta kolumna stoi na granicy: nie przechowuje pojęcia z frameworka
 * ani z przepisu (`status`, `source`, `reason`, `good_faith_at` — te
 * ZOSTAJĄ angielskie), tylko KATEGORIĘ Z NASZEJ WŁASNEJ PROCEDURY
 * OPERACYJNEJ, opisanej po polsku w podręczniku moderacji. Ta sama tabela
 * ma już dwie takie kolumny i obie są po polsku z tego samego powodu:
 * `numer_sprawy` (nasz format numeru w korespondencji) i `klucz_wyslania`
 * (nasza tożsamość jednego wysłania formularza).
 *
 * Drugi powód jest praktyczny: `priority` w tabeli czyta się jak wagę
 * technicznej kolejki zadań i pierwszą rzeczą, jaką ktoś z nią zrobi, będzie
 * wpisanie tam dowolnej liczby „bo wyżej znaczy pilniej". `priorytet` z
 * `CHECK` na 0–3 i z jednym miejscem, które nadaje wartość
 * (`App\Domain\Moderation\PriorytetSprawy`), nie zaprasza do tego.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO DOKŁADAMY
 * ────────────────────────────────────────────────────────────────────────
 *
 *  - `priorytet` (smallint 0–3, NOT NULL, domyślnie P2) — 0 znaczy
 *    NAJPILNIEJSZE, żeby `ORDER BY priorytet ASC` czytało wprost z indeksu.
 *    Wartość WYNIKA Z POWODU zgłoszenia (`PriorytetSprawy::MAPOWANIE`),
 *    a nadaje ją hak `Report::booted()` — tak samo jak `numer_sprawy`,
 *    i z tego samego powodu: zgłoszenie powstaje pięcioma drogami
 *    (formularz społecznościowy, formularz prawny bez konta, automat,
 *    seeder, test), a piąta droga bez priorytetu znaczyłaby sprawę, której
 *    kolejka nie umie ustawić.
 *
 *  - `priorytet_powod` + `priorytet_zmieniony_przez` + `priorytet_zmieniony_o`
 *    — RĘCZNA zmiana priorytetu z uzasadnieniem. Automat czyta wyłącznie
 *    kategorię wybraną przez zgłaszającego, nie treść: „Ujawnia czyjeś dane
 *    osobowe" to P1, dopóki człowiek nie zobaczy, że to aktywny doxxing
 *    z adresem domowym, czyli P0. Zmiana BEZ uzasadnienia byłaby w logu
 *    nieodróżnialna od pomyłki, więc `CHECK` wymaga jej razem z powodem.
 *
 *  - `przeglad_zaczety_przez` + `przeglad_zaczety_o` — „wziąłem do
 *    przeglądu" (status `reviewing`). Do dziś ten status istniał w schemacie
 *    i NIC go nie nadawało: kod prowadził sprawę z `open` prosto do
 *    `resolved`/`rejected`, a zakładka „W trakcie" w panelu była stale
 *    pusta. Pełne uzasadnienie, dlaczego wdrażamy, a nie kasujemy: D-070.
 *
 *  - `reports_kolejka_priorytet_idx` — indeks częściowy pod jedno zapytanie
 *    kolejki i pod licznik „P0 nieprzejrzane".
 *
 * Kolumny na priorytet AUTOMATYCZNY (tego z mapowania) świadomie NIE MA:
 * da się go w każdej chwili policzyć z `reason`, a druga kolumna z tą samą
 * wiedzą to druga okazja, żeby się rozjechały.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  `triage` WYCHODZI Z DOZWOLONYCH STATUSÓW
 * ────────────────────────────────────────────────────────────────────────
 *
 * `status IN ('open','triage','reviewing','resolved','rejected')` od
 * 5 września dopuszczał wartość, której nie nadaje ani jedna linia kodu
 * i której nie pokazuje ani jeden ekran. `reviewing` zaczyna tą zmianą
 * działać naprawdę; `triage` nie ma czego zacząć — nie ma etapu „wstępna
 * kwalifikacja" ani osobnej osoby, która by ją robiła. Zwężamy więc `CHECK`,
 * zamiast trzymać w schemacie stan udający funkcję (D-070, znalezisko
 * MOD-04).
 *
 * WARUNKI DWÓCH INDEKSÓW CZĘŚCIOWYCH (`reports_one_open_per_pair`,
 * `reports_automat_autor_idx`) NADAL WYMIENIAJĄ `'triage'` I ZOSTAJĄ TAKIE.
 * To jest nadzbiór wartości dopuszczalnych, więc nic nie psuje: PostgreSQL
 * dalej dowodzi, że `status IN ('open','reviewing')` z zapytania implikuje
 * warunek indeksu, i dalej go używa. Przebudowa obu indeksów po to, żeby
 * usunąć z ich definicji wartość niemożliwą, kosztowałaby zdjęcie
 * i postawienie od nowa indeksu, który chroni przed podwójnym zgłoszeniem —
 * czyli realne ryzyko za zero zysku.
 */
return new class extends Migration
{
    private const INDEKS_KOLEJKA = 'reports_kolejka_priorytet_idx';

    /**
     * Statusy, w których sprawa jeszcze czeka na człowieka — ten sam zbiór
     * co `Report::STANY_OTWARTE`. Wpisany dosłownie, nie wzięty ze stałej
     * modelu: warunek indeksu jest zapisany w bazie na zawsze i nie może się
     * zmienić dlatego, że ktoś w przyszłości poprawi stałą w PHP.
     */
    private const OTWARTE = "'open','reviewing'";

    /** Świadome wymuszenie wycofania z pilnymi sprawami w kolejce — patrz `down()`. */
    private const FURTKA = 'KUKING_ROLLBACK_KASUJE_PRIORYTETY';

    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            // `default` na P2, a nie na P0 ani P3. Dotyczy wyłącznie wiersza
            // wstawionego drogą, która pominęła hak modelu (surowy
            // `DB::table()->insert()` w komendzie albo w migracji) — czyli
            // sytuacji „nie wiemy, co to jest". Uzasadnienie tej odpowiedzi
            // stoi przy `PriorytetSprawy::DOMYSLNY` i jest tam JEDNO dla
            // bazy i dla PHP.
            $table->unsignedTinyInteger('priorytet')
                ->default(PriorytetSprawy::DOMYSLNY)
                ->after('reason');

            // UZASADNIENIE RĘCZNEJ ZMIANY. 500 znaków, nie 2000 jak
            // `resolution_note`: to jest jedno zdanie o tym, czego automat
            // nie mógł wiedzieć („w treści jest adres domowy i wezwanie,
            // żeby tam pojechać"), a nie uzasadnienie decyzji.
            $table->string('priorytet_powod', 500)->nullable()->after('priorytet');

            // `nullOnDelete`, tak jak `resolved_by` i `reporter_id`:
            // skasowanie konta moderatora nie może skasować sprawy ani
            // śladu, że priorytet ktoś podniósł.
            $table->foreignUuid('priorytet_zmieniony_przez')->nullable()
                ->after('priorytet_powod')
                ->constrained('users')->nullOnDelete();

            $table->timestampTz('priorytet_zmieniony_o')->nullable()->after('priorytet_zmieniony_przez');

            // KTO WZIĄŁ SPRAWĘ DO PRZEGLĄDU I KIEDY (status `reviewing`).
            //
            // Znacznik czasu jest tu równie ważny jak osoba i to nie jest
            // symetria dla ozdoby: sprawa wzięta do przeglądu i nie domknięta
            // musi po jakimś czasie WRÓCIĆ do kolejki, inaczej „wziąłem do
            // przeglądu" byłoby sposobem na trwałe wyciszenie alarmu P0.
            // Próg i sposób powrotu: `App\Domain\Moderation\Przeglad`.
            $table->foreignUuid('przeglad_zaczety_przez')->nullable()
                ->after('priorytet_zmieniony_o')
                ->constrained('users')->nullOnDelete();

            $table->timestampTz('przeglad_zaczety_o')->nullable()->after('przeglad_zaczety_przez');
        });

        if (! $this->isPostgres()) {
            return;
        }

        // Cztery znane priorytety i nic więcej. `CHECK` w bazie, nie sama
        // walidacja w PHP — `AGENTS.md` §6: przy audycie liczy się to, czego
        // baza nie mogła przyjąć.
        DB::statement(
            'ALTER TABLE reports ADD CONSTRAINT reports_priorytet_check CHECK ('
            .'priorytet BETWEEN '.PriorytetSprawy::P0.' AND '.PriorytetSprawy::P3.')',
        );

        /*
         * RĘCZNA ZMIANA PRIORYTETU: ALBO CAŁA, ALBO ŻADNA.
         *
         * DLACZEGO W `num_nonnulls()` NIE MA KOLUMNY Z MODERATOREM.
         * Bo `priorytet_zmieniony_przez` ma `nullOnDelete`. Gdyby wchodziła
         * do tego warunku, skasowanie konta moderatora zamieniłoby poprawny
         * wiersz w niepoprawny — i baza odmówiłaby wtedy skasowania konta,
         * czyli `CHECK` postawiony dla porządku w danych zablokowałby
         * usunięcie konta na żądanie z RODO art. 17. Ta pułapka jest cicha:
         * wychodzi dopiero przy pierwszym prawdziwym kasowaniu, w produkcji,
         * przy operacji, której nie wolno przerwać.
         *
         * Zostają więc dwie kolumny, których nikt nie wyzeruje z boku:
         * powód i czas. Jedna bez drugiej znaczyłaby „ktoś podniósł
         * priorytet i nie wiadomo dlaczego" albo „jest uzasadnienie zmiany,
         * której nie było".
         */
        DB::statement(<<<'SQL'
            ALTER TABLE reports ADD CONSTRAINT reports_priorytet_zmiana_check CHECK (
                num_nonnulls(priorytet_powod, priorytet_zmieniony_o) IN (0, 2)
            )
        SQL);

        /*
         * SPRAWA W PRZEGLĄDZIE MUSI MIEĆ CZAS ROZPOCZĘCIA.
         *
         * Bez tego `reviewing` wróciłoby do stanu, który ta zmiana naprawia:
         * status mówiący „ktoś to czyta", którego nie da się ani sprawdzić,
         * ani wygasić. Osoby nie wymagamy z tego samego powodu co wyżej
         * (`nullOnDelete`), a `przeglad_zaczety_o` po decyzji ZOSTAJE —
         * to ślad, kto sprawę prowadził, i nie ma powodu go czyścić.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE reports ADD CONSTRAINT reports_przeglad_check CHECK (
                status <> 'reviewing' OR przeglad_zaczety_o IS NOT NULL
            )
        SQL);

        /*
         * WYPEŁNIENIE STARYCH WIERSZY — `CASE` ZBUDOWANY Z KLASY DOMENOWEJ.
         *
         * Wpisanie tu liczb wprost dałoby DRUGIE miejsce z mapowaniem
         * powodów na priorytety, a pierwsza zmiana polityki poprawiłaby
         * tylko jedno z dwóch. Rozjazd byłby przy tym niewidoczny: sprawy
         * sprzed migracji miałyby inny priorytet niż identyczne sprawy
         * przyjęte dzień później, a nikt by nie wiedział dlaczego.
         *
         * Jeden `UPDATE`, bez pętli po wierszach: tabela ma dziś rzędy
         * setek wierszy, a to jest wyrażenie liczone przez bazę.
         */
        DB::statement('UPDATE reports SET priorytet = '.PriorytetSprawy::sqlZPowodu('reason'));

        /*
         * KOLEJKA CZYTA WPROST Z INDEKSU.
         *
         * `(priorytet, created_at)` — pierwszy człon daje wagę, drugi
         * „najstarsze pierwsze w obrębie wagi". Oba rosnąco, więc
         * `ORDER BY priorytet, created_at` idzie po indeksie bez sortowania.
         *
         * CZĘŚCIOWY, bo kolejka pyta wyłącznie o sprawy jeszcze otwarte,
         * a tych jest garść przy tabeli, która rośnie z każdą rozpatrzoną
         * sprawą i nie maleje nigdy (retencja to lata, nie dni). Indeks
         * pełny rósłby z historią, a nie z pracą do zrobienia.
         *
         * `source` NIE JEST w warunku, choć kolejka spraw od ludzi filtruje
         * `source <> 'automat'`. Powód: ten sam indeks obsługuje licznik
         * „P0 nieprzejrzane", który patrzy na WSZYSTKIE źródła — bo
         * moderator może podnieść do P0 także oznaczenie automatu, a alarm,
         * który by takiej sprawy nie zobaczył, byłby gorszy niż brak alarmu.
         * Dwa indeksy z prawie tym samym warunkiem kosztowałyby więcej niż
         * dokładka jednego warunku do filtru w zapytaniu.
         */
        DB::statement(
            'CREATE INDEX '.self::INDEKS_KOLEJKA.' ON reports (priorytet, created_at) '
            .'WHERE status IN ('.self::OTWARTE.')',
        );

        /*
         * `triage` WYCHODZI Z DOZWOLONYCH STATUSÓW.
         *
         * Strażnik stoi PRZED zmianą `CHECK`-u, bo `ALTER TABLE ... ADD
         * CONSTRAINT` na tabeli z wierszem `triage` padłby komunikatem
         * o naruszeniu ograniczenia — prawdziwym, ale nic nie mówiącym
         * o tym, co zrobić. Dziś takich wierszy nie ma (żaden kod nie nadaje
         * tego statusu i nigdy nie nadawał), ale w bazie produkcyjnej mógł
         * zostać wpis postawiony ręcznie w trakcie incydentu.
         */
        $wTriage = DB::table('reports')->where('status', 'triage')->count();

        if ($wTriage > 0) {
            throw new RuntimeException(
                'W tabeli `reports` jest '.$wTriage.' spraw ze statusem `triage`, '
                .'który ta migracja usuwa z dozwolonych wartości. Przenieś je najpierw '
                .'na `open` (czekają na człowieka) albo na `reviewing` razem z '
                .'`przeglad_zaczety_o`, jeśli ktoś je już czyta, i powtórz migrację.',
            );
        }

        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_status_check');
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_status_check CHECK (status IN ('open','reviewing','resolved','rejected'))");
    }

    /**
     * WYCOFANIE — I CO SIĘ WTEDY DZIEJE Z KOLEJNOŚCIĄ SPRAW.
     *
     * ────────────────────────────────────────────────────────────────────
     *  TO WYCOFANIE PRZYWRACA STAN GORSZY. MÓWIMY TO WPROST.
     * ────────────────────────────────────────────────────────────────────
     *
     * Zdjęcie kolumny `priorytet` nie jest neutralne: kolejka wraca do
     * `ORDER BY created_at DESC`, czyli do porządku „najnowsze pierwsze",
     * w którym sprawa P0 sprzed dwóch dni znowu leży pod świeżym spamem.
     * Znika też licznik „P0 nieprzejrzane" — nie zgaśnie z komunikatem,
     * tylko przestanie istnieć, a moderator nie ma jak zauważyć braku
     * ostrzeżenia, którego nie widział. To jest dokładnie ten kształt
     * błędu, który audyt znalazł w innej migracji (`down()` przywracający
     * domyślny opt-in na mailing): wycofanie schematu cofające OCHRONĘ,
     * a nie tylko strukturę.
     *
     * Dlatego `down()` ODMAWIA, dopóki w kolejce stoi choć jedna sprawa
     * pilna (P0 albo P1) jeszcze nierozpatrzona, oraz gdy ktokolwiek zmienił
     * priorytet ręcznie — bo `priorytet_powod` jest wtedy jedynym zapisem
     * tego, co moderator zobaczył w treści, a tej wiedzy nie da się odtworzyć
     * z `reason`. Komunikat mówi, co zrobić: najpierw domknąć pilne sprawy,
     * potem wycofywać schemat.
     *
     * Świadome wymuszenie (najpierw kopia tabeli `reports`):
     * `KUKING_ROLLBACK_KASUJE_PRIORYTETY=1`.
     *
     * SPRAWY W `reviewing` WRACAJĄ DO `open`. Bez tego zostałyby w stanie,
     * którego po wycofaniu nikt nie nadaje i nikt nie zdejmuje: leżałyby
     * w zakładce „W trakcie" na zawsze, bo formularz decyzji przyjmuje
     * wyłącznie sprawy `open` (`ModerationController::decide()`). Lepiej
     * oddać je do kolejki, niż zostawić w niewidocznej szufladzie.
     */
    public function down(): void
    {
        if ($this->isPostgres()) {
            $pilne = DB::table('reports')
                ->whereIn('status', ['open', 'reviewing'])
                ->where('priorytet', '<=', PriorytetSprawy::P1)
                ->count();

            $zmienione = DB::table('reports')->whereNotNull('priorytet_zmieniony_o')->count();

            if (($pilne > 0 || $zmienione > 0) && getenv(self::FURTKA) !== '1') {
                throw new RuntimeException(
                    'Wycofanie tej migracji przywraca kolejkę „najnowsze pierwsze", '
                    .'w której sprawa pilna sprzed dwóch dni leży pod świeżym spamem, '
                    .'i usuwa oznaczenie „P0 nieprzejrzane". W kolejce jest teraz '
                    .$pilne.' nierozpatrzonych spraw P0/P1, a '.$zmienione
                    .' spraw ma priorytet zmieniony ręcznie (uzasadnienia zniknęły '
                    .'bezpowrotnie razem z kolumną). Domknij najpierw pilne sprawy, '
                    .'a jeśli naprawdę musisz wycofać schemat teraz — zrób kopię tabeli '
                    .'`reports` i powtórz z '.self::FURTKA.'=1.',
                );
            }

            DB::statement('DROP INDEX IF EXISTS '.self::INDEKS_KOLEJKA);
            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_przeglad_check');
            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_priorytet_zmiana_check');
            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_priorytet_check');

            // Sprawy w przeglądzie z powrotem do kolejki — PRZED zwężeniem
            // `CHECK`-u statusu, żeby nie zostały w stanie, którego nikt już
            // nie umie zdjąć.
            DB::table('reports')->where('status', 'reviewing')->update(['status' => 'open']);

            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_status_check');
            DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_status_check CHECK (status IN ('open','triage','reviewing','resolved','rejected'))");
        }

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('przeglad_zaczety_przez');
            $table->dropConstrainedForeignId('priorytet_zmieniony_przez');
        });

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn(['przeglad_zaczety_o', 'priorytet_zmieniony_o', 'priorytet_powod', 'priorytet']);
        });
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

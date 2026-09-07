<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zgłaszający dostaje dostęp do wewnętrznego systemu skarg (issue #23,
 * DSA art. 20 ust. 1). Pomiar: `docs/decyzje/DSA_POMIAR.md` §3 punkty 2 i 3,
 * potwierdzony na kodzie testami w `PomiarBrakowArt20Test` PRZED tą migracją:
 * `appeals.user_id` był `NOT NULL` i wskazywał wyłącznie autora treści —
 * zgłaszający nie miał żadnej drogi do złożenia skargi, nawet od decyzji
 * `no_action`, którą art. 20 ust. 1 wymienia wprost.
 *
 * CO SIĘ ZMIENIA
 *  - `user_id` przestaje być `NOT NULL` — odwołanie zgłaszającego go nie ma.
 *  - nowa kolumna `appellant` (`author` | `reporter`) mówi, KTO się odwołuje.
 *  - nowa kolumna `report_id` (FK → `reports`) łączy odwołanie zgłaszającego
 *    z JEGO zgłoszeniem — tam, i tylko tam, jest jego tożsamość (imię,
 *    e-mail), bo zgłaszający nie musi mieć konta (art. 16 ust. 2 lit. c,
 *    migracja `allow_anonymous_legal_notices`).
 *  - CHECK `appeals_appellant_identity_check` pilnuje, że te dwie kolumny
 *    nigdy nie mieszają ról: autor ma `user_id`, nigdy `report_id`;
 *    zgłaszający ma `report_id`, nigdy `user_id`. Rozważałem tu
 *    `num_nonnulls(user_id, report_id) = 1` — wzorzec już użyty
 *    w `comments` i `collection_items` — ale on pilnuje tylko „dokładnie
 *    jedno pole wypełnione", nie „WŁAŚCIWE pole dla tej roli". Ktoś mógłby
 *    zapisać `appellant = 'reporter'` z wypełnionym `user_id` zamiast
 *    `report_id` i CHECK by to przepuścił. Jawny warunek per rola jest
 *    dłuższy, ale nie da się go oszukać przez pomyłkę w kolejności pól.
 *  - `UNIQUE (moderation_action_id)` zamienia się na
 *    `UNIQUE (moderation_action_id, appellant)`. Jedna decyzja może dziś
 *    dostać DWA odwołania — jedno od autora treści, jedno od zgłaszającego —
 *    bo art. 20 ust. 1 daje to prawo obu stronom osobno, a nie łącznie.
 *    Przykład, w którym to naprawdę się zdarzy: decyzja `warn` na zgłoszeniu
 *    wpisu — autor odwołuje się od ostrzeżenia, zgłaszający odwołuje się,
 *    bo uważa ostrzeżenie za zbyt łagodne. „Jedno odwołanie na jedną
 *    decyzję" (audyt W3-09) zostaje w mocy PER ROLĘ, nie znika.
 *
 * ODRZUCONA ALTERNATYWA: OSOBNA TABELA `reporter_appeals`.
 * Rozważałem osobną tabelę zamiast rozszerzania `appeals` — czystszy podział
 * (żadnych nullable kolumn, żadnego CHECK-u na rolę), ale trzy koszty
 * przeważyły:
 *  1. Kolejka moderatora (`admin/appeals.blade.php`,
 *     `AdminAppealController::index()`) musi pokazywać WSZYSTKIE otwarte
 *     sprawy w jednej liście posortowanej po terminie — dwie tabele to
 *     `UNION` w każdym zapytaniu, które dziś jest jednym `Appeal::query()`.
 *  2. `ResolveAppeal` (karencja na podtrzymanie własnej decyzji, cofnięcie
 *     skutków, zapis do audytu) jest logiką WSPÓLNĄ dla obu ról — osobna
 *     tabela wymagałaby albo dwóch kopii tej klasy, albo interfejsu nad
 *     dwoma modelami, żeby dostać to, co jedna tabela z kolumną `appellant`
 *     daje za darmo.
 *  3. `moderation_actions_one_per_report` i `appeals_moderation_action_id_*`
 *     już dziś mówią „jedna decyzja, jedno miejsce, gdzie się od niej
 *     odwołujesz" — druga tabela rozbijałaby to na dwa miejsca do
 *     sprawdzenia za każdym razem, gdy ktoś pyta „czy od tej decyzji ktoś
 *     się już odwołał".
 * Cena jednej tabeli: dwie nullable kolumny i jeden CHECK. Cena osobnej
 * tabeli: dwie ścieżki w każdym miejscu, które dziś zna tylko jedną.
 * W małym zespole (`docs/legal/MODERATION_PLAYBOOK.md` §8) to jest
 * różnica między jedną kolejką a dwiema, które trzeba pamiętać sprawdzać.
 *
 * KOGO TO NIE OBEJMUJE — I DLACZEGO TO JEST ŚWIADOME
 * Zgłoszenie prawne wolno dziś złożyć bez żadnych danych (migracja
 * `allow_anonymous_legal_notices`, `illegality_explanation` i `good_faith_at`
 * zostają jedynym wymogiem). Osoba, która nie zostawiła e-maila, nie ma
 * kanału, którym mogłaby odebrać zaproszenie do złożenia skargi: nie ma
 * konta, więc nie ma sesji ani powiadomień w serwisie, i nie ma adresu
 * e-mail, na który wysłać podpisany link. Ta migracja tego nie naprawia, bo
 * NIE DA SIĘ tego naprawić bez naruszenia samej anonimowości, o którą art. 16
 * ust. 2 lit. c prosi. Konsekwencja jest zapisana wprost w kodzie warstwy
 * domenowej (`FileReporterAppeal`) i w raporcie z prac nad #23: zgłaszający
 * bez adresu e-mail nie ma i nie będzie miał dostępu do systemu skarg —
 * zostaje mu wyłącznie napisanie na adres kontaktowy, tak jak dziś.
 *
 * DOSTĘP: podpisany, wygasający link (`URL::temporarySignedRoute`, ten sam
 * mechanizm co `settings.data.download` w `DataExportReady`) w mailu
 * z decyzją — NIE nowa kolumna z tokenem. UUID zgłoszenia w adresie
 * i tak nie byłoby autoryzacją (AGENTS.md §7); podpis kryptograficzny jest
 * tym, co odróżnia ten link od zgadywalnego UUID-a, i wygasa dokładnie
 * z terminem na odwołanie (`ModerationAction::appealDeadline()`), więc nie
 * potrzeba osobnego pola i osobnego zadania czyszczącego wygasłe tokeny.
 *
 * ROLLBACK: `down()` ODMAWIA, jeśli w bazie są już odwołania zgłaszających
 * (`appellant = 'reporter'`) — stary schemat nie ma jak ich pomieścić:
 * `user_id NOT NULL` nie da się przywrócić, kiedy te wiersze mają `user_id
 * NULL`. Wzór: `2026_09_07_600000_allow_anonymous_legal_notices.php`.
 * Jedyna uczciwa droga to usunięcie tych wierszy ręcznie, ze świadomością,
 * że to są zapisy dowodowe („dowieźliśmy odpowiedź na skargę"), albo
 * zostanie na tej wersji schematu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('appeals', function (Blueprint $table): void {
            $table->foreignUuid('report_id')->nullable()->after('moderation_action_id')
                ->constrained('reports')->nullOnDelete();

            $table->string('appellant', 10)->nullable()->after('report_id');
        });

        // Backfill: każde odwołanie, które istnieje dziś, jest odwołaniem
        // AUTORA treści — to jedyna rola, którą schemat sprzed tej migracji
        // w ogóle umiał wyrazić.
        DB::table('appeals')->update(['appellant' => 'author']);

        DB::statement('ALTER TABLE appeals ALTER COLUMN appellant SET NOT NULL');
        DB::statement('ALTER TABLE appeals ALTER COLUMN user_id DROP NOT NULL');

        DB::statement("ALTER TABLE appeals ADD CONSTRAINT appeals_appellant_check CHECK (appellant IN ('author','reporter'))");

        DB::statement(<<<'SQL'
            ALTER TABLE appeals ADD CONSTRAINT appeals_appellant_identity_check CHECK (
                (appellant = 'author' AND user_id IS NOT NULL AND report_id IS NULL)
                OR (appellant = 'reporter' AND report_id IS NOT NULL AND user_id IS NULL)
            )
        SQL);

        DB::statement('ALTER TABLE appeals DROP CONSTRAINT appeals_moderation_action_id_unique');
        DB::statement('CREATE UNIQUE INDEX appeals_moderation_action_appellant_unique ON appeals (moderation_action_id, appellant)');

        DB::statement('CREATE INDEX appeals_report_idx ON appeals (report_id, created_at DESC) WHERE report_id IS NOT NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $odZglaszajacych = DB::table('appeals')->where('appellant', 'reporter')->count();

        if ($odZglaszajacych > 0) {
            throw new RuntimeException(
                "W bazie jest {$odZglaszajacych} odwołań złożonych przez zgłaszających (appellant='reporter'). ".
                'Stary schemat wymaga appeals.user_id NOT NULL, a te wiersze mają user_id NULL — cofnięcie '.
                'migracji je złamie. Te wiersze są dowodem, że zgłaszający dostał odpowiedź na skargę wymaganą '.
                'przez art. 20 ust. 1 DSA. Usuń je ręcznie, ze świadomością tej ceny, albo zostań na tej wersji '.
                'schematu.',
            );
        }

        DB::statement('DROP INDEX IF EXISTS appeals_report_idx');
        DB::statement('DROP INDEX IF EXISTS appeals_moderation_action_appellant_unique');
        DB::statement('ALTER TABLE appeals ADD CONSTRAINT appeals_moderation_action_id_unique UNIQUE (moderation_action_id)');

        DB::statement('ALTER TABLE appeals DROP CONSTRAINT IF EXISTS appeals_appellant_identity_check');
        DB::statement('ALTER TABLE appeals DROP CONSTRAINT IF EXISTS appeals_appellant_check');

        DB::statement('ALTER TABLE appeals ALTER COLUMN user_id SET NOT NULL');

        Schema::table('appeals', function (Blueprint $table): void {
            $table->dropColumn('appellant');
            $table->dropConstrainedForeignId('report_id');
        });
    }
};

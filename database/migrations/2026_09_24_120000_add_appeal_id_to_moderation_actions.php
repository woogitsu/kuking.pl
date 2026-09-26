<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `moderation_actions.appeal_id` — decyzja podjęta PO UZNANIU ODWOŁANIA (#989).
 *
 * PO CO
 * Zgłaszający może odwołać się od decyzji „Bez działania” (DSA art. 20
 * ust. 1). Do 24.09.2026 uznanie takiego odwołania zapisywało `overturned`
 * i wysyłało „Zmieniamy naszą decyzję”, ale nie wykonywało żadnej nowej
 * decyzji: treść zostawała, a jedyną decyzją w rejestrze było `no_action`.
 * Teraz administrator wybiera nową decyzję w formularzu rozpatrzenia,
 * a `ResolveAppeal` wykonuje ją w tej samej transakcji.
 *
 * DLACZEGO NOWA KOLUMNA, A NIE DRUGA DECYZJA Z TYM SAMYM `report_id`
 * `moderation_actions_one_per_report` gwarantuje jedną decyzję PIERWSZEJ
 * instancji na zgłoszenie i tak ma zostać. Decyzja po odwołaniu ma
 * `report_id = NULL` (jak `unhide` i „Zdejmij z urzędu”, D-251) i wskazuje
 * odwołanie, które do niej doprowadziło. Przez odwołanie wskazuje też
 * pierwotną decyzję (`appeals.moderation_action_id`) i zgłoszenie.
 *
 *  - UNIQUE (częściowy): jedno odwołanie — najwyżej jedna decyzja po nim;
 *  - CHECK: decyzja po odwołaniu nie jest decyzją ze zgłoszenia, więc
 *    `appeal_id` i `report_id` nie występują razem;
 *  - `ON DELETE SET NULL`: retencja (`PrzedawnioneSprawyModeracyjne`)
 *    kasuje odwołania PRZED decyzjami. `RESTRICT` zatrzymywałby ją na
 *    zawsze na każdym takim odwołaniu. Po upływie okresu retencji sprawy
 *    powiązanie znika razem z odwołaniem — tak jak cała reszta sprawy.
 *
 * ROLLBACK: odmawia, gdy istnieje choć jedna decyzja z `appeal_id` (D-088).
 * Bez tej kolumny taka decyzja wygląda jak decyzja z urzędu (`report_id`
 * puste), a uzasadnienie dla autora (`UzasadnienieDecyzji::skadSprawa()`)
 * zaczęłoby mówić „Nikt tego nie zgłosił — sprawę znaleźliśmy sami” o
 * sprawie, która zaczęła się od zgłoszenia. Na świeżej bazie i bez takich
 * decyzji rollback przechodzi bez pytania.
 */
return new class extends Migration
{
    private const INDEKS = 'moderation_actions_one_per_appeal';

    private const CHECK = 'moderation_actions_appeal_or_report_check';

    public function up(): void
    {
        Schema::table('moderation_actions', function (Blueprint $table): void {
            $table->foreignUuid('appeal_id')->nullable()->constrained('appeals')->nullOnDelete();
        });

        DB::statement('CREATE UNIQUE INDEX '.self::INDEKS.' ON moderation_actions (appeal_id) WHERE appeal_id IS NOT NULL');
        DB::statement('ALTER TABLE moderation_actions ADD CONSTRAINT '.self::CHECK
            .' CHECK (appeal_id IS NULL OR report_id IS NULL)');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('moderation_actions', 'appeal_id')) {
            return;
        }

        $ile = DB::table('moderation_actions')->whereNotNull('appeal_id')->count();

        if ($ile > 0) {
            throw new RuntimeException(
                'Odmawiam cofnięcia migracji: w moderation_actions są decyzje podjęte po uznaniu odwołania '
                ."(appeal_id niepuste). Liczba takich decyzji: {$ile}. "
                .'Bez tej kolumny wyglądałyby jak decyzje z urzędu, a autor czytałby w uzasadnieniu, '
                .'że nikt tej treści nie zgłosił. CO ZROBIĆ: zachowaj powiązania poza tabelą '
                .'(SELECT id, appeal_id FROM moderation_actions WHERE appeal_id IS NOT NULL), '
                .'wycofaj kod, który ich używa, i dopiero wtedy — świadomie — usuń kolumnę ręcznie.',
            );
        }

        DB::statement('ALTER TABLE moderation_actions DROP CONSTRAINT IF EXISTS '.self::CHECK);
        DB::statement('DROP INDEX IF EXISTS '.self::INDEKS);

        Schema::table('moderation_actions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('appeal_id');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jedno zgłoszenie — jedna decyzja moderacyjna (audyt W3-09, W6-01).
 *
 * PO CO OGRANICZENIE W BAZIE, SKORO JEST JUŻ BLOKADA WIERSZA
 * `ModerationController::decide()` obejmuje decyzję transakcją
 * z `lockForUpdate()` i to wystarcza dla ruchu przez ten kontroler. Ale:
 *
 *   * komenda konsolowa, seeder albo przyszły endpoint API nie przejdą tą
 *     drogą i nikt o tej blokadzie nie będzie pamiętał;
 *   * blokada chroni wiersz `reports`, a ograniczenie mówi wprost o tym, co
 *     naprawdę ma być niepowtarzalne: decyzji dla zgłoszenia jest jedna.
 *
 * AGENTS.md §6: „prawdziwe klucze obce i prawdziwe CHECK-i w bazie —
 * walidacja w PHP jest dodatkiem, nie zamiennikiem". Tutaj było odwrotnie.
 *
 * DLACZEGO INDEKS CZĘŚCIOWY, A NIE ZWYKŁY UNIQUE
 * `report_id` dopuszcza NULL: decyzja może powstać z własnej inicjatywy
 * moderatora, bez żadnego zgłoszenia. W PostgreSQL zwykły UNIQUE przepuszcza
 * dowolnie wiele NULL-i, więc technicznie zadziałałby — ale indeks częściowy
 * mówi to wprost i nie każe czytelnikowi pamiętać o tej właściwości.
 *
 * MIGRACJA ODMAWIA, GDY DUPLIKATY JUŻ SĄ. Skasowanie „nadmiarowej" decyzji
 * po cichu byłoby skasowaniem wpisu w logu moderacji, na który ktoś mógł się
 * już powołać w odwołaniu (DSA art. 17). Który wpis jest właściwy, rozstrzyga
 * człowiek.
 *
 * ROLLBACK: `DROP INDEX IF EXISTS`, bezstratnie.
 */
return new class extends Migration
{
    private const INDEKS = 'moderation_actions_one_per_report';

    public function up(): void
    {
        $duplikaty = DB::table('moderation_actions')
            ->selectRaw('report_id, count(*) as ile')
            ->whereNotNull('report_id')
            ->groupBy('report_id')
            ->havingRaw('count(*) > 1')
            ->pluck('report_id')
            ->all();

        if ($duplikaty !== []) {
            throw new RuntimeException(
                'W `moderation_actions` są już zgłoszenia z więcej niż jedną decyzją: '
                .implode(', ', $duplikaty).'. '
                .'Rozstrzygnij, która decyzja obowiązuje, usuń pozostałe i uruchom migrację ponownie. '
                .'Nie robię tego automatycznie: to są wpisy w logu moderacji, na które ktoś mógł '
                .'powołać się w odwołaniu.',
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX '.self::INDEKS.' ON moderation_actions (report_id) WHERE report_id IS NOT NULL',
        );
    }

    public function down(): void
    {
        Schema::table('moderation_actions', function (): void {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEKS);
        });
    }
};

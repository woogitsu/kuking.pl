<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zgłoszenie treści NIELEGALNEJ nie musi już podawać imienia (decyzja
 * właściciela z 7 września 2026, na podstawie pomiaru
 * `docs/decyzje/DSA_POMIAR.md`).
 *
 * CO BYŁO
 * `reports_legal_notice_complete_check` (migracja
 * `2026_09_06_200000_add_legal_notice_fields_to_reports`) wymagał trzech
 * rzeczy naraz: uzasadnienia, oświadczenia o dobrej wierze I `notifier_name`.
 * Adres e-mail był już opcjonalny — z komentarzem powołującym się dokładnie
 * na ten przepis, który zwalnia też z podania IMIENIA. Czyli reguła była
 * w kodzie w połowie: formularz i baza dopuszczały brak adresu, a wymagały
 * nazwiska.
 *
 * DLACZEGO TO NIE JEST DROBIAZG
 * Art. 16 ust. 2 lit. c DSA zwalnia z podania danych zgłaszającego przy
 * zgłoszeniach dotyczących przestępstw z art. 3-7 dyrektywy 2011/93/UE
 * (przestępstwa seksualne wobec dzieci). Wymóg nazwiska zamykał więc drogę
 * prawną dokładnie w najcięższych sprawach — i to nie teoretycznie:
 * przy społeczności liczonej w dziesiątkach osób z jednej okolicy człowiek
 * zgłaszający kogoś z rodziny albo z sąsiedztwa nie podpisze się nazwiskiem.
 * Serwis dla grupy 50+ w małych miejscowościach to nie jest przypadek
 * skrajny.
 *
 * CZEGO TA MIGRACJA NIE LUZUJE
 * Uzasadnienia nielegalności i oświadczenia o dobrej wierze — one zostają
 * wymagane w bazie. Bez uzasadnienia nie da się treści ocenić, a dobra wiara
 * jest w art. 16 ust. 2 lit. d osobnym wymogiem, niepowiązanym z tożsamością
 * zgłaszającego. Zgłoszenie anonimowe to nie zgłoszenie puste.
 *
 * ROLLBACK: `down()` przywraca stary CHECK. Może się nie udać i to jest
 * ZAMIERZONE: jeśli w bazie są już anonimowe zgłoszenia prawne, PostgreSQL
 * odmówi założenia warunku, którego istniejące wiersze nie spełniają.
 * Alternatywą byłoby dopisanie im wymyślonego nazwiska albo skasowanie —
 * pierwsze jest kłamstwem w kolumnie, drugie niszczy dowód w sprawie
 * o najcięższej możliwej wadze. Lepiej, żeby rollback się zatrzymał
 * i człowiek zobaczył, dlaczego.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_legal_notice_complete_check');

        DB::statement(<<<'SQL'
            ALTER TABLE reports ADD CONSTRAINT reports_legal_notice_complete_check CHECK (
                source <> 'legal_notice'
                OR (illegality_explanation IS NOT NULL AND good_faith_at IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $anonimowe = DB::table('reports')
            ->where('source', 'legal_notice')
            ->whereNull('notifier_name')
            ->count();

        if ($anonimowe > 0) {
            throw new RuntimeException(
                "W bazie jest {$anonimowe} anonimowych zgłoszeń prawnych. Przywrócenie starego "
                ."warunku wymagałoby albo wpisania im wymyślonego nazwiska, albo ich skasowania.\n\n"
                .'Pierwsze jest kłamstwem w kolumnie, drugie niszczy dowód w sprawie, której '
                .'anonimowość jest wprost przewidziana w art. 16 ust. 2 lit. c DSA. '
                .'Zdecyduj świadomie i zrób to ręcznie, zanim cofniesz tę migrację.',
            );
        }

        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_legal_notice_complete_check');

        DB::statement(<<<'SQL'
            ALTER TABLE reports ADD CONSTRAINT reports_legal_notice_complete_check CHECK (
                source <> 'legal_notice'
                OR (illegality_explanation IS NOT NULL AND good_faith_at IS NOT NULL AND notifier_name IS NOT NULL)
            )
        SQL);
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Zgłoszenie nielegalnej treści (DSA art. 16) — pola wymagane przepisem
 * (audyt G-08, W5-01, W5-02).
 *
 * DWIE RÓŻNE RZECZY W JEDNEJ TABELI, ŚWIADOMIE
 * `reports` obsługuje dziś ZGŁOSZENIE SPOŁECZNOŚCIOWE: „to jest spam",
 * „to jest chamskie". Taka droga może wymagać zalogowania i to jest w
 * porządku — to nasze zasady, nie przepis.
 *
 * DSA art. 16 to co innego: mechanizm zgłaszania treści NIELEGALNEJ, dostępny
 * dla KAŻDEJ osoby i każdego podmiotu, także bez konta. Nie wolno kazać komuś
 * zakładać konta w serwisie społecznościowym po to, żeby mógł zgłosić
 * przestępstwo.
 *
 * Nie robimy osobnej tabeli, bo obie drogi kończą się TĄ SAMĄ decyzją
 * moderatora, tym samym wpisem w `moderation_actions` i tą samą ścieżką
 * odwołania. Dwie tabele znaczyłyby dwie kolejki, dwa ekrany moderatora i dwie
 * okazje, żeby jedna z nich została z tyłu. Rozróżnia je kolumna `source`.
 *
 * POLA WYMAGANE PRZEZ ART. 16 UST. 2
 *   - wystarczająco uzasadnione wyjaśnienie, dlaczego treść jest nielegalna
 *     (`illegality_explanation`, osobne od `details`, które jest swobodne);
 *   - dokładna lokalizacja elektroniczna, czyli adres (`target_url`) —
 *     zapisujemy to, co człowiek wpisał, nawet jeśli nie umiemy tego
 *     rozwiązać na konkretną treść;
 *   - imię i adres e-mail zgłaszającego (`notifier_name`, `notifier_email`);
 *   - oświadczenie o dobrej wierze (`good_faith_at` — znacznik czasu, nie
 *     `boolean`: chcemy wiedzieć KIEDY je złożono).
 *
 * ORAZ OBOWIĄZEK ODPOWIEDZI (art. 16 ust. 4-5): potwierdzenie odbioru bez
 * zbędnej zwłoki i powiadomienie o decyzji wraz z pouczeniem o środkach
 * odwoławczych. Bez `receipt_sent_at` i `decision_sent_at` nie da się
 * odpowiedzieć na pytanie „czy wysłaliśmy", a przy audycie to jest pierwsze
 * pytanie.
 *
 * `notifier_email` MOŻE być NULL — art. 16 ust. 2 lit. c przewiduje wyjątek
 * dla zgłoszeń dotyczących przestępstw z art. 3-7 dyrektywy 2011/93/UE.
 * Wtedy nie ma komu wysłać potwierdzenia i to jest zgodne z przepisem.
 *
 * ROLLBACK: `down()` usuwa kolumny. UWAGA: to jest utrata danych, jeśli
 * w tabeli są już zgłoszenia prawne — imię, adres i uzasadnienie znikają,
 * a zostaje samo `reason`. Dlatego `down()` odmawia, gdy takie wiersze są.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table): void {
            $table->string('source', 20)->default('community')->after('reporter_id');

            $table->string('notifier_name', 120)->nullable()->after('source');
            $table->string('notifier_email', 255)->nullable()->after('notifier_name');
            $table->string('target_url', 2000)->nullable()->after('target_id');
            $table->text('illegality_explanation')->nullable()->after('details');

            $table->timestampTz('good_faith_at')->nullable()->after('illegality_explanation');
            $table->timestampTz('receipt_sent_at')->nullable()->after('good_faith_at');
            $table->timestampTz('decision_sent_at')->nullable()->after('receipt_sent_at');
        });

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_source_check CHECK (source IN ('community','legal_notice'))");

        /*
         * ADRES, KTÓREGO NIE UMIEMY ROZWIĄZAĆ, TO NADAL WAŻNE ZGŁOSZENIE.
         *
         * Ktoś wkleja link z pamięci albo ze zrzutu ekranu, treść mogła już
         * zniknąć, adres bywa z innego serwisu. Odrzucenie takiego zgłoszenia
         * byłoby odmówieniem mechanizmu, który przepis nakazuje udostępnić —
         * a dokładnie tak działała baza: CHECK na `target_type` znał tylko
         * pięć typów treści, więc pierwsze zgłoszenie z nierozpoznanym
         * adresem kończyło się błędem 500 na twarzy zgłaszającego.
         *
         * `unknown` NIE JEST DZIURĄ W REGULE, tylko szóstym, nazwanym stanem:
         * ModeratedContent::znajdz() nie zna takiego typu i zwraca null,
         * a ModerationAction::dozwoloneDla() zwraca wtedy samo `none` —
         * czyli moderator może taką sprawę zamknąć i odpowiedzieć, ale nie
         * może „ukryć" treści, której nie wskazano.
         */
        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_target_type_check');
        DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_target_type_check CHECK (target_type IN ('user','post','recipe','comment','cooked_event','unknown'))");

        /*
         * `target_id` MOŻE BYĆ PUSTE — ale tylko przy nierozpoznanym adresie.
         *
         * Kusiło, żeby wstawić tam UUID z samych zer. To byłoby kłamstwo
         * w kolumnie: identyfikator, który wygląda jak identyfikator, ale nie
         * wskazuje niczego, i który prędzej czy później ktoś skopiuje do
         * zapytania albo pokaże na ekranie moderatora. NULL mówi prawdę:
         * nie wiemy, o którą treść chodzi.
         *
         * Zgłoszenie SPOŁECZNOŚCIOWE dalej musi mieć cel — tam przycisk stoi
         * pod konkretną treścią, więc brak celu znaczyłby błąd w kodzie,
         * a nie sytuację życiową. Pilnuje tego CHECK niżej.
         */
        DB::statement('ALTER TABLE reports ALTER COLUMN target_id DROP NOT NULL');
        DB::statement('ALTER TABLE moderation_actions ALTER COLUMN target_id DROP NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE reports ADD CONSTRAINT reports_community_target_check CHECK (
                source <> 'community'
                OR (target_type IS NOT NULL AND target_type <> 'unknown' AND target_id IS NOT NULL)
            )
        SQL);

        // Zgłoszenie prawne MUSI mieć uzasadnienie i oświadczenie o dobrej
        // wierze. CHECK, a nie sama walidacja formularza: przy audycie liczy
        // się to, czego baza NIE MOGŁA przyjąć, a nie to, co formularz
        // odrzucał w chwili, gdy ktoś na niego patrzył.
        DB::statement(<<<'SQL'
            ALTER TABLE reports ADD CONSTRAINT reports_legal_notice_complete_check CHECK (
                source <> 'legal_notice'
                OR (illegality_explanation IS NOT NULL AND good_faith_at IS NOT NULL AND notifier_name IS NOT NULL)
            )
        SQL);

        // Kolejka moderatora filtruje po źródle: zgłoszenia prawne mają
        // termin odpowiedzi, społecznościowe nie.
        DB::statement('CREATE INDEX reports_source_status_idx ON reports (source, status, created_at)');

        // Do znalezienia zgłoszeń czekających na potwierdzenie odbioru
        // albo na powiadomienie o decyzji.
        DB::statement(<<<'SQL'
            CREATE INDEX reports_pending_receipt_idx ON reports (created_at)
            WHERE source = 'legal_notice' AND notifier_email IS NOT NULL AND receipt_sent_at IS NULL
        SQL);
    }

    public function down(): void
    {
        $prawne = DB::table('reports')->where('source', 'legal_notice')->count();

        if ($prawne > 0 && getenv('KUKING_ROLLBACK_KASUJE_ZGLOSZENIA_PRAWNE') !== '1') {
            throw new RuntimeException(
                "W tabeli `reports` jest {$prawne} zgłoszeń nielegalnej treści (DSA art. 16). "
                .'Cofnięcie tej migracji usunie imię, adres e-mail i uzasadnienie zgłaszającego — '
                ."zostanie samo `reason`, czyli zgłoszenie bez treści.\n\n"
                .'To są dane, na podstawie których podjęto decyzje moderacyjne i na które ktoś mógł '
                ."się powołać w odwołaniu.\n\n"
                .'Zrób kopię tabeli, a potem uruchom ponownie '
                .'z KUKING_ROLLBACK_KASUJE_ZGLOSZENIA_PRAWNE=1.',
            );
        }

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS reports_pending_receipt_idx');
            DB::statement('DROP INDEX IF EXISTS reports_source_status_idx');
            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_legal_notice_complete_check');
            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_community_target_check');
            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_source_check');

            // Powrót do stanu sprzed migracji: bez `unknown` i bez pustego
            // celu. Wiersze, które mają puste `target_id`, to wyłącznie
            // zgłoszenia prawne — a te i tak są usuwane wyżej razem
            // z kolumną `source`, więc ALTER nie ma na czym się wywrócić.
            DB::statement('DELETE FROM moderation_actions WHERE target_id IS NULL');
            DB::statement('DELETE FROM reports WHERE target_id IS NULL');
            DB::statement('ALTER TABLE moderation_actions ALTER COLUMN target_id SET NOT NULL');
            DB::statement('ALTER TABLE reports ALTER COLUMN target_id SET NOT NULL');
            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS reports_target_type_check');
            DB::statement("ALTER TABLE reports ADD CONSTRAINT reports_target_type_check CHECK (target_type IN ('user','post','recipe','comment','cooked_event'))");
        }

        Schema::table('reports', function (Blueprint $table): void {
            $table->dropColumn([
                'source', 'notifier_name', 'notifier_email', 'target_url',
                'illegality_explanation', 'good_faith_at', 'receipt_sent_at', 'decision_sent_at',
            ]);
        });
    }
};

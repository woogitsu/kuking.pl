<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * TREŚĆ KOMENTARZA ZDJĘTEGO Z WĄTKU PRZEZ MODERACJĘ (G31, D-251).
 *
 * Komentarz z odpowiedziami nie znika z bazy przy usunięciu — dostaje
 * napis „Komentarz usunięty.” w `body` i `body_removed_at`, żeby wątek
 * się nie rozsypał (`DeleteComment`). Od tej zmiany tę samą regułę stosuje
 * moderacja (decyzja „Usuń” ze zgłoszenia i „Zdejmij z urzędu”). Wcześniej
 * robiła zwykły soft delete i odpowiedzi innych osób znikały razem z nim.
 *
 * Tyle że napis ZASTĘPUJE tekst, a od decyzji moderacji przysługuje
 * odwołanie, które przy „cofam” ma przywrócić treść od razu (DSA art. 17
 * i 20, `ResolveAppeal`). Bez kopii nie byłoby czego przywrócić. Kopia
 * leży przy decyzji, bo tam jest jej miejsce: to jest materiał sprawy,
 * sprzątany razem z nią (`kuking:sprzataj-sprawy-moderacyjne`), a przy
 * usunięciu konta z zakresem „wszystko” czyszczony razem z komentarzami
 * (`EraseAccountData::usunTresci()`).
 *
 * CHECK zawęża kolumnę do jedynego przypadku, dla którego istnieje:
 * decyzja `remove` na komentarzu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moderation_actions', function (Blueprint $table): void {
            $table->text('tresc_sprzed_zdjecia')->nullable();
        });

        DB::statement(
            'ALTER TABLE moderation_actions ADD CONSTRAINT moderation_actions_tresc_sprzed_zdjecia_check '
            ."CHECK (tresc_sprzed_zdjecia IS NULL OR (target_type = 'comment' AND action = 'remove'))",
        );
    }

    /**
     * Rollback ODMAWIA, gdy są zapisane treści (D-088).
     *
     * Po zdjęciu kolumny komentarz z napisem „Komentarz usunięty.” nie ma
     * już skąd wrócić: odwołanie zakończone „cofam” zostawiłoby napis
     * zamiast tekstu, choć powiadomienie obiecuje, że treść wraca od razu.
     * Na świeżej bazie i bez takich decyzji rollback przechodzi bez pytania.
     *
     * Świadome wymuszenie (najpierw kopia kolumny):
     * `KUKING_ROLLBACK_KASUJE_TRESC_ZDJETYCH_KOMENTARZY=1`.
     */
    public function down(): void
    {
        DB::transaction(function (): void {
            DB::statement('LOCK TABLE moderation_actions IN ACCESS EXCLUSIVE MODE');

            $sa = DB::table('moderation_actions')->whereNotNull('tresc_sprzed_zdjecia')->exists();

            if ($sa && getenv('KUKING_ROLLBACK_KASUJE_TRESC_ZDJETYCH_KOMENTARZY') !== '1') {
                throw new RuntimeException(
                    'Nie cofam tej migracji: moderation_actions.tresc_sprzed_zdjecia trzyma tekst komentarzy '
                    .'zdjętych przez moderację, a bez niego odwołanie nie przywróci treści. '
                    .'Co zrobić: 1) skopiuj wiersze z niepustą kolumną (id, target_id, tresc_sprzed_zdjecia); '
                    .'2) wycofaj kod bez cofania migracji albo uruchom ponownie '
                    .'z KUKING_ROLLBACK_KASUJE_TRESC_ZDJETYCH_KOMENTARZY=1.',
                );
            }

            DB::statement('ALTER TABLE moderation_actions DROP CONSTRAINT IF EXISTS moderation_actions_tresc_sprzed_zdjecia_check');

            Schema::table('moderation_actions', function (Blueprint $table): void {
                $table->dropColumn('tresc_sprzed_zdjecia');
            });
        });
    }
};

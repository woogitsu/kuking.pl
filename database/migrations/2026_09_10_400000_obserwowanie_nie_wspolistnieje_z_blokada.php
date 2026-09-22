<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TWARDA BARIERA W BAZIE: nie ma wiersza `follows` między osobami, między
 * którymi istnieje blokada (ustalenie SOCIAL-01, decyzja D-080).
 *
 * ══════════════════════════════════════════════════════════════════════
 *  DLACZEGO WYZWALACZ, A NIE CHECK ANI UNIQUE
 * ══════════════════════════════════════════════════════════════════════
 *
 * Inwariant dotyczy DWÓCH TABEL — „nie istnieje wiersz w `blocks` dla tej
 * pary" — a `CHECK` w PostgreSQL ma prawo patrzeć wyłącznie na wiersz, który
 * właśnie sprawdza. Podzapytanie w `CHECK` jest wprost zabronione, a `CHECK`
 * na funkcji czytającej drugą tabelę jest w tej bazie pułapką: nie jest
 * wymuszany ponownie przy zmianie tamtej tabeli, a `pg_dump`/`pg_restore`
 * potrafi go sprawdzać przy przywracaniu w kolejności, w której druga tabela
 * jest jeszcze pusta. `EXCLUDE` też nie, bo działa w obrębie jednej tabeli.
 * Zostaje wyzwalacz.
 *
 * ── DLACZEGO WYZWALACZ STOI TYLKO NA `follows` ──
 *
 * Bo obie tabele nie są równorzędne. **Blokada musi się udać zawsze.** Jest
 * to jedyna czynność, którą człowiek ma do dyspozycji, gdy ktoś staje się
 * dla niego problemem, i bariera, która potrafiłaby jej ODMÓWIĆ (bo istnieje
 * jakiś wiersz `follows`), byłaby zamkniętymi drzwiami w najgorszym
 * możliwym momencie. Konflikt na tej stronie rozstrzyga `BlockUser`,
 * kasując obserwowanie w obie strony pod blokadą wierszy — jawnie, w kodzie
 * domenowym, który da się przeczytać.
 *
 * Symetryczny wyzwalacz na `blocks`, który sam z siebie KASOWAŁBY wiersze
 * `follows`, byłby jeszcze gorszy: ukryta mutacja za plecami wywołującego.
 * Wyzwalacz, który ODMAWIA, mówi prawdę; wyzwalacz, który cicho zmienia dane
 * w innej tabeli, zamienia każdą przyszłą sesję debugowania w zgadywanie.
 *
 * ── CO TA BARIERA DAJE, A CZEGO NIE DAJE ──
 *
 * DAJE: żadna droga zapisu poza `FollowUser` — druga akcja dopisana za pół
 * roku, komenda konsolowa, seeder, ręczny `UPDATE` w psql podczas awarii —
 * nie założy obserwowania przy istniejącej, ZATWIERDZONEJ blokadzie. To jest
 * dokładnie ta klasa błędu, przed którą `AGENTS.md` §6 ostrzega zdaniem
 * „walidacja w PHP jest dodatkiem, nie zamiennikiem".
 *
 * NIE DAJE: odporności na prawdziwą równoległość. Przy `READ COMMITTED`
 * wyzwalacz nie widzi blokady, która nie jest jeszcze zatwierdzona, więc dwa
 * równoległe żądania nadal mogłyby przejść oba. Za to odpowiada
 * `App\Domain\Social\ZamekPary` i rewalidacja pod blokadą wierszy. Bariera
 * i blokada nie zastępują się wzajemnie: bariera pilnuje DRÓG ZAPISU,
 * blokada pilnuje RÓWNOLEGŁOŚCI. Trzeba obu.
 *
 * ── KOSZT ──
 *
 * Jedno trafienie w indeks przy każdym `INSERT` do `follows`, i tylko przy
 * `INSERT` (nie ma `UPDATE` na tej tabeli — klucz główny JEST relacją).
 * `blocks` ma klucz główny `(blocker_id, blocked_id)` i indeks
 * `blocks_blocked_idx`, więc oba kierunki idą po indeksie. `follows` rośnie
 * o wiersz wtedy, gdy człowiek kliknie „Obserwuj" — to nie jest ścieżka
 * gorąca. `FollowUser` i tak wykonuje to samo pytanie, więc realny narzut
 * to jedno dodatkowe, indeksowane `EXISTS` na kliknięcie.
 *
 * ── ROLLBACK ──
 *
 * `down()` usuwa wyzwalacz i funkcję. Jest bezpieczny i bezstratny: nic nie
 * zmienia w danych, więc nie da się przez niego niczego utracić. Po rollbacku
 * wraca stan sprzed tej migracji, w którym gwarancję trzyma sam `ZamekPary`
 * — czyli mniej, ale nie nic. Wyzwalacz nie jest wymagany do działania
 * aplikacji i nie ma zależnego od niego kodu; można go zdjąć na produkcji
 * pod ruchem, gdyby okazał się źródłem problemu.
 *
 * Migracja NIE czyści danych istniejących. Gdyby na produkcji leżał już
 * wiersz-sierota (para z blokadą i obserwowaniem naraz, powstały właśnie
 * przez ten wyścig), wyzwalacz go NIE ruszy — pilnuje tylko nowych zapisów.
 * Sprzątanie takich wierszy to osobna, jawna decyzja i osobne zadanie:
 * kasowanie relacji społecznych migracją, bez możliwości wglądu w to, co
 * zostało skasowane, jest dokładnie tą destrukcyjną operacją, której
 * `AGENTS.md` §6 zabrania. Zapytanie diagnostyczne jest w `docs/DATABASE.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION follows_blokada_ma_pierwszenstwo()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM blocks
                    WHERE (blocker_id = NEW.follower_id AND blocked_id = NEW.followed_id)
                       OR (blocker_id = NEW.followed_id AND blocked_id = NEW.follower_id)
                ) THEN
                    RAISE EXCEPTION 'Blokada ma pierwszenstwo przed obserwowaniem (follows: % -> %)',
                        NEW.follower_id, NEW.followed_id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER follows_blokada_ma_pierwszenstwo_trg
            BEFORE INSERT ON follows
            FOR EACH ROW
            EXECUTE FUNCTION follows_blokada_ma_pierwszenstwo();
        SQL);
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS follows_blokada_ma_pierwszenstwo_trg ON follows');
        DB::unprepared('DROP FUNCTION IF EXISTS follows_blokada_ma_pierwszenstwo()');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

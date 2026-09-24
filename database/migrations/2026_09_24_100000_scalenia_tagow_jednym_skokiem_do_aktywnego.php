<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GRAF SCALEŃ TAGÓW MA JEDEN SKOK DO AKTYWNEGO CELU — W BAZIE (issue #996).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO
 * ────────────────────────────────────────────────────────────────────────
 *
 * `Tag::tagKanoniczny()` świadomie robi dokładnie JEDEN skok po
 * `merged_into_tag_id`. Działa to tylko wtedy, gdy cel scalenia jest
 * aktywny — łańcuch `A → B → C` zwróciłby scalone B, a pętla `A → A` albo
 * cykl `A → B → A` nie mają kanonicznego końca w ogóle. `MergeTags` pilnuje
 * tego w PHP, ale import, seeder, konsola czy druga, przyszła droga zapisu
 * mogłyby to ominąć (AGENTS.md §6: „walidacja w PHP jest dodatkiem, nie
 * zamiennikiem”). Do tej pory baza sprawdzała tylko, czy tag scalony MA cel
 * (`tags_merged_consistency_check`), a nie JAKI to cel.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  REGUŁA — JEDNA KRAWĘDŹ, DWIE STRONY
 * ────────────────────────────────────────────────────────────────────────
 *
 *   każda krawędź `X.merged_into_tag_id = Y` ma  X ≠ Y  i  Y.status = 'active'.
 *
 * To wystarcza na łańcuchy i cykle bez rekurencji: aktywny tag nie ma celu
 * scalenia (`tags_merged_consistency_check`), więc skoro każda krawędź
 * kończy się w tagu aktywnym, żadna krawędź nie może z niego wychodzić
 * dalej. Łańcuch ma zawsze długość 1, a cykl — który wymaga krawędzi
 * WYCHODZĄCEJ z celu — nie ma jak powstać.
 *
 * Krawędź można zepsuć z dwóch stron, więc wyzwalacz pilnuje obu:
 *
 *  1. zapis ŹRÓDŁA (`merged_into_tag_id` wskazuje Y) — Y musi istnieć jako
 *     tag aktywny; ukryty albo scalony cel jest odrzucany;
 *  2. zapis CELU (Y przestaje być aktywny: ukrycie albo scalenie) — nie
 *     może na niego wskazywać żaden inny tag. Tak powstałby łańcuch
 *     „od tyłu” (A → B, potem B → C). `MergeTags` przepina dawne źródła
 *     na nowy cel PRZED oznaczeniem źródła jako scalonego, więc ta ścieżka
 *     przechodzi bez zmian.
 *
 * Pętlę `X → X` łapie zwykły `CHECK` (`tags_merged_not_self_check`) —
 * wiersz porównany sam ze sobą to dokładnie to, do czego CHECK służy.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO WYZWALACZ, A NIE CHECK
 * ────────────────────────────────────────────────────────────────────────
 *
 * Reguła patrzy na INNY wiersz. `CHECK` w PostgreSQL może patrzeć tylko na
 * własny; `CHECK` na funkcji czytającej tabelę nie jest wymuszany przy
 * zmianie tamtego wiersza i potrafi paść przy `pg_restore`. Dokumentacja
 * PostgreSQL 18 (ddl-constraints) zaleca w takim przypadku wyzwalacz — ten
 * sam wybór co `follows_blokada_ma_pierwszenstwo_trg` (D-080).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  RÓWNOLEGŁOŚĆ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Przy `READ COMMITTED` sam odczyt nie widzi niezatwierdzonej zmiany drugiej
 * transakcji: T1 zapisuje `A → B`, T2 równocześnie scala `B → C`, obie
 * „widzą” stan poprawny i obie by przeszły. Dlatego strona 1 czyta cel
 * `FOR SHARE`. Ten zamek koliduje z blokadą wiersza, którą bierze każdy
 * `UPDATE` celu (PostgreSQL zakłada ją, zanim wywoła wyzwalacz `BEFORE`),
 * więc obie kolejności się serializują:
 *
 *  - T1 pierwsza: T2 czeka na zamek wiersza B do końca T1, a potem jej
 *    wyzwalacz (nowy snapshot na każde zapytanie w plpgsql) widzi już
 *    `A → B` i odmawia;
 *  - T2 pierwsza: `FOR SHARE` w T1 czeka do końca T2, dostaje NOWĄ wersję
 *    wiersza B (już scalonego) i odmawia.
 *
 * Najgorszy przypadek dwóch krzyżowych zapisów (`A → B` i `B → A` naraz)
 * kończy się wykrytym zakleszczeniem — jedna transakcja przegrywa, żadna
 * nie zapisuje cyklu. `MergeTags` i tak bierze wyłączność
 * `TagMutationLock::forMerge()`, więc na zwykłej ścieżce do tego nie dojdzie.
 *
 * Wyzwalacz działa tylko `UPDATE OF status, merged_into_tag_id` (oraz przy
 * `INSERT`), więc zmiana nazwy, kategorii czy `updated_at` go nie budzi.
 * Strona 2 pyta o odwrotną krawędź tylko przy ZMIANIE statusu na
 * nieaktywny — to rzadka operacja moderatora, a `tags` ma ~1,4 tys. wierszy.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ISTNIEJĄCE DANE: ODMOWA, NIE NAPRAWA
 * ────────────────────────────────────────────────────────────────────────
 *
 * Przed założeniem bariery migracja blokuje zapisy do `tags` (tabela jest
 * mała, blokada trwa ułamek sekundy) i liczy krawędzie łamiące regułę.
 * Jeśli jakaś jest — PRZERYWA się z liczbami i niczego nie zmienia.
 * Naprawa („dokąd naprawdę ma prowadzić ten stary adres”) to decyzja
 * redakcyjna, nie wartość do zgadnięcia w migracji. Skrypt diagnostyczny
 * (tylko odczyt, rekurencyjne CTE pokazujące łańcuchy i cykle):
 * `docs/diagnostyka/996_graf_scalen_tagow.sql`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK
 * ────────────────────────────────────────────────────────────────────────
 *
 * `down()` zdejmuje wyzwalacz, funkcję i CHECK. Bezstratnie: poluzowanie
 * reguły nie dotyka żadnego wiersza, więc — inaczej niż przy D-088 — nie
 * ma czego odmawiać. Po rollbacku gwarancję trzyma już tylko `MergeTags`.
 */
return new class extends Migration
{
    private const CHECK_BEZ_PETLI = 'tags_merged_not_self_check';

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        // Do końca transakcji migracji nikt nie dopisze krawędzi między
        // kontrolą a założeniem bariery. Odczyty tagów działają dalej.
        DB::statement('LOCK TABLE tags IN SHARE ROW EXCLUSIVE MODE');

        $this->odmowJesliGrafNiespojny();

        DB::statement(
            'ALTER TABLE tags ADD CONSTRAINT '.self::CHECK_BEZ_PETLI.' '
            .'CHECK (merged_into_tag_id IS NULL OR merged_into_tag_id <> id)',
        );

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tags_scalenie_jednym_skokiem()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                cel_status text;
            BEGIN
                -- Strona 1: cel scalenia musi być aktywny. FOR SHARE —
                -- patrz „RÓWNOLEGŁOŚĆ” w migracji #996. Brak wiersza zgłosi
                -- klucz obcy, a wskazanie samego siebie — CHECK.
                IF NEW.merged_into_tag_id IS NOT NULL AND NEW.merged_into_tag_id <> NEW.id THEN
                    SELECT status INTO cel_status
                    FROM tags
                    WHERE id = NEW.merged_into_tag_id
                    FOR SHARE;

                    IF FOUND AND cel_status <> 'active' THEN
                        RAISE EXCEPTION 'Tag % mozna scalic tylko w aktywny tag; % ma status %',
                            NEW.id, NEW.merged_into_tag_id, cel_status
                            USING ERRCODE = 'integrity_constraint_violation';
                    END IF;
                END IF;

                -- Strona 2: tag, który przestaje być aktywny, nie może być
                -- celem innych scaleń — inaczej powstaje łańcuch albo cykl.
                IF NEW.status <> 'active'
                   AND (TG_OP = 'INSERT' OR OLD.status = 'active')
                   AND EXISTS (
                       SELECT 1 FROM tags
                       WHERE merged_into_tag_id = NEW.id AND id <> NEW.id
                   ) THEN
                    RAISE EXCEPTION 'Tag % jest celem innych scalen; najpierw przepnij je na nowy cel',
                        NEW.id
                        USING ERRCODE = 'integrity_constraint_violation';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER tags_scalenie_jednym_skokiem_trg
            BEFORE INSERT OR UPDATE OF status, merged_into_tag_id ON tags
            FOR EACH ROW
            EXECUTE FUNCTION tags_scalenie_jednym_skokiem();
        SQL);
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS tags_scalenie_jednym_skokiem_trg ON tags');
        DB::unprepared('DROP FUNCTION IF EXISTS tags_scalenie_jednym_skokiem()');
        DB::statement('ALTER TABLE tags DROP CONSTRAINT IF EXISTS '.self::CHECK_BEZ_PETLI);
    }

    private function odmowJesliGrafNiespojny(): void
    {
        $wynik = DB::selectOne(<<<'SQL'
            SELECT
                count(*) FILTER (WHERE zrodlo.id = cel.id) AS petle,
                count(*) FILTER (WHERE zrodlo.id <> cel.id AND cel.status = 'merged') AS cel_scalony,
                count(*) FILTER (WHERE zrodlo.id <> cel.id AND cel.status = 'hidden') AS cel_ukryty
            FROM tags AS zrodlo
            JOIN tags AS cel ON cel.id = zrodlo.merged_into_tag_id
            SQL);

        $petle = (int) $wynik->petle;
        $celScalony = (int) $wynik->cel_scalony;
        $celUkryty = (int) $wynik->cel_ukryty;

        if ($petle + $celScalony + $celUkryty === 0) {
            return;
        }

        throw new RuntimeException(
            "W `tags` są scalenia, które nie prowadzą jednym skokiem do aktywnego tagu. Migracja niczego nie zmieniła.\n"
            .'Tag scalony sam w siebie: '.$petle.".\n"
            .'Cel scalenia sam jest scalony (łańcuch albo cykl): '.$celScalony.".\n"
            .'Cel scalenia jest ukryty: '.$celUkryty.".\n"
            .'Uruchom docs/diagnostyka/996_graf_scalen_tagow.sql (tylko odczyt), zdecyduj, dokąd ma prowadzić '
            .'każdy z tych tagów, popraw dane i uruchom migrację ponownie.',
        );
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

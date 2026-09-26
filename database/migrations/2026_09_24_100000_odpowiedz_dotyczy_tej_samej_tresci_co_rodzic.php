<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ODPOWIEDŹ DOTYCZY TEJ SAMEJ TREŚCI CO JEJ RODZIC I WISI POD KOMENTARZEM
 * GŁÓWNYM (issue #954).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO
 * ────────────────────────────────────────────────────────────────────────
 *
 * `comments_single_target_check` pilnuje, że wiersz wskazuje dokładnie jeden
 * wpis, przepis albo wykonanie, a FK `parent_id` — że rodzic istnieje. Nic
 * nie pilnowało dwóch reguł, na których stoi rozmowa:
 *
 *   1. odpowiedź i rodzic dotyczą TEJ SAMEJ treści (te same trzy kolumny
 *      celu, porównane przez IS NOT DISTINCT FROM);
 *   2. rodzic jest komentarzem głównym (`parent.parent_id IS NULL`) —
 *      drzewo ma jeden poziom.
 *
 * `PublishComment` egzekwuje to w PHP, ale seeder, importer, ręczny UPDATE
 * albo nowa akcja mogły zapisać odpowiedź widoczną pod inną treścią niż
 * mówią jej kolumny albo drugi poziom, którego żaden widok nie pokazuje.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  DLACZEGO WYZWALACZ
 * ────────────────────────────────────────────────────────────────────────
 *
 * Reguła porównuje dwa wiersze. CHECK widzi tylko własny wiersz, a CHECK na
 * funkcji czytającej inne wiersze nie jest ponownie wymuszany i potrafi
 * zepsuć dump/restore (dokumentacja PostgreSQL 18, „Check Constraints").
 * FK złożony (parent_id, post_id, …) nie zadziała, bo trzy kolumny celu są
 * NULL-owalne, a FK z NULL-em w którejkolwiek kolumnie nie jest sprawdzany.
 *
 * Wyzwalacz pilnuje OBU STRON relacji:
 *
 *   • odpowiedź (NEW.parent_id IS NOT NULL): rodzic musi być komentarzem
 *     głównym o tym samym celu; komentarz nie może być rodzicem samego
 *     siebie; komentarz, który ma odpowiedzi, nie może stać się odpowiedzią;
 *   • komentarz główny, któremu UPDATE zmienia cel: nie może odjechać od
 *     swoich odpowiedzi.
 *
 * Bez drugiej strony wystarczyłby `UPDATE comments SET post_id = …` na
 * rodzicu, żeby rozjechać cały wątek bez dotykania żadnej odpowiedzi.
 *
 * ── RÓWNOLEGŁOŚĆ ──
 *
 * Rodzica czytamy `FOR SHARE`. To koliduje z blokadą wiersza, którą bierze
 * każdy UPDATE rodzica, więc „wstaw odpowiedź" i „zmień cel rodzica" nie
 * przejdą obok siebie: drugie czeka na pierwsze i przy READ COMMITTED
 * widzi jego zatwierdzony wynik. Sam FK bierze tylko FOR KEY SHARE, które
 * zmianie kolumn niekluczowych nie przeszkadza.
 *
 * Rodzic, którego nie ma, NIE jest tu błędem — to rozstrzyga FK
 * `comments_parent_id_foreign` swoim własnym komunikatem.
 *
 * Wyzwalacz nie reaguje na DELETE: kaskada FK nadal usuwa odpowiedzi razem
 * z rodzicem.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ISTNIEJĄCE DANE: ODMOWA, NIE ZGADYWANIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Przed założeniem wyzwalacza liczymy wiersze łamiące regułę (także
 * miękko skasowane — wyzwalacz ich nie wyróżnia). Jeśli są, migracja
 * PRZERYWA się z liczbami. Nie przepinamy rozmów sami: pod którą treścią
 * naprawdę padła odpowiedź, to fakt do ustalenia przez człowieka.
 * Skrypt tylko-do-odczytu dla właściciela:
 * `docs/diagnostyka/954_odpowiedzi_niezgodne_z_rodzicem.sql`.
 *
 * Kontrola i CREATE TRIGGER idą w jednej transakcji pod
 * `SHARE ROW EXCLUSIVE` na `comments` — między policzeniem a założeniem
 * wyzwalacza nikt nie dopisze niespójnego wiersza. Blokada wstrzymuje
 * zapisy komentarzy na czas jednego przejścia po tabeli.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK
 * ────────────────────────────────────────────────────────────────────────
 *
 * `down()` zdejmuje wyzwalacz i funkcję. Bezstratnie: poluzowanie reguły
 * nie dotyka żadnego wiersza, więc — inaczej niż przy D-088 — nie ma czego
 * odmawiać. Po rollbacku regułę trzyma już tylko `PublishComment`.
 */
return new class extends Migration
{
    private const FUNKCJA = 'comments_odpowiedz_zgodna_z_rodzicem';

    private const WYZWALACZ = 'comments_odpowiedz_zgodna_z_rodzicem_trg';

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('LOCK TABLE comments IN SHARE ROW EXCLUSIVE MODE');

        $this->odmowJesliDaneNiespojne();

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION comments_odpowiedz_zgodna_z_rodzicem()
            RETURNS TRIGGER
            LANGUAGE plpgsql
            AS $$
            DECLARE
                rodzic comments%ROWTYPE;
            BEGIN
                IF NEW.parent_id IS NOT NULL THEN
                    IF NEW.parent_id = NEW.id THEN
                        RAISE EXCEPTION 'Komentarz % nie moze byc odpowiedzia na samego siebie', NEW.id
                            USING ERRCODE = 'integrity_constraint_violation',
                                  CONSTRAINT = 'comments_odpowiedz_zgodna_z_rodzicem';
                    END IF;

                    SELECT * INTO rodzic FROM comments WHERE id = NEW.parent_id FOR SHARE;

                    IF FOUND THEN
                        IF rodzic.parent_id IS NOT NULL THEN
                            RAISE EXCEPTION 'Odpowiedz % wskazuje komentarz %, ktory sam jest odpowiedzia; rodzic musi byc komentarzem glownym',
                                NEW.id, NEW.parent_id
                                USING ERRCODE = 'integrity_constraint_violation',
                                      CONSTRAINT = 'comments_odpowiedz_zgodna_z_rodzicem';
                        END IF;

                        IF rodzic.post_id IS DISTINCT FROM NEW.post_id
                           OR rodzic.recipe_id IS DISTINCT FROM NEW.recipe_id
                           OR rodzic.cooked_event_id IS DISTINCT FROM NEW.cooked_event_id THEN
                            RAISE EXCEPTION 'Odpowiedz % dotyczy innej tresci niz jej rodzic %', NEW.id, NEW.parent_id
                                USING ERRCODE = 'integrity_constraint_violation',
                                      CONSTRAINT = 'comments_odpowiedz_zgodna_z_rodzicem';
                        END IF;
                    END IF;

                    IF TG_OP = 'UPDATE' AND EXISTS (SELECT 1 FROM comments WHERE parent_id = NEW.id) THEN
                        RAISE EXCEPTION 'Komentarz % ma odpowiedzi, wiec nie moze stac sie odpowiedzia', NEW.id
                            USING ERRCODE = 'integrity_constraint_violation',
                                  CONSTRAINT = 'comments_odpowiedz_zgodna_z_rodzicem';
                    END IF;
                ELSIF TG_OP = 'UPDATE'
                      AND (OLD.post_id IS DISTINCT FROM NEW.post_id
                           OR OLD.recipe_id IS DISTINCT FROM NEW.recipe_id
                           OR OLD.cooked_event_id IS DISTINCT FROM NEW.cooked_event_id)
                      AND EXISTS (
                          SELECT 1 FROM comments odp
                          WHERE odp.parent_id = NEW.id
                            AND (odp.post_id IS DISTINCT FROM NEW.post_id
                                 OR odp.recipe_id IS DISTINCT FROM NEW.recipe_id
                                 OR odp.cooked_event_id IS DISTINCT FROM NEW.cooked_event_id)
                      ) THEN
                    RAISE EXCEPTION 'Komentarz % ma odpowiedzi pod obecna trescia, wiec nie moze zmienic celu', NEW.id
                        USING ERRCODE = 'integrity_constraint_violation',
                              CONSTRAINT = 'comments_odpowiedz_zgodna_z_rodzicem';
                END IF;

                RETURN NEW;
            END;
            $$;
        SQL);

        DB::unprepared('DROP TRIGGER IF EXISTS '.self::WYZWALACZ.' ON comments');
        DB::unprepared(
            'CREATE TRIGGER '.self::WYZWALACZ
            .' BEFORE INSERT OR UPDATE OF parent_id, post_id, recipe_id, cooked_event_id ON comments'
            .' FOR EACH ROW EXECUTE FUNCTION '.self::FUNKCJA.'()',
        );
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS '.self::WYZWALACZ.' ON comments');
        DB::unprepared('DROP FUNCTION IF EXISTS '.self::FUNKCJA.'()');
    }

    private function odmowJesliDaneNiespojne(): void
    {
        $wynik = DB::selectOne(<<<'SQL'
            SELECT
                count(*) FILTER (WHERE odp.parent_id = odp.id) AS sama_sobie,
                count(*) FILTER (WHERE odp.parent_id <> odp.id AND rodzic.parent_id IS NOT NULL) AS drugi_poziom,
                count(*) FILTER (WHERE odp.parent_id <> odp.id AND (
                    rodzic.post_id IS DISTINCT FROM odp.post_id
                    OR rodzic.recipe_id IS DISTINCT FROM odp.recipe_id
                    OR rodzic.cooked_event_id IS DISTINCT FROM odp.cooked_event_id
                )) AS inna_tresc
            FROM comments odp
            JOIN comments rodzic ON rodzic.id = odp.parent_id
            SQL);

        $samaSobie = (int) $wynik->sama_sobie;
        $drugiPoziom = (int) $wynik->drugi_poziom;
        $innaTresc = (int) $wynik->inna_tresc;

        if ($samaSobie + $drugiPoziom + $innaTresc === 0) {
            return;
        }

        throw new RuntimeException(
            "W `comments` są odpowiedzi niezgodne z rodzicem. Migracja niczego nie zmieniła.\n"
            .'Odpowiedzi na samą siebie: '.$samaSobie.".\n"
            .'Odpowiedzi pod komentarzem, który sam jest odpowiedzią: '.$drugiPoziom.".\n"
            .'Odpowiedzi dotyczące innej treści niż rodzic: '.$innaTresc.".\n\n"
            ."CO ZROBIĆ\n"
            .'Uruchom tylko-do-odczytu docs/diagnostyka/954_odpowiedzi_niezgodne_z_rodzicem.sql. '
            .'Dla każdego wiersza człowiek ustala, pod którą treścią i w którym wątku padła odpowiedź '
            .'(treść, daty, powiadomienia) — nie przepinaj rozmów na ślepo. '
            .'Potem uruchom migrację ponownie.',
        );
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

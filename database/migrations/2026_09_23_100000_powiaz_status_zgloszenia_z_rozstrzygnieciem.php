<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STATUS ZGŁOSZENIA JEST ZWIĄZANY Z DATĄ ROZSTRZYGNIĘCIA (issue #997).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO
 * ────────────────────────────────────────────────────────────────────────
 *
 * `reports_status_check` pilnował słownika statusów, ale nie tego, czy
 * sprawa zamknięta ma datę zamknięcia. Retencja
 * (`PrzedawnioneSprawyModeracyjne`) bierze `status IN ('resolved','rejected')
 * AND resolved_at < próg` — zamknięta sprawa bez daty nie zostałaby
 * skasowana NIGDY, a otwarta z datą wisiałaby w kolejce z fałszywym śladem
 * rozstrzygnięcia. To dane zgłaszającego trzymane poza obiecanym cyklem
 * życia. `appeals` (`appeals_decision_complete_check`) i `contact_messages`
 * (`contact_messages_handled_complete`) miały ten niezmiennik od początku;
 * `reports` był brakującym trzecim przypadkiem.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  REGUŁA
 * ────────────────────────────────────────────────────────────────────────
 *
 *   stan otwarty  (open, triage, reviewing)
 *       ⇔ resolved_at, resolved_by i resolution_note są puste;
 *   stan końcowy  (resolved, rejected)
 *       ⇔ resolved_at NIE jest puste.
 *
 * `resolved_by` w stanie końcowym NIE jest wymagane: klucz ma świadome
 * `nullOnDelete()` — fizyczne usunięcie konta operatora nie może
 * unieważnić historycznej sprawy ani zablokować kasowania konta. Kto
 * rozstrzygnął, zapisuje też niemutowalny `moderation_actions.moderator_id`.
 *
 * `resolution_note` w stanie końcowym NIE jest wymagane: to wewnętrzna
 * notatka, a formularz decyzji i odrzucenie oznaczeń automatu świadomie
 * pozwalają ją pominąć (uzasadnienie dla człowieka żyje w
 * `moderation_actions.user_message`). W stanie otwartym notatka nie ma
 * skąd się wziąć — żadna ścieżka jej wtedy nie zapisuje.
 *
 * Lista statusów jest wypisana wprost w obu gałęziach, więc nowy status
 * dopisany do `reports_status_check` bez przemyślenia tego CHECK-a odbije
 * się o bazę — to celowe.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ISTNIEJĄCE DANE: ODMOWA, NIE ZGADYWANIE
 * ────────────────────────────────────────────────────────────────────────
 *
 * Przed założeniem CHECK-a liczymy wiersze łamiące regułę. Jeśli są —
 * migracja PRZERYWA się z liczbami i instrukcją. Nie naprawiamy sami:
 * „kiedy zamknięto sprawę" i „czy ta otwarta sprawa naprawdę jest otwarta"
 * to fakty, a nie wartości do wymyślenia (`created_at` jako data zamknięcia
 * przyspieszyłoby retencję i skasowało sprawę przed czasem). Zapytanie
 * kontrolne dla właściciela: `docs/DATABASE.md`, sekcja `reports`.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  NOT VALID + VALIDATE, POZA TRANSAKCJĄ
 * ────────────────────────────────────────────────────────────────────────
 *
 * `ADD CONSTRAINT … NOT VALID` bierze krótką blokadę i nie czyta tabeli;
 * `VALIDATE CONSTRAINT` czyta ją pod `SHARE UPDATE EXCLUSIVE`, który NIE
 * blokuje zapisów. Działa to tylko wtedy, gdy oba kroki są osobnymi
 * transakcjami — w jednej blokada z pierwszego kroku trwałaby do końca
 * drugiego. Stąd `$withinTransaction = false`.
 *
 * Jeśli między kontrolą danych a `VALIDATE` ktoś zapisze niespójny wiersz
 * (stary kod w trakcie wdrożenia), `VALIDATE` padnie — wtedy zdejmujemy
 * niezwalidowany CHECK, żeby ponowne `migrate` zaczęło od czystego stanu.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  ROLLBACK
 * ────────────────────────────────────────────────────────────────────────
 *
 * `down()` zdejmuje CHECK. Bezstratnie: poluzowanie reguły nie dotyka
 * żadnego wiersza, więc tu — inaczej niż przy D-088 — nie ma czego
 * odmawiać.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const OGRANICZENIE = 'reports_resolution_complete_check';

    private const WARUNEK = "(status IN ('open','triage','reviewing') "
        .'AND resolved_at IS NULL AND resolved_by IS NULL AND resolution_note IS NULL) '
        ."OR (status IN ('resolved','rejected') AND resolved_at IS NOT NULL)";

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $this->odmowJesliDaneNiespojne();

        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);
        DB::statement('ALTER TABLE reports ADD CONSTRAINT '.self::OGRANICZENIE.' CHECK ('.self::WARUNEK.') NOT VALID');

        try {
            DB::statement('ALTER TABLE reports VALIDATE CONSTRAINT '.self::OGRANICZENIE);
        } catch (Throwable $e) {
            DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);

            throw new RuntimeException(
                'Nie udało się zwalidować '.self::OGRANICZENIE.': w trakcie migracji pojawiły się '
                .'zgłoszenia ze statusem niezgodnym z datą rozstrzygnięcia. CHECK został zdjęty. '
                .'Sprawdź dane zapytaniem kontrolnym z docs/DATABASE.md (sekcja reports) '
                .'i uruchom migrację ponownie.',
                previous: $e,
            );
        }
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement('ALTER TABLE reports DROP CONSTRAINT IF EXISTS '.self::OGRANICZENIE);
    }

    private function odmowJesliDaneNiespojne(): void
    {
        $wynik = DB::selectOne(<<<'SQL'
            SELECT
                count(*) FILTER (WHERE status IN ('resolved','rejected') AND resolved_at IS NULL) AS zamkniete_bez_daty,
                count(*) FILTER (WHERE status IN ('open','triage','reviewing') AND resolved_at IS NOT NULL) AS otwarte_z_data,
                count(*) FILTER (WHERE status IN ('open','triage','reviewing') AND resolved_by IS NOT NULL) AS otwarte_z_moderatorem,
                count(*) FILTER (WHERE status IN ('open','triage','reviewing') AND resolution_note IS NOT NULL) AS otwarte_z_notatka
            FROM reports
            SQL);

        $zamknieteBezDaty = (int) $wynik->zamkniete_bez_daty;
        $otwarteZData = (int) $wynik->otwarte_z_data;
        $otwarteZModeratorem = (int) $wynik->otwarte_z_moderatorem;
        $otwarteZNotatka = (int) $wynik->otwarte_z_notatka;

        if ($zamknieteBezDaty + $otwarteZData + $otwarteZModeratorem + $otwarteZNotatka === 0) {
            return;
        }

        throw new RuntimeException(
            "W `reports` są zgłoszenia, których status nie zgadza się z rozstrzygnięciem. Migracja niczego nie zmieniła.\n"
            .'Zamknięte (resolved/rejected) bez resolved_at: '.$zamknieteBezDaty.".\n"
            .'Otwarte (open/triage/reviewing) z resolved_at: '.$otwarteZData.".\n"
            .'Otwarte z resolved_by: '.$otwarteZModeratorem.".\n"
            .'Otwarte z resolution_note: '.$otwarteZNotatka.".\n\n"
            ."CO ZROBIĆ\n"
            .'Znajdź wiersze zapytaniem kontrolnym z docs/DATABASE.md (sekcja reports). '
            .'Datę zamknięcia weź z moderation_actions.created_at tej sprawy (report_id), '
            .'a nie z created_at zgłoszenia — zła data przesunie retencję. '
            .'Otwartą sprawę z datą rozstrzygnięcia rozstrzyga człowiek: albo była zamknięta '
            .'(popraw status), albo jest otwarta (wyczyść resolved_at, resolved_by, resolution_note). '
            .'Potem uruchom migrację ponownie.',
        );
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

<?php

declare(strict_types=1);

namespace App\Domain\Feed\Actions;

use App\Domain\Feed\ZamekWyboruRedakcji;
use App\Models\AuditLogEntry;
use App\Models\DailyPick;
use App\Models\User;
use App\Support\Czas;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Zastąpienie i wyczyszczenie wyboru redakcyjnego tablicy „kuKINGi na dziś".
 *
 * Kontroler autoryzuje, waliduje i wybiera odpowiedź; tu mieszka reguła
 * zapisu, niezależna od HTTP. Dwie gwarancje:
 *
 *  1. JEDEN PEŁNY WYBÓR NARAZ (#1027). Całe zastąpienie idzie pod blokadą
 *     doradczą danego dnia (`ZamekWyboruRedakcji::tablicy()`), więc dwa
 *     równoległe zapisy różnych zestawów kończą się zestawem A albo B —
 *     nigdy sumą obu.
 *  2. WYBÓR I WPIS AUDYTU RAZEM ALBO WCALE (#1329, D-249 klasa 1). Wybór
 *     gospodarza zmienia publiczną stronę, a `daily_picks` są przy tym
 *     twardo kasowane — bez wpisu `audit_log` nie zostaje ślad, kto i kiedy
 *     to zrobił. Awaria dziennika cofa więc cały zapis.
 */
final class ZapiszTabliceDnia
{
    /**
     * @param  list<string>  $osoby  identyfikatory osób, bez powtórzeń, w kolejności z formularza
     * @param  list<string>  $wpisy  identyfikatory wpisów, bez powtórzeń, w kolejności z formularza
     * @param  array<string, ?string>  $notatki  notatka po identyfikatorze pozycji
     * @return array<string, int> liczba zapisanych pozycji po `subject_type`
     */
    public function zastap(
        User $gospodarz,
        array $osoby,
        array $wpisy,
        array $notatki,
        int $przeslaneOsoby,
        int $przeslaneWpisy,
        ?string $ip,
        ?string $dzien = null,
    ): array {
        $dzien ??= Czas::dzisiajData();

        return DB::transaction(function () use ($gospodarz, $osoby, $wpisy, $notatki, $przeslaneOsoby, $przeslaneWpisy, $ip, $dzien): array {
            // Blokada PRZED `DELETE`: drugi zapis tego samego dnia czeka tu
            // na zatwierdzenie pierwszego i dopiero wtedy kasuje JEGO wybór.
            ZamekWyboruRedakcji::tablicy($dzien);

            // Wybór na dany dzień zastępujemy w całości — to jest prostsze
            // w obsłudze niż dokładanie i odejmowanie pozycji.
            DailyPick::query()->whereDate('shown_on', $dzien)->delete();

            foreach ([DailyPick::TYPE_USER => $osoby, DailyPick::TYPE_POST => $wpisy] as $typ => $identyfikatory) {
                foreach (array_values($identyfikatory) as $pozycja => $subjectId) {
                    $this->utworzPozycje($dzien, $typ, $subjectId, $pozycja, $gospodarz, $this->nullIfBlank($notatki[$subjectId] ?? null));
                }
            }

            $zapisane = DailyPick::query()->forDate(new DateTimeImmutable($dzien))->get()->countBy('subject_type')->all();

            AuditLogEntry::record(
                action: 'daily_board.updated',
                actor: $gospodarz,
                metadata: [
                    'osoby' => $zapisane[DailyPick::TYPE_USER] ?? 0,
                    'przeslane_osoby' => $przeslaneOsoby,
                    'wpisy' => $zapisane[DailyPick::TYPE_POST] ?? 0,
                    'przeslane_wpisy' => $przeslaneWpisy,
                ],
                ip: $ip,
            );

            return $zapisane;
        });
    }

    /**
     * Usuwa wybór dnia — tablica wraca do trybu automatycznego.
     *
     * Wpis `daily_board.cleared` powstaje także wtedy, gdy nie było czego
     * usuwać: gospodarz kliknął „Wyczyść" i to jest jego decyzja, a licznik
     * `usunietych` mówi, czy zmieniła stronę.
     *
     * @return int ile pozycji usunięto
     */
    public function wyczysc(User $gospodarz, ?string $ip, ?string $dzien = null): int
    {
        $dzien ??= Czas::dzisiajData();

        return DB::transaction(function () use ($gospodarz, $ip, $dzien): int {
            ZamekWyboruRedakcji::tablicy($dzien);

            $usunietych = DailyPick::query()->whereDate('shown_on', $dzien)->delete();

            AuditLogEntry::record(
                action: 'daily_board.cleared',
                actor: $gospodarz,
                metadata: ['usunietych' => $usunietych],
                ip: $ip,
            );

            return $usunietych;
        });
    }

    /**
     * Jedna pozycja tablicy — z przechwyceniem zderzenia z UNIQUE.
     *
     * Po blokadzie dnia dwa zapisy tego dnia już się nie przeplatają, więc
     * zderzenie z `unique(['shown_on', 'subject_type', 'subject_id'])` jest
     * tu tylko siatką bezpieczeństwa: dla człowieka, który kliknął „Zapisz"
     * dwa razy z tym samym wyborem, wynik ma być jedną pozycją, nie błędem
     * 500 (`tests/Feature/Wyscigi/DailyBoardRaceTest.php`).
     *
     * WŁASNA ZAGNIEŻDŻONA TRANSAKCJA, NIE SAM `try/catch`: w PostgreSQL
     * zderzenie z UNIQUE unieważnia całą otaczającą transakcję. Laravel
     * zamienia zagnieżdżone `DB::transaction()` na `SAVEPOINT`, więc wycofuje
     * się tylko ten jeden `INSERT`.
     */
    private function utworzPozycje(string $dzien, string $typ, string $subjectId, int $pozycja, User $gospodarz, ?string $notatka): void
    {
        try {
            DB::transaction(function () use ($dzien, $typ, $subjectId, $pozycja, $gospodarz, $notatka): void {
                DailyPick::create([
                    'shown_on' => $dzien,
                    'subject_type' => $typ,
                    'subject_id' => $subjectId,
                    'position' => $pozycja,
                    'curator_id' => $gospodarz->getKey(),
                    'note' => $notatka,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // Ta sama pozycja na ten dzień już stoi — nic do zrobienia.
        }
    }

    private function nullIfBlank(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Logging\BezpiecznyBlad;
use App\Models\Notification;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retencja `notifications` (issue #19, docs/decyzje/ADR_RETENCJE.md §5.2, §5.6).
 *
 * Domyślnie `config('kuking.notifications.retention_months')` miesięcy od
 * `created_at`, NIEZALEŻNIE od `read_at` — jeden wiek dla wszystkich
 * powiadomień, poza jednym wyjątkiem (wariant A z ADR §6).
 *
 * WYJĄTEK — TYPY Z `Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`.
 * Trzy miesiące (decyzja właściciela, druga tura, po zewnętrznej ocenie
 * prawnej) są KRÓTSZE niż sześć miesięcy, przez które prawo do odwołania od
 * decyzji moderacyjnej ma obowiązywać (DSA art. 20 ust. 1). Powiadomienie
 * o decyzji niesie jedyny w serwisie link „Odwołaj się" — wygaszenie go po
 * ogólnym okresie odbierałoby prawo, które jeszcze obowiązuje. Dla tych
 * typów WŁASNY termin to `Notification::terminOchronyOdwolawczej()`
 * (`ModerationAction::appealDeadline()` powiązanej decyzji), NIE liczba
 * z configu — więc ta klasa sprawdza je JEDNO PO JEDNYM (Wzorzec C), a nie
 * jednym masowym `DELETE`, jak resztę tabeli.
 *
 * Powiadomienie, którego powiązanej decyzji nie da się ustalić (odniesienie
 * puste albo skasowane), NIE jest kasowane automatycznie — patrz komentarz
 * `Notification::terminOchronyOdwolawczej()`. To nie powinno się zdarzać
 * w praktyce (obaj producenci `TYPE_MODERATION`, `NotifyModerationDecision`
 * i `NotifyAppealOutcome`, zawsze zapisują odniesienie), ale błąd w tym
 * miejscu ma kosztować "zostaje o kilka miesięcy dłużej", nie "zniknęło,
 * zanim ktoś zdążył się odwołać".
 */
final class PrzedawnionePowiadomienia
{
    public function posprzataj(int $miesiecyKarencji, bool $naSucho = false): RaportRetencjiPowiadomien
    {
        // `subMonthsNoOverflow`, NIE `subMonths` — A6-04.
        //
        // Zwykłe odejmowanie miesięcy PRZEPEŁNIA datę, gdy dzień nie istnieje
        // w miesiącu docelowym, i przesuwa próg w stronę NOWSZYCH wierszy.
        // Zmierzone: 31 maja minus 3 miesiące daje `2026-03-03`, więc wiersz
        // z 1 marca wypadał po dwóch miesiącach i trzydziestu dniach —
        // wcześniej, niż obiecuje polityka prywatności.
        //
        //     now()                 subMonths(3)   subMonthsNoOverflow(3)
        //     2026-05-31 12:00      2026-03-03     2026-02-28
        //
        // Wariant bez przepełnienia cofa próg do ostatniego istniejącego dnia,
        // czyli myli się WYŁĄCZNIE w stronę „zostaje dłużej". Przy retencji
        // to jedyny dopuszczalny kierunek pomyłki: dane skasowane za wcześnie
        // znikają na zawsze, dane trzymane dzień dłużej — nie.
        //
        // Uwaga na przyszłość: NIE stosować tego odruchowo wszędzie.
        // `ModerationAction::appealDeadline()` DODAJE miesiące i tam
        // przepełnienie wydłuża termin odwołania, czyli działa na korzyść
        // człowieka. Podmiana byłaby tam skróceniem obiecanego terminu.
        $prog = now()->subMonthsNoOverflow($miesiecyKarencji);

        // Zwykłe powiadomienia — Wzorzec B (masowy DELETE), tak jak
        // audit_log/product_signals: wiersz nie ma odpowiednika w storage.
        $zwykle = Notification::query()
            ->whereNotIn('type', Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA)
            ->where('created_at', '<', $prog);

        $usunieteZwykle = $naSucho ? $zwykle->count() : $zwykle->delete();

        [$usunieteModeracyjne, $zatrzymane, $bezDecyzji, $nieudane] = $this->posprzatajModeracyjne($prog, $naSucho);

        return new RaportRetencjiPowiadomien(
            usunieteZwykle: $usunieteZwykle,
            usunieteModeracyjne: $usunieteModeracyjne,
            zatrzymaneTerminemOdwolania: $zatrzymane,
            bezPowiazanejDecyzji: $bezDecyzji,
            nieudaneModeracyjne: $nieudane,
        );
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int} [usunięto, zatrzymano
     *                                               terminem odwołania, pominięto
     *                                               bez decyzji, nie udało się
     *                                               skasować]
     */
    private function posprzatajModeracyjne(CarbonInterface $prog, bool $naSucho): array
    {
        // Wstępny filtr po ogólnym progu jest wyłącznie optymalizacją: termin
        // odwołania (created_at + co najmniej 6 miesięcy) jest ZAWSZE późniejszy
        // niż ogólny próg (created_at + 3 miesiące), więc wiersz młodszy niż
        // ten próg i tak zostałby zatrzymany terminem odwołania niżej —
        // pomijamy go tu bez sprawdzania, żeby nie liczyć `appealDeadline()`
        // dla powiadomień, które oczywiście jeszcze nie są kandydatem.
        $kandydaci = Notification::query()
            ->whereIn('type', Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA)
            ->where('created_at', '<', $prog)
            ->get();

        $usuniete = 0;
        $zatrzymane = 0;
        $bezDecyzji = 0;
        $nieudane = 0;

        foreach ($kandydaci as $powiadomienie) {
            $termin = $powiadomienie->terminOchronyOdwolawczej();

            if ($termin === null) {
                $bezDecyzji++;
                Log::warning('Powiadomienie moderacyjne bez ustalalnej decyzji — pominięte przy retencji, nie kasowane.', [
                    'notification_id' => $powiadomienie->getKey(),
                    'type' => $powiadomienie->type,
                ]);

                continue;
            }

            if ($termin->isFuture()) {
                $zatrzymane++;

                continue;
            }

            if ($naSucho) {
                $usuniete++;

                continue;
            }

            try {
                $powiadomienie->delete();
                $usuniete++;
            } catch (Throwable $e) {
                // JEDEN WADLIWY WIERSZ NIE ZATRZYMUJE RESZTY, ALE SIĘ LICZY
                // (#1342). Wcześniej był tu sam wpis w logu, a przebieg kończył
                // się sukcesem — harmonogram nie odróżniał pełnego sprzątania
                // od częściowego. Licznik trafia do raportu, a komenda zwraca
                // przy nim kod ≠ 0. Wiersz zostaje w bazie, więc następny
                // przebieg spróbuje go jeszcze raz.
                $nieudane++;

                // Sam identyfikator i bezpieczny opis wyjątku (#973:
                // `BezpiecznyBlad` — klasa, kod, miejsce, odcisk) — bez
                // komunikatu, który mógłby nieść treść powiadomienia. Zapis
                // do logu we własnym `try`: awaria logowania (także samego
                // opisu) nie może przesłonić wyniku ani przerwać kasowania
                // kolejnych kandydatów.
                try {
                    Log::error('Nie udało się skasować przedawnionego powiadomienia moderacyjnego', [
                        'notification_id' => $powiadomienie->getKey(),
                        'error' => BezpiecznyBlad::kontekst($e),
                    ]);
                } catch (Throwable) {
                }
            }
        }

        return [$usuniete, $zatrzymane, $bezDecyzji, $nieudane];
    }
}

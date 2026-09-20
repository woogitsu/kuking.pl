<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\ContactMessage;

/**
 * Retencja `contact_messages` — wiadomości z formularza „Napisz do nas".
 *
 * `config('kuking.kontakt.retention_months')` miesięcy (domyślnie 12) liczone
 * OD ZAŁATWIENIA (`handled_at`), nigdy od napisania. Uzasadnienie liczby stoi
 * przy kluczu w `config/kuking.php`; tutaj jest to, co z niej wynika w kodzie.
 *
 * WIADOMOŚĆ OTWARTA NIE JEST KANDYDATEM, NIEZALEŻNIE OD WIEKU.
 * To ta sama zasada, co przy sprawach moderacyjnych
 * (`PrzedawnioneSprawyModeracyjne`): „zamknięcie" nie istnieje, więc nie ma
 * od czego liczyć. Praktycznie znaczy to, że automat nigdy nie posprząta po
 * zaniedbaniu — wiadomość, której nikt nie przeczytał, będzie leżeć w kolejce
 * tak długo, aż ktoś ją przeczyta. Tak ma być: retencja jest ochroną danych,
 * nie sprzątaczką dowodów.
 *
 * WZORZEC B — MASOWY `DELETE`, NIE TRANSAKCJA PER WIERSZ.
 * Odpowiedzi `contact_message_replies` wskazują na wiadomość kluczem
 * `ON DELETE CASCADE`: znikają razem z nią i nie mają własnej retencji.
 * Nowa odpowiedź nie przesuwa `handled_at`, także przy zamkniętej sprawie.
 * Skutek dla operatora i warianty decyzji opisuje #847. `user_id` oraz
 * `handled_by` mają `nullOnDelete()` w swoją stronę. Nie ma tu pliku
 * w storage, jak przy eksportach. Jeden `DELETE` po indeksie
 * `contact_messages_handled_at_idx`.
 *
 * `subMonthsNoOverflow`, NIE `subMonths` — A6-04. Pełne uzasadnienie
 * i pomiar są w `PrzedawnionePowiadomienia`, przy oryginalnym znalezisku;
 * w skrócie: zwykłe odejmowanie miesięcy przepełnia datę, gdy dzień nie
 * istnieje w miesiącu docelowym (31 maja minus 3 miesiące daje 3 marca),
 * i przesuwa próg w stronę NOWSZYCH wierszy — czyli kasuje je przed czasem
 * obiecanym w polityce prywatności. Wariant bez przepełnienia myli się
 * wyłącznie w stronę „zostaje dłużej", a to jedyny dopuszczalny kierunek
 * pomyłki przy retencji.
 */
final class PrzedawnioneWiadomosciDoOperatora
{
    /**
     * @param  bool  $naSucho  policz kandydatów, nie kasuj niczego
     * @return int liczba wierszy usuniętych (albo policzonych przy `$naSucho`)
     */
    public function posprzataj(int $miesiecyKarencji, bool $naSucho = false): int
    {
        $prog = now()->subMonthsNoOverflow($miesiecyKarencji);

        $kandydaci = ContactMessage::query()
            ->where('status', ContactMessage::STATUS_ZALATWIONA)
            // `whereNotNull` jest tu nadmiarowe wobec CHECK-a
            // `contact_messages_handled_complete` (status inny niż `new`
            // WYMAGA kompletu `handled_by` + `handled_at`) i zostaje
            // świadomie: gdyby ten CHECK kiedyś zniknął albo dołączył
            // czwarty status, `handled_at < $prog` przepuściłoby `NULL`
            // jako „nieprawda", czyli wiersz zostałby na zawsze — cicho
            // i bez śladu. Wolę warunek, który mówi to wprost.
            ->whereNotNull('handled_at')
            ->where('handled_at', '<', $prog);

        return $naSucho ? $kandydaci->count() : $kandydaci->delete();
    }
}

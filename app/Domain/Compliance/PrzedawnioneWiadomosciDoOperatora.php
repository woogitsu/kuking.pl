<?php

declare(strict_types=1);

namespace App\Domain\Compliance;

use App\Models\ContactMessage;
use Carbon\CarbonInterface;

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
        $prog = self::progRetencji($miesiecyKarencji);

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

    /**
     * Próg z `posprzataj()`, wyjęty do osobnej metody WYŁĄCZNIE po to, żeby
     * `terminUsuniecia()` niżej mogła się do niego odwołać — bez kopiowania
     * `subMonthsNoOverflow` drugi raz gdzie indziej (#847). Zachowanie
     * `posprzataj()` jest identyczne jak przed tą zmianą: to przeniesienie
     * jednej linijki, nie nowa reguła.
     */
    private static function progRetencji(int $miesiecyKarencji, ?CarbonInterface $teraz = null): CarbonInterface
    {
        return ($teraz ?? now())->copy()->subMonthsNoOverflow($miesiecyKarencji);
    }

    /**
     * Termin, po którym KONKRETNA wiadomość ZAŁATWIONA stanie się
     * kandydatem do skasowania — `null`, gdy wiadomość nie jest zamknięta
     * (nie ma od czego liczyć) albo `handled_at` brakuje.
     *
     * DECYZJA WŁAŚCICIELA (20.09.2026, #847): ekran z formularzem odpowiedzi
     * ma pokazywać ten termin i drogę ponownego otwarcia sprawy, zamiast
     * milczeć o tym, że dopisek do starej zamkniętej sprawy zniknie razem
     * z nią następnego dnia. WPROST ODRZUCONE: przesuwanie retencji od
     * ostatniej odpowiedzi — to byłaby nowa decyzja o okresie przechowywania
     * (zmiana polityki prywatności), nie poprawka ekranu. `posprzataj()`
     * wyżej NIE ZMIENIŁO SIĘ ani o jedną datę.
     *
     * DLACZEGO `addMonthsNoOverflow`, A NIE DRUGA KOPIA PROGU LICZONA OD
     * „TERAZ" Wprost odwrócić `subMonthsNoOverflow(teraz, N)` względem
     * `teraz` się nie da (to `teraz`, nie `handled_at`, jest tam zmienną) —
     * ale odpowiedź na pytanie „kiedy TA wiadomość stanie się kandydatem"
     * to dokładnie „kiedy `teraz` przekroczy `handled_at + N miesięcy`",
     * licząc TYM SAMYM sposobem klamrowania końca miesiąca co
     * `subMonthsNoOverflow` (Carbon używa go symetrycznie w obie strony).
     * Dla zwykłych dat (nie 29 lutego) obie operacje są się wzajemnie
     * odwrotne co do dnia — test `RetencjaWiadomosciDoOperatoraTest`
     * i próba `TerminUsunieciaKorespondencjiTest` dowodzą tego, uruchamiając
     * `posprzataj()` naprawdę w dniu przed i w dniu tego terminu, a nie
     * tylko licząc daty.
     *
     * Jedyny znany wyjątek: wiadomość załatwiona 29 lutego roku
     * przestępnego — `addMonthsNoOverflow(12)` wyląduje na 28 lutego roku
     * zwykłego, dzień PRZED tym, jak faktycznie policzy próg
     * `subMonthsNoOverflow` uruchomiony dokładnie w południe 29 lutego (dnia,
     * który w tamtym roku nie istnieje). Błąd myli się w stronę „termin
     * pokazany wcześniej niż rzeczywisty" — czyli ostrzega za wcześnie,
     * nigdy za późno. To jedyny dopuszczalny kierunek pomyłki przy retencji
     * (ta sama zasada, co przy samym `subMonthsNoOverflow`, A6-04).
     */
    public function terminUsuniecia(ContactMessage $wiadomosc): ?CarbonInterface
    {
        if ($wiadomosc->status !== ContactMessage::STATUS_ZALATWIONA || $wiadomosc->handled_at === null) {
            return null;
        }

        $miesiace = (int) config('kuking.kontakt.retention_months');

        return $wiadomosc->handled_at->copy()->addMonthsNoOverflow($miesiace);
    }
}

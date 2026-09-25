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
     * JAK LICZONY JEST TERMIN (#1345). Termin to NAJWCZEŚNIEJSZA chwila
     * `teraz`, w której próg `progRetencji(N, teraz)` przekracza
     * `handled_at` — czyli chwila, od której `posprzataj()` naprawdę bierze
     * tę sprawę. `addMonthsNoOverflow` NIE jest odwrotnością
     * `subMonthsNoOverflow` przy końcu miesiąca: 31 stycznia + 1 miesiąc
     * daje 28 lutego, a sprzątanie 28 lutego liczy próg 28 stycznia i sprawy
     * nie rusza; próg przeskakuje za 31 stycznia dopiero o północy 1 marca.
     * To samo przy 31.01 + 3 (30 kwietnia → 1 maja), 30.03 + 11 i 29 lutego
     * + 12. Wcześniejsza wersja tej metody pokazywała wtedy termin o dzień
     * (albo kilka godzin) za wcześnie.
     *
     * Dlatego: kandydat `handled_at + N` i sprawdzenie TYM SAMYM progiem,
     * którego używa `posprzataj()`. Gdy próg w chwili kandydata nie sięga
     * `handled_at`, kandydat wylądował na przyciętym końcu miesiąca — a próg
     * przez resztę tego dnia zostaje w miesiącu `handled_at` na dniu
     * mniejszym niż dzień `handled_at`, i przeskakuje za niego dokładnie
     * o północy pierwszego dnia następnego miesiąca. `RetencjaTerminuNaKoncuMiesiacaTest`
     * pilnuje tego, uruchamiając `posprzataj()` minutę przed i minutę po
     * pokazanym terminie.
     */
    public function terminUsuniecia(ContactMessage $wiadomosc): ?CarbonInterface
    {
        if ($wiadomosc->status !== ContactMessage::STATUS_ZALATWIONA || $wiadomosc->handled_at === null) {
            return null;
        }

        $miesiace = (int) config('kuking.kontakt.retention_months');

        $termin = $wiadomosc->handled_at->copy()->addMonthsNoOverflow($miesiace);

        if (self::progRetencji($miesiace, $termin)->lessThan($wiadomosc->handled_at)) {
            $termin = $termin->copy()->addDay()->startOfDay();
        }

        return $termin;
    }
}

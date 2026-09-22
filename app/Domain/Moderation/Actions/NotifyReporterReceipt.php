<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Models\Notification;
use App\Models\Report;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * POTWIERDZENIE PRZYJĘCIA ZGŁOSZENIA DLA ZGŁASZAJĄCEGO Z KONTEM
 * (DSA art. 16 ust. 4), issue #10.
 *
 * CO BYŁO PRZEDTEM
 * Po kliknięciu „Zgłoś" człowiek dostawał jedno zdanie flash-em w sesji
 * („Dziękujemy. Zgłoszenie trafiło do nas…") i nic poza tym. Znikało po
 * odświeżeniu strony, nie było go w żadnej tabeli, nie było gdzie do niego
 * wrócić i nie niosło numeru sprawy. Art. 16 ust. 4 mówi o potwierdzeniu
 * odbioru bez zbędnej zwłoki — a potwierdzenie, którego nie da się odczytać
 * drugi raz, nim nie jest.
 *
 * DLACZEGO POWIADOMIENIE W SERWISIE, A NIE MAIL
 * Bo tą drogą zgłasza wyłącznie osoba Z KONTEM (`reports.create` stoi
 * w grupie `auth`), więc ma gdzie to przeczytać, a serwis nie wysyła dziś
 * poczty poza tym, co konieczne (brak SMTP — patrz „Poza zakresem" w issue
 * #10). Zgłoszenie prawne od osoby BEZ konta ma własną, mailową drogę
 * (`ZglosNielegalnaTresc` + `PotwierdzenieZgloszeniaNielegalnejTresci`)
 * i tej klasy nie dotyka.
 *
 * DLACZEGO NIE PRZEZ `NotifyUser`
 * Ten sam powód co w `NotifyModerationDecision`: wspólna bramka wycisza
 * powiadomienia dla kont nieaktywnych i przy blokadzie między osobami.
 * Tutaj obie reguły są szkodliwe — to nie jest cudza aktywność, tylko
 * odpowiedź serwisu na pismo tego człowieka. Zablokowanie kogokolwiek ani
 * zawieszenie własnego konta nie może jej wyciszyć: zawieszony ma prawo
 * zgłaszać i prawo wiedzieć, że zgłoszenie doszło.
 *
 * `actor_id` JEST PUSTE — powiadomienie pochodzi od serwisu, nie od
 * człowieka. Gdyby autorem był moderator, przy zespole 1-2 osób lista
 * powiadomień wskazywałaby palcem konkretną osobę.
 *
 * RETENCJA: zwykła (`config('kuking.notifications.retention_months')`).
 * Uzasadnienie stoi przy `Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`
 * — w skrócie: to jest ping, a trwałym zapisem sprawy jest sam wiersz
 * w `reports` i ekran `/zgloszenia`.
 */
final class NotifyReporterReceipt
{
    /**
     * `null`, gdy nie ma kogo powiadomić — zgłoszenie bez konta (droga
     * prawna, art. 16 ust. 2 lit. c) nie ma odbiorcy w tej tabeli i to jest
     * zgodne z przepisem, a nie brak w danych.
     */
    /**
     * Potwierdzenie, którego awaria NIE wywraca przyjęcia sprawy
     * (issue #797, kryterium 5).
     *
     * Sprawa jest w tym momencie zatwierdzona w bazie i widoczna na liście
     * `/zgloszenia` — a to właśnie ekran sprawy, nie ping, jest trwałym
     * potwierdzeniem odbioru z DSA art. 16 ust. 4 (uzasadnienie przy
     * `ReportController::index`). Przepuszczenie wyjątku dalej zamieniłoby
     * przyjęte zgłoszenie w stronę błędu: człowiek nie zobaczyłby numeru
     * sprawy ANI RAZU i miałby pełne prawo sądzić, że zgłoszenie przepadło.
     *
     * CISZY Z TEGO NIE MA. Wyjątek idzie do kanału błędów (`report()`),
     * a sprawa zostaje z `receipt_sent_at = null`, więc pierwsze ponowienie
     * tego samego zgłoszenia dokończy potwierdzenie
     * (`ReportContent::dokonczPotwierdzenie()`).
     *
     * A SPRAWA, DO KTÓREJ NIKT NIE WRÓCI, ma własną drogę. Do 20.09.2026
     * stało tu, że takiej komendy NIE MA i że jej dołożenie jest decyzją
     * właściciela. Decyzja zapadła: komenda nazywa się
     * `kuking:dosylaj-potwierdzenia-zgloszen` i chodzi w harmonogramie co
     * godzinę. Woła ona `handle()` niżej, a nie własną kopię tej logiki —
     * zamek na `receipt_sent_at` rozstrzyga więc zbieg dosyłki z powrotem
     * człowieka tak samo, jak rozstrzyga dwa równoległe ponowienia.
     */
    public function potwierdzBezWywracaniaSprawy(Report $zgloszenie): ?Notification
    {
        try {
            return $this->handle($zgloszenie);
        } catch (Throwable $awaria) {
            report($awaria);

            return null;
        }
    }

    public function handle(Report $zgloszenie): ?Notification
    {
        if ($zgloszenie->reporter_id === null) {
            return null;
        }

        /*
         * POWIADOMIENIE I ZNACZNIK W JEDNEJ TRANSAKCJI (issue #797).
         *
         * Przedtem były to dwa osobne zapisy: najpierw `Notification::create`,
         * potem `save()` znacznika. Awaria między nimi zostawiała stan,
         * z którego nie da się wyjść poprawnie — powiadomienie ISTNIEJE,
         * a `receipt_sent_at` jest `null`. Każde późniejsze ponowienie
         * „bo znacznika nie ma" dokładałoby drugi ping do tej samej sprawy.
         * Teraz albo są obie rzeczy, albo żadna.
         *
         * ZNACZNIK JEST ZAMKIEM, NIE TYLKO ZAPISEM. Warunkowy `UPDATE
         * ... WHERE receipt_sent_at IS NULL` rozstrzyga wyścig W BAZIE,
         * a nie w PHP: przy dwóch równoległych ponowieniach dokładnie jedno
         * dostanie wiersz, drugie zobaczy zero i nie utworzy niczego.
         * `SELECT` + `if` w PHP byłby check-then-act i przepuściłby oba
         * (ten sam argument, co przy `reports_one_open_per_pair`
         * w `ReportContent`).
         *
         * KOLEJNOŚĆ JEST CELOWA: najpierw zajmujemy miejsce znacznikiem,
         * potem tworzymy powiadomienie. Gdyby `Notification::create` padło,
         * transakcja cofa TAKŻE znacznik — sprawa wraca do stanu „jeszcze
         * niepotwierdzona" i ponowienie ma co dokończyć.
         *
         * SAMEGO ZGŁOSZENIA TO NIE DOTYKA. Wiersz w `reports` jest w tym
         * momencie dawno zatwierdzony (`ReportContent` zamyka swoją
         * transakcję przed wywołaniem tej klasy) i żadne wycofanie tutaj go
         * nie usunie. Przy obowiązku z DSA art. 16 ciche zgubienie sprawy
         * jest najgorszym z możliwych skutków — ta granica zostaje.
         */
        return DB::transaction(function () use ($zgloszenie): ?Notification {
            $zajete = Report::query()
                ->whereKey($zgloszenie->getKey())
                ->whereNull('receipt_sent_at')
                // Ten sam znacznik, którym mierzymy potwierdzenia wysłane
                // pocztą (`ZglosNielegalnaTresc`). Kolumna odpowiada na
                // pytanie „czy potwierdziliśmy odbiór", a nie „czy wysłaliśmy
                // list" — przy audycie liczy się to pierwsze.
                ->update(['receipt_sent_at' => now()]);

            if ($zajete === 0) {
                // Sprawa jest już potwierdzona. To NIE znaczy, że
                // powiadomienie nadal istnieje: `RetencjaPowiadomien` kasuje
                // je po ogólnym okresie, a trwałym zapisem sprawy jest sam
                // wiersz w `reports` i ekran `/zgloszenia`. Ponowienie nie ma
                // prawa wskrzeszać pingu sprzed trzech miesięcy.
                return null;
            }

            $powiadomienie = Notification::create([
                'user_id' => $zgloszenie->reporter_id,
                'actor_id' => null,
                'type' => Notification::TYPE_REPORT_RECEIVED,
                'data' => [
                    'report_id' => (string) $zgloszenie->getKey(),
                    'numer_sprawy' => $zgloszenie->numer_sprawy,
                    // Powód WŁASNEGO zgłoszenia — to jest informacja, którą
                    // ten człowiek sam nam podał, więc jej powtórzenie
                    // niczego o zgłoszonej osobie nie zdradza (Luka 3
                    // z DSA-LUKI.md).
                    'reason' => $zgloszenie->reason,
                ],
            ]);

            // Model w ręku wołającego ma znać stan, który właśnie zapisaliśmy
            // — `UPDATE` przez query builder go nie odświeża.
            $zgloszenie->refresh();

            return $powiadomienie;
        });
    }
}

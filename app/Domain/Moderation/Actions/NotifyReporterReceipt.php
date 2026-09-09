<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Models\Notification;
use App\Models\Report;

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
    public function handle(Report $zgloszenie): ?Notification
    {
        if ($zgloszenie->reporter_id === null) {
            return null;
        }

        $powiadomienie = Notification::create([
            'user_id' => $zgloszenie->reporter_id,
            'actor_id' => null,
            'type' => Notification::TYPE_REPORT_RECEIVED,
            'data' => [
                'report_id' => (string) $zgloszenie->getKey(),
                'numer_sprawy' => $zgloszenie->numer_sprawy,
                // Powód WŁASNEGO zgłoszenia — to jest informacja, którą ten
                // człowiek sam nam podał, więc jej powtórzenie niczego
                // o zgłoszonej osobie nie zdradza (Luka 3 z DSA-LUKI.md).
                'reason' => $zgloszenie->reason,
            ],
        ]);

        // Ten sam znacznik, którym mierzymy potwierdzenia wysłane pocztą
        // (`ZglosNielegalnaTresc`). Kolumna odpowiada na pytanie „czy
        // potwierdziliśmy odbiór", a nie „czy wysłaliśmy list" — przy
        // audycie liczy się to pierwsze.
        $zgloszenie->forceFill(['receipt_sent_at' => now()])->save();

        return $powiadomienie;
    }
}

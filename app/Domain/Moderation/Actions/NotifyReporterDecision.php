<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\OdpowiedzDlaZglaszajacego;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Report;

/**
 * INFORMACJA O ROZSTRZYGNIĘCIU ZGŁOSZENIA DLA ZGŁASZAJĄCEGO Z KONTEM
 * (DSA art. 16 ust. 5), issue #10.
 *
 * CO BYŁO PRZEDTEM
 * `ModerationController::decide()` powiadamiał zgłaszającego WYŁĄCZNIE przy
 * zgłoszeniu prawnym z adresem e-mail (`Report::maAdresDoOdpowiedzi()`).
 * Zgłoszenie ze zwykłego formularza „Zgłoś" nigdy nie ma `notifier_email`,
 * więc jego autor nie dowiadywał się niczego — ani że sprawa jest zamknięta,
 * ani co postanowiliśmy. Zmieniał się tylko `status` w bazie.
 *
 * DLACZEGO TO NIE JEST `NotifyModerationDecision` Z INNYM ODBIORCĄ
 * Bo to inny obowiązek i inna treść. Tamto powiadomienie mówi AUTOROWI, co
 * zrobiliśmy z jego treścią i dlaczego (art. 17), i niesie link do odwołania
 * od kary. To mówi ZGŁASZAJĄCEMU, co zrobiliśmy z jego zgłoszeniem —
 * i celowo NIE mówi, kogo ukaraliśmy ani jak (Luka 3
 * z `docs/research/DSA-LUKI.md`).
 *
 * TREŚĆ NIE JEST TU PISANA. Zdania liczy
 * `App\Domain\Moderation\OdpowiedzDlaZglaszajacego` przy WYŚWIETLANIU, z tego
 * samego powodu co `UzasadnienieDecyzji`: adres kontaktowy i numer sprawy
 * mają być aktualne, a nie zamrożone w `notifications.data` sprzed pół roku.
 * W `data` zostaje samo odniesienie do decyzji i do zgłoszenia.
 *
 * `actor_id` JEST PUSTE — powiadomienie pochodzi od serwisu, nie od
 * człowieka (patrz `NotifyModerationDecision`).
 *
 * RETENCJA: WYDŁUŻONA, do terminu odwołania od tej decyzji
 * (`Notification::WYDLUZONA_RETENCJA_DO_TERMINU_ODWOLANIA`). Pełne
 * uzasadnienie stoi przy tej stałej.
 */
final class NotifyReporterDecision
{
    /**
     * `null`, gdy nie ma kogo powiadomić tą drogą — zgłoszenie bez konta
     * (droga prawna) odpowiedź dostaje pocztą, w `DecyzjaWSprawieZgloszenia`.
     */
    public function handle(Report $zgloszenie, ModerationAction $decyzja): ?Notification
    {
        if ($zgloszenie->reporter_id === null) {
            return null;
        }

        $skutek = OdpowiedzDlaZglaszajacego::skutek($decyzja);

        $powiadomienie = Notification::create([
            'user_id' => $zgloszenie->reporter_id,
            'actor_id' => null,
            'type' => Notification::TYPE_REPORT_DECIDED,
            'data' => [
                'report_id' => (string) $zgloszenie->getKey(),
                'numer_sprawy' => $zgloszenie->numer_sprawy,
                // Skutek zamrażamy, bo to jest zdanie o TAMTEJ decyzji i ma
                // brzmieć tak samo za rok — inaczej niż pouczenie, które ma
                // nieść AKTUALNY adres kontaktowy.
                'naglowek' => $skutek['naglowek'],
                'reszta' => $skutek['reszta'],
                // Odniesienie do decyzji. Z niego retencja liczy termin
                // ochrony (`TerminOchronyOdwolawczej::dla()`) —
                // bez niego to powiadomienie zniknęłoby po ogólnych trzech
                // miesiącach razem z pouczeniem.
                'action_id' => (string) $decyzja->getKey(),
            ],
        ]);

        // Ta sama kolumna, którą znaczymy odpowiedź wysłaną pocztą. Pytanie
        // przy audycie brzmi „czy poinformowaliśmy zgłaszającego o decyzji",
        // a nie „czy poszedł list".
        $zgloszenie->forceFill(['decision_sent_at' => now()])->save();

        return $powiadomienie;
    }
}

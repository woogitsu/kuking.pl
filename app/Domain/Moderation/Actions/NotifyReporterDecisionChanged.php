<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Domain\Moderation\OdpowiedzDlaZglaszajacego;
use App\Domain\Moderation\ZmianaDecyzjiPoOdwolaniu;
use App\Models\Appeal;
use App\Models\Notification;
use App\Notifications\ZmianaDecyzjiWSprawieZgloszenia;
use Illuminate\Support\Facades\Notification as Poczta;

/**
 * KOREKTA DLA ZGŁASZAJĄCEGO PO COFNIĘCIU DECYZJI W ODWOŁANIU AUTORA (#1024).
 *
 * Pierwsza odpowiedź (`NotifyReporterDecision` albo list
 * `DecyzjaWSprawieZgloszenia`) mówiła „treść nie jest już dostępna". Po
 * wygranym odwołaniu autora treść wraca — i zgłaszający ma się o tym
 * dowiedzieć tym samym kanałem, którym dostał pierwszą odpowiedź (DSA
 * art. 16 ust. 5: informacja o decyzji, czyli także o jej zmianie).
 *
 * PIERWSZEGO POWIADOMIENIA NIE RUSZAMY. Opisuje decyzję, która naprawdę
 * zapadła; korekta jest NOWYM wpisem obok, a aktualny skutek na karcie
 * sprawy liczy `ZmianaDecyzjiPoOdwolaniu` z logu, nie z powiadomień.
 *
 * JEDNA KOREKTA NA ODWOŁANIE. W serwisie pilnuje tego `data.zmiana_po_odwolaniu`
 * — powtórzone wywołanie zastaje wpis i nic nie dokłada. List idzie
 * po zatwierdzeniu transakcji (`afterCommit`), więc wycofane rozpatrzenie
 * nie wyśle korekty zmiany, której nie było.
 *
 * Zgłoszenie anonimowe bez adresu nie ma kanału — i nie próbujemy go szukać.
 */
final class NotifyReporterDecisionChanged
{
    public function handle(Appeal $odwolanie): void
    {
        if ($odwolanie->isFromReporter() || $odwolanie->status !== Appeal::STATUS_OVERTURNED) {
            return;
        }

        $decyzja = $odwolanie->moderationAction;
        $zgloszenie = $decyzja?->report;

        if ($decyzja === null || $zgloszenie === null || ZmianaDecyzjiPoOdwolaniu::czyZmieniona($decyzja) === null) {
            return;
        }

        $skutek = OdpowiedzDlaZglaszajacego::skutekPoZmianie();

        if ($zgloszenie->reporter_id !== null) {
            $juzJest = Notification::query()
                ->where('user_id', $zgloszenie->reporter_id)
                ->where('type', Notification::TYPE_REPORT_DECIDED)
                ->where('data->zmiana_po_odwolaniu', (string) $odwolanie->getKey())
                ->exists();

            if (! $juzJest) {
                Notification::create([
                    'user_id' => $zgloszenie->reporter_id,
                    'actor_id' => null,
                    'type' => Notification::TYPE_REPORT_DECIDED,
                    'data' => [
                        'report_id' => (string) $zgloszenie->getKey(),
                        'numer_sprawy' => $zgloszenie->numer_sprawy,
                        'naglowek' => $skutek['naglowek'],
                        'reszta' => $skutek['reszta'],
                        // Retencja liczy termin od tej samej decyzji co
                        // pierwsza odpowiedź (`terminOchronyOdwolawczej()`).
                        'action_id' => (string) $decyzja->getKey(),
                        // Klucz jednej korekty. Sam identyfikator — widok
                        // go nie pokazuje i nic nie mówi o autorze.
                        'zmiana_po_odwolaniu' => (string) $odwolanie->getKey(),
                    ],
                ]);
            }

            return;
        }

        if ($zgloszenie->maAdresDoOdpowiedzi()) {
            Poczta::route('mail', $zgloszenie->notifier_email)
                ->notify(new ZmianaDecyzjiWSprawieZgloszenia($zgloszenie));
        }
    }
}

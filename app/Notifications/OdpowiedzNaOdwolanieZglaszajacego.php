<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Appeal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Odpowiedź na odwołanie ZGŁASZAJĄCEGO (issue #23, DSA art. 20).
 *
 * Odpowiednik `NotifyAppealOutcome`, który idzie do autora treści przez
 * powiadomienie w serwisie. Zgłaszający nie ma tam gdzie tego przeczytać —
 * może nie mieć konta wcale — więc odpowiedź idzie mailem, tak jak cała
 * reszta jego korespondencji z nami (`PotwierdzenieZgloszeniaNielegalnejTresci`,
 * `DecyzjaWSprawieZgloszenia`).
 *
 * Bez gry słowem „kuKING" — D-009 zabrania jej w wiadomości moderacyjnej.
 */
final class OdpowiedzNaOdwolanieZglaszajacego extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Appeal $odwolanie) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $utrzymana = $this->odwolanie->status === Appeal::STATUS_UPHELD;
        // Numer bierzemy z powiązanego zgłoszenia, nie z `report_id`:
        // to ta sama wartość, którą zgłaszający dostał w potwierdzeniu odbioru
        // i w decyzji, a nie druga, wyliczona osobno (D-027 wcześniej wyliczał
        // ją z UUID-a w pięciu miejscach — i w każdym mógł się rozjechać).
        $numer = $this->odwolanie->report?->numer_sprawy ?? '—';

        return (new MailMessage)
            ->subject("Sprawdziliśmy Twoje odwołanie (sprawa nr {$numer})")
            ->greeting('Dzień dobry.')
            ->line($utrzymana
                ? "Sprawdziliśmy jeszcze raz Twoje odwołanie w sprawie zgłoszenia nr **{$numer}**. **Podtrzymujemy naszą decyzję.**"
                : "Sprawdziliśmy jeszcze raz Twoje odwołanie w sprawie zgłoszenia nr **{$numer}**. **Zmieniamy naszą decyzję.**")
            ->line((string) $this->odwolanie->decision_note)
            ->line('Odwołanie od jednej decyzji rozpatrujemy raz. Jeśli pojawiły się nowe okoliczności, napisz na '
                .config('kuking.community.contact_email').'.')
            ->salutation('Zespół Kuking.pl');
    }
}

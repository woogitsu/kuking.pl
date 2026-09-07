<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Potwierdzenie odbioru odwołania ZGŁASZAJĄCEGO (issue #23, DSA art. 20).
 *
 * Ten sam powód co przy `PotwierdzenieZgloszeniaNielegalnejTresci`: zgłaszający
 * nie ma sesji ani powiadomień w serwisie, więc jedyny sygnał, że odwołanie
 * w ogóle do nas doszło, to ten list.
 */
final class PotwierdzenieOdwolaniaZglaszajacego extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Report $zgloszenie) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $numer = $this->zgloszenie->numer_sprawy;

        return (new MailMessage)
            ->subject("Dostaliśmy Twoje odwołanie (sprawa nr {$numer})")
            ->greeting('Dzień dobry.')
            ->line("Dostaliśmy Twoje odwołanie od naszej decyzji w sprawie zgłoszenia nr **{$numer}**.")
            ->line('Sprawdzimy je jeszcze raz i odpiszemy w ciągu '
                .config('kuking.moderation.appeal_response_working_days')
                .' dni roboczych, zawsze z uzasadnieniem.')
            ->line('Ten list jest potwierdzeniem odbioru. Nie musisz na niego odpisywać.')
            ->salutation('Zespół Kuking.pl');
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Moderation\OdpowiedzDlaZglaszajacego;
use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Korekta dla zgłaszającego PRAWNEGO po cofnięciu zdjęcia treści (#1024).
 *
 * Odpowiednik powiadomienia w serwisie z `NotifyReporterDecisionChanged`
 * dla osoby, która zgłosiła treść bez konta i dostała pierwszą decyzję
 * listem (`DecyzjaWSprawieZgloszenia`). Zdania o skutku i pouczenie idą
 * z `OdpowiedzDlaZglaszajacego` — te same co na ekranie.
 *
 * Bez nazwy autora, treści jego odwołania i słowa o koncie — ta sama
 * granica co w pierwszym liście (Luka 3 z `docs/research/DSA-LUKI.md`).
 *
 * `afterCommit()`: list nie wychodzi, jeśli rozpatrzenie odwołania, w którym
 * go zakolejkowano, zostanie wycofane.
 */
final class ZmianaDecyzjiWSprawieZgloszenia extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Report $zgloszenie)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $numer = $this->zgloszenie->numer_sprawy;
        $skutek = OdpowiedzDlaZglaszajacego::skutekPoZmianie();

        $list = (new MailMessage)
            ->subject("Zmiana decyzji w sprawie Twojego zgłoszenia (nr {$numer})")
            ->greeting('Dzień dobry.')
            ->line("Piszemy jeszcze raz w sprawie zgłoszenia nr **{$numer}**.")
            ->line("**{$skutek['naglowek']}** {$skutek['reszta']}")
            ->line('Poprzednio napisaliśmy, że treść nie jest już dostępna. Ta wiadomość zastępuje tamtą informację.')
            ->line('Zgłoszona przez Ciebie strona:')
            ->line((string) $this->zgloszenie->target_url)
            ->line('---')
            ->line('**'.OdpowiedzDlaZglaszajacego::NAGLOWEK_POUCZENIA.'**');

        foreach (OdpowiedzDlaZglaszajacego::pouczenie($this->zgloszenie) as $zdanie) {
            $list->line($zdanie);
        }

        return $list->salutation('Zespół Kuking.pl');
    }
}

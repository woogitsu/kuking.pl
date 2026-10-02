<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Profile;
use App\Support\Czas;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * „Hasło do Twojego konta zostało zmienione" — list po SKUTECZNEJ zmianie
 * hasła w ustawieniach albo po zakończonym resecie linkiem (issue #2565).
 *
 * Zamawia go wyłącznie `App\Domain\Users\Actions\UstawNoweHaslo`, jedną
 * drogą, PO zatwierdzeniu transakcji (`afterCommit()`): wycofany zapis nie
 * zostawia listu o czynności, której nie było. Kolejka, jak
 * `ZgloszonaZmianaAdresu` — awaria poczty nie zamienia udanej zmiany
 * w błąd formularza.
 *
 * W liście NIE MA: tokenu, linku logującego, linku wykonującego cokolwiek,
 * adresu IP ani urządzenia. Jest chwila zmiany (strefa `kuking.strefa`)
 * i to, co zrobić, jeśli to nie była decyzja właściciela. Oba odnośniki to
 * zwykłe strony: „Nie pamiętam hasła" i „Napisz do nas".
 *
 * Odbiorcę, imię i formę zwracania utrwala się przy zleceniu (worker nie
 * sięga do konta) — wzorzec z `ZgloszonaZmianaAdresu` (#888). List
 * bezpieczeństwa, więc bez zgód marketingowych i poza ciszą powiadomień
 * społecznych; do wspólnej puli poczty wlicza się jak inne listy bez
 * rezerwacji (`PoliczListBezRezerwacji`, D-239) i nigdy nie jest przez nią
 * odrzucany.
 */
final class PotwierdzenieZmianyHasla extends Notification implements ShouldQueue
{
    use Queueable;

    public const ZMIANA_W_USTAWIENIACH = 'ustawienia';

    public const RESET_LINKIEM = 'reset';

    public function __construct(
        private readonly CarbonInterface $kiedy,
        private readonly string $sposob,
        private readonly ?string $displayName = null,
        private readonly ?string $formaZwracania = null,
    ) {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Hasło do Twojego konta w Kuking zostało zmienione')
            ->view('mail.potwierdzenie-zmiany-hasla', [
                'kiedy' => Czas::data($this->kiedy, 'j F Y, H:i'),
                'sposob' => $this->sposob,
                'displayName' => $this->displayName,
                'profilAdresata' => new Profile(['form_of_address' => $this->formaZwracania]),
                'linkOdzyskania' => route('password.request'),
                'linkKontakt' => route('kontakt'),
            ]);
    }
}

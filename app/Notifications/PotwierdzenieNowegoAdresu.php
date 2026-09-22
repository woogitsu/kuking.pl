<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Czas;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * „Potwierdź nowy adres e-mail" — list na NOWY adres (issue #195).
 *
 * OSOBNA KLASA OBOK `PotwierdzenieAdresu`, A NIE JEJ PONOWNE UŻYCIE.
 * Tamta klasa jest pierwszym listem nowego konta i mówi „konto jest już
 * gotowe, została jedna rzecz". Tutaj konto działa od dawna, a człowiek
 * przenosi je na inną skrzynkę — i musi przeczytać rzecz, której tam nie ma:
 * że DO KLIKNIĘCIA loguje się i odzyskuje hasło STARYM adresem. Wciśnięcie
 * obu treści w jeden szablon skończyłoby się listem, który nie mówi wprost
 * ani jednego, ani drugiego.
 *
 * Wysyłamy go „na adres", nie „do użytkownika"
 * (`Notification::route('mail', …)` w `RequestEmailChange`), bo w chwili
 * wysyłki konto ma jeszcze STARY adres — `$notifiable->email` prowadziłoby
 * tam, gdzie nikt niczego nie potwierdza. Dlatego wszystko, czego ten list
 * potrzebuje, przychodzi w konstruktorze: obiektu `User` tu nie ma i nie
 * ma go po co wciągać (kolejka serializuje całe powiadomienie).
 */
final class PotwierdzenieNowegoAdresu extends Notification implements ShouldQueue
{
    /*
     * KOLEJKA — ten sam powód co w `PotwierdzenieAdresu` (audyt W3-13):
     * awaria serwera poczty nie może przewrócić żądania, które po naszej
     * stronie już się udało. Żądanie zmiany jest zapisane w bazie, zanim
     * ten list w ogóle ruszy.
     */
    use Queueable;

    public function __construct(
        private readonly string $linkUrl,
        private readonly CarbonInterface $waznyDo,
        private readonly ?string $displayName,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Potwierdź nowy adres e-mail w Kuking')
            ->view('mail.potwierdz-nowy-adres', [
                'linkUrl' => $this->linkUrl,
                'waznyDo' => Czas::data($this->waznyDo, 'j F Y, H:i'),
                'displayName' => $this->displayName,
            ]);
    }
}

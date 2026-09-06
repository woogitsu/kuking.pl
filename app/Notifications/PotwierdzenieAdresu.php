<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * „Potwierdź swój adres e-mail" — pierwszy list, jaki dostaje nowe konto.
 *
 * To jest pierwsze wrażenie z serwisu poza przeglądarką. Angielskie
 * „Verify your email address" od nadawcy, którego adresat nie zna, ląduje
 * w koszu albo w zgłoszeniu do syna („co to za dziwny mail przyszedł").
 *
 * Powód istnienia własnej klasy jest ten sam co przy `UstawienieNowegoHasla`
 * — szczegóły w komentarzu tamtej klasy.
 */
final class PotwierdzenieAdresu extends VerifyEmail implements ShouldQueue
{
    /*
     * KOLEJKA, NIE WYSYŁKA W ŻĄDANIU (audyt W3-13).
     *
     * Bez tego awaria serwera poczty zamieniała UDANĄ czynność w błąd 500.
     * Przy rejestracji wyglądało to tak: konto i profil zapisują się
     * w transakcji, transakcja się zatwierdza, potem leci `event(new
     * Registered($user))`, a listener Laravela wysyła list SYNCHRONICZNIE.
     * Wyjątek z SMTP przewracał więc żądanie PO utworzeniu konta — człowiek
     * widział błąd, próbował jeszcze raz i słyszał „na ten adres jest już
     * założone konto". Wyglądało to jak zgubiona rejestracja, choć konto
     * istniało.
     *
     * Z kolejką list jest zadaniem: nieudana wysyłka ponawia się i zostawia
     * ślad w `failed_jobs`, a czynność człowieka kończy się tak, jak
     * powinna. Kolejka to `database`, ta sama co reszta — nic nowego
     * nie dokładamy.
     */
    use Queueable;

    /**
     * @param  User  $notifiable
     */
    public function toMail($notifiable): MailMessage
    {
        $minut = (int) config('auth.verification.expire', 60);

        return (new MailMessage)
            ->subject('Potwierdź swój adres e-mail w Kuking')
            ->view('mail.potwierdz-adres', [
                // `verificationUrl()` z klasy nadrzędnej: adres jest podpisany
                // i wygasający. Własne sklejanie linku zgubiłoby podpis.
                'linkUrl' => $this->verificationUrl($notifiable),
                'waznoscTekst' => self::waznosc($minut),
                'displayName' => $notifiable->profile?->display_name,
            ]);
    }

    /** Uzasadnienie tego kształtu — patrz UstawienieNowegoHasla::waznosc(). */
    private static function waznosc(int $minut): string
    {
        return $minut === 60 ? 'przez godzinę' : "przez {$minut} min.";
    }
}

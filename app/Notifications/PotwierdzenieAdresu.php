<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
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
final class PotwierdzenieAdresu extends VerifyEmail
{
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

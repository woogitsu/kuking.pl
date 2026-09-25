<?php

declare(strict_types=1);

namespace App\Poczta;

use Illuminate\Notifications\Messages\MailMessage;
use Symfony\Component\Mime\Email;

/**
 * ZNACZNIK „TEN LIST MA JUŻ MIEJSCE WE WSPÓLNEJ PULI POCZTY" (audyt B8-02).
 *
 * Wspólny licznik `DziennyBudzetListow` liczył tylko drogi, które przed
 * wysyłką wołają `sprobujZarezerwowac()`. Połowa poczty (decyzje moderacji,
 * potwierdzenia zgłoszeń DSA, eksport, ostrzeżenia o zmianie adresu…) szła
 * obok niego, więc „300 na dobę" było liczone niepełnie, a dostawca liczy
 * wszystko. Od teraz `PoliczListBezRezerwacji` dolicza każdy list, który
 * wychodzi do transportu BEZ tego nagłówka.
 *
 * Nagłówek dokłada WYŁĄCZNIE list, którego droga zarezerwowała miejsce
 * przed wysyłką — inaczej wypadłby z rachunku. Rejestr takich klas i miejsc
 * rezerwacji trzyma `KazdyListLiczySieWPuliTest`; słuchacz zdejmuje nagłówek,
 * zanim list trafi do dostawcy.
 *
 * Mailable kolejkowany dokłada go przez `headers()` (tablica tekstowa),
 * nie przez `withSymfonyMessage()`: domknięcie w polu Mailable nie przeżyje
 * serializacji do kolejki. `MailMessage` powiadomienia buduje się dopiero
 * w workerze, więc tam domknięcie jest bezpieczne.
 */
final class ListZarezerwowany
{
    public const NAGLOWEK = 'X-Kuking-Budzet';

    public const WARTOSC = 'zarezerwowany';

    public static function oznacz(MailMessage $wiadomosc): MailMessage
    {
        return $wiadomosc->withSymfonyMessage(static function (Email $list): void {
            $list->getHeaders()->addTextHeader(self::NAGLOWEK, self::WARTOSC);
        });
    }

    /** @return array<string, string> do `new Headers(text: …)` Mailable */
    public static function naglowekTekstowy(): array
    {
        return [self::NAGLOWEK => self::WARTOSC];
    }
}

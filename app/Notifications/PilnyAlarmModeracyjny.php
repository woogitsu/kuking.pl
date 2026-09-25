<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Report;
use App\Poczta\ListZarezerwowany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * LIST, KTÓRY NIE MOŻE CZEKAĆ DO JUTRA (D-055).
 *
 * DLACZEGO NIE PO JEDNYM LIŚCIE NA KAŻDĄ OZNACZONĄ TREŚĆ
 * Bo przy fali migracyjnej skrzynka moderatora zamieniłaby się w śmietnik,
 * a skończyłoby się tym, że przestałby te listy otwierać — czyli alarm
 * przestałby działać dokładnie wtedy, gdy jest potrzebny. Zwykłe oznaczenia
 * idą raz dziennie, jednym podsumowaniem
 * (`App\Console\Commands\PodsumowanieAutomatu`).
 *
 * Ten list wychodzi WYŁĄCZNIE dla kategorii z `KategorieModeracji::PILNE` —
 * treści seksualnych i wszystkiego, co dotyczy dzieci. Obie mają
 * w `resources/legal/zasady.md` własną sekcję „Czego nie tolerujemy
 * w ogóle" i są jedynymi, przy których zwłoka jednego dnia jest realną
 * szkodą, a nie niedogodnością.
 *
 * DRUGI, NIEZALEŻNY POWÓD TEGO OGRANICZENIA: EmailLabs na planie darmowym
 * daje 300 listów dziennie, dzielone z potwierdzeniami rejestracji. Alarmy
 * moderacyjne nie mogą zjeść limitu potrzebnego na to, żeby ktoś w ogóle
 * mógł założyć konto.
 *
 * CZEGO W TYM LIŚCIE NIE MA
 * Treści wpisu i zdjęcia. Poczta idzie przez zewnętrznego dostawcę i leży
 * potem w cudzej skrzynce — a to jest treść, którą model dopiero
 * PODEJRZEWA o coś poważnego. List mówi, że jest sprawa i gdzie ją
 * obejrzeć; obejrzeć trzeba w panelu, za logowaniem i 2FA.
 */
final class PilnyAlarmModeracyjny extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly Report $oznaczenie) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return ListZarezerwowany::oznacz(new MailMessage)
            ->subject('Kuking: pilna pozycja w kolejce moderacji')
            ->greeting('Dzień dobry.')
            ->line('Automat oznaczył treść, która nie powinna czekać do jutrzejszego podsumowania.')
            ->line('**Powód:**')
            ->line((string) $this->oznaczenie->details)
            ->line('Nr sprawy: **'.$this->oznaczenie->numer_sprawy.'**')
            ->action('Otwórz kolejkę automatu', route('admin.sygnaly'))
            ->line('Treść jest w serwisie widoczna normalnie — automat niczego nie ukrył '
                .'ani nie zablokował. Decyzja należy do Ciebie.')
            ->salutation('Kuking');
    }
}

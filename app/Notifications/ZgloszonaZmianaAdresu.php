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
 * „Ktoś zażądał zmiany adresu e-mail Twojego konta" — list na STARY adres
 * (issue #195).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  TO JEST NAJWAŻNIEJSZY LIST W CAŁEJ TEJ FUNKCJI
 * ────────────────────────────────────────────────────────────────────────
 *
 * Cała reszta drogi (hasło, potwierdzenie nowej skrzynki) chroni przed
 * pomyłką. Przed DRUGIM CZŁOWIEKIEM — kimś, kto usiadł przy niezablokowanej
 * przeglądarce albo dorwał się do sesji — chroni wyłącznie ten list. Jest
 * jedynym sygnałem, jaki dotrze do właściciela, i jedyną chwilą, w której
 * da się to jeszcze zatrzymać.
 *
 * Dlatego mówi trzy rzeczy w tej kolejności:
 *
 *  1. sama prośba nie zmienia adresu; list może dotrzeć po potwierdzeniu;
 *  2. jeśli to Ty — nie musisz nic robić, list poszedł też na tamten adres;
 *  3. jeśli to NIE Ty — zmień hasło. Zmiana hasła unieważnia to żądanie
 *     (`App\Domain\Users\Actions\CancelEmailChange`), więc ta rada
 *     naprawdę działa. Gdyby nie działała, byłaby gorsza niż jej brak.
 *
 * NOWY ADRES POKAZUJEMY W SKRÓCIE (`j***@wp.pl`). Wpisuje go w całości
 * żądający, więc pełny zapis zamieniłby ten list w tablicę ogłoszeń
 * napastnika — pełne uzasadnienie w `App\Support\AdresEmail`.
 *
 * BEZ ODNOŚNIKA „to nie ja, anuluj" — świadomie. Taki link musiałby działać
 * bez logowania (właściciel może nie mieć sesji), czyli byłby drugim
 * podpisanym adresem zmieniającym stan konta, wysłanym pocztą. Zamiast tego
 * kierujemy na ekran zmiany hasła: droga dłuższa o jedno logowanie, za to
 * bez nowej powierzchni ataku, i kończy się rzeczą, którą i tak trzeba
 * zrobić — odcięciem tego, kto miał dostęp.
 */
final class ZgloszonaZmianaAdresu extends Notification implements ShouldQueue
{
    /** Kolejka — ten sam powód co w `PotwierdzenieAdresu` (audyt W3-13). */
    use Queueable;

    public function __construct(
        private readonly string $nowyAdresSkrot,
        private readonly CarbonInterface $waznyDo,
        private readonly ?string $displayName = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ktoś prosi o zmianę adresu e-mail Twojego konta w Kuking')
            ->view('mail.zgloszona-zmiana-adresu', [
                'nowyAdresSkrot' => $this->nowyAdresSkrot,
                'waznyDo' => Czas::data($this->waznyDo, 'j F Y, H:i'),
                'displayName' => $this->displayName ?? null,
                'linkHaslo' => route('settings.security'),
            ]);
    }
}

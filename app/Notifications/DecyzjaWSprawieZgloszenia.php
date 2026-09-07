<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ModerationAction;
use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Powiadomienie ZGŁASZAJĄCEGO o decyzji (DSA art. 16 ust. 5).
 *
 * DLACZEGO TO JEST OSOBNY OBOWIĄZEK
 * Serwis ma już `NotifyModerationDecision` — ale ono idzie do AUTORA treści,
 * czyli do osoby ukaranej (art. 17). Zgłaszającemu należy się odpowiedź
 * osobno i z innej podstawy: art. 16 ust. 5 wymaga powiadomienia o decyzji
 * wraz z informacją o możliwościach odwołania.
 *
 * Do tej pory zgłaszający nie dowiadywał się NICZEGO — nawet tego, że sprawa
 * została zamknięta. Ekran obiecywał „odpiszemy Ci, co zrobiliśmy" i była to
 * obietnica bez pokrycia (audyt W5-02).
 *
 * CZEGO TU NIE MA I DLACZEGO
 * Nie piszemy, KTO opublikował zgłoszoną treść ani jaką dokładnie karę
 * dostał. Zgłaszający ma prawo wiedzieć, czy treść zostaje czy znika, i to
 * mu mówimy. Reszta to dane osobowe osoby trzeciej i podanie ich zamieniłoby
 * mechanizm zgłoszeń w narzędzie do ustalania, kogo ukarano.
 */
final class DecyzjaWSprawieZgloszenia extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Jedyne decyzje, po których zgłoszona treść PRZESTAJE być dostępna.
     *
     * Lista jest jawna i wąska, a nie „wszystko oprócz braku działania":
     * przy dodaniu nowej decyzji domyślną odpowiedzią ma być „treść
     * zostaje", bo to jest odpowiedź prawdziwa dla każdej decyzji
     * dotyczącej KONTA, a nie treści.
     */
    private const AKCJE_ZDEJMUJACE_TRESC = [
        ModerationAction::ACTION_HIDE,
        ModerationAction::ACTION_REMOVE,
    ];

    public function __construct(
        private readonly Report $zgloszenie,
        private readonly ModerationAction $decyzja,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $numer = mb_strtoupper(mb_substr((string) $this->zgloszenie->getKey(), 0, 8));

        $list = (new MailMessage)
            ->subject("Decyzja w sprawie Twojego zgłoszenia (nr {$numer})")
            ->greeting('Dzień dobry.')
            ->line("Sprawdziliśmy zgłoszenie nr **{$numer}**, które nam przysłałeś.");

        // TRZY KOMUNIKATY, NIE DWA — bo „zgłoszenie zasadne" i „treści już
        // nie ma" to dwie różne rzeczy, a wcześniej były jedną.
        //
        // Kod liczył skutek jako `action !== ACTION_NONE`, czyli
        // „cokolwiek poza brakiem działania znaczy, że treści nie ma".
        // Tymczasem treść przestaje być dostępna WYŁĄCZNIE przy `hide`
        // i `remove`. Ostrzeżenie, zawieszenie i ban nie ruszają zgłoszonej
        // treści wcale — patrz `ModerationController::zastosuj()`.
        //
        // Najgorszy przypadek: przy zgłoszeniu OSOBY macierz
        // `ModerationAction::DOZWOLONE` nie dopuszcza ani `hide`, ani
        // `remove`, więc KAŻDE uznane zgłoszenie konta wysyłało zdanie,
        // które nie mogło być prawdziwe.
        $list->line(match (true) {
            in_array($this->decyzja->action, self::AKCJE_ZDEJMUJACE_TRESC, true) => '**Uznaliśmy Twoje zgłoszenie za zasadne.** Zgłoszona treść '
                .'nie jest już dostępna w serwisie.',

            // Zasadne, ale treść zostaje. NIE PISZEMY, co zrobiliśmy
            // z kontem: to dane osobowe osoby trzeciej, a mechanizm
            // zgłoszeń nie jest narzędziem do ustalania, kogo ukarano.
            $this->decyzja->action !== ModerationAction::ACTION_NONE => '**Uznaliśmy Twoje zgłoszenie za zasadne** i podjęliśmy działania '
                .'przewidziane w naszym regulaminie. Sama zgłoszona treść '
                .'zostaje w serwisie — środek, który zastosowaliśmy, jej nie '
                .'dotyczy.',

            // Decyzja odmowna MUSI podać powód — inaczej nie da się jej
            // sensownie zakwestionować, a przepis wymaga pouczenia o środkach
            // odwoławczych, które bez powodu jest puste.
            default => '**Po sprawdzeniu uznaliśmy, że ta treść zostaje w serwisie.** '
                .'Nie znaleźliśmy w niej naruszenia, które by to uzasadniało.',
        });

        return $list
            ->line('Zgłoszona przez Ciebie strona:')
            ->line((string) $this->zgloszenie->target_url)
            ->line('---')
            ->line('**Jeśli się z nami nie zgadzasz**')
            ->line('Napisz do nas na '.(string) config('kuking.community.contact_email')
                .', podając numer sprawy — sprawa wróci do człowieka, który jej wcześniej nie prowadził.')
            ->line('Możesz też zwrócić się do pozasądowego organu rozstrzygania sporów '
                .'albo do sądu. Decyzja, którą tu opisujemy, nie zamyka Ci żadnej z tych dróg.')
            ->salutation('Zespół Kuking.pl');
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\ModerationAction;
use App\Models\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

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
        $numer = $this->zgloszenie->numer_sprawy;

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

        $list
            ->line('Zgłoszona przez Ciebie strona:')
            ->line((string) $this->zgloszenie->target_url)
            ->line('---')
            ->line('**Jeśli się z nami nie zgadzasz**');

        // ODWOŁANIE W NASZYM SYSTEMIE SKARG (issue #23, DSA art. 20 ust. 1).
        //
        // Do 7 września 2026 zgłaszający nie miał tu ŻADNEJ drogi poza
        // napisaniem maila — `appeals.user_id` było `NOT NULL` i wskazywało
        // wyłącznie autora treści (pomiar: `docs/decyzje/DSA_POMIAR.md` §3
        // punkt 2, potwierdzony testami w `PomiarBrakowArt20Test` PRZED
        // migracją `appeals_open_to_reporters`). Link jest PODPISANY
        // i WYGASAJĄCY (`URL::temporarySignedRoute`, ten sam mechanizm co
        // `DataExportReady`) — UUID zgłoszenia w adresie sam w sobie nie
        // byłby autoryzacją (AGENTS.md §7), podpis jest tym, co czyni link
        // nie do podrobienia. Wygasa DOKŁADNIE z terminem na odwołanie, nie
        // wcześniej i nie później.
        //
        // `report_id` na decyzji powinno tu być zawsze — ta notyfikacja
        // idzie wyłącznie z `ModerationController::decide()`, który tworzy
        // `$decyzja` Z tego właśnie zgłoszenia. Warunek zostaje jako obrona
        // w głąb, nie jako ścieżka realnie osiągana.
        if ($this->decyzja->report_id !== null) {
            $link = URL::temporarySignedRoute(
                'appeals.reporter',
                $this->decyzja->appealDeadline(),
                ['report' => $this->zgloszenie->getKey()],
            );

            $list->line('Jeśli się nie zgadzasz z tą decyzją — także jeśli chodzi o brak działania '
                .'z naszej strony — możesz się odwołać w naszym systemie skarg:')
                ->action('Złóż odwołanie', $link)
                ->line('Na odwołanie masz sześć miesięcy od tej decyzji, czyli do '
                    .$this->decyzja->appealDeadline()->translatedFormat('j F Y').'.');
        }

        return $list
            // NIE OBIECUJEMY „INNEGO CZŁOWIEKA". Do dziś stało tu zdanie
            // „sprawa wróci do człowieka, który jej wcześniej nie prowadził"
            // — i kod nie czynił go prawdą. Przy zespole 1-2 osób nic tego
            // nie zapewnia; jedyne, co jest egzekwowane, to karencja
            // z `ResolveAppeal` (ten sam moderator nie PODTRZYMA własnej
            // decyzji przez `appeal_self_uphold_hours`) — i dziś dotyczy
            // OBU dróg odwołania, autora i zgłaszającego, jednakowo.
            //
            // Zostaje więc zdanie, które jest prawdą: napisz, zajmiemy się
            // sprawą ponownie. Tego akurat nie obiecuje żaden mechanizm
            // w kodzie, tylko człowiek czytający skrzynkę — i dokładnie tak
            // to brzmi.
            ->line('Możesz też napisać do nas na '.(string) config('kuking.community.contact_email')
                .', podając numer sprawy — zajmiemy się nią ponownie.')
            ->line('Możesz też zwrócić się do pozasądowego organu rozstrzygania sporów '
                .'albo do sądu. Decyzja, którą tu opisujemy, nie zamyka Ci żadnej z tych dróg.')
            ->salutation('Zespół Kuking.pl');
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Moderation\OdpowiedzDlaZglaszajacego;
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
 *
 * SAME ZDANIA MIESZKAJĄ W `App\Domain\Moderation\OdpowiedzDlaZglaszajacego`
 * (issue #10). Ten list nie jest już jedynym kanałem, którym mówimy
 * zgłaszającemu, co zrobiliśmy — zgłoszenie ze zwykłego formularza „Zgłoś"
 * dostaje tę samą treść powiadomieniem w serwisie. Dwie kopie tych samych
 * zdań rozjechałyby się przy pierwszej poprawce, a to jest treść, której
 * kształtu wymaga przepis.
 */
final class DecyzjaWSprawieZgloszenia extends Notification implements ShouldQueue
{
    use Queueable;

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
            ->line("Sprawdziliśmy zgłoszenie nr **{$numer}**, które od Ciebie dostaliśmy.");

        // TRZY KOMUNIKATY, NIE DWA — bo „zgłoszenie zasadne" i „treści już
        // nie ma" to dwie różne rzeczy, a wcześniej były jedną. Pełne
        // uzasadnienie i sam podział: `OdpowiedzDlaZglaszajacego::skutek()`.
        $skutek = OdpowiedzDlaZglaszajacego::skutek($this->decyzja);

        $list->line("**{$skutek['naglowek']}** {$skutek['reszta']}");

        $list
            ->line('Zgłoszona przez Ciebie strona:')
            ->line((string) $this->zgloszenie->target_url)
            ->line('---')
            ->line('**'.OdpowiedzDlaZglaszajacego::NAGLOWEK_POUCZENIA.'**');

        // ODWOŁANIE W NASZYM SYSTEMIE SKARG (issue #23, DSA art. 20 ust. 1).
        //
        // Do 7 września 2026 zgłaszający nie miał tu ŻADNEJ drogi poza
        // napisaniem maila — `appeals.user_id` było `NOT NULL` i wskazywało
        // wyłącznie autora treści (pomiar: `docs/decyzje/DSA_POMIAR.md` §3
        // punkt 2, potwierdzony testami w `PomiarBrakowArt20Test` PRZED
        // migracją `appeals_open_to_reporters`). Link jest PODPISANY
        // (`URL::signedRoute`, ten sam mechanizm co `OdnosnikWypisania`) —
        // UUID zgłoszenia w adresie sam w sobie nie byłby autoryzacją
        // (AGENTS.md §7), podpis jest tym, co czyni link nie do podrobienia.
        //
        // PODPIS NIE NIESIE JUŻ TERMINU (issue #798, decyzja właściciela
        // 20.09.2026). Stało tu `temporarySignedRoute(..., appealDeadline())`,
        // więc link umierał dokładnie wtedy, gdy mijał termin na ZŁOŻENIE
        // odwołania — a ten sam adres służy potem do ŚLEDZENIA sprawy. Kto
        // odwołał się tydzień przed terminem, dostawał 403 na własną, wciąż
        // nierozpatrzoną sprawę. Dziś o tym, czy strona jeszcze istnieje,
        // decyduje STAN SPRAWY (`App\Domain\Moderation\DostepDoStronySprawy`,
        // sprawdzany w `ReporterAppealController`), a nie data wyliczona
        // w chwili wysyłania tego listu. Terminu na złożenie to nie rusza:
        // pilnuje go `FileReporterAppeal`, tak jak pilnował.
        //
        // `report_id` na decyzji powinno tu być zawsze — ta notyfikacja
        // idzie wyłącznie z `ModerationController::decide()`, który tworzy
        // `$decyzja` Z tego właśnie zgłoszenia. Warunek zostaje jako obrona
        // w głąb, nie jako ścieżka realnie osiągana.
        if ($this->decyzja->report_id !== null) {
            $link = URL::signedRoute(
                'appeals.reporter',
                ['report' => $this->zgloszenie->getKey()],
            );

            $list->line('Jeśli się nie zgadzasz z tą decyzją — także jeśli chodzi o brak działania '
                .'z naszej strony — możesz się odwołać w naszym systemie skarg:')
                ->action('Złóż odwołanie', $link)
                ->line('Na odwołanie masz sześć miesięcy od tej decyzji, czyli do '
                    .$this->decyzja->appealDeadline()->translatedFormat('j F Y').'.')
                // JAK WRÓCIĆ PO ODPOWIEDŹ — ekran i ten list mają mówić to
                // samo (issue #798). Zachowaj ten list: to jest jedyne
                // miejsce, z którego ta osoba wchodzi na stronę swojej sprawy.
                ->line('Ten sam odnośnik pokazuje, co się z Twoją sprawą dzieje — działa, '
                    .'dopóki sprawa jest otwarta, i jeszcze '
                    .config('kuking.moderation.reporter_case_link_days')
                    .' dni po naszej odpowiedzi. Samą odpowiedź wyślemy Ci mailem, '
                    .'więc zachowaj też tę wiadomość.');
        }

        // NIE OBIECUJEMY „INNEGO CZŁOWIEKA". Stało tu kiedyś zdanie „sprawa
        // wróci do człowieka, który jej wcześniej nie prowadził" — i kod nie
        // czynił go prawdą. Przy zespole 1-2 osób nic tego nie zapewnia;
        // jedyne, co jest egzekwowane, to karencja z `ResolveAppeal` (ten
        // sam moderator nie PODTRZYMA własnej decyzji przez
        // `appeal_self_uphold_hours`) — i dziś dotyczy OBU dróg odwołania,
        // autora i zgłaszającego, jednakowo.
        //
        // Same zdania stoją w `OdpowiedzDlaZglaszajacego::pouczenie()`, żeby
        // ekran „Twoje zgłoszenia" (issue #10) pouczał dokładnie tak samo jak
        // ten list, a nie podobnie.
        foreach (OdpowiedzDlaZglaszajacego::pouczenie($this->zgloszenie) as $zdanie) {
            $list->line($zdanie);
        }

        return $list->salutation('Zespół Kuking.pl');
    }
}

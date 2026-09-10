<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Report;
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
 * Ten list wychodzi z DWÓCH dróg i z żadnej innej:
 *
 *  1. AUTOMAT (D-055, droga pierwotna) — wyłącznie dla kategorii
 *     z `KategorieModeracji::PILNE`, czyli treści seksualnych i wszystkiego,
 *     co dotyczy dzieci. Obie mają w `resources/legal/zasady.md` własną
 *     sekcję „Czego nie tolerujemy w ogóle" i są jedynymi, przy których
 *     zwłoka jednego dnia jest realną szkodą, a nie niedogodnością.
 *
 *  2. ZGŁOSZENIE OD CZŁOWIEKA O PRIORYTECIE P0 (D-070) — CSAM, groźba
 *     zagrażająca życiu, aktywny doxxing. Droga dołożona 10 września 2026
 *     i ŚWIADOMIE PODPIĘTA DO ISTNIEJĄCEGO KANAŁU, a nie zbudowana obok:
 *     drugi mechanizm alarmowy znaczyłby dwa progi „co jest pilne", dwa
 *     miejsca do wyciszenia i dwie okazje, żeby jedno z nich zamilkło bez
 *     śladu. Adres, kolejkowanie, brak treści w liście i rachunek za
 *     budżet poczty są tu wspólne — patrz `AlarmujModeratora`.
 *
 * Panel ma na to własne, niezależne oznaczenie
 * (`App\Domain\Moderation\PilneSprawy`) i to ono jest podstawą: poczta
 * bywa niedostarczona, a pasek panelu nie. List jest tym, co dociera do
 * człowieka, który akurat nie patrzy w panel.
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
        return $this->oznaczenie->wykrylAutomat()
            ? $this->listOdAutomatu()
            : $this->listOZgloszeniu();
    }

    /**
     * Droga pierwotna: automat podniósł rękę (D-055).
     *
     * Tekst bez zmian od września — celowo. Ten list moderator już zna
     * i rozpoznaje go po pierwszym zdaniu.
     */
    private function listOdAutomatu(): MailMessage
    {
        return (new MailMessage)
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

    /**
     * Droga druga: człowiek zgłosił sprawę krytyczną (P0, D-070).
     *
     * CZEGO W TYM LIŚCIE NIE MA — z tego samego powodu co wyżej: ani treści
     * zgłoszenia (`details`), ani adresu zgłoszonej strony, ani danych
     * zgłaszającego. To jest sprawa, o której zgłaszający dopiero TWIERDZI,
     * że dotyczy przestępstwa, a list leży potem w cudzej skrzynce
     * u zewnętrznego dostawcy poczty. Powód jest podany kategorią
     * (`reasonLabel()` — nasza własna, zamknięta lista), bo bez niej nie da
     * się ocenić, czy trzeba wstać od stołu; wszystko poza nią czyta się
     * w panelu, za logowaniem i 2FA.
     *
     * Odnośnik prowadzi na kolejkę spraw pilnych, nie na kartę tej jednej
     * sprawy: przy P0 zwykle warto zobaczyć od razu, czy nie przyszło coś
     * jeszcze, a moderator dostaje wtedy właściwą kolejność (najpilniejsze,
     * najdłużej czekające pierwsze) bez wybierania filtrów.
     */
    private function listOZgloszeniu(): MailMessage
    {
        return (new MailMessage)
            ->subject('Kuking: zgłoszenie krytyczne (P0) w kolejce moderacji')
            ->greeting('Dzień dobry.')
            ->line('W kolejce moderacji jest zgłoszenie zakwalifikowane jako **P0 — krytyczne**. '
                .'Podręcznik moderacji przewiduje dla niego reakcję natychmiast, poza kolejnością '
                .'wszystkiego innego.')
            ->line('**Kategoria zgłoszenia:** '.$this->oznaczenie->reasonLabel())
            ->line('**Czego dotyczy:** '.$this->oznaczenie->targetLabel())
            ->line('Nr sprawy: **'.$this->oznaczenie->numer_sprawy.'**')
            ->action('Otwórz sprawy pilne', route('admin.reports', ['pilne' => 1]))
            ->line('Treść zgłoszenia i wskazana treść są w panelu — tego listu świadomie nie '
                .'obciążamy niczym, co dopiero trzeba ocenić.')
            ->line('Priorytet ustala kolejność, nie decyzję. Jeśli po przeczytaniu okaże się, że to '
                .'nie jest sprawa krytyczna, obniż priorytet w panelu i napisz jednym zdaniem dlaczego.')
            ->salutation('Kuking');
    }
}

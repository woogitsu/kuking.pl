<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Moderation\PriorytetSprawy;
use App\Models\Report;
use App\Poczta\ListZarezerwowany;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * LIST, KTÓRY NIE MOŻE CZEKAĆ — ZGŁOSZENIE OD CZŁOWIEKA.
 *
 * DLACZEGO OSOBNA KLASA OBOK `PilnyAlarmModeracyjny`
 * Bo to jest inna wiadomość dla tego samego adresata, nie ta sama wiadomość
 * z innego wejścia. Tamta mówi „automat coś podejrzewa" i prowadzi na
 * `/admin/sygnaly`; ta mówi „człowiek to zgłosił" i prowadzi na
 * `/admin/zgloszenia`. Różni się też tym, czego w NIEJ nie ma — patrz niżej.
 * Jedna klasa z dwoma `if`-ami w środku byłaby oszczędnością na pliku, którą
 * płaci się potem przy każdej zmianie treści którejkolwiek z nich.
 *
 * Wspólne jest to, co w tym projekcie jest regułą, a nie szczegółem tej
 * skrzynki: kanałem alarmowym jest POCZTA (`AGENTS.md`), a listy tego rodzaju
 * wychodzą WYŁĄCZNIE dla najwęższej listy kategorii (`PriorytetSprawy::P0`,
 * ta sama para co `KategorieModeracji::PILNE`). Alarm, który zapala się przy
 * pięciu rzeczach, przestaje być alarmem — moderator uczy się go nie otwierać.
 * Osobno liczy się limit poczty: 300 listów dziennie na cały serwis (D-047),
 * dzielone z potwierdzeniami rejestracji.
 *
 * CZEGO W TYM LIŚCIE NIE MA — I TU JEST WIĘCEJ NIŻ W ALARMIE AUTOMATU
 *
 *  • Treści zgłoszonego wpisu ani zdjęcia. Poczta idzie przez zewnętrznego
 *    dostawcę i leży potem w cudzej skrzynce, a to jest treść, którą ktoś
 *    dopiero PODEJRZEWA o coś najcięższego. List mówi, że jest sprawa i gdzie
 *    ją obejrzeć; obejrzeć trzeba w panelu, za logowaniem i 2FA.
 *  • Pola `details`, czyli tego, co zgłaszający wpisał WŁASNYMI SŁOWAMI.
 *    Alarm automatu wysyła swoje `details`, bo tam pisze je nasz kod — całe
 *    zdanie, które sami układamy. Tutaj to jest niesprawdzony tekst od
 *    dowolnej osoby z internetu i nie ma powodu, żeby wychodził na zewnątrz.
 *    Zostaje sama KATEGORIA z zamkniętej listy `Report::REASONS`.
 *  • Kim jest zgłaszający i czyja jest zgłoszona treść. Numer sprawy
 *    wystarczy, żeby ją w panelu odnaleźć.
 */
final class PilneZgloszenieOdCzlowieka extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  bool  $ostatniDzis  ten list zajął ostatnie miejsce dobowego
     *                             sufitu alarmów (`AlarmujOPilnymZgloszeniu`) —
     *                             mówimy to wprost, żeby cisza po nim nie
     *                             wyglądała jak „nic się nie dzieje"
     */
    public function __construct(
        private readonly Report $zgloszenie,
        private readonly bool $ostatniDzis = false,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $prawne = $this->zgloszenie->source === Report::SOURCE_LEGAL_NOTICE;

        $list = ListZarezerwowany::oznacz(new MailMessage)
            ->subject('Kuking: pilne zgłoszenie w kolejce moderacji')
            ->greeting('Dzień dobry.')
            ->line($prawne
                // Przy drodze prawnej mówimy to wprost, bo zmienia obowiązki:
                // zgłaszającemu należy się zawiadomienie o decyzji i pouczenie
                // o środkach odwoławczych (DSA art. 16 ust. 5).
                ? 'Ktoś złożył zgłoszenie nielegalnej treści w kategorii, która nie powinna czekać.'
                : 'Ktoś zgłosił treść w kategorii, która nie powinna czekać.')
            ->line('**Kategoria:** '.$this->zgloszenie->reasonLabel())
            ->line('Nr sprawy: **'.$this->zgloszenie->numer_sprawy.'**')
            ->action('Otwórz kolejkę zgłoszeń', route('admin.reports'))
            // „Decyzja należy do Ciebie" — jak w alarmie automatu — byłoby
            // tu nieprawdą w jednym przypadku: gdy zgłosił sam moderator,
            // a list trafia na wspólny adres alarmowy. Własnego zgłoszenia
            // nie rozstrzyga nikt (D-244), więc list mówi, KTO rozstrzyga.
            ->line('Kategorię wybrał zgłaszający i nikt jej jeszcze nie sprawdził. '
                .'Treść jest w serwisie widoczna normalnie — samo zgłoszenie niczego '
                .'nie ukryło ani nie zablokowało. Rozstrzyga moderator, który tego '
                .'zgłoszenia nie wniósł.');

        if ($this->ostatniDzis) {
            $list->line('**To ostatni taki list dzisiaj.** Kolejne pilne zgłoszenia czekają już tylko '
                .'w kolejce, na samej górze. Zajrzyj do niej, zanim skończysz dzień.');
        }

        return $list->salutation('Kuking');
    }

    /** Priorytet, przy którym ten list w ogóle wychodzi — czytane przez akcję alarmu. */
    public static function prog(): int
    {
        return PriorytetSprawy::P0;
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Support\Odmiana;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * LIST O TERMINIE Z DSA ART. 20, KTÓRY ZARAZ MINIE (D-058).
 *
 * DLACZEGO POCZTA JEST TU, A NIE PRZY KAŻDYM ODWOŁANIU
 * Nowe odwołanie daje powiadomienie w panelu (`PowiadomOOdwolaniu`) i licznik
 * przy pozycji „Odwołania" na każdym ekranie panelu. To wystarcza, dopóki ktoś
 * do panelu zagląda — a termin to siedem DNI ROBOCZYCH, nie godziny.
 *
 * Pocztą jedzie dokładnie jedna sytuacja: termin jest BLISKO albo już MINĄŁ,
 * a sprawy nikt nie zamknął. Wtedy powiadomienie w serwisie właśnie zawiodło
 * (nikt go nie przeczytał) i trzeba innego kanału. To jest ta sama logika, co
 * przy `PilnyAlarmModeracyjny`: poczta zarezerwowana dla rzeczy, które nie
 * mogą czekać — inaczej rozróżnienie przestaje cokolwiek znaczyć.
 *
 * KOSZT W WIADRZE: najwyżej JEDEN list na dobę, na jeden adres, i tylko
 * w dniach, w których naprawdę coś wisi — dlatego ta funkcja nie ma własnego
 * sufitu w podziale wiadra 300 listów (D-047, D-057), a mieści się w rezerwie
 * transakcyjnej. Rachunek stoi w komentarzu sekcji `poczta` w `config/kuking.php`.
 *
 * CZEGO W TYM LIŚCIE NIE MA
 * Treści odwołania i nazw ludzi. Poczta idzie przez zewnętrznego dostawcę
 * i leży potem w cudzej skrzynce, a to jest pismo w sprawie moderacyjnej —
 * ten sam powód, dla którego `PilnyAlarmModeracyjny` nie niesie treści wpisu.
 * List mówi, ILE spraw wisi i DO KIEDY; przeczytać trzeba w panelu, za
 * logowaniem i 2FA.
 */
final class TerminOdwolaniaBlisko extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $poTerminie  odwołania, którym termin odpowiedzi już minął
     * @param  int  $blisko  odwołania z terminem w najbliższych dniach
     * @param  string  $najblizszyTermin  data najpilniejszej sprawy, po polsku
     */
    public function __construct(
        private readonly int $poTerminie,
        private readonly int $blisko,
        private readonly string $najblizszyTermin,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $razem = $this->poTerminie + $this->blisko;

        $list = (new MailMessage)
            // Temat mówi stan, nie liczbę porządkową: to jedyna rzecz, którą
            // widać w skrzynce bez otwierania listu.
            ->subject($this->poTerminie > 0
                ? 'Kuking: termin odpowiedzi na odwołanie MINĄŁ'
                : 'Kuking: termin odpowiedzi na odwołanie się zbliża')
            ->greeting('Dzień dobry.');

        if ($this->poTerminie > 0) {
            $list->line('**'.$this->poTerminie.'** '
                .Odmiana::rzeczownik($this->poTerminie, 'odwołanie ma', 'odwołania mają', 'odwołań ma')
                .' termin odpowiedzi PO CZASIE. Obiecaliśmy odpowiedź w ciągu '
                .config('kuking.moderation.appeal_response_working_days')
                .' dni roboczych — w regulaminie i w każdej wiadomości o decyzji.');
        }

        if ($this->blisko > 0) {
            $list->line('**'.$this->blisko.'** '
                .Odmiana::rzeczownik($this->blisko, 'odwołanie czeka', 'odwołania czekają', 'odwołań czeka')
                .' z terminem w najbliższych dniach.');
        }

        return $list
            ->line('Najpilniejsza sprawa ma termin: **'.$this->najblizszyTermin.'**.')
            ->action('Otwórz kolejkę odwołań', route('admin.appeals'))
            ->line('Odpowiedź na odwołanie MUSI mieć uzasadnienie — wynik bez wyjaśnienia '
                .'nie jest odpowiedzią w rozumieniu DSA art. 20.')
            ->line('Ten list wychodzi tylko wtedy, gdy termin jest blisko albo minął. '
                .'O nowych odwołaniach mówi powiadomienie w panelu i licznik '
                .'przy pozycji „Odwołania" — łącznie czeka ich '.$razem.'.')
            ->salutation('Kuking');
    }
}

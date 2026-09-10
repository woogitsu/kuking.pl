<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Report;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * JEDEN LIST DZIENNIE ZAMIAST STU (D-055).
 *
 * Kolejka moderacji nie jest awarią i nie wymaga budzenia nikogo w nocy.
 * Codzienny rytm wystarcza, żeby nic nie zaległo, i — co ważniejsze — nie
 * uczy nikogo ignorowania listów od własnego serwisu. Rzeczy, które nie mogą
 * czekać, mają osobny kanał (`PilnyAlarmModeracyjny`) i są ograniczone do
 * dwóch kategorii, żeby to rozróżnienie coś znaczyło.
 *
 * List NIE WYCHODZI, gdy nie ma o czym pisać — „0 nowych pozycji" codziennie
 * przez trzy tygodnie nauczyłoby odbiorcę, że ten temat wolno przewijać.
 */
final class PodsumowanieKolejkiAutomatu extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  int  $nowe  oznaczenia z ostatniej doby
     * @param  int  $czekaja  wszystko, co stoi otwarte w kolejce
     * @param  array<string, int>  $wedlugSygnalu  powód (etykieta po polsku) → ile
     */
    public function __construct(
        private readonly int $nowe,
        private readonly int $czekaja,
        private readonly array $wedlugSygnalu,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $list = (new MailMessage)
            ->subject('Kuking: '.$this->nowe.' '.$this->odmiana($this->nowe).' w kolejce automatu')
            ->greeting('Dzień dobry.')
            ->line('W ciągu ostatniej doby automat oznaczył **'.$this->nowe.'** '
                .$this->odmiana($this->nowe).' do przejrzenia.');

        foreach ($this->wedlugSygnalu as $etykieta => $ile) {
            $list->line('— '.$etykieta.': '.$ile);
        }

        $list
            ->line('W kolejce czeka łącznie **'.$this->czekaja.'**.')
            ->action('Otwórz kolejkę automatu', route('admin.sygnaly'))
            ->line('Wszystkie te treści są w serwisie widoczne normalnie, a ich autorzy '
                .'o niczym nie wiedzą. Automat niczego nie ukrywa ani nie blokuje.')
            ->salutation('Kuking');

        return $list;
    }

    /** „1 pozycję", „3 pozycje", „7 pozycji" — moderator czyta to co rano. */
    private function odmiana(int $ile): string
    {
        if ($ile === 1) {
            return 'pozycję';
        }

        $reszta10 = $ile % 10;
        $reszta100 = $ile % 100;

        if ($reszta10 >= 2 && $reszta10 <= 4 && ($reszta100 < 12 || $reszta100 > 14)) {
            return 'pozycje';
        }

        return 'pozycji';
    }

    /** @return array<string, int> */
    public static function wedlugSygnalu(CarbonInterface $od): array
    {
        $wynik = [];

        foreach (Report::REASONS_AUTOMAT as $kod => $etykieta) {
            $ile = Report::query()
                ->where('source', Report::SOURCE_AUTOMAT)
                ->where('reason', $kod)
                ->where('created_at', '>=', $od)
                ->count();

            if ($ile > 0) {
                $wynik[$etykieta] = $ile;
            }
        }

        return $wynik;
    }
}

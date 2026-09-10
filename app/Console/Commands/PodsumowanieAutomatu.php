<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Report;
use App\Notifications\PodsumowanieKolejkiAutomatu;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * CODZIENNY LIST O KOLEJCE AUTOMATU (D-055).
 *
 * DLACZEGO ZBIORCZO, A NIE PO JEDNYM LIŚCIE
 * Bo przy setkach kont pojedyncze listy zamieniłyby skrzynkę moderatora
 * w śmietnik i nauczyłyby go, że listy od własnego serwisu wolno przewijać.
 * Kolejka moderacji nie jest awarią: codzienny rytm wystarcza, żeby nic nie
 * zaległo. Rzeczy, które nie mogą czekać, mają osobny kanał
 * (`App\Notifications\PilnyAlarmModeracyjny`) ograniczony do dwóch kategorii
 * — treści seksualnych i wszystkiego, co dotyczy dzieci — właśnie po to,
 * żeby to rozróżnienie coś znaczyło.
 *
 * DRUGI POWÓD, NIEZALEŻNY OD PIERWSZEGO: EmailLabs na planie darmowym daje
 * 300 listów dziennie, dzielone z potwierdzeniami rejestracji i resetami
 * hasła. Jeden list dziennie kosztuje 1/300 tego limitu; jeden list na
 * oznaczenie potrafiłby przy pierwszej fali zjeść go w całości, a wtedy
 * nikt nie założyłby konta.
 *
 * NIE WYSYŁAMY LISTU, GDY NIE MA O CZYM PISAĆ. „0 nowych pozycji" codziennie
 * przez trzy tygodnie to najlepszy sposób, żeby czwarty list przeszedł
 * niezauważony.
 */
class PodsumowanieAutomatu extends Command
{
    protected $signature = 'kuking:podsumowanie-automatu
                            {--godzin=24 : Za ile ostatnich godzin liczyć nowe pozycje}';

    protected $description = 'Wysyła moderatorowi jeden zbiorczy list o nowych pozycjach w kolejce automatu';

    public function handle(): int
    {
        $adres = config('kuking.moderation.model.alarm_email');

        if (! is_string($adres) || $adres === '') {
            $this->info('Adres alarmowy nie jest ustawiony (KUKING_MODEL_ALARM_EMAIL) — nie ma dokąd wysłać.');

            return self::SUCCESS;
        }

        $od = now()->subHours(max(1, (int) $this->option('godzin')));

        $nowe = Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->where('created_at', '>=', $od)
            ->count();

        if ($nowe === 0) {
            $this->info('Brak nowych oznaczeń — list nie wychodzi.');

            return self::SUCCESS;
        }

        $czekaja = Report::query()
            ->where('source', Report::SOURCE_AUTOMAT)
            ->whereIn('status', Report::STANY_OTWARTE)
            ->count();

        Notification::route('mail', $adres)->notify(
            new PodsumowanieKolejkiAutomatu($nowe, $czekaja, PodsumowanieKolejkiAutomatu::wedlugSygnalu($od)),
        );

        $this->info('Wysłano podsumowanie: '.$nowe.' nowych, '.$czekaja.' czeka w kolejce.');

        return self::SUCCESS;
    }
}

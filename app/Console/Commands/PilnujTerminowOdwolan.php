<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Appeal;
use App\Notifications\TerminOdwolaniaBlisko;
use App\Support\Czas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * PILNOWANIE TERMINU Z DSA ART. 20 (D-058).
 *
 * Nowe odwołanie daje powiadomienie w panelu i licznik przy pozycji
 * „Odwołania" (`PowiadomOOdwolaniu`, `KolejkiPanelu`). Ta komenda pilnuje
 * czegoś innego i pojedynczo ważniejszego: czy któreś z odwołań LEŻY tak
 * długo, że obiecany termin odpowiedzi zaraz minie albo już minął. Wtedy —
 * i tylko wtedy — wychodzi list.
 *
 * JEDEN LIST NA DOBĘ, NIE JEDEN NA SPRAWĘ. Ten sam powód co przy dobowym
 * podsumowaniu kolejki automatu (D-055): list na każdą pozycję zamienia
 * skrzynkę w śmietnik i uczy, że listy od własnego serwisu wolno przewijać.
 * A dodatkowo: EmailLabs daje 300 listów na dobę na CAŁY serwis (D-047),
 * dzielone z potwierdzeniami rejestracji.
 *
 * LIST NIE WYCHODZI, GDY NIE MA O CZYM PISAĆ. „0 spraw po terminie"
 * codziennie przez trzy tygodnie to najlepszy sposób, żeby czwarty list
 * przeszedł niezauważony.
 */
class PilnujTerminowOdwolan extends Command
{
    protected $signature = 'kuking:pilnuj-terminow-odwolan';

    protected $description = 'Wysyła jeden list, gdy termin odpowiedzi na odwołanie jest blisko albo minął (DSA art. 20)';

    public function handle(): int
    {
        $adres = config('kuking.moderation.model.alarm_email');

        if (! is_string($adres) || $adres === '') {
            $this->info('Adres alarmowy nie jest ustawiony (KUKING_MODEL_ALARM_EMAIL) — nie ma dokąd wysłać.');

            return self::SUCCESS;
        }

        // Termin liczy `Appeal::responseDeadline()` w dniach ROBOCZYCH, więc
        // nie da się go wyrazić warunkiem SQL bez powtórzenia tej reguły
        // w drugim miejscu — a druga kopia reguły terminu prawnego to
        // najgorszy rodzaj duplikatu. Otwartych odwołań jest w tym serwisie
        // najwyżej kilkadziesiąt (jedno na decyzję, jedna decyzja na sprawę),
        // więc bierzemy je i liczymy w PHP, tam gdzie reguła już mieszka.
        $otwarte = Appeal::query()
            ->where('status', Appeal::STATUS_OPEN)
            ->get();

        if ($otwarte->isEmpty()) {
            $this->info('Brak otwartych odwołań — list nie wychodzi.');

            return self::SUCCESS;
        }

        $prog = now()->addWeekdays(
            max(0, (int) config('kuking.moderation.appeal_reminder_working_days')),
        );

        $poTerminie = $otwarte->filter(fn (Appeal $o): bool => $o->isOverdue());
        $blisko = $otwarte->filter(
            fn (Appeal $o): bool => ! $o->isOverdue() && $o->responseDeadline()->lessThanOrEqualTo($prog),
        );

        if ($poTerminie->isEmpty() && $blisko->isEmpty()) {
            $this->info('Wszystkie otwarte odwołania mają termin z zapasem — list nie wychodzi.');

            return self::SUCCESS;
        }

        $najblizszy = $poTerminie->concat($blisko)
            ->sortBy(fn (Appeal $o) => $o->responseDeadline()->getTimestamp())
            ->first();

        Notification::route('mail', $adres)->notify(new TerminOdwolaniaBlisko(
            poTerminie: $poTerminie->count(),
            blisko: $blisko->count(),
            najblizszyTermin: Czas::data($najblizszy->responseDeadline(), 'j F Y'),
        ));

        $this->info('Wysłano przypomnienie: po terminie '.$poTerminie->count()
            .', blisko terminu '.$blisko->count().'.');

        return self::SUCCESS;
    }
}

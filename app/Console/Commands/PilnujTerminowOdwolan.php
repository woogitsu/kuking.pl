<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Notifications\PrzypomnienieDobowe;
use App\Models\Appeal;
use App\Notifications\TerminOdwolaniaBlisko;
use App\Support\Czas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * PILNOWANIE TERMINU Z DSA ART. 20 (D-060).
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
 * „JEDEN NA DOBĘ" PILNUJE BAZA, NIE HARMONOGRAM (#1333). `withoutOverlapping()`
 * chroni tylko przed dwoma przebiegami NARAZ; ręczne ponowienie albo restart
 * tego samego dnia kolejkowały drugi list. Przed kolejkowaniem zajmujemy
 * wiersz w `przypomnienia_dobowe` (`PrzypomnienieDobowe`) — kto go nie
 * zajął, ten nie wysyła. Nieudane kolejkowanie oddaje miejsce i kończy się
 * błędem, żeby kolejny przebieg mógł spróbować.
 *
 * LIST NIE WYCHODZI, GDY NIE MA O CZYM PISAĆ. „0 spraw po terminie"
 * codziennie przez trzy tygodnie to najlepszy sposób, żeby czwarty list
 * przeszedł niezauważony.
 */
class PilnujTerminowOdwolan extends Command
{
    public const RODZAJ = 'termin-odwolania';

    protected $signature = 'kuking:pilnuj-terminow-odwolan';

    protected $description = 'Wysyła jeden list, gdy termin odpowiedzi na odwołanie jest blisko albo minął (DSA art. 20)';

    public function handle(PrzypomnienieDobowe $przypomnienie): int
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

        try {
            $zarezerwowano = $przypomnienie->zarezerwuj(self::RODZAJ, $adres);
        } catch (Throwable $e) {
            // Bez rezerwacji nie wysyłamy: duplikat jest tu gorszy niż
            // przypomnienie przesunięte do następnego przebiegu.
            Log::error('Przypomnienie o terminach odwołań: nie udało się zarezerwować dzisiejszego listu.', [
                'wyjatek' => $e::class,
            ]);
            $this->error('Nie udało się sprawdzić, czy dzisiejsze przypomnienie już wyszło — list nie wyszedł. '
                .'Sprawdź połączenie z bazą i uruchom komendę ponownie.');

            return self::FAILURE;
        }

        if (! $zarezerwowano) {
            $this->info('Dzisiejsze przypomnienie o terminach odwołań już wyszło — kolejne jutro, jeśli sprawy nadal będą czekać.');

            return self::SUCCESS;
        }

        try {
            Notification::route('mail', $adres)->notify(new TerminOdwolaniaBlisko(
                poTerminie: $poTerminie->count(),
                blisko: $blisko->count(),
                najblizszyTermin: Czas::data($najblizszy->responseDeadline(), 'j F Y'),
            ));
        } catch (Throwable $e) {
            // List nie trafił do kolejki, więc nie wyszedł — oddajemy dzisiejsze
            // miejsce, żeby kolejny przebieg mógł spróbować.
            $przypomnienie->zwolnij(self::RODZAJ, $adres);
            Log::error('Przypomnienie o terminach odwołań: nie udało się wstawić listu do kolejki.', [
                'wyjatek' => $e::class,
            ]);
            $this->error('Nie udało się wstawić przypomnienia do kolejki. Sprawdź kolejkę '
                .'(`php artisan kuking:sprawdz-kolejke`) i uruchom komendę ponownie.');

            return self::FAILURE;
        }

        $this->info('Wysłano przypomnienie: po terminie '.$poTerminie->count()
            .', blisko terminu '.$blisko->count().'.');

        return self::SUCCESS;
    }
}

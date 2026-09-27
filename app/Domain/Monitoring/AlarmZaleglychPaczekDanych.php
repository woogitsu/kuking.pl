<?php

declare(strict_types=1);

namespace App\Domain\Monitoring;

use App\Logging\WebhookBleduHandler;
use App\Models\DataExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wygasłe paczki z danymi, które NADAL leżą w storage (issue #1331).
 *
 * Paczka to kopia całego konta. `kuking:sprzataj-eksporty` przy nieudanym
 * kasowaniu zachowuje adres i próbuje następnej nocy — i to jest dobre, ale
 * sam wpis w dzienniku serwera nie jest alarmem. Ta czujka patrzy na STAN
 * w bazie, nie na przebieg komendy, więc łapie oba przypadki:
 *  - storage uparcie nie kasuje (paczka `expired` z zachowanym adresem),
 *  - komenda w ogóle przestała chodzić (paczka `ready` długo po terminie).
 *
 * PRÓG 36 GODZIN PO `expires_at`. Sprzątanie chodzi raz na dobę, więc zdrowa
 * paczka czeka po terminie najwyżej ~24 h. 36 h znaczy: co najmniej jeden
 * przebieg jej nie usunął albo się nie odbył.
 *
 * SZUM: czujka chodzi raz na dobę, więc trwająca zaległość daje najwyżej
 * jedną wiadomość dziennie. Powrót do normy daje JEDNĄ wiadomość odwołującą.
 * Pamięć „był alarm" mieszka w cache (`AlarmMemory`), który czyści się przy
 * starcie kontenera — po wdrożeniu odwołanie może przepaść; sama ocena jest
 * bezstanowa, więc restart nie wywoła fałszywego alarmu. Ten sam kompromis
 * co w `App\Domain\Kolejka\AlarmKolejki`.
 *
 * W WIADOMOŚCI SĄ WYŁĄCZNIE LICZBY. Żadnego identyfikatora paczki, klucza
 * obiektu ani danych osoby: `blad_webhook` wychodzi do usługi, nad którą nie
 * mamy kontroli (AGENTS.md §7). Adresy do ręcznego dokończenia są w dzienniku
 * serwera (`kuking:sprzataj-eksporty` loguje je przy każdej porażce).
 */
final class AlarmZaleglychPaczekDanych
{
    public const PROG_GODZIN = 36;

    private const KLUCZ = 'kuking:eksporty:zalegle-paczki-alarm';

    /** @return array{zalegle: int, najstarsza_godzin: int} */
    public function sprawdz(): array
    {
        $zapytanie = DataExport::query()
            ->whereIn('status', [DataExport::STATUS_READY, DataExport::STATUS_EXPIRED])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->subHours(self::PROG_GODZIN))
            ->where(fn ($q) => $q->whereNotNull('disk')->orWhereNotNull('object_key'));

        $najstarszyTermin = (clone $zapytanie)->min('expires_at');

        return [
            'zalegle' => $zapytanie->count(),
            'najstarsza_godzin' => $najstarszyTermin === null
                ? 0
                : (int) now()->diffInHours(Carbon::parse($najstarszyTermin), true),
        ];
    }

    /**
     * @param  array{zalegle: int, najstarsza_godzin: int}  $wynik
     * @return bool czy kanał PRZYJĄŁ wiadomość (alarm albo odwołanie)
     */
    public function zadzwonJesliTrzeba(array $wynik): bool
    {
        $pamiec = app(AlarmMemory::class);

        if ($wynik['zalegle'] > 0) {
            Log::error('Wygasłe paczki z danymi nadal leżą w storage.', $wynik + ['prog_godzin' => self::PROG_GODZIN]);

            if (! $this->kanalWlaczony() || ! $this->kanalPrzyjal($this->tresc($wynik))) {
                return false;
            }

            $pamiec->put(self::KLUCZ, ['alarm' => true], new \DateInterval('P30D'));

            return true;
        }

        if ($pamiec->get(self::KLUCZ) === null || ! $this->kanalWlaczony()) {
            return false;
        }

        if (! $this->kanalPrzyjal('paczki z danymi: zaległość zniknęła — wszystkie wygasłe paczki są usunięte ze storage.')) {
            // Pamięć zostaje: następny przebieg spróbuje odwołać jeszcze raz.
            return false;
        }

        $pamiec->forget(self::KLUCZ);

        return true;
    }

    /** @param  array{zalegle: int, najstarsza_godzin: int}  $wynik */
    public function tresc(array $wynik): string
    {
        return sprintf(
            'paczki z danymi: w storage nadal są wygasłe paczki (kopie całych kont) — liczba: %d, '
            .'każda dłużej niż %d h po terminie, najstarsza %d h. Co zrobić: sprawdź w dzienniku serwera wpisy „paczka z danymi” '
            .'z `kuking:sprzataj-eksporty`, napraw dostęp do storage i uruchom `php artisan kuking:sprzataj-eksporty`. '
            .'Adres pliku zostaje w bazie — nic nie ginie, ale dane są przechowywane po terminie.',
            $wynik['zalegle'],
            self::PROG_GODZIN,
            $wynik['najstarsza_godzin'],
        );
    }

    private function kanalWlaczony(): bool
    {
        // Ten sam warunek co w `bootstrap/app.php`, `AlarmKopii` i `AlarmKolejki`.
        return ! blank(config('logging.channels.blad_webhook.url'));
    }

    /** Brak wyjątku nie jest przyjęciem — pełne uzasadnienie w `AlarmKolejki::kanalPrzyjal()`. */
    private function kanalPrzyjal(string $tresc): bool
    {
        WebhookBleduHandler::zapomnijOstatniaWysylke();

        try {
            Log::channel('blad_webhook')->error($tresc);
        } catch (Throwable) {
            return false;
        }

        return WebhookBleduHandler::ostatniaWysylkaSieUdala() === true;
    }
}

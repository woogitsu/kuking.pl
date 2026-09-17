<?php

declare(strict_types=1);

namespace App\Domain\Kolejka;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * „Kolejka stanęła" albo „coś padło w nocy" na webhook właściciela (#599).
 *
 * TEN SAM KANAŁ, CO BŁĘDY 500, CZUJKA KOPII I BUDŻET POŁĄCZEŃ
 * (`blad_webhook`, D-041) — jedno miejsce, w które właściciel patrzy.
 * Nie dokładamy drugiej platformy monitoringu; to jest wymóg #599.
 *
 * OGRANICZENIE POWTÓRZEŃ I POWRÓT DO ZDROWIA
 * Czujka chodzi co kwadrans, a martwy worker bywa martwy przez dobę. Bez
 * ciszy dałoby to 96 identycznych wiadomości i nauczyło ignorować kanał.
 * Zmiana stanu dzwoni od razu (także eskalacja „nowe nieudane" → „zaległość"),
 * ten sam stan nie częściej niż raz na `cisza_godzin`, a powrót do spokoju
 * daje DOKŁADNIE JEDNĄ wiadomość odwołującą.
 *
 * ZNANE OGRANICZENIE PAMIĘCI
 * Pamięć stanu mieszka w cache, a `docker/entrypoint.sh` czyści cache przy
 * każdym starcie kontenera. Po wdrożeniu pamięć jest pusta, więc trwający
 * alarm zadzwoni raz dodatkowo, a niewysłane odwołanie przepadnie. To jest
 * świadomie zaakceptowane: nadmiarowa wiadomość o prawdziwej awarii jest
 * tańsza niż tabela i migracja dla stanu, który wolno zgubić. SAMA OCENA
 * stanu jest bezstanowa (`StanKolejki` liczy z okna czasowego), więc restart
 * nie generuje fałszywej awarii — tylko ewentualne powtórzenie prawdziwej.
 */
final class AlarmKolejki
{
    private const KLUCZ = 'kuking:kolejka:ostatni-alarm';

    /** Stany, które są ALARMEM. `spokojna` nie dzwoni. */
    private const ALARMUJACE = [
        StanKolejki::ZALEGLOSC,
        StanKolejki::NOWE_NIEUDANE,
        StanKolejki::NIEDOSTEPNA,
    ];

    /**
     * @param  array<string, mixed>  $wynik
     */
    public function zadzwonJesliTrzeba(array $wynik): bool
    {
        $stan = (string) ($wynik['stan'] ?? '');

        if (! in_array($stan, self::ALARMUJACE, true)) {
            return $this->odwolajJesliTrzeba($stan);
        }

        if (! $this->wolnoDzwonic($stan)) {
            return false;
        }

        if (! $this->wyslij($this->tresc($wynik))) {
            return false;
        }

        Cache::put(self::KLUCZ, ['stan' => $stan, 'o' => time()], $this->pamiec());

        return true;
    }

    private function odwolajJesliTrzeba(string $stan): bool
    {
        if ($stan !== StanKolejki::SPOKOJNA) {
            return false;
        }

        $poprzedni = Cache::get(self::KLUCZ);

        if (! is_array($poprzedni)) {
            return false;
        }

        Cache::forget(self::KLUCZ);

        return $this->wyslij(sprintf(
            'kolejka wróciła do normy (poprzedni stan: %s).',
            (string) ($poprzedni['stan'] ?? 'nieznany'),
        ));
    }

    /**
     * Metoda publiczna, bo to ONA jest przedmiotem testu „czego tu nie ma".
     *
     * @param  array<string, mixed>  $wynik
     */
    public function tresc(array $wynik): string
    {
        $stan = (string) ($wynik['stan'] ?? '');

        // Teksty są STAŁYMI z tego pliku, dobieranymi przez `match` po
        // wartości z listy stałych. Nic, co przyszło z `payload` albo
        // `exception`, nie ma prawa się tu znaleźć.
        $co = match ($stan) {
            StanKolejki::ZALEGLOSC => sprintf(
                'najstarsze gotowe zadanie czeka %d s (próg %d s), oczekujących %d, zawieszonych %d. '
                .'To wygląda na workera, który NIE PRACUJE — a worker, który nie chodzi, nie zgłasza żadnego błędu.',
                (int) ($wynik['zaleglosc_sekundy'] ?? 0),
                (int) ($wynik['prog_zaleglosci_sekundy'] ?? 0),
                (int) ($wynik['oczekujace'] ?? 0),
                (int) ($wynik['zawieszone'] ?? 0),
            ),
            StanKolejki::NOWE_NIEUDANE => sprintf(
                'w ostatnich %d h padło %d zadań (w tabeli razem: %d).',
                (int) ($wynik['okno_godzin'] ?? 0),
                (int) ($wynik['nieudane_w_oknie'] ?? 0),
                (int) ($wynik['nieudane_razem'] ?? 0),
            ),
            StanKolejki::NIEDOSTEPNA => 'nie udało się odczytać stanu tabel kolejki.',
            default => 'nieznany stan kolejki.',
        };

        return implode(' ', [
            'kolejka:',
            $co,
            'Co zrobić: `php artisan kuking:sprawdz-kolejke`, potem `php artisan kuking:martwe-zadania`',
            '(niczego nie kasuje bez `--skasuj`). NIE ponawiaj zbiorczo starych zadań —',
            'żeton resetu hasła wygasa i ponowienie wysyła człowiekowi martwy link.',
        ]);
    }

    private function wolnoDzwonic(string $stan): bool
    {
        $poprzedni = Cache::get(self::KLUCZ);

        if (! is_array($poprzedni) || ($poprzedni['stan'] ?? null) !== $stan) {
            return true;
        }

        $cisza = max(1, (int) config('kuking.kolejka.cisza_godzin')) * 3600;

        return (time() - (int) ($poprzedni['o'] ?? 0)) >= $cisza;
    }

    private function wyslij(string $tresc): bool
    {
        if (blank(config('logging.channels.blad_webhook.url'))) {
            // Kanał wyłączony — tak jest DZIŚ na produkcji (odczyt listy
            // zmiennych usługi, 17.09.2026: brak `LOG_BLAD_WEBHOOK_URL`).
            // Ten sam warunek stoi w `bootstrap/app.php`, `AlarmKopii`
            // i `AlarmPolaczen`.
            return false;
        }

        try {
            Log::channel('blad_webhook')->error($tresc);
        } catch (Throwable) {
            // Nieudane powiadomienie nie ma prawa przewrócić zadania
            // harmonogramu — w roli `all` błąd harmonogramu kładł kiedyś
            // cały kontener (`docker/entrypoint.sh`).
            return false;
        }

        return true;
    }

    private function pamiec(): \DateInterval
    {
        $godziny = max(1, (int) config('kuking.kolejka.cisza_godzin')) * 3;

        return new \DateInterval('PT'.$godziny.'H');
    }
}

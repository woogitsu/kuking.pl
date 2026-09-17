<?php

declare(strict_types=1);

namespace App\Domain\Polaczenia;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * „Pula połączeń PostgreSQL się zapełnia" na webhook właściciela (issue #598).
 *
 * TEN SAM KANAŁ, CO BŁĘDY 500 I CZUJKA KOPII (`blad_webhook`, D-041).
 * Właściciel ma JEDNO miejsce, w które patrzy; drugie znaczyłoby drugie
 * miejsce do niepatrzenia. Wzorzec jest przepisany z `App\Domain\Kopie\AlarmKopii`
 * celowo — nie budujemy drugiego mechanizmu alarmowania.
 *
 * DWIE RZECZY, KTÓRYCH `AlarmKopii` NIE ROBI, A TA KLASA MUSI (issue #599)
 *
 * 1. OGRANICZENIE POWTÓRZEŃ. Czujka kopii chodzi raz na dobę, więc powtórka
 *    jest raz na dobę. Ta chodzi co godzinę, a stan „za dużo połączeń" trwa
 *    godzinami — bez ograniczenia dałby 24 identyczne wiadomości na dobę
 *    i nauczyłby ignorować kanał. Alarm wychodzi więc przy ZMIANIE stanu
 *    i nie częściej niż raz na `cisza_godzin` przy stanie niezmienionym.
 *
 * 2. POWRÓT DO ZDROWIA. Alarm bez odwołania zostawia człowieka z pytaniem
 *    „czy to jeszcze trwa". Po powrocie do `spokojny` wychodzi DOKŁADNIE
 *    JEDNA wiadomość i pamięć stanu się zeruje.
 *
 * DLACZEGO PAMIĘĆ W CACHE, A NIE W TABELI
 * Bo zapomnienie tego stanu jest nieszkodliwe: najgorsze, co się stanie po
 * wyczyszczeniu cache, to jedna nadmiarowa wiadomość o stanie, który i tak
 * trwa. Osobna tabela i migracja byłyby tu kosztem bez pokrycia.
 *
 * CO STĄD WYCHODZI — LISTA ZAMKNIĘTA
 * stan, liczba zajętych backendów, liczba dostępnych miejsc, próg i zdanie
 * mówiące, CO ZROBIĆ. Bez nazw baz, użytkowników, hostów i treści zapytań.
 */
final class AlarmPolaczen
{
    private const KLUCZ = 'kuking:polaczenia:ostatni-alarm';

    /** Stany, które są ALARMEM. `spokojny` i `nieobslugiwany` nie dzwonią. */
    private const ALARMUJACE = [
        StanPolaczenBazy::OSTRZEZENIE,
        StanPolaczenBazy::KRYTYCZNY,
        StanPolaczenBazy::NIEDOSTEPNY,
    ];

    /**
     * @param  array<string, mixed>  $wynik
     * @return bool czy wiadomość faktycznie poszła
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

    /**
     * Jedna wiadomość o powrocie do normy — i tylko wtedy, gdy wcześniej
     * cokolwiek dzwoniło. Bez tego warunku kanał dostawałby „wszystko OK"
     * co godzinę, czyli dokładnie ten szum, którego ta klasa ma unikać.
     */
    private function odwolajJesliTrzeba(string $stan): bool
    {
        if ($stan !== StanPolaczenBazy::SPOKOJNY) {
            return false;
        }

        $poprzedni = Cache::get(self::KLUCZ);

        if (! is_array($poprzedni)) {
            return false;
        }

        Cache::forget(self::KLUCZ);

        return $this->wyslij(sprintf(
            'połączenia PostgreSQL wróciły do normy (poprzedni stan: %s).',
            (string) ($poprzedni['stan'] ?? 'nieznany'),
        ));
    }

    /**
     * Metoda publiczna, bo to ONA jest przedmiotem testu „czego tu nie ma":
     * sprawdzenie treści musi dać się zrobić bez stawiania kanału logowania.
     *
     * @param  array<string, mixed>  $wynik
     */
    public function tresc(array $wynik): string
    {
        $stan = (string) ($wynik['stan'] ?? '');

        // Teksty są STAŁYMI z tego pliku, dobieranymi przez `match` po
        // wartości z listy stałych — nie składamy ich z niczego, co przyszło
        // z zewnątrz. Ta sama zasada, co w `AlarmKopii` i w handlerze webhooka.
        $co = match ($stan) {
            StanPolaczenBazy::KRYTYCZNY => sprintf(
                'zajętych backendów: %d z %d dostępnych miejsc (próg krytyczny %d).',
                (int) ($wynik['zajete_serwer'] ?? 0),
                (int) ($wynik['dostepne'] ?? 0),
                (int) ($wynik['prog_krytyczny'] ?? 0),
            ),
            StanPolaczenBazy::OSTRZEZENIE => sprintf(
                'zajętych backendów: %d, a policzony budżet szczytowy tej topologii to %d (próg ostrzegawczy %d).',
                (int) ($wynik['zajete_serwer'] ?? 0),
                (int) ($wynik['budzet_szczytowy'] ?? 0),
                (int) ($wynik['prog_ostrzegawczy'] ?? 0),
            ),
            StanPolaczenBazy::NIEDOSTEPNY => 'nie udało się odczytać stanu połączeń z serwera bazy.',
            default => 'nieznany stan połączeń.',
        };

        return implode(' ', [
            'połączenia PostgreSQL:',
            $co,
            'Co zrobić: NIE dokładaj replik ani workerów, zanim nie wiadomo, co je zajmuje.',
            'Najpierw `php artisan kuking:budzet-polaczen`, potem porównaj z budżetem w docs/DATABASE.md.',
        ]);
    }

    /**
     * Zmiana stanu dzwoni od razu. Ten sam stan — nie częściej niż raz na
     * `cisza_godzin`. Eskalacja `ostrzezenie` → `krytyczny` jest ZMIANĄ
     * stanu, więc przechodzi bez czekania; to jest cały sens tego warunku.
     */
    private function wolnoDzwonic(string $stan): bool
    {
        $poprzedni = Cache::get(self::KLUCZ);

        if (! is_array($poprzedni) || ($poprzedni['stan'] ?? null) !== $stan) {
            return true;
        }

        $cisza = max(1, (int) config('kuking.polaczenia.cisza_godzin')) * 3600;

        return (time() - (int) ($poprzedni['o'] ?? 0)) >= $cisza;
    }

    private function wyslij(string $tresc): bool
    {
        if (blank(config('logging.channels.blad_webhook.url'))) {
            // Kanał wyłączony — tak jest dziś na produkcji (stan zmierzony
            // 17.09.2026: zmienna LOG_BLAD_WEBHOOK_URL nie istnieje w usłudze).
            // Ten sam warunek stoi w `bootstrap/app.php` i w `AlarmKopii`.
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
        $godziny = max(1, (int) config('kuking.polaczenia.cisza_godzin')) * 3;

        return new \DateInterval('PT'.$godziny.'H');
    }
}

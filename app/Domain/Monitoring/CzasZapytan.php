<?php

declare(strict_types=1);

namespace App\Domain\Monitoring;

use App\Logging\WebhookBleduHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Łączny czas zapytań SQL jednego żądania HTTP — z progiem i alarmem (#599).
 *
 * DLACZEGO `whenQueryingForLongerThan`, A NIE `DB::listen()`
 * Laravel sumuje czas wszystkich zapytań na połączeniu i woła nasz handler
 * RAZ, gdy suma przekroczy próg. Łapie więc zarówno jedno wolne zapytanie,
 * jak i N+1 złożone ze stu szybkich — a nie wymaga zapisywania SQL-a
 * i parametrów, w których siedzą adresy e-mail i treści ludzi.
 *
 * CO WYCHODZI — LISTA ZAMKNIĘTA
 * metoda HTTP, WZORZEC trasy (`GET /przepisy/{recipe}`, nigdy prawdziwy
 * adres), łączny czas bazy i próg. Bez SQL, bez bindings,
 * bez query stringa, bez identyfikatora osoby.
 *
 * KIEDY LICZYMY: suma przekracza próg w trakcie żądania, ale raportujemy ją
 * dopiero przy `terminating`, czyli po wysłaniu odpowiedzi. Dwa powody:
 * w dzienniku stoi CAŁY czas bazy żądania, a nie wartość tuż nad progiem,
 * i wysyłka na webhook (do 3 s) nie wydłuża odpowiedzi człowiekowi, który
 * i tak czeka już za długo.
 *
 * TYLKO ŻĄDANIA HTTP. Komendy i worker kolejki chodzą w jednym procesie
 * przez wiele zadań; ich suma nie byłaby czasem „jednego żądania". Worker
 * zeruje sumę między zadaniami sam (`QueueServiceProvider`), ale kategoria
 * procesu dla zadań to osobny krok — tu świadomie nie rejestrujemy.
 *
 * Suma i flaga „handler już zadziałał" są zerowane przy dopasowaniu trasy
 * (`RouteMatched`) — to samo, co worker robi między zadaniami. Zapytania
 * sprzed routingu (prawie żadnych) nie wchodzą do pomiaru.
 */
final class CzasZapytan
{
    /** Połączenie, które w TYM żądaniu przekroczyło próg; `null` = nie przekroczyło. */
    private ?Connection $przekroczone = null;

    public function __construct(private readonly SeriaAlarmow $seria) {}

    /**
     * Jeden handler, jeden słuchacz `RouteMatched` i jeden `terminating`
     * na cały proces. Rejestrowanie `terminating` przy każdym przekroczeniu
     * kumulowało callbacki w procesie obsługującym wiele żądań i zgłaszało
     * stare przekroczenie przy następnym — zmierzone w `LacznyCzasZapytanTest`.
     */
    public static function zarejestruj(Application $app, int $progMs): void
    {
        if ($progMs <= 0) {
            return;
        }

        Event::listen(RouteMatched::class, static function (): void {
            foreach (DB::getConnections() as $polaczenie) {
                $polaczenie->resetTotalQueryDuration();
                $polaczenie->allowQueryDurationHandlersToRunAgain();
            }
            app(self::class)->zapomnij();
        });

        DB::whenQueryingForLongerThan($progMs, static function (Connection $polaczenie): void {
            app(self::class)->przekroczono($polaczenie);
        });

        $app->terminating(static function () use ($progMs): void {
            app(self::class)->zakoncz($progMs);
        });
    }

    public function przekroczono(Connection $polaczenie): void
    {
        $this->przekroczone = $polaczenie;
    }

    /** Przekroczenie sprzed dopasowania trasy nie należy do tego żądania. */
    public function zapomnij(): void
    {
        $this->przekroczone = null;
    }

    public function zakoncz(int $progMs): void
    {
        $polaczenie = $this->przekroczone;
        $this->przekroczone = null;

        if ($polaczenie === null || $polaczenie->totalQueryDuration() <= $progMs) {
            return;
        }

        $this->zglos($polaczenie->totalQueryDuration(), $progMs);
    }

    public function zglos(float $czasMs, int $progMs): void
    {
        $metoda = app()->bound('request') ? request()->method() : 'CLI';
        $trasa = app()->bound('request') ? '/'.ltrim(request()->route()?->uri() ?? '?', '/') : '?';
        $czas = (int) round($czasMs);

        Log::warning('Łączny czas zapytań SQL żądania przekroczył próg.', [
            'trasa' => $metoda.' '.$trasa,
            'czas_bazy_ms' => $czas,
            'prog_ms' => $progMs,
        ]);

        if (blank(config('logging.channels.blad_webhook.url'))) {
            return;
        }

        // Jeden odcisk na wzorzec trasy: wolny feed nie zasłania wolnego
        // wyszukiwania, a sto wolnych wejść na feed to jedna wiadomość.
        $this->seria->zglos(
            'czas-bazy:'.substr(sha1($metoda.' '.$trasa), 0, 12),
            (int) config('kuking.monitoring.seria_okno_minut'),
            static function (int $pominiete) use ($metoda, $trasa, $czas, $progMs): bool {
                WebhookBleduHandler::zapomnijOstatniaWysylke();
                try {
                    Log::channel('blad_webhook')->error(sprintf(
                        'wolna baza: %s %s — %d ms zapytań SQL w jednym żądaniu (próg %d ms). '
                        .'Co zrobić: sprawdź w dzienniku serwera wpisy „czas_bazy_ms” dla tej trasy; '
                        .'jedna wiadomość to pojedyncze żądanie, seria to N+1 albo brak indeksu.',
                        $metoda,
                        $trasa,
                        $czas,
                        $progMs,
                    ), ['pominiete_powtorzenia' => $pominiete]);
                } catch (Throwable) {
                    return false;
                }

                return WebhookBleduHandler::ostatniaWysylkaSieUdala() === true;
            },
        );
    }
}

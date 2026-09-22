<?php

declare(strict_types=1);

namespace App\Domain\Polaczenia;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Ile z puli połączeń PostgreSQL jest naprawdę zajęte (issue #598).
 *
 * PO CO TO ISTNIEJE
 * `max_connections` jest twardym limitem całego serwera. Po jego wyczerpaniu
 * baza nie odrzuca „trochę" ruchu — odrzuca KAŻDE nowe połączenie, łącznie
 * z połączeniem administratora, który przyszedł to naprawić. Awaria jest
 * więc skokowa, a nie stopniowa, i dlatego próg alarmowy musi stać daleko
 * przed limitem, a nie tuż przed nim.
 *
 * CO TU JEST ZMIERZONE, A CO POLICZONE — TO SĄ DWIE RÓŻNE RZECZY
 * Ta klasa MIERZY: `max_connections`, rezerwy serwera i bieżącą liczbę
 * backendów. Nie zna topologii wdrożenia i nie zgaduje jej z niczego.
 * Budżet szczytowy (ile połączeń MA prawo zająć aktualna topologia) jest
 * liczbą POLICZONĄ przez człowieka i stoi w `config/kuking.php`, razem
 * z wyprowadzeniem w `docs/DATABASE.md`. Rozdzielenie jest celowe: pomiar
 * ma prawo zaprzeczyć obliczeniu, a obliczenie nie ma prawa udawać pomiaru.
 *
 * DLACZEGO LICZYMY BACKENDY CAŁEGO SERWERA, A NIE TYLKO NASZEJ BAZY
 * Bo `max_connections` jest wspólne dla całej instancji. Baza sąsiada
 * (albo `pg_restore` puszczony obok) zajmuje tę samą pulę. Liczba dla
 * naszej bazy jest w wyniku osobno, bo mówi, czy to MY rośniemy.
 *
 * DLACZEGO `backend_type = 'client backend'`
 * `pg_stat_activity` pokazuje też procesy wewnętrzne serwera (autovacuum
 * launcher, walwriter, checkpointer). One nie zajmują miejsc z puli
 * `max_connections` i wliczanie ich zawyżałoby wynik o stałą wartość —
 * czyli przesuwałoby próg w stronę fałszywych alarmów.
 *
 * CZEGO TA KLASA NIE ROBI
 * Nie zabija połączeń, nie zmienia ustawień serwera i nie zna nazw
 * użytkowników ani treści zapytań. Do wyniku wchodzą wyłącznie liczby.
 */
final class StanPolaczenBazy
{
    /** Połączenie nie jest PostgreSQL-em — nie ma czego mierzyć. */
    public const NIEOBSLUGIWANY = 'nieobslugiwany';

    /** Nie udało się odpytać serwera (padł, brak uprawnień, zerwana sieć). */
    public const NIEDOSTEPNY = 'niedostepny';

    /** Wykorzystanie mieści się w policzonym budżecie z zapasem. */
    public const SPOKOJNY = 'spokojny';

    /** Powyżej progu ostrzegawczego: budżet przestał opisywać rzeczywistość. */
    public const OSTRZEZENIE = 'ostrzezenie';

    /** Powyżej progu krytycznego: pula zmierza do wyczerpania. */
    public const KRYTYCZNY = 'krytyczny';

    /**
     * @return array{
     *     stan: string,
     *     max_connections: int|null,
     *     rezerwa_superusera: int|null,
     *     rezerwa_zwykla: int|null,
     *     dostepne: int|null,
     *     zajete_serwer: int|null,
     *     zajete_baza: int|null,
     *     bezczynne_baza: int|null,
     *     aktywne_baza: int|null,
     *     w_transakcji_baza: int|null,
     *     budzet_szczytowy: int,
     *     prog_ostrzegawczy: int,
     *     prog_krytyczny: int,
     *     baza: string|null
     * }
     */
    public function sprawdz(?ConnectionInterface $polaczenie = null): array
    {
        $polaczenie ??= DB::connection();

        $pusty = [
            'max_connections' => null,
            'rezerwa_superusera' => null,
            'rezerwa_zwykla' => null,
            'dostepne' => null,
            'zajete_serwer' => null,
            'zajete_baza' => null,
            'bezczynne_baza' => null,
            'aktywne_baza' => null,
            'w_transakcji_baza' => null,
            'budzet_szczytowy' => $this->budzet(),
            'prog_ostrzegawczy' => $this->progOstrzegawczy(),
            'prog_krytyczny' => $this->progKrytyczny(),
            'baza' => null,
        ];

        if ($polaczenie->getDriverName() !== 'pgsql') {
            return ['stan' => self::NIEOBSLUGIWANY] + $pusty;
        }

        try {
            $ustawienia = $this->ustawienia($polaczenie);
            $liczby = $this->liczby($polaczenie);
        } catch (Throwable) {
            // Świadomie bez treści wyjątku: komunikat sterownika potrafi
            // wnieść w siebie DSN razem z użytkownikiem i hostem, a ten
            // wynik trafia do logu i na webhook (ta sama zasada, co
            // w App\Logging\WebhookBleduHandler po audycie A6-01).
            return ['stan' => self::NIEDOSTEPNY] + $pusty;
        }

        $dostepne = max(
            0,
            $ustawienia['max_connections'] - $ustawienia['rezerwa_superusera'] - $ustawienia['rezerwa_zwykla'],
        );

        $wynik = [
            'max_connections' => $ustawienia['max_connections'],
            'rezerwa_superusera' => $ustawienia['rezerwa_superusera'],
            'rezerwa_zwykla' => $ustawienia['rezerwa_zwykla'],
            'dostepne' => $dostepne,
            'zajete_serwer' => $liczby['serwer'],
            'zajete_baza' => $liczby['baza'],
            'bezczynne_baza' => $liczby['bezczynne'],
            'aktywne_baza' => $liczby['aktywne'],
            'w_transakcji_baza' => $liczby['w_transakcji'],
            'budzet_szczytowy' => $this->budzet(),
            'prog_ostrzegawczy' => $this->progOstrzegawczy(),
            'prog_krytyczny' => $this->progKrytyczny(),
            'baza' => $liczby['nazwa_bazy'],
        ];

        return ['stan' => $this->ocen($liczby['serwer'], $dostepne)] + $wynik;
    }

    /**
     * Próg krytyczny jest sprawdzany PRZED ostrzegawczym. Odwrotna kolejność
     * zwracałaby „ostrzeżenie" przy stanie krytycznym, gdyby ktoś kiedyś
     * ustawił progi tak, że krytyczny jest niższy od ostrzegawczego.
     *
     * `min(prog_krytyczny, dostepne)` jest tu po to, żeby na serwerze
     * z małym `max_connections` (lokalny klaster deweloperski, kontener CI)
     * próg krytyczny nigdy nie stanął POWYŻEJ twardego limitu — inaczej
     * pula wyczerpałaby się, zanim cokolwiek zdążyłoby zaalarmować.
     */
    private function ocen(int $zajete, int $dostepne): string
    {
        $krytyczny = min($this->progKrytyczny(), $dostepne);

        if ($zajete >= $krytyczny) {
            return self::KRYTYCZNY;
        }

        if ($zajete >= $this->progOstrzegawczy()) {
            return self::OSTRZEZENIE;
        }

        return self::SPOKOJNY;
    }

    /**
     * @return array{max_connections: int, rezerwa_superusera: int, rezerwa_zwykla: int}
     */
    private function ustawienia(ConnectionInterface $polaczenie): array
    {
        // Jedno zapytanie do `pg_settings` zamiast trzech `SHOW`: `SHOW`
        // rzuca błędem na nieznanej nazwie, a `reserved_connections` istnieje
        // dopiero od PostgreSQL 16. Repozytorium dopuszcza 16+, ale skrypt
        // odtworzenia bywa uruchamiany także na starszym serwerze i wtedy
        // brak tej nazwy nie ma prawa wywrócić pomiaru.
        $wiersze = $polaczenie->select(
            "SELECT name, setting FROM pg_settings
             WHERE name IN ('max_connections', 'superuser_reserved_connections', 'reserved_connections')",
        );

        $wartosci = [];

        foreach ($wiersze as $wiersz) {
            $wartosci[(string) $wiersz->name] = (int) $wiersz->setting;
        }

        if (! isset($wartosci['max_connections'])) {
            throw new RuntimeException('pg_settings nie zwrocilo max_connections');
        }

        return [
            'max_connections' => $wartosci['max_connections'],
            'rezerwa_superusera' => $wartosci['superuser_reserved_connections'] ?? 0,
            'rezerwa_zwykla' => $wartosci['reserved_connections'] ?? 0,
        ];
    }

    /**
     * @return array{serwer: int, baza: int, bezczynne: int, aktywne: int, w_transakcji: int, nazwa_bazy: string}
     */
    private function liczby(ConnectionInterface $polaczenie): array
    {
        $wiersz = $polaczenie->selectOne(
            "SELECT
                 current_database() AS nazwa_bazy,
                 count(*) FILTER (WHERE backend_type = 'client backend') AS serwer,
                 count(*) FILTER (WHERE backend_type = 'client backend' AND datname = current_database()) AS baza,
                 count(*) FILTER (WHERE backend_type = 'client backend' AND datname = current_database() AND state = 'idle') AS bezczynne,
                 count(*) FILTER (WHERE backend_type = 'client backend' AND datname = current_database() AND state = 'active') AS aktywne,
                 count(*) FILTER (WHERE backend_type = 'client backend' AND datname = current_database() AND state LIKE 'idle in transaction%') AS w_transakcji
             FROM pg_stat_activity",
        );

        return [
            'serwer' => (int) $wiersz->serwer,
            'baza' => (int) $wiersz->baza,
            'bezczynne' => (int) $wiersz->bezczynne,
            'aktywne' => (int) $wiersz->aktywne,
            'w_transakcji' => (int) $wiersz->w_transakcji,
            'nazwa_bazy' => (string) $wiersz->nazwa_bazy,
        ];
    }

    private function budzet(): int
    {
        return max(1, (int) config('kuking.polaczenia.budzet_szczytowy'));
    }

    private function progOstrzegawczy(): int
    {
        return max(1, (int) config('kuking.polaczenia.prog_ostrzegawczy'));
    }

    private function progKrytyczny(): int
    {
        return max(1, (int) config('kuking.polaczenia.prog_krytyczny'));
    }
}

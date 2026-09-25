<?php

declare(strict_types=1);

namespace App\Domain\Kolejka;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Czy kolejka w ogóle jeszcze pracuje (issue #599).
 *
 * CZEGO NIE ODPOWIADA `/health`, A ODPOWIADA TO
 * `/health` liczy wiersze w `failed_jobs` i przy liczbie większej od zera
 * stawia serwis w `degraded`. To jest sprawdzenie STANU, nie ZDARZENIA —
 * i dlatego dziś nie mówi już nic. Na produkcji leżą cztery zadania nieudane
 * z 9 września 2026; od tamtej pory `/health` jest w `degraded` NIEPRZERWANIE,
 * więc piąte zadanie, które padnie dziś w nocy, nie zmieni w tej odpowiedzi
 * ani jednego znaku. Sygnał, który świeci zawsze, nie niesie informacji.
 *
 * Ta klasa patrzy na dwie rzeczy, których `/health` nie mierzy wcale:
 *
 * 1. ZDARZENIE, nie stan: ile zadań padło w OSTATNICH godzinach. Stare,
 *    znane i nierozliczone zadania nie zagłuszają nowej awarii.
 *
 * 2. ZALEGŁOŚĆ: jak długo czeka najstarsze zadanie gotowe do wzięcia.
 *    To jest jedyny sygnał, który zauważa MARTWEGO WORKERA — bo worker,
 *    który nie chodzi, nie generuje żadnego błędu. Awaria 5–6 września 2026
 *    (3,5 godziny niedostępności, `docker/entrypoint.sh`) zaczęła się
 *    dokładnie od procesu, który po cichu przestał być wskrzeszany.
 *
 * DLACZEGO OKNO CZASOWE, A NIE PAMIĘĆ OSTATNIEGO ODCZYTU
 * Pamięć musiałaby gdzieś mieszkać, a jedyne tanie miejsce to cache —
 * który `docker/entrypoint.sh` czyści przy KAŻDYM starcie kontenera.
 * Czujka oparta o pamięć krzyczałaby więc „nowe nieudane zadania" po każdym
 * wdrożeniu, czyli nauczyłaby się ignorować samą siebie. Okno czasowe liczy
 * się z `failed_at`, jest bezstanowe i przeżywa restart.
 *
 * CZEGO TA KLASA NIE ROBI
 * Nie ponawia, nie kasuje i nie czyta `payload` ani `exception`. Treść
 * wyjątku bywa pełnym śladem stosu z argumentami wywołań — dokładnie tym,
 * czego kanał alarmowy unika (audyt A6-01). Do wyniku wchodzą liczby.
 */
final class StanKolejki
{
    /** Nie udało się odpytać tabel kolejki. */
    public const NIEDOSTEPNA = 'niedostepna';

    /** Najstarsze gotowe zadanie czeka za długo albo coś wisi zarezerwowane. */
    public const ZALEGLOSC = 'zaleglosc';

    /** W oknie czasowym padły nowe zadania. */
    public const NOWE_NIEUDANE = 'nowe-nieudane';

    /** Kolejka pracuje, nic nowego nie padło. */
    public const SPOKOJNA = 'spokojna';

    /**
     * @return array{
     *     stan: string,
     *     oczekujace: int|null,
     *     zaleglosc_sekundy: int|null,
     *     zawieszone: int|null,
     *     nieudane_w_oknie: int|null,
     *     nieudane_razem: int|null,
     *     okno_godzin: int,
     *     prog_zaleglosci_sekundy: int,
     *     prog_zawieszenia_sekundy: int
     * }
     */
    public function sprawdz(?Carbon $teraz = null): array
    {
        $teraz ??= Carbon::now();

        $progi = [
            'okno_godzin' => $this->oknoGodzin(),
            'prog_zaleglosci_sekundy' => $this->progZaleglosci(),
            'prog_zawieszenia_sekundy' => $this->progZawieszenia(),
        ];

        try {
            $kolejka = $this->zKolejki($teraz);
            $nieudane = $this->zNieudanych($teraz);
        } catch (Throwable) {
            // Bez treści wyjątku: komunikat sterownika potrafi wnieść w siebie
            // DSN, a ten wynik trafia na zewnętrzny webhook (audyt A6-01).
            return [
                'stan' => self::NIEDOSTEPNA,
                'oczekujace' => null,
                'zaleglosc_sekundy' => null,
                'zawieszone' => null,
                'nieudane_w_oknie' => null,
                'nieudane_razem' => null,
            ] + $progi;
        }

        return [
            'stan' => $this->ocen($kolejka, $nieudane),
            'oczekujace' => $kolejka['oczekujace'],
            'zaleglosc_sekundy' => $kolejka['zaleglosc_sekundy'],
            'zawieszone' => $kolejka['zawieszone'],
            'nieudane_w_oknie' => $nieudane['w_oknie'],
            'nieudane_razem' => $nieudane['razem'],
        ] + $progi;
    }

    /** Kolejki, które zna `docker/entrypoint.sh` (`QUEUE_NAMES`); reszta idzie do „inne". */
    public const ZNANE_KOLEJKI = ['high', 'default', 'media', 'low'];

    /**
     * Te same liczby co `sprawdz()`, ale OSOBNO dla każdej kolejki (issue #599).
     *
     * Po co: `media` to zadania ciężkie (dekodowanie zdjęć), `high` to
     * potwierdzenia adresu i resety hasła. Suma z `sprawdz()` nie odróżni
     * „zdjęcia czekają minutę" od „reset hasła czeka minutę", a to są dwie
     * różne pilności. Alarm zostaje na sumie — ta rozbicie jest do szeregu
     * czasowego w dzienniku i do decyzji o osobnym workerze media (#595).
     *
     * Nazwy spoza listy znanych kolejek łączymy w `inne`, żeby do dziennika
     * nie trafiło nic poza stałym, krótkim słownikiem. `null` znaczy, że
     * tabel nie dało się odpytać.
     *
     * @return array<string, array{oczekujace: int, zaleglosc_sekundy: int, zawieszone: int, nieudane_w_oknie: int}>|null
     */
    public function poKolejkach(?Carbon $teraz = null): ?array
    {
        $teraz ??= Carbon::now();
        $znacznik = $teraz->getTimestamp();
        $od = $teraz->copy()->subHours($this->oknoGodzin());

        $wynik = [];

        foreach (self::ZNANE_KOLEJKI as $nazwa) {
            $wynik[$nazwa] = ['oczekujace' => 0, 'zaleglosc_sekundy' => 0, 'zawieszone' => 0, 'nieudane_w_oknie' => 0];
        }

        try {
            $kolejki = DB::table('jobs')->selectRaw(
                'queue,
                 count(*) FILTER (WHERE reserved_at IS NULL AND available_at <= ?) AS oczekujace,
                 coalesce(max(? - available_at) FILTER (WHERE reserved_at IS NULL AND available_at <= ?), 0) AS zaleglosc,
                 count(*) FILTER (WHERE reserved_at IS NOT NULL AND reserved_at <= ?) AS zawieszone',
                [$znacznik, $znacznik, $znacznik, $znacznik - $this->progZawieszenia()],
            )->groupBy('queue')->get();

            $nieudane = DB::table('failed_jobs')
                ->selectRaw('queue, count(*) AS w_oknie')
                ->where('failed_at', '>=', $od)
                ->groupBy('queue')
                ->get();
        } catch (Throwable) {
            return null;
        }

        foreach ($kolejki as $wiersz) {
            $nazwa = in_array($wiersz->queue, self::ZNANE_KOLEJKI, true) ? $wiersz->queue : 'inne';
            $wynik[$nazwa] ??= ['oczekujace' => 0, 'zaleglosc_sekundy' => 0, 'zawieszone' => 0, 'nieudane_w_oknie' => 0];
            $wynik[$nazwa]['oczekujace'] += (int) $wiersz->oczekujace;
            $wynik[$nazwa]['zaleglosc_sekundy'] = max($wynik[$nazwa]['zaleglosc_sekundy'], (int) $wiersz->zaleglosc);
            $wynik[$nazwa]['zawieszone'] += (int) $wiersz->zawieszone;
        }

        foreach ($nieudane as $wiersz) {
            $nazwa = in_array($wiersz->queue, self::ZNANE_KOLEJKI, true) ? $wiersz->queue : 'inne';
            $wynik[$nazwa] ??= ['oczekujace' => 0, 'zaleglosc_sekundy' => 0, 'zawieszone' => 0, 'nieudane_w_oknie' => 0];
            $wynik[$nazwa]['nieudane_w_oknie'] += (int) $wiersz->w_oknie;
        }

        return $wynik;
    }

    /**
     * ZALEGŁOŚĆ WYGRYWA Z NOWYMI NIEUDANYMI, i to nie jest kolejność losowa:
     * kilka zadań, które padły, znaczy „coś jest zepsute". Zaległość znaczy
     * „NIC NIE PRACUJE" — wtedy nie padnie już nawet to, co miało paść.
     *
     * @param  array{oczekujace: int, zaleglosc_sekundy: int, zawieszone: int}  $kolejka
     * @param  array{w_oknie: int, razem: int}  $nieudane
     */
    private function ocen(array $kolejka, array $nieudane): string
    {
        if ($kolejka['zaleglosc_sekundy'] >= $this->progZaleglosci() || $kolejka['zawieszone'] > 0) {
            return self::ZALEGLOSC;
        }

        if ($nieudane['w_oknie'] > 0) {
            return self::NOWE_NIEUDANE;
        }

        return self::SPOKOJNA;
    }

    /**
     * @return array{oczekujace: int, zaleglosc_sekundy: int, zawieszone: int}
     */
    private function zKolejki(Carbon $teraz): array
    {
        $znacznik = $teraz->getTimestamp();

        // `available_at <= teraz` — zadanie odłożone na później (backoff po
        // nieudanej próbie) NIE JEST zaległością. Bez tego warunku każdy
        // `--backoff=300` wyglądałby jak martwy worker.
        $wiersz = DB::table('jobs')->selectRaw(
            'count(*) FILTER (WHERE reserved_at IS NULL AND available_at <= ?) AS oczekujace,
             coalesce(max(? - available_at) FILTER (WHERE reserved_at IS NULL AND available_at <= ?), 0) AS zaleglosc,
             count(*) FILTER (WHERE reserved_at IS NOT NULL AND reserved_at <= ?) AS zawieszone',
            [$znacznik, $znacznik, $znacznik, $znacznik - $this->progZawieszenia()],
        )->first();

        return [
            'oczekujace' => (int) $wiersz->oczekujace,
            'zaleglosc_sekundy' => max(0, (int) $wiersz->zaleglosc),
            'zawieszone' => (int) $wiersz->zawieszone,
        ];
    }

    /**
     * @return array{w_oknie: int, razem: int}
     */
    private function zNieudanych(Carbon $teraz): array
    {
        $od = $teraz->copy()->subHours($this->oknoGodzin());

        $wiersz = DB::table('failed_jobs')->selectRaw(
            'count(*) AS razem, count(*) FILTER (WHERE failed_at >= ?) AS w_oknie',
            [$od],
        )->first();

        return [
            'w_oknie' => (int) $wiersz->w_oknie,
            'razem' => (int) $wiersz->razem,
        ];
    }

    private function oknoGodzin(): int
    {
        return max(1, (int) config('kuking.kolejka.okno_nieudanych_godzin'));
    }

    private function progZaleglosci(): int
    {
        return max(1, (int) config('kuking.kolejka.prog_zaleglosci_sekundy'));
    }

    private function progZawieszenia(): int
    {
        return max(1, (int) config('kuking.kolejka.prog_zawieszenia_sekundy'));
    }
}

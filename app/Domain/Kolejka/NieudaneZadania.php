<?php

declare(strict_types=1);

namespace App\Domain\Kolejka;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * CO stoi w `failed_jobs` — w postaci, którą wolno pokazać w przeglądarce.
 *
 * ────────────────────────────────────────────────────────────────────────
 *  PO CO TO ISTNIEJE, SKORO SĄ JUŻ DWIE KOMENDY
 * ────────────────────────────────────────────────────────────────────────
 *
 * `/health` mówi `degraded` i podaje POWÓD `zadania_nieudane`, ale ani
 * liczby, ani klasy zadania nie podaje nigdzie (`HealthController::
 * sprawdzKolejke()` — publiczna odpowiedź niesie sam kod). Odpowiedź na
 * pytanie „KTÓRE zadanie padło" mają dziś wyłącznie `kuking:martwe-zadania`
 * i `kuking:kto-nie-dostal-listu`, czyli komendy z POWŁOKI SERWERA.
 *
 * Na Railway powłoki nie ma: `proc_open` jest wyłączony w `docker/php.ini`
 * (hardening, patrz `routes/console.php`), a dostępu do bazy produkcyjnej
 * właściciel nie ma. Stan `degraded` trwał więc od 9 września 2026 i nikt
 * nie umiał powiedzieć, co go trzyma — nie dlatego, że nie ma narzędzia,
 * tylko dlatego, że jedyne narzędzie stoi po drugiej stronie ściany.
 *
 * Ta klasa daje TĘ SAMĄ odpowiedź drogą, którą właściciel ma otwartą:
 * przez zalogowanie się do panelu. Niczego nie ponawia i niczego nie kasuje
 * — decyzja o wyrzuceniu wiersza należy do człowieka i zapada w komendzie,
 * bo `failed_jobs` to jedyny ślad po awarii (`MartweZadania`).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CZEGO STĄD NIE WYCHODZI NIGDY: ŁADUNEK I TREŚĆ WYJĄTKU
 * ────────────────────────────────────────────────────────────────────────
 *
 * `failed_jobs.payload` niesie w `data.command` zserializowane
 * `SendQueuedNotifications`, a w nim ŻYWY ŻETON logowania albo resetu hasła
 * (zmierzone w `MartweZadaniaTest`). `failed_jobs.exception` to ślad stosu,
 * a ślad stosu w Laravelu potrafi nieść argumenty wywołań — czyli ten sam
 * żeton i adres e-mail (`WebhookBleduHandler`, audyt A6-01).
 *
 * Dlatego stąd wychodzą WYŁĄCZNIE dwie nazwy klas, nazwa kolejki i liczby:
 *
 *  - `klasa`   — `payload.displayName`, czyli nazwa klasy zadania;
 *  - `wyjatek` — sama nazwa klasy wyjątku, odcięta przed pierwszym `:`.
 *    KOMUNIKAT wyjątku zostaje w bazie i nie jest tu nawet czytany;
 *  - `kolejka` — kolumna `failed_jobs.queue`, przepuszczona przez
 *    `nazwaKolejki()` (krótki identyfikator albo `?`).
 *
 * Obie przechodzą przez `nazwaKlasy()`, która przepuszcza tylko kształt
 * identyfikatora PHP z ukośnikami. To nie jest ozdobnik: gdyby kiedykolwiek
 * do tych kolumn trafiło coś innego niż nasz własny `displayName`, ekran
 * dostałby `?`, a nie cudzy tekst.
 *
 * Nazwa klasy wyjątku wystarcza do postawienia diagnozy, o którą tu chodzi:
 * `TransportException` to awaria dostawcy poczty, `QueryException` to baza,
 * `RuntimeException` z `ProcessUploadedImage` to zdjęcia. Różnica między
 * tymi trzema decyduje o tym, gdzie się szuka — a komunikat, który tę
 * różnicę doprecyzowuje, kosztowałby ryzyko wyniesienia żetonu.
 */
final class NieudaneZadania
{
    /** Ile grup najwyżej pokazujemy — reszta zostaje policzona w `poza_lista`. */
    public const GRUP_NA_EKRAN = 50;

    /** Nazwa dla wiersza, z którego nie dało się odczytać klasy. */
    public const NIEZNANA = '?';

    /**
     * @return array{
     *     razem: int,
     *     grupy: list<array{klasa: string, nazwa: string, wyjatek: string, nazwa_wyjatku: string, kolejka: string, ile: int, najstarsze: ?Carbon, najnowsze: ?Carbon}>,
     *     poza_lista: int,
     *     odczytane: bool
     * }
     */
    public function pogrupowane(): array
    {
        try {
            $wiersze = DB::table('failed_jobs')
                ->select(['queue', 'payload', 'exception', 'failed_at'])
                ->orderByDesc('failed_at')
                ->get();
        } catch (Throwable) {
            // Bez treści wyjątku: komunikat sterownika potrafi wnieść w siebie
            // DSN. Ta sama zasada co w `StanKolejki::sprawdz()`.
            return ['razem' => 0, 'grupy' => [], 'poza_lista' => 0, 'odczytane' => false];
        }

        $grupy = [];

        foreach ($wiersze as $wiersz) {
            $klasa = $this->klasaZadania($wiersz->payload ?? null);
            $wyjatek = $this->klasaWyjatku($wiersz->exception ?? null);
            $kolejka = $this->nazwaKolejki($wiersz->queue ?? null);
            $kiedy = $this->kiedy($wiersz->failed_at ?? null);

            $klucz = $klasa."\0".$wyjatek."\0".$kolejka;

            if (! array_key_exists($klucz, $grupy)) {
                $grupy[$klucz] = [
                    'klasa' => $klasa,
                    'nazwa' => $this->krotka($klasa),
                    'wyjatek' => $wyjatek,
                    'nazwa_wyjatku' => $this->krotka($wyjatek),
                    'kolejka' => $kolejka,
                    'ile' => 0,
                    'najstarsze' => null,
                    'najnowsze' => null,
                ];
            }

            $grupy[$klucz]['ile']++;

            if ($kiedy instanceof Carbon) {
                $najstarsze = $grupy[$klucz]['najstarsze'];
                $najnowsze = $grupy[$klucz]['najnowsze'];

                $grupy[$klucz]['najstarsze'] = $najstarsze === null || $kiedy->lt($najstarsze) ? $kiedy : $najstarsze;
                $grupy[$klucz]['najnowsze'] = $najnowsze === null || $kiedy->gt($najnowsze) ? $kiedy : $najnowsze;
            }
        }

        // Najnowsza awaria na górze: człowiek wchodzi tu, żeby zobaczyć, co
        // się dzieje TERAZ. Grupy bez czytelnej daty lądują na końcu, ale
        // NIE ZNIKAJĄ — „nie wiem, co to jest" jest informacją, nie brakiem.
        $lista = array_values($grupy);

        usort($lista, function (array $a, array $b): int {
            $czasA = $a['najnowsze'] instanceof Carbon ? $a['najnowsze']->getTimestamp() : PHP_INT_MIN;
            $czasB = $b['najnowsze'] instanceof Carbon ? $b['najnowsze']->getTimestamp() : PHP_INT_MIN;

            return $czasB <=> $czasA ?: $b['ile'] <=> $a['ile'];
        });

        $pokazane = array_slice($lista, 0, self::GRUP_NA_EKRAN);

        return [
            'razem' => $wiersze->count(),
            'grupy' => $pokazane,
            'poza_lista' => count($lista) - count($pokazane),
            'odczytane' => true,
        ];
    }

    /**
     * Klasa zadania z `payload.displayName` — bez dotykania `data.command`.
     *
     * `unserialize()` nie jest tu wywoływane ani razu. `MartweZadania` musi
     * odtwarzać obiekt, bo liczy OSOBY; temu ekranowi wystarcza nazwa, więc
     * nie otwiera ładunku wcale i nie ma czego z niego wynieść.
     */
    private function klasaZadania(mixed $payload): string
    {
        if (! is_string($payload) || $payload === '') {
            return self::NIEZNANA;
        }

        $odczyt = json_decode($payload, true);

        if (! is_array($odczyt)) {
            return self::NIEZNANA;
        }

        return $this->nazwaKlasy($odczyt['displayName'] ?? null);
    }

    /**
     * Sama nazwa klasy wyjątku — wszystko po pierwszym `:` jest odcinane
     * PRZED jakąkolwiek inną obróbką, bo tam zaczyna się komunikat.
     */
    private function klasaWyjatku(mixed $exception): string
    {
        if (! is_string($exception) || $exception === '') {
            return self::NIEZNANA;
        }

        $pierwszaLinia = strtok($exception, "\r\n");

        if ($pierwszaLinia === false) {
            return self::NIEZNANA;
        }

        $dwukropek = strpos($pierwszaLinia, ':');

        return $this->nazwaKlasy($dwukropek === false ? $pierwszaLinia : substr($pierwszaLinia, 0, $dwukropek));
    }

    /**
     * Przepuszcza WYŁĄCZNIE kształt nazwy klasy PHP. Cokolwiek innego
     * (zdanie, adres, ślad stosu bez dwukropka) wychodzi jako `?`.
     */
    private function nazwaKlasy(mixed $wartosc): string
    {
        if (! is_string($wartosc)) {
            return self::NIEZNANA;
        }

        $wartosc = trim($wartosc);

        if (preg_match('/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/', $wartosc) !== 1) {
            return self::NIEZNANA;
        }

        return ltrim($wartosc, '\\');
    }

    /**
     * Nazwa kolejki (`default`, `mail`…) — tylko krótki identyfikator.
     * Kolumna jest tekstem wpisanym przez kod wysyłający zadanie, więc
     * dostaje tę samą białą listę kształtu co nazwy klas: cokolwiek
     * dłuższego albo z innymi znakami wychodzi jako `?`.
     */
    private function nazwaKolejki(mixed $wartosc): string
    {
        if (! is_string($wartosc) || preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $wartosc) !== 1) {
            return self::NIEZNANA;
        }

        return $wartosc;
    }

    /** Krótka nazwa do nagłówka; pełna zostaje w wierszu obok. */
    private function krotka(string $klasa): string
    {
        if ($klasa === self::NIEZNANA) {
            return self::NIEZNANA;
        }

        $ostatni = strrchr($klasa, '\\');

        return $ostatni === false ? $klasa : substr($ostatni, 1);
    }

    private function kiedy(mixed $failedAt): ?Carbon
    {
        if (! is_string($failedAt) && ! $failedAt instanceof Carbon) {
            return null;
        }

        try {
            return $failedAt instanceof Carbon ? $failedAt : Carbon::parse($failedAt);
        } catch (Throwable) {
            return null;
        }
    }
}

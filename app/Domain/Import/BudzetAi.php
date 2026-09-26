<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Support\Czas;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Dzienny i miesięczny budżet płatnego modelu OpenAI — w PostgreSQL (D-297).
 *
 * REZERWACJA PRZED WYWOŁANIEM, ROZLICZENIE PO NIM
 *
 *     zarezerwuj(szacunek)   -- transakcja, SELECT … FOR UPDATE na wierszu dnia
 *     KlientLuna::odczytaj() -- żądanie
 *     rozlicz(rezerwacja, koszt z usage)
 *
 * Szacunek to NAJGORSZY przypadek: szacowane tokeny wejścia × cena wejścia
 * + sufit tokenów wyjścia (z rozumowaniem) × cena wyjścia. Dzięki temu suma
 * rezerwacji nigdy nie wypuści wywołania, które mogłoby przebić limit.
 *
 * BLOKADA WIERSZA DNIA SZEREGUJE WSZYSTKIE REZERWACJE — dwa odczyty naraz
 * czekają na siebie i drugi widzi już rezerwację pierwszego. Miesiąc liczymy
 * sumą wierszy od pierwszego dnia miesiąca: przeszłe dni się nie zmieniają
 * (poza rozliczeniem odczytu, który przeszedł przez północ — i to zawsze
 * w DÓŁ albo bez zmiany, bo koszt nie przekracza rezerwacji).
 *
 * BRAK `usage` = REZERWACJA ZOSTAJE JAKO WYDANA. Błąd sieci po wysłaniu
 * żądania nie znaczy, że dostawca go nie policzył. Lepiej zawyżyć niż
 * przekroczyć (wymaganie z pilota #814/#912).
 *
 * „DZIEŃ" TO DZIEŃ W POLSCE (`Czas::dzisiajData()`), nie w UTC.
 */
final class BudzetAi
{
    public const ODMOWA_DZIEN = 'dzien';

    public const ODMOWA_MIESIAC = 'miesiac';

    private const KLUCZ_OSTRZEZENIA = 'kuking:import:budzet-ostrzezenie:';

    /**
     * @return Rezerwacja|string rezerwacja albo powód odmowy (`ODMOWA_*`)
     */
    public function zarezerwuj(int $mikroUsd): Rezerwacja|string
    {
        $mikroUsd = max(0, $mikroUsd);
        $dzien = Czas::dzisiajData();

        $wynik = DB::transaction(function () use ($mikroUsd, $dzien): Rezerwacja|string {
            DB::statement(
                'INSERT INTO ai_budzet_dzienny (dzien, created_at, updated_at) VALUES (?, now(), now()) ON CONFLICT (dzien) DO NOTHING',
                [$dzien],
            );

            $wiersz = DB::selectOne(
                'SELECT zarezerwowano_mikrousd, wydano_mikrousd FROM ai_budzet_dzienny WHERE dzien = ? FOR UPDATE',
                [$dzien],
            );

            $dzisiaj = (int) $wiersz->zarezerwowano_mikrousd + (int) $wiersz->wydano_mikrousd;

            if ($dzisiaj + $mikroUsd > self::limitDzienny()) {
                return self::ODMOWA_DZIEN;
            }

            if ($this->sumaMiesiaca($dzien) + $mikroUsd > self::limitMiesieczny()) {
                return self::ODMOWA_MIESIAC;
            }

            DB::update(
                'UPDATE ai_budzet_dzienny SET zarezerwowano_mikrousd = zarezerwowano_mikrousd + ?, liczba_wywolan = liczba_wywolan + 1, updated_at = now() WHERE dzien = ?',
                [$mikroUsd, $dzien],
            );

            return new Rezerwacja($dzien, $mikroUsd);
        });

        if ($wynik instanceof Rezerwacja) {
            $this->ostrzezJesliBlisko($dzien);
        }

        return $wynik;
    }

    /**
     * Zamienia rezerwację na wydatek. `null` = nie znamy faktycznego kosztu,
     * więc cała rezerwacja zostaje policzona jako wydana.
     *
     * Faktyczny koszt WIĘKSZY od rezerwacji (dostawca policzył więcej, niż
     * zakładał szacunek wejścia) też jest wpisywany w całości — budżet ma
     * mówić prawdę o wydatkach, nawet jeśli ta prawda przekracza limit.
     */
    public function rozlicz(Rezerwacja $rezerwacja, ?int $faktycznyMikroUsd): int
    {
        $wydano = $faktycznyMikroUsd === null ? $rezerwacja->mikroUsd : max(0, $faktycznyMikroUsd);

        DB::update(
            'UPDATE ai_budzet_dzienny SET zarezerwowano_mikrousd = GREATEST(0, zarezerwowano_mikrousd - ?), '
            .'wydano_mikrousd = wydano_mikrousd + ?, updated_at = now() WHERE dzien = ?',
            [$rezerwacja->mikroUsd, $wydano, $rezerwacja->dzien],
        );

        return $wydano;
    }

    /**
     * Zwraca rezerwację bez wydatku — WYŁĄCZNIE wtedy, gdy żądanie na pewno
     * nie wyszło (brak konfiguracji, zgoda cofnięta przed wysyłką).
     */
    public function zwolnij(Rezerwacja $rezerwacja): void
    {
        DB::update(
            'UPDATE ai_budzet_dzienny SET zarezerwowano_mikrousd = GREATEST(0, zarezerwowano_mikrousd - ?), '
            .'liczba_wywolan = GREATEST(0, liczba_wywolan - 1), updated_at = now() WHERE dzien = ?',
            [$rezerwacja->mikroUsd, $rezerwacja->dzien],
        );
    }

    /**
     * Czy następny odczyt tego zadania by się zmieścił — dla ekranu, ZANIM
     * ktoś kliknie (D-297: informacja nad przyciskiem, nie po kliknięciu).
     * Bez blokady: to jest podpowiedź, rozstrzyga `zarezerwuj()`.
     */
    public function brakMiejscaNa(int $mikroUsd): ?string
    {
        $dzien = Czas::dzisiajData();
        $wiersz = DB::table('ai_budzet_dzienny')->where('dzien', $dzien)->first();
        $dzisiaj = $wiersz === null ? 0 : (int) $wiersz->zarezerwowano_mikrousd + (int) $wiersz->wydano_mikrousd;

        if ($dzisiaj + $mikroUsd > self::limitDzienny()) {
            return self::ODMOWA_DZIEN;
        }

        if ($this->sumaMiesiaca($dzien) + $mikroUsd > self::limitMiesieczny()) {
            return self::ODMOWA_MIESIAC;
        }

        return null;
    }

    /** @return array{dzien: string, dzisiaj_mikrousd: int, miesiac_mikrousd: int, limit_dzienny_mikrousd: int, limit_miesieczny_mikrousd: int, wywolan_dzisiaj: int} */
    public function stan(): array
    {
        $dzien = Czas::dzisiajData();
        $wiersz = DB::table('ai_budzet_dzienny')->where('dzien', $dzien)->first();

        return [
            'dzien' => $dzien,
            'dzisiaj_mikrousd' => $wiersz === null ? 0 : (int) $wiersz->zarezerwowano_mikrousd + (int) $wiersz->wydano_mikrousd,
            'miesiac_mikrousd' => $this->sumaMiesiaca($dzien),
            'limit_dzienny_mikrousd' => self::limitDzienny(),
            'limit_miesieczny_mikrousd' => self::limitMiesieczny(),
            'wywolan_dzisiaj' => $wiersz === null ? 0 : (int) $wiersz->liczba_wywolan,
        ];
    }

    /** Najgorszy koszt jednego wywołania zadania — `null`, gdy nie ma cennika. */
    public static function szacunek(string $zadanie): ?int
    {
        $cennik = Cennik::zKonfiguracji();

        if ($cennik === null) {
            return null;
        }

        return $cennik->koszt(
            max(0, (int) config("kuking.import.model.szacunek_tokenow_wejscia.{$zadanie}")),
            KlientLuna::maxWyjscie(),
        );
    }

    public static function limitDzienny(): int
    {
        return self::mikro((float) config('kuking.import.budzet.dzienny_usd'));
    }

    public static function limitMiesieczny(): int
    {
        return self::mikro((float) config('kuking.import.budzet.miesieczny_usd'));
    }

    private static function mikro(float $usd): int
    {
        return is_finite($usd) && $usd > 0 ? (int) floor($usd * 1_000_000) : 0;
    }

    private function sumaMiesiaca(string $dzien): int
    {
        $poczatek = substr($dzien, 0, 8).'01';

        return (int) DB::table('ai_budzet_dzienny')
            ->whereBetween('dzien', [$poczatek, $dzien])
            ->sum(DB::raw('zarezerwowano_mikrousd + wydano_mikrousd'));
    }

    /** Jeden wpis dziennie po przekroczeniu progu — `Cache::add` jest atomowe. */
    private function ostrzezJesliBlisko(string $dzien): void
    {
        $limit = self::limitDzienny();

        if ($limit === 0) {
            return;
        }

        $stan = $this->stan();
        $prog = (float) config('kuking.import.budzet.prog_ostrzezenia', 0.8);

        if ($stan['dzisiaj_mikrousd'] < $limit * $prog) {
            return;
        }

        if (! Cache::add(self::KLUCZ_OSTRZEZENIA.$dzien, true, 86400)) {
            return;
        }

        Log::warning('Budżet odczytu przepisów modelem: przekroczony próg ostrzegawczy dziennego limitu.', [
            'dzien' => $dzien,
            'wydano_i_zarezerwowano_usd' => round($stan['dzisiaj_mikrousd'] / 1_000_000, 2),
            'limit_dzienny_usd' => round($limit / 1_000_000, 2),
            'stage' => 'import_budzet_prog',
        ]);
    }
}

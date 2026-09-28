<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Support\Czas;
use Carbon\CarbonInterface;
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
 *
 * KSIĘGA REZERWACJI (`ai_rezerwacje`, D-298 „maszyna stanów”, #1973/#1974).
 * Każda rezerwacja ma trwały wiersz pod kluczem (zlecenie, próba), zapisany
 * w TEJ SAMEJ transakcji co zwiększenie `zarezerwowano_mikrousd`:
 *
 *     zarezerwowana ──oznaczWyslana()──► wyslana ──rozlicz()──► rozliczona
 *           └──zwolnij()──► zwolniona
 *
 * Każde przejście to warunkowy `UPDATE … WHERE stan IN (otwarte)`. Wiersz
 * już zamknięty nie jest trafiany, więc powtórzone `rozlicz()`/`zwolnij()`
 * NIE DOTYKA budżetu — zwraca `null`/`false`. `zamknijOtwarte()` domyka
 * rezerwację porzuconą: niewysłaną zwalnia (żądanie na pewno nie wyszło,
 * bo stan `wyslana` zapisujemy PRZED żądaniem), wysłaną rozlicza całą
 * kwotą (dostawca mógł policzyć — lepiej zawyżyć).
 */
final class BudzetAi
{
    public const ODMOWA_DZIEN = 'dzien';

    public const ODMOWA_MIESIAC = 'miesiac';

    /** Ta próba tego zlecenia ma już rezerwację — drugiej nie będzie. */
    public const ODMOWA_POWTORZONA = 'powtorzona';

    public const STAN_ZAREZERWOWANA = 'zarezerwowana';

    public const STAN_WYSLANA = 'wyslana';

    public const STAN_ROZLICZONA = 'rozliczona';

    public const STAN_ZWOLNIONA = 'zwolniona';

    private const OTWARTE = "('zarezerwowana', 'wyslana')";

    private const KLUCZ_OSTRZEZENIA = 'kuking:import:budzet-ostrzezenie:';

    /**
     * @return Rezerwacja|string rezerwacja albo powód odmowy (`ODMOWA_*`)
     */
    public function zarezerwuj(int $mikroUsd, string $importId, int $proba): Rezerwacja|string
    {
        $mikroUsd = max(0, $mikroUsd);
        $dzien = Czas::dzisiajData();

        $wynik = DB::transaction(function () use ($mikroUsd, $dzien, $importId, $proba): Rezerwacja|string {
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

            // Wiersz księgi PRZED zwiększeniem licznika i w tej samej
            // transakcji: albo są oba, albo żadne (#1973).
            $wiersz = DB::selectOne(
                'INSERT INTO ai_rezerwacje (import_id, proba, dzien, mikrousd, stan, created_at, updated_at) '
                ."VALUES (?, ?, ?, ?, 'zarezerwowana', now(), now()) ON CONFLICT (import_id, proba) DO NOTHING RETURNING id",
                [$importId, $proba, $dzien, $mikroUsd],
            );

            if ($wiersz === null) {
                return self::ODMOWA_POWTORZONA;
            }

            DB::update(
                'UPDATE ai_budzet_dzienny SET zarezerwowano_mikrousd = zarezerwowano_mikrousd + ?, liczba_wywolan = liczba_wywolan + 1, updated_at = now() WHERE dzien = ?',
                [$mikroUsd, $dzien],
            );

            return new Rezerwacja((int) $wiersz->id, $importId, $proba, $dzien, $mikroUsd);
        });

        if ($wynik instanceof Rezerwacja) {
            $this->ostrzezJesliBlisko($dzien);
        }

        return $wynik;
    }

    /**
     * Zapisuje „żądanie za chwilę wychodzi" — WOŁANE PRZED żądaniem. Od tej
     * chwili porzucona rezerwacja jest liczona jako wydana, a nie zwalniana.
     * `false` = rezerwacji już nie ma (wygaszona) i żądanie NIE MOŻE wyjść.
     */
    public function oznaczWyslana(Rezerwacja $rezerwacja): bool
    {
        return DB::update(
            "UPDATE ai_rezerwacje SET stan = 'wyslana', updated_at = now() WHERE id = ? AND stan = 'zarezerwowana'",
            [$rezerwacja->id],
        ) === 1;
    }

    /**
     * Zamienia rezerwację na wydatek. `null` = nie znamy faktycznego kosztu,
     * więc cała rezerwacja zostaje policzona jako wydana.
     *
     * Faktyczny koszt WIĘKSZY od rezerwacji (dostawca policzył więcej, niż
     * zakładał szacunek wejścia) też jest wpisywany w całości — budżet ma
     * mówić prawdę o wydatkach, nawet jeśli ta prawda przekracza limit.
     *
     * IDEMPOTENTNE (#1974): rezerwacja już zamknięta daje `null` i nie
     * zmienia budżetu. Zwraca kwotę wpisaną w wydatki TYM wywołaniem.
     */
    public function rozlicz(Rezerwacja $rezerwacja, ?int $faktycznyMikroUsd): ?int
    {
        $wydano = $faktycznyMikroUsd === null ? $rezerwacja->mikroUsd : max(0, $faktycznyMikroUsd);

        return DB::transaction(function () use ($rezerwacja, $wydano): ?int {
            $zamknieta = DB::update(
                "UPDATE ai_rezerwacje SET stan = 'rozliczona', wydano_mikrousd = ?, zamknieto_at = now(), updated_at = now() "
                .'WHERE id = ? AND stan IN '.self::OTWARTE,
                [$wydano, $rezerwacja->id],
            );

            if ($zamknieta !== 1) {
                return null;
            }

            DB::update(
                'UPDATE ai_budzet_dzienny SET zarezerwowano_mikrousd = GREATEST(0, zarezerwowano_mikrousd - ?), '
                .'wydano_mikrousd = wydano_mikrousd + ?, updated_at = now() WHERE dzien = ?',
                [$rezerwacja->mikroUsd, $wydano, $rezerwacja->dzien],
            );

            return $wydano;
        });
    }

    /**
     * Zwraca rezerwację bez wydatku — WYŁĄCZNIE wtedy, gdy żądanie na pewno
     * nie wyszło (brak konfiguracji, zgoda cofnięta przed wysyłką, błąd
     * stały 4xx). Idempotentne jak `rozlicz()`: `false` = już zamknięta.
     */
    public function zwolnij(Rezerwacja $rezerwacja): bool
    {
        return DB::transaction(function () use ($rezerwacja): bool {
            $zamknieta = DB::update(
                "UPDATE ai_rezerwacje SET stan = 'zwolniona', zamknieto_at = now(), updated_at = now() "
                .'WHERE id = ? AND stan IN '.self::OTWARTE,
                [$rezerwacja->id],
            );

            if ($zamknieta !== 1) {
                return false;
            }

            DB::update(
                'UPDATE ai_budzet_dzienny SET zarezerwowano_mikrousd = GREATEST(0, zarezerwowano_mikrousd - ?), '
                .'liczba_wywolan = GREATEST(0, liczba_wywolan - 1), updated_at = now() WHERE dzien = ?',
                [$rezerwacja->mikroUsd, $rezerwacja->dzien],
            );

            return true;
        });
    }

    /**
     * Domyka rezerwacje zlecenia, których nikt nie domknął — `failed()`,
     * początek następnej próby i sprzątanie po czasie (#1973).
     *
     *  - `zarezerwowana` → zwolniona: stan `wyslana` pada PRZED żądaniem,
     *    więc bez niego żądanie na pewno nie wyszło;
     *  - `wyslana` → rozliczona CAŁĄ kwotą: żądanie mogło dojść i zostać
     *    policzone, a `usage` nie przetrwało (D-297: lepiej zawyżyć).
     *
     * @param  ?CarbonInterface  $starszeNiz  tylko rezerwacje założone przed tą chwilą
     * @return int ile wpisano w wydatki TYM wywołaniem
     */
    public function zamknijOtwarte(string $importId, ?CarbonInterface $starszeNiz = null): int
    {
        $otwarte = DB::table('ai_rezerwacje')
            ->where('import_id', $importId)
            ->whereIn('stan', [self::STAN_ZAREZERWOWANA, self::STAN_WYSLANA])
            ->when($starszeNiz !== null, fn ($q) => $q->where('created_at', '<', $starszeNiz))
            ->orderBy('proba')
            ->get();

        $wydano = 0;

        foreach ($otwarte as $wiersz) {
            $rezerwacja = new Rezerwacja((int) $wiersz->id, (string) $wiersz->import_id, (int) $wiersz->proba, (string) $wiersz->dzien, (int) $wiersz->mikrousd);

            if ($wiersz->stan === self::STAN_WYSLANA) {
                $wydano += $this->rozlicz($rezerwacja, null) ?? 0;
            } else {
                $this->zwolnij($rezerwacja);
            }
        }

        return $wydano;
    }

    /**
     * Zlecenia, które mają rezerwację otwartą dłużej niż `$starszeNiz` —
     * dla sprzątania po czasie.
     *
     * @return list<string>
     */
    public function zleceniaZPorzuconymiRezerwacjami(CarbonInterface $starszeNiz): array
    {
        return DB::table('ai_rezerwacje')
            ->whereIn('stan', [self::STAN_ZAREZERWOWANA, self::STAN_WYSLANA])
            ->where('created_at', '<', $starszeNiz)
            ->distinct()
            ->pluck('import_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
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

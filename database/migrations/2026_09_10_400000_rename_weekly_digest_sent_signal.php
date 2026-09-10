<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `weekly_digest_sent` → `weekly_digest_queued` (audyt 10.09.2026 ustalenie
 * MAIL-03, `docs/DECISIONS.md` D-078).
 *
 * CO BYŁO NIE TAK Z POPRZEDNIĄ NAZWĄ
 * Sygnał powstaje w `WyslijPodsumowaniaTygodnia::handle()` ZARAZ PO
 * `Mail::queue()`, czyli w chwili, w której worker jeszcze nawet nie sięgnął
 * po ten wiersz z kolejki, a dostawca poczty o istnieniu listu nie wie.
 * Nazwa `..._sent` sklejała więc w jedno trzy różne zdarzenia:
 * ZAKOLEJKOWANO, DOSTAWCA PRZYJĄŁ, DORĘCZONO — a Kuking ma prawdziwy sygnał
 * tylko o pierwszym z nich.
 *
 * Skutek nie był akademicki: list, który przewróci się w workerze i wyląduje
 * w `failed_jobs`, ZOSTAWAŁ w statystyce policzony jako wysłany. Metryka
 * zawyżała skuteczność wysyłki dokładnie wtedy, gdy wysyłka nie działała —
 * czyli w jedynym momencie, w którym ktoś na nią patrzy. To ta sama usterka
 * co dryf dokumentacji: liczba nie jest fałszywa przez pomyłkę w kodzie, tylko
 * przez nazwę obiecującą więcej, niż kod może wiedzieć.
 *
 * DLACZEGO `queued`, A NIE POLSKIE `zakolejkowano`
 * `AGENTS.md` §11: „Nazwy zdarzeń analitycznych: `snake_case` po angielsku".
 * Pozostałe cztery nazwy w tym zbiorze (`photo_upload_failed`,
 * `search_performed`, `weekly_digest_unsubscribed`) trzymają tę konwencję,
 * a `properties` w tej samej tabeli są po polsku (`wykonania`, `wpisy`) —
 * czyli podział jest już ustalony i nie ma powodu się z niego wyłamywać
 * przy jednej nazwie.
 *
 * PRZEPISUJEMY STARE WIERSZE, NIE OBSŁUGUJEMY DWÓCH NAZW PRZY ODCZYCIE
 * Sprawdzone w kodzie na `main`: `weekly_digest_sent` NIE JEST DZIŚ NIGDZIE
 * ODCZYTYWANY. Jedynymi miejscami, które w ogóle znają tę wartość, są
 * `ZapiszSygnal` (zapis), komenda wysyłkowa (zapis) i testy; `kuking:raport`
 * liczy powroty z `users.ostatnio_widziany_at`, a nie z `product_signals`,
 * i żaden ekran panelu nie sięga do `signal_name`. Nie ma więc panelu, który
 * po tej zmianie przestanie cokolwiek pokazywać, i nie ma czego uczyć dwóch
 * nazw.
 *
 * Wobec tego dwie nazwy przy odczycie byłyby kosztem bez korzyści: rozgałęzia
 * się każde przyszłe zapytanie, a pierwszy człowiek, który napisze
 * `where('signal_name', 'weekly_digest_queued')` bez tej gałęzi, dostanie
 * po cichu za małą liczbę. Jedna nazwa w tabeli znaczy, że takiej pomyłki nie
 * da się popełnić.
 *
 * Wiersze ze starą nazwą mogą już istnieć — `UPDATE` niżej przepisuje je
 * WSZYSTKIE i to jest przepisanie bezstratne: zmienia się nazwa zdarzenia,
 * nie jego znaczenie (te wiersze od początku opisywały zakolejkowanie, tylko
 * nazywały się inaczej). Na produkcji jest ich prawdopodobnie zero, bo digest
 * jest domyślnie wyłączony (`KUKING_DIGEST_WLACZONY`, D-057 §8), ale migracja
 * nie zakłada tego — na bazie testowej i lokalnej te wiersze bywają.
 *
 * KOLEJNOŚĆ MA ZNACZENIE: najpierw poszerzamy CHECK o obie nazwy, potem
 * przepisujemy wiersze, na końcu zwężamy CHECK do nowej. Odwrotna kolejność
 * (najpierw zwęź) odbiłaby `UPDATE` o ograniczenie, którego wiersze jeszcze
 * nie spełniają, i migracja padłaby w połowie. `ALTER TABLE ... ADD
 * CONSTRAINT` waliduje istniejące dane, więc „poszerz na chwilę" jest tu
 * jedynym bezpiecznym przejściem.
 *
 * ROLLBACK
 * Symetryczny i też bezstratny: `down()` przepisuje `weekly_digest_queued`
 * z powrotem na `weekly_digest_sent` tą samą trójką kroków. Wiersze zostają,
 * bo cofnięcie kodu przywraca kod, który tę nazwę zapisywał — kasowanie
 * telemetrii przy rollbacku byłoby karą za cofnięcie wdrożenia.
 *
 * Uwaga przy cofaniu: jeśli w bazie leżą JEDNOCZEŚNIE wiersze z obu nazw
 * (bo ktoś cofnął kod, ale nie migrację, i nowy-stary kod dopisał swoje),
 * `down()` po prostu skleja je z powrotem w jedną nazwę. To jest ta sama
 * utrata rozróżnienia, którą ten wpis naprawia — dlatego cofać należy kod
 * i migrację razem.
 */
return new class extends Migration
{
    private const STARA = 'weekly_digest_sent';

    private const NOWA = 'weekly_digest_queued';

    /**
     * Nazwy spoza tej pary wypisane WPROST, nie przez stałe z
     * `App\Domain\Analytics\ZapiszSygnal`. Migracja opisuje schemat z dnia,
     * w którym powstała, i musi dać się odtworzyć od zera także wtedy, gdy
     * kod aplikacji pójdzie dalej — żadna migracja w tym repozytorium nie
     * importuje klas z `app/`.
     *
     * @var list<string>
     */
    private const POZOSTALE = ['photo_upload_failed', 'search_performed', 'weekly_digest_unsubscribed'];

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $this->przemianuj(self::STARA, self::NOWA);
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $this->przemianuj(self::NOWA, self::STARA);
    }

    private function przemianuj(string $z, string $na): void
    {
        // Krok 1: CHECK dopuszcza obie nazwy naraz, żeby UPDATE miał gdzie
        // wylądować.
        $this->przestawCheck([...self::POZOSTALE, $z, $na]);

        // Krok 2: wiersze dostają nową nazwę.
        DB::table('product_signals')->where('signal_name', $z)->update(['signal_name' => $na]);

        // Krok 3: CHECK znów jest zamknięty na dokładnie tyle nazw, ile ich
        // jest. Zamknięty zbiór to druga linia obrony przed zamienieniem
        // `product_signals` w ogólny dziennik odwiedzin (AGENTS.md §3) —
        // migracja `2026_09_06_220000_create_product_signals_table` opisuje
        // to szerzej i nie wolno go po drodze „zostawić szerszego, bo wygodnie".
        $this->przestawCheck([...self::POZOSTALE, $na]);
    }

    /** @param  list<string>  $nazwy */
    private function przestawCheck(array $nazwy): void
    {
        $lista = implode(', ', array_map(static fn (string $n): string => "'".$n."'", $nazwy));

        DB::statement('ALTER TABLE product_signals DROP CONSTRAINT IF EXISTS product_signals_signal_name_check');
        DB::statement(
            'ALTER TABLE product_signals ADD CONSTRAINT product_signals_signal_name_check '
            ."CHECK (signal_name IN ({$lista}))",
        );
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};

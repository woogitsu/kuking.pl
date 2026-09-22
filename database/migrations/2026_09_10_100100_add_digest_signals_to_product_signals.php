<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dwa nowe sygnały produktowe dla tygodniowego podsumowania (issue #11,
 * `docs/DECISIONS.md` D-057): `weekly_digest_sent` i
 * `weekly_digest_unsubscribed`.
 *
 * PO CO ROZSZERZAĆ CHECK, SKORO MOŻNA GO BYŁO ZDJĄĆ
 * Bo ten CHECK jest DRUGĄ LINIĄ OBRONY, nie formalnością — tak go opisuje
 * migracja `2026_09_06_220000_create_product_signals_table.php`: zamknięty
 * zbiór nazw pilnuje, żeby `product_signals` nie stało się ogólnym
 * dziennikiem odwiedzin, którego AGENTS.md §3 zabrania budować bez
 * zmierzonej potrzeby. Zbiór ma więc rosnąć o nazwy WYMIENIONE Z IMIENIA,
 * jedna decyzja na jedną nazwę, a nie znikać przy pierwszej niewygodzie.
 *
 * DLACZEGO AKURAT TE DWIE, A NIE CZTERY Z ISSUE
 * Issue #11 wymienia cztery zdarzenia: wysłany, otwarty, kliknięty,
 * wypisany. Wdrażamy PIERWSZE i OSTATNIE. „Otwarty" wymaga niewidzialnego
 * obrazka śledzącego w treści listu, a „kliknięty" — podmiany każdego
 * odnośnika na przekierowanie przez nasz serwer. Obie techniki zapisują,
 * KIEDY konkretna osoba czytała pocztę i z jakiego adresu IP, czyli robią
 * dokładnie to, czego polityka prywatności obiecuje nie robić („Nie używamy
 * żadnych plików cookies do statystyk ani do reklam", `resources/legal/
 * polityka-prywatnosci.md` §5). Transport ma nawet własny wyłącznik
 * śledzenia odnośników po stronie dostawcy (`X-TRACKING-OFF`, patrz
 * `App\Poczta\TransportEmailLabs`) i jest on domyślnie WŁĄCZONY — dokładanie
 * własnego śledzenia byłoby cofnięciem tamtej decyzji tylnymi drzwiami.
 *
 * Zostaje więc para, która wystarcza do jedynej liczby, jaką ten mechanizm
 * naprawdę musi obserwować: `docs/product/RETENTION_LOOPS.md` §6, sygnał
 * ostrzegawczy nr 5 — „wypisy z digestu > 1% na wysyłkę". Do tego trzeba
 * wiedzieć, ile listów poszło i ile osób się po nich wypisało. Otwarcia
 * i kliknięcia byłyby miłe, ale nie są progiem, po którym cokolwiek robimy.
 *
 * ROLLBACK
 * `down()` przywraca zbiór dwuelementowy. Wcześniej kasuje wiersze z nowymi
 * nazwami — inaczej `ALTER TABLE ... ADD CONSTRAINT` odbiłby się o dane,
 * które sam przed chwilą dopuszczał, i rollback padłby w połowie. Utrata
 * tych wierszy jest bez znaczenia: to telemetria z 90-dniową retencją
 * (`kuking:sprzataj-sygnaly`), nie dowód niczego.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const NOWE = ['weekly_digest_sent', 'weekly_digest_unsubscribed'];

    /** @var list<string> */
    private const STARE = ['photo_upload_failed', 'search_performed'];

    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $this->przestawCheck([...self::STARE, ...self::NOWE]);
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::table('product_signals')->whereIn('signal_name', self::NOWE)->delete();

        $this->przestawCheck(self::STARE);
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

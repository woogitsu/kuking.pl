<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * Rollback migracji nie zamienia po cichu wyboru „usuń wszystko" na „usuń
 * minimum" (issue #287, D-088 — ta sama choroba, którą audyt znalazł już raz
 * jako DB2 w `2026_09_07_400000_default_weekly_digest_to_off.php`).
 *
 * ODTWORZONE NA PRAWDZIWEJ BAZIE, PRZED NAPRAWĄ (nie w teorii — patrz opis PR)
 * `markForDeletion(DELETE_SCOPE_EVERYTHING)` → `migrate:rollback` → `migrate`
 * dawało `delete_scope = 'minimum'` na koncie, które poprosiło o `everything`.
 * `down()` kasował kolumnę (bez błędu, bo jest `nullable`), a `up()` przy
 * ponownym uruchomieniu backfillował `NULL` jako `minimum` — bo to jedyna
 * wartość, jaką umiał wtedy nadać. Ani jeden test w tym repo, sprzed tej
 * poprawki, nie sprawdzał tego cyklu — istniejące testy migracji patrzyły
 * na KSZTAŁT schematu (`information_schema.columns`), nie na WARTOŚĆ wiersza
 * po pełnym `down()` + `up()`, więc choroba przechodziła przez zielone CI.
 *
 * CZEGO TEN PLIK PILNUJE — trzy rzeczy naraz, żaden test nie zastępuje reszty
 * (ta sama zasada co w `JedenAktywnyEksportNaKontoTest` i
 * `CofniecieMigracjiNieKasujeZeszytowTest`):
 *
 *  1. że rollback ODMAWIA, gdy istnieje choć jedno konto z `everything`,
 *     zamiast po cichu zgubić ten wybór;
 *  2. że odmowa NAPRAWDĘ nic nie kasuje — kolumna i wartość zostają, nie
 *     tylko komunikat jest ładny;
 *  3. że odmowa jest WĄSKA: konto z `minimum` (albo brak jakiegokolwiek
 *     wyboru) nie blokuje niczego — inaczej „naprawą" byłoby zablokowanie
 *     rollbacku na zawsze, co audyt i issue #287 wprost odrzucają.
 */
class CofniecieMigracjiNiePodmieniaZakresuUsunieciaTest extends TestCase
{
    use RefreshDatabase;

    private const SCIEZKA_MIGRACJI = 'database/migrations/2026_09_07_500000_add_erased_status_and_delete_scope_to_users.php';

    #[Test]
    public function test_cofniecie_odmawia_gdy_ktos_wybral_usun_wszystko(): void
    {
        // Prawdziwa droga produkcyjna do tego stanu — nie ręczny UPDATE.
        // `markForDeletion()` jest jedynym miejscem w kodzie aplikacji, które
        // w ogóle zapisuje `delete_scope` (AGENTS.md §7: `status` poza
        // `$fillable`), więc test ma przejść dokładnie tę samą drogę co
        // człowiek klikający „usuń wszystko" na ekranie ustawień.
        $basia = $this->user('basia');
        $basia->markForDeletion(User::DELETE_SCOPE_EVERYTHING);

        // `fail()` NIE STOI W `try` i to jest jedyny powód, dla którego ten
        // blok wygląda tak, a nie krócej. `AssertionFailedError` dziedziczy
        // `PHPUnit\Framework\Exception` → `RuntimeException` → `Exception`,
        // więc `fail()` postawione wewnątrz `try` wpadłoby do `catch` poniżej —
        // do tego samego, który ma złapać odmowę migracji. Przy takim kształcie
        // `catch` bez asercji na treść komunikatu robi z testu atrapę: zielony
        // także wtedy, gdy `down()` w ogóle nie odmawia. Wyjątek idzie więc do
        // zmiennej, a ocena stoi poza blokiem, gdzie nic jej nie łapie.
        $odmowa = null;

        try {
            Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
        } catch (RuntimeException $e) {
            $odmowa = $e;
        }

        $this->assertNotNull($odmowa, 'Cofnięcie migracji przeszło, mimo że konto ma delete_scope = everything.');

        // Komunikat ma mówić ILE kont i CO ZROBIĆ — „coś się nie zgadza"
        // nie skłania nikogo do zatrzymania się w środku wdrożenia.
        $this->assertStringContainsString("1 kont z delete_scope = 'everything'", $odmowa->getMessage());
        $this->assertStringContainsString('everything', $odmowa->getMessage());

        // NAJWAŻNIEJSZA ASERCJA W TYM PLIKU: wybór człowieka jest NADAL
        // `everything`, nie coś, co "wygląda podobnie". Odmowa, która i tak
        // zdążyła podmienić wartość, byłaby tylko ładniejszym komunikatem
        // o tej samej podmianie.
        $this->assertSame(
            User::DELETE_SCOPE_EVERYTHING,
            $basia->fresh()->delete_scope,
            'Wybór „usuń wszystko" zniknął mimo odmowy rollbacku.',
        );

        // Kolumna też zostaje — odmowa ma zatrzymać CAŁY down(), nie tylko
        // przeskoczyć nad `dropColumn`.
        $this->assertSame(1, $this->iloscKolumnDeleteScope());
    }

    #[Test]
    public function test_cofniecie_z_wyborem_minimum_nie_jest_blokowane(): void
    {
        // Kontrola dodatnia (PULAPKI_TESTOW.md #4): sama odmowa dla
        // `everything` nie dowodzi, że reszta przypadków nadal działa.
        // `minimum` jest NAJCZĘSTSZYM wyborem (domyślny, D-022) i musi
        // przechodzić rollback bez pytania — inaczej „naprawa" MIG-01
        // zablokowałaby wdrożenia na co dzień, nie tylko w tym rzadkim
        // przypadku, który miała naprawić.
        $marek = $this->user('marek');
        $marek->markForDeletion(User::DELETE_SCOPE_MINIMUM);

        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->assertSame(0, $this->iloscKolumnDeleteScope(), 'Rollback nie przeszedł, choć nikt nie wybrał everything.');

        // Odtwarzamy schemat, żeby nie zostawić bazy w połowie drogi dla
        // kolejnych testów w tym samym procesie (ten sam nawyk co
        // `CofniecieMigracjiNieKasujeZeszytowTest`).
        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
    }

    #[Test]
    public function test_na_swiezej_bazie_bez_zadnego_wyboru_cofniecie_dziala_bez_pytania(): void
    {
        // Kontrola dodatnia numer dwa: środowisko bez żadnego konta
        // w usuwaniu (staging świeżo po `migrate:fresh`) nie może zostać
        // zablokowane. Inaczej ta sama „naprawa" byłaby błędem tej samej
        // wagi w drugą stronę.
        Artisan::call('migrate:rollback', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);

        $this->assertSame(0, $this->iloscKolumnDeleteScope());

        Artisan::call('migrate', ['--path' => self::SCIEZKA_MIGRACJI, '--realpath' => false]);
    }

    private function iloscKolumnDeleteScope(): int
    {
        return count(DB::select(
            'SELECT column_name FROM information_schema.columns WHERE table_name = ? AND column_name = ?',
            ['users', 'delete_scope'],
        ));
    }
}

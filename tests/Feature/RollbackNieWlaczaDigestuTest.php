<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CookedEvent;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Cofnięcie migracji NIE MOŻE przywrócić mailingu bez zgody (audyt DB2,
 * `docs/DECISIONS.md` D-072).
 *
 * SKĄD SIĘ WZIĄŁ TEN PLIK
 * `2026_09_07_400000_default_weekly_digest_to_off` powstała właśnie dlatego,
 * że `wants_weekly_digest` miało `DEFAULT true`, a formularz rejestracji o tę
 * zgodę nie pyta — każde nowe konto wstawało zapisane na wysyłkę. Jej `down()`
 * przywracał jednak `SET DEFAULT true`. Gdy migrację pisano, było to
 * niegroźne: digestu nie było w kodzie w ogóle. Od 10 września wysyłka
 * ISTNIEJE, więc techniczny rollback zapisywałby NOWE konta na prawdziwy
 * mailing bez ani jednego kliknięcia.
 *
 * DWIE RZECZY OSOBNO, BO CHRONIĄ PRZED RÓŻNYMI DROGAMI TEJ SAMEJ WADY:
 *  1. `down()` zostawia `DEFAULT false` — sprawdzane na `information_schema`,
 *     nie na komentarzu w pliku;
 *  2. gdyby `DEFAULT true` wrócił INNĄ drogą (ręczny `ALTER`, przywrócenie
 *     bazy z kopii sprzed migracji, rollback na starszym wydaniu kodu),
 *     wysyłka odmawia startu.
 */
class RollbackNieWlaczaDigestuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kuking.digest.wlaczony', true);
        config()->set('kuking.digest.dzienny_limit', 60);
        config()->set('kuking.digest.odstep_dni', 7);
        config()->set('kuking.digest.okno_dni', 7);
        config()->set('kuking.digest.odstep_sekund', 0);
    }

    private function domyslnaWartoscZgody(): string
    {
        $wiersz = DB::selectOne(
            "select column_default from information_schema.columns
             where table_name = 'users' and column_name = 'wants_weekly_digest'",
        );

        $this->assertNotNull($wiersz, 'Kolumna `users.wants_weekly_digest` nie istnieje.');

        return strtolower(trim((string) $wiersz->column_default));
    }

    private function migracjaDomyslnejZgody(): Migration
    {
        /** @var Migration $migracja */
        $migracja = require base_path(
            'database/migrations/2026_09_07_400000_default_weekly_digest_to_off.php',
        );

        return $migracja;
    }

    /** Autor, którego przepis ktoś w tym tygodniu ugotował — czyli ktoś z realną treścią w liście. */
    private function autorZWykonaniem(string $username): User
    {
        $autor = $this->user($username);
        $kucharz = $this->user($username.'_kucharz');

        $przepis = Recipe::factory()->for($autor, 'author')->create();
        CookedEvent::factory()->for($kucharz, 'user')->for($przepis, 'recipe')->create([
            'cooked_at' => now()->subDay(),
        ]);

        return $autor;
    }

    /**
     * Test na `information_schema`, nie na komentarzu: wykonujemy prawdziwe
     * `down()` tej migracji i pytamy bazę, co teraz zrobi przy `INSERT` bez
     * tej kolumny.
     *
     * DDL wewnątrz transakcji `RefreshDatabase` jest na PostgreSQL
     * transakcyjny, więc cokolwiek ten test zmieni w schemacie, zostaje
     * wycofane razem z testem. `up()` na końcu i tak wołamy — żeby kolejność
     * testów w klasie nie zależała od szczegółu implementacji bazy.
     */
    public function test_po_cofnieciu_migracji_domyslna_zgoda_nadal_jest_wylaczona(): void
    {
        $this->assertStringStartsWith('false', $this->domyslnaWartoscZgody());

        $migracja = $this->migracjaDomyslnejZgody();
        $migracja->down();

        $this->assertStringStartsWith(
            'false',
            $this->domyslnaWartoscZgody(),
            'Cofnięcie migracji przywróciło zapisywanie nowych kont na mailing bez zgody (DB2).',
        );

        $migracja->up();

        $this->assertStringStartsWith('false', $this->domyslnaWartoscZgody());
    }

    /**
     * Bramka `App\Domain\Digest\BramkaDomyslnejZgody`: przy `DEFAULT true`
     * wysyłka nie startuje wcale. To jest zabezpieczenie na wypadek dróg,
     * których `down()` nie kontroluje — przywrócenia bazy z kopii, ręcznego
     * `ALTER`-a albo rollbacku wykonanego na starszym wydaniu kodu.
     */
    public function test_wysylka_odmawia_startu_gdy_domyslna_zgoda_jest_wlaczona(): void
    {
        Mail::fake();

        $this->autorZWykonaniem('bramka_zamknieta');

        DB::statement('ALTER TABLE users ALTER COLUMN wants_weekly_digest SET DEFAULT true');

        $kod = Artisan::call('kuking:wyslij-podsumowania');

        $this->assertSame(1, $kod, 'Wysyłka wystartowała mimo domyślnej zgody włączonej w schemacie.');
        Mail::assertNothingQueued();

        $this->assertStringContainsString(
            'SET DEFAULT false',
            Artisan::output(),
            'Komunikat nie mówi, CO ZROBIĆ — a to jedyna rzecz, jakiej potrzebuje osoba czytająca log o 8:30.',
        );

        DB::statement('ALTER TABLE users ALTER COLUMN wants_weekly_digest SET DEFAULT false');
    }

    /**
     * KONTROLA do testu wyżej. Bez niej „wysyłka nic nie wysłała" byłoby
     * prawdą także wtedy, gdyby bramka blokowała WSZYSTKO — i cały digest
     * przestałby chodzić, a testy dalej by przechodziły.
     */
    public function test_przy_poprawnym_schemacie_wysylka_normalnie_dziala(): void
    {
        Mail::fake();

        $this->autorZWykonaniem('bramka_otwarta');

        $kod = Artisan::call('kuking:wyslij-podsumowania');

        $this->assertSame(0, $kod);
        Mail::assertQueuedCount(1);
    }

    /**
     * Przebieg na sucho niczego nie wysyła i niczego nie zapisuje, więc wolno
     * mu przejść przez zamkniętą bramkę — właśnie przy takim schemacie chce
     * się policzyć, ilu ludzi dotyczyłaby pomyłka. Ostrzeżenie musi być
     * widoczne.
     */
    public function test_przebieg_na_sucho_przechodzi_ale_ostrzega(): void
    {
        Mail::fake();

        $this->autorZWykonaniem('bramka_na_sucho');

        DB::statement('ALTER TABLE users ALTER COLUMN wants_weekly_digest SET DEFAULT true');

        $kod = Artisan::call('kuking:wyslij-podsumowania', ['--na-sucho' => true]);

        $this->assertSame(0, $kod);
        Mail::assertNothingQueued();
        $this->assertStringContainsString('domyślną wartość `true`', Artisan::output());

        DB::statement('ALTER TABLE users ALTER COLUMN wants_weekly_digest SET DEFAULT false');
    }
}

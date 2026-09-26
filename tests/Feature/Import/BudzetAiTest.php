<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\BudzetAi;
use App\Domain\Import\Rezerwacja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Budżet modelu importu w PostgreSQL (D-297). Równoległość mierzy osobno
 * `tests/Dwa/BudzetAiNaDwochPolaczeniachTest.php`.
 */
final class BudzetAiTest extends TestCase
{
    use RefreshDatabase;

    private BudzetAi $budzet;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.import.budzet.dzienny_usd' => 5.0,
            'kuking.import.budzet.miesieczny_usd' => 100.0,
        ]);

        $this->budzet = app(BudzetAi::class);
    }

    public function test_domyslne_limity_to_decyzja_wlasciciela(): void
    {
        $this->assertSame(5.0, (float) $this->zPliku('dzienny_usd'));
        $this->assertSame(100.0, (float) $this->zPliku('miesieczny_usd'));
        $this->assertSame(5, (int) $this->zPliku('na_osobe_dzien', 'limity'));
        $this->assertSame(30, (int) $this->zPliku('na_osobe_miesiac', 'limity'));
    }

    public function test_rezerwacja_miesci_sie_do_limitu_dziennego_i_ani_mikrodolara_dalej(): void
    {
        $this->assertInstanceOf(Rezerwacja::class, $this->budzet->zarezerwuj(3_000_000));
        $this->assertInstanceOf(Rezerwacja::class, $this->budzet->zarezerwuj(2_000_000));
        $this->assertSame(BudzetAi::ODMOWA_DZIEN, $this->budzet->zarezerwuj(1));
        $this->assertSame(5_000_000, $this->budzet->stan()['dzisiaj_mikrousd']);
    }

    public function test_limit_miesieczny_liczy_sume_dni_miesiaca(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 10:00', 'Europe/Warsaw'));
        DB::table('ai_budzet_dzienny')->insert([
            ['dzien' => '2026-09-01', 'wydano_mikrousd' => 60_000_000, 'zarezerwowano_mikrousd' => 0, 'liczba_wywolan' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['dzien' => '2026-09-19', 'wydano_mikrousd' => 38_000_000, 'zarezerwowano_mikrousd' => 0, 'liczba_wywolan' => 1, 'created_at' => now(), 'updated_at' => now()],
            // Poprzedni miesiąc się nie liczy.
            ['dzien' => '2026-08-31', 'wydano_mikrousd' => 99_000_000, 'zarezerwowano_mikrousd' => 0, 'liczba_wywolan' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertInstanceOf(Rezerwacja::class, $this->budzet->zarezerwuj(2_000_000));
        $this->assertSame(BudzetAi::ODMOWA_MIESIAC, $this->budzet->zarezerwuj(1));
    }

    public function test_dzien_to_dzien_w_polsce_nie_w_utc(): void
    {
        // 23:30 UTC 30 września = 01:30 1 października w Warszawie.
        Carbon::setTestNow(Carbon::parse('2026-09-30 23:30', 'UTC'));

        $rezerwacja = $this->budzet->zarezerwuj(1000);

        $this->assertInstanceOf(Rezerwacja::class, $rezerwacja);
        $this->assertSame('2026-10-01', $rezerwacja->dzien);
    }

    public function test_rozliczenie_z_usage_zwalnia_nadwyzke_rezerwacji(): void
    {
        $rezerwacja = $this->budzet->zarezerwuj(4_000_000);
        $this->assertInstanceOf(Rezerwacja::class, $rezerwacja);

        $this->budzet->rozlicz($rezerwacja, 250_000);

        $wiersz = DB::table('ai_budzet_dzienny')->first();
        $this->assertSame(0, (int) $wiersz->zarezerwowano_mikrousd);
        $this->assertSame(250_000, (int) $wiersz->wydano_mikrousd);
        // Zwolnione miejsce jest znowu do wzięcia.
        $this->assertInstanceOf(Rezerwacja::class, $this->budzet->zarezerwuj(4_000_000));
    }

    public function test_brak_usage_zostawia_cala_rezerwacje_jako_wydana(): void
    {
        $rezerwacja = $this->budzet->zarezerwuj(1_500_000);
        $this->assertInstanceOf(Rezerwacja::class, $rezerwacja);

        $this->budzet->rozlicz($rezerwacja, null);

        $wiersz = DB::table('ai_budzet_dzienny')->first();
        $this->assertSame(0, (int) $wiersz->zarezerwowano_mikrousd);
        $this->assertSame(1_500_000, (int) $wiersz->wydano_mikrousd);
    }

    public function test_prog_ostrzegawczy_zostawia_jeden_wpis_dziennie(): void
    {
        $log = Log::spy();

        $this->budzet->zarezerwuj(3_000_000);
        $this->budzet->zarezerwuj(1_100_000); // 4,1 z 5 USD — ponad 80%
        $this->budzet->zarezerwuj(100_000);

        $log->shouldHaveReceived('warning')
            ->withArgs(fn (string $m, array $k = []): bool => ($k['stage'] ?? null) === 'import_budzet_prog')
            ->once();
    }

    public function test_szacunek_to_najgorszy_przypadek_z_cennika(): void
    {
        config([
            'kuking.import.model.cena_wejscie_mln_usd' => '2',
            'kuking.import.model.cena_wyjscie_mln_usd' => '8',
            'kuking.import.model.szacunek_tokenow_wejscia.ocr' => 6000,
            'kuking.import.model.max_wyjscie_tokenow' => 8000,
        ]);

        // 6000 × 2 + 8000 × 8 = 76 000 mikro-USD = 0,076 USD.
        $this->assertSame(76_000, BudzetAi::szacunek('ocr'));

        config(['kuking.import.model.cena_wejscie_mln_usd' => null]);
        $this->assertNull(BudzetAi::szacunek('ocr'));
    }

    /** Wartość z PLIKU konfiguracji (nie z `config()` nadpisanego w teście). */
    private function zPliku(string $klucz, string $sekcja = 'budzet'): mixed
    {
        $plik = require base_path('config/kuking.php');

        return $plik['import'][$sekcja][$klucz];
    }
}

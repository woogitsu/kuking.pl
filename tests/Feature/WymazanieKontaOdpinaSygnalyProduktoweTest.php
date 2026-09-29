<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Users\Actions\EraseAccountData;
use App\Models\ProductSignal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Wymazanie konta odpina od niego sygnały produktowe (issue #1324).
 *
 * `product_signals.user_id` ma `nullOnDelete()`, ale konta z Kuking się nie
 * kasuje, tylko anonimizuje (D-022) — akcja klucza obcego nigdy się nie
 * uruchamiała i zdarzenia z ostatnich 90 dni dalej wskazywały identyfikator
 * wymazanego konta. Zdarzenia zostają (liczniki zbiorcze bez zmian), znika
 * tylko powiązanie z osobą.
 */
class WymazanieKontaOdpinaSygnalyProduktoweTest extends TestCase
{
    use RefreshDatabase;

    public function test_po_wymazaniu_zaden_sygnal_nie_wskazuje_konta_a_liczniki_zostaja(): void
    {
        $odchodzi = $this->doWymazania();
        $zostaje = $this->user('zostaje');
        $sygnaly = app(ZapiszSygnal::class);

        $sygnaly->handle($odchodzi, ZapiszSygnal::SEARCH_PERFORMED, ['query_length' => 5, 'has_results' => true]);
        $sygnaly->handle($odchodzi, ZapiszSygnal::SEARCH_PERFORMED, ['query_length' => 3, 'has_results' => false]);
        $sygnaly->handle($odchodzi, ZapiszSygnal::WEEKLY_DIGEST_UNSUBSCRIBED);
        $sygnaly->handle($zostaje, ZapiszSygnal::SEARCH_PERFORMED, ['query_length' => 4, 'has_results' => true]);

        // Kontrola dodatnia: bez niej test przeszedłby także wtedy, gdyby
        // sygnały w ogóle nie zapisywały `user_id`.
        $this->assertSame(3, ProductSignal::query()->where('user_id', $odchodzi->getKey())->count());
        $przed = $this->licznikiZbiorcze();

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertSame(0, ProductSignal::query()->where('user_id', $odchodzi->getKey())->count());
        $this->assertSame($przed, $this->licznikiZbiorcze(), 'Odpięcie nie może zmienić liczników zbiorczych.');
        $this->assertSame(1, ProductSignal::query()->where('user_id', $zostaje->getKey())->count());
    }

    /**
     * Żądanie zaczęte przed wymazaniem trzyma jeszcze stary obiekt `User`
     * i zapisuje sygnał już po zatwierdzeniu wymazania.
     */
    public function test_sygnal_zapisany_po_wymazaniu_nie_wiaze_sie_z_kontem(): void
    {
        $odchodzi = $this->doWymazania();
        $staryObiekt = User::query()->findOrFail($odchodzi->getKey());

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        app(ZapiszSygnal::class)->handle($staryObiekt, ZapiszSygnal::SEARCH_PERFORMED, ['query_length' => 2, 'has_results' => true]);

        $this->assertSame(1, ProductSignal::query()->where('signal_name', ZapiszSygnal::SEARCH_PERFORMED)->count());
        $this->assertSame(0, ProductSignal::query()->where('user_id', $odchodzi->getKey())->count());
    }

    /** Konto wymazane, zanim poprawka istniała: ponowienie domyka powiązanie. */
    public function test_ponowienie_wymazania_odpina_pozostale_sygnaly(): void
    {
        $odchodzi = $this->doWymazania();
        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        DB::table('product_signals')->insert([
            'user_id' => $odchodzi->getKey(),
            'signal_name' => ZapiszSygnal::WEEKLY_DIGEST_UNSUBSCRIBED,
            'properties' => '{}',
        ]);

        app(EraseAccountData::class)->handle($odchodzi->refresh());

        $this->assertSame(0, ProductSignal::query()->where('user_id', $odchodzi->getKey())->count());
        $this->assertSame(1, ProductSignal::query()->count());
    }

    private function doWymazania(): User
    {
        return $this->user('odchodzi', [
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => User::DELETE_SCOPE_MINIMUM,
        ]);
    }

    /** @return array<string, int> */
    private function licznikiZbiorcze(): array
    {
        return ProductSignal::query()
            ->selectRaw('signal_name, count(*) as ile')
            ->groupBy('signal_name')
            ->orderBy('signal_name')
            ->pluck('ile', 'signal_name')
            ->map(fn ($ile): int => (int) $ile)
            ->all();
    }
}

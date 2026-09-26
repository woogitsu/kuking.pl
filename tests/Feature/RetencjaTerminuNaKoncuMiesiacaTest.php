<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWiadomosciDoOperatora;
use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Termin usunięcia zamkniętej wiadomości przy końcu miesiąca (issue #1345).
 *
 * `terminUsuniecia()` liczyło `handled_at + N miesięcy` bez klamrowania
 * zgodnego z progiem sprzątania. Dla sprawy zamkniętej 31 stycznia
 * i retencji 1 miesiąca ekran obiecywał 28 lutego, a `posprzataj()`
 * brało sprawę dopiero 1 marca. Ten plik URUCHAMIA sprzątanie minutę przed
 * i minutę po pokazanym terminie — tak jak `TerminUsunieciaKorespondencjiTest`
 * dla zwykłych dat — i sprawdza, że termin wypada dokładnie tam, gdzie
 * trzeba.
 */
class RetencjaTerminuNaKoncuMiesiacaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, int, string}>
     */
    public static function przypadki(): array
    {
        return [
            '31.01 + 1 miesiąc' => ['2026-01-31 12:00:00', 1, '2026-03-01 00:00:00'],
            '31.01 + 3 miesiące' => ['2026-01-31 12:00:00', 3, '2026-05-01 00:00:00'],
            '30.03 + 11 miesięcy' => ['2026-03-30 08:15:00', 11, '2027-03-01 00:00:00'],
            '29.02 + 12 miesięcy' => ['2028-02-29 12:00:00', 12, '2029-03-01 00:00:00'],
            // Kontrola dodatnia: zwykła data bez przycinania końca miesiąca
            // zostaje przy `handled_at + N` co do minuty.
            '15.01 + 1 miesiąc (zwykła data)' => ['2026-01-15 12:00:00', 1, '2026-02-15 12:00:00'],
        ];
    }

    #[DataProvider('przypadki')]
    public function test_pokazany_termin_to_chwila_w_ktorej_sprzatanie_bierze_sprawe(
        string $zamknieta,
        int $miesiecy,
        string $oczekiwany,
    ): void {
        config(['kuking.kontakt.retention_months' => $miesiecy]);
        Carbon::setTestNow(Carbon::parse($zamknieta, 'UTC'));

        $wiadomosc = ContactMessage::factory()
            ->zalatwiona(null, Carbon::parse($zamknieta, 'UTC'))
            ->create();

        $sprzatanie = app(PrzedawnioneWiadomosciDoOperatora::class);
        $termin = $sprzatanie->terminUsuniecia($wiadomosc->fresh());

        $this->assertNotNull($termin);
        $this->assertSame($oczekiwany, $termin->copy()->utc()->format('Y-m-d H:i:s'));

        // Minutę przed terminem sprzątanie sprawy jeszcze nie bierze —
        // ekran nie obiecuje za późno.
        Carbon::setTestNow($termin->copy()->subMinute());
        $this->assertSame(0, $sprzatanie->posprzataj($miesiecy, naSucho: true));

        // Minutę po terminie sprzątanie naprawdę ją kasuje — ekran nie
        // obiecuje za wcześnie (to był błąd z #1345).
        Carbon::setTestNow($termin->copy()->addMinute());
        $this->assertSame(1, $sprzatanie->posprzataj($miesiecy));
        $this->assertDatabaseMissing('contact_messages', ['id' => $wiadomosc->getKey()]);

        Carbon::setTestNow();
    }
}

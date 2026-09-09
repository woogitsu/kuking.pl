<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Compliance\PrzedawnioneWiadomosciDoOperatora;
use App\Models\ContactMessage;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Retencja `contact_messages` — obietnica z polityki prywatności jest
 * wykonywana, a nie tylko napisana.
 *
 * Trzy rzeczy, których pilnuje ten plik:
 *
 *  1. wiadomość OTWARTA nie znika nigdy, niezależnie od wieku — inaczej
 *     retencja sprzątałaby po zaniedbaniu zamiast chronić dane;
 *  2. wiadomość ZAŁATWIONA znika po okresie z konfiguracji, liczonym od
 *     `handled_at`, nie od `created_at`;
 *  3. próg liczy się przez `subMonthsNoOverflow`, nie `subMonths` (A6-04) —
 *     bo zwykłe odejmowanie miesięcy potrafi skasować wiersz WCZEŚNIEJ, niż
 *     obiecuje polityka prywatności.
 */
class RetencjaWiadomosciDoOperatoraTest extends TestCase
{
    use RefreshDatabase;

    private function sprzataj(int $miesiecy, bool $naSucho = false): int
    {
        return app(PrzedawnioneWiadomosciDoOperatora::class)->posprzataj($miesiecy, $naSucho);
    }

    public function test_zalatwiona_i_przedawniona_znika(): void
    {
        ContactMessage::factory()
            ->zalatwiona($this->user('ula'), now()->subMonths(13))
            ->create(['created_at' => now()->subMonths(14)]);

        $this->assertSame(1, $this->sprzataj(12));
        $this->assertSame(0, ContactMessage::count());
    }

    public function test_zalatwiona_ale_swieza_zostaje(): void
    {
        ContactMessage::factory()
            ->zalatwiona($this->user('ula'), now()->subMonths(2))
            ->create(['created_at' => now()->subMonths(6)]);

        $this->assertSame(0, $this->sprzataj(12));
        $this->assertSame(1, ContactMessage::count());
    }

    /**
     * NAJWAŻNIEJSZY TEST W TYM PLIKU.
     *
     * Wiadomość sprzed pięciu lat, której nikt nie przeczytał, zostaje.
     * Kasowanie jej byłoby usunięciem jedynego śladu po tym, że ktoś czekał
     * na odpowiedź i jej nie dostał — czyli sprzątaniem dowodu, nie ochroną
     * danych. Ta sama zasada, co przy otwartych sprawach moderacyjnych.
     */
    public function test_otwarta_wiadomosc_nie_znika_nawet_bardzo_stara(): void
    {
        ContactMessage::factory()->create(['created_at' => now()->subYears(5)]);
        ContactMessage::factory()->create([
            'status' => ContactMessage::STATUS_W_TOKU,
            'handled_by' => $this->user('ula')->getKey(),
            'handled_at' => now()->subYears(5),
            'created_at' => now()->subYears(5),
            'message' => 'Druga wiadomość, w trakcie od pięciu lat.',
        ]);

        $this->assertSame(0, $this->sprzataj(12));
        $this->assertSame(2, ContactMessage::count());
    }

    public function test_na_sucho_liczy_ale_nie_kasuje(): void
    {
        ContactMessage::factory()
            ->zalatwiona($this->user('ula'), now()->subMonths(20))
            ->create();

        $this->assertSame(1, $this->sprzataj(12, naSucho: true));
        $this->assertSame(1, ContactMessage::count());
    }

    /**
     * PRZEPEŁNIENIE DATY (A6-04) — pomiar, nie deklaracja.
     *
     * 31 maja minus 3 miesiące przez `subMonths()` daje 3 marca (bo 31 lutego
     * nie istnieje i Carbon przewija datę do przodu). Próg przesuwa się wtedy
     * w stronę NOWSZYCH wierszy i kasuje je przed czasem. `subMonthsNoOverflow`
     * cofa próg do 28 lutego — czyli myli się wyłącznie w stronę
     * „zostaje dłużej", jedyną dopuszczalną przy retencji.
     *
     * Wiersz załatwiony 1 marca ma zostać. Przy `subMonths()` zniknąłby.
     */
    public function test_prog_nie_przepelnia_daty_i_nie_kasuje_za_wczesnie(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-31 12:00:00'));

        $wiadomosc = ContactMessage::factory()
            ->zalatwiona($this->user('ula'), Carbon::parse('2026-03-01 09:00:00'))
            ->create(['created_at' => Carbon::parse('2026-02-01 09:00:00')]);

        // Kontrola: gdyby próg liczyć przez `subMonths(3)`, wypadłby na
        // 3 marca — czyli PO tej dacie, więc wiersz byłby kandydatem.
        $this->assertTrue(
            Carbon::parse('2026-03-01 09:00:00')->lessThan(now()->subMonths(3)),
            'Kontrola testu: przy `subMonths(3)` ten wiersz MA być kandydatem — inaczej '
            .'test nie sprawdza tego, co zakłada.',
        );

        $this->assertSame(0, $this->sprzataj(3));
        $this->assertDatabaseHas('contact_messages', ['id' => $wiadomosc->getKey()]);

        Carbon::setTestNow();
    }

    public function test_komenda_chodzi_i_bierze_liczbe_z_konfiguracji(): void
    {
        config(['kuking.kontakt.retention_months' => 12]);

        ContactMessage::factory()
            ->zalatwiona($this->user('ula'), now()->subMonths(13))
            ->create();

        $this->artisan('kuking:sprzataj-wiadomosci')
            ->expectsOutputToContain('12')
            ->assertSuccessful();

        $this->assertSame(0, ContactMessage::count());
    }

    public function test_komenda_jest_w_harmonogramie(): void
    {
        $nazwy = collect(app(Schedule::class)->events())
            ->map(fn ($zadanie) => (string) $zadanie->description)
            ->all();

        $this->assertContains(
            'kuking:sprzataj-wiadomosci',
            $nazwy,
            'Retencja bez zadania w harmonogramie jest obietnicą bez pokrycia — '
            .'polityka prywatności mówi, że serwis „naprawdę tego pilnuje".',
        );
    }
}

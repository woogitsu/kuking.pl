<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\DataExportReady;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Termin paczki danych: jedna chwila na ekranie, w liście i w kodzie (issue #819).
 *
 * Rdzeń #819 (PR #1457) sprawił, że paczka `ready` po terminie nie jest już
 * nazywana „gotową”. Zostały dwie rzeczy, które mierzy ten plik:
 *
 *  1. Ekran „Twoje dane” podawał sam dzień („do 27 września 2026”), a list
 *     dzień z godziną („27 września 2026, 10:15”). Teraz oba mówią to samo,
 *     w strefie człowieka (Europe/Warsaw), co do minuty.
 *  2. GRANICA. `DataExport::isDownloadable()` używa `expires_at->isFuture()`,
 *     więc DOKŁADNIE w chwili `expires_at` paczka jest już WYGASŁA — i ekran,
 *     i pobieranie. Wcześniej był tylko test minutę po terminie, który nie
 *     rozstrzygał, po której stronie leży sama chwila.
 *
 * KONTROLE UJEMNE (wykonane przy pisaniu):
 *  - format na ekranie z powrotem `'j F Y'` → `test_ekran_i_list_podaja_…`
 *    oblewa (brak „, 10:15” na ekranie),
 *  - `isFuture()` zamienione na `! isPast()` (granica po drugiej stronie) →
 *    oba testy `…_w_chwili_terminu_…` oblewają („gotowa”, pobranie 200).
 * Kontrola dodatnia: sekundę przed terminem ta sama paczka jest gotowa
 * i da się ją pobrać — więc testy granicy nie zdają „przez pustkę”.
 */
class TerminPaczkiDanychTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Stan paczki stoi w HTML-u w NOWYM WIERSZU po myślniku („—\n  gotowa”),
     * więc `assertDontSee('— gotowa')` nie znajdowało niczego NIGDY — także
     * przy paczce gotowej. Stąd wyrażenie z `\s+`, sprawdzone kontrolą dodatnią.
     */
    private const GOTOWA = '/—\s+gotowa\b/u';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ekran_i_list_podaja_ten_sam_termin_z_godzina_w_strefie_czlowieka(): void
    {
        // 08:15 UTC = 10:15 w Warszawie (czas letni) — środek dnia, nie północ.
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        $basia = $this->user('basiatermin');
        $export = $this->gotowaPaczka($basia, Carbon::parse('2026-09-27 08:15:00', 'UTC'));

        $this->actingAs($basia)->get(route('settings.data'))
            ->assertOk()
            ->assertSee('Pobierz paczkę')
            ->assertSee('Do pobrania do 27 września 2026, 10:15.', false);

        $list = (new DataExportReady($export))->render();
        $this->assertStringContainsString('27 września 2026, 10:15', $list);
    }

    public function test_paczka_w_chwili_terminu_jest_wygasla_na_ekranie(): void
    {
        $termin = Carbon::parse('2026-09-27 08:15:00', 'UTC');
        Carbon::setTestNow($termin->copy()->subDay());
        $basia = $this->user('basiagranica');
        $this->gotowaPaczka($basia, $termin);

        Carbon::setTestNow($termin);

        $odpowiedz = $this->actingAs($basia)->get(route('settings.data'))
            ->assertOk()
            ->assertDontSee('Pobierz paczkę')
            ->assertSee('Tej paczki nie można już pobrać.');
        $this->assertDoesNotMatchRegularExpression(self::GOTOWA, (string) $odpowiedz->getContent());
    }

    public function test_paczki_w_chwili_terminu_nie_da_sie_pobrac(): void
    {
        $termin = Carbon::parse('2026-09-27 08:15:00', 'UTC');
        Carbon::setTestNow($termin->copy()->subDay());
        $basia = $this->user('basiagranicapob');
        $export = $this->gotowaPaczka($basia, $termin);
        $adres = $this->adresPobrania($export);

        Carbon::setTestNow($termin);

        // Podpis w tej sekundzie jeszcze przechodzi (`signed` odrzuca dopiero
        // PO `expires`), więc 404 to decyzja `isDownloadable()`, nie podpisu.
        $this->actingAs($basia)->get($adres)->assertNotFound();
    }

    public function test_kontrola_dodatnia_sekunde_przed_terminem_paczka_jest_gotowa(): void
    {
        $termin = Carbon::parse('2026-09-27 08:15:00', 'UTC');
        Carbon::setTestNow($termin->copy()->subDay());
        $basia = $this->user('basiaprzedterm');
        $export = $this->gotowaPaczka($basia, $termin);
        $adres = $this->adresPobrania($export);

        Carbon::setTestNow($termin->copy()->subSecond());

        $odpowiedz = $this->actingAs($basia)->get(route('settings.data'))
            ->assertOk()
            ->assertSee('Pobierz paczkę');
        $this->assertMatchesRegularExpression(self::GOTOWA, (string) $odpowiedz->getContent());

        $this->actingAs($basia)->get($adres)->assertOk();
    }

    // -----------------------------------------------------------------

    private function gotowaPaczka(User $user, Carbon $wygasa): DataExport
    {
        $export = DataExport::create([
            'user_id' => $user->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        $export->forceFill([
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'eksporty/'.$user->getKey().'/'.$export->getKey().'-kuking-moje-dane.zip',
            'bytes' => 3,
            'completed_at' => now(),
            'expires_at' => $wygasa,
            'notified_at' => now(),
        ])->save();

        Storage::disk('local')->put((string) $export->object_key, 'zip');

        return $export->refresh();
    }

    private function adresPobrania(DataExport $export): string
    {
        return URL::temporarySignedRoute('settings.data.download', $export->expires_at, ['export' => $export->getKey()]);
    }
}

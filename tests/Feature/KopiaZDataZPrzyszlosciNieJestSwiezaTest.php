<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kopie\AlarmKopii;
use App\Domain\Kopie\StanKopiiBazy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kopia z datą Z PRZYSZŁOŚCI w nazwie nie jest „świeża" (issue #2259).
 *
 * `StanKopiiBazy::sprawdz()` liczył wiek przez `diffInHours(absolute: true)`,
 * więc plik `kuking-…Z.dump.cms` z datą o kilka godzin późniejszą niż teraz
 * dostawał wiek „kilka godzin" i stan AKTUALNA. Taki plik sortuje się jako
 * najnowszy, więc zasłaniał prawdziwy wiek kopii: serwis kopii mógł przestać
 * chodzić, a czujka milczała aż do dnia wpisanego w nazwę.
 *
 * Granice (zegar testu 2026-09-09 08:00:00 UTC, próg 36 h, tolerancja
 * zegara 5 min — `StanKopiiBazy::TOLERANCJA_ZEGARA_SEKUND`):
 *   teraz, +1 min, +5 min      → AKTUALNA, wiek 0 h (rozjazd zegarów kontenerów),
 *   +5 min 1 s, +1 h, +2 dni   → Z_PRZYSZLOSCI i alarm,
 *   36 h wstecz / 37 h wstecz  → AKTUALNA / PRZESTARZALA (próg bez zmian).
 */
final class KopiaZDataZPrzyszlosciNieJestSwiezaTest extends TestCase
{
    private const PREFIKS = 'baza/';

    private const ADRES_WEBHOOKA = 'https://discord.przyklad.invalid/api/webhooks/kopia2259/test';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2_kopie');

        config()->set('kuking.kopie.dysk', 'r2_kopie');
        config()->set('kuking.kopie.prefiks', self::PREFIKS);
        config()->set('kuking.kopie.maks_wiek_godzin', 36);
        config()->set('filesystems.disks.r2_kopie.bucket', 'kuking-kopie-test');

        Carbon::setTestNow(Carbon::parse('2026-09-09 08:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function polozKopie(string $znacznik): void
    {
        Storage::disk('r2_kopie')->put(self::PREFIKS."kuking-{$znacznik}Z.dump.cms", 'to-udaje-szyfrogram');
    }

    /**
     * @return array<string, array{string, string, int}>
     */
    public static function granice(): array
    {
        return [
            'dokładnie teraz' => ['20260909-080000', StanKopiiBazy::AKTUALNA, 0],
            '+1 min (rozjazd zegarów)' => ['20260909-080100', StanKopiiBazy::AKTUALNA, 0],
            '+5 min (granica tolerancji)' => ['20260909-080500', StanKopiiBazy::AKTUALNA, 0],
            '+5 min 1 s' => ['20260909-080501', StanKopiiBazy::Z_PRZYSZLOSCI, 0],
            '+1 h (czas zimowy zamiast UTC)' => ['20260909-090000', StanKopiiBazy::Z_PRZYSZLOSCI, -1],
            '+2 h (czas letni zamiast UTC)' => ['20260909-100000', StanKopiiBazy::Z_PRZYSZLOSCI, -2],
            '+2 dni' => ['20260911-080000', StanKopiiBazy::Z_PRZYSZLOSCI, -48],
            '36 h wstecz (na progu)' => ['20260907-200000', StanKopiiBazy::AKTUALNA, 36],
            '37 h wstecz (za progiem)' => ['20260907-190000', StanKopiiBazy::PRZESTARZALA, 37],
        ];
    }

    #[Test]
    #[DataProvider('granice')]
    public function stan_na_granicach_czasu(string $znacznik, string $stan, int $wiek): void
    {
        $this->polozKopie($znacznik);

        $wynik = app(StanKopiiBazy::class)->sprawdz();

        $this->assertSame($stan, $wynik['stan'], "Znacznik {$znacznik}: zły stan kopii.");
        $this->assertSame($wiek, $wynik['wiek_godzin'], "Znacznik {$znacznik}: zły wiek kopii.");
        $this->assertSame(1, $wynik['liczba']);
    }

    #[Test]
    public function plik_z_przyszlosci_zaslania_stara_kopie_wiec_to_alarm_a_nie_spokoj(): void
    {
        // Prawdziwa najnowsza kopia jest sprzed 7 dni (serwis nie chodzi),
        // ale obok leży plik z datą jutrzejszą. Przed poprawką: AKTUALNA.
        $this->polozKopie('20260902-021700');
        $this->polozKopie('20260910-021700');

        $wynik = app(StanKopiiBazy::class)->sprawdz();

        $this->assertSame(StanKopiiBazy::Z_PRZYSZLOSCI, $wynik['stan']);
        $this->assertSame(2, $wynik['liczba']);
    }

    #[Test]
    public function data_z_przyszlosci_naprawde_wysyla_alarm_mowiacy_co_zrobic(): void
    {
        config()->set('logging.channels.blad_webhook.url', self::ADRES_WEBHOOKA);
        Log::forgetChannel('blad_webhook');
        Http::fake([self::ADRES_WEBHOOKA => Http::response('ok', 200)]);

        $this->polozKopie('20260909-100000');

        $wynik = app(StanKopiiBazy::class)->sprawdz();
        $this->assertSame(StanKopiiBazy::Z_PRZYSZLOSCI, $wynik['stan']);

        $this->assertTrue(
            app(AlarmKopii::class)->zadzwonJesliTrzeba($wynik),
            'Data z przyszłości MUSI zadzwonić — to ten sam fałszywy spokój, co brak kopii.',
        );

        Http::assertSentCount(1);
        Http::assertSent(function (Request $zadanie): bool {
            $wyslane = (string) ($zadanie->data()['text'] ?? '');

            $this->assertStringContainsString('datę z przyszłości (2 h do przodu)', $wyslane);
            $this->assertStringContainsString('UTC', $wyslane);
            $this->assertStringContainsString('KOPIE_I_ODTWORZENIE.md', $wyslane);
            // To nie jest „serwis przestał chodzić" — zdanie z innych stanów
            // wysłałoby właściciela w złe miejsce.
            $this->assertStringNotContainsString('przestał chodzić', $wyslane);
            $this->assertStringNotContainsString('kuking-kopie-test', $wyslane);
            $this->assertStringNotContainsString('.dump.cms', $wyslane);

            return true;
        });
    }

    #[Test]
    public function komenda_konczy_sie_porazka_i_mowi_co_zrobic(): void
    {
        $this->polozKopie('20260909-090000');

        $this->artisan('kuking:sprawdz-kopie', ['--bez-alarmu' => true])
            ->expectsOutputToContain('datę z przyszłości (1 h do przodu)')
            ->doesntExpectOutputToContain('Kopia jest')
            ->assertFailed();
    }

    #[Test]
    public function komenda_przy_kopii_w_tolerancji_zegara_konczy_sie_sukcesem(): void
    {
        // Kontrola dodatnia: komenda nie oblewa każdej świeżej kopii.
        $this->polozKopie('20260909-080100');

        $this->artisan('kuking:sprawdz-kopie', ['--bez-alarmu' => true])
            ->expectsOutputToContain('Kopia jest')
            ->assertSuccessful();
    }
}

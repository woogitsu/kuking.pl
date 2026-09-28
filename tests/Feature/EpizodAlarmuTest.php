<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Monitoring\EpizodAlarmu;
use App\Domain\Monitoring\KanalAlarmowy;
use App\Logging\WebhookBleduHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Wspólna maszyna epizodu alarmowego (#972) — bez żadnej konkretnej czujki.
 *
 * Stany, klucz i teksty są tu celowo WYMYŚLONE: test ma dowieść, że
 * algorytm nie zależy od `StanKolejki` ani `StanPolaczenBazy`. Mapowanie
 * prawdziwych stanów i tekstów sprawdzają `EpizodyAlarmowTest`,
 * `NieudanyDzwonekNieKupujeCiszyTest`, `CzujkaKolejkiTest`
 * i `BudzetPolaczenBazyTest`. Prawdziwy handler i cache w pamięci,
 * atrapa wyłącznie HTTP; bez bazy i bez prawdziwego webhooka.
 */
class EpizodAlarmuTest extends TestCase
{
    private const URL = 'https://przyklad.test/maszyna';

    private const KLUCZ = 'kuking:test:epizod';

    private const CISZA_GODZIN = 2;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        Cache::flush();
        config()->set('logging.channels.blad_webhook.url', self::URL);
        Log::forgetChannel('blad_webhook');
        Http::preventStrayRequests();
        WebhookBleduHandler::zapomnijOstatniaWysylke();
        $this->freezeTime();
    }

    protected function tearDown(): void
    {
        WebhookBleduHandler::zapomnijOstatniaWysylke();
        Log::forgetChannel('blad_webhook');
        parent::tearDown();
    }

    private function odpowiedzi(int ...$statusy): void
    {
        $kolejka = Http::sequence();
        foreach ($statusy as $status) {
            $kolejka->push('', $status);
        }
        Http::fake([self::URL => $kolejka]);
    }

    private function zadzwon(string $stan): bool
    {
        return app(EpizodAlarmu::class)->zadzwonJesliTrzeba(
            klucz: self::KLUCZ,
            stan: $stan,
            spokojny: 'spokoj',
            alarmujace: ['niski', 'wysoki'],
            ciszaGodzin: self::CISZA_GODZIN,
            trescAlarmu: fn (): string => 'ALARM '.$stan,
            trescOdwolania: fn (string $poprzedni): string => 'ODWOLANIE po '.$poprzedni,
        );
    }

    private function ostatniaTresc(): string
    {
        return (string) Http::recorded()->last()[0]['text'];
    }

    public function test_przyjety_alarm_zapisuje_dowod_i_cisze(): void
    {
        $this->odpowiedzi(204);

        $this->assertTrue($this->zadzwon('wysoki'));

        $this->assertStringContainsString('ALARM wysoki', $this->ostatniaTresc());
        $pamiec = Cache::get(self::KLUCZ);
        $this->assertSame(2, $pamiec['wersja']);
        $this->assertSame('wysoki', $pamiec['przyjety_stan']);
        $this->assertSame(now()->timestamp, $pamiec['dostarczony_o']);
        $this->assertSame(now()->timestamp + self::CISZA_GODZIN * 3600, $pamiec['cisza_do']);
    }

    public function test_dluga_cisza_trzyma_do_konca_okna(): void
    {
        $this->odpowiedzi(204, 204);
        $this->assertTrue($this->zadzwon('wysoki'));

        $this->travel(self::CISZA_GODZIN * 3600 - 1)->seconds();
        $this->assertFalse($this->zadzwon('wysoki'));
        Http::assertSentCount(1);

        $this->travel(1)->seconds();
        $this->assertTrue($this->zadzwon('wysoki'));
        Http::assertSentCount(2);
    }

    public function test_nieudana_proba_daje_tylko_krotkie_ponowienie(): void
    {
        $this->odpowiedzi(500, 204);
        $this->assertFalse($this->zadzwon('wysoki'));
        $this->assertSame(0, Cache::get(self::KLUCZ)['cisza_do']);

        $this->travel(299)->seconds();
        $this->assertFalse($this->zadzwon('wysoki'));
        Http::assertSentCount(1);

        $this->travel(1)->seconds();
        $this->assertTrue($this->zadzwon('wysoki'));
        Http::assertSentCount(2);
    }

    public function test_odrzucona_eskalacja_nie_gubi_przyjetego_alarmu(): void
    {
        $this->odpowiedzi(204, 404, 204);
        $this->assertTrue($this->zadzwon('niski'));
        $this->assertFalse($this->zadzwon('wysoki'));
        $this->assertSame('niski', Cache::get(self::KLUCZ)['przyjety_stan']);

        $this->assertTrue($this->zadzwon('spokoj'));
        $this->assertStringContainsString('ODWOLANIE po niski', $this->ostatniaTresc());
        $this->assertNull(Cache::get(self::KLUCZ));
    }

    public function test_powrot_do_normy_daje_jedno_odwolanie(): void
    {
        $this->odpowiedzi(204, 204);
        $this->assertTrue($this->zadzwon('wysoki'));
        $this->assertTrue($this->zadzwon('spokoj'));
        $this->assertFalse($this->zadzwon('spokoj'));
        Http::assertSentCount(2);
        $this->assertNull(Cache::get(self::KLUCZ));
    }

    public function test_spokoj_bez_przyjetego_alarmu_nie_dzwoni(): void
    {
        $this->odpowiedzi(404);
        $this->assertFalse($this->zadzwon('wysoki'));
        $this->assertFalse($this->zadzwon('spokoj'));
        Http::assertSentCount(1);
        $this->assertNull(Cache::get(self::KLUCZ));
    }

    public function test_nieudane_odwolanie_ponawia_sie_po_przerwie(): void
    {
        $this->odpowiedzi(204, 404, 204);
        $this->assertTrue($this->zadzwon('wysoki'));
        $this->assertFalse($this->zadzwon('spokoj'));
        $this->assertSame('wysoki', Cache::get(self::KLUCZ)['przyjety_stan']);

        $this->travel(4)->minutes();
        $this->assertFalse($this->zadzwon('spokoj'));
        Http::assertSentCount(2);

        $this->travel(1)->minutes();
        $this->assertTrue($this->zadzwon('spokoj'));
        $this->assertStringContainsString('ODWOLANIE po wysoki', $this->ostatniaTresc());
    }

    public function test_powrot_awarii_po_nieudanym_odwolaniu_dzwoni_od_razu(): void
    {
        $this->odpowiedzi(204, 404, 204);
        $this->assertTrue($this->zadzwon('wysoki'));
        $this->assertFalse($this->zadzwon('spokoj'));

        // Nawrót to zmiana obserwowanego stanu — ani cisza sprzed odwołania,
        // ani pięć minut po nieudanej próbie nie mają prawa go zatrzymać.
        $this->assertTrue($this->zadzwon('wysoki'));
        Http::assertSentCount(3);
    }

    public function test_stan_spoza_listy_nie_dotyka_pamieci_ani_kanalu(): void
    {
        Http::fake();
        $this->assertFalse($this->zadzwon('nieobslugiwany'));
        Http::assertNothingSent();
        $this->assertNull(Cache::get(self::KLUCZ));
    }

    public function test_wylaczony_kanal_bez_wczesniejszego_alarmu_nie_tworzy_pamieci(): void
    {
        config()->set('logging.channels.blad_webhook.url', null);
        Http::fake();
        $this->assertFalse($this->zadzwon('wysoki'));
        Http::assertNothingSent();
        $this->assertNull(Cache::get(self::KLUCZ));
    }

    public function test_stary_format_z_samym_o_to_tylko_proba(): void
    {
        $this->odpowiedzi(204);
        Cache::put(self::KLUCZ, ['stan' => 'wysoki', 'o' => now()->timestamp], 3600);

        // Brak dowodu przyjęcia: spokój nie ma czego odwoływać, a świeża próba
        // daje tylko krótką przerwę.
        $this->assertFalse($this->zadzwon('wysoki'));
        Http::assertNothingSent();

        $this->travel(5)->minutes();
        $this->assertTrue($this->zadzwon('wysoki'));
        Http::assertSentCount(1);
    }

    public function test_stary_format_z_potwierdzeniem_nie_odtwarza_ciszy_a_zachowuje_odwolanie(): void
    {
        $this->odpowiedzi(204);
        Cache::put(self::KLUCZ, [
            'stan' => 'niski',
            'proba_o' => now()->subMinutes(6)->timestamp,
            'dostarczony_o' => now()->subMinutes(6)->timestamp,
            'epizod_zamkniety' => true,
            'odwolanie_nieudane' => true,
        ], 3600);

        $this->assertTrue($this->zadzwon('spokoj'));
        $this->assertStringContainsString('ODWOLANIE po niski', $this->ostatniaTresc());
        $this->assertNull(Cache::get(self::KLUCZ));
    }

    public function test_kanal_przyjmuje_wylacznie_2xx(): void
    {
        $kanal = app(KanalAlarmowy::class);
        $this->odpowiedzi(404, 500, 204);

        $this->assertTrue($kanal->wlaczony());
        $this->assertFalse($kanal->przyjal('raz'));
        $this->assertFalse($kanal->przyjal('dwa'));
        $this->assertTrue($kanal->przyjal('trzy'));

        // Cudzy sukces sprzed chwili nie jest naszym: bez adresu nie ma próby.
        config()->set('logging.channels.blad_webhook.url', null);
        Log::forgetChannel('blad_webhook');
        $this->assertFalse($kanal->wlaczony());
        $this->assertFalse($kanal->przyjal('cztery'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kolejka\AlarmKolejki;
use App\Domain\Kolejka\StanKolejki;
use App\Domain\Polaczenia\AlarmPolaczen;
use App\Domain\Polaczenia\StanPolaczenBazy;
use App\Logging\WebhookBleduHandler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Prawdziwe klasy alarmu i handler, cache w pamięci, atrapa wyłącznie HTTP.
 * Bez bazy, bez prawdziwego webhooka; to nie jest dowód odbioru produkcji
 * ani zachowania przy równoczesnych wysyłkach i utracie cache.
 */
class EpizodyAlarmowTest extends TestCase
{
    private const URL = 'https://przyklad.test/epizody';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        Cache::flush();
        config()->set('kuking.polaczenia.cisza_godzin', 6);
        config()->set('kuking.kolejka.cisza_godzin', 6);
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

    /** @return array<string, array{class-string<AlarmPolaczen>|class-string<AlarmKolejki>, string, string, string, string}> */
    public static function czujki(): array
    {
        return [
            'połączenia' => [AlarmPolaczen::class, StanPolaczenBazy::OSTRZEZENIE, StanPolaczenBazy::KRYTYCZNY, StanPolaczenBazy::SPOKOJNY, 'kuking:polaczenia:ostatni-alarm'],
            'kolejka' => [AlarmKolejki::class, StanKolejki::NOWE_NIEUDANE, StanKolejki::ZALEGLOSC, StanKolejki::SPOKOJNA, 'kuking:kolejka:ostatni-alarm'],
        ];
    }

    private function odpowiedzi(int ...$statusy): void
    {
        $kolejka = Http::sequence();
        foreach ($statusy as $status) {
            $kolejka->push('', $status);
        }
        Http::fake([self::URL => $kolejka]);
    }

    #[DataProvider('czujki')]
    public function test_nieudana_eskalacja_nie_gubi_odwolania_przyjetego_ostrzezenia(string $klasa, string $niski, string $wysoki, string $spokoj, string $klucz): void
    {
        $this->odpowiedzi(204, 404, 204);
        $alarm = app($klasa);
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $niski]));
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $spokoj]));
        Http::assertSentCount(3);
        $odwolanie = Http::recorded()->last()[0]['text'];
        $this->assertStringContainsString('poprzedni stan: '.$niski, $odwolanie);
        $this->assertStringNotContainsString('poprzedni stan: '.$wysoki, $odwolanie);
        $this->assertNull(Cache::get($klucz));
    }

    #[DataProvider('czujki')]
    public function test_eskalacja_po_odmowie_ponawia_po_pieciu_minutach(string $klasa, string $niski, string $wysoki, string $spokoj, string $klucz): void
    {
        $this->odpowiedzi(204, 404, 204);
        $alarm = app($klasa);
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $niski]));
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        $this->travel(299)->seconds();
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        Http::assertSentCount(2);
        $this->travel(1)->seconds();
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        Http::assertSentCount(3);
    }

    #[DataProvider('czujki')]
    public function test_odrzucony_nawrot_nie_odtwarza_ciszy_poprzedniego_epizodu(string $klasa, string $niski, string $wysoki, string $spokoj, string $klucz): void
    {
        $this->odpowiedzi(204, 404, 404, 204);
        $alarm = app($klasa);
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $spokoj]));
        // Nawrót to zmiana obserwowanego stanu: od razu, bez pięciu minut.
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        Http::assertSentCount(3);
        $this->travel(4)->minutes();
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        Http::assertSentCount(3);
        $this->travel(2)->minutes();
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        Http::assertSentCount(4);
    }

    #[DataProvider('czujki')]
    public function test_odwolanie_ponawia_sie_i_po_przyjeciu_nie_powtarza(string $klasa, string $niski, string $wysoki, string $spokoj, string $klucz): void
    {
        $this->odpowiedzi(204, 404, 204);
        $alarm = app($klasa);
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $spokoj]));
        $this->travel(4)->minutes();
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $spokoj]));
        Http::assertSentCount(2);
        $this->travel(2)->minutes();
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $spokoj]));
        $this->assertNull(Cache::get($klucz));
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $spokoj]));
        Http::assertSentCount(3);
    }

    #[DataProvider('czujki')]
    public function test_same_odrzucone_alarmy_nie_produkuja_odwolania(string $klasa, string $niski, string $wysoki, string $spokoj, string $klucz): void
    {
        $this->odpowiedzi(404, 404);
        $alarm = app($klasa);
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $niski]));
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $spokoj]));
        Http::assertSentCount(2);
        $this->assertNull(Cache::get($klucz));
    }

    #[DataProvider('czujki')]
    public function test_nieudane_odnowienie_nie_przedluza_ciszy(string $klasa, string $niski, string $wysoki, string $spokoj, string $klucz): void
    {
        $this->odpowiedzi(204, 404, 204);
        $alarm = app($klasa);
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        $this->travel(359)->minutes();
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        Http::assertSentCount(1);
        $this->travel(1)->minutes();
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        Http::assertSentCount(2);
        $this->travel(4)->minutes();
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        Http::assertSentCount(2);
        $this->travel(2)->minutes();
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        Http::assertSentCount(3);
    }

    #[DataProvider('czujki')]
    public function test_zmiana_bez_kanalu_uniewaznia_cisze_ale_zachowuje_alarm(string $klasa, string $niski, string $wysoki, string $spokoj, string $klucz): void
    {
        $this->odpowiedzi(204, 204);
        $alarm = app($klasa);
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        config()->set('logging.channels.blad_webhook.url', null);
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $spokoj]));
        $this->assertSame($wysoki, Cache::get($klucz)['przyjety_stan']);
        Http::assertSentCount(1);
        config()->set('logging.channels.blad_webhook.url', self::URL);
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        Http::assertSentCount(2);
    }

    #[DataProvider('czujki')]
    public function test_stara_proba_nie_udaje_przyjecia_i_zachowuje_krotka_przerwe(string $klasa, string $niski, string $wysoki, string $spokoj, string $klucz): void
    {
        $this->odpowiedzi(204);
        $alarm = app($klasa);
        Cache::put($klucz, ['stan' => $wysoki, 'o' => now()->timestamp], 3600);
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        Http::assertNothingSent();
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $spokoj]));
        Http::assertNothingSent();
        Cache::put($klucz, ['stan' => $wysoki, 'o' => now()->subMinutes(6)->timestamp], 3600);
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        Http::assertSentCount(1);
    }

    #[DataProvider('czujki')]
    public function test_stare_potwierdzenie_pozostaje_do_odwolania(string $klasa, string $niski, string $wysoki, string $spokoj, string $klucz): void
    {
        $this->odpowiedzi(204);
        Cache::put($klucz, ['stan' => $niski, 'proba_o' => now()->timestamp, 'dostarczony_o' => now()->timestamp], 3600);
        $this->assertTrue(app($klasa)->zadzwonJesliTrzeba(['stan' => $spokoj]));
        Http::assertSentCount(1);
        $this->assertStringContainsString('poprzedni stan: '.$niski, Http::recorded()->last()[0]['text']);
        $this->assertNull(Cache::get($klucz));
    }

    #[DataProvider('czujki')]
    public function test_stary_format_nie_odtwarza_dlugiej_ciszy(string $klasa, string $niski, string $wysoki, string $spokoj, string $klucz): void
    {
        $this->odpowiedzi(204);
        Cache::put($klucz, ['stan' => $wysoki, 'proba_o' => now()->subMinutes(6)->timestamp, 'dostarczony_o' => now()->subMinutes(7)->timestamp], 3600);
        $this->assertTrue(app($klasa)->zadzwonJesliTrzeba(['stan' => $wysoki]));
        Http::assertSentCount(1);
    }

    #[DataProvider('czujki')]
    public function test_powrot_do_przyjetego_stopnia_po_odrzuconej_eskalacji_jest_zmiana(string $klasa, string $niski, string $wysoki, string $spokoj, string $klucz): void
    {
        $this->odpowiedzi(204, 404, 204);
        $alarm = app($klasa);
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $niski]));
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $wysoki]));
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $niski]));
        Http::assertSentCount(3);
    }

    #[DataProvider('czujki')]
    public function test_stare_nieudane_odwolanie_zachowuje_przerwe_i_alarm(string $klasa, string $niski, string $wysoki, string $spokoj, string $klucz): void
    {
        $this->odpowiedzi(204);
        Cache::put($klucz, [
            'stan' => $wysoki,
            'dostarczony_o' => now()->subMinute()->timestamp,
            'proba_o' => now()->timestamp,
            'epizod_zamkniety' => true,
            'odwolanie_nieudane' => true,
        ], 3600);
        $alarm = app($klasa);
        $this->assertFalse($alarm->zadzwonJesliTrzeba(['stan' => $spokoj]));
        Http::assertNothingSent();
        $this->travel(6)->minutes();
        $this->assertTrue($alarm->zadzwonJesliTrzeba(['stan' => $spokoj]));
        Http::assertSentCount(1);
        $this->assertStringContainsString('poprzedni stan: '.$wysoki, Http::recorded()->last()[0]['text']);
    }
}

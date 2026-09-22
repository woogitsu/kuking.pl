<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\SprawdzAlarm;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * „Alarm dochodzi" to osobna rzecz od „alarm został wysłany" (issue #599).
 *
 * DLACZEGO TA KOMENDA W OGÓLE ISTNIEJE
 * Monitoring ma pięć warstw i każda może działać osobno: kod czujki,
 * konfiguracja produkcji, faktyczne wywołanie, ODEBRANIE wiadomości oraz
 * wyciszanie duplikatów. Do 18.09.2026 warstwy 1, 3 i 5 były sprawdzone,
 * a warstwa 4 na produkcji NIE była sprawdzona ani razu — bo jedynym
 * sposobem było doprowadzenie do prawdziwej awarii albo zaniżenie progu
 * czujki na żywym serwisie.
 *
 * Ta komenda zamienia to w jedno polecenie, które niczego nie psuje.
 *
 * CZEGO TEN PLIK NIE DOWODZI: że na produkcji cokolwiek dojdzie. Tam nie ma
 * dziś zmiennej `LOG_BLAD_WEBHOOK_URL` i to jest właśnie ten stan, który
 * pierwszy test niżej opisuje jako porażkę z instrukcją naprawy.
 */
class ProbaKanaluAlarmowegoTest extends TestCase
{
    private const ADRES_WEBHOOKA = 'https://przyklad.test/webhook-proby';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('logging.channels.blad_webhook.url', null);
        Cache::flush();
    }

    private function wlaczKanal(): void
    {
        config()->set('logging.channels.blad_webhook.url', self::ADRES_WEBHOOKA);

        // Kanał jest zapamiętywany po pierwszym użyciu, a inne testy w tej
        // klasie tworzą go z pustym adresem. Bez tego wiersza pamiętany
        // egzemplarz zostałby z `null` i test przechodziłby, nic nie wysyłając.
        Log::forgetChannel('blad_webhook');

        Http::fake([self::ADRES_WEBHOOKA => Http::response('ok', 200)]);
    }

    #[Test]
    public function bez_zmiennej_komenda_oblewa_i_mowi_dokladnie_czego_brakuje(): void
    {
        Http::fake();

        $this->artisan('kuking:sprawdz-alarm')
            ->expectsOutputToContain('LOG_BLAD_WEBHOOK_URL')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    #[Test]
    public function skonfigurowany_kanal_wysyla_dokladnie_jedna_wiadomosc(): void
    {
        $this->wlaczKanal();

        $this->artisan('kuking:sprawdz-alarm')->assertExitCode(0);

        Http::assertSentCount(1);
    }

    #[Test]
    public function przelacznik_bez_wysylki_niczego_nie_wysyla(): void
    {
        $this->wlaczKanal();

        $this->artisan('kuking:sprawdz-alarm', ['--bez-wysylki' => true])->assertExitCode(0);

        Http::assertNothingSent();
    }

    #[Test]
    public function wyjscie_komendy_nie_wypisuje_adresu_webhooka(): void
    {
        // Webhook JEST poświadczeniem: kto zna adres, ten pisze na kanał
        // właściciela. Wyjście tej komendy bywa wklejane do zgłoszeń.
        $this->wlaczKanal();

        $this->artisan('kuking:sprawdz-alarm')
            ->doesntExpectOutputToContain(self::ADRES_WEBHOOKA)
            ->doesntExpectOutputToContain('przyklad.test')
            ->assertExitCode(0);
    }

    #[Test]
    public function wiadomosc_mowi_ze_to_proba_i_ma_znacznik(): void
    {
        $this->wlaczKanal();

        $this->artisan('kuking:sprawdz-alarm')->assertExitCode(0);

        Http::assertSent(function (Request $zadanie): bool {
            $wyslane = (string) ($zadanie->data()['text'] ?? '');

            // Kontrola DODATNIA: odbiorca ma od razu wiedzieć, że to nie awaria.
            $this->assertStringContainsString('PRÓBA KANAŁU ALARMOWEGO', $wyslane);
            $this->assertStringContainsString('to nie jest awaria', $wyslane);
            $this->assertStringContainsString('kuking:sprawdz-alarm', $wyslane);
            $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $wyslane);

            return true;
        });
    }

    #[Test]
    public function naglowek_kuking_jest_w_dostarczonej_wiadomosci_dokladnie_raz(): void
    {
        // Ta sama pułapka, która wyszła 17.09 na prawdziwym odbiorniku:
        // nagłówek dokleja kanał, więc klasa, która dokleja go drugi raz,
        // wysyła „[Kuking/local] [Kuking/local] …", a asercje typu
        // „treść zawiera X" przechodzą przy tym bez mrugnięcia.
        $this->wlaczKanal();

        $this->artisan('kuking:sprawdz-alarm')->assertExitCode(0);

        $naglowek = sprintf('[%s/%s]', config('app.name'), config('app.env'));

        Http::assertSent(function (Request $zadanie) use ($naglowek): bool {
            $wyslane = (string) ($zadanie->data()['text'] ?? '');

            $this->assertSame(1, substr_count($wyslane, $naglowek));
            $this->assertStringStartsWith($naglowek, $wyslane);

            return true;
        });
    }

    #[Test]
    public function proba_nie_zmienia_pamieci_wyciszania_alarmow(): void
    {
        // Gdyby próba zapisywała pamięć wyciszania, prawdziwy alarm tuż po
        // niej zostałby połknięty jako „ten sam stan w oknie ciszy" — czyli
        // sprawdzenie kanału wyłączałoby kanał.
        $this->wlaczKanal();

        $this->artisan('kuking:sprawdz-alarm')->assertExitCode(0);

        $this->assertNull(Cache::get('kuking:polaczenia:ostatni-alarm'));
        $this->assertNull(Cache::get('kuking:kolejka:ostatni-alarm'));
    }

    #[Test]
    public function tresc_nie_niesie_niczego_poza_stalymi_i_znacznikiem(): void
    {
        $tresc = app(SprawdzAlarm::class)->tresc('2026-09-18T10:00:00+00:00');

        $this->assertStringContainsString('2026-09-18T10:00:00+00:00', $tresc);

        // Kontrola UJEMNA: żadnych adresów, kluczy ani nazw baz.
        $this->assertStringNotContainsString('http', $tresc);
        $this->assertStringNotContainsString((string) config('database.connections.pgsql.database'), $tresc);
    }
}

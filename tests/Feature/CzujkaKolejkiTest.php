<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kolejka\AlarmKolejki;
use App\Domain\Kolejka\StanKolejki;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Kolejka, która stanęła, MUSI dać znać — a stare zadania nie mają prawa
 * tego zagłuszyć (issue #599).
 *
 * SKĄD SIĘ WZIĄŁ TEN PLIK
 * `/health` liczy WSZYSTKIE wiersze `failed_jobs` i przy liczbie większej od
 * zera stawia serwis w `degraded`. Na produkcji leżą cztery zadania z 9
 * września 2026 i od tamtego dnia `/health` jest w `degraded` nieprzerwanie —
 * widać to w logu wdrożenia z 17.09.2026 19:58:19 UTC. Piąte zadanie, które
 * padnie dziś w nocy, nie zmieni w tej odpowiedzi ani jednego znaku.
 *
 * Drugi brak jest poważniejszy: NIC nie mierzy zaległości kolejki. Worker,
 * który przestał chodzić, nie zgłasza błędu — po prostu przestaje brać
 * zadania, a potwierdzenia adresu i resety hasła przestają wychodzić w ciszy.
 *
 * CZEGO TEN PLIK NIE DOWODZI: że alarm dociera na produkcję. Kanał
 * `blad_webhook` włącza zmienna, której na produkcji dziś NIE MA. Tutaj
 * dowodzimy logiki i tego, że treść nie wynosi `payload` ani `exception`.
 */
class CzujkaKolejkiTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES_WEBHOOKA = 'https://przyklad.test/webhook-kolejki';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('kuking.kolejka.okno_nieudanych_godzin', 3);
        config()->set('kuking.kolejka.prog_zaleglosci_sekundy', 600);
        config()->set('kuking.kolejka.prog_zawieszenia_sekundy', 1920);
        config()->set('kuking.kolejka.cisza_godzin', 3);
        config()->set('logging.channels.blad_webhook.url', null);

        Cache::flush();
        Carbon::setTestNow('2026-09-17 23:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function wlaczKanalAlarmu(): void
    {
        config()->set('logging.channels.blad_webhook.url', self::ADRES_WEBHOOKA);
        Log::forgetChannel('blad_webhook');
        Http::fake([self::ADRES_WEBHOOKA => Http::response('ok', 200)]);
    }

    /** Zadanie w tabeli `jobs`. `$gotoweOd` to przesunięcie w sekundach wstecz. */
    private function polozZadanie(int $gotoweOd, ?int $zarezerwowaneOd = null): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            // Payload celowo zawiera słowo, którego alarm NIE MA prawa wynieść.
            'payload' => '{"displayName":"App\\\\Jobs\\\\TajneZadanie","data":{"adres":"ktos@przyklad.test"}}',
            'attempts' => 0,
            'reserved_at' => $zarezerwowaneOd === null ? null : Carbon::now()->getTimestamp() - $zarezerwowaneOd,
            'available_at' => Carbon::now()->getTimestamp() - $gotoweOd,
            'created_at' => Carbon::now()->getTimestamp() - max($gotoweOd, 0),
        ]);
    }

    private function polozNieudane(Carbon $kiedy): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{"displayName":"App\\\\Notifications\\\\UstawienieNowegoHasla"}',
            'exception' => 'Symfony\\Component\\Mailer\\Exception\\TransportException: tajny-host.internal odmowa',
            'failed_at' => $kiedy,
        ]);
    }

    // -----------------------------------------------------------------
    //  Stany
    // -----------------------------------------------------------------

    #[Test]
    public function pusta_kolejka_bez_awarii_jest_spokojna(): void
    {
        $wynik = app(StanKolejki::class)->sprawdz();

        $this->assertSame(StanKolejki::SPOKOJNA, $wynik['stan']);
        $this->assertSame(0, $wynik['oczekujace']);
        $this->assertSame(0, $wynik['zaleglosc_sekundy']);
    }

    #[Test]
    public function zadanie_czekajace_dluzej_niz_prog_to_zaleglosc(): void
    {
        $this->polozZadanie(gotoweOd: 900);

        $wynik = app(StanKolejki::class)->sprawdz();

        $this->assertSame(StanKolejki::ZALEGLOSC, $wynik['stan']);
        $this->assertSame(900, $wynik['zaleglosc_sekundy']);
        $this->assertSame(1, $wynik['oczekujace']);
    }

    #[Test]
    public function zadanie_czekajace_krocej_niz_prog_nie_jest_zalegloscia(): void
    {
        // Kontrola UJEMNA progu: bez niej test wyżej pilnowałby tylko
        // „jest wiersz w tabeli, więc alarm".
        $this->polozZadanie(gotoweOd: 60);

        $this->assertSame(StanKolejki::SPOKOJNA, app(StanKolejki::class)->sprawdz()['stan']);
    }

    #[Test]
    public function zadanie_odlozone_na_pozniej_nie_udaje_zaleglosci(): void
    {
        // `--backoff=10,60,300` odkłada nieudane zadanie w przyszłość. Bez
        // warunku `available_at <= teraz` KAŻDE takie odłożenie wyglądałoby
        // jak martwy worker i czujka krzyczałaby przy normalnej pracy.
        $this->polozZadanie(gotoweOd: -3600);

        $wynik = app(StanKolejki::class)->sprawdz();

        $this->assertSame(StanKolejki::SPOKOJNA, $wynik['stan']);
        $this->assertSame(0, $wynik['oczekujace']);
        $this->assertSame(0, $wynik['zaleglosc_sekundy']);
    }

    #[Test]
    public function rezerwacja_starsza_niz_prog_zawieszenia_to_zaleglosc(): void
    {
        // Po `retry_after` (960 s) kolejka sama zwalnia porzuconą rezerwację.
        // Rezerwacja starsza niż dwa takie okresy znaczy, że nie zwolnił jej
        // nikt — czyli nie chodzi też proces, który miał to zrobić.
        $this->polozZadanie(gotoweOd: 5000, zarezerwowaneOd: 4000);

        $wynik = app(StanKolejki::class)->sprawdz();

        $this->assertSame(StanKolejki::ZALEGLOSC, $wynik['stan']);
        $this->assertSame(1, $wynik['zawieszone']);
        $this->assertSame(0, $wynik['oczekujace'], 'Zadanie zarezerwowane nie jest „gotowe do wzięcia".');
    }

    #[Test]
    public function swieza_rezerwacja_nie_jest_zawieszeniem(): void
    {
        $this->polozZadanie(gotoweOd: 5000, zarezerwowaneOd: 30);

        $wynik = app(StanKolejki::class)->sprawdz();

        $this->assertSame(StanKolejki::SPOKOJNA, $wynik['stan']);
        $this->assertSame(0, $wynik['zawieszone']);
    }

    #[Test]
    public function zadanie_ktore_padlo_w_oknie_jest_zdarzeniem(): void
    {
        $this->polozNieudane(Carbon::now()->subMinutes(30));

        $wynik = app(StanKolejki::class)->sprawdz();

        $this->assertSame(StanKolejki::NOWE_NIEUDANE, $wynik['stan']);
        $this->assertSame(1, $wynik['nieudane_w_oknie']);
    }

    #[Test]
    public function stare_nierozliczone_zadania_nie_zagluszaja_nowej_awarii(): void
    {
        // TO JEST CAŁY POWÓD ISTNIENIA TEJ CZUJKI. Cztery zadania z 9 września
        // trzymają `/health` w `degraded` od tygodnia; gdyby czujka też liczyła
        // stan tabeli, nowa awaria nie zmieniłaby w niej niczego.
        foreach (range(1, 4) as $ignored) {
            $this->polozNieudane(Carbon::parse('2026-09-09 14:05:04'));
        }

        $spokoj = app(StanKolejki::class)->sprawdz();

        $this->assertSame(StanKolejki::SPOKOJNA, $spokoj['stan']);
        $this->assertSame(0, $spokoj['nieudane_w_oknie']);
        $this->assertSame(4, $spokoj['nieudane_razem'], 'Stare zadania mają być POLICZONE, tylko nie mają alarmować.');

        // A teraz piąte, dzisiejsze — i TO musi być widać.
        $this->polozNieudane(Carbon::now()->subMinutes(5));

        $po = app(StanKolejki::class)->sprawdz();

        $this->assertSame(StanKolejki::NOWE_NIEUDANE, $po['stan']);
        $this->assertSame(1, $po['nieudane_w_oknie']);
        $this->assertSame(5, $po['nieudane_razem']);
    }

    #[Test]
    public function zaleglosc_wygrywa_z_nowymi_nieudanymi(): void
    {
        $this->polozZadanie(gotoweOd: 900);
        $this->polozNieudane(Carbon::now()->subMinutes(10));

        $this->assertSame(
            StanKolejki::ZALEGLOSC,
            app(StanKolejki::class)->sprawdz()['stan'],
            'Kilka zadań, które padły, znaczy „coś jest zepsute". Zaległość znaczy „nic nie pracuje".',
        );
    }

    // -----------------------------------------------------------------
    //  Treść alarmu
    // -----------------------------------------------------------------

    #[Test]
    public function alarm_mowi_co_zrobic_i_nie_wynosi_payloadu_ani_wyjatku(): void
    {
        $this->polozZadanie(gotoweOd: 900);
        $this->polozNieudane(Carbon::now()->subMinutes(10));

        $tresc = app(AlarmKolejki::class)->tresc(app(StanKolejki::class)->sprawdz());

        // Kontrola DODATNIA.
        $this->assertStringContainsString('kolejka', $tresc);
        $this->assertStringContainsString('kuking:martwe-zadania', $tresc);
        $this->assertStringContainsString('NIE ponawiaj zbiorczo', $tresc);

        // Kontrola UJEMNA: ani `payload`, ani `exception` nie mają prawa
        // wyjść na zewnętrzny webhook (audyt A6-01).
        $this->assertStringNotContainsString('TajneZadanie', $tresc);
        $this->assertStringNotContainsString('ktos@przyklad.test', $tresc);
        $this->assertStringNotContainsString('tajny-host.internal', $tresc);
        $this->assertStringNotContainsString('UstawienieNowegoHasla', $tresc);
    }

    #[Test]
    public function naglowek_kuking_jest_w_dostarczonej_wiadomosci_dokladnie_raz(): void
    {
        // ZNALEZIONE POMIAREM NA PRAWDZIWYM ODBIORNIKU HTTP, nie w tym pliku.
        // `WebhookBleduHandler::tresc()` sam dokleja `[nazwa/środowisko]`.
        // Klasa alarmu, która dokleja go drugi raz, dostarcza
        // „[Kuking/production] [Kuking/production] …", a asercje typu
        // `assertStringContainsString` przechodzą wtedy jak gdyby nigdy nic.
        $this->wlaczKanalAlarmu();
        $this->polozZadanie(gotoweOd: 900);

        app(AlarmKolejki::class)->zadzwonJesliTrzeba(app(StanKolejki::class)->sprawdz());

        $naglowek = sprintf('[%s/%s]', config('app.name'), config('app.env'));

        Http::assertSent(function (Request $zadanie) use ($naglowek): bool {
            $wyslane = (string) ($zadanie->data()['text'] ?? '');

            $this->assertSame(1, substr_count($wyslane, $naglowek), 'Nagłówek dokłada kanał, nie klasa alarmu.');
            $this->assertStringStartsWith($naglowek, $wyslane);

            return true;
        });
    }

    #[Test]
    public function stan_spokojny_nigdy_nie_dzwoni_nawet_z_wlaczonym_kanalem(): void
    {
        $this->wlaczKanalAlarmu();

        $this->assertFalse(app(AlarmKolejki::class)->zadzwonJesliTrzeba(app(StanKolejki::class)->sprawdz()));

        Http::assertNothingSent();
    }

    #[Test]
    public function alarm_milczy_gdy_kanal_webhooka_jest_wylaczony(): void
    {
        Http::fake();
        $this->polozZadanie(gotoweOd: 900);

        $this->assertFalse(
            app(AlarmKolejki::class)->zadzwonJesliTrzeba(app(StanKolejki::class)->sprawdz()),
            'Brak zmiennej = zero efektu. Tak jest DZIŚ na produkcji i test ma to nazywać wprost.',
        );

        Http::assertNothingSent();
    }

    #[Test]
    public function zaleglosc_naprawde_wysyla_wiadomosc_na_kanal(): void
    {
        $this->wlaczKanalAlarmu();
        $this->polozZadanie(gotoweOd: 900);

        $this->assertTrue(app(AlarmKolejki::class)->zadzwonJesliTrzeba(app(StanKolejki::class)->sprawdz()));

        Http::assertSentCount(1);

        Http::assertSent(function (Request $zadanie): bool {
            $wyslane = (string) ($zadanie->data()['text'] ?? '');

            $this->assertStringContainsString('NIE PRACUJE', $wyslane);
            $this->assertStringNotContainsString('TajneZadanie', $wyslane);

            return true;
        });
    }

    // -----------------------------------------------------------------
    //  Powtórzenia i powrót do zdrowia
    // -----------------------------------------------------------------

    #[Test]
    public function ten_sam_stan_w_oknie_ciszy_nie_dzwoni_drugi_raz(): void
    {
        $this->wlaczKanalAlarmu();
        $this->polozZadanie(gotoweOd: 900);

        $alarm = app(AlarmKolejki::class);
        $wynik = app(StanKolejki::class)->sprawdz();

        $this->assertTrue($alarm->zadzwonJesliTrzeba($wynik));
        $this->assertFalse($alarm->zadzwonJesliTrzeba($wynik));
        $this->assertFalse($alarm->zadzwonJesliTrzeba($wynik));

        Http::assertSentCount(1);
    }

    #[Test]
    public function eskalacja_nowych_nieudanych_do_zaleglosci_dzwoni_od_razu(): void
    {
        $this->wlaczKanalAlarmu();
        $alarm = app(AlarmKolejki::class);

        $this->polozNieudane(Carbon::now()->subMinutes(10));
        $pierwszy = app(StanKolejki::class)->sprawdz();
        $this->assertSame(StanKolejki::NOWE_NIEUDANE, $pierwszy['stan']);
        $this->assertTrue($alarm->zadzwonJesliTrzeba($pierwszy));

        $this->polozZadanie(gotoweOd: 900);
        $drugi = app(StanKolejki::class)->sprawdz();
        $this->assertSame(StanKolejki::ZALEGLOSC, $drugi['stan']);

        $this->assertTrue(
            $alarm->zadzwonJesliTrzeba($drugi),
            'Cisza po „coś padło" NIE MA PRAWA zagłuszyć „nic nie pracuje".',
        );

        Http::assertSentCount(2);
    }

    #[Test]
    public function powrot_do_normy_daje_dokladnie_jedna_wiadomosc(): void
    {
        $this->wlaczKanalAlarmu();
        $alarm = app(AlarmKolejki::class);

        $this->polozZadanie(gotoweOd: 900);
        $this->assertTrue($alarm->zadzwonJesliTrzeba(app(StanKolejki::class)->sprawdz()));

        DB::table('jobs')->delete();
        $spokoj = app(StanKolejki::class)->sprawdz();
        $this->assertSame(StanKolejki::SPOKOJNA, $spokoj['stan']);

        $this->assertTrue($alarm->zadzwonJesliTrzeba($spokoj));
        $this->assertFalse($alarm->zadzwonJesliTrzeba($spokoj));

        Http::assertSentCount(2);

        Http::assertSent(fn (Request $z): bool => str_contains((string) ($z->data()['text'] ?? ''), 'wróciła do normy'));
    }

    // -----------------------------------------------------------------
    //  Komenda
    // -----------------------------------------------------------------

    #[Test]
    public function komenda_konczy_sie_sukcesem_gdy_kolejka_pracuje(): void
    {
        $this->artisan('kuking:sprawdz-kolejke', ['--bez-alarmu' => true])->assertExitCode(0);
    }

    #[Test]
    public function komenda_konczy_sie_bledem_gdy_kolejka_zalega(): void
    {
        $this->polozZadanie(gotoweOd: 900);

        $this->artisan('kuking:sprawdz-kolejke', ['--bez-alarmu' => true])->assertExitCode(1);
    }

    #[Test]
    public function komenda_konczy_sie_sukcesem_mimo_starych_nieudanych_zadan(): void
    {
        // Odwrotnie niż `/health`, które przy tych samych czterech wierszach
        // stoi w `degraded` od 9 września i nie da się z tego wyjść inaczej
        // niż rozliczeniem tabeli.
        foreach (range(1, 4) as $ignored) {
            $this->polozNieudane(Carbon::parse('2026-09-09 14:05:04'));
        }

        $this->artisan('kuking:sprawdz-kolejke', ['--bez-alarmu' => true])->assertExitCode(0);
    }

    #[Test]
    public function komenda_bez_przelacznika_dzwoni_a_z_przelacznikiem_milczy(): void
    {
        $this->wlaczKanalAlarmu();
        $this->polozZadanie(gotoweOd: 900);

        $this->artisan('kuking:sprawdz-kolejke', ['--bez-alarmu' => true])->assertExitCode(1);
        Http::assertNothingSent();

        $this->artisan('kuking:sprawdz-kolejke')->assertExitCode(1);
        Http::assertSentCount(1);
    }
}

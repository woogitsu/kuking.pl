<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kolejka\StanKolejki;
use App\Domain\Notifications\Push\KodZamknieciaPush;
use App\Domain\Notifications\Push\StanWysylkiPush;
use App\Domain\Notifications\Push\TransportPush;
use App\Domain\Notifications\Push\WynikWysylkiPush;
use App\Jobs\WyslijPowiadomieniePush;
use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FalszywyTransportPush;
use Tests\TestCase;

/**
 * Trwała porażka Web Push i utracone ponowienie MUSZĄ dać alarm, choć
 * `jobs` i `failed_jobs` są czyste (issue #2053).
 *
 * Do #2053 wyczerpane próby kończyły zadanie `Log::error(); return;` —
 * sukces w oczach kolejki. Utracone ponowienie (#1992) nie zostawiało
 * zadania wcale. Czujka kolejki i `/health` nie miały czego zobaczyć.
 *
 * Każda asercja „cisza” stoi obok kontroli dodatniej w tym samym pliku
 * (`docs/PULAPKI_TESTOW.md` pułapka 4).
 */
final class CzujkaTrwalychPorazekPushTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES_WEBHOOKA = 'https://przyklad.test/webhook-push';

    private const ENDPOINT = 'https://fcm.googleapis.com/fcm/send/tajny-endpoint-2053';

    private const TYTUL = 'Tajny rosół babci Heleny';

    private FalszywyTransportPush $transport;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.strefa' => 'Europe/Warsaw',
            'kuking.push.vapid_public_key' => 'BTestowyKluczPublicznyNieDoWysylki',
            'kuking.push.vapid_private_key' => 'testowy-klucz-prywatny',
            'kuking.notifications.zewnetrzne.wlaczone' => true,
            'kuking.notifications.zewnetrzne.cisza_od_godziny' => 21,
            'kuking.notifications.zewnetrzne.cisza_do_godziny' => 8,
            'kuking.notifications.zewnetrzne.dzienny_limit' => 1,
            'kuking.notifications.zewnetrzne.push_maks_prob_transportu' => 2,
            'kuking.notifications.zewnetrzne.push_osierocenie_minut' => 30,
            'kuking.notifications.zewnetrzne.push_alarm_cisza_godzin' => 24,
            'logging.channels.blad_webhook.url' => null,
        ]);

        $this->transport = new FalszywyTransportPush;
        $this->app->instance(TransportPush::class, $this->transport);

        Cache::flush();
        // 12:00 w Warszawie — poza ciszą nocną.
        $this->travelTo(CarbonImmutable::parse('2026-09-25 10:00:00', 'UTC'));
    }

    public function test_pelna_awaria_transportu_daje_trwala_porazke_i_alarm_przy_pustych_failed_jobs(): void
    {
        [$odbiorca, $powiadomienie] = $this->pelnaAwaria();

        $this->assertNull($powiadomienie->push_wyslano_at);
        $this->assertSame(KodZamknieciaPush::PorazkaTransportu->value, $powiadomienie->push_wynik);

        // TO JEST ISSUE: kolejka nie widzi nic, a push nie doszedł.
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(StanKolejki::SPOKOJNA, app(StanKolejki::class)->sprawdz()['stan']);

        $this->wlaczKanalAlarmu();
        $this->artisan('kuking:sprawdz-push')->assertExitCode(0);

        Http::assertSentCount(1);
        $tresc = $this->wyslanaTresc(0);
        $this->assertStringContainsString('1 grup z trwałą porażką transportu', $tresc);
        $this->assertStringContainsString('porazka_transportu', $tresc);
        $this->assertStringContainsString('0 grup z utraconym ponowieniem', $tresc);

        // Asercja negatywna na konkretne wartości z fixture.
        foreach ([
            (string) $powiadomienie->getKey(),
            (string) $powiadomienie->push_grupa_id,
            (string) $odbiorca->getKey(),
            (string) $odbiorca->profile?->username,
            self::ENDPOINT,
            'tajny-endpoint-2053',
            self::TYTUL,
            (string) $powiadomienie->actor?->profile?->username,
            (string) $powiadomienie->actor?->profile?->display_name,
            (string) $powiadomienie->actor_id,
        ] as $wrazliwe) {
            $this->assertNotSame('', $wrazliwe);
            $this->assertStringNotContainsString($wrazliwe, $tresc);
        }
    }

    public function test_czesciowy_sukces_zamyka_porazka_i_czujka_niczego_nie_ponawia(): void
    {
        $odbiorca = $this->user('odbiorca_czesciowy');
        $dziala = $this->subskrypcja($odbiorca, 'https://fcm.googleapis.com/fcm/send/dziala-2053');
        $pada = $this->subskrypcja($odbiorca, self::ENDPOINT);
        $this->transport->odpowiadaj($pada->endpoint, WynikWysylkiPush::Blad);
        $powiadomienie = $this->powiadomienie($odbiorca);

        Queue::fake();
        (new WyslijPowiadomieniePush((string) $odbiorca->getKey()))->handle($this->transport);
        (new WyslijPowiadomieniePush((string) $odbiorca->getKey(), [(string) $powiadomienie->getKey()],
            pominieteSubskrypcje: [(string) $dziala->getKey()], probaTransportu: 2))->handle($this->transport);

        $this->assertSame([$dziala->endpoint, $pada->endpoint, $pada->endpoint], array_column($this->transport->wyslane, 'endpoint'));
        $this->assertSame(KodZamknieciaPush::PorazkaTransportu->value, $powiadomienie->refresh()->push_wynik);

        $this->wlaczKanalAlarmu();
        Queue::fake();
        $this->artisan('kuking:sprawdz-push')->assertExitCode(0);

        Http::assertSentCount(1);
        Queue::assertNothingPushed();
        $this->assertCount(3, $this->transport->wyslane, 'Czujka nie ma prawa wysłać drugi raz do urządzenia, które już dostało push.');
        $this->assertNull($powiadomienie->refresh()->push_wyslano_at);
        $this->assertNotNull($powiadomienie->push_proba_at, 'Czujka nie zeruje rezerwacji.');

        // Świeże zdarzenie też nie wciąga zamkniętej grupy z powrotem.
        $this->travelTo(CarbonImmutable::parse('2026-09-26 10:00:00', 'UTC'));
        (new WyslijPowiadomieniePush((string) $odbiorca->getKey()))->handle($this->transport);
        $this->assertCount(3, $this->transport->wyslane);
    }

    public function test_utracone_ponowienie_po_progu_alarmuje(): void
    {
        $powiadomienie = $this->rezerwacja(minutTemu: 31);

        $wynik = app(StanWysylkiPush::class)->sprawdz();
        $this->assertSame(StanWysylkiPush::NIEROZLICZONE, $wynik['stan']);
        $this->assertSame(1, $wynik['utracone_ponowienia']);
        $this->assertSame(31 * 60, $wynik['najstarsze_utracone_sekundy']);
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $this->wlaczKanalAlarmu();
        $this->artisan('kuking:sprawdz-push')->assertExitCode(0);
        Http::assertSentCount(1);
        $tresc = $this->wyslanaTresc(0);
        $this->assertStringContainsString('1 grup z utraconym ponowieniem (najstarsza 31 min, próg 30 min)', $tresc);
        $this->assertStringContainsString('utracone_ponowienie', $tresc);
        $this->assertStringNotContainsString((string) $powiadomienie->getKey(), $tresc);
        $this->assertStringNotContainsString((string) $powiadomienie->user_id, $tresc);
    }

    public function test_swieza_rezerwacja_w_toku_nie_alarmuje(): void
    {
        $this->rezerwacja(minutTemu: 29);

        $this->assertSame(StanWysylkiPush::SPOKOJNY, app(StanWysylkiPush::class)->sprawdz()['stan']);

        // Kontrola dodatnia: ta sama rezerwacja po przekroczeniu progu alarmuje.
        $this->travel(2)->minutes();
        $this->assertSame(StanWysylkiPush::NIEROZLICZONE, app(StanWysylkiPush::class)->sprawdz()['stan']);
    }

    public function test_retry_czekajacy_w_kolejce_nie_alarmuje_nawet_po_progu(): void
    {
        $powiadomienie = $this->rezerwacja(minutTemu: 120);
        $innyOdbiorca = $this->user('inny_odbiorca_2053');

        // Zadanie INNEGO odbiorcy nie osłania tej rezerwacji.
        $this->polozZadaniePush((string) $innyOdbiorca->getKey());
        $this->assertSame(StanWysylkiPush::NIEROZLICZONE, app(StanWysylkiPush::class)->sprawdz()['stan']);

        // Ponowienie tego odbiorcy czeka (np. zaległa kolejka) — to nie jest utrata.
        $this->polozZadaniePush((string) $powiadomienie->user_id);
        $wynik = app(StanWysylkiPush::class)->sprawdz();
        $this->assertSame(StanWysylkiPush::SPOKOJNY, $wynik['stan']);
        $this->assertSame(0, $wynik['utracone_ponowienia']);
    }

    public function test_swiadomie_anulowana_grupa_nie_jest_awaria(): void
    {
        $odbiorca = $this->user('odbiorca_anulowany');
        $sub = $this->subskrypcja($odbiorca, self::ENDPOINT);
        $this->transport->odpowiadaj($sub->endpoint, WynikWysylkiPush::Blad);
        $powiadomienie = $this->powiadomienie($odbiorca);

        Queue::fake();
        (new WyslijPowiadomieniePush((string) $odbiorca->getKey()))->handle($this->transport);
        $powiadomienie->forceFill(['read_at' => now()])->save();
        (new WyslijPowiadomieniePush((string) $odbiorca->getKey(), [(string) $powiadomienie->getKey()], probaTransportu: 2))
            ->handle($this->transport);

        $powiadomienie->refresh();
        $this->assertNotNull($powiadomienie->push_zakonczono_at);
        $this->assertSame(KodZamknieciaPush::Anulowano->value, $powiadomienie->push_wynik);

        $this->travel(3)->hours();
        $this->wlaczKanalAlarmu();
        $this->artisan('kuking:sprawdz-push')->assertExitCode(0);
        Http::assertNothingSent();
        $this->assertSame(StanWysylkiPush::SPOKOJNY, app(StanWysylkiPush::class)->sprawdz()['stan']);
    }

    public function test_jeden_epizod_to_jeden_alarm_a_rozliczenie_to_jedno_odwolanie(): void
    {
        $this->pelnaAwaria();
        $this->wlaczKanalAlarmu();

        $this->artisan('kuking:sprawdz-push')->assertExitCode(0);
        $this->travel(1)->hours();
        $this->artisan('kuking:sprawdz-push')->assertExitCode(0);
        $this->travel(1)->hours();
        $this->artisan('kuking:sprawdz-push')->assertExitCode(0);
        Http::assertSentCount(1);

        // Ręczne rozliczenie z runbooka: kod się zmienia, rezerwacja zostaje.
        $zmienione = DB::update(
            "UPDATE notifications SET push_wynik = 'zamknieto_recznie' WHERE push_wynik = 'porazka_transportu' AND push_wyslano_at IS NULL",
        );
        $this->assertSame(1, $zmienione);

        $this->travel(1)->hours();
        $this->artisan('kuking:sprawdz-push')->assertExitCode(0);
        $this->travel(1)->hours();
        $this->artisan('kuking:sprawdz-push')->assertExitCode(0);

        Http::assertSentCount(2);
        $this->assertStringContainsString('rozliczone', $this->wyslanaTresc(1));
    }

    public function test_reczne_zamkniecie_utraconego_ponowienia_konczy_alarm(): void
    {
        $this->rezerwacja(minutTemu: 90);
        $this->wlaczKanalAlarmu();
        $this->artisan('kuking:sprawdz-push');
        Http::assertSentCount(1);

        DB::update(
            "UPDATE notifications SET push_zakonczono_at = now(), push_wynik = 'zamknieto_recznie' "
            .'WHERE push_proba_at IS NOT NULL AND push_wyslano_at IS NULL AND push_zakonczono_at IS NULL',
        );
        $this->artisan('kuking:sprawdz-push');

        Http::assertSentCount(2);
        $this->assertSame(StanWysylkiPush::SPOKOJNY, app(StanWysylkiPush::class)->sprawdz()['stan']);
    }

    public function test_baza_odrzuca_kod_bez_zamkniecia_i_spoza_listy(): void
    {
        $powiadomienie = $this->rezerwacja(minutTemu: 5);

        foreach ([
            "UPDATE notifications SET push_wynik = 'anulowano' WHERE id = ?",
            "UPDATE notifications SET push_zakonczono_at = now(), push_wynik = 'wyslij_jeszcze_raz' WHERE id = ?",
        ] as $sql) {
            try {
                DB::transaction(fn () => DB::update($sql, [$powiadomienie->getKey()]));
                $this->fail('CHECK notifications_push_wynik_check miał odrzucić: '.$sql);
            } catch (QueryException $e) {
                $this->assertStringContainsString('notifications_push_wynik_check', $e->getMessage());
            }
        }

        // Kontrola dodatnia: poprawne zamknięcie przechodzi.
        $this->assertSame(1, DB::update(
            "UPDATE notifications SET push_zakonczono_at = now(), push_wynik = 'zamknieto_recznie' WHERE id = ?",
            [$powiadomienie->getKey()],
        ));
    }

    /** @return array{0: User, 1: Notification} */
    private function pelnaAwaria(): array
    {
        $odbiorca = $this->user('odbiorca_awaria_2053');
        $sub = $this->subskrypcja($odbiorca, self::ENDPOINT);
        $this->transport->odpowiadaj($sub->endpoint, WynikWysylkiPush::Blad);
        $powiadomienie = $this->powiadomienie($odbiorca);

        Queue::fake();
        (new WyslijPowiadomieniePush((string) $odbiorca->getKey()))->handle($this->transport);
        Queue::assertPushed(WyslijPowiadomieniePush::class, 1);

        Queue::fake();
        (new WyslijPowiadomieniePush((string) $odbiorca->getKey(), [(string) $powiadomienie->getKey()], probaTransportu: 2))
            ->handle($this->transport);
        Queue::assertNothingPushed();
        $this->assertCount(2, $this->transport->wyslane, 'Kontrola dodatnia: obie próby doszły do transportu.');

        return [$odbiorca, $powiadomienie->refresh()];
    }

    /** Rezerwacja bez wysyłki i bez zamknięcia — stan, który zostawia utracone ponowienie. */
    private function rezerwacja(int $minutTemu): Notification
    {
        $powiadomienie = $this->powiadomienie($this->user('odbiorca_'.Str::lower(Str::random(8))));
        $powiadomienie->forceFill([
            'push_proba_at' => now()->subMinutes($minutTemu),
            'push_grupa_id' => (string) Str::uuid(),
        ])->save();

        return $powiadomienie;
    }

    private function polozZadaniePush(string $userId): void
    {
        $komenda = serialize(new WyslijPowiadomieniePush($userId, [], null, [], 2));
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => (string) json_encode([
                'displayName' => WyslijPowiadomieniePush::class,
                'data' => ['commandName' => WyslijPowiadomieniePush::class, 'command' => $komenda],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp() + 30,
            'created_at' => now()->getTimestamp(),
        ]);
    }

    private function wlaczKanalAlarmu(): void
    {
        config()->set('logging.channels.blad_webhook.url', self::ADRES_WEBHOOKA);
        Log::forgetChannel('blad_webhook');
        Http::fake([self::ADRES_WEBHOOKA => Http::response('ok', 200)]);
    }

    private function wyslanaTresc(int $ktora): string
    {
        $wyslane = Http::recorded(fn (Request $r): bool => $r->url() === self::ADRES_WEBHOOKA)->values();
        $this->assertArrayHasKey($ktora, $wyslane->all());

        return (string) json_encode($wyslane[$ktora][0]->data(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function subskrypcja(User $user, string $endpoint): PushSubscription
    {
        $sub = new PushSubscription;
        $sub->forceFill([
            'user_id' => $user->getKey(),
            'endpoint' => $endpoint,
            'klucz_p256dh' => str_repeat('A', 87),
            'klucz_auth' => str_repeat('B', 22),
            'kodowanie' => 'aes128gcm',
        ])->save();

        return $sub;
    }

    private function powiadomienie(User $odbiorca): Notification
    {
        return Notification::create([
            'user_id' => $odbiorca->getKey(),
            'actor_id' => $this->user('kucharz_'.Str::lower(Str::random(8)), ['display_name' => 'Helena Aktorka'])->getKey(),
            'type' => Notification::TYPE_COOKED,
            'data' => ['recipe_title' => self::TYTUL],
        ]);
    }
}

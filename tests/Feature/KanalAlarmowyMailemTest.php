<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Monitoring\KanalAlarmowy;
use App\Logging\EmailBleduHandler;
use App\Logging\KanalyAlarmowe;
use App\Mail\AlarmOperacyjny;
use App\Poczta\DziennyBudzetListow;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Drugi kanał alarmowy: poczta obok Discorda (#599).
 *
 * Ścieżka jest PRAWDZIWA: `report()` → `bootstrap/app.php` → `SeriaAlarmow`
 * → `KanalyAlarmowe` → oba handlery. Poczta przez `Mail::fake()` (nic nie
 * wychodzi), Discord przez `Http::fake()`.
 *
 * Kontrakt:
 *   1. obie zmienne puste = nic nie wychodzi;
 *   2. sama poczta wystarczy, a list nie niesie komunikatu wyjątku (A6-01);
 *   3. okno serii jest wspólne: sto identycznych błędów = jedna wiadomość
 *      na Discordzie i JEDEN list;
 *   4. awaria Discorda nie zabiera listu, awaria poczty nie zabiera Discorda
 *      ani nie przewraca raportowania wyjątku;
 *   5. dobowy sufit listów alarmowych trzyma pulę poczty;
 *   6. `kuking:sprawdz-alarm` melduje o każdym kanale osobno i nie wypisuje
 *      adresów.
 */
final class KanalAlarmowyMailemTest extends TestCase
{
    private const WEBHOOK = 'https://discord.example.test/api/webhooks/000/tajny-token/slack';

    private const SKRZYNKA = 'operator-alarmy@kuking.test';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('kuking.monitoring.seria_okno_minut', 15);
        $this->ustaw(webhook: null, poczta: null);
    }

    private function ustaw(?string $webhook, ?string $poczta): void
    {
        config()->set('logging.channels.blad_webhook.url', $webhook);
        config()->set('logging.channels.blad_email.adres', $poczta);

        // Kanały są zapamiętywane po pierwszym użyciu — bez tego pamiętany
        // egzemplarz zostałby z poprzednim adresem.
        Log::forgetChannel(KanalyAlarmowe::DISCORD);
        Log::forgetChannel(KanalyAlarmowe::POCZTA);
    }

    private function awaria(): RuntimeException
    {
        return new RuntimeException('SQLSTATE[23505] Key (email)=(ktos@example.com) already exists, haslo=Tajne123!');
    }

    /** @return list<string> */
    private function naDiscordzie(): array
    {
        return Http::recorded()
            ->filter(fn (array $para): bool => $para[0]->url() === self::WEBHOOK)
            ->map(fn (array $para): string => (string) ($para[0]['text'] ?? ''))
            ->values()
            ->all();
    }

    #[Test]
    public function bez_obu_zmiennych_nic_nie_wychodzi(): void
    {
        Mail::fake();
        Http::fake();

        report($this->awaria());

        Mail::assertNothingSent();
        Http::assertNothingSent();
        $this->assertFalse(KanalyAlarmowe::wlaczony());
    }

    #[Test]
    public function sama_poczta_wystarczy_a_list_nie_niesie_komunikatu_wyjatku(): void
    {
        Mail::fake();
        Http::fake();
        $this->ustaw(webhook: null, poczta: self::SKRZYNKA);

        report($this->awaria());

        Http::assertNothingSent();
        Mail::assertSent(AlarmOperacyjny::class, function (AlarmOperacyjny $list): bool {
            $this->assertTrue($list->hasTo(self::SKRZYNKA));
            $this->assertStringContainsString('RuntimeException', $list->tresc);
            $this->assertStringContainsString('odcisk:', $list->tresc);

            foreach ([$list->tresc, $list->temat] as $tekst) {
                $this->assertStringNotContainsString('ktos@example.com', $tekst);
                $this->assertStringNotContainsString('Tajne123', $tekst);
                $this->assertStringNotContainsString('SQLSTATE', $tekst);
            }

            $this->assertStringNotContainsString("\n", $list->temat);

            return true;
        });
        Mail::assertSentCount(1);
    }

    #[Test]
    public function seria_stu_identycznych_bledow_to_jedna_wiadomosc_i_jeden_list(): void
    {
        Mail::fake();
        Http::fake([self::WEBHOOK => Http::response('ok', 200)]);
        $this->ustaw(webhook: self::WEBHOOK, poczta: self::SKRZYNKA);

        $wyjatek = $this->awaria();
        for ($i = 0; $i < 100; $i++) {
            report($wyjatek);
        }

        $this->assertCount(1, $this->naDiscordzie());
        Mail::assertSentCount(1);
    }

    #[Test]
    public function inna_awaria_w_tym_samym_oknie_idzie_osobnym_listem(): void
    {
        Mail::fake();
        Http::fake([self::WEBHOOK => Http::response('ok', 200)]);
        $this->ustaw(webhook: self::WEBHOOK, poczta: self::SKRZYNKA);

        report($this->awaria());
        report(new LogicException('inna'));

        $this->assertCount(2, $this->naDiscordzie());
        Mail::assertSentCount(2);
    }

    #[Test]
    public function odwolany_webhook_nie_zabiera_listu(): void
    {
        Mail::fake();
        Http::fake([self::WEBHOOK => Http::response('Unknown Webhook', 404)]);
        $this->ustaw(webhook: self::WEBHOOK, poczta: self::SKRZYNKA);

        $this->assertTrue(KanalyAlarmowe::zadzwon('czujka: próba'));

        Mail::assertSentCount(1);
        $this->assertSame([KanalyAlarmowe::DISCORD => false, KanalyAlarmowe::POCZTA => true], KanalyAlarmowe::wyniki());
    }

    #[Test]
    public function awaria_poczty_nie_zabiera_discorda_ani_nie_przewraca_raportu(): void
    {
        Http::fake([self::WEBHOOK => Http::response('ok', 200)]);
        $this->ustaw(webhook: self::WEBHOOK, poczta: self::SKRZYNKA);

        // Transport rzuca przy wysyłce. Jedno wywołanie — nie pętla: wpis
        // o nieudanym liście idzie na `stderr`, nie z powrotem pocztą.
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('smtp: 550 rejected operator-alarmy@kuking.test'));

        report($this->awaria());

        $this->assertCount(1, $this->naDiscordzie());
        $this->assertSame([KanalyAlarmowe::DISCORD => true, KanalyAlarmowe::POCZTA => false], KanalyAlarmowe::wyniki());
        // Miejsce w puli wraca, skoro list nie wyszedł.
        $this->assertSame(0, DziennyBudzetListow::dlaAlarmuOperacyjnego()->zuzyte());
    }

    #[Test]
    public function handler_poczty_sam_nie_rzuca_przy_awarii_transportu(): void
    {
        // Osobno od `KanalyAlarmowe`, które ma własny `try`: to dwie
        // niezależne linie obrony i każda ma własny test.
        $this->ustaw(webhook: null, poczta: self::SKRZYNKA);
        Mail::shouldReceive('to')->once()->andThrow(new TransportException('niedostępny'));

        Log::channel(KanalyAlarmowe::POCZTA)->error('czujka: próba');

        $this->assertFalse(EmailBleduHandler::ostatniaWysylkaSieUdala());
    }

    #[Test]
    public function sam_nieudany_list_nie_kupuje_ciszy_okna(): void
    {
        $this->ustaw(webhook: null, poczta: self::SKRZYNKA);
        Mail::shouldReceive('to')->andThrow(new TransportException('niedostępny'));

        $this->assertFalse((new KanalAlarmowy)->przyjal('czujka: próba'));
    }

    #[Test]
    public function dobowy_sufit_listow_trzyma_pule_a_discord_dostaje_wszystko(): void
    {
        Mail::fake();
        Http::fake([self::WEBHOOK => Http::response('ok', 200)]);
        config()->set('kuking.poczta.alarm_operacyjny_na_dobe', 2);
        $this->ustaw(webhook: self::WEBHOOK, poczta: self::SKRZYNKA);

        foreach (['a', 'b', 'c'] as $czujka) {
            KanalyAlarmowe::zadzwon("czujka {$czujka}: awaria");
        }

        Mail::assertSentCount(2);
        $discord = $this->naDiscordzie();
        $this->assertCount(3, array_filter($discord, fn (string $t): bool => str_contains($t, 'czujka')));
        // Zużycie sufitu listów alarmowych samo jest sygnałem — idzie na
        // Discord zdaniem z liczbami (ostrzeżenie `DziennyBudzetListow`).
        $this->assertCount(1, array_filter($discord, fn (string $t): bool => str_contains($t, 'alarm-operacyjny')));
        $this->assertFalse(KanalyAlarmowe::wyniki()[KanalyAlarmowe::POCZTA]);
    }

    #[Test]
    public function sprawdz_alarm_melduje_o_kazdym_kanale_i_nie_wypisuje_adresow(): void
    {
        Mail::fake();
        Http::fake([self::WEBHOOK => Http::response('ok', 200)]);
        $this->ustaw(webhook: self::WEBHOOK, poczta: self::SKRZYNKA);

        $this->artisan('kuking:sprawdz-alarm')
            ->expectsOutputToContain('Discord (LOG_BLAD_WEBHOOK_URL) PRZYJĄŁ')
            ->expectsOutputToContain('poczta (KUKING_ALARM_EMAIL) PRZYJĄŁ')
            ->doesntExpectOutputToContain(self::SKRZYNKA)
            ->doesntExpectOutputToContain('tajny-token')
            ->assertExitCode(0);

        Mail::assertSent(AlarmOperacyjny::class, fn (AlarmOperacyjny $list): bool => str_contains($list->tresc, 'PRÓBA KANAŁU ALARMOWEGO'));
        Http::assertSent(fn (Request $zadanie): bool => str_contains((string) ($zadanie['text'] ?? ''), 'PRÓBA KANAŁU ALARMOWEGO'));
    }

    #[Test]
    public function sprawdz_alarm_oblewa_gdy_poczta_nie_przyjela_choc_discord_tak(): void
    {
        Http::fake([self::WEBHOOK => Http::response('ok', 200)]);
        $this->ustaw(webhook: self::WEBHOOK, poczta: self::SKRZYNKA);
        Mail::shouldReceive('to')->andThrow(new TransportException('smtp: 550 rejected operator-alarmy@kuking.test'));

        $this->artisan('kuking:sprawdz-alarm')
            ->expectsOutputToContain('Discord (LOG_BLAD_WEBHOOK_URL) PRZYJĄŁ')
            ->expectsOutputToContain('NIE ZOSTAŁA PRZYJĘTA przez kanał poczta (KUKING_ALARM_EMAIL)')
            ->doesntExpectOutputToContain(self::SKRZYNKA)
            ->doesntExpectOutputToContain('550')
            ->assertExitCode(1);
    }

    #[Test]
    public function sprawdz_alarm_bez_wysylki_pokazuje_stan_obu_kanalow(): void
    {
        Mail::fake();
        Http::fake();
        $this->ustaw(webhook: null, poczta: self::SKRZYNKA);

        $this->artisan('kuking:sprawdz-alarm', ['--bez-wysylki' => true])
            ->expectsOutputToContain('Discord (LOG_BLAD_WEBHOOK_URL): wyłączony')
            ->expectsOutputToContain('poczta (KUKING_ALARM_EMAIL): skonfigurowany')
            ->assertExitCode(0);

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    /**
     * `--przez-wyjatek` (#2223) idzie przez `report()`, nie przez
     * `zadzwon()` wprost — też musi meldować o KAŻDYM kanale. Sama poczta
     * wystarcza, a list niesie klasę wyjątku, nie treść próby.
     */
    #[Test]
    public function proba_przez_wyjatek_dochodzi_samym_listem(): void
    {
        Mail::fake();
        Http::fake();
        $this->ustaw(webhook: null, poczta: self::SKRZYNKA);

        $this->artisan('kuking:sprawdz-alarm', ['--przez-wyjatek' => true])
            ->expectsOutputToContain('poczta (KUKING_ALARM_EMAIL) PRZYJĄŁ')
            ->expectsOutputToContain('drogą błędu 500')
            ->doesntExpectOutputToContain(self::SKRZYNKA)
            ->assertExitCode(0);

        Mail::assertSent(AlarmOperacyjny::class, fn (AlarmOperacyjny $list): bool => str_contains($list->tresc, 'RuntimeException')
            && ! str_contains($list->tresc, 'PRÓBA KANAŁU'));
        Http::assertNothingSent();
    }

    #[Test]
    public function proba_przez_wyjatek_oblewa_gdy_jeden_z_kanalow_nie_przyjal(): void
    {
        Http::fake([self::WEBHOOK => Http::response('nie ma', 404)]);
        Mail::fake();
        $this->ustaw(webhook: self::WEBHOOK, poczta: self::SKRZYNKA);

        $this->artisan('kuking:sprawdz-alarm', ['--przez-wyjatek' => true])
            ->expectsOutputToContain('poczta (KUKING_ALARM_EMAIL) PRZYJĄŁ')
            ->expectsOutputToContain('NIE ZOSTAŁA PRZYJĘTA przez kanał Discord (LOG_BLAD_WEBHOOK_URL)')
            ->doesntExpectOutputToContain('tajny-token')
            ->assertExitCode(1);

        Mail::assertSent(AlarmOperacyjny::class, 1);
    }

    /**
     * Wynik cudzej, wcześniejszej próby w tym samym procesie nie może
     * udawać wyniku próby pominiętej przez okno serii.
     */
    #[Test]
    public function proba_przez_wyjatek_w_oknie_serii_nie_czyta_cudzego_wyniku(): void
    {
        Mail::fake();
        Http::fake([self::WEBHOOK => Http::response('ok', 200)]);
        $this->ustaw(webhook: self::WEBHOOK, poczta: self::SKRZYNKA);

        $this->artisan('kuking:sprawdz-alarm', ['--przez-wyjatek' => true])->assertExitCode(0);
        // Cudzy sukces w pamięci kanałów tuż przed drugą próbą.
        KanalyAlarmowe::zadzwon('inna czujka');

        $this->artisan('kuking:sprawdz-alarm', ['--przez-wyjatek' => true])
            ->expectsOutputToContain('NIE DOSZŁA')
            ->assertExitCode(1);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }
}

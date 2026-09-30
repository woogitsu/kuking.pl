<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\AlarmSufituPotwierdzen;
use App\Logging\WebhookBleduHandler;
use App\Models\Report;
use App\Notifications\PotwierdzenieZgloszeniaNielegalnejTresci;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * ZNACZNIK `receipt_sent_at` NIE KŁAMIE PO AWARII POCZTY (issue #2218).
 *
 * Kolejka produkcyjna jest bazodanowa, więc znacznik ustawiany razem
 * ze zleceniem listu stał już przy wpisie do `jobs`. Gdy dostawca poczty
 * padał PÓŹNIEJ, w workerze, baza mówiła „potwierdzono", a list nie wyszedł —
 * i nic tej sprawy nie ponawiało, zwłaszcza zgłoszenia prawnego bez konta
 * (`reporter_id IS NULL`), które dosyłka pomijała.
 *
 * Testy chodzą na kolejce `database` (nie `sync`, który chowałby błąd:
 * wyjątek cofnąłby transakcję zlecenia) i na prawdziwym workerze.
 * Awarię dostawcy wstrzykuje słuchacz `MessageSending`. Poczta nie wychodzi
 * nigdzie: mailer testowy to `array`, a udane dosłanie mierzy
 * `Notification::fake()`.
 */
class PotwierdzenieOdbioruPoDostawieTest extends TestCase
{
    use RefreshDatabase;

    private const KOMENDA = 'kuking:dosylaj-potwierdzenia-zgloszen';

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'database']);
    }

    private function zlozAnonimoweZgloszenie(): Report
    {
        $this->post(route('zglos.nielegalna.store'), [
            'notifier_name' => 'Anna Kowalska',
            'notifier_email' => 'anna@kancelaria.example',
            'target_url' => 'https://kuking.pl/przepis/rosol-babci-zofii',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody z mojej książki.',
            'good_faith' => '1',
        ])->assertSessionHasNoErrors();

        return Report::where('source', Report::SOURCE_LEGAL_NOTICE)->firstOrFail();
    }

    private function poczta_pada(): void
    {
        Event::listen(MessageSending::class, function (): void {
            throw new RuntimeException('Dostawca poczty nie odpowiada.');
        });
    }

    private function workerJedenPrzebieg(): void
    {
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--tries' => 1, '--sleep' => 0]);
    }

    public function test_awaria_dostawcy_po_zleceniu_nie_zostawia_znacznika_potwierdzenia(): void
    {
        $this->poczta_pada();

        $sprawa = $this->zlozAnonimoweZgloszenie();

        // Zlecenie doszło do kolejki, znacznik stoi — tak jak w produkcji.
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertNotNull($sprawa->refresh()->receipt_sent_at);

        $this->workerJedenPrzebieg();

        $this->assertSame(1, DB::table('failed_jobs')->count(), 'Porażka listu ma zostać w failed_jobs jako ślad.');
        $this->assertNull(
            $sprawa->refresh()->receipt_sent_at,
            'Baza mówi „potwierdzono", a list nie wyszedł — fałszywy znacznik doręczenia.',
        );
    }

    public function test_anonimowe_zgloszenie_z_adresem_jest_dosylane_po_awarii_i_tylko_raz(): void
    {
        $this->poczta_pada();

        $sprawa = $this->zlozAnonimoweZgloszenie();
        $this->workerJedenPrzebieg();
        $this->assertNull($sprawa->refresh()->receipt_sent_at);

        // Dostawca wraca do życia; od tej chwili obserwujemy tylko zlecenia.
        Event::forget(MessageSending::class);
        Notification::fake();

        $this->artisan(self::KOMENDA)
            ->expectsOutput('Zaległych potwierdzeń w bazie: 1.')
            ->expectsOutput('Dosłano potwierdzeń: 1.')
            ->assertSuccessful();

        Notification::assertSentOnDemandTimes(PotwierdzenieZgloszeniaNielegalnejTresci::class, 1);
        $this->assertNotNull($sprawa->refresh()->receipt_sent_at);

        // Idempotencja: drugi przebieg niczego nie dokłada.
        $this->artisan(self::KOMENDA)
            ->expectsOutput('Zaległych potwierdzeń w bazie: 0.')
            ->assertSuccessful();

        Notification::assertSentOnDemandTimes(PotwierdzenieZgloszeniaNielegalnejTresci::class, 1);
    }

    /**
     * Adres, który dostawca odrzuca zawsze: dosyłka co godzinę nie może
     * ponawiać listu bez końca. Po `LIMIT_PORAZEK_LISTU` ostatecznych
     * porażkach przestaje zlecać list, a sprawa dalej jest zaległością.
     */
    public function test_stale_odrzucany_adres_nie_jest_ponawiany_bez_konca(): void
    {
        $this->poczta_pada();

        $sprawa = $this->zlozAnonimoweZgloszenie();
        $this->workerJedenPrzebieg();

        for ($i = 1; $i < PotwierdzenieZgloszeniaNielegalnejTresci::LIMIT_PORAZEK_LISTU; $i++) {
            $this->artisan(self::KOMENDA)->expectsOutput('Dosłano potwierdzeń: 1.')->assertSuccessful();
            $this->workerJedenPrzebieg();
        }

        $this->assertSame(PotwierdzenieZgloszeniaNielegalnejTresci::LIMIT_PORAZEK_LISTU, DB::table('failed_jobs')->count());
        $this->assertNull($sprawa->refresh()->receipt_sent_at);

        $this->artisan(self::KOMENDA)
            ->expectsOutput('Zaległych potwierdzeń w bazie: 1.')
            ->expectsOutput('Dosłano potwierdzeń: 0.')
            ->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count(), 'Dosyłka zleciła list na adres, który dostawca odrzucił już '.PotwierdzenieZgloszeniaNielegalnejTresci::LIMIT_PORAZEK_LISTU.' razy.');
        $this->assertNull($sprawa->refresh()->receipt_sent_at);
    }

    /**
     * SUFIT PRÓB DOCHODZI DO OPERATORA (#2218). Wcześniej komenda robiła
     * tylko `warn()` z kodem 0, a `Harmonogram` czyta wyjście wyłącznie przy
     * kodzie ≠ 0 — z harmonogramu zostawało samo „DONE". Teraz: jeden alarm
     * na kanale alarmowym (bez adresu i numeru sprawy), cisza przy kolejnym
     * przebiegu, jedno odwołanie, gdy sprawy na suficie znikną; kod wyjścia 0
     * (inaczej wiadomość co godzinę — `AlarmSufituPotwierdzen`).
     */
    public function test_sprawa_na_suficie_prob_daje_jeden_alarm_operatorowi_i_odwolanie(): void
    {
        $this->poczta_pada();

        $sprawa = $this->zlozAnonimoweZgloszenie();
        $this->workerJedenPrzebieg();

        for ($i = 1; $i < PotwierdzenieZgloszeniaNielegalnejTresci::LIMIT_PORAZEK_LISTU; $i++) {
            $this->artisan(self::KOMENDA)->assertSuccessful();
            $this->workerJedenPrzebieg();
        }

        // Kanał alarmowy włączamy dopiero teraz: wcześniejsze porażki listu
        // idą przez `report()` i same trafiłyby na webhook błędów.
        $this->wlaczKanalAlarmowy();
        $ostrzezenia = [];
        Event::listen(MessageLogged::class, function (MessageLogged $wpis) use (&$ostrzezenia): void {
            if ($wpis->level === 'warning' && ($wpis->context['stage'] ?? null) === 'dosylka_potwierdzen_sufit') {
                $ostrzezenia[] = $wpis->context;
            }
        });

        $this->artisan(self::KOMENDA)
            ->expectsOutput('Spraw na suficie prób listu (dosyłka ich nie ponawia, alarm do operatora): 1.')
            ->assertSuccessful();

        Http::assertSentCount(1);
        $tresc = (string) Http::recorded()->first()[0]['text'];
        $this->assertStringContainsString('1 zgłoszeń prawnych bez konta', $tresc);
        $this->assertStringContainsString('Co zrobić', $tresc);
        $this->assertStringNotContainsString('anna@kancelaria.example', $tresc);
        $this->assertStringNotContainsString((string) $sprawa->refresh()->numer_sprawy, $tresc);
        // Ślad w dzienniku serwera (wzorzec IN-05) — z numerem sprawy, bez adresu.
        $this->assertSame([[
            'stage' => 'dosylka_potwierdzen_sufit',
            'na_suficie' => 1,
            'numery_spraw' => [(string) $sprawa->numer_sprawy],
        ]], $ostrzezenia);

        // Ten sam stan w oknie ciszy: bez drugiej wiadomości.
        $this->artisan(self::KOMENDA)->assertSuccessful();
        Http::assertSentCount(1);

        // Ktoś potwierdził sprawę ręcznie — jedno odwołanie, potem cisza.
        $sprawa->forceFill(['receipt_sent_at' => now()])->save();
        $this->artisan(self::KOMENDA)->assertSuccessful();
        Http::assertSentCount(2);
        $this->assertStringContainsString('żadna sprawa nie stoi już na suficie', (string) Http::recorded()->last()[0]['text']);
        $this->assertNull(cache()->get(AlarmSufituPotwierdzen::KLUCZ));

        $this->artisan(self::KOMENDA)->assertSuccessful();
        Http::assertSentCount(2);
    }

    /** Kontrola ujemna: sprawa poniżej sufitu (zwykła zaległość) nie budzi operatora. */
    public function test_zalegle_potwierdzenie_ponizej_sufitu_nie_alarmuje(): void
    {
        $this->poczta_pada();

        $this->zlozAnonimoweZgloszenie();
        $this->workerJedenPrzebieg();

        $this->wlaczKanalAlarmowy();

        $this->artisan(self::KOMENDA)
            ->expectsOutput('Dosłano potwierdzeń: 1.')
            ->doesntExpectOutputToContain('suficie')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    private function wlaczKanalAlarmowy(): void
    {
        $url = 'https://przyklad.test/alarm-sufitu';
        config()->set('logging.channels.blad_webhook.url', $url);
        Log::forgetChannel('blad_webhook');
        WebhookBleduHandler::zapomnijOstatniaWysylke();
        Http::fake([$url => Http::response('', 204)]);
    }

    protected function tearDown(): void
    {
        WebhookBleduHandler::zapomnijOstatniaWysylke();
        Log::forgetChannel('blad_webhook');
        parent::tearDown();
    }

    public function test_udane_potwierdzenie_zostawia_znacznik(): void
    {
        $sprawa = $this->zlozAnonimoweZgloszenie();

        $this->workerJedenPrzebieg();

        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertNotNull($sprawa->refresh()->receipt_sent_at);
    }
}

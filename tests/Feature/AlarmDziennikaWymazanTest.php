<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Monitoring\AlarmDziennikaWymazan;
use App\Logging\WebhookBleduHandler;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Alarm „wymazania stoją, bo dziennik wymazań nie przyjmuje wpisów”
 * (issue #2038, etap 3): licznik kolejnych nocy, reset po przebiegu bez
 * porażki dziennika, jedna wiadomość na kanale `blad_webhook` (D-041, #599)
 * i jedno odwołanie po powrocie do normy.
 *
 * Prawdziwy egzekutor, prawdziwy `EpizodAlarmu` i handler; atrapa wyłącznie
 * dysku dziennika i HTTP. Nie dowodzi, że wiadomość dociera na produkcję.
 */
class AlarmDziennikaWymazanTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://przyklad.test/dziennik-wymazan';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('cache.default', 'array');
        Cache::flush();
        config()->set('kuking.dziennik_wymazan.dysk', 'dziennik_test');
        config()->set('kuking.dziennik_wymazan.alarm_po_nocach', 3);
        config()->set('kuking.dziennik_wymazan.alarm_cisza_godzin', 72);
        config()->set('filesystems.disks.dziennik_test', ['driver' => 'local', 'root' => storage_path('framework/testing/dziennik')]);
        config()->set('logging.channels.blad_webhook.url', self::URL);
        Log::forgetChannel('blad_webhook');
        Storage::fake('dziennik_test');
        Sleep::fake();
        Http::fake([self::URL => Http::response('', 204)]);
        WebhookBleduHandler::zapomnijOstatniaWysylke();
        $this->travelTo(now()->startOfDay()->addHours(3)->addMinutes(50));
    }

    protected function tearDown(): void
    {
        WebhookBleduHandler::zapomnijOstatniaWysylke();
        Log::forgetChannel('blad_webhook');
        parent::tearDown();
    }

    /** Dysk dziennika: `true` = zapisy rzucają, `false` = działa jak prawdziwy. */
    private function magazynPada(bool $pada): void
    {
        Storage::fake('dziennik_test');

        if (! $pada) {
            return;
        }

        $dysk = Mockery::mock(Storage::disk('dziennik_test'))->makePartial();
        $dysk->shouldReceive('put', 'makeDirectory')->andThrow(new RuntimeException('R2 nie odpowiada'));
        Storage::set('dziennik_test', $dysk);
    }

    private function kontoPoKarencji(): User
    {
        $konto = User::factory()->create();
        $konto->fresh()->markForDeletion(User::DELETE_SCOPE_MINIMUM);
        $konto->forceFill(['delete_requested_at' => now()->subDays(31)])->save();

        return $konto;
    }

    private function noc(): void
    {
        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();
        $this->travel(1)->day();
    }

    private function wyslane(): int
    {
        return Http::recorded()->count();
    }

    public function test_alarm_dopiero_po_progu_kolejnych_nocy_i_dokladnie_raz(): void
    {
        $this->magazynPada(true);
        $konto = $this->kontoPoKarencji();

        $this->noc();
        $this->noc();
        $this->assertSame(0, $this->wyslane(), 'Dwie noce to jeszcze czkawka magazynu, nie alarm.');

        $this->noc();
        $this->assertSame(1, $this->wyslane());
        $tresc = Http::recorded()->first()[0]['text'];
        $this->assertStringContainsString('3 noce z rzędu', $tresc);
        $this->assertStringContainsString('Co zrobić', $tresc);
        $this->assertStringNotContainsString((string) $konto->getKey(), $tresc);
        $this->assertStringNotContainsString($konto->email, $tresc);

        // Awaria trwa, ale jesteśmy w oknie ciszy (72 h): kolejne noce nie dzwonią.
        $this->noc();
        $this->noc();
        $this->assertSame(1, $this->wyslane());

        // Konto nadal nietknięte — bezpieczeństwo wariantu A.
        $this->assertSame(User::STATUS_PENDING_DELETE, $konto->fresh()->status);
    }

    public function test_przebieg_bez_porazki_dziennika_zeruje_licznik_i_daje_jedno_odwolanie(): void
    {
        $this->magazynPada(true);
        $pierwsze = $this->kontoPoKarencji();
        $this->noc();
        $this->noc();

        // Magazyn wraca: konto się wymazuje, licznik spada do zera.
        $this->magazynPada(false);
        $this->noc();
        $this->assertSame(User::STATUS_ERASED, $pierwsze->fresh()->status);
        $this->assertNull(Cache::get(AlarmDziennikaWymazan::KLUCZ_LICZNIKA));
        $this->assertSame(0, $this->wyslane());

        // Dwie kolejne noce porażki to NIE trzy z rzędu (przerwa się liczy).
        $this->magazynPada(true);
        $this->kontoPoKarencji();
        $this->noc();
        $this->noc();
        $this->assertSame(0, $this->wyslane());

        $this->noc();
        $this->assertSame(1, $this->wyslane(), 'Trzecia noc z rzędu po resecie daje alarm.');

        // Powrót do normy: dokładnie jedno odwołanie, potem cisza.
        $this->magazynPada(false);
        $this->noc();
        $this->assertSame(2, $this->wyslane());
        $this->assertStringContainsString('znów przyjmuje wpisy', Http::recorded()->last()[0]['text']);
        $this->noc();
        $this->assertSame(2, $this->wyslane());
    }

    public function test_drugi_przebieg_tego_samego_dnia_nie_nabija_licznika(): void
    {
        $this->magazynPada(true);
        $this->kontoPoKarencji();

        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();
        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();
        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();

        $this->assertSame(1, Cache::get(AlarmDziennikaWymazan::KLUCZ_LICZNIKA)['noce']);
        $this->assertSame(0, $this->wyslane());
    }

    public function test_przebieg_probny_i_pusta_kolejka_dzialaja_zgodnie_z_kontraktem(): void
    {
        $this->magazynPada(true);
        $konto = $this->kontoPoKarencji();
        $this->noc();
        $noce = Cache::get(AlarmDziennikaWymazan::KLUCZ_LICZNIKA)['noce'];
        $this->assertSame(1, $noce);

        // --dry-run niczego nie zmienia.
        $this->artisan('kuking:usun-wygasle-konta', ['--dry-run' => true])->assertSuccessful();
        $noce = Cache::get(AlarmDziennikaWymazan::KLUCZ_LICZNIKA)['noce'];
        $this->assertSame(1, $noce);

        // Człowiek cofnął usunięcie: kolejka pusta, nic nie czeka, licznik zerowy.
        $konto->forceFill(['status' => User::STATUS_ACTIVE, 'delete_requested_at' => null])->save();
        $this->travel(1)->day();
        $this->artisan('kuking:usun-wygasle-konta')->assertSuccessful();
        $this->assertNull(Cache::get(AlarmDziennikaWymazan::KLUCZ_LICZNIKA));
    }

    public function test_przebieg_bez_porazki_dziennika_zeruje_licznik_takze_gdy_konto_czeka_z_innej_przyczyny(): void
    {
        $alarm = app(AlarmDziennikaWymazan::class);
        $alarm->zapiszPrzebieg(1, 1);
        $this->assertSame(1, Cache::get(AlarmDziennikaWymazan::KLUCZ_LICZNIKA)['noce']);

        // Przebieg z nieudanym kontem, ale nie z powodu dziennika (0 porażek dziennika).
        $alarm->zapiszPrzebieg(0, 1);
        $this->assertNull(Cache::get(AlarmDziennikaWymazan::KLUCZ_LICZNIKA));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\AlarmujModeratora;
use App\Domain\Moderation\Actions\OznaczDoPrzegladu;
use App\Domain\Moderation\Sygnaly\Sygnal;
use App\Domain\Security\DziennyBudzetListow;
use App\Models\Post;
use App\Models\Report;
use App\Notifications\PilnyAlarmModeracyjny;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Notifications\Dispatcher as DyspozytorPowiadomien;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Tests\TestCase;

/**
 * DOSYŁANIE PILNYCH ALARMÓW, KTÓRE NIE DOTARŁY (issue #1051, po przeglądzie).
 *
 * Przegląd PR #1268 wskazał, że stany `zalegly`, `nieudany` i `bez_adresu`
 * nie miały żadnej drogi wyjścia: analiza treści jest zlecana tylko przy
 * publikacji, zadanie kończyło się sukcesem (brak `failed_jobs`), a sonda
 * `/health` nie miała okna czasu ani nie patrzyła na status sprawy. Gasił ją
 * tylko ręczny SQL. Ten plik mierzy drogę wyjścia: komendę
 * `kuking:doslij-pilne-alarmy` i regułę gaśnięcia sondy.
 */
class DosylaniePilnychAlarmowTest extends TestCase
{
    use RefreshDatabase;

    private const ALARM = 'moderacja@example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['kuking.moderation.model.alarm_email' => self::ALARM]);
    }

    /** Pilna sprawa, przy której alarm nie dotarł — w zadanym stanie. */
    private function sprawa(string $login, string $stan = Report::ALARM_ZALEGLY): Report
    {
        $autor = $this->user($login);

        $wpis = Post::create([
            'author_id' => $autor->getKey(),
            'body' => 'Wpis, przy którym zwłoka jednego dnia jest realną szkodą.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $sprawa = app(OznaczDoPrzegladu::class)->handle($wpis, [
            new Sygnal('automat_model', 'Model wskazał treść seksualną z udziałem dziecka.', pilny: true),
        ]);

        $this->assertNotNull($sprawa);
        Report::query()->whereKey($sprawa->getKey())->update(['alarm_pilny_stan' => $stan]);

        return $sprawa->refresh();
    }

    private function sonda(): array
    {
        return (array) $this->get('/health')->json('checks.alarmy_moderacji');
    }

    public function test_komenda_dosyla_nieudany_i_zalegly(): void
    {
        Notification::fake();
        $zalegla = $this->sprawa('zalegla');
        $nieudana = $this->sprawa('nieudana', Report::ALARM_NIEUDANY);

        $this->artisan('kuking:doslij-pilne-alarmy')->assertSuccessful();

        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 2);

        foreach ([$zalegla, $nieudana] as $sprawa) {
            $sprawa->refresh();
            $this->assertSame(Report::ALARM_ZLECONY, $sprawa->alarm_pilny_stan);
            $this->assertNotNull($sprawa->alarm_pilny_zlecony_at);
        }

        $this->assertTrue($this->sonda()['ok']);
    }

    /**
     * Wyścig dwóch przebiegów (stary i nowy kontener przy wdrożeniu): obaj
     * wczytali tę samą sprawę jako „do dosłania". List ma wyjść JEDEN.
     */
    public function test_dwa_przebiegi_z_tym_samym_wierszem_wysylaja_jeden_list(): void
    {
        Notification::fake();
        $sprawa = $this->sprawa('wyscig');

        $pierwszy = Report::query()->findOrFail($sprawa->getKey());
        $drugi = Report::query()->findOrFail($sprawa->getKey());

        $alarm = app(AlarmujModeratora::class);

        $this->assertSame(Report::ALARM_ZLECONY, $alarm->doslij($pierwszy));
        $this->assertSame(AlarmujModeratora::JUZ_ZLECONY, $alarm->doslij($drugi));

        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);
    }

    /** Kontrola dodatnia wyścigu: bez zajętego wiersza drugi przebieg JEST w stanie wysłać. */
    public function test_kontrola_dodatnia_dwie_rozne_sprawy_to_dwa_listy(): void
    {
        Notification::fake();
        $alarm = app(AlarmujModeratora::class);

        $this->assertSame(Report::ALARM_ZLECONY, $alarm->doslij($this->sprawa('pierwsza')));
        $this->assertSame(Report::ALARM_ZLECONY, $alarm->doslij($this->sprawa('druga')));

        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 2);
    }

    public function test_komenda_pomija_sprawy_rozstrzygniete_i_odrzucone(): void
    {
        Notification::fake();

        foreach ([Report::STATUS_REJECTED => 'odrzucona', Report::STATUS_RESOLVED => 'rozstrzygnieta'] as $status => $login) {
            $sprawa = $this->sprawa($login, Report::ALARM_BEZ_ADRESU);
            Report::query()->whereKey($sprawa->getKey())->update(['status' => $status, 'resolved_at' => now()]);
        }

        $this->artisan('kuking:doslij-pilne-alarmy')->assertSuccessful();

        Notification::assertNothingSent();

        // Zamknięcie sprawy w panelu gasi sondę — bez SQL-a. Ślad w bazie
        // (że kanał wtedy nie zadziałał) zostaje.
        $this->assertTrue($this->sonda()['ok']);
        $this->assertSame(2, Report::query()->pilneBezAlarmu()->count());
    }

    public function test_awaria_poczty_przy_dosylaniu_zostawia_nieudany_i_nastepny_przebieg_dosyla(): void
    {
        Exceptions::fake();
        $sprawa = $this->sprawa('awaria');

        $this->app->bind(DyspozytorPowiadomien::class, fn () => new class
        {
            public function __call(string $metoda, array $argumenty): never
            {
                throw new RuntimeException('Kanał pocztowy nie odpowiada (awaria udawana w teście).');
            }
        });

        // Kod 1 — `Harmonogram::artisan()` zamieni go w porażkę przebiegu.
        $this->artisan('kuking:doslij-pilne-alarmy')->assertFailed();
        Exceptions::assertReported(RuntimeException::class);

        $sprawa->refresh();
        $this->assertSame(Report::ALARM_NIEUDANY, $sprawa->alarm_pilny_stan);
        $this->assertNull($sprawa->alarm_pilny_zlecony_at, 'Wycofana transakcja zostawiła znacznik bez listu.');

        // Poczta wraca — następna godzina dosyła sama.
        $this->app->bind(DyspozytorPowiadomien::class, fn ($app) => $app->make(ChannelManager::class));
        Notification::fake();

        $this->artisan('kuking:doslij-pilne-alarmy')->assertSuccessful();
        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);
        $this->assertSame(Report::ALARM_ZLECONY, $sprawa->refresh()->alarm_pilny_stan);
    }

    public function test_limit_partii(): void
    {
        Notification::fake();
        config(['kuking.moderation.model.alarm_partia' => 1]);

        $this->sprawa('pierwsza');
        $this->sprawa('druga');

        $this->artisan('kuking:doslij-pilne-alarmy')->assertSuccessful();
        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);

        $this->artisan('kuking:doslij-pilne-alarmy')->assertSuccessful();
        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 2);
    }

    /**
     * Okno sondy: otwarta sprawa bez alarmu świeci przez
     * `alarm_sonda_godzin` (72 h), potem gaśnie. Obie strony granicy.
     */
    /**
     * Audyt B8-02 po scaleniu z #1051: dosyłanie też idzie spod dobowego
     * sufitu alarmów automatu. `PilnyAlarmModeracyjny` niesie znacznik
     * rezerwacji, więc list bez niej wypadłby z rachunku puli. Po
     * wyczerpaniu sufitu sprawa zostaje „do dosłania" — sonda ją widzi,
     * a następny przebieg spróbuje znowu.
     */
    public function test_dosylanie_idzie_spod_dobowego_sufitu_i_zostawia_sprawe_do_doslania(): void
    {
        Notification::fake();
        config(['kuking.moderation.model.alarm_dzienny_sufit' => 1]);
        $pierwsza = $this->sprawa('sufit-pierwsza');
        $druga = $this->sprawa('sufit-druga');

        $this->artisan('kuking:doslij-pilne-alarmy')->assertSuccessful();

        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);
        $this->assertSame(1, DziennyBudzetListow::dlaAlarmuAutomatu()->zuzyte());
        $this->assertSame(1, Report::query()->pilneDoDoslania()->count());
        $stany = [$pierwsza->refresh()->alarm_pilny_stan, $druga->refresh()->alarm_pilny_stan];
        sort($stany);
        $this->assertSame([Report::ALARM_ZALEGLY, Report::ALARM_ZLECONY], $stany);

        $czeka = $pierwsza->alarm_pilny_stan === Report::ALARM_ZALEGLY ? $pierwsza : $druga;
        $this->assertNull($czeka->alarm_pilny_zlecony_at);
        $this->assertSame(AlarmujModeratora::SUFIT, app(AlarmujModeratora::class)->doslij($czeka));
        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);
    }

    public function test_sonda_gasnie_po_oknie_czasu(): void
    {
        $sprawa = $this->sprawa('okno');

        Report::query()->whereKey($sprawa->getKey())->update(['created_at' => now()->subHours(71)]);
        $this->assertFalse($this->sonda()['ok'], 'Sprawa sprzed 71 godzin miała jeszcze świecić.');
        $this->assertSame('pilny_alarm_nie_dotarl', $this->sonda()['error']);

        Report::query()->whereKey($sprawa->getKey())->update(['created_at' => now()->subHours(73)]);
        $this->assertTrue($this->sonda()['ok'], 'Sprawa sprzed 73 godzin nadal trzyma degraded — sonda nie ma okna.');

        // Komenda nadal ją dośle — okno dotyczy sondy, nie dosyłania.
        Notification::fake();
        $this->artisan('kuking:doslij-pilne-alarmy')->assertSuccessful();
        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);
    }

    /**
     * #1051, drobne z przeglądu: wiersz sprzed migracji (`alarm_pilny_stan
     * IS NULL`) o sprawie ODRZUCONEJ. `queue:retry` starego zadania analizy
     * nie ma prawa wysłać listu „pilna pozycja w kolejce" o sprawie, której
     * w kolejce nie ma.
     */
    public function test_ponowiona_analiza_nie_alarmuje_o_odrzuconej_sprawie_sprzed_migracji(): void
    {
        Notification::fake();
        $sprawa = $this->sprawa('stara');

        Report::query()->whereKey($sprawa->getKey())->update([
            'alarm_pilny_stan' => null,
            'status' => Report::STATUS_REJECTED,
            'resolved_at' => now(),
        ]);

        $wpis = Post::query()->findOrFail($sprawa->target_id);
        $pilne = [new Sygnal('automat_model', 'Model wskazał treść seksualną z udziałem dziecka.', pilny: true)];

        $oddane = app(OznaczDoPrzegladu::class)->handle($wpis, $pilne);
        $this->assertNull($oddane, 'Zamknięta sprawa wróciła do alarmu.');

        Notification::assertNothingSent();
        $this->assertNull($sprawa->refresh()->alarm_pilny_stan);
        $this->assertSame(1, Report::query()->where('source', Report::SOURCE_AUTOMAT)->count());
    }

    /** Kontrola dodatnia: ta sama droga przy sprawie OTWARTEJ oddaje wiersz i alarm idzie. */
    public function test_kontrola_dodatnia_otwarta_sprawa_sprzed_migracji_dostaje_alarm(): void
    {
        Notification::fake();
        $sprawa = $this->sprawa('otwarta');
        Report::query()->whereKey($sprawa->getKey())->update(['alarm_pilny_stan' => null]);

        $wpis = Post::query()->findOrFail($sprawa->target_id);
        $pilne = [new Sygnal('automat_model', 'Model wskazał treść seksualną z udziałem dziecka.', pilny: true)];

        $oddane = app(OznaczDoPrzegladu::class)->handle($wpis, $pilne);
        $this->assertNotNull($oddane);
        $this->assertSame(Report::ALARM_ZLECONY, app(AlarmujModeratora::class)->handle($oddane, $pilne));

        Notification::assertSentOnDemandTimes(PilnyAlarmModeracyjny::class, 1);
    }

    public function test_komenda_jest_w_harmonogramie_co_godzine(): void
    {
        $zadanie = collect(app(Schedule::class)->events())
            ->first(fn ($e) => $e->description === 'kuking:doslij-pilne-alarmy');

        $this->assertNotNull($zadanie, 'Komenda dosyłania nie jest w harmonogramie — w produkcji nikt jej nie zawoła.');
        $this->assertSame('35 * * * *', $zadanie->expression);
        $this->assertTrue($zadanie->onOneServer);
        $this->assertTrue($zadanie->withoutOverlapping);
        $this->assertSame(50, $zadanie->expiresAt);
    }
}

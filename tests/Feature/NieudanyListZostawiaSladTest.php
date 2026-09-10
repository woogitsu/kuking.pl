<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailFailure;
use App\Models\User;
use App\Notifications\PotwierdzenieAdresu;
use App\Poczta\PowodOdmowy;
use App\Poczta\ZapiszNieudanyList;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;
use Throwable;

/**
 * List, którego dostawca nie przyjął, NIE PRZEPADA W CISZY (issue #234, D-062).
 *
 * ────────────────────────────────────────────────────────────────────────
 *  CO DOKŁADNIE PILNUJE TEN PLIK
 * ────────────────────────────────────────────────────────────────────────
 *
 * Usterka z issue #234 brzmiała: odmowa EmailLabs → trzy próby workera
 * (`--tries=3 --backoff=10,60,300`) → wiersz w `failed_jobs` → cisza.
 * Sześć minut i potwierdzenie rejestracji przepadało tak, że nie wiedział
 * o tym ani adresat, ani właściciel: kolejka pusta, `/health` zielony.
 *
 * Testy niżej sprawdzają cztery rzeczy, po jednej na każdą połowę poprawki:
 *
 *  1. ŚLAD POWSTAJE i niesie kategorię („nie wyjdzie nigdy" ≠ „nie wyszło
 *     teraz"), kod HTTP, klasę powiadomienia i konto, które czekało;
 *  2. `/health` MÓWI `degraded`, dopóki ktoś nie odhaczy — i przestaje po
 *     odhaczeniu;
 *  3. ŚLAD NIE NIESIE DANYCH OSOBOWYCH: ani adresu odbiorcy, ani treści;
 *  4. ZAPIS NIE MA PRAWA PRZEWRÓCIĆ ZAPISU DO `failed_jobs` — bo nasz
 *     słuchacz leci PIERWSZY, więc jego wyjątek zabrałby diagnostyce
 *     ostatnią rzecz, jaka po awarii zostaje.
 *
 * DLACZEGO TESTY NIE ŁĄCZĄ SIĘ Z DOSTAWCĄ: ten sam powód co
 * w `PocztaPrzezApiEmailLabsTest` — podstawiamy klienta HTTP (`Http::fake()`).
 * Kolejka w testach chodzi w trybie `sync`, więc `JobFailed` leci przy
 * PIERWSZEJ nieudanej próbie, a nie trzeciej. Dla tego, co tu sprawdzamy,
 * jest to bez znaczenia (na produkcji zmienia się tylko liczba w kolumnie
 * `prob`) i jest napisane w komentarzu tam, gdzie ma to znaczenie.
 */
class NieudanyListZostawiaSladTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES_API = 'https://api.emaillabs.io/v2.1/email';

    /** @var list<MessageLogged> */
    private array $zapisaneWLogu = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'emaillabs',
            'services.emaillabs.key' => 'klucz-aplikacji-do-testu',
            'services.emaillabs.secret' => 'klucz-autoryzacyjny-do-testu',
            'services.emaillabs.smtp_account' => '1.kuking.smtp',
            'services.emaillabs.endpoint' => self::ADRES_API,
            'services.emaillabs.tracking' => false,
        ]);

        // Konfiguracja mailera jest zapamiętywana po pierwszym użyciu.
        Mail::purge('emaillabs');
    }

    /**
     * SEDNO POPRAWKI. Dostawca odmawia z powodu wyczerpanego limitu (HTTP 429),
     * a po awarii zostaje wiersz, który mówi CO, KOMU, DLACZEGO i KIEDY.
     */
    public function test_odmowa_dostawcy_zostawia_slad_z_kategoria_i_kontem(): void
    {
        Http::fake([self::ADRES_API => $this->odpowiedz429()]);

        $osoba = User::factory()->unverified()->create();

        $this->oczekujOdmowy(fn () => $osoba->notify(new PotwierdzenieAdresu));

        $slad = MailFailure::query()->sole();

        $this->assertSame(
            PowodOdmowy::LIMIT_DOBOWY,
            $slad->powod,
            'Wyczerpany limit dobowy (HTTP 429) musi być odróżniony od zwykłej awarii — '
            .'to jedyna kategoria, w której NIE ma sensu powtarzać wysyłki tego samego dnia.',
        );
        $this->assertSame(429, $slad->status_http);
        $this->assertSame(PotwierdzenieAdresu::class, $slad->rodzaj);
        $this->assertSame($osoba->getKey(), $slad->user_id, 'Bez `user_id` właściciel nie wie, do kogo napisać.');
        $this->assertNull($slad->zauwazony_at, 'Świeży ślad ma być NIEODHACZONY, inaczej /health nic nie powie.');
        $this->assertNotNull($slad->komunikat);
    }

    /** HTTP 500 to awaria przejściowa: powtórzenie ma sens i tekst dla człowieka to mówi. */
    public function test_awaria_dostawcy_jest_odmowa_przejsciowa(): void
    {
        Http::fake([self::ADRES_API => Http::response(['meta' => ['numberOfErrors' => 1]], 500)]);
        $this->oczekujOdmowy(fn () => User::factory()->unverified()->create()->notify(new PotwierdzenieAdresu));

        $this->assertSame(PowodOdmowy::PRZEJSCIOWA, MailFailure::query()->sole()->powod);
        $this->assertTrue(PowodOdmowy::PRZEJSCIOWA->czyPowtorzenieMaSens());
    }

    /**
     * HTTP 4xx bez słowa o limicie to odmowa TRWAŁA.
     *
     * Osobny test, a nie drugi akapit poprzedniego: `Http::fake()` wywołane
     * po raz drugi NIE zastępuje pierwszej atrapy — wygrywa ta, która pasuje
     * pierwsza. Dwie odpowiedzi dostawcy w jednym teście dawały więc dwa razy
     * ten sam wynik i test „sprawdzał" coś, czego nie było.
     */
    public function test_odrzucony_adres_jest_odmowa_trwala(): void
    {
        Http::fake([self::ADRES_API => Http::response([
            'meta' => ['numberOfErrors' => 1, 'numberOfData' => 0],
            'errors' => [['code' => 'E-0-004', 'title' => 'Invalid parameter', 'message' => 'Field to is invalid']],
        ], 400)]);

        $this->oczekujOdmowy(fn () => User::factory()->unverified()->create()->notify(new PotwierdzenieAdresu));

        $slad = MailFailure::query()->sole();

        $this->assertSame(
            PowodOdmowy::TRWALA,
            $slad->powod,
            'HTTP 4xx bez słowa o limicie to odmowa trwała — powtarzanie nic nie da, dopóki ktoś czegoś nie zmieni.',
        );
        $this->assertFalse($slad->powod->czyPowtorzenieMaSens());
    }

    /**
     * Limit potrafi przyjść jako HTTP 2xx z błędem w treści — i to jest
     * przypadek, w którym klasyfikacja po samym kodzie HTTP kłamałaby
     * najbardziej: „trwała odmowa" kazałaby szukać usterki w konfiguracji
     * przez cały dzień, w którym wystarczyło poczekać do północy.
     */
    public function test_limit_rozpoznajemy_takze_gdy_dostawca_odpowiada_dwiescie(): void
    {
        Http::fake([self::ADRES_API => Http::response([
            'meta' => ['numberOfErrors' => 1, 'numberOfData' => 0],
            'errors' => [['code' => 'E-1-002', 'title' => 'Message limit exceeded', 'message' => 'Daily limit reached']],
        ], 200)]);

        $this->oczekujOdmowy(fn () => User::factory()->unverified()->create()->notify(new PotwierdzenieAdresu));

        $this->assertSame(PowodOdmowy::LIMIT_DOBOWY, MailFailure::query()->sole()->powod);
    }

    /** W wierszu nie ma ani adresu odbiorcy, ani tematu, ani treści listu (AGENTS.md §7). */
    public function test_slad_nie_niesie_danych_osobowych(): void
    {
        // Odpowiedź dostawcy, która WKŁADA adres odbiorcy w treść błędu —
        // dokładnie tak robi SMTP („550 <ktos@wp.pl>: Recipient rejected").
        Http::fake([self::ADRES_API => Http::response([
            'meta' => ['numberOfErrors' => 1, 'numberOfData' => 0],
            'errors' => [[
                'code' => 'E-0-004',
                'title' => 'Invalid recipient',
                'message' => 'Recipient basia-tajny-adres@wp.pl rejected',
            ]],
        ], 400)]);

        $osoba = User::factory()->unverified()->create(['email' => 'basia-tajny-adres@wp.pl']);
        $this->oczekujOdmowy(fn () => $osoba->notify(new PotwierdzenieAdresu));

        $wiersz = json_encode(MailFailure::query()->sole()->getAttributes(), JSON_UNESCAPED_UNICODE);

        $this->assertIsString($wiersz);
        $this->assertStringNotContainsString(
            'basia-tajny-adres@wp.pl',
            $wiersz,
            'Adres odbiorcy nie ma prawa wejść do `mail_failures` — jest w payloadzie zadania, '
            .'a druga kopia adresu w bazie to druga rzecz do skasowania przy żądaniu RODO.',
        );
        $this->assertStringContainsString('[adres]', $wiersz, 'Redakcja ma podmieniać adres, nie wycinać całego komunikatu.');
    }

    /** `/health` mówi `degraded`, dopóki właściciel nie odhaczy. */
    public function test_health_zglasza_degraded_dopoki_nikt_nie_odhaczy(): void
    {
        // `storage:link` jak w `HealthCheckTest`: świeży klon nie ma
        // `public/storage`, a bez niego sonda `media` świeci na czerwono
        // i przykrywa to, o czym jest ten test.
        Artisan::call('storage:link');

        $this->get('/health')->assertOk()->assertJsonPath('status', 'ok');

        Http::fake([self::ADRES_API => $this->odpowiedz429()]);
        $this->oczekujOdmowy(fn () => User::factory()->unverified()->create()->notify(new PotwierdzenieAdresu));

        $odpowiedz = $this->get('/health');

        $odpowiedz->assertOk(); // NIE 503: poczta nie jest krytyczna, Railway nie ma czego restartować.
        $odpowiedz->assertJsonPath('status', 'degraded');
        $odpowiedz->assertJsonPath('checks.listy.ok', false);
        $odpowiedz->assertJsonPath(
            'checks.listy.error',
            'limit_poczty_wyczerpany',
            'Wyczerpany limit ma własny kod — monitoring odróżnia „skończyła się pula" od „coś się psuje".',
        );

        $this->artisan('kuking:nieudane-listy', ['--odhacz' => true])->assertExitCode(0);

        $this->get('/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    /**
     * Alarm NIE GAŚNIE SAM PO CZASIE. To jest osobny test, bo „okno ostatniej
     * godziny" jest pierwszym pomysłem, jaki się tu narzuca — i jest zły:
     * awaria z drugiej w nocy byłaby o świcie znowu niewidoczna, czyli
     * wracalibyśmy do usterki z issue #234, tylko o kilka godzin później.
     */
    public function test_stary_nieodhaczony_slad_nadal_trzyma_health_w_degraded(): void
    {
        $this->slad(['failed_at' => now()->subDays(9)]);

        $this->get('/health')
            ->assertOk()
            ->assertJsonPath('checks.listy.ok', false)
            ->assertJsonPath('checks.listy.error', 'listy_przepadaja');
    }

    /**
     * NAJWAŻNIEJSZA WŁASNOŚĆ TEGO KODU: nasz słuchacz `JobFailed` leci PRZED
     * tym, który zapisuje wiersz w `failed_jobs` (rejestruje go `queue:work`).
     * Gdyby rzucił, zabrałby diagnostyce payload zadania — czyli jedyną rzecz,
     * z której da się list ponowić. Zamiana „nikt się nie dowie" na „nikt się
     * nie dowie i nie ma czego ponowić" byłaby poprawką w złą stronę.
     */
    public function test_awaria_zapisu_sladu_nie_przewraca_obslugi_bledu(): void
    {
        $this->nasluchujLogu();

        Schema::drop('mail_failures');

        $zdarzenie = new JobFailed(
            'database',
            $this->atrapaZadania(),
            new TransportException('Dostawca odmówił.'),
        );

        // Brak wyjątku JEST tu asercją: gdyby cokolwiek poleciało dalej,
        // `queue:work` nie zapisałby wiersza w `failed_jobs`.
        app(ZapiszNieudanyList::class)($zdarzenie);

        $this->assertTrue(
            $this->wLoguJest('Nie udało się zapisać śladu nieudanego listu.'),
            'Awaria zapisu ma zostać w dzienniku — inaczej „nie rzucamy" znaczyłoby „milczymy".',
        );
    }

    /** Nieudane zadanie, które nie jest listem (zdjęcie, eksport), nie zaśmieca tabeli. */
    public function test_nieudane_zadanie_bez_awarii_poczty_nie_zostawia_sladu(): void
    {
        app(ZapiszNieudanyList::class)(new JobFailed(
            'database',
            $this->atrapaZadania(),
            new RuntimeException('Nie udało się przetworzyć zdjęcia.'),
        ));

        $this->assertSame(
            0,
            MailFailure::query()->count(),
            '`mail_failures` opisuje POCZTĘ. Wrzucanie tu awarii przetwarzania zdjęć zamieniłoby '
            .'alarm o przepadłym liście w drugi `failed_jobs`.',
        );
    }

    /** To samo zdarzenie dwa razy (worker przerwany w trakcie) to jeden wiersz, nie dwa. */
    public function test_to_samo_zadanie_nie_zapisuje_sie_dwa_razy(): void
    {
        $zadanie = $this->atrapaZadania();

        foreach ([1, 2] as $ignorowane) {
            app(ZapiszNieudanyList::class)(new JobFailed(
                'database',
                $zadanie,
                new TransportException('Dostawca odmówił.'),
            ));
        }

        $this->assertSame(1, MailFailure::query()->count());
    }

    /**
     * CHECK w bazie i lista kategorii w PHP to jedna reguła w dwóch miejscach.
     * Ten test jest po to, żeby dopisanie piątej kategorii bez migracji nie
     * skończyło się cichym `QueryException` w słuchaczu — czyli utratą śladu
     * przy pierwszej awarii nowego typu.
     */
    public function test_baza_przyjmuje_dokladnie_te_kategorie_ktore_zna_php(): void
    {
        foreach (PowodOdmowy::cases() as $powod) {
            $this->slad(['powod' => $powod->value, 'zauwazony_at' => now()]);
        }

        $this->assertSame(count(PowodOdmowy::cases()), MailFailure::query()->count());

        $this->expectException(Throwable::class);

        DB::table('mail_failures')->insert([
            'id' => (string) Str::uuid(),
            'powod' => 'wymyslona-kategoria',
            'rodzaj' => PotwierdzenieAdresu::class,
            'prob' => 1,
            'failed_at' => now(),
        ]);
    }

    /**
     * Ekran „Potwierdź adres e-mail" przestaje obiecywać list, który nie
     * wyszedł, i przestaje odsyłać do folderu „Spam" po wiadomość, której
     * tam nie ma (D-062 §4).
     */
    public function test_ekran_potwierdzenia_mowi_prawde_o_nieudanej_wysylce(): void
    {
        $osoba = User::factory()->unverified()->create();

        $this->actingAs($osoba->fresh())->get('/potwierdz-email')
            ->assertOk()
            ->assertSee('Zajrzyj do folderu', false);

        $this->slad(['user_id' => $osoba->getKey(), 'failed_at' => now()->subMinutes(5)]);

        $odpowiedz = $this->actingAs($osoba->fresh())->get('/potwierdz-email');

        $odpowiedz->assertOk();
        $odpowiedz->assertSee('Ostatnia wiadomość nie dotarła', false);
        $odpowiedz->assertDontSee('Zajrzyj do folderu', false);
    }

    /** Ślad SPRZED DOBY nie straszy człowieka — on ma na ekranie przycisk „wyślij jeszcze raz". */
    public function test_stary_slad_nie_zostaje_na_ekranie_czlowieka(): void
    {
        $osoba = User::factory()->unverified()->create();
        $this->slad(['user_id' => $osoba->getKey(), 'failed_at' => now()->subDays(3)]);

        $this->actingAs($osoba->fresh())->get('/potwierdz-email')
            ->assertOk()
            ->assertDontSee('Ostatnia wiadomość nie dotarła', false);
    }

    /** Cudzy ślad nie ma prawa pokazać się na ekranie innej osoby. */
    public function test_slad_innej_osoby_nie_pokazuje_sie_na_moim_ekranie(): void
    {
        $ktosInny = User::factory()->unverified()->create();
        $ja = User::factory()->unverified()->create();

        $this->slad(['user_id' => $ktosInny->getKey()]);

        $this->actingAs($ja->fresh())->get('/potwierdz-email')
            ->assertOk()
            ->assertDontSee('Ostatnia wiadomość nie dotarła', false);
    }

    /**
     * ROLLBACK MIGRACJI ODMAWIA, gdy zniszczyłby wiedzę o liście, o którym
     * nikt jeszcze nie wie. Odhaczone wiersze giną razem z tabelą i to jest
     * w porządku — właściciel je przeczytał.
     */
    public function test_rollback_odmawia_gdy_zniszczylby_nieodhaczony_slad(): void
    {
        $this->slad([]);

        try {
            $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true]);
            $this->fail('Wycofanie migracji miało odmówić — w tabeli leży nieodhaczony ślad.');
        } catch (Throwable $e) {
            $this->assertStringContainsString('Odmawiam wycofania migracji', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('mail_failures'), 'Tabela miała zostać nietknięta.');

        MailFailure::query()->update(['zauwazony_at' => now()]);

        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('mail_failures'));
    }

    /** Komenda mówi, CO ZROBIĆ, i różnicuje to po kategorii. */
    public function test_komenda_mowi_co_zrobic_i_nie_pokazuje_adresu(): void
    {
        $osoba = User::factory()->unverified()->create(['email' => 'basia-tajny-adres@wp.pl']);
        $this->slad(['user_id' => $osoba->getKey(), 'powod' => PowodOdmowy::LIMIT_DOBOWY->value]);

        $this->artisan('kuking:nieudane-listy')
            ->expectsOutputToContain('Nieodhaczonych: 1')
            ->expectsOutputToContain('Nie powtarzaj dziś')
            ->doesntExpectOutputToContain('basia-tajny-adres@wp.pl')
            ->assertExitCode(0);
    }

    // ────────────────────────────────────────────────────────────────────
    //  Pomocnicze
    // ────────────────────────────────────────────────────────────────────

    private function odpowiedz429(): PromiseInterface
    {
        return Http::response([
            'meta' => ['numberOfErrors' => 1, 'numberOfData' => 0, 'status' => 429, 'uniqId' => 'limit123'],
            'errors' => [['code' => 'E-1-002', 'title' => 'Too many requests', 'message' => 'Daily limit exceeded']],
        ], 429);
    }

    /**
     * Wysyłka, która MA się nie udać. W trybie `sync` wyjątek wraca do
     * wołającego (na produkcji zostaje w workerze), więc łapiemy go tutaj —
     * ślad zapisuje się po drodze, w słuchaczu `JobFailed`.
     */
    private function oczekujOdmowy(callable $czynnosc): void
    {
        try {
            $czynnosc();
            $this->fail('Wysyłka miała się nie udać, a nie rzuciła niczym.');
        } catch (Throwable $e) {
            $this->assertNotNull($e);
        }
    }

    /** @param array<string, mixed> $pola */
    private function slad(array $pola): MailFailure
    {
        $slad = new MailFailure;
        $slad->failed_job_uuid = (string) Str::uuid();
        $slad->powod = PowodOdmowy::from((string) ($pola['powod'] ?? PowodOdmowy::TRWALA->value));
        $slad->status_http = 400;
        $slad->rodzaj = PotwierdzenieAdresu::class;
        $slad->kolejka = 'high';
        $slad->prob = 3;
        $slad->user_id = isset($pola['user_id']) ? (string) $pola['user_id'] : null;
        $slad->komunikat = 'Dostawca odmówił.';
        $slad->failed_at = $pola['failed_at'] ?? now();
        $slad->zauwazony_at = $pola['zauwazony_at'] ?? null;
        $slad->save();

        return $slad;
    }

    /**
     * Atrapa zadania kolejki — tylko te metody, o które pyta
     * `ZapiszNieudanyList`. Prawdziwy `Job` wymagałby workera i payloadu
     * z tokenem, czyli rzeczy, których ten test nie sprawdza.
     */
    private function atrapaZadania(): Job
    {
        $zadanie = $this->createMock(Job::class);
        $zadanie->method('uuid')->willReturn('11111111-2222-3333-4444-555555555555');
        $zadanie->method('getQueue')->willReturn('high');
        $zadanie->method('attempts')->willReturn(3);
        $zadanie->method('resolveName')->willReturn(PotwierdzenieAdresu::class);
        $zadanie->method('payload')->willReturn(['displayName' => PotwierdzenieAdresu::class]);

        return $zadanie;
    }

    private function nasluchujLogu(): void
    {
        $this->zapisaneWLogu = [];

        // `Log::listen`, nie `Event::listen` — ta sama droga co
        // w `HealthNieZdradzaSzczegolowTest`.
        Log::listen(function (MessageLogged $wpis): void {
            $this->zapisaneWLogu[] = $wpis;
        });
    }

    private function wLoguJest(string $fragment): bool
    {
        foreach ($this->zapisaneWLogu as $wpis) {
            if (str_contains($wpis->message, $fragment)) {
                return true;
            }
        }

        return false;
    }
}

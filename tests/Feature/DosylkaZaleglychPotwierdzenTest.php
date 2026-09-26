<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\DosylajPotwierdzeniaZgloszen;
use App\Domain\Moderation\Actions\NotifyReporterReceipt;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * ZALEGŁE POTWIERDZENIE PRZYJĘCIA ZGŁOSZENIA DOCHODZI SAMO
 * (issue #797, D-252 — decyzja właściciela z 23.09.2026, DSA art. 16 ust. 4).
 *
 * ── CO ZOSTAWAŁA NIEDOKOŃCZONE POPRZEDNIA POPRAWKA ──
 *
 * `PotwierdzenieZgloszeniaDaSiePonowicTest` dowodzi, że zaległe
 * potwierdzenie DOKAŃCZA SIĘ, GDY KTOŚ WRÓCI do sprawy. Ale sprawa, do
 * której nikt nie wróci, zostawała bez potwierdzenia na zawsze: wyjątek szedł
 * do kanału błędów i na tym koniec.
 *
 * POMIAR PRZED ZMIANĄ (na tej samej bazie zadania, awaria wstrzyknięta
 * naprawdę przez `DB::listen`): `php artisan kuking:dosylaj-potwierdzenia-zgloszen`
 * kończyło się `CommandNotFoundException` — komendy nie było, a sprawa
 * zostawała z 0 potwierdzeń i `receipt_sent_at = null`.
 *
 * ── GRANICE, KTÓRYCH TEN PLIK PILNUJE TAK SAMO MOCNO ──
 *
 * `receipt_sent_at IS NULL` NIE ZNACZY „zaległość". Zgłoszenie bez konta
 * (art. 16 ust. 2 lit. c) i oznaczenie automatu nie mają adresata w tabeli
 * `notifications` i mają własną drogę potwierdzenia. Potwierdzenie skasowane
 * przez retencję też nie jest zaległością — pytamy o ZNACZNIK, nigdy
 * o istnienie powiadomienia.
 *
 * ── CZEGO TEN PLIK NIE MIERZY ──
 *
 * Zbiegu dosyłki z powrotem człowieka na DWÓCH połączeniach — tu wszystko
 * chodzi w jednej, niezatwierdzonej transakcji `RefreshDatabase`
 * (`docs/PULAPKI_TESTOW.md` §6). Mierzy to
 * `tests/Dwa/DosylkaNieDublujePotwierdzeniaTest`.
 */
class DosylkaZaleglychPotwierdzenTest extends TestCase
{
    use RefreshDatabase;

    private const KOMENDA = 'kuking:dosylaj-potwierdzenia-zgloszen';

    private int $licznik = 0;

    // ------------------------------------------------------------------
    // REGRESJA #797: sprawa, do której nikt nie wróci
    // ------------------------------------------------------------------

    public function test_sprawa_do_ktorej_nikt_nie_wrocil_dostaje_potwierdzenie(): void
    {
        $zglaszajacy = $this->user('zaleglyA');
        $sprawa = $this->zalegleZgloszenie($zglaszajacy);

        $this->assertSame(0, $this->potwierdzenia());
        $this->assertNull($sprawa->receipt_sent_at);

        // NIKT NIE WRACA DO SPRAWY. Jedyne, co się dzieje, to przebieg
        // komendy z harmonogramu.
        $this->artisan(self::KOMENDA)->assertSuccessful();

        $this->assertSame(1, $this->potwierdzenia(), 'Zaległe potwierdzenie nie doszło.');
        $this->assertNotNull($sprawa->refresh()->receipt_sent_at);

        $ping = Notification::query()->where('type', Notification::TYPE_REPORT_RECEIVED)->firstOrFail();
        $this->assertSame((string) $zglaszajacy->getKey(), (string) $ping->user_id);
        $this->assertSame((string) $sprawa->getKey(), $ping->data['report_id']);
    }

    /**
     * Drugi przebieg nie dokłada drugiego pingu — znacznik jest już
     * ustawiony, więc sprawa nie jest zaległością.
     */
    public function test_drugi_przebieg_nie_dosyla_tego_samego_potwierdzenia(): void
    {
        $this->zalegleZgloszenie($this->user('zaleglyB'));

        $this->artisan(self::KOMENDA)->assertSuccessful();
        $this->artisan(self::KOMENDA)->assertSuccessful();

        $this->assertSame(1, $this->potwierdzenia(), 'Dosyłka wysłała potwierdzenie dwa razy.');
    }

    public function test_sprawa_potwierdzona_normalnie_nie_dostaje_drugiego_pingu(): void
    {
        $zglaszajacy = $this->user('zaleglyC');
        $this->zglos($zglaszajacy, $this->wpis())->assertSessionHasNoErrors();

        $this->assertSame(1, $this->potwierdzenia());

        $this->artisan(self::KOMENDA)->assertSuccessful();

        $this->assertSame(1, $this->potwierdzenia());
    }

    // ------------------------------------------------------------------
    // Granice: co NIE jest zaległością
    // ------------------------------------------------------------------

    /**
     * NAJWAŻNIEJSZA GRANICA. `RetencjaPowiadomien` świadomie kasuje ping po
     * ogólnym okresie retencji, a sprawa żyje 36 miesięcy. Komenda, która
     * pytałaby „czy istnieje powiadomienie", wskrzeszałaby pingi sprzed
     * kwartału przy każdym cogodzinnym przebiegu.
     */
    public function test_ping_skasowany_przez_retencje_nie_jest_zalegloscia(): void
    {
        $zglaszajacy = $this->user('zaleglyD');
        $this->zglos($zglaszajacy, $this->wpis())->assertSessionHasNoErrors();
        $this->assertSame(1, $this->potwierdzenia());

        // Tak robi `kuking:sprzataj-powiadomienia`: ping znika, sprawa
        // i jej znacznik zostają.
        Notification::query()->where('type', Notification::TYPE_REPORT_RECEIVED)->delete();
        $this->assertNotNull(Report::firstOrFail()->receipt_sent_at);

        $this->artisan(self::KOMENDA)->assertSuccessful();

        $this->assertSame(0, $this->potwierdzenia(), 'Dosyłka wskrzesiła ping skasowany przez retencję.');
    }

    /**
     * Zgłoszenie bez konta ma potwierdzenie MAILOWE i ten sam znacznik na tej
     * samej kolumnie — ale w `notifications` nie ma dla niego adresata.
     * Dosyłka nie ma tu czego dosyłać i nie wolno jej udawać, że ma.
     */
    public function test_zgloszenie_bez_konta_nie_jest_zalegloscia(): void
    {
        $sprawa = Report::create([
            'reporter_id' => null,
            'source' => Report::SOURCE_LEGAL_NOTICE,
            'target_type' => 'unknown',
            'target_url' => 'https://kuking.pl/wpisy/cos',
            'reason' => 'copyright',
            'illegality_explanation' => 'To jest mój tekst, przepisany bez zgody.',
            'good_faith_at' => now(),
            'status' => Report::STATUS_OPEN,
        ]);

        // Sprawa bez konta nie może nawet WEJŚĆ do kolejki dosyłki: gdyby
        // wchodziła, komenda meldowałaby zaległość, której nie ma i której
        // nigdy nie da się odrobić — licznik rósłby w nieskończoność.
        $this->artisan(self::KOMENDA)
            ->expectsOutput('Zaległych potwierdzeń w bazie: 0.')
            ->assertSuccessful();

        $this->assertSame(0, $this->potwierdzenia());
        $this->assertNull($sprawa->refresh()->receipt_sent_at, 'Dosyłka „potwierdziła" sprawę, której nie ma jak potwierdzić w serwisie.');
    }

    public function test_przebieg_na_sucho_niczego_nie_wysyla(): void
    {
        $sprawa = $this->zalegleZgloszenie($this->user('zaleglyE'));

        $this->artisan(self::KOMENDA, ['--na-sucho' => true])->assertSuccessful();

        $this->assertSame(0, $this->potwierdzenia(), 'Przebieg na sucho wysłał potwierdzenie.');
        $this->assertNull($sprawa->refresh()->receipt_sent_at);
    }

    /**
     * SPRAWA Z DORĘCZONYM ROZSTRZYGNIĘCIEM (D-252). Potwierdzenie mówi
     * „sprawdzimy je i napiszemy, co postanowiliśmy" — po decyzji to jest
     * nieprawda. Sprawa nie jest zaległością i nie wchodzi nawet do licznika.
     */
    public function test_sprawa_z_doreczona_decyzja_nie_dostaje_potwierdzenia_przyjecia(): void
    {
        $sprawa = $this->zalegleZgloszenie($this->user('zaleglyI'));
        $sprawa->forceFill([
            'status' => Report::STATUS_RESOLVED,
            'resolved_at' => now(),
            'decision_sent_at' => now(),
        ])->save();

        $this->artisan(self::KOMENDA)
            ->expectsOutput('Zaległych potwierdzeń w bazie: 0.')
            ->assertSuccessful();

        $this->assertSame(0, $this->potwierdzenia(), 'Dosyłka wysłała „mamy Twoje zgłoszenie" po doręczonej decyzji.');
        $this->assertNull($sprawa->refresh()->receipt_sent_at);
    }

    /**
     * KONTROLA DODATNIA do testu wyżej: sprawa rozstrzygnięta, której decyzja
     * NIE doszła, potwierdzenie dostaje — to jedyny sygnał, że zgłoszenie
     * w ogóle do nas trafiło. Bez tego testu warunek „rozstrzygnięta" zamiast
     * „decyzja doręczona" przeszedłby niezauważony.
     */
    public function test_sprawa_rozstrzygnieta_bez_doreczonej_decyzji_dostaje_potwierdzenie(): void
    {
        $sprawa = $this->zalegleZgloszenie($this->user('zaleglyJ'));
        $sprawa->forceFill(['status' => Report::STATUS_REJECTED, 'resolved_at' => now()])->save();

        $this->artisan(self::KOMENDA)->assertSuccessful();

        $this->assertSame(1, $this->potwierdzenia());
        $this->assertNotNull($sprawa->refresh()->receipt_sent_at);
    }

    /**
     * WYŚCIG Z DECYZJĄ: partia przeczytana, zanim decyzja doszła, a dosyłka
     * rusza po niej. Warunek stoi w zamku (`UPDATE ... WHERE
     * decision_sent_at IS NULL`), więc potwierdzenie nie wychodzi, choć
     * model w ręku dosyłki jeszcze o decyzji nie wie.
     */
    public function test_decyzja_doreczona_po_odczycie_partii_wygrywa_z_dosylka(): void
    {
        $sprawa = $this->zalegleZgloszenie($this->user('zaleglyK'));
        $wRece = Report::query()->findOrFail($sprawa->getKey());

        Report::query()->whereKey($sprawa->getKey())->update(['decision_sent_at' => now()]);

        $this->assertNull(app(NotifyReporterReceipt::class)->handle($wRece));
        $this->assertSame(0, $this->potwierdzenia());
    }

    /**
     * Konto WYMAZANE nie ma czytelnika — to nie zaległość, tylko brak
     * adresata, jak przy zgłoszeniu bez konta.
     */
    public function test_zglaszajacy_z_kontem_wymazanym_nie_jest_zalegloscia(): void
    {
        $zglaszajacy = $this->user('zaleglyL');
        $sprawa = $this->zalegleZgloszenie($zglaszajacy);
        $zglaszajacy->forceFill(['status' => User::STATUS_ERASED, 'data_erased_at' => now()])->save();

        $this->artisan(self::KOMENDA)
            ->expectsOutput('Zaległych potwierdzeń w bazie: 0.')
            ->assertSuccessful();

        $this->assertSame(0, $this->potwierdzenia());
        $this->assertNull($sprawa->refresh()->receipt_sent_at);
    }

    /**
     * KONTROLA DODATNIA do testu wyżej: zawieszony ma prawo wiedzieć, że
     * zgłoszenie doszło (uzasadnienie w `NotifyReporterReceipt`).
     */
    public function test_zglaszajacy_zawieszony_dostaje_potwierdzenie(): void
    {
        $zglaszajacy = $this->user('zaleglyM');
        $this->zalegleZgloszenie($zglaszajacy);
        $zglaszajacy->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->artisan(self::KOMENDA)->assertSuccessful();

        $this->assertSame(1, $this->potwierdzenia());
    }

    /**
     * LIMIT PARTII: `--ile` ogranicza jeden przebieg, najstarsze idą
     * pierwsze, a reszta czeka na następny przebieg — nie przepada.
     */
    public function test_limit_partii_bierze_najstarsze_a_reszte_zostawia_na_nastepny_przebieg(): void
    {
        $starsza = $this->zalegleZgloszenie($this->user('zaleglyN1'));
        $starsza->forceFill(['created_at' => now()->subDay()])->save();
        $nowsza = $this->zalegleZgloszenie($this->user('zaleglyN2'));

        $this->artisan(self::KOMENDA, ['--ile' => 1])
            ->expectsOutput('Zaległych potwierdzeń w bazie: 2.')
            ->assertSuccessful();

        $this->assertSame(1, $this->potwierdzenia());
        $this->assertNotNull($starsza->refresh()->receipt_sent_at, 'Partia nie zaczęła od najstarszej zaległości.');
        $this->assertNull($nowsza->refresh()->receipt_sent_at);

        $this->artisan(self::KOMENDA, ['--ile' => 1])->assertSuccessful();

        $this->assertSame(2, $this->potwierdzenia());
    }

    /**
     * ZATOR NAJSTARSZYCH (D-252). Sprawa, która padła
     * `ODLOZ_PO_PORAZKACH` razy, idzie na koniec kolejki — przy `--ile=1`
     * bez tego nowsza zaległość nie doszłaby nigdy. Odłożona nie przepada:
     * dalej się liczy i dostaje próbę, gdy w partii zostaje miejsce.
     */
    public function test_sprawa_padajaca_stale_idzie_na_koniec_kolejki_i_nie_zatyka_nowszych(): void
    {
        $zepsuta = $this->zalegleZgloszenie($this->user('zaleglyZ1'));
        $zepsuta->forceFill(['created_at' => now()->subDay()])->save();

        for ($proba = 1; $proba <= DosylajPotwierdzeniaZgloszen::ODLOZ_PO_PORAZKACH; $proba++) {
            $wylacz = $this->zepsuj('insert into "notifications"');
            $this->artisan(self::KOMENDA, ['--ile' => 1])->assertExitCode(1);
            $wylacz();
        }

        $this->assertSame(
            [$zepsuta->getKey() => DosylajPotwierdzeniaZgloszen::ODLOZ_PO_PORAZKACH],
            Cache::get(DosylajPotwierdzeniaZgloszen::KLUCZ_PORAZEK),
        );

        $nowsza = $this->zalegleZgloszenie($this->user('zaleglyZ2'));

        $this->artisan(self::KOMENDA, ['--ile' => 1])
            ->expectsOutput('Zaległych potwierdzeń w bazie: 2.')
            ->assertSuccessful();

        $this->assertNotNull($nowsza->refresh()->receipt_sent_at, 'Stale padająca sprawa zatkała kolejkę nowszej.');
        $this->assertNull($zepsuta->refresh()->receipt_sent_at);

        // Miejsce w partii jest wolne — odłożona dostaje próbę i dochodzi.
        $this->artisan(self::KOMENDA, ['--ile' => 1])->assertSuccessful();

        $this->assertNotNull($zepsuta->refresh()->receipt_sent_at, 'Odłożona sprawa przepadła zamiast dostać próbę na końcu kolejki.');
        $this->assertNull(Cache::get(DosylajPotwierdzeniaZgloszen::KLUCZ_PORAZEK), 'Licznik porażek dosłanej sprawy nie wygasł.');
    }

    // ------------------------------------------------------------------
    // Kod wyjścia — to na nim stoi wpięcie w harmonogram
    // ------------------------------------------------------------------

    /**
     * Awaria JEDNEJ sprawy nie zatrzymuje reszty kolejki, ale przebieg
     * kończy się NIEZEROWYM kodem. Bez tego harmonogram zameldowałby
     * sukces po przebiegu, w którym nic nie doszło.
     */
    public function test_awaria_jednej_sprawy_nie_zatrzymuje_reszty_i_daje_niezerowy_kod(): void
    {
        $pierwsza = $this->zalegleZgloszenie($this->user('zaleglyF1'));
        $pierwsza->forceFill(['created_at' => now()->subDay()])->save();

        $druga = $this->zalegleZgloszenie($this->user('zaleglyF2'));

        $wylacz = $this->zepsuj('insert into "notifications"');
        $this->artisan(self::KOMENDA)->assertExitCode(1);
        $wylacz();

        $this->assertSame(1, $this->potwierdzenia(), 'Awaria pierwszej sprawy zatrzymała całą kolejkę.');
        $this->assertNull($pierwsza->refresh()->receipt_sent_at);
        $this->assertNotNull($druga->refresh()->receipt_sent_at);

        // Sprawa, która padła, ZOSTAJE zaległością — następny przebieg ją
        // dokończy.
        $this->artisan(self::KOMENDA)->assertSuccessful();
        $this->assertSame(2, $this->potwierdzenia());
    }

    // ------------------------------------------------------------------
    // Wpięcie w harmonogram (uwaga do issue #835)
    // ------------------------------------------------------------------

    /**
     * ZADANIE W HARMONOGRAMIE ZAMIENIA KOD WYJŚCIA ≠ 0 W WYJĄTEK.
     *
     * `CallbackEvent` uznaje za porażkę wyłącznie wyjątek albo `false` —
     * liczbowe `1` zwrócone przez `Artisan::call()` przeszłoby jako sukces
     * (#835, #1342). Reguła jest ta sama co przy
     * `kuking:sprzataj-powiadomienia` (`RetencjaPowiadomienCzesciowaPorazkaTest`).
     */
    public function test_zadanie_w_harmonogramie_melduje_porazke_gdy_komenda_padnie(): void
    {
        $zadanie = $this->zadanieHarmonogramu();

        $this->zalegleZgloszenie($this->user('zaleglyG'));

        $wylacz = $this->zepsuj('insert into "notifications"');

        try {
            $zadanie->run($this->app);
            $this->fail('Harmonogram uznał nieudany przebieg dosyłki za sukces — to jest wada z #835, powtórzona w nowym zadaniu.');
        } catch (RuntimeException $e) {
            // Komunikat wspólnego adaptera `Harmonogram::artisan()` (#835) —
            // ten sam, który sprawdza `HarmonogramSprawdzaKodWyjsciaTest`.
            // Pierwsze zdanie jest stałe; po nim adapter dokleja końcówkę
            // wyjścia komendy (`HarmonogramPokazujePrzyczyneTest`).
            $this->assertStringStartsWith(
                "Komenda harmonogramu 'kuking:dosylaj-potwierdzenia-zgloszen' zakończyła się niepowodzeniem (kod wyjścia: 1).",
                $e->getMessage(),
            );
        } finally {
            $wylacz();
        }

        $this->assertSame(1, $zadanie->exitCode);
    }

    /**
     * KONTROLA DODATNIA do testu wyżej: to samo zadanie, przebieg udany,
     * ma meldować sukces. Osobno, bo `CallbackEvent` pamięta wyjątek
     * z poprzedniego `run()` i rzuciłby go ponownie.
     */
    public function test_zadanie_w_harmonogramie_melduje_sukces_gdy_komenda_przejdzie(): void
    {
        $zadanie = $this->zadanieHarmonogramu();

        $this->zalegleZgloszenie($this->user('zaleglyH'));

        $zadanie->run($this->app);

        $this->assertSame(0, $zadanie->exitCode);
        $this->assertSame(1, $this->potwierdzenia(), 'Zadanie przeszło, ale niczego nie dosłało.');
    }

    /**
     * Reguły harmonogramu z main: `onOneServer()` (#595) i blokada krótsza
     * niż interwał godzinny (#1002 — 50 minut). Czytane ze zdarzenia, nie
     * z tekstu pliku.
     */
    public function test_zadanie_chodzi_co_godzine_na_jednym_serwerze_z_krotka_blokada(): void
    {
        $zadanie = $this->zadanieHarmonogramu();

        $this->assertSame('35 * * * *', $zadanie->expression);
        $this->assertTrue($zadanie->onOneServer, 'Dosyłka bez onOneServer() odpali się dwa razy przy wdrożeniu.');
        $this->assertTrue($zadanie->withoutOverlapping);
        $this->assertSame(50, $zadanie->expiresAt, 'Blokada zadania co godzinę ma wygasać przed następnym terminem (#1002).');
    }

    // ------------------------------------------------------------------
    // Przyrządy
    // ------------------------------------------------------------------

    private function zadanieHarmonogramu(): Event
    {
        foreach (app(Schedule::class)->events() as $zadanie) {
            if ($zadanie->description === self::KOMENDA) {
                return $zadanie;
            }
        }

        $this->fail('W harmonogramie nie ma zadania „'.self::KOMENDA.'" — zaległości nikt by nie obchodził.');
    }

    /**
     * Sprawa w stanie PO AWARII potwierdzenia: zapisana, bez pingu, bez
     * znacznika. Awaria jest wstrzyknięta naprawdę, a nie podstawiona
     * `update`em — stan po atrapie i stan po awarii to nie to samo.
     */
    private function zalegleZgloszenie(User $zglaszajacy): Report
    {
        $wpis = $this->wpis();

        $wylacz = $this->zepsuj('insert into "notifications"');
        $this->zglos($zglaszajacy, $wpis);
        $wylacz();

        $sprawa = Report::query()->where('reporter_id', $zglaszajacy->getKey())->firstOrFail();

        // KONTROLA DODATNIA przyrządu: bez niej każdy test niżej mierzyłby
        // sprawę, która wcale nie jest zaległa.
        $this->assertNull($sprawa->receipt_sent_at, 'Wstrzyknięta awaria nie zadziałała — sprawa jest potwierdzona.');

        return $sprawa;
    }

    private function wpis(): Post
    {
        return Post::factory()->for($this->user('autorZ'.(++$this->licznik)), 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
    }

    private function potwierdzenia(): int
    {
        return Notification::query()->where('type', Notification::TYPE_REPORT_RECEIVED)->count();
    }

    private function zglos(User $kto, Post $co): TestResponse
    {
        return $this->actingAs($kto)->post(
            route('reports.store', ['type' => 'post', 'id' => $co->getKey()]),
            ['reason' => 'spam', 'details' => 'To jest reklama.'],
        );
    }

    /**
     * Wstrzyknięcie awarii w PIERWSZE zapytanie pasujące do wzorca — ten sam
     * przyrząd co w `PotwierdzenieZgloszeniaDaSiePonowicTest`. `DB::listen`
     * woła się PO wykonaniu zapytania, więc wyjątek leci już po zapisie.
     */
    private function zepsuj(string $wzorzec): callable
    {
        $aktywna = true;

        DB::listen(function ($zapytanie) use ($wzorzec, &$aktywna): void {
            if ($aktywna && str_contains($zapytanie->sql, $wzorzec)) {
                $aktywna = false;
                throw new RuntimeException('Wstrzyknięta awaria: '.$wzorzec);
            }
        });

        return function () use (&$aktywna): void {
            $aktywna = false;
        };
    }
}

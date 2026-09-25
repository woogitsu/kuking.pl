<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Actions\ZalozoneKonto;
use App\Jobs\PrzeanalizujTresc;
use App\Models\AuditLogEntry;
use App\Models\Block;
use App\Models\DataExport;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification as Powiadomienia;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Awaria dziennika audytu i innych skutków po `COMMIT` nie zamienia udanej
 * zmiany w błąd — a wpis będący częścią decyzji ją cofa (D-249, #1373,
 * #1343, #1363).
 *
 * CO SIĘ DZIAŁO
 * Rejestracja, zgłoszenie treści i zbiorcze zamknięcie sygnałów automatu
 * zatwierdzały swoją transakcję, a dopiero potem wołały
 * `AuditLogEntry::record()`. Wyjątek z tego ostatniego `INSERT`-a leciał do
 * człowieka jako błąd — przy koncie, sprawie albo decyzji, które już
 * istniały. Ponowienie odbijało się od nich („adres zajęty", „już
 * zamknięte") i wpisu też nie uzupełniało.
 *
 * REGUŁA (D-249): wpis POMOCNICZY (rejestracja, zgłoszenie) stoi za
 * transakcją; jego awaria idzie do `report()` z nazwą brakującego wpisu,
 * a odpowiedź zostaje odpowiedzią udanej zmiany. Wpis będący częścią
 * DECYZJI (zamknięcie grupy sygnałów) stoi w jej transakcji; jego awaria
 * cofa decyzję, a ponowienie daje jeden komplet.
 *
 * Awarię wstrzykujemy w SAM `INSERT` do `audit_log` danej akcji, po jego
 * wykonaniu (`DB::listen` woła się po zapytaniu) — ten sam kształt co
 * w `PotwierdzenieZgloszeniaDaSiePonowicTest`. Każdy przypadek ma kontrolę
 * dodatnią: bez awarii wpis powstaje, więc „naprawa" przez usunięcie wpisu
 * nie przejdzie.
 */
class AwariaAudytuNiePrzewracaZatwierdzonejZmianyTest extends TestCase
{
    use RefreshDatabase;

    /** Przełącznik awarii — `DB::listen` nie da się odpiąć, więc ponowienie po „naprawie" gasi go tutaj. */
    private bool $awaria = true;

    private function zepsujWpis(string $akcja): void
    {
        DB::listen(function ($zapytanie) use ($akcja): void {
            if ($this->awaria
                && str_contains($zapytanie->sql, 'insert into "audit_log"')
                && in_array($akcja, $zapytanie->bindings, true)) {
                throw new RuntimeException('Wstrzyknięta awaria dziennika: '.$akcja);
            }
        });
    }

    private function wpisy(string $akcja): int
    {
        return AuditLogEntry::query()->where('action', $akcja)->count();
    }

    private function assertZgloszonoBrakWpisu(string $akcja): void
    {
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), '„'.$akcja.'"')
            && $e->getPrevious() instanceof RuntimeException);
    }

    private function assertNieZgloszonoBrakuWpisu(): void
    {
        Exceptions::assertNotReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'dziennika audytu'));
    }

    // ------------------------------------------------------------------
    // #1373 — rejestracja
    // ------------------------------------------------------------------

    private function zarejestruj(): TestResponse
    {
        return $this->post('/register', [
            'email' => 'basia@example.com',
            'password' => 'bardzo-tajne-haslo-123',
            'password_confirmation' => 'bardzo-tajne-haslo-123',
            'display_name' => 'Basia',
            'username' => 'basia',
            'terms_accepted' => '1',
            'age_confirmed' => '1',
        ]);
    }

    public function test_awaria_audytu_rejestracji_nie_daje_bledu_przy_zalozonym_koncie(): void
    {
        Powiadomienia::fake();
        Exceptions::fake();
        $this->zepsujWpis('account.registered');

        $this->zarejestruj()->assertRedirect()->assertSessionHasNoErrors();

        $konto = User::where('email', 'basia@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($konto);
        $this->assertSame(0, $this->wpisy('account.registered'));
        $this->assertZgloszonoBrakWpisu('account.registered');
    }

    public function test_kontrola_dodatnia_rejestracja_bez_awarii_zapisuje_wpis(): void
    {
        Powiadomienia::fake();
        Exceptions::fake();

        $this->zarejestruj()->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $this->wpisy('account.registered'));
        $this->assertNieZgloszonoBrakuWpisu();
    }

    /**
     * `Registered` po `COMMIT` (#1373): wyjątek z listenera — np. zlecenie
     * listu, które nie weszło do kolejki — dawał 500 przy istniejącym
     * koncie. Teraz konto jest, człowiek jest zalogowany, a komunikat NIE
     * mówi „wysłaliśmy", tylko co zrobić, żeby list jednak przyszedł.
     *
     * Kontrola ujemna: bez `try` wokół `event(new Registered)` ten test
     * dostaje 500 zamiast przekierowania.
     */
    public function test_awaria_registered_po_zalozeniu_konta_mowi_co_zrobic_zamiast_500(): void
    {
        Powiadomienia::fake();
        Exceptions::fake();
        Event::listen(Registered::class, function (): void {
            throw new RuntimeException('Wstrzyknięta awaria zlecenia listu');
        });

        $this->zarejestruj()
            ->assertRedirect(route('onboarding.interests'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', ZalozoneKonto::KOMUNIKAT_BEZ_LISTU);

        $konto = User::where('email', 'basia@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($konto);
        // Kolejne skutki po `Registered` nie zostały pominięte przez jego awarię.
        $this->assertSame(1, $this->wpisy('account.registered'));
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'nie wyszło zdarzenie Registered')
            && str_contains($e->getMessage(), (string) $konto->getKey()));
    }

    public function test_kontrola_dodatnia_rejestracja_bez_awarii_mowi_zwykle_konto_gotowe(): void
    {
        Powiadomienia::fake();

        $this->zarejestruj()->assertSessionHas('status', 'Konto gotowe. Miło Cię widzieć w Kuking.');
    }

    /**
     * Obserwowanie gospodarza po `COMMIT` (#1373): awaria bazy przy
     * `follows` NIE jest „gospodarzem źle wpisanym" — idzie do `report()`
     * z nazwą konta — ale też nie daje 500 przy koncie, które już jest.
     *
     * Kontrola ujemna: z samym `catch (BladDlaCzlowieka)` ten test dostaje
     * 500, a z `catch (Throwable)` bez `report()` oblewa na ostatniej asercji.
     */
    public function test_awaria_obserwowania_gospodarza_nie_daje_500_i_jest_zgloszona(): void
    {
        Powiadomienia::fake();
        Exceptions::fake();
        $gospodarz = $this->user('gospodarz');
        config(['kuking.community.host_username' => 'gospodarz']);
        DB::listen(function ($zapytanie): void {
            if (str_contains($zapytanie->sql, 'insert into "follows"')) {
                throw new RuntimeException('Wstrzyknięta awaria obserwowania');
            }
        });

        $this->zarejestruj()
            ->assertRedirect(route('onboarding.interests'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Konto gotowe. Miło Cię widzieć w Kuking.');

        $konto = User::where('email', 'basia@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($konto);
        $this->assertFalse($konto->isFollowing($gospodarz));
        $this->assertSame(1, $this->wpisy('account.registered'));
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'nie zaczęło obserwować gospodarza')
            && str_contains($e->getMessage(), (string) $konto->getKey()));
    }

    public function test_kontrola_dodatnia_rejestracja_obserwuje_gospodarza(): void
    {
        Powiadomienia::fake();
        Exceptions::fake();
        $gospodarz = $this->user('gospodarz');
        config(['kuking.community.host_username' => 'gospodarz']);

        $this->zarejestruj()->assertSessionHasNoErrors();

        $this->assertTrue(User::where('email', 'basia@example.com')->firstOrFail()->isFollowing($gospodarz));
        Exceptions::assertNotReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'gospodarza'));
    }

    // ------------------------------------------------------------------
    // #1363 — zgłoszenie treści
    // ------------------------------------------------------------------

    private function zglos(User $kto, Post $co): TestResponse
    {
        return $this->actingAs($kto)->post(
            route('reports.store', ['type' => 'post', 'id' => $co->getKey()]),
            ['reason' => 'spam', 'details' => 'To jest reklama.'],
        );
    }

    private function wpisDoZgloszenia(): Post
    {
        return Post::factory()->for($this->user('autor'), 'author')->create([
            'status' => Post::STATUS_PUBLISHED,
            'visibility' => Post::VISIBILITY_PUBLIC,
            'published_at' => now()->subHour(),
        ]);
    }

    public function test_awaria_audytu_zgloszenia_zostawia_sprawe_i_potwierdzenie(): void
    {
        Exceptions::fake();
        $zglaszajaca = $this->user('zglaszajaca');
        $wpis = $this->wpisDoZgloszenia();
        $this->zepsujWpis('content.reported');

        $this->zglos($zglaszajaca, $wpis)->assertRedirect()->assertSessionHasNoErrors();

        $sprawa = Report::query()->sole();
        // Potwierdzenie z DSA art. 16 ust. 4 nie zostało pominięte przez
        // awarię dziennika, która stoi przed nim.
        $this->assertNotNull($sprawa->refresh()->receipt_sent_at);
        $this->assertSame(1, Notification::query()->where('type', Notification::TYPE_REPORT_RECEIVED)->count());
        $this->assertSame(0, $this->wpisy('content.reported'));
        $this->assertZgloszonoBrakWpisu('content.reported');

        // Ponowienie nie mnoży spraw ani potwierdzeń.
        $this->zglos($zglaszajaca, $wpis)->assertSessionHasNoErrors();
        $this->assertSame(1, Report::query()->count());
        $this->assertSame(1, Notification::query()->where('type', Notification::TYPE_REPORT_RECEIVED)->count());
    }

    public function test_kontrola_dodatnia_zgloszenie_bez_awarii_zapisuje_wpis(): void
    {
        Exceptions::fake();

        $this->zglos($this->user('zglaszajaca'), $this->wpisDoZgloszenia())->assertSessionHasNoErrors();

        $this->assertSame(1, $this->wpisy('content.reported'));
        $this->assertNieZgloszonoBrakuWpisu();
    }

    // ------------------------------------------------------------------
    // #1343 — zbiorcze zamknięcie sygnałów automatu
    // ------------------------------------------------------------------

    private function oznaczonyAutor(): User
    {
        $autor = $this->user('podejrzany');
        $autor->forceFill(['created_at' => now()->subDays(400)])->save();

        $wpis = Post::create([
            'author_id' => $autor->getKey(),
            'body' => 'Ciasta na zamówienie, tel. 600 100 200.',
            'visibility' => Post::VISIBILITY_PUBLIC,
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        dispatch_sync(new PrzeanalizujTresc(PrzeanalizujTresc::TYP_WPIS, (string) $wpis->getKey()));

        $this->assertSame(1, Report::query()->where('source', Report::SOURCE_AUTOMAT)->count());

        return $autor;
    }

    /**
     * #1343 NIE idzie drogą „za transakcją": wpis zbiorczy jest częścią
     * decyzji moderacyjnej i stoi w jej transakcji (D-249). Awaria dziennika
     * ma więc COFNĄĆ decyzję, a nie udawać sukces — inaczej ponowienie
     * znajdzie zero otwartych oznaczeń i wpisu nie uzupełni nigdy.
     *
     * Kontrola ujemna: przeniesienie `record()` z powrotem za transakcję
     * (albo na `recordBezWywracania()`) zostawia grupę zamkniętą bez wpisu
     * i ten test oblewa na pierwszej asercji o `ModerationAction`.
     */
    public function test_awaria_audytu_zamkniecia_sygnalow_cofa_decyzje_a_ponowienie_daje_jeden_komplet(): void
    {
        Exceptions::fake();
        $moderator = $this->moderator();
        $autor = $this->oznaczonyAutor();
        $this->zepsujWpis('moderation.automat_dismissed');

        $this->actingAs($moderator)
            ->from(route('admin.sygnaly'))
            ->post(route('admin.sygnaly.dismiss'), ['autor' => (string) $autor->getKey()])
            ->assertRedirect(route('admin.sygnaly'))
            ->assertSessionHasErrors(['autor' => 'Nie udało się zamknąć tej grupy i nic się w niej nie zmieniło. Spróbuj jeszcze raz za chwilę.'])
            ->assertSessionMissing('status');

        $this->assertSame(0, ModerationAction::query()->count());
        $oznaczenie = Report::query()->where('source', Report::SOURCE_AUTOMAT)->sole();
        $this->assertTrue($oznaczenie->isOpen());
        $this->assertNull($oznaczenie->resolved_at);
        $this->assertSame(0, $this->wpisy('moderation.automat_dismissed'));
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'Wstrzyknięta awaria dziennika'));

        // Awaria minęła — moderator klika jeszcze raz.
        $this->awaria = false;

        $this->actingAs($moderator)
            ->from(route('admin.sygnaly'))
            ->post(route('admin.sygnaly.dismiss'), ['autor' => (string) $autor->getKey()])
            ->assertRedirect(route('admin.sygnaly'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(1, ModerationAction::query()->where('action', ModerationAction::ACTION_NONE)->count());
        $this->assertSame(Report::STATUS_REJECTED, Report::query()->where('source', Report::SOURCE_AUTOMAT)->sole()->status);
        $this->assertSame(1, $this->wpisy('moderation.automat_dismissed'));
    }

    public function test_kontrola_dodatnia_zamkniecie_sygnalow_bez_awarii_zapisuje_wpis(): void
    {
        Exceptions::fake();
        $moderator = $this->moderator();
        $autor = $this->oznaczonyAutor();

        $this->actingAs($moderator)
            ->post(route('admin.sygnaly.dismiss'), ['autor' => (string) $autor->getKey()])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, $this->wpisy('moderation.automat_dismissed'));
        $this->assertNieZgloszonoBrakuWpisu();
    }

    // ------------------------------------------------------------------
    // Punkt zapisu: awaria nie zrywa transakcji wołającego
    // ------------------------------------------------------------------

    public function test_awaria_wpisu_wewnatrz_cudzej_transakcji_zostawia_ja_zdatna_do_pracy(): void
    {
        Exceptions::fake();
        $osoba = $this->user('ktos');

        DB::transaction(function () use ($osoba): void {
            // Prawdziwy błąd bazy, nie wyjątek PHP: tylko taki zrywa
            // transakcję w PostgreSQL (25P02). `action` ma limit 100 znaków.
            $this->assertNull(AuditLogEntry::recordBezWywracania(str_repeat('x', 101), $osoba));

            // Bez punktu zapisu to zapytanie dostałoby 25P02.
            $this->assertSame(1, User::query()->whereKey($osoba->getKey())->count());
        });

        Exceptions::assertReported(RuntimeException::class);
    }

    // ------------------------------------------------------------------
    // #1429 — zlecenie eksportu danych
    // ------------------------------------------------------------------

    /**
     * Rekord `data_exports` i zadanie w `jobs` zatwierdzają się razem (A02);
     * wpis `data.export_requested` stoi za nimi jako pomocniczy. Kolejka
     * bazodanowa jak na produkcji — przy `sync` nie byłoby czego liczyć.
     */
    public function test_awaria_audytu_eksportu_potwierdza_przyjecie_zamiast_500(): void
    {
        config(['queue.default' => 'database']);
        Exceptions::fake();
        $basia = $this->user('basia');
        $this->zepsujWpis('data.export_requested');

        $odpowiedz = $this->actingAs($basia)->post(route('settings.data.export'))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertStringContainsString('Przygotowujemy paczkę', (string) self::sesjaPrzekierowania($odpowiedz)->get('status'));
        $this->assertSame(1, DataExport::query()->where('user_id', $basia->getKey())->count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(0, $this->wpisy('data.export_requested'));
        $this->assertZgloszonoBrakWpisu('data.export_requested');
        Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), '„data.export_requested"')
            && ! str_contains($e->getMessage(), $basia->email));
    }

    public function test_kontrola_dodatnia_eksport_bez_awarii_zapisuje_wpis(): void
    {
        config(['queue.default' => 'database']);
        Exceptions::fake();
        $basia = $this->user('basia');

        $this->actingAs($basia)->post(route('settings.data.export'))->assertRedirect();

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame(1, $this->wpisy('data.export_requested'));
        $this->assertNieZgloszonoBrakuWpisu();
    }

    // ------------------------------------------------------------------
    // #1573 — blokada
    // ------------------------------------------------------------------

    /**
     * Blokada ma się udać zawsze (D-080, D-090): jej autorytatywny ślad to
     * wiersz `blocks`. Awaria dziennika nie zamienia jej w „nie udało się",
     * a oba odcięcia obserwowania zostają.
     */
    public function test_awaria_audytu_blokady_zostawia_blokade_i_mowi_o_sukcesie(): void
    {
        Exceptions::fake();
        $basia = $this->user('basia');
        $zenek = $this->user('zenek');
        $basia->following()->attach($zenek->getKey(), ['created_at' => now()]);
        $zenek->following()->attach($basia->getKey(), ['created_at' => now()]);
        $this->zepsujWpis('user.blocked');

        $odpowiedz = $this->actingAs($basia)->post(route('social.block', ['username' => 'zenek']))
            ->assertRedirect(route('home'))->assertSessionHasNoErrors();

        $this->assertStringContainsString('Zablokowano', (string) self::sesjaPrzekierowania($odpowiedz)->get('status'));
        $this->assertTrue(Block::query()->where('blocker_id', $basia->getKey())->where('blocked_id', $zenek->getKey())->exists());
        $this->assertSame(0, DB::table('follows')->count());
        $this->assertSame(0, $this->wpisy('user.blocked'));
        $this->assertZgloszonoBrakWpisu('user.blocked');
    }

    public function test_kontrola_dodatnia_blokada_bez_awarii_zapisuje_wpis(): void
    {
        Exceptions::fake();
        $basia = $this->user('basia');
        $this->user('zenek');

        $this->actingAs($basia)->post(route('social.block', ['username' => 'zenek']))->assertRedirect(route('home'));

        $this->assertSame(1, Block::query()->count());
        $this->assertSame(1, $this->wpisy('user.blocked'));
        $this->assertNieZgloszonoBrakuWpisu();
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PrzeanalizujTresc;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification as Powiadomienia;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

/**
 * Awaria dziennika audytu PO zatwierdzonej zmianie nie zamienia jej w błąd
 * dla człowieka (#1373, #1343, #1363, D-088).
 *
 * CO SIĘ DZIAŁO
 * Rejestracja, zgłoszenie treści i zbiorcze zamknięcie sygnałów automatu
 * zatwierdzały swoją transakcję, a dopiero potem wołały
 * `AuditLogEntry::record()`. Wyjątek z tego ostatniego `INSERT`-a leciał do
 * człowieka jako błąd — przy koncie, sprawie albo decyzji, które już
 * istniały. Ponowienie odbijało się od nich („adres zajęty", „już
 * zamknięte") i wpisu też nie uzupełniało.
 *
 * REGUŁA (D-088): wpis za transakcją jest osobnym śladem. Jego awaria idzie
 * do `report()` — widoczna dla operatora, z nazwą brakującego wpisu — a
 * odpowiedź zostaje odpowiedzią udanej zmiany.
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

    private function zepsujWpis(string $akcja): void
    {
        DB::listen(function ($zapytanie) use ($akcja): void {
            if (str_contains($zapytanie->sql, 'insert into "audit_log"')
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

    public function test_awaria_audytu_zamkniecia_sygnalow_pokazuje_sukces_zapisanej_decyzji(): void
    {
        Exceptions::fake();
        $moderator = $this->moderator();
        $autor = $this->oznaczonyAutor();
        $this->zepsujWpis('moderation.automat_dismissed');

        $this->actingAs($moderator)
            ->from(route('admin.sygnaly'))
            ->post(route('admin.sygnaly.dismiss'), ['autor' => (string) $autor->getKey()])
            ->assertRedirect(route('admin.sygnaly'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(Report::STATUS_REJECTED, Report::query()->where('source', Report::SOURCE_AUTOMAT)->sole()->status);
        $this->assertSame(1, ModerationAction::query()->where('action', ModerationAction::ACTION_NONE)->count());
        $this->assertSame(0, $this->wpisy('moderation.automat_dismissed'));
        $this->assertZgloszonoBrakWpisu('moderation.automat_dismissed');
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
}

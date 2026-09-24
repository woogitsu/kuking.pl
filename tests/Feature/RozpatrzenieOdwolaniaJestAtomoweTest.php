<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Moderation\Actions\ResolveAppeal;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\Appeal;
use App\Models\AuditLogEntry;
use App\Models\ModerationAction;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Rozpatrzenie odwołania to JEDNA operacja (#950).
 *
 * Skutek (odblokowanie konta, przywrócenie treści), wynik odwołania,
 * odpowiedź w serwisie i wpis `appeal.resolved` zatwierdzają się razem albo
 * wcale. Wyścig dwóch rozpatrzeń na dwóch połączeniach mierzy
 * `tests/Dwa/RozpatrzenieOdwolaniaNaDwochPolaczeniachTest.php`; tu są dwie
 * rzeczy widoczne na jednym połączeniu:
 *
 *  - awaria na ostatnim kroku (wpis w dzienniku) wycofuje wszystko, także
 *    już wykonane odblokowanie konta. Kontrola ujemna (24.09.2026): bez
 *    `DB::transaction()` w `ResolveAppeal` test oblewa — błąd nie ma wtedy
 *    własnego punktu zapisu, więc psuje transakcję testu (25P02) zamiast
 *    wycofać odblokowanie. Na prawdziwej bazie, bez transakcji testu, to
 *    samo znaczy: konto `active` przy odwołaniu dalej otwartym;
 *  - spóźnione rozpatrzenie z nieodświeżonym obiektem (dwie karty) nie
 *    nadpisuje wyniku — ponowne sprawdzenie stanu pod blokadą.
 */
class RozpatrzenieOdwolaniaJestAtomoweTest extends TestCase
{
    use RefreshDatabase;

    public function test_awaria_w_polowie_nie_zostawia_konta_odblokowanego_przy_otwartym_odwolaniu(): void
    {
        [$autor, $odwolanie] = $this->odwolanieOdBlokady();

        // Awaria wymuszona w bazie na OSTATNIM kroku, po odblokowaniu konta
        // i zapisaniu wyniku. DDL w PostgreSQL jest transakcyjny, więc
        // wyzwalacz znika razem z transakcją testu.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION test_awaria_dziennika() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.action = 'appeal.resolved' THEN
                    RAISE EXCEPTION 'awaria dziennika na żądanie testu';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER test_awaria_dziennika BEFORE INSERT ON audit_log
                FOR EACH ROW EXECUTE FUNCTION test_awaria_dziennika();
            SQL);

        try {
            app(ResolveAppeal::class)->handle(
                moderator: $this->admin(),
                odwolanie: $odwolanie,
                wynik: Appeal::STATUS_OVERTURNED,
                uzasadnienie: 'Linki prowadziły do przepisów. Konto wraca.',
            );
            $this->fail('Wymuszona awaria dziennika nie przerwała rozpatrzenia — test nic nie mierzy.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('awaria dziennika na żądanie testu', $e->getMessage());
        }

        $this->assertSame(User::STATUS_BANNED, $autor->fresh()->status, 'Konto odblokowane, choć rozpatrzenie się nie powiodło.');
        $this->assertSame(Appeal::STATUS_OPEN, $odwolanie->fresh()->status);
        $this->assertNull($odwolanie->fresh()->decided_by);
        $this->assertSame(0, Notification::query()->where('user_id', $autor->getKey())->count());
    }

    public function test_bez_awarii_ta_sama_droga_odblokowuje_konto_i_zamyka_odwolanie(): void
    {
        // KONTROLA DODATNIA do testu wyżej: bez wyzwalacza ta sama droga
        // naprawdę odblokowuje konto — inaczej „zostało zablokowane” nie
        // mówiłoby nic o transakcji.
        [$autor, $odwolanie] = $this->odwolanieOdBlokady();

        app(ResolveAppeal::class)->handle(
            moderator: $this->admin(),
            odwolanie: $odwolanie,
            wynik: Appeal::STATUS_OVERTURNED,
            uzasadnienie: 'Linki prowadziły do przepisów. Konto wraca.',
        );

        $this->assertSame(User::STATUS_ACTIVE, $autor->fresh()->status);
        $this->assertSame(Appeal::STATUS_OVERTURNED, $odwolanie->fresh()->status);
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'appeal.resolved')->count());
    }

    public function test_spoznione_rozpatrzenie_z_nieodswiezonym_obiektem_nie_nadpisuje_wyniku(): void
    {
        [$autor, $odwolanie] = $this->odwolanieOdBlokady();

        // Dwie karty związały odwołanie, gdy było jeszcze `open`.
        $kartaA = $odwolanie->fresh();
        $kartaB = $odwolanie->fresh();

        app(ResolveAppeal::class)->handle($this->admin(), $kartaA, Appeal::STATUS_OVERTURNED, 'Linki prowadziły do przepisów. Konto wraca.');

        // Obejście taniego sprawdzenia na wejściu: w pamięci karty B
        // odwołanie wciąż jest otwarte, jak w drugim równoległym żądaniu.
        $this->assertTrue($kartaB->isOpen());

        try {
            app(ResolveAppeal::class)->handle($this->admin(), $kartaB, Appeal::STATUS_UPHELD, 'Linki były reklamą, blokada zostaje.');
            $this->fail('Drugie rozpatrzenie tego samego odwołania przeszło.');
        } catch (BladDlaCzlowieka $blad) {
            $this->assertSame(ResolveAppeal::JUZ_ROZPATRZONE, $blad->getMessage());
        }

        $this->assertSame(Appeal::STATUS_OVERTURNED, $odwolanie->fresh()->status);
        $this->assertSame(User::STATUS_ACTIVE, $autor->fresh()->status);
        $this->assertSame(1, AuditLogEntry::query()->where('action', 'appeal.resolved')->count());
    }

    /** @return array{0: User, 1: Appeal} */
    private function odwolanieOdBlokady(): array
    {
        $moderator = $this->admin();
        $autor = $this->user('basia');
        $wpis = Post::factory()->create(['author_id' => $autor->getKey()]);

        $zgloszenie = Report::create([
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'reason' => 'spam',
            'status' => Report::STATUS_RESOLVED,
            'resolved_by' => $moderator->getKey(),
            'resolved_at' => now(),
        ]);

        $decyzja = ModerationAction::create([
            'moderator_id' => $moderator->getKey(),
            'report_id' => $zgloszenie->getKey(),
            'target_type' => 'post',
            'target_id' => $wpis->getKey(),
            'subject_user_id' => $autor->getKey(),
            'action' => ModerationAction::ACTION_BAN,
            'reason_code' => 'spam',
            'user_message' => 'Linki reklamowe mimo ostrzeżenia.',
        ]);

        $autor->ban();

        $odwolanie = Appeal::create([
            'moderation_action_id' => $decyzja->getKey(),
            'user_id' => $autor->getKey(),
            'appellant' => Appeal::APPELLANT_AUTHOR,
            'body' => 'To były linki do przepisów mojej córki.',
            'status' => Appeal::STATUS_OPEN,
        ]);

        return [$autor, $odwolanie];
    }
}

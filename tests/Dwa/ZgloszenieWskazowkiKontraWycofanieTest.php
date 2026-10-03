<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Moderation\Actions\ReportContent;
use App\Domain\Recipes\Actions\PoprawWykonanie;
use App\Domain\Wskazowki\WycofajWskazowke;
use App\Models\AuditLogEntry;
use App\Models\Collection;
use App\Models\Comment;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\RecipeHint;
use App\Models\RecipeVersion;
use App\Models\Report;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** #2887: osobne procesy, prawdziwe blokady, prawdziwe zgłoszenie i korekta. */
#[Group('dwa-polaczenia')]
final class ZgloszenieWskazowkiKontraWycofanieTest extends TestDwochPolaczen
{
    private const BEFORE = 'Uwaga A przed wycofaniem.';

    private const AFTER = 'Uwaga B po wycofaniu.';

    /** @var list<ProcesRownolegly> */
    private array $children = [];

    /** @var list<string> */
    private array $hintIds = [];

    /** @var list<string> */
    private array $reportIds = [];

    /** @var list<string> */
    private array $versionIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->assertSame(base_path('app/Domain/Moderation/Actions/ReportContent.php'), (new \ReflectionClass(ReportContent::class))->getFileName());
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            $child->zabij();
        }
        if ($this->hintIds !== []) {
            Report::query()->where('target_type', 'recipe_hint')->whereIn('target_id', $this->hintIds)->delete();
        }
        if ($this->reportIds !== []) {
            Report::query()->whereIn('id', $this->reportIds)->delete();
        }
        if ($this->versionIds !== []) {
            RecipeVersion::query()->whereIn('id', $this->versionIds)->delete();
        }
        parent::tearDown();
    }

    /** @return array<string, array{bool, bool}> */
    public static function accounts(): array
    {
        return [
            'ordinary-cook-lower' => [false, true],
            'ordinary-author-lower' => [false, false],
            'author-cook-lower' => [true, true],
            'author-author-lower' => [true, false],
        ];
    }

    /** @return array{User, User, User, CookedEvent, RecipeHint} */
    private function fixture(bool $viewerIsAuthor, bool $cookIsLower): array
    {
        [$lower, $higher] = $this->paraPosortowana();
        [$cook, $author] = $cookIsLower ? [$lower, $higher] : [$higher, $lower];
        $viewer = $viewerIsAuthor ? $author : $this->konto();
        $moderator = $this->moderator();
        $this->konta[] = (string) $moderator->getKey();
        $recipe = Recipe::factory()->create(['author_id' => $author->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']);
        $event = CookedEvent::factory()->create(['user_id' => $cook->getKey(), 'recipe_id' => $recipe->getKey(), 'note' => self::BEFORE]);
        $hint = RecipeHint::factory()->dlaWykonania($event, RecipeHint::STATUS_ACCEPTED)->create();
        $this->hintIds[] = (string) $hint->getKey();

        return [$cook, $viewer, $moderator, $event, $hint];
    }

    private function start(string $mode, User $viewer, User $cook, RecipeHint $hint): ProcesRownolegly
    {
        $child = ProcesRownolegly::start(__DIR__.'/bin/zgloszenie-wskazowki.php', $mode, [
            'viewer' => (string) $viewer->getKey(), 'cook' => (string) $cook->getKey(), 'hint' => (string) $hint->getKey(),
        ], ['DB_DATABASE' => $this->baza, 'APP_BASE_PATH' => base_path(), 'APP_ENV' => 'testing']);
        $this->children[] = $child;

        return $child;
    }

    /** @return array{pid: int, blockers: string} */
    private function blockedBy(string $mode, RecipeHint $hint, int $blocker): array
    {
        $query = $this->obserwator->prepare('SELECT pid, pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE datname = current_database() AND application_name = ? AND CAST(? AS int) = ANY(pg_blocking_pids(pid))');
        $deadline = microtime(true) + self::SEKUNDY_NA_KOLEJKE;
        do {
            $query->execute(['2887-'.$mode.'-'.$hint->getKey(), $blocker]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (is_array($row)) {
                return ['pid' => (int) $row['pid'], 'blockers' => (string) $row['blockers']];
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->fail('WSKAZOWKA_2887_BRAK_WLASCIWEJ_BLOKADY: '.$mode.' nie czeka na backend '.$blocker.'.');
    }

    private function quote(User $moderator): string
    {
        $response = $this->actingAs($moderator)->get(route('admin.reports', ['status' => 'wszystkie']))->assertOk();
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.(string) $response->getContent());
        $quotes = (new DOMXPath($dom))->query('//blockquote[contains(@class,"wskazowka-cytat")]');
        $this->assertSame(1, $quotes->length);

        return $quotes->item(0)->textContent;
    }

    /** @param array<string, mixed> $data */
    private function evidence(array $data): void
    {
        $path = getenv('WSKAZOWKA_2887_EVIDENCE');
        if (is_string($path) && $path !== '') {
            file_put_contents($path, json_encode(['test' => $this->nameWithDataSet(), 'database' => $this->baza] + $data, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
        }
    }

    #[DataProvider('accounts')]
    public function test_withdraw_and_edit_first_refuses_late_report(bool $viewerIsAuthor, bool $cookIsLower): void
    {
        [$cook, $viewer, , $event, $hint] = $this->fixture($viewerIsAuthor, $cookIsLower);
        $barrier = $this->bariera('SELECT pg_advisory_xact_lock(628870, 1)', []);
        $barrierPid = (int) $this->odczytaj($barrier, 'SELECT pg_backend_pid()');
        $reporter = $this->start('report-forward', $viewer, $cook, $hint);
        $wait = $this->blockedBy('report-forward', $hint, $barrierPid);
        $this->assertSame(0, Report::query()->where('target_id', $hint->getKey())->count());
        app(WycofajWskazowke::class)->handle($cook, $hint);
        app(PoprawWykonanie::class)->handle($cook, $event, ['note' => self::AFTER], $event->fresh()->wersjaPolKorekty());
        $this->assertSame(self::AFTER, $event->fresh()->note);
        $this->zwolnijBariere($barrier);
        $result = $reporter->wynik();
        $this->evidence(['order' => 'withdraw-first', 'wait' => $wait, 'result' => $result, 'reports' => Report::query()->where('target_id', $hint->getKey())->count(), 'note' => $event->fresh()->note]);
        $this->assertSame(0, Report::query()->where('target_id', $hint->getKey())->count(), 'WSKAZOWKA_2887_PO_WYCOFANIU_BEZ_REPORT');
        $this->assertFalse($result['ok']);
        $this->assertSame(ModelNotFoundException::class, $result['wyjatek']);
        $this->assertNull($result['sqlstate']);
        $this->assertTrue($result['wartosc']['initial_authorization']);
        $this->assertSame(self::BEFORE, $result['wartosc']['note_before']);
        $this->assertSame(0, Notification::query()->where('user_id', $viewer->getKey())->where('type', Notification::TYPE_REPORT_RECEIVED)->count());
        $this->assertSame(0, AuditLogEntry::query()->where('actor_id', $viewer->getKey())->where('action', 'content.reported')->count());
        $this->actingAs($viewer);
        $refused = $this->post(route('reports.store', ['type' => 'recipe_hint', 'id' => $hint->getKey()]), ['reason' => 'spam'])->assertNotFound();
        $missingId = (string) Str::uuid();
        $missing = $this->post(route('reports.store', ['type' => 'recipe_hint', 'id' => $missingId]), ['reason' => 'spam'])->assertNotFound();
        // Kanoniczny adres zawiera żądany UUID; pozostała odpowiedź jest identyczna.
        $this->assertSame(
            str_replace($missingId, 'target-uuid', $missing->getContent()),
            str_replace((string) $hint->getKey(), 'target-uuid', $refused->getContent()),
        );
        $refused->assertDontSee('moderacja', false)->assertDontSee(self::BEFORE)->assertDontSee(self::AFTER);
    }

    #[DataProvider('accounts')]
    public function test_report_first_serializes_withdraw_and_protects_note(bool $viewerIsAuthor, bool $cookIsLower): void
    {
        [$cook, $viewer, $moderator, $event, $hint] = $this->fixture($viewerIsAuthor, $cookIsLower);
        $barrier = $this->bariera('SELECT pg_advisory_xact_lock(628870, 1)', []);
        $barrierPid = (int) $this->odczytaj($barrier, 'SELECT pg_backend_pid()');
        $reporter = $this->start('report-reverse', $viewer, $cook, $hint);
        $reportWait = $this->blockedBy('report-reverse', $hint, $barrierPid);
        $withdrawal = $this->start('withdraw-edit', $viewer, $cook, $hint);
        $withdrawWait = $this->blockedBy('withdraw-edit', $hint, $reportWait['pid']);
        $this->assertNotSame($reportWait['pid'], $withdrawWait['pid']);
        $this->assertSame(0, Report::query()->where('target_id', $hint->getKey())->count());
        $this->assertSame(RecipeHint::STATUS_ACCEPTED, $hint->fresh()->status);
        $this->zwolnijBariere($barrier);
        $reportResult = $reporter->wynik();
        $withdrawResult = $withdrawal->wynik();
        $this->assertBezZakleszczenia($reportResult, 'Zgłoszenie #2887');
        $this->assertBezZakleszczenia($withdrawResult, 'Wycofanie i korekta #2887');
        $this->assertTrue($reportResult['ok'], $reportResult['komunikat']);
        $this->assertTrue($withdrawResult['ok'], $withdrawResult['komunikat']);
        $this->assertSame(['changes_note', 'actual_minutes'], $withdrawResult['wartosc']['changed']);
        $this->assertSame(['note'], array_keys($withdrawResult['wartosc']['skipped']));
        $this->assertSame('Tej uwagi nie można teraz zmienić. Czas i opis zmian nadal możesz poprawić.', $withdrawResult['wartosc']['skipped']['note']);
        $this->assertSame(RecipeHint::STATUS_WITHDRAWN, $hint->fresh()->status);
        $this->assertFalse($hint->fresh()->jestPokazywana());
        $this->assertSame(self::BEFORE, $event->fresh()->note, 'WSKAZOWKA_2887_REPORT_PIERWSZY_CHRONI_NOTE');
        $this->assertSame('Osobny opis zmian.', $event->fresh()->changes_note);
        $this->assertSame(23, $event->fresh()->actual_minutes);
        $this->assertSame(Report::STATUS_OPEN, Report::query()->where('target_id', $hint->getKey())->sole()->status);
        $this->assertSame(self::BEFORE, $this->quote($moderator));
        $this->evidence(['order' => 'report-first', 'report_wait' => $reportWait, 'withdraw_wait' => $withdrawWait, 'report' => $reportResult, 'withdraw' => $withdrawResult, 'moderator_quote' => self::BEFORE]);
    }

    public function test_concurrent_hint_reports_keep_one_report_and_receipt(): void
    {
        [$cook, $viewer, $moderator, , $hint] = $this->fixture(false, true);
        $barrier = $this->bariera('SELECT pg_advisory_xact_lock(628870, 1)', []);
        $barrierPid = (int) $this->odczytaj($barrier, 'SELECT pg_backend_pid()');
        $first = $this->start('report-reverse', $viewer, $cook, $hint);
        $firstWait = $this->blockedBy('report-reverse', $hint, $barrierPid);
        $second = $this->start('report-duplicate', $viewer, $cook, $hint);
        $secondWait = $this->blockedBy('report-duplicate', $hint, $firstWait['pid']);
        $this->zwolnijBariere($barrier);
        $firstResult = $first->wynik();
        $secondResult = $second->wynik();
        $this->assertTrue($firstResult['ok'], $firstResult['komunikat']);
        $this->assertTrue($secondResult['ok'], $secondResult['komunikat']);
        $this->assertSame($firstResult['wartosc']['report_id'], $secondResult['wartosc']['report_id']);
        $this->assertSame(1, Report::query()->where('target_id', $hint->getKey())->count());
        $this->assertSame(1, Notification::query()->where('user_id', $viewer->getKey())->where('type', Notification::TYPE_REPORT_RECEIVED)->count());
        $this->assertSame(1, AuditLogEntry::query()->where('actor_id', $viewer->getKey())->where('action', 'content.reported')->count());
        $this->assertSame(self::BEFORE, $this->quote($moderator));
        $this->evidence(['order' => 'duplicate', 'first_wait' => $firstWait, 'second_wait' => $secondWait, 'first' => $firstResult, 'second' => $secondResult]);
    }

    public function test_other_seven_types_keep_reporting_and_duplicates(): void
    {
        $owner = $this->konto();
        $viewer = $this->konto();
        $recipe = Recipe::factory()->create(['author_id' => $owner->getKey(), 'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public']);
        $post = Post::factory()->create(['author_id' => $owner->getKey(), 'status' => Post::STATUS_PUBLISHED, 'visibility' => Post::VISIBILITY_PUBLIC]);
        $comment = Comment::factory()->create(['author_id' => $owner->getKey(), 'post_id' => $post->getKey(), 'recipe_id' => null]);
        $event = CookedEvent::factory()->create(['user_id' => $owner->getKey(), 'recipe_id' => $recipe->getKey()]);
        $collection = Collection::create(['owner_id' => $owner->getKey(), 'name' => 'Zeszyt kontrolny', 'visibility' => 'public']);
        $version = RecipeVersion::create(['recipe_id' => $recipe->getKey(), 'editor_id' => $owner->getKey(), 'version_number' => 1, 'snapshot' => ['title' => $recipe->title, 'ingredients' => [], 'steps' => []]]);
        $latest = RecipeVersion::create(['recipe_id' => $recipe->getKey(), 'editor_id' => $owner->getKey(), 'version_number' => 2, 'snapshot' => ['title' => $recipe->title, 'ingredients' => [], 'steps' => []]]);
        $this->versionIds = [(string) $version->getKey(), (string) $latest->getKey()];

        foreach (['user' => $owner, 'post' => $post, 'recipe' => $recipe, 'comment' => $comment, 'cooked_event' => $event, 'collection' => $collection, 'recipe_version' => $version] as $type => $target) {
            $first = app(ReportContent::class)->handle($viewer, $target, 'spam');
            $this->reportIds[] = (string) $first->getKey();
            $second = app(ReportContent::class)->handle($viewer, $target, 'spam');
            $this->assertTrue($first->wasRecentlyCreated, $type);
            $this->assertFalse($second->wasRecentlyCreated, $type);
            $this->assertSame($type, $first->target_type);
            $this->assertSame($target->getKey(), $first->target_id);
            $this->assertSame($first->getKey(), $second->getKey());
            $this->assertSame(1, Report::query()->where('target_type', $type)->where('target_id', $target->getKey())->count());
        }
        $this->assertSame(7, Notification::query()->where('user_id', $viewer->getKey())->where('type', Notification::TYPE_REPORT_RECEIVED)->count());
        $this->assertSame(7, AuditLogEntry::query()->where('actor_id', $viewer->getKey())->where('action', 'content.reported')->count());
    }

    public function test_visible_hint_and_duplicate_keep_one_report_and_receipt(): void
    {
        [, $viewer, $moderator, , $hint] = $this->fixture(false, true);
        $first = app(ReportContent::class)->handle($viewer, $hint, 'spam');
        $second = app(ReportContent::class)->handle($viewer, $hint, 'spam');
        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertFalse($second->wasRecentlyCreated);
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, Report::query()->where('target_id', $hint->getKey())->count());
        $this->assertSame(1, Notification::query()->where('user_id', $viewer->getKey())->where('type', Notification::TYPE_REPORT_RECEIVED)->count());
        $this->assertSame(1, AuditLogEntry::query()->where('actor_id', $viewer->getKey())->where('action', 'content.reported')->count());
        $this->assertSame(self::BEFORE, $this->quote($moderator));
    }
}

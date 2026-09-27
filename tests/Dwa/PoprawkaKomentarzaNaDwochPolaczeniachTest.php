<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Comments\Actions\EditComment;
use App\Domain\Comments\KonfliktPoprawkiKomentarza;
use App\Models\Comment;
use App\Models\Post;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * Issue #982 na dwóch połączeniach: karta A zapisuje poprawkę i trzyma
 * transakcję otwartą, karta B (osobny proces) przychodzi z wersją sprzed
 * zapisu A. B musi poczekać na A i dostać konflikt — nie przeczytać starej
 * treści, zgodzić się z nią i nadpisać A po zatwierdzeniu.
 *
 * Kontrola ujemna (ręczna, opisana w PR): bez porównania wersji albo bez
 * blokady wiersza w `EditComment` karta B kończy się sukcesem, a w bazie
 * zostaje jej tekst — test oblewa na asercji o treści.
 */
#[Group('dwa-polaczenia')]
final class PoprawkaKomentarzaNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @var list<ProcesRownolegly> */
    private array $karty = [];

    protected function tearDown(): void
    {
        foreach ($this->karty as $karta) {
            $karta->zabij();
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        parent::tearDown();
    }

    public function test_stara_karta_czeka_na_nowsza_poprawke_i_dostaje_konflikt(): void
    {
        $autor = $this->konto(['email' => 'race-'.bin2hex(random_bytes(12)).'@example.invalid']);
        $post = Post::factory()->for($autor, 'author')->create(['status' => 'published', 'visibility' => 'public', 'published_at' => now()->subDay()]);
        $comment = Comment::factory()->create([
            'author_id' => $autor->getKey(), 'post_id' => $post->getKey(), 'body' => 'Sól do smaku',
        ]);
        $wersjaStartowa = $comment->wersjaTresci();
        $name = 'poprawka-982-'.bin2hex(random_bytes(5));

        // Karta A: prawdziwa akcja, transakcja jeszcze niezatwierdzona.
        DB::beginTransaction();
        $this->assertNotNull(
            app(EditComment::class)->handle($autor, $comment, 'Pół łyżeczki soli', $wersjaStartowa),
            'Kontrola: karta A nie zapisała poprawki — Policy odmówiła, test nie mierzy wyścigu.',
        );

        $kartaB = $this->karta($name, $comment, 'Sól i pieprz do smaku', $wersjaStartowa);
        $this->czekajNaKarte($name);
        DB::commit();

        $wynik = $kartaB->wynik(12);
        $this->assertBezZakleszczenia($wynik, 'karta B');
        $this->assertFalse($wynik['ok'], 'Karta B nadpisała poprawkę z karty A: '.json_encode($wynik));
        $this->assertSame(KonfliktPoprawkiKomentarza::class, $wynik['wyjatek'], $wynik['komunikat']);
        $this->assertSame('Pół łyżeczki soli', $comment->fresh()->body);

        // Kontrola dodatnia: formularz z AKTUALNĄ wersją zapisuje normalnie.
        $kartaC = $this->karta($name.'-c', $comment, 'Sól i pieprz do smaku', $comment->fresh()->wersjaTresci());
        $dodatnia = $kartaC->wynik(12);
        $this->assertTrue($dodatnia['ok'], $dodatnia['komunikat']);
        $this->assertSame('Sól i pieprz do smaku', $comment->fresh()->body);
    }

    private function karta(string $name, Comment $comment, string $body, string $wersja): ProcesRownolegly
    {
        $karta = ProcesRownolegly::start(__DIR__.'/bin/poprawkaKomentarza.php', 'edit', [
            'name' => $name, 'comment' => (string) $comment->getKey(), 'body' => $body, 'wersja' => $wersja,
        ], [
            'DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
        $this->karty[] = $karta;

        return $karta;
    }

    /** Karta B naprawdę stoi w kolejce po wiersz trzymany przez kartę A. */
    private function czekajNaKarte(string $name): void
    {
        $pid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        $query = $this->obserwator->prepare("SELECT pg_blocking_pids(pid)::text AS blockers FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'");
        $deadline = microtime(true) + 6;
        do {
            $query->execute([$name]);
            $blockers = $query->fetchColumn();
            if (is_string($blockers)) {
                $this->assertStringContainsString((string) $pid, $blockers);

                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Karta B nie stanęła w kolejce po wiersz komentarza — przeplot się nie ustawił.');
    }
}

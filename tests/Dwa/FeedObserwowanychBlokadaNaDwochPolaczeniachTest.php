<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Social\Actions\BlockUser;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Issue #2026 NA DWÓCH POŁĄCZENIACH: blokada ZATWIERDZONA na połączeniu B
 * między listą obserwowanych a głównym SELECT-em wpisów połączenia A.
 *
 * `FeedObserwowanychBlokadaWTrakcieZadaniaTest` (tests/Feature) odtwarza
 * ten sam wyścig w jednym połączeniu i w transakcji `RefreshDatabase` —
 * szybka regresja, ale nie dowód, że zapytanie wpisów widzi stan
 * zatwierdzony PRZEZ KOGOŚ INNEGO. Tutaj:
 *
 *   A (osobny proces, `bin/feedObserwowanych.php`) woła prawdziwy
 *     `FollowingFeed::paginate()` i staje na barierze zaraz PO zapytaniu
 *     o listę obserwowanych, a PRZED zapytaniem o wpisy;
 *   B (połączenie tego testu) wykonuje `BlockUser` — prawdziwa akcja,
 *     własna transakcja — i ją ZATWIERDZA; commit potwierdza odczyt
 *     z trzeciego, niezależnego połączenia;
 *   A rusza dalej. Wpis zablokowanej osoby — publiczny, „tylko dla
 *     obserwujących" i publiczny z obserwowanym tagiem — nie może wyjść.
 *
 * Kontrola dodatnia: ten sam przeplot, ta sama bariera, bez blokady —
 * wszystkie trzy wpisy wychodzą. Bez niej asercje „nie ma wpisu" przeszłyby
 * także przy pustej stronie (`docs/PULAPKI_TESTOW.md` §4).
 *
 * ── KONTROLA UJEMNA (wykonana 28.09.2026) ──
 *
 * Cofnięcie poprawki w `FollowingFeed::zrodla()` — `widoczneDla($viewer)`
 * z powrotem tylko w gałęzi tagów — oblewa oba przypadki blokady:
 * lista autorów z PHP ma zablokowaną osobę, a jej wpisy przechodzą.
 */
#[Group('dwa-polaczenia')]
final class FeedObserwowanychBlokadaNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $tagi = [];

    /** @var list<ProcesRownolegly> */
    private array $procesyFeedu = [];

    protected function tearDown(): void
    {
        foreach ($this->procesyFeedu as $proces) {
            $proces->zabij();
        }

        if ($this->tagi !== []) {
            $this->nowePolaczenie()->prepare('DELETE FROM tags WHERE id = ANY(?::uuid[])')
                ->execute(['{'.implode(',', $this->tagi).'}']);
        }

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function kierunki(): array
    {
        return [
            'widz blokuje autora' => ['widz'],
            'autor blokuje widza' => ['autor'],
        ];
    }

    #[DataProvider('kierunki')]
    public function test_blokada_zatwierdzona_miedzy_lista_a_wpisami_zamyka_wpisy(string $ktoBlokuje): void
    {
        [$widz, $autor, $inna, $wpisy, $wpisInnej] = $this->dane();

        $wynik = $this->przeplot($widz, function () use ($widz, $autor, $ktoBlokuje): void {
            // B: prawdziwa akcja, bez transakcji zewnętrznej — `BlockUser`
            // zatwierdza własną transakcję, zanim odda sterowanie.
            $this->assertSame(0, DB::transactionLevel());
            $ktoBlokuje === 'widz'
                ? app(BlockUser::class)->handle($widz, $autor)
                : app(BlockUser::class)->handle($autor, $widz);

            // Jawne potwierdzenie COMMIT: trzecie połączenie, spoza A i B.
            $swiadek = $this->nowePolaczenie();
            [$blokady, $obserwowania] = $this->stanPary($swiadek, $widz, $autor);
            $this->assertSame(1, $blokady, 'Blokada nie jest zatwierdzona — trzecie połączenie jej nie widzi.');
            $this->assertSame(0, $obserwowania, 'Po zatwierdzonej blokadzie zostało obserwowanie.');
        });

        $przecieki = array_keys(array_filter($wpisy,
            fn (Post $wpis): bool => in_array((string) $wpis->getKey(), $wynik['ids'], true)));
        $this->assertSame([], $przecieki,
            'Wpisy zablokowanej osoby przeciekły do Obserwowanych po blokadzie zatwierdzonej na drugim połączeniu.');
        // Blokada zamyka jedną osobę, nie całą gałąź obserwowanych.
        $this->assertContains((string) $wpisInnej->getKey(), $wynik['ids']);
        $this->assertSame(1, DB::table('follows')->where('follower_id', $widz->getKey())
            ->where('followed_id', $inna->getKey())->count());
    }

    public function test_kontrola_dodatnia_ten_sam_przeplot_bez_blokady_pokazuje_wpisy(): void
    {
        [$widz, $autor, , $wpisy, $wpisInnej] = $this->dane();

        $wynik = $this->przeplot($widz, function () use ($widz, $autor): void {
            [$blokady, $obserwowania] = $this->stanPary($this->nowePolaczenie(), $widz, $autor);
            $this->assertSame(0, $blokady);
            $this->assertSame(1, $obserwowania);
        });

        foreach ($wpisy as $opis => $wpis) {
            $this->assertContains((string) $wpis->getKey(), $wynik['ids'],
                "Bez blokady wpis obserwowanej osoby ({$opis}) nie wyszedł — przyrząd nic nie mierzy.");
        }
        $this->assertContains((string) $wpisInnej->getKey(), $wynik['ids']);
    }

    /**
     * Uruchamia A, czeka, aż stanie na barierze po liście obserwowanych,
     * wykonuje `$srodek` (B), zwalnia barierę i zwraca wynik A.
     *
     * @param  callable(): void  $srodek
     * @return array{ids: list<string>, bariera: bool, posts_przed: int, posts_po: int, w_transakcji: ?int}
     */
    private function przeplot(User $widz, callable $srodek): array
    {
        $nazwa = 'feed2026-'.bin2hex(random_bytes(4));
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2026, hashtext(?))', [$nazwa]);

        try {
            $proces = ProcesRownolegly::start(__DIR__.'/bin/feedObserwowanych.php', 'feed', [
                'name' => $nazwa, 'viewer' => (string) $widz->getKey(),
            ], [
                'DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'sync',
                'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
            ]);
            $this->procesyFeedu[] = $proces;

            $this->czekajNaBariere($nazwa, $bariera);

            $srodek();
        } finally {
            if ($bariera->inTransaction()) {
                $bariera->rollBack();
            }
        }

        $wynik = $proces->wynik();
        $this->assertTrue($wynik['ok'], (string) $wynik['wyjatek'].': '.$wynik['komunikat']);

        /** @var array{ids: list<string>, bariera: bool, posts_przed: int, posts_po: int, w_transakcji: ?int} $wartosc */
        $wartosc = $wynik['wartosc'];
        // Przeplot naprawdę był taki, jak w issue: bariera po liście
        // obserwowanych, A poza transakcją, a wpisy czytane DOPIERO po niej.
        $this->assertTrue($wartosc['bariera'], 'A nie zatrzymało się po liście obserwowanych.');
        $this->assertSame(0, $wartosc['w_transakcji'], 'A trzymało transakcję — mierzylibyśmy migawkę, nie READ COMMITTED.');
        $this->assertSame(0, $wartosc['posts_przed'], 'A czytało wpisy przed barierą.');
        $this->assertGreaterThan(0, $wartosc['posts_po'], 'A nie czytało wpisów po barierze.');

        return $wartosc;
    }

    /** Czeka, aż proces o tej nazwie stoi w kolejce za barierą (i właśnie za nią). */
    private function czekajNaBariere(string $nazwa, PDO $bariera): void
    {
        $wlasciciel = (string) $bariera->query('SELECT pg_backend_pid()')?->fetchColumn();
        $zapytanie = $this->obserwator->prepare("SELECT pg_blocking_pids(pid)::text FROM pg_stat_activity
            WHERE application_name = ? AND wait_event_type = 'Lock'");
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;

        do {
            $zapytanie->execute([$nazwa]);
            $blokujacy = $zapytanie->fetchColumn();

            if (is_string($blokujacy)) {
                $this->assertStringContainsString($wlasciciel, $blokujacy);

                return;
            }

            usleep(10_000);
        } while (microtime(true) < $koniec);

        $this->fail('Połączenie A nie stanęło na barierze po liście obserwowanych — przeplot się nie ustawił.');
    }

    /** @return array{0: int, 1: int} blokady w parze, obserwowania w parze */
    private function stanPary(PDO $polaczenie, User $a, User $b): array
    {
        $para = [(string) $a->getKey(), (string) $b->getKey(), (string) $b->getKey(), (string) $a->getKey()];

        $blokady = $polaczenie->prepare('SELECT count(*) FROM blocks
            WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?)');
        $blokady->execute($para);
        $obserwowania = $polaczenie->prepare('SELECT count(*) FROM follows
            WHERE (follower_id = ? AND followed_id = ?) OR (follower_id = ? AND followed_id = ?)');
        $obserwowania->execute($para);

        return [(int) $blokady->fetchColumn(), (int) $obserwowania->fetchColumn()];
    }

    /** @return array{0: User, 1: User, 2: User, 3: array<string, Post>, 4: Post} */
    private function dane(): array
    {
        $widz = $this->konto();
        $autor = $this->konto();
        $inna = $this->konto();
        $widz->following()->attach($autor->getKey(), ['created_at' => now()]);
        $widz->following()->attach($inna->getKey(), ['created_at' => now()]);

        $tag = Tag::factory()->create();
        $this->tagi[] = (string) $tag->getKey();
        DB::table('tag_follows')->insert([
            'user_id' => $widz->getKey(), 'tag_id' => $tag->getKey(), 'created_at' => now(),
        ]);

        $wpisy = [
            'publiczny' => Post::factory()->create(['author_id' => $autor->getKey(), 'published_at' => now()->subMinutes(30)]),
            'tylko dla obserwujących' => Post::factory()->followersOnly()
                ->create(['author_id' => $autor->getKey(), 'published_at' => now()->subMinutes(20)]),
            'publiczny z obserwowanym tagiem' => Post::factory()
                ->create(['author_id' => $autor->getKey(), 'published_at' => now()->subMinutes(10)]),
        ];
        $wpisy['publiczny z obserwowanym tagiem']->tags()->attach($tag->getKey(), ['position' => 0]);

        $wpisInnej = Post::factory()->followersOnly()
            ->create(['author_id' => $inna->getKey(), 'published_at' => now()->subMinutes(5)]);

        return [$widz, $autor, $inna, $wpisy, $wpisInnej];
    }
}

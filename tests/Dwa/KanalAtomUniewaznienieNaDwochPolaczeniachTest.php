<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Models\Collection;
use App\Models\Post;
use App\Models\Tag;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** #2863: późny builder nie może odtworzyć kopii po modelowym ukryciu. */
#[Group('dwa-polaczenia')]
final class KanalAtomUniewaznienieNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @var list<string> */
    private array $tagi = [];

    /** @var list<ProcesRownolegly> */
    private array $uczestnicy = [];

    protected function tearDown(): void
    {
        foreach ($this->uczestnicy as $uczestnik) {
            $uczestnik->zabij();
        }

        foreach ($this->tagi as $id) {
            DB::table('tags')->where('id', $id)->delete();
        }

        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function rodzaje(): array
    {
        return ['profil' => ['profil'], 'tag' => ['tag'], 'zeszyt' => ['zeszyt']];
    }

    #[DataProvider('rodzaje')]
    public function test_pozny_builder_nie_przywraca_ukrytego_wpisu(string $rodzaj): void
    {
        config(['cache.default' => 'database', 'kuking.kanal_cache_sekund' => 300]);

        $autor = $this->konto();
        $stary = Post::factory()->create(['author_id' => $autor->getKey(), 'body' => 'ATOM_2863_WYCOFANY']);
        $kontrolny = Post::factory()->create(['author_id' => $autor->getKey(), 'body' => 'ATOM_2863_POZOSTAJE']);
        $tag = Tag::factory()->create();
        $this->tagi[] = (string) $tag->getKey();
        $stary->tags()->attach($tag->getKey());
        $kontrolny->tags()->attach($tag->getKey());
        $zeszyt = Collection::create(['owner_id' => $autor->getKey(), 'name' => 'Kanał 2863', 'visibility' => 'public']);
        $zeszyt->posts()->attach($stary->getKey(), ['created_at' => now()->subMinute()]);
        $zeszyt->posts()->attach($kontrolny->getKey(), ['created_at' => now()]);

        $url = match ($rodzaj) {
            'profil' => route('kanaly.profil', $autor->profile->username),
            'tag' => route('kanaly.tag', $tag->slug),
            'zeszyt' => route('kanaly.zeszyt', $zeszyt),
            default => throw new \InvalidArgumentException('Nieznany typ kanału w teście.'),
        };
        $nazwa = 'atom2863-'.bin2hex(random_bytes(4));
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(28630, hashtext(?))', [$nazwa]);

        try {
            $a = $this->uruchom('kanal', $nazwa.'-a', ['url' => $url, 'barrier' => $nazwa]);
            $this->czekajNaBariere($nazwa.'-a', $bariera, $a);

            $b = $this->uruchom('ukryj', $nazwa.'-b', ['post' => (string) $stary->getKey()]);
            $this->czekajNaCommit($stary);
            $this->czekajNaUniewaznienie($nazwa.'-b', $b);
        } finally {
            if ($bariera->inTransaction()) {
                $bariera->rollBack();
            }
        }

        $wynikA = $a->wynik();
        $wynikB = $b->wynik();
        $this->assertTrue($wynikA['ok'], $wynikA['komunikat']);
        $this->assertTrue($wynikB['ok'], $wynikB['komunikat']);
        $this->assertSame('ukryto', $wynikB['wartosc']);
        $this->assertSame(200, $wynikA['wartosc']['status']);
        $this->assertTrue($wynikA['wartosc']['bariera'], 'Budowniczy nie zatrzymał się po odczycie wpisu.');
        $this->assertStringContainsString('ATOM_2863_WYCOFANY', $wynikA['wartosc']['xml']);
        $this->assertStringContainsString('ATOM_2863_POZOSTAJE', $wynikA['wartosc']['xml']);

        // C zaczyna PO zakończeniu modelowego ukrycia i spóźnionego GET A.
        $c = $this->get($url)->assertOk();
        $this->assertStringNotContainsString('ATOM_2863_WYCOFANY', (string) $c->getContent(), 'ATOM_2863_BEZ_POWROTU: ukryty wpis wrócił do cache.');
        $this->assertStringContainsString('ATOM_2863_POZOSTAJE', (string) $c->getContent());
        $this->assertNotSame($wynikA['wartosc']['etag'], (string) $c->headers->get('ETag'));
        $this->assertSame((string) $c->getContent(), (string) $this->get($url)->assertOk()->getContent());
    }

    /** @param array<string, string> $args */
    private function uruchom(string $scenariusz, string $nazwa, array $args): ProcesRownolegly
    {
        $proces = ProcesRownolegly::start(__DIR__.'/bin/kanal-atom-2863.php', $scenariusz, ['name' => $nazwa, ...$args], [
            'DB_DATABASE' => $this->baza,
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'database',
            'KUKING_KANAL_CACHE_SEKUND' => '300',
        ]);
        $this->uczestnicy[] = $proces;

        return $proces;
    }

    private function czekajNaBariere(string $nazwa, PDO $bariera, ProcesRownolegly $proces): void
    {
        $pidBariery = (string) $this->odczytaj($bariera, 'SELECT pg_backend_pid()');
        $sql = $this->obserwator->prepare("SELECT pg_blocking_pids(pid)::text FROM pg_stat_activity
            WHERE application_name = ? AND wait_event_type = 'Lock'");
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;

        do {
            $sql->execute([$nazwa]);
            $blokujacy = $sql->fetchColumn();
            if (is_string($blokujacy)) {
                $this->assertStringContainsString($pidBariery, $blokujacy);

                return;
            }
            if ($proces->zakonczony()) {
                $wynik = $proces->wynik();
                $this->fail('Proces A skończył przed barierą: '.json_encode([
                    'ok' => $wynik['ok'], 'status' => $wynik['wartosc']['status'] ?? null,
                    'bariera' => $wynik['wartosc']['bariera'] ?? null,
                    'komunikat' => $wynik['komunikat'],
                ]));
            }
            usleep(10_000);
        } while (microtime(true) < $koniec);

        $this->fail('Proces A nie stanął po odczycie wpisu.');
    }

    private function czekajNaCommit(Post $post): void
    {
        $sql = $this->obserwator->prepare('SELECT status FROM posts WHERE id = ?');
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;
        do {
            $sql->execute([(string) $post->getKey()]);
            if ($sql->fetchColumn() === Post::STATUS_HIDDEN) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $koniec);

        $this->fail('Ukrycie B nie zostało zatwierdzone, zanim wznowiono A.');
    }

    private function czekajNaUniewaznienie(string $nazwa, ProcesRownolegly $proces): void
    {
        $sql = $this->obserwator->prepare('SELECT wait_event_type, query FROM pg_stat_activity WHERE application_name = ?');
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;
        do {
            // Stary kod kończy forget przed A; poprawiony czeka na jego
            // blokadę. Oba stany ustalają kolejność bez wyścigu zegarowego.
            if ($proces->zakonczony()) {
                return;
            }
            $sql->execute([$nazwa]);
            $stan = $sql->fetch(PDO::FETCH_ASSOC);
            if (is_array($stan) && $stan['wait_event_type'] === 'Lock'
                && str_contains((string) $stan['query'], 'pg_advisory_xact_lock(2863')) {
                return;
            }
            usleep(10_000);
        } while (microtime(true) < $koniec);

        $this->fail('B ani nie wyczyściło cache, ani nie czeka na blokadę budowniczego.');
    }
}

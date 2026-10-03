<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Recipes\Gotowanie\Wspolne\PostepWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\SesjaWspolnegoGotowania;
use App\Domain\Recipes\Gotowanie\Wspolne\ZaproszenieDoGotowania;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\CookingSession;
use App\Models\Recipe;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** #2879: stan konta pod zamkiem, dwa procesy i obie kolejności rzeczywistego suspend(). */
#[Group('dwa-polaczenia')]
final class PostepWspolnegoGotowaniaPoZawieszeniuTest extends TestDwochPolaczen
{
    /** @var list<ProcesRownolegly> */
    private array $wlasneProcesy = [];

    protected function tearDown(): void
    {
        foreach ($this->wlasneProcesy as $proces) {
            $proces->zabij();
        }
        $this->wlasneProcesy = [];
        parent::tearDown();
    }

    /** @return array<string, array{string, string}> */
    public static function operacje(): array
    {
        return [
            'gospodarz-zrobiono' => ['gospodarz', 'zrobiono'],
            'gospodarz-cofnieto' => ['gospodarz', 'cofnieto'],
            'pomocnik-zrobiono' => ['pomocnik', 'zrobiono'],
            'pomocnik-cofnieto' => ['pomocnik', 'cofnieto'],
            'gospodarz-wyczysc' => ['gospodarz', 'wyczysc'],
        ];
    }

    #[DataProvider('operacje')]
    public function test_zawieszenie_zatwierdzone_po_policy_odmawia_bez_zmiany_postepu(string $rola, string $operacja): void
    {
        [$konto, $sesja, $krok] = $this->przygotuj($rola, $operacja);
        $przed = $this->stanPostepu($sesja);
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2879, 1)', []);
        $a = $this->postep($konto, $sesja, $krok, $operacja, 'przed');
        $this->czekajNaBariere($a, $bariera);
        $b = $this->wlasnyProces('zawies', ['konto' => (string) $konto->getKey()]);
        $zawieszenie = $b->wynik();
        $this->assertZawieszono($zawieszenie, $konto);
        $this->assertTrue($a->trwa());
        $this->assertSame($przed, $this->stanPostepu($sesja));
        $this->zwolnijBariere($bariera);

        $wynik = $a->wynik();
        $this->assertBezZakleszczenia($wynik, '#2879 postęp po zawieszeniu');
        $this->assertSame(User::STATUS_ACTIVE, $wynik['wartosc']['status_wejsciowy']);
        $this->assertSame(1, $wynik['wartosc']['transakcja']);
        $this->assertFalse($wynik['ok'], 'WSPOLNE_2879_SWIEZE_KONTO_ODMOWA: stare aktywne konto zapisało postęp po zatwierdzonym suspend().');
        $this->assertSame(BladDlaCzlowieka::class, $wynik['wyjatek']);
        $this->assertNull($wynik['sqlstate']);
        $this->assertSame('Konto jest zawieszone, więc możesz tylko czytać.', $wynik['komunikat']);
        $this->assertSame($przed, $this->stanPostepu($sesja), 'WSPOLNE_2879_SWIEZE_KONTO_ODMOWA: odmowa zmieniła kroki lub rewizję.');
    }

    #[DataProvider('operacje')]
    public function test_postep_pod_zamkiem_konta_konczy_sie_przed_publicznym_zawieszeniem(string $rola, string $operacja): void
    {
        [$konto, $sesja, $krok] = $this->przygotuj($rola, $operacja);
        $przed = $this->stanPostepu($sesja);
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2879, 1)', []);
        $a = $this->postep($konto, $sesja, $krok, $operacja, 'po');
        $this->czekajNaBariere($a, $bariera);
        $b = $this->wlasnyProces('zawies', ['konto' => (string) $konto->getKey()]);
        $this->assertZawieszenieCzekaNaPostep($b);
        $this->assertSame(User::STATUS_ACTIVE, $konto->fresh()?->status);
        $this->assertSame($przed, $this->stanPostepu($sesja));
        $this->zwolnijBariere($bariera);

        $wynik = $a->wynik();
        $this->assertBezZakleszczenia($wynik, '#2879 pierwszy postęp');
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertTrue($wynik['wartosc']['zmieniono']);
        $this->assertSame(User::STATUS_ACTIVE, $wynik['wartosc']['status_wejsciowy']);
        $this->assertSame(1, $wynik['wartosc']['transakcja']);
        $this->assertZawieszono($b->wynik(), $konto);
        $po = $this->stanPostepu($sesja);
        $this->assertSame($przed['rewizja'] + 1, $po['rewizja']);
        $this->assertCount($operacja === 'zrobiono' ? 2 : ($operacja === 'cofnieto' ? 1 : 0), $po['kroki']);
        $this->assertSame($operacja === 'zrobiono' ? 1 : 0, DB::table('cooking_session_steps')->where('session_id', $sesja->getKey())->where('step_id', $krok)->count());
    }

    /** @return array{0: User, 1: CookingSession, 2: string} */
    private function przygotuj(string $rola, string $operacja): array
    {
        $gospodarz = $this->konto();
        $pomocnik = $this->konto();
        $przepis = Recipe::factory()->create(['author_id' => $gospodarz->getKey(), 'visibility' => 'public']);
        $kroki = [];
        foreach ([0, 1] as $i) {
            $kroki[] = (string) RecipeStep::create([
                'recipe_id' => $przepis->getKey(), 'position' => $i, 'instruction' => 'Krok '.($i + 1).'.',
            ])->getKey();
        }
        $sesja = app(SesjaWspolnegoGotowania::class)->zaloz($gospodarz, $przepis);
        [, $token] = app(ZaproszenieDoGotowania::class)->utworz($gospodarz, $sesja);
        app(ZaproszenieDoGotowania::class)->dolacz($pomocnik, $token);
        app(PostepWspolnegoGotowania::class)->ustaw($gospodarz, $sesja, $kroki[1], true);
        if ($operacja !== 'zrobiono') {
            app(PostepWspolnegoGotowania::class)->ustaw($gospodarz, $sesja, $kroki[0], true);
        }

        return [$rola === 'gospodarz' ? $gospodarz : $pomocnik, $sesja->refresh(), $kroki[0]];
    }

    /** @return array{rewizja: int, kroki: list<array<string, mixed>>} */
    private function stanPostepu(CookingSession $sesja): array
    {
        return [
            'rewizja' => (int) DB::table('cooking_sessions')->where('id', $sesja->getKey())->value('revision'),
            'kroki' => DB::table('cooking_session_steps')->where('session_id', $sesja->getKey())
                ->orderBy('step_id')->get()->map(fn (object $wiersz): array => (array) $wiersz)->all(),
        ];
    }

    /** @param array{ok: bool, sqlstate: ?string, komunikat: string, wartosc: mixed, wyjatek: ?string} $wynik */
    private function assertZawieszono(array $wynik, User $konto): void
    {
        $this->assertBezZakleszczenia($wynik, '#2879 rzeczywiste zawieszenie');
        $this->assertTrue($wynik['ok'], $wynik['komunikat']);
        $this->assertNotEmpty($wynik['wartosc']['zamki_konta']);
        $this->assertSame(User::STATUS_SUSPENDED, $wynik['wartosc']['status_po']);
        $this->assertSame(User::STATUS_SUSPENDED, $konto->fresh()?->status);
    }

    private function assertZawieszenieCzekaNaPostep(ProcesRownolegly $b): void
    {
        $zapytanie = $this->obserwator->prepare("SELECT count(*) FROM pg_stat_activity a JOIN pg_stat_activity b ON a.pid = ANY(pg_blocking_pids(b.pid)) WHERE a.datname = ? AND a.application_name = 'postep2879-postep' AND b.datname = a.datname AND b.application_name = 'postep2879-zawies' AND b.wait_event_type = 'Lock' AND b.query ILIKE '%from \"users\"%for update%'");
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;
        do {
            $zapytanie->execute([$this->baza]);
            if ((int) $zapytanie->fetchColumn() === 1) {
                $this->assertTrue($b->trwa(), 'WSPOLNE_2879_SERIALIZACJA_KONTA');

                return;
            }
            if (! $b->trwa()) {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $koniec);
        $this->fail('WSPOLNE_2879_SERIALIZACJA_KONTA: publiczne suspend() nie czeka na PID postępu przy FOR UPDATE konta.');
    }

    private function czekajNaBariere(ProcesRownolegly $a, PDO $bariera): void
    {
        $pidBariery = (int) $this->odczytaj($bariera, 'SELECT pg_backend_pid()');
        $zapytanie = $this->obserwator->prepare("SELECT count(*) FROM pg_stat_activity WHERE datname = ? AND application_name = 'postep2879-postep' AND wait_event_type = 'Lock' AND wait_event = 'advisory' AND ? = ANY(pg_blocking_pids(pid))");
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;
        do {
            $zapytanie->execute([$this->baza, $pidBariery]);
            if ((int) $zapytanie->fetchColumn() === 1) {
                return;
            }
            if (! $a->trwa()) {
                $wynik = $a->wynik();
                $this->fail('Postęp nie dotarł do bariery #2879: '.$wynik['komunikat']);
            }
            usleep(20_000);
        } while (microtime(true) < $koniec);
        $this->fail('Postęp nie ustawił się na rzeczywistej barierze #2879.');
    }

    private function postep(User $konto, CookingSession $sesja, string $krok, string $operacja, string $bariera): ProcesRownolegly
    {
        return $this->wlasnyProces('postep', [
            'konto' => (string) $konto->getKey(), 'sesja' => (string) $sesja->getKey(), 'krok' => $krok,
            'operacja' => $operacja, 'bariera' => $bariera,
        ]);
    }

    /** @param array<string, string> $argumenty */
    private function wlasnyProces(string $etap, array $argumenty): ProcesRownolegly
    {
        $proces = ProcesRownolegly::start(__DIR__.'/bin/postep2879.php', $etap, $argumenty, [
            'APP_BASE_PATH' => dirname(__DIR__, 2), 'APP_ENV' => 'testing', 'DB_DATABASE' => $this->baza,
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'BCRYPT_ROUNDS' => '4',
        ]);
        $this->wlasneProcesy[] = $proces;

        return $proces;
    }
}

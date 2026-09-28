<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Exceptions\BladDlaCzlowieka;
use App\Models\Block;
use App\Models\CookedEvent;
use App\Models\Notification;
use App\Models\Recipe;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * „Ugotowałem” kontra równoległa utrata dostępu — na dwóch połączeniach
 * (issue #2017).
 *
 * `tests/Feature/UgotowalemPoUtracieDostepuTest.php` pokazuje, że akcja
 * sprawdza dostęp na świeżym stanie. Tu mierzymy to, czego jedno połączenie
 * nie pokaże: że zmiana, która jest W TOKU (ma już swoje blokady, ale się
 * jeszcze nie zatwierdziła), i zapis wykonania ustawiają się w kolejce
 * i mają JEDNO rozstrzygnięcie — bez zakleszczenia.
 *
 *  - ZMIANA PIERWSZA: akcja zmiany stoi na barierze wewnątrz swojej
 *    transakcji; „Ugotowałem” przechodzi Policy (widzi stan zatwierdzony),
 *    po czym MUSI czekać na blokadę trzymaną przez zmianę. Po zatwierdzeniu
 *    zmiany odmawia tym samym zdaniem co przy braku dostępu — bez wykonania
 *    i bez powiadomienia.
 *  - WYKONANIE PIERWSZE: „Ugotowałem” stoi na barierze zaraz po blokadzie
 *    przepisu; zmiana MUSI na nie czekać. Wykonanie i powiadomienie
 *    zapisują się, a zmiana wchodzi po nich — bez 40P01 i bez 55P03.
 *
 * Przed naprawą oba kierunki oblewały się: w pierwszym wykonanie
 * zapisywało się po zmianie albo w ogóle na nią nie czekało, w drugim
 * „Ugotowałem” nie brało żadnej blokady, więc bariera nigdy nie stanęła.
 */
#[Group('dwa-polaczenia')]
final class UgotowalemKontraUtrataDostepuNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    private const ODMOWA = 'Nie można dodać wykonania do tego przepisu.';

    /** @var list<ProcesRownolegly> */
    private array $workers = [];

    /** @param array<string, mixed> $atrybuty */
    protected function konto(array $atrybuty = []): User
    {
        // Baza wyścigów zachowuje fixture poprzednich przebiegów (moderatorzy).
        return parent::konto(array_merge(['email' => 'race-'.bin2hex(random_bytes(12)).'@example.invalid'], $atrybuty));
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->zabij();
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        // Wersje z `PublishRecipe` mają RESTRICT na redaktorze — ta sama
        // kolejność sprzątania co w `KomentarzBiezacyStanTest`.
        DB::table('recipe_versions')->whereIn('editor_id', $this->konta)->delete();
        parent::tearDown();
    }

    /**
     * Zmiana => [scenariusz, zapytanie, po którym zmiana staje na barierze].
     *
     * @return iterable<string, array{string, string}>
     */
    public static function zmiany(): iterable
    {
        foreach (['private', 'hide', 'block', 'ban'] as $zmiana) {
            yield $zmiana.'/zmiana-pierwsza' => [$zmiana, 'zmiana'];
            yield $zmiana.'/wykonanie-pierwsze' => [$zmiana, 'wykonanie'];
        }
    }

    #[DataProvider('zmiany')]
    public function test_wykonanie_i_utrata_dostepu_maja_jedno_rozstrzygniecie(string $zmiana, string $pierwsze): void
    {
        $autor = $this->konto();
        $kucharz = $this->konto();
        $przepis = Recipe::factory()->for($autor, 'author')->create([
            'status' => Recipe::STATUS_PUBLISHED, 'visibility' => 'public', 'published_at' => now()->subDay(),
        ]);

        $nazwa = 'cook-race-'.bin2hex(random_bytes(5));
        $gotowanie = ['name' => $nazwa.'-cook', 'actor' => $kucharz->id, 'recipe' => $przepis->id];
        $zmianaArgs = ['name' => $nazwa.'-change', 'recipe' => $przepis->id] + $this->argumentyZmiany($zmiana, $autor, $kucharz, $przepis);

        if ($pierwsze === 'zmiana') {
            $zmianaArgs['after_sql'] = $this->zapytanieZmiany($zmiana);
            $bariera = $this->bariera('SELECT pg_advisory_xact_lock(9157, hashtext(?))', [$zmianaArgs['name']]);

            $zmieniajacy = $this->worker($zmiana, $zmianaArgs);
            $pidZmiany = $this->czekajNaKolejke($zmianaArgs['name'], 'pg_advisory', $bariera);

            $gotujacy = $this->worker('cook', $gotowanie);
            // Wykonanie przeszło Policy (stan zatwierdzony nie zmienił się)
            // i czeka na blokadę trzymaną przez zmianę — nie na barierę.
            $this->czekajNaKolejke($gotowanie['name'], null, null, $pidZmiany);

            $this->zwolnijBariere($bariera);
            $zmienione = $zmieniajacy->wynik(12);
            $ugotowane = $gotujacy->wynik(12);

            $this->assertTrue($zmienione['ok'], $zmienione['komunikat']);
            $this->assertFalse($ugotowane['ok'], 'Wykonanie zapisało się po zatwierdzonej utracie dostępu ('.$zmiana.').');
            $this->assertSame(BladDlaCzlowieka::class, $ugotowane['wyjatek'], $ugotowane['komunikat']);
            $this->assertSame(self::ODMOWA, $ugotowane['komunikat']);
            $this->assertSame(0, CookedEvent::query()->where('user_id', $kucharz->id)->count());
            $this->assertSame(0, Notification::query()->where('actor_id', $kucharz->id)->where('type', Notification::TYPE_COOKED)->count());
        } else {
            $gotowanie['after_sql'] = ['from "recipes"', 'for share'];
            $bariera = $this->bariera('SELECT pg_advisory_xact_lock(9157, hashtext(?))', [$gotowanie['name']]);

            $gotujacy = $this->worker('cook', $gotowanie);
            $pidGotowania = $this->czekajNaKolejke($gotowanie['name'], 'pg_advisory', $bariera);

            $zmieniajacy = $this->worker($zmiana, $zmianaArgs);
            $this->czekajNaKolejke($zmianaArgs['name'], null, null, $pidGotowania);

            $this->zwolnijBariere($bariera);
            $ugotowane = $gotujacy->wynik(12);
            $zmienione = $zmieniajacy->wynik(12);

            $this->assertTrue($ugotowane['ok'], $ugotowane['komunikat']);
            $this->assertTrue($zmienione['ok'], $zmienione['komunikat']);
            $this->assertSame(1, CookedEvent::query()->whereKey($ugotowane['wartosc'])->count());
            $this->assertSame(1, Notification::query()
                ->where('user_id', $autor->id)->where('type', Notification::TYPE_COOKED)
                ->where('data->cooked_event_id', $ugotowane['wartosc'])->count());
        }

        foreach ([$zmienione, $ugotowane] as $wynik) {
            $this->assertNotSame('40P01', $wynik['sqlstate'], 'Zakleszczenie: '.$wynik['komunikat']);
            $this->assertNotSame('55P03', $wynik['sqlstate'], 'Przekroczony lock_timeout: '.$wynik['komunikat']);
        }

        // Kontrola dodatnia: zmiana naprawdę się wykonała w obu kierunkach.
        match ($zmiana) {
            'private' => $this->assertSame('private', $przepis->fresh()->visibility),
            'hide' => $this->assertSame(Recipe::STATUS_HIDDEN, $przepis->fresh()->status),
            'block' => $this->assertTrue(Block::query()->where('blocker_id', $autor->id)->where('blocked_id', $kucharz->id)->exists()),
            'ban' => $this->assertSame(User::STATUS_BANNED, $autor->fresh()->status),
        };
    }

    /** @return array<string, string> */
    private function argumentyZmiany(string $zmiana, User $autor, User $kucharz, Recipe $przepis): array
    {
        if ($zmiana !== 'hide') {
            return ['actor' => $zmiana === 'block' || $zmiana === 'private' ? $autor->id : $kucharz->id, 'other' => $zmiana === 'ban' ? $autor->id : $kucharz->id];
        }

        // Moderator zostaje jako fixture: log moderacji ma RESTRICT.
        $moderator = User::factory()->create(['email' => 'moderator-'.bin2hex(random_bytes(12)).'@example.invalid']);
        $moderator->forceFill(['role' => 'moderator'])->save();
        $zglaszajacy = $this->konto();
        $zgloszenie = Report::create([
            'reporter_id' => $zglaszajacy->id, 'source' => Report::SOURCE_COMMUNITY,
            'target_type' => 'recipe', 'target_id' => $przepis->id, 'reason' => 'spam', 'status' => 'open',
        ]);

        return ['actor' => $moderator->id, 'report' => $zgloszenie->id];
    }

    /** @return list<string> */
    private function zapytanieZmiany(string $zmiana): array
    {
        return match ($zmiana) {
            'private', 'hide' => ['update "recipes"'],
            'block' => ['insert into "blocks"'],
            'ban' => ['update "users"', '"status"'],
        };
    }

    /** @param array<string, mixed> $args */
    private function worker(string $scenariusz, array $args): ProcesRownolegly
    {
        $worker = ProcesRownolegly::start(__DIR__.'/bin/ugotowalem.php', $scenariusz, $args, [
            'DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
        $this->workers[] = $worker;

        return $worker;
    }

    /**
     * Czeka, aż proces o tej nazwie stoi w kolejce po blokadę — na barierze
     * (`$bariera`) albo za konkretnym innym uczestnikiem (`$zaPidem`).
     * Zwraca PID czekającego.
     */
    private function czekajNaKolejke(string $nazwa, ?string $sql, ?PDO $bariera, ?int $zaPidem = null): int
    {
        $wlasciciel = $bariera !== null ? (int) $bariera->query('SELECT pg_backend_pid()')->fetchColumn() : $zaPidem;
        $zapytanie = $this->obserwator->prepare("SELECT pid, query, pg_blocking_pids(pid)::text AS blokujacy FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'");
        $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;

        do {
            $zapytanie->execute([$nazwa]);
            $wiersz = $zapytanie->fetch(PDO::FETCH_ASSOC);

            if ($wiersz && ($sql === null || str_contains(strtolower($wiersz['query']), $sql))) {
                if ($wlasciciel !== null) {
                    $blokujacy = array_map('intval', array_filter(explode(',', trim((string) $wiersz['blokujacy'], '{}'))));
                    $this->assertContains($wlasciciel, $blokujacy, $nazwa.' czeka, ale nie na tego, na kogo powinien.');
                }

                return (int) $wiersz['pid'];
            }

            usleep(10_000);
        } while (microtime(true) < $koniec);

        $this->fail('Nie potwierdzono kolejki procesu '.$nazwa.' — przeplot się nie ustawił.');
    }
}

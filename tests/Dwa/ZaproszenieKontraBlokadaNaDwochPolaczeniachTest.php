<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Collections\Wspoldzielenie\ZaprosDoZeszytu;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2077: zaproszenie do zeszytu po nazwie kontra blokada (obie strony).
 *
 * Przeplot: zaproszenie przechodzi kontrolę blokady i zamek zeszytu, staje
 * przed wstawieniem wiersza, a w tym czasie druga osoba zakłada blokadę.
 * Przed poprawką blokada kończyła się bez zaproszenia do odwołania i
 * zaproszenie `pending` powstawało po niej. Po poprawce oba idą przez
 * `ZamekPary`: blokada czeka na zaproszenie i je odwołuje.
 *
 * Kontrola ujemna (wykonana): `poNazwie()` bez `ZamekPary` (sama
 * `DB::transaction` z kontrolą przed nią) oblewa oba warianty:
 * „Po zatwierdzeniu blokady zostało 1 aktywne zaproszenie".
 */
#[Group('dwa-polaczenia')]
final class ZaproszenieKontraBlokadaNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @var list<ProcesRownolegly> */
    private array $workers = [];

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->zabij();
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::table('collection_invitations')->whereIn('inviter_id', $this->konta)->delete();
        DB::table('collections')->whereIn('owner_id', $this->konta)->delete();
        parent::tearDown();
    }

    /** @return iterable<string, array{bool}> */
    public static function kierunki(): iterable
    {
        yield 'adresat blokuje zapraszającego' => [true];
        yield 'zapraszający blokuje adresata' => [false];
    }

    #[DataProvider('kierunki')]
    public function test_blokada_w_trakcie_zaproszenia_nie_zostawia_aktywnego_zaproszenia(bool $blokujeAdresat): void
    {
        $wlasciciel = $this->konto();
        $adresat = $this->konto();
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
        $nazwa = 'zapros-'.bin2hex(random_bytes(5));

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(9157, hashtext(?))', [$nazwa]);
        $zapraszanie = $this->worker('zapros', [
            'name' => $nazwa,
            'actor' => (string) $wlasciciel->getKey(),
            'zeszyt' => (string) $zeszyt->getKey(),
            'nazwa' => (string) $adresat->profile->username,
            // Po zamku zeszytu — czyli po kontroli blokady, przed INSERT.
            'after_sql' => ['from "collections"', 'for update'],
        ]);
        $this->czekajNaZablokowane(1);

        $blokada = $this->worker('block', [
            'name' => $nazwa.'-blok',
            'actor' => (string) ($blokujeAdresat ? $adresat : $wlasciciel)->getKey(),
            'other' => (string) ($blokujeAdresat ? $wlasciciel : $adresat)->getKey(),
        ]);

        // Blokada albo już zatwierdzona (stary kod: nic jej nie wstrzymuje),
        // albo stoi w kolejce za zaproszeniem (nowy kod: zamek pary).
        $this->czekajNaBlokade($wlasciciel->getKey(), $adresat->getKey(), $nazwa.'-blok');
        $this->zwolnijBariere($bariera);

        $wynikZaproszenia = $zapraszanie->wynik(15);
        $wynikBlokady = $blokada->wynik(15);

        $this->assertBezZakleszczenia($wynikZaproszenia, 'zaproszenie');
        $this->assertBezZakleszczenia($wynikBlokady, 'blokada');
        $this->assertTrue($wynikBlokady['ok'], 'Blokada musi się udać zawsze: '.$wynikBlokady['komunikat']);
        $this->assertTrue(
            $wynikZaproszenia['ok'] || str_contains($wynikZaproszenia['komunikat'], ZaprosDoZeszytu::NIE_DA_SIE),
            'Zaproszenie padło z innego powodu niż blokada: '.$wynikZaproszenia['wyjatek'].' '.$wynikZaproszenia['komunikat'],
        );

        $this->assertSame(
            1,
            DB::table('blocks')->whereIn('blocker_id', [$wlasciciel->getKey(), $adresat->getKey()])->whereIn('blocked_id', [$wlasciciel->getKey(), $adresat->getKey()])->count(),
        );
        $this->assertSame(
            0,
            DB::table('collection_invitations')
                ->where('collection_id', $zeszyt->getKey())
                ->where('status', CollectionInvitation::STATUS_PENDING)
                ->count(),
            'Po zatwierdzeniu blokady zostało aktywne zaproszenie między zablokowanymi kontami.',
        );
        // Kontrola dodatnia: przeplot zaproszenie-pierwsze faktycznie utworzyło wiersz (odwołany).
        $this->assertSame(
            $wynikZaproszenia['ok'] ? 1 : 0,
            DB::table('collection_invitations')->where('collection_id', $zeszyt->getKey())->count(),
        );
    }

    /** Czeka, aż blokada jest zatwierdzona albo stoi w kolejce po zamek. */
    private function czekajNaBlokade(string $a, string $b, string $nazwaBlokady): void
    {
        $stoi = $this->obserwator->prepare("SELECT 1 FROM pg_stat_activity WHERE application_name = ? AND wait_event_type = 'Lock'");
        $zatwierdzona = $this->obserwator->prepare('SELECT 1 FROM blocks WHERE blocker_id IN (?, ?) AND blocked_id IN (?, ?)');
        $koniec = microtime(true) + 8;

        while (microtime(true) < $koniec) {
            $stoi->execute([$nazwaBlokady]);
            $zatwierdzona->execute([$a, $b, $a, $b]);

            if ($stoi->fetchColumn() !== false || $zatwierdzona->fetchColumn() !== false) {
                return;
            }
            usleep(20_000);
        }

        $this->fail('Blokada ani nie zatwierdziła się, ani nie stanęła w kolejce — przeplot się nie ustawił.');
    }

    /** @param array<string, mixed> $args */
    private function worker(string $scenario, array $args): ProcesRownolegly
    {
        $worker = ProcesRownolegly::start(__DIR__.'/bin/zaproszenie-blokada.php', $scenario, $args, [
            'DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
        $this->workers[] = $worker;

        return $worker;
    }
}

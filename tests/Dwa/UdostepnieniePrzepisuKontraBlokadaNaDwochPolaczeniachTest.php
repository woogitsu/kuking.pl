<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Recipes\Udostepnienia\UdostepnijPrzepis;
use App\Models\Recipe;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * #2650: udostępnienie przepisu po nazwie konta kontra blokada (obie strony).
 *
 * Przeplot: udostępnienie przechodzi kontrolę blokady pod `ZamekPary`
 * i blokadę doradczą na przepis, staje przed wstawieniem wiersza, a w tym
 * czasie druga osoba zakłada blokadę. Oba idą przez `ZamekPary`, więc
 * blokada czeka na udostępnienie i je kasuje
 * (`ZerwijUdostepnieniaPrzepisow::miedzy()`). Bez tego zostałby wiersz
 * dostępu między kontami, które się zablokowały — a odblokowanie po
 * cichu przywróciłoby odbiorcy przepis.
 *
 * Drugi wyścig z issue — cofnięcie dostępu kontra otwarcie strony — nie ma
 * tu osobnego testu i to świadomie: odczyt nie bierze żadnej blokady, a
 * `DELETE` jest jednym poleceniem. Otwarcie przed zatwierdzeniem `DELETE`
 * widzi wiersz (dostęp był), po nim — nie widzi. Trzeciego wyniku nie ma,
 * więc nie ma przeplotu do wymuszenia barierą; „od następnego żądania"
 * pilnuje `UdostepnieniePrzepisuTest::test_odebranie_dostepu_dziala_od_nastepnego_zadania`.
 *
 * Kontrola ujemna (wykonana): `BlockUser` bez wywołania
 * `KoniecUdostepnienPrzepisow::miedzy()` oblewa oba warianty:
 * „Po zatwierdzeniu blokady zostało udostępnienie między zablokowanymi kontami".
 */
#[Group('dwa-polaczenia')]
final class UdostepnieniePrzepisuKontraBlokadaNaDwochPolaczeniachTest extends TestDwochPolaczen
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
        DB::table('recipe_shares')->whereIn('recipient_id', $this->konta)->delete();
        DB::table('recipes')->whereIn('author_id', $this->konta)->delete();
        parent::tearDown();
    }

    /** @return iterable<string, array{bool}> */
    public static function kierunki(): iterable
    {
        yield 'odbiorca blokuje autorkę' => [true];
        yield 'autorka blokuje odbiorcę' => [false];
    }

    #[DataProvider('kierunki')]
    public function test_blokada_w_trakcie_udostepnienia_nie_zostawia_dostepu(bool $blokujeOdbiorca): void
    {
        $autorka = $this->konto();
        $odbiorca = $this->konto();
        $przepis = Recipe::factory()->create(['author_id' => $autorka->getKey(), 'visibility' => 'private']);
        $nazwa = 'udost-'.bin2hex(random_bytes(5));

        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(9158, hashtext(?))', [$nazwa]);
        $udostepnianie = $this->worker('udostepnij', [
            'name' => $nazwa,
            'actor' => (string) $autorka->getKey(),
            'przepis' => (string) $przepis->getKey(),
            'nazwa' => (string) $odbiorca->profile->username,
            // Po blokadzie doradczej na przepis — czyli po kontroli blokady
            // pod zamkiem pary, przed INSERT.
            'after_sql' => ['pg_advisory_xact_lock', 'hashtext'],
        ]);
        $this->czekajNaZablokowane(1);

        $blokada = $this->worker('block', [
            'name' => $nazwa.'-blok',
            'actor' => (string) ($blokujeOdbiorca ? $odbiorca : $autorka)->getKey(),
            'other' => (string) ($blokujeOdbiorca ? $autorka : $odbiorca)->getKey(),
        ]);

        $this->czekajNaBlokade($autorka->getKey(), $odbiorca->getKey(), $nazwa.'-blok');
        $this->zwolnijBariere($bariera);

        $wynikUdostepnienia = $udostepnianie->wynik(15);
        $wynikBlokady = $blokada->wynik(15);

        $this->assertBezZakleszczenia($wynikUdostepnienia, 'udostępnienie');
        $this->assertBezZakleszczenia($wynikBlokady, 'blokada');
        $this->assertTrue($wynikBlokady['ok'], 'Blokada musi się udać zawsze: '.$wynikBlokady['komunikat']);
        $this->assertTrue(
            $wynikUdostepnienia['ok'] || str_contains($wynikUdostepnienia['komunikat'], UdostepnijPrzepis::NIE_DA_SIE),
            'Udostępnienie padło z innego powodu niż blokada: '.$wynikUdostepnienia['wyjatek'].' '.$wynikUdostepnienia['komunikat'],
        );

        $this->assertSame(
            1,
            DB::table('blocks')->whereIn('blocker_id', [$autorka->getKey(), $odbiorca->getKey()])->whereIn('blocked_id', [$autorka->getKey(), $odbiorca->getKey()])->count(),
        );
        $this->assertSame(
            0,
            DB::table('recipe_shares')->where('recipe_id', $przepis->getKey())->count(),
            'Po zatwierdzeniu blokady zostało udostępnienie między zablokowanymi kontami.',
        );
        // Kontrola dodatnia: przeplot naprawdę się ustawił — udostępnienie
        // doszło do wstawienia wiersza (zwróciło jego identyfikator).
        $this->assertTrue($wynikUdostepnienia['ok'], 'Udostępnienie nie doszło do zapisu — przyrząd nie zmierzył wyścigu.');
        $this->assertIsString($wynikUdostepnienia['wartosc']);
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
        $worker = ProcesRownolegly::start(__DIR__.'/bin/udostepnienie-blokada.php', $scenario, $args, [
            'DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
        $this->workers[] = $worker;

        return $worker;
    }
}

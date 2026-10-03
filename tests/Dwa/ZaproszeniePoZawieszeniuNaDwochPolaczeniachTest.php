<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Collections\Wspoldzielenie\ZaprosDoZeszytu;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** #2835: prawdziwe transakcje na osobnych połączeniach, obie kolejności. */
#[Group('dwa-polaczenia')]
final class ZaproszeniePoZawieszeniuNaDwochPolaczeniachTest extends TestDwochPolaczen
{
    /** @var list<ProcesRownolegly> */
    private array $workers = [];

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->zabij();
        }
        DB::table('collection_invitations')->whereIn('inviter_id', $this->konta)->delete();
        DB::table('collections')->whereIn('owner_id', $this->konta)->delete();
        parent::tearDown();
    }

    /** @return iterable<string, array{string, bool}> */
    public static function drogi(): iterable
    {
        yield 'po nazwie, sankcja pierwsza' => ['nazwa', false];
        yield 'linkiem, sankcja pierwsza' => ['link', false];
        yield 'po nazwie, zaproszenie pierwsze' => ['nazwa', true];
        yield 'linkiem, zaproszenie pierwsze' => ['link', true];
    }

    #[DataProvider('drogi')]
    public function test_sankcja_i_zaproszenie_ukladaja_sie_w_jednej_kolejnosci(string $droga, bool $zaproszeniePierwsze): void
    {
        $wlasciciel = $this->konto();
        $adresat = $this->konto();
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
        $nazwa = 'zapros-zaw-'.bin2hex(random_bytes(4));
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(92835, hashtext(?))', [$nazwa]);
        $zapros = $this->worker($droga, [
            'name' => $nazwa, 'actor' => (string) $wlasciciel->getKey(),
            'zeszyt' => (string) $zeszyt->getKey(), 'nazwa' => (string) $adresat->profile->username,
            'moment' => $zaproszeniePierwsze ? 'po_zamku' : 'przed_zamkiem',
        ]);
        $this->czekajNaZablokowane(1);

        if ($zaproszeniePierwsze) {
            $sankcja = $this->worker('suspend', ['name' => $nazwa.'-sankcja', 'actor' => (string) $wlasciciel->getKey()]);
            $this->czekajNaZablokowane(2);
            $this->zwolnijBariere($bariera);
            $wynikZapros = $zapros->wynik(15);
            $wynikSankcji = $sankcja->wynik(15);
            $this->assertBezZakleszczenia($wynikSankcji, 'sankcja');
            $this->assertTrue($wynikSankcji['ok'], 'Sankcja nie powiodła się: '.$wynikSankcji['komunikat']);
            $this->assertTrue($wynikZapros['ok'], 'Zamknięcie transakcji zaproszenia nie powiodło się: '.$wynikZapros['komunikat']);
        } else {
            // A stoi po Policy, ale jeszcze nie ma blokady konta. B kończy
            // prawdziwą sankcję, dopiero potem A wznawia działanie.
            $wlasciciel->suspend();
            $this->zwolnijBariere($bariera);
            $wynikZapros = $zapros->wynik(15);
            $this->assertFalse($wynikZapros['ok'], 'ZAPROSZENIE_2835_SWIEZA_POLICY: zaproszenie po sankcji zostało zapisane.');
            $this->assertSame(ZaprosDoZeszytu::BRAK_PRAWA, $wynikZapros['komunikat'], 'ZAPROSZENIE_2835_SWIEZA_POLICY');
        }

        $this->assertBezZakleszczenia($wynikZapros, 'zaproszenie');
        $this->assertSame('suspended', DB::table('users')->where('id', $wlasciciel->getKey())->value('status'));
        $this->assertSame($zaproszeniePierwsze ? 1 : 0,
            DB::table('collection_invitations')->where('collection_id', $zeszyt->getKey())->where('status', CollectionInvitation::STATUS_PENDING)->count(),
            'ZAPROSZENIE_2835_SWIEZA_POLICY');
        $this->assertSame($zaproszeniePierwsze && $droga === 'nazwa' ? 1 : 0,
            DB::table('notifications')->where('user_id', $adresat->getKey())->where('type', Notification::TYPE_COLLECTION_INVITED)->count(),
            'ZAPROSZENIE_2835_SWIEZA_POLICY');
    }

    /** @param array<string, string> $args */
    private function worker(string $scenario, array $args): ProcesRownolegly
    {
        $worker = ProcesRownolegly::start(__DIR__.'/bin/zaproszenie-zawieszenie.php', $scenario, $args, [
            'DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
        $this->workers[] = $worker;

        return $worker;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Collections\Wspoldzielenie\OdpowiedzNaZaproszenie;
use App\Domain\Collections\Wspoldzielenie\ZaprosDoZeszytu;
use App\Models\Collection;
use App\Models\CollectionInvitation;
use App\Models\Notification;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/** #2838: oba porządki odpowiedzi na ten sam link, dwa backendy PostgreSQL. */
#[Group('dwa-polaczenia')]
final class OdpowiedzNaLinkNieZakleszczaSieTest extends TestDwochPolaczen
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

    /** @return iterable<string, array{bool}> */
    public static function kolejnosc(): iterable
    {
        yield 'odmowa pierwsza' => [true];
        yield 'przyjęcie pierwsze' => [false];
    }

    #[DataProvider('kolejnosc')]
    public function test_odpowiedzi_na_link_nie_zakleszczaja_sie(bool $odmowaPierwsza): void
    {
        $wlasciciel = $this->konto();
        $osoba = $this->konto();
        $zeszyt = Collection::create(['owner_id' => $wlasciciel->getKey(), 'name' => 'Obiady', 'visibility' => 'private']);
        [$zaproszenie] = app(ZaprosDoZeszytu::class)->linkiem($wlasciciel, $zeszyt);
        $this->assertNull($zaproszenie->invitee_id, 'Test wymaga linku z invitee_id = NULL.');

        $nazwa = 'odp-link-2838-'.bin2hex(random_bytes(4));
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(92838, hashtext(?))', [$nazwa]);
        $args = ['name' => $nazwa, 'kto' => (string) $osoba->getKey(), 'zaproszenie' => (string) $zaproszenie->getKey()];
        $pierwszy = $this->worker($odmowaPierwsza ? 'odrzuc' : 'przyjmij', $args);
        $this->czekajNaZablokowane(1);
        $drugi = $this->worker($odmowaPierwsza ? 'przyjmij' : 'odrzuc', $args);
        $this->czekajNaZablokowane(2);
        $this->zwolnijBariere($bariera);

        $wynikPierwszego = $pierwszy->wynik(20);
        $wynikDrugiego = $drugi->wynik(20);
        $this->assertNotSame('40P01', $wynikPierwszego['sqlstate'], 'ZAPROSZENIE_2838_BEZ_40P01: pierwszy uczestnik padł ofiarą zakleszczenia.');
        $this->assertNotSame('40P01', $wynikDrugiego['sqlstate'], 'ZAPROSZENIE_2838_BEZ_40P01: drugi uczestnik padł ofiarą zakleszczenia.');
        $this->assertBezZakleszczenia($wynikPierwszego, 'pierwsza odpowiedź');
        $this->assertBezZakleszczenia($wynikDrugiego, 'druga odpowiedź');
        $this->assertTrue($wynikPierwszego['ok'], 'ZAPROSZENIE_2838_BEZ_40P01: '.$wynikPierwszego['komunikat']);
        $this->assertFalse($wynikDrugiego['ok'], 'Druga odpowiedź nie może zmienić wyniku pierwszej.');
        $this->assertSame(OdpowiedzNaZaproszenie::NIEAKTUALNE, $wynikDrugiego['komunikat']);

        $this->assertSame($odmowaPierwsza ? CollectionInvitation::STATUS_DECLINED : CollectionInvitation::STATUS_ACCEPTED,
            DB::table('collection_invitations')->where('id', $zaproszenie->getKey())->value('status'));
        $this->assertSame((string) $osoba->getKey(),
            DB::table('collection_invitations')->where('id', $zaproszenie->getKey())->value('invitee_id'));
        $this->assertSame($odmowaPierwsza ? 0 : 1,
            DB::table('collection_members')->where('collection_id', $zeszyt->getKey())->count());
        $this->assertSame($odmowaPierwsza ? 0 : 1,
            DB::table('notifications')->where('user_id', $wlasciciel->getKey())
                ->where('type', Notification::TYPE_COLLECTION_JOINED)->count());
    }

    /** @param array<string, string> $args */
    private function worker(string $scenariusz, array $args): ProcesRownolegly
    {
        $worker = ProcesRownolegly::start(__DIR__.'/bin/odpowiedz-zaproszenie-2838.php', $scenariusz, $args, [
            'DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
        ]);
        $this->workers[] = $worker;

        return $worker;
    }
}

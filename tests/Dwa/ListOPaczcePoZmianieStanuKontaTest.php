<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Jobs\NotifyUserExportReady;
use App\Mail\DataExportReady;
use App\Mail\DataExportReadyInGracePeriod;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;

/**
 * ISSUE #2320 NA DWÓCH POŁĄCZENIACH: list „paczka gotowa” i „Usuń konto”.
 *
 * `NotifyUserExportReady` chodzi w procesie testu (połączenie A), a PRAWDZIWE
 * `markForDeletion()` — w osobnym procesie (`bin/scenariusz.php
 * stan-konta-980`, połączenie B). Dwa przeploty:
 *
 *  1. Usunięcie konta ZATWIERDZA SIĘ między pierwszym odczytem konta w jobie
 *     a zajęciem listu (nasłuch na pierwszy `SELECT … FROM "users"`, proces B
 *     kończy się, zanim job ruszy dalej). Job musi wybrać list z karencji,
 *     `DataExportReadyInGracePeriod`, a nie zwykły link do pobrania.
 *
 *  2. Usunięcie konta rusza, gdy job JUŻ trzyma blokadę konta (nasłuch na
 *     `SELECT … FROM "users" … FOR UPDATE`). Proces B staje w kolejce na
 *     wierszu konta (`czekajNaZablokowane`), job zajmuje list na stanie
 *     sprzed usunięcia, a B kończy się po nim — bez zakleszczenia.
 *
 * KONTROLA UJEMNA (wykonana): przywrócenie `handle()` sprzed poprawki
 * (zajęcie bez `ZamekKonta`, szablon z pierwszego odczytu) oblewa przypadek 1
 * na „Zwykły list z linkiem mimo usunięcia konta”, a przypadek 2 na
 * „Job nie wziął blokady konta — przeplot się nie ustawił”.
 */
#[Group('dwa-polaczenia')]
final class ListOPaczcePoZmianieStanuKontaTest extends TestDwochPolaczen
{
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_usuniecie_konta_miedzy_odczytem_a_zajeciem_daje_list_z_karencji(): void
    {
        $konto = $this->konto();
        $export = $this->gotowaPaczka($konto);

        $wynik = null;

        DB::listen(function (QueryExecuted $query) use (&$wynik, $konto): void {
            $sql = mb_strtolower($query->sql);

            if ($wynik !== null || ! str_contains($sql, 'from "users"') || str_contains($sql, 'for update')) {
                return;
            }

            // Pierwszy odczyt konta w jobie — stan „aktywne”. Usunięcie konta
            // zatwierdza się, zanim job zajmie list.
            $wynik = $this->wTle('stan-konta-980', ['konto' => (string) $konto->getKey(), 'przejscie' => 'usun'])->wynik();
        });

        (new NotifyUserExportReady((string) $export->getKey()))->handle();

        $this->assertIsArray($wynik, 'Job nie przeczytał konta — przeplot się nie ustawił.');
        $this->assertTrue($wynik['ok'], 'Usunięcie konta padło: '.$wynik['komunikat']);
        $this->assertSame(User::STATUS_PENDING_DELETE, $wynik['wartosc']);

        $this->assertCount(0, Mail::sent(DataExportReady::class), 'Zwykły list z linkiem mimo usunięcia konta.');
        Mail::assertSent(DataExportReadyInGracePeriod::class, 1);
        $this->assertNotNull($export->fresh()->notified_at);
    }

    public function test_usuniecie_konta_w_trakcie_zajecia_czeka_na_blokade_konta(): void
    {
        $konto = $this->konto();
        $export = $this->gotowaPaczka($konto);

        $proces = null;

        DB::listen(function (QueryExecuted $query) use (&$proces, $konto): void {
            $sql = mb_strtolower($query->sql);

            if ($proces !== null || ! str_contains($sql, 'from "users"') || ! str_contains($sql, 'for update')) {
                return;
            }

            $proces = $this->wTle('stan-konta-980', ['konto' => (string) $konto->getKey(), 'przejscie' => 'usun']);
            $this->czekajNaZablokowane(1);
        });

        (new NotifyUserExportReady((string) $export->getKey()))->handle();

        $this->assertNotNull($proces, 'Job nie wziął blokady konta — przeplot się nie ustawił.');

        $wynik = $proces->wynik();
        $this->assertBezZakleszczenia($wynik, 'usunięcie konta');
        $this->assertTrue($wynik['ok'], 'Usunięcie konta padło: '.$wynik['komunikat']);

        // Decyzja zapadła na stanie sprzed usunięcia — jeden list, zwykły.
        Mail::assertSent(DataExportReady::class, 1);
        Mail::assertNotSent(DataExportReadyInGracePeriod::class);
        $this->assertSame(User::STATUS_PENDING_DELETE, $konto->fresh()->status);
    }

    private function gotowaPaczka(User $konto): DataExport
    {
        return DataExport::create([
            'user_id' => $konto->getKey(),
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'eksporty/'.$konto->getKey().'/paczka.zip',
            'bytes' => 1024,
            'completed_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }
}

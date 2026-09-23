<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DyskEksportuZHakiem;

/**
 * ISSUE #1307 NA DWÓCH POŁĄCZENIACH: eksport i egzekucja wymazania konta.
 *
 * Job budujący paczkę chodzi w procesie testu (połączenie A), a PRAWDZIWE
 * `EraseAccountData` — w osobnym procesie (`bin/scenariusz.php kasowanie`,
 * połączenie B). Oba przeploty z kryteriów issue:
 *
 *  1. Wymazanie ZATWIERDZA SIĘ między `writeStream()` a finalizacją
 *     (hak dysku czeka na wynik procesu B). Finalizacja pod blokadą widzi
 *     wymazanie: paczka nie jest `ready`, pliku nie ma.
 *
 *  2. Wymazanie rusza, gdy finalizacja JUŻ trzyma blokadę i przeczytała
 *     stan eksportu (nasłuch na pierwszy `SELECT ... FROM data_exports`
 *     po zapisie paczki). Proces B staje w kolejce na wierszu konta
 *     (`czekajNaZablokowane`), więc jego unieważnienie trafia w wiersz już
 *     `ready` — i paczka przestaje być do pobrania, a nocne sprzątanie ją
 *     kasuje. Przed #1307 job czytał stan tylko na starcie; tu przeplot
 *     z B zatwierdzonym PRZED zapisem `ready` dawał `ready` z terminem
 *     w przyszłości.
 *
 * KONTROLA UJEMNA (wykonana): bez `lockForUpdate()` na `users`
 * i `data_exports` w `finalize()` proces B nie czeka na nikogo, wymazuje
 * konto, zanim job zapisze `ready`, a job nadpisuje unieważnienie —
 * przypadek 2 oblewa na „Paczka po wymazaniu konta jest nadal do pobrania".
 */
#[Group('dwa-polaczenia')]
final class EksportPoWymazaniuKontaTest extends TestDwochPolaczen
{
    private DyskEksportuZHakiem $dysk;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->dysk = DyskEksportuZHakiem::zarejestruj('eksporty-dwa');

        config([
            'kuking.exports.disk' => 'eksporty-dwa',
            'kuking.exports.ttl_days' => 7,
        ]);
    }

    public function test_wymazanie_zatwierdzone_po_zapisie_paczki_nie_pozwala_jej_oznaczyc_jako_gotowej(): void
    {
        $konto = $this->konto();
        $konto->markForDeletion();
        $export = DataExport::create(['user_id' => $konto->getKey(), 'status' => DataExport::STATUS_QUEUED]);

        $wynikWymazania = null;
        $klucz = null;

        $this->dysk->poZapisie = function (string $zapisany) use ($konto, &$wynikWymazania, &$klucz): void {
            $klucz = $zapisany;
            $wynikWymazania = $this->wTle('kasowanie', ['konto' => (string) $konto->getKey()])->wynik();
        };

        (new GenerateUserExport((string) $export->getKey()))->handle();

        // KONTROLA DODATNIA: proces B naprawdę wymazał konto.
        $this->assertIsArray($wynikWymazania, 'Hak po zapisie paczki nie został wywołany.');
        $this->assertTrue($wynikWymazania['ok'], 'Wymazanie padło: '.$wynikWymazania['komunikat']);
        $this->assertTrue($wynikWymazania['wartosc'] === true, 'Wymazanie niczego nie wymazało.');

        $export->refresh();

        $this->assertNotSame(DataExport::STATUS_READY, $export->status);
        $this->assertFalse($export->isDownloadable());
        $this->assertTrue($export->expires_at?->isPast() ?? false);
        $this->dysk->assertMissing((string) $klucz);
    }

    public function test_wymazanie_rownolegle_z_finalizacja_czeka_na_nia_i_uniewaznia_gotowa_paczke(): void
    {
        $konto = $this->konto();
        $konto->markForDeletion();
        $export = DataExport::create(['user_id' => $konto->getKey(), 'status' => DataExport::STATUS_QUEUED]);

        $proces = null;
        $poZapisie = false;

        $this->dysk->poZapisie = function () use (&$poZapisie): void {
            $poZapisie = true;
        };

        DB::listen(function (QueryExecuted $query) use (&$proces, &$poZapisie, $konto): void {
            if (! $poZapisie || $proces !== null) {
                return;
            }

            $sql = mb_strtolower($query->sql);

            // Pierwszy odczyt eksportu PO zapisie paczki to odczyt
            // w finalizacji — ostatnia chwila przed zapisem `ready`.
            if (! str_starts_with(trim($sql), 'select') || ! str_contains($sql, 'from "data_exports"')) {
                return;
            }

            $proces = $this->wTle('kasowanie', ['konto' => (string) $konto->getKey()]);

            if (str_contains($sql, 'for update')) {
                // Finalizacja trzyma blokady: B musi stanąć w kolejce.
                $this->czekajNaZablokowane(1);
            } else {
                // Kod bez blokady (kontrola ujemna): nic B nie zatrzyma,
                // więc dajemy mu skończyć — to najgorszy przeplot, ten
                // z issue #1307.
                $proces->wynik();
            }
        });

        (new GenerateUserExport((string) $export->getKey()))->handle();

        $this->assertNotNull($proces, 'Finalizacja nie przeczytała eksportu po zapisie paczki — przeplot się nie ustawił.');

        $wynik = $proces->wynik();

        $this->assertTrue($wynik['ok'], 'Wymazanie padło: '.$wynik['komunikat']);
        $this->assertTrue($wynik['wartosc'] === true, 'Wymazanie niczego nie wymazało.');

        $export->refresh();

        $this->assertFalse($export->isDownloadable(), 'Paczka po wymazaniu konta jest nadal do pobrania.');
        $this->assertTrue($export->expires_at?->isPast() ?? false);

        // Plik znika tą samą drogą co każda wygasła paczka.
        $klucz = (string) $export->object_key;
        Artisan::call('kuking:sprzataj-eksporty');
        $this->dysk->assertMissing($klucz);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Dwa;

use App\Domain\Users\Exports\ExportFileNames;
use App\Models\DataExport;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use ZipArchive;

/** Nazwy pozycji i pustych list pochodzą z jednej migawki w rzeczywistym ZIP-ie (#2847). */
#[Group('dwa-polaczenia')]
final class EksportZakupowKontraZmianaNazwyTest extends TestDwochPolaczen
{
    private string $root;

    private string $dysk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dysk = 'eksport-2847-'.bin2hex(random_bytes(4));
        $this->root = storage_path('framework/testing/disks/'.$this->dysk);
        (new Filesystem)->ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_przemianowanie_miedzy_odczytami_nie_miesza_nazw_w_zip_i_pozniejszy_eksport_widzi_nowosc(): void
    {
        $user = $this->konto();
        $lista = new ShoppingList(['name' => 'Święta']);
        $lista->user_id = $user->getKey();
        $lista->save();
        $pusta = new ShoppingList(['name' => 'Pusta']);
        $pusta->user_id = $user->getKey();
        $pusta->save();
        foreach ([['karp', $lista, 1], ['mleko', null, 2]] as [$tekst, $cel, $pozycja]) {
            $wiersz = new ShoppingListItem(['text' => $tekst]);
            $wiersz->user_id = $user->getKey();
            $wiersz->source = ShoppingListItem::SOURCE_MANUAL;
            $wiersz->position = $pozycja;
            $wiersz->list_id = $cel?->getKey();
            $wiersz->save();
        }

        $export = DataExport::create(['user_id' => $user->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        $nazwa = 'eksport-2847-'.Str::random(12);
        $bariera = $this->bariera('SELECT pg_advisory_xact_lock(2847, hashtext(?))', [$nazwa]);
        $args = [
            'name' => $nazwa, 'user' => (string) $user->getKey(), 'list' => (string) $lista->getKey(),
            'new_name' => 'Wigilia', 'other_list' => (string) $pusta->getKey(), 'old_name' => 'Święta',
            'export' => (string) $export->getKey(),
            'disk' => $this->dysk, 'root' => $this->root,
        ];
        $env = ['DB_DATABASE' => $this->baza, 'APP_ENV' => 'testing', 'APP_BASE_PATH' => base_path(), 'MAIL_MAILER' => 'array'];
        $eksportowanie = ProcesRownolegly::start(__DIR__.'/bin/eksport-zakupy-2847.php', 'eksport', $args, $env);
        $przemianowanie = null;

        try {
            // Pierwszy odczyt nazw zatrzymany; drugie połączenie próbuje
            // prawdziwej akcji domenowej, a nie SQL-a odtworzonego w teście.
            $this->czekajNaZablokowane(1);
            $przemianowanie = ProcesRownolegly::start(__DIR__.'/bin/eksport-zakupy-2847.php', 'przemianuj', $args, $env);
            $koniec = microtime(true) + self::SEKUNDY_NA_KOLEJKE;
            while ($this->ilu() < 2 && ! $przemianowanie->zakonczony() && microtime(true) < $koniec) {
                usleep(20_000);
            }
            $this->assertTrue($this->ilu() >= 2 || $przemianowanie->zakonczony(), 'EKSPORT_2847_DRUGIE_POLACZENIE_NIE_DOSZLO_DO_ZMIANY');
            $this->zwolnijBariere($bariera);

            $wynikEksportu = $eksportowanie->wynik();
            $wynikZmiany = $przemianowanie->wynik();
            $this->assertTrue($wynikEksportu['ok'], $wynikEksportu['komunikat']);
            $this->assertSame('gotowy', $wynikEksportu['wartosc']);
            $this->assertTrue($wynikZmiany['ok'], $wynikZmiany['komunikat']);
            $this->assertSame('Wigilia', $wynikZmiany['wartosc']);

            $dane = $this->daneZZip($export);
            $this->assertSame(['Święta', 'Pusta'], array_column($dane['listy_zakupow'], 'nazwa'), 'EKSPORT_2847_NAZWY_Z_JEDNEJ_MIGAWKI');
            $this->assertSame(['karp', 'mleko'], array_column($dane['lista_zakupow'], 'pozycja'));
            $this->assertSame(['Święta', ShoppingList::NAZWA_DOMYSLNEJ], array_column($dane['lista_zakupow'], 'lista'), 'EKSPORT_2847_NAZWY_Z_JEDNEJ_MIGAWKI');
            $this->assertSame('Wigilia', $lista->fresh()?->name);
            $this->assertSame('Święta', $pusta->fresh()?->name, 'Druga lista może później zająć dawną nazwę bez przypisania jej pozycji karp.');

            // Druga paczka, zamówiona po zmianie, widzi już nową nazwę.
            $pozniejszy = DataExport::create(['user_id' => $user->getKey(), 'status' => DataExport::STATUS_QUEUED]);
            $args['export'] = (string) $pozniejszy->getKey();
            $args['name'] = $nazwa.'-pozniej';
            $drugiEksport = ProcesRownolegly::start(__DIR__.'/bin/eksport-zakupy-2847.php', 'eksport', $args, $env);
            $wynikDrugi = $drugiEksport->wynik();
            $this->assertTrue($wynikDrugi['ok'], $wynikDrugi['komunikat']);
            $danePozniej = $this->daneZZip($pozniejszy);
            $this->assertSame(['Wigilia', 'Święta'], array_column($danePozniej['listy_zakupow'], 'nazwa'));
            $this->assertSame(['Wigilia', ShoppingList::NAZWA_DOMYSLNEJ], array_column($danePozniej['lista_zakupow'], 'lista'));
        } finally {
            if ($bariera->inTransaction()) {
                $this->zwolnijBariere($bariera);
            }
            $eksportowanie->zabij();
            if ($przemianowanie !== null) {
                $przemianowanie->zabij();
            }
            if (isset($drugiEksport)) {
                $drugiEksport->zabij();
            }
        }
    }

    /** @return array<string, mixed> */
    private function daneZZip(DataExport $export): array
    {
        $export->refresh();
        $this->assertSame(DataExport::STATUS_READY, $export->status);
        $sciezka = $this->root.'/'.ExportFileNames::objectKey($export);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($sciezka) === true, 'Nie ma gotowego archiwum ZIP.');
        $json = $zip->getFromName('dane.json');
        $zip->close();
        $this->assertIsString($json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
}

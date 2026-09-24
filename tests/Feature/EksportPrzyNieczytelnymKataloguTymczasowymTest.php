<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\ExportTempDirectory;
use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Katalog tymczasowy eksportu po #1436: osobny dla użytkownika systemu
 * i odporny na nieczytelne wpisy.
 *
 * Do 23 września 2026 wszystkie procesy dzieliły `<tmp>/kuking-eksport`.
 * Założony z prawami 0700 przez jednego użytkownika (runner CI) był
 * nieczytelny dla drugiego, a `sweepStale()` na starcie `handle()` rzucało
 * `UnexpectedValueException` z `FilesystemIterator` — eksport padał, zanim
 * cokolwiek zbudował (losowo `tests/Dwa/EksportPoWymazaniuKontaTest`).
 *
 * Nieczytelny katalog symuluje strumień `kuking-nieczytelny://`: `is_dir()`
 * mówi „katalog”, a otwarcie się nie udaje. Chmod 000 nie wystarczy, bo
 * testy lokalnie chodzą jako root.
 *
 * KONTROLA UJEMNA (wykonana): bez `try/catch` w `sweepStale()` test
 * o nieczytelnym katalogu głównym oblewa wyjątkiem `UnexpectedValueException`;
 * bez niego w `remove()` — test o `remove()`. Powrót do wspólnego
 * `sys_get_temp_dir().'/kuking-eksport'` oblewa test o domyślnym katalogu.
 */
class EksportPrzyNieczytelnymKataloguTymczasowymTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $doSprzatniecia = [];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');

        config([
            'kuking.exports.disk' => 'local',
            'kuking.exports.ttl_days' => 7,
        ]);

        if (! in_array(NieczytelnyKatalog::SCHEMAT, stream_get_wrappers(), true)) {
            stream_wrapper_register(NieczytelnyKatalog::SCHEMAT, NieczytelnyKatalog::class);
        }
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->doSprzatniecia) as $sciezka) {
            @chmod($sciezka, 0700);
            foreach (glob($sciezka.'/*') ?: [] as $plik) {
                is_dir($plik) ? @rmdir($plik) : @unlink($plik);
            }
            @rmdir($sciezka);
        }

        parent::tearDown();
    }

    public function test_domyslny_katalog_glowny_jest_osobny_dla_uzytkownika_i_nie_pasuje_do_starego_globu(): void
    {
        config(['kuking.exports.temp_dir' => null]);

        $root = ExportTempDirectory::root();

        $this->assertSame(sys_get_temp_dir().'/kuking-eksport.u'.posix_geteuid(), $root);
        $this->assertFalse(fnmatch(sys_get_temp_dir().'/kuking-eksport-*', $root), 'Glob starego układu złapałby nowy katalog główny.');
    }

    public function test_dwoch_uzytkownikow_nie_dzieli_katalogu_i_nie_sprzata_sobie_nawzajem(): void
    {
        $katalogA = $this->katalogGlowny();
        $katalogB = $this->katalogGlowny();
        mkdir($katalogB, 0700);
        $id = (string) Str::uuid();

        config(['kuking.exports.temp_dir' => $katalogA]);
        $plikA = ExportTempDirectory::newFile($id, 'json');
        file_put_contents($plikA, '{}');
        touch($plikA, time() - 2 * 3600);
        touch(dirname($plikA), time() - 2 * 3600);

        // KONTROLA DODATNIA: katalog powstał z prawami tylko dla właściciela.
        $this->assertSame(0700, fileperms(dirname($plikA)) & 0777);

        config(['kuking.exports.temp_dir' => $katalogB]);
        $this->assertNotSame(dirname($plikA), ExportTempDirectory::forExport($id));
        $this->assertSame(0, ExportTempDirectory::sweepStale());
        $this->assertFileExists($plikA, 'Sprzątanie jednego użytkownika zabrało pliki drugiego.');

        config(['kuking.exports.temp_dir' => $katalogA]);
        $this->assertSame(1, ExportTempDirectory::sweepStale());
        $this->assertFileDoesNotExist($plikA);
    }

    public function test_nieczytelny_katalog_glowny_nie_wywraca_sprzatania_i_loguje_raz_bez_sciezki(): void
    {
        config(['kuking.exports.temp_dir' => NieczytelnyKatalog::SCHEMAT.'://kuking-eksport']);
        $dziennik = Log::spy();

        $this->assertSame(0, ExportTempDirectory::sweepStale());

        $dziennik->shouldHaveReceived('warning')
            ->withArgs(fn (string $wiadomosc, array $kontekst): bool => ! str_contains($wiadomosc.json_encode($kontekst), 'kuking-eksport'))
            ->once();
    }

    public function test_nieczytelny_katalog_eksportu_nie_wywraca_remove(): void
    {
        config(['kuking.exports.temp_dir' => NieczytelnyKatalog::SCHEMAT.'://kuking-eksport']);
        $id = (string) Str::uuid();
        $dziennik = Log::spy();

        $this->assertFalse(ExportTempDirectory::remove($id));

        $dziennik->shouldHaveReceived('warning')
            ->withArgs(fn (string $wiadomosc, array $kontekst): bool => ($kontekst['data_export_id'] ?? null) === $id
                && ! str_contains($wiadomosc.json_encode($kontekst), 'kuking-eksport'))
            ->once();
    }

    /**
     * Cudzy, nieczytelny katalog osieroconego eksportu w katalogu głównym
     * nie przeszkadza eksportowi: paczka jest gotowa. (Jako root chmod nie
     * odcina odczytu — wtedy wpis po prostu zostaje sprzątnięty; wyjątek
     * pokrywają testy strumienia wyżej.)
     */
    public function test_eksport_konczy_sie_normalnie_mimo_nieczytelnego_wpisu(): void
    {
        $root = $this->katalogGlowny();
        mkdir($root, 0700);
        config(['kuking.exports.temp_dir' => $root]);

        $cudzy = $root.'/'.Str::uuid();
        mkdir($cudzy, 0700);
        touch($cudzy, time() - 2 * 3600);
        chmod($cudzy, 0000);
        $this->doSprzatniecia[] = $cudzy;

        $export = DataExport::create([
            'user_id' => $this->user('basianieczyt'.Str::random(6))->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        (new GenerateUserExport((string) $export->getKey()))->handle();

        $this->assertSame(DataExport::STATUS_READY, $export->refresh()->status);
        $this->assertDirectoryDoesNotExist(ExportTempDirectory::forExport((string) $export->getKey()));
    }

    private function katalogGlowny(): string
    {
        $katalog = sys_get_temp_dir().'/kuking-test-eksport-'.Str::uuid();
        $this->doSprzatniecia[] = $katalog;

        return $katalog;
    }
}

/**
 * Strumień, w którym każda ścieżka jest katalogiem, ale żadnej nie da się
 * otworzyć — jak katalog innego użytkownika z prawami 0700.
 */
final class NieczytelnyKatalog
{
    public const SCHEMAT = 'kuking-nieczytelny';

    /** @var resource|null */
    public $context;

    /** @return array<int|string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0040700, 'mtime' => 0];
    }

    public function dir_opendir(string $path, int $options): bool
    {
        return false;
    }
}

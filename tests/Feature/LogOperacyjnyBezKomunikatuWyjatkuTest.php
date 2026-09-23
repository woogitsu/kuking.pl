<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\KasujZdjecie;
use App\Logging\BezpiecznyBlad;
use App\Models\DataExport;
use App\Models\Media;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PDOException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\TestCase;

/**
 * Logi OPERACYJNE (sprzątanie, media, eksport, retencja, /health) nie niosą
 * komunikatu obcego wyjątku — #973, rozszerzenie #828.
 *
 * Komunikat buduje sterownik bazy, klient storage, dekoder albo klient HTTP.
 * Niesie SQL z wartościami, adres żądania z kluczem obiektu, token w adresie,
 * CR/LF. Stderr czyta Railway. Zamiast komunikatu idzie
 * `BezpiecznyBlad::kontekst()`: klasa, kod o zamkniętym kształcie, klasy
 * przyczyn, odcisk. Identyfikator encji dokłada każde wywołanie.
 */
final class LogOperacyjnyBezKomunikatuWyjatkuTest extends TestCase
{
    use RefreshDatabase;

    /** Wszystko, czego w logu być nie może — naraz, w jednym komunikacie. */
    private const ZLY_KOMUNIKAT = "Error executing DeleteObject on https://konto.r2.example/media/basia.jpg?X-Amz-Signature=deadbeef123&token=sekret-abc\r\n"
        .'[2026-09-23] production.CRITICAL: wstrzyknięty wpis basia@example.com';

    /** @return list<string> */
    private function zakazane(): array
    {
        return ['basia@example.com', 'X-Amz-Signature', 'deadbeef123', 'sekret-abc', 'r2.example', "\r", 'wstrzyknięty'];
    }

    private function assertBezZakazanych(array $kontekst): void
    {
        $caly = json_encode($kontekst, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach ($this->zakazane() as $fraza) {
            $this->assertStringNotContainsString($fraza, (string) $caly);
        }
    }

    public function test_kontekst_ma_klase_kod_przyczyny_i_odcisk_a_nie_komunikat(): void
    {
        $sterownik = new PDOException('SQLSTATE[23505]: Unique violation: Key (email)=(basia@example.com) already exists.');
        $sterownik->errorInfo = ['23505', 7, 'x'];
        $baza = new QueryException('pgsql', 'insert into users (email) values (?)', ['basia@example.com'], $sterownik);
        $opakowanie = new RuntimeException(self::ZLY_KOMUNIKAT, 0, $baza);

        $kontekst = BezpiecznyBlad::kontekst($opakowanie);

        $this->assertBezZakazanych($kontekst);
        $this->assertSame(RuntimeException::class, $kontekst['wyjatek']);
        $this->assertSame([QueryException::class.' (23505)', PDOException::class.' (23505)'], $kontekst['przyczyny']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $kontekst['odcisk']);
        $this->assertArrayNotHasKey('kod', $kontekst);

        // Ten sam rodzaj awarii → ten sam odcisk (zestawienie z webhookiem).
        $this->assertSame(
            $kontekst['odcisk'],
            substr(sha1(RuntimeException::class.'|'.$opakowanie->getFile().'|'.$opakowanie->getLine()), 0, 8),
        );
    }

    public function test_kod_o_niebezpiecznym_ksztalcie_jest_pomijany(): void
    {
        $e = new class('x') extends RuntimeException
        {
            public function __construct(string $m)
            {
                parent::__construct($m);
                $this->code = 'basia@example.com';
            }
        };

        $this->assertArrayNotHasKey('kod', BezpiecznyBlad::kontekst($e));
        $this->assertSame(42, BezpiecznyBlad::kontekst(new RuntimeException('x', 42))['kod']);
    }

    private function dyskKtoryRzuca(string $nazwa): void
    {
        $dysk = Mockery::mock(Filesystem::class);
        $dysk->shouldReceive('exists', 'delete')->andThrow(new RuntimeException(self::ZLY_KOMUNIKAT));
        Storage::set($nazwa, $dysk);
    }

    public function test_kasowanie_zdjecia_loguje_klucz_i_klase_bez_komunikatu(): void
    {
        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);

        $zdjecie = Media::factory()->create([
            'disk' => 'public',
            'variants_disk' => 'public',
            'object_key' => 'media/abc/oryginal.jpg',
            'metadata' => ['variants' => []],
        ]);

        $this->dyskKtoryRzuca('public');
        $dziennik = Log::spy();

        $this->assertFalse(app(KasujZdjecie::class)->skasujPliki($zdjecie));

        $dziennik->shouldHaveReceived('error')
            ->withArgs(function (string $wiadomosc, array $kontekst) use ($zdjecie): bool {
                if ($wiadomosc !== 'Nie udało się usunąć pliku zdjęcia') {
                    return false;
                }

                $this->assertBezZakazanych($kontekst);
                $this->assertSame($zdjecie->getKey(), $kontekst['media_id']);
                $this->assertSame('media/abc/oryginal.jpg', $kontekst['klucz']);
                $this->assertSame(RuntimeException::class, $kontekst['error']['wyjatek']);

                return true;
            })
            ->atLeast()->once();
    }

    public function test_sprzatanie_wygaslej_paczki_loguje_klucz_i_klase_bez_komunikatu(): void
    {
        $export = DataExport::create([
            'user_id' => User::factory()->create()->getKey(),
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'exports/abc.zip',
            'expires_at' => now()->subDay(),
        ]);

        $this->dyskKtoryRzuca('local');
        $dziennik = Log::spy();

        $this->artisan('kuking:sprzataj-eksporty');

        $dziennik->shouldHaveReceived('error')
            ->withArgs(function (string $wiadomosc, array $kontekst) use ($export): bool {
                if ($wiadomosc !== 'Nie udało się usunąć wygasłej paczki z danymi') {
                    return false;
                }

                $this->assertBezZakazanych($kontekst);
                $this->assertSame($export->getKey(), $kontekst['data_export_id']);
                $this->assertSame('exports/abc.zip', $kontekst['object_key']);
                $this->assertSame(RuntimeException::class, $kontekst['error']['wyjatek']);

                return true;
            })
            ->once();
    }

    /**
     * Skan całego `app/`: żadne wywołanie `Log::…()` nie przekazuje
     * `->getMessage()` (poza `BezpiecznyKomunikat::z()` z modułu poczty).
     * Nowe miejsce z surowym komunikatem ma oblać ten test, nie czekać na
     * kolejny audyt.
     */
    public function test_zadne_wywolanie_loggera_w_app_nie_przekazuje_surowego_komunikatu(): void
    {
        // Świadome wyjątki, z datą ważności: `GenerateUserExport` przebudowuje
        // PR #1436 (`claude/g3-eksport-karencja-list`) — drugi z tych wpisów
        // usuwa, a import `BezpiecznyBlad` wszedłby w konflikt z jego nowym
        // importem. Poprawka po scaleniu #1436. Do tego czasu filtr
        // `BezDanychOsobowychWLogu` na stderr wycina z nich e-mail, hash i SQL.
        $dopuszczone = [
            "Log::warning('Nie udało się zbudować paczki z danymi użytkownika'",
            "Log::warning('Pominięto zdjęcie w paczce z danymi'",
        ];

        $wywolanie = '/Log::(?:channel\([^)]*\)->|stack\([^)]*\)->)?(?:debug|info|notice|warning|error|critical|alert|emergency|log)\((?:[^;]|;(?!\s*$))*?\);/ms';
        $surowy = '/(?<!BezpiecznyKomunikat::z\()\$\w+\??->(?:getPrevious\(\)\??->)?getMessage\(\)/';

        $naruszenia = [];
        $sprawdzone = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $plik) {
            if (! str_ends_with((string) $plik, '.php')) {
                continue;
            }

            $kod = (string) file_get_contents((string) $plik);
            preg_match_all($wywolanie, $kod, $trafienia, PREG_OFFSET_CAPTURE);

            foreach ($trafienia[0] as [$tekst, $pozycja]) {
                $sprawdzone++;

                $dopuszczony = array_filter($dopuszczone, static fn (string $d): bool => str_starts_with($tekst, $d)) !== [];

                if (! $dopuszczony && preg_match($surowy, $tekst) === 1) {
                    $naruszenia[] = substr((string) $plik, strlen(base_path()) + 1).':'.(substr_count(substr($kod, 0, $pozycja), "\n") + 1);
                }
            }
        }

        // KONTROLA DODATNIA: skan naprawdę widzi wywołania loggera.
        $this->assertGreaterThan(50, $sprawdzone);

        $this->assertSame([], $naruszenia, "Surowe getMessage() w logu (użyj BezpiecznyBlad::kontekst()):\n".implode("\n", $naruszenia));
    }
}

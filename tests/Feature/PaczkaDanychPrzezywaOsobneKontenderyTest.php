<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DataExport;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Paczka z danymi da się pobrać z innego kontenera, niż ją zbudował
 * (audyt W3-01), a nieudane kasowanie nie gubi jej adresu (audyt W3-02).
 *
 * DLACZEGO TO NIE JEST TEORIA
 * Produkcja ma OSOBNE serwisy `web`, `worker` i `scheduler`
 * (`PRODUCTION_SPLIT_SERVICES = true` w `.railway/railway.ts`), bez wspólnego
 * wolumenu. Paczkę buduje worker, a pobranie obsługuje web. Przy dysku `local`
 * plik powstawał więc w jednym kontenerze, a szukano go w drugim:
 *
 *   1. człowiek prosi o paczkę,
 *   2. worker zapisuje ZIP do swojego `storage/app/private/`,
 *   3. w bazie ląduje `status = ready`, `disk = local`,
 *   4. człowiek klika „Pobierz",
 *   5. web sprawdza SWÓJ dysk lokalny,
 *   6. 404.
 *
 * Restart workera zabierał ją tak samo. A prosi się o taką paczkę zwykle
 * w jednej konkretnej chwili: przed zamknięciem konta.
 */
class PaczkaDanychPrzezywaOsobneKontenderyTest extends TestCase
{
    use RefreshDatabase;

    public function test_na_r2_paczki_nie_ida_na_dysk_lokalny(): void
    {
        // Ładujemy konfigurację OD NOWA ze zmienną jak na produkcji.
        // Wpisanie wartości przez `config()` i sprawdzenie ich zaraz potem
        // dowodziłoby tylko, że `config()` działa.
        $poprzednie = $_SERVER['FILESYSTEM_DISK'] ?? null;
        $_SERVER['FILESYSTEM_DISK'] = 'r2';

        try {
            /** @var array{exports: array{disk: string}} $swiezy */
            $swiezy = require config_path('kuking.php');

            $this->assertSame(
                'r2_eksporty',
                $swiezy['exports']['disk'],
                'Przy zdjęciach na R2 paczki nadal lądują na dysku lokalnym. Na produkcji buduje '.
                'je worker, a pobiera web — inny kontener, inny dysk, 404 u człowieka.',
            );
        } finally {
            if ($poprzednie === null) {
                unset($_SERVER['FILESYSTEM_DISK']);
            } else {
                $_SERVER['FILESYSTEM_DISK'] = $poprzednie;
            }
        }
    }

    public function test_wdrozenie_ustawia_dysk_paczek_jawnie(): void
    {
        // Wartość domyślna to za mało: ktoś zmieni `FILESYSTEM_DISK` i paczki
        // po cichu wrócą na `local`. Produkcja ma to mieć wpisane wprost.
        $railway = (string) file_get_contents(base_path('.railway/railway.ts'));

        $this->assertStringContainsString('KUKING_EXPORT_DISK: "r2_eksporty"', $railway);
        $this->assertStringContainsString('AWS_EXPORTS_BUCKET', $railway);
    }

    public function test_bucket_paczek_nie_ma_publicznego_adresu(): void
    {
        // Paczka to kopia CAŁEGO konta w jednym pliku. Pobranie idzie wyłącznie
        // trasą z podpisem, po sprawdzeniu, że pyta właściciel — a nie przez
        // adres, który wystarczy zgadnąć.
        $this->assertArrayNotHasKey('url', (array) config('filesystems.disks.r2_eksporty'));

        $this->assertTrue(
            (bool) config('filesystems.disks.r2_eksporty.throw'),
            'Dysk paczek ma `throw => false` — nieudany zapis zwróci wtedy `false`, job poleci '.
            'dalej, rekord dostanie `ready`, a pliku nie będzie nigdzie.',
        );
    }

    public function test_nieudane_kasowanie_zachowuje_adres_paczki(): void
    {
        // NAJWAŻNIEJSZY TEST W TYM PLIKU (audyt W3-02).
        //
        // Wcześniej `disk` i `object_key` znikały bezwarunkowo, także po
        // nieudanym kasowaniu. To zamieniało odwracalną awarię sprzątania
        // w plik z kopią całego konta, o którym nikt już nie wie, gdzie leży —
        // czyli w bezterminowe przechowywanie danych osobowych.
        Storage::fake('local');
        config(['kuking.exports.disk' => 'local']);

        $export = DataExport::create([
            'user_id' => $this->user('basia')->getKey(),
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'eksporty/basia.zip',
            'bytes' => 1234,
            'expires_at' => now()->subDay(),
        ]);

        Storage::disk('local')->put('eksporty/basia.zip', 'udawana paczka');

        // Dysk, z którego nie da się skasować: `delete()` przechodzi bez
        // wyjątku, ale plik zostaje. To jest dokładnie zachowanie dysku `local`
        // z `throw => false`, na którym stary kod się przewracał.
        Storage::shouldReceive('disk')->andReturn(
            $niekasujacy = \Mockery::mock(Filesystem::class),
        );
        $niekasujacy->shouldReceive('delete')->andReturn(false);
        $niekasujacy->shouldReceive('exists')->andReturn(true);

        $this->artisan('kuking:sprzataj-eksporty')->assertSuccessful();

        $export->refresh();

        // Status zmieniony — paczka ma przestać być do pobrania...
        $this->assertSame(DataExport::STATUS_EXPIRED, $export->status);

        // ...ale ADRES ZOSTAJE, bo plik nadal tam leży i ktoś musi go usunąć.
        $this->assertSame('local', $export->disk);
        $this->assertSame('eksporty/basia.zip', $export->object_key);
    }

    public function test_nieudane_kasowanie_jest_ponawiane_i_dopiero_sukces_czysci_adres(): void
    {
        Storage::fake('local');

        $export = DataExport::create([
            'user_id' => $this->user('basia')->getKey(),
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'eksporty/do-ponowienia.zip',
            'bytes' => 1234,
            'expires_at' => now()->subDay(),
        ]);

        $dysk = \Mockery::mock(Filesystem::class);
        $dysk->shouldReceive('delete')->twice()->andReturn(false, true);
        $dysk->shouldReceive('exists')->twice()->andReturn(true, false);
        Storage::shouldReceive('disk')->twice()->with('local')->andReturn($dysk);

        $this->artisan('kuking:sprzataj-eksporty')->assertSuccessful();
        $this->assertSame(DataExport::STATUS_EXPIRED, $export->refresh()->status);
        $this->assertSame('eksporty/do-ponowienia.zip', $export->object_key);

        $this->artisan('kuking:sprzataj-eksporty')->assertSuccessful();
        $this->assertNull($export->refresh()->disk);
        $this->assertNull($export->object_key);
    }

    public function test_niepelny_adres_nie_jest_uznawany_za_udane_kasowanie(): void
    {
        $log = Log::spy();

        $export = DataExport::create([
            'user_id' => $this->user('basia')->getKey(),
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => null,
            'bytes' => 1234,
            'expires_at' => now()->subDay(),
        ]);

        $this->artisan('kuking:sprzataj-eksporty')->assertSuccessful();

        $export->refresh();
        $this->assertSame(DataExport::STATUS_EXPIRED, $export->status);
        $this->assertSame('local', $export->disk);
        $this->assertNull($export->object_key);
        $log->shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Paczka z danymi ma niepełny adres pliku'
                && $context['data_export_id'] === $export->getKey());

        // Niespójnego adresu nie da się automatycznie naprawić. Po zapisaniu
        // błędu nie raportujemy go codziennie jako nowego usunięcia.
        $this->artisan('kuking:sprzataj-eksporty')
            ->expectsOutput('Nie ma wygasłych paczek do usunięcia.')
            ->assertSuccessful();
    }

    public function test_udane_kasowanie_zapomina_adres(): void
    {
        // Kontrola w drugą stronę: gdyby adres zostawał ZAWSZE, baza zbierałaby
        // wskaźniki do plików, których nie ma, a sprzątanie próbowałoby ich
        // w kółko.
        Storage::fake('local');
        config(['kuking.exports.disk' => 'local']);

        $export = DataExport::create([
            'user_id' => $this->user('basia')->getKey(),
            'status' => DataExport::STATUS_READY,
            'disk' => 'local',
            'object_key' => 'eksporty/basia.zip',
            'bytes' => 1234,
            'expires_at' => now()->subDay(),
        ]);

        Storage::disk('local')->put('eksporty/basia.zip', 'udawana paczka');

        $this->artisan('kuking:sprzataj-eksporty')->assertSuccessful();

        $export->refresh();

        $this->assertSame(DataExport::STATUS_EXPIRED, $export->status);
        $this->assertNull($export->disk);
        $this->assertNull($export->object_key);
        Storage::disk('local')->assertMissing('eksporty/basia.zip');
    }
}

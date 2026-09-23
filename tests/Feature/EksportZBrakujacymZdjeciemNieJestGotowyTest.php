<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\DataExportPhotoUnreadable;
use App\Jobs\GenerateUserExport;
use App\Mail\DataExportReady;
use App\Models\DataExport;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DyskEksportuZHakiem;
use Tests\TestCase;
use ZipArchive;

/**
 * ISSUE #1388: zdjęcie `ready`, którego nie da się odczytać, NIE daje
 * paczki `ready`.
 *
 * Plan zdjęć (`ExportPhotoPlan`) powstaje przed kopiowaniem plików, więc
 * `dane.json`, strony przepisów, `index.html` i README liczą każde zdjęcie
 * `ready` i do niego odsyłają. `copyToTemp()` przy błędzie odczytu tylko
 * logował i zwracał `null` — paczka wychodziła jako gotowa, z martwym
 * odnośnikiem i fałszywą liczbą zdjęć, a człowiek dostawał list „gotowe".
 *
 * Wybrany kontrakt: paczka niepełna nie jest wydawana. Kolejka ponawia,
 * a po ostatniej próbie ekran mówi po polsku, co zrobić
 * (`DataExport::REASON_PHOTO_UNREADABLE`).
 *
 * KONTROLA UJEMNA (wykonana): przywrócenie w `copyToTemp()` starego
 * `Log::warning` + `return null` (i `continue` w `addPhotos()`) oblewa oba
 * przypadki z dostawcy — job kończy się bez błędu, czyli paczką `ready`.
 */
class EksportZBrakujacymZdjeciemNieJestGotowyTest extends TestCase
{
    use RefreshDatabase;

    private DyskEksportuZHakiem $zdjecia;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');

        $this->zdjecia = DyskEksportuZHakiem::zarejestruj('zdjecia-hak');

        config([
            'kuking.exports.disk' => 'local',
            'kuking.exports.ttl_days' => 7,
        ]);
    }

    /** @return array<string, array{0: 'false'|'wyjatek'}> */
    public static function odmowy(): array
    {
        return [
            'readStream oddaje false' => ['false'],
            'readStream rzuca wyjątek' => ['wyjatek'],
        ];
    }

    #[DataProvider('odmowy')]
    public function test_jedno_nieodczytane_zdjecie_wsrod_dobrych_zatrzymuje_paczke(string $odmowa): void
    {
        $basia = $this->user('basiazdjecia');

        $this->zdjecie($basia, 'rosol');
        $zepsute = $this->zdjecie($basia, 'pierogi');
        $this->zdjecie($basia, 'sernik');

        $this->zdjecia->odmowOdczytu[$zepsute->object_key] = $odmowa;

        $export = DataExport::create(['user_id' => $basia->getKey(), 'status' => DataExport::STATUS_QUEUED]);

        $dziennik = Log::spy();

        try {
            (new GenerateUserExport((string) $export->getKey()))->handle();
            $this->fail('Job powinien rzucić, żeby kolejka ponowiła próbę.');
        } catch (DataExportPhotoUnreadable) {
            // Oczekiwane — kolejka ma wiedzieć o porażce i ponowić.
        }

        $export->refresh();

        $this->assertNotSame(DataExport::STATUS_READY, $export->status);
        $this->assertSame(DataExport::REASON_PHOTO_UNREADABLE, $export->failure_reason);
        $this->assertNull($export->object_key);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Niepełna paczka nie może wylądować w magazynie.');
        Mail::assertNotSent(DataExportReady::class);

        // Ekran mówi, co zrobić — nie kod i nie komunikat magazynu.
        $this->assertStringContainsString('Spróbuj przygotować paczkę jeszcze raz', $export->failureReasonLabel());

        // Dziennik: identyfikator zdjęcia tak, ścieżka z komunikatu magazynu nie.
        $dziennik->shouldHaveReceived('warning')
            ->withArgs(function (string $wiadomosc, array $kontekst) use ($zepsute): bool {
                $caly = json_encode($kontekst, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return str_contains($caly, (string) $zepsute->getKey())
                    && ! str_contains($caly, '/sciezka/do/');
            })
            ->once();
    }

    public function test_po_ostatniej_probie_powod_jest_ten_sam(): void
    {
        $basia = $this->user('basiazdjeciafailed');
        $export = DataExport::create(['user_id' => $basia->getKey(), 'status' => DataExport::STATUS_PROCESSING]);

        // `failed()` działa na nowej instancji joba — rozpoznaje po klasie.
        (new GenerateUserExport((string) $export->getKey()))
            ->failed(new DataExportPhotoUnreadable('Nie udało się odczytać zdjęcia.'));

        $this->assertSame(DataExport::REASON_PHOTO_UNREADABLE, $export->refresh()->failure_reason);
    }

    /**
     * KONTROLA DODATNIA: te same trzy zdjęcia, wszystkie czytelne — paczka
     * gotowa i ma w środku DOKŁADNIE tyle zdjęć, ile liczy plan.
     */
    public function test_gdy_wszystkie_zdjecia_sa_czytelne_paczka_jest_gotowa_i_pelna(): void
    {
        $basia = $this->user('basiazdjeciaok');

        foreach (['rosol', 'pierogi', 'sernik'] as $nazwa) {
            $this->zdjecie($basia, $nazwa);
        }

        $export = DataExport::create(['user_id' => $basia->getKey(), 'status' => DataExport::STATUS_QUEUED]);

        (new GenerateUserExport((string) $export->getKey()))->handle();

        $export->refresh();

        $this->assertSame(DataExport::STATUS_READY, $export->status);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path((string) $export->object_key)) === true);

        $zdjecia = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (str_starts_with((string) $zip->getNameIndex($i), 'zdjecia/')) {
                $zdjecia++;
            }
        }

        $zip->close();

        $this->assertSame(3, $zdjecia);
        Mail::assertSent(DataExportReady::class);
    }

    private function zdjecie(User $owner, string $nazwa): Media
    {
        $key = "media/{$owner->getKey()}/{$nazwa}.webp";

        $this->zdjecia->put($key, 'udawana-zawartosc-'.$nazwa);

        return Media::factory()->create([
            'owner_id' => $owner->getKey(),
            'disk' => 'zdjecia-hak',
            'object_key' => $key,
            'status' => Media::STATUS_READY,
        ]);
    }
}

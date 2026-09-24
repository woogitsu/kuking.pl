<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\ExportFileNames;
use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

/**
 * Nazwy plików w paczce i klucze paczek w magazynie nie kolidują (issue #825).
 *
 * Do 23 września 2026 plik przepisu brał 6 pierwszych znaków UUID, a klucz
 * paczki — 8. UUID v7 zaczyna się od znacznika czasu w milisekundach, więc
 * te fragmenty są wspólne dla wszystkiego, co powstało w ciągu ~4,6 godziny
 * (6 znaków) albo ~65 sekund (8 znaków). Dwa przepisy „Rosół" z jednego
 * popołudnia dawały JEDNĄ stronę w ZIP-ie; dwie paczki jednego konta —
 * jeden obiekt w magazynie.
 *
 * Dwa pierwsze testy przeniesione z PR #1416 (`codex/issue-825`); trzeci
 * dokłada identyfikatory z PRAWDZIWEGO generatora UUID v7 dla tej samej
 * milisekundy, a nie ręcznie wpisane.
 *
 * KONTROLA UJEMNA: powrót do `Str::substr(…, 0, 6)` / `(…, 0, 8)`
 * w `ExportFileNames` oblewa wszystkie trzy testy.
 */
class NazwyPlikowPaczkiNieKolidujaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('public');
        Storage::fake('local');

        config([
            'kuking.exports.disk' => 'local',
            'kuking.exports.ttl_days' => 7,
        ]);
    }

    public function test_dwa_przepisy_o_identycznym_tytule_z_tej_samej_milisekundy_maja_osobne_strony(): void
    {
        $basia = $this->user('basianazwy');
        $chwila = Carbon::parse('2026-09-23 12:00:00.123');

        $pierwszyId = (string) Str::uuid7($chwila);
        $drugiId = (string) Str::uuid7($chwila);
        $this->assertSame(Str::substr($pierwszyId, 0, 12), Str::substr($drugiId, 0, 12),
            'Założenie testu: ta sama milisekunda daje wspólny początek UUID v7.');

        // Slug jest unikalny, więc serwis nadaje drugiemu przepisowi końcówkę
        // „-2". Nazwa pliku przycina slug do 70 znaków — przy długim tytule
        // końcówka odpada i oba slugi dają ten sam czytelny początek.
        // Rozróżnia je wtedy WYŁĄCZNIE identyfikator.
        $tytul = 'Rosół babci Heleny na kurze zagrodowej z lubczykiem, marchewką i domowym makaronem';
        $slug = Str::slug($tytul);
        $this->assertGreaterThan(70, strlen($slug), 'Założenie testu: slug dłuższy niż limit nazwy pliku.');

        $pierwszy = $this->przepis($basia, $pierwszyId, $tytul, $slug);
        $drugi = $this->przepis($basia, $drugiId, $tytul, $slug.'-2');

        $this->assertNotSame(ExportFileNames::recipeFile($pierwszy), ExportFileNames::recipeFile($drugi));
        $this->assertStringStartsWith('rosol-babci-heleny', ExportFileNames::recipeFile($pierwszy), 'Nazwa ma zostać czytelna dla człowieka.');
        $this->assertStringContainsString($pierwszyId, ExportFileNames::recipeFile($pierwszy));

        $export = $this->zbudujPaczke($basia, (string) Str::uuid7());
        $strony = $this->stronyPrzepisow($export);

        $this->assertCount(2, $strony, 'Dwa przepisy „Rosół" muszą mieć dwie strony w paczce.');

        $dane = json_decode($this->plik($export, 'dane.json'), true, 512, JSON_THROW_ON_ERROR);
        $odnosniki = array_column($dane['przepisy'], 'plik_do_czytania');
        sort($odnosniki);
        $this->assertSame($strony, $odnosniki, 'Spis w dane.json wskazuje dokładnie te pliki, które są w ZIP-ie.');
    }

    public function test_dwa_przepisy_o_wspolnym_poczatku_nazwy_i_uuid_maja_osobne_strony_w_paczce(): void
    {
        $basia = $this->user('basia');
        $wspolnySlug = str_repeat('a', 70);

        $pierwszy = $this->przepis($basia, '01995a80-0000-7000-8000-000000000001', 'Pierwszy przepis', $wspolnySlug.'-pierwszy');
        $drugi = $this->przepis($basia, '01995a80-0000-7000-8000-000000000002', 'Drugi przepis', $wspolnySlug.'-drugi');

        $export = $this->zbudujPaczke($basia, (string) Str::uuid7());

        $this->assertCount(2, $this->stronyPrzepisow($export), 'Oba przepisy muszą dostać osobne strony HTML w gotowej paczce.');
        $this->assertStringContainsString('Pierwszy przepis', $this->plik($export, 'przepisy/'.ExportFileNames::recipeFile($pierwszy)));
        $this->assertStringContainsString('Drugi przepis', $this->plik($export, 'przepisy/'.ExportFileNames::recipeFile($drugi)));
    }

    public function test_dwa_kolejne_eksporty_tego_samego_konta_nie_nadpisuja_sie_i_sprzatanie_nie_kasuje_nowszego(): void
    {
        $basia = $this->user('basia');
        $pierwszy = $this->zbudujPaczke($basia, '01995a80-0000-7000-8000-000000000011');

        Recipe::factory()->for($basia, 'author')->create(['title' => 'Nowy przepis']);

        $drugi = $this->zbudujPaczke($basia, '01995a80-0000-7000-8000-000000000012');

        $this->assertSame(DataExport::STATUS_READY, $pierwszy->status);
        $this->assertSame(DataExport::STATUS_READY, $drugi->status);
        $this->assertNotSame($pierwszy->object_key, $drugi->object_key, 'Kolejne paczki mają ten sam klucz i nadpisują się w magazynie.');
        Storage::disk('local')->assertExists((string) $pierwszy->object_key);
        Storage::disk('local')->assertExists((string) $drugi->object_key);

        $this->actingAs($basia)->get($this->adresPobrania($pierwszy))->assertOk();
        $this->actingAs($basia)->get($this->adresPobrania($drugi))->assertOk();

        $staryKlucz = (string) $pierwszy->object_key;
        $nowyKlucz = (string) $drugi->object_key;
        $pierwszy->update(['expires_at' => now()->subDay()]);

        $this->artisan('kuking:sprzataj-eksporty')->assertSuccessful();

        Storage::disk('local')->assertMissing($staryKlucz);
        Storage::disk('local')->assertExists($nowyKlucz);
        $this->assertSame(DataExport::STATUS_READY, $drugi->refresh()->status);
    }

    // -----------------------------------------------------------------

    private function przepis(User $autor, string $id, string $tytul, string $slug): Recipe
    {
        $przepis = Recipe::factory()->for($autor, 'author')->make(['title' => $tytul, 'slug' => $slug]);
        $przepis->setAttribute('id', $id);
        $przepis->save();

        return $przepis;
    }

    private function zbudujPaczke(User $user, string $id): DataExport
    {
        $export = new DataExport;
        $export->forceFill([
            'id' => $id,
            'user_id' => $user->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);
        $export->save();

        (new GenerateUserExport($id))->handle();

        return $export->refresh();
    }

    /** @return list<string> posortowane ścieżki `przepisy/*.html` */
    private function stronyPrzepisow(DataExport $export): array
    {
        $zip = $this->archiwum($export);
        $strony = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nazwa = (string) $zip->getNameIndex($i);

            if (str_starts_with($nazwa, 'przepisy/') && str_ends_with($nazwa, '.html')) {
                $strony[] = $nazwa;
            }
        }

        $zip->close();
        sort($strony);

        return $strony;
    }

    private function plik(DataExport $export, string $nazwa): string
    {
        $zip = $this->archiwum($export);
        $tresc = $zip->getFromName($nazwa);
        $zip->close();

        $this->assertIsString($tresc, "W archiwum nie ma pliku {$nazwa}.");

        return $tresc;
    }

    private function archiwum(DataExport $export): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk((string) $export->disk)->path((string) $export->object_key)) === true);

        return $zip;
    }

    private function adresPobrania(DataExport $export): string
    {
        return URL::temporarySignedRoute('settings.data.download', $export->expires_at, ['export' => $export->getKey()]);
    }
}

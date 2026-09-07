<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Paczka RODO przyznaje się do zdjęć, których w niej nie ma (issue #113).
 *
 * SKĄD SIĘ BIERZE PROBLEM
 * `ExportPhotoPlan` bierze wyłącznie zdjęcia w stanie `ready`. Zdjęcie wgrane
 * chwilę wcześniej jest jeszcze w kolejce, więc do paczki nie wchodzi — a widoki
 * po prostu pomijają `<img>`, gdy dostaną `null`. Liczniki w `index.html`
 * i `CZYTAJ-TO-NAJPIERW.txt` też liczą tylko to, co weszło, więc paczka jest
 * WEWNĘTRZNIE SPÓJNA i wygląda kompletnie. Nie jest.
 *
 * `GenerateUserExport` i `ProcessUploadedImage` dzielą tę samą kolejkę, więc na
 * koncie z wieloma zdjęciami eksport realnie potrafi wystartować pierwszy.
 *
 * DLACZEGO TO NIE JEST DROBIAZG
 * Człowiek prosi o paczkę zwykle PRZED skasowaniem konta. Brak zauważy dopiero
 * po 30 dniach, gdy nie ma już czego odzyskać. RODO art. 15/20 — cytowane wprost
 * w `dane.json` — obiecuje dostęp do wszystkich danych, nie do tych, które
 * akurat zdążyły się przetworzyć.
 */
class EksportMowiOZdjeciachWDrodzeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Fragment ZDANIA z ostrzeżenia, nie samo słowo „UWAGA”.
     *
     * Słowo padało też w komentarzu arkusza stylów paczki, więc asercja na nim
     * przechodziła zawsze, niczego nie sprawdzając — dokładnie ta pułapka,
     * którą opisuje `bezKomentarzy` w `LogotypIMarkaTest`. Komentarz już tego
     * słowa nie cytuje, ale asercja i tak ma trafiać w treść dla człowieka,
     * a nie w jedno słowo, które może wrócić gdzie indziej.
     */
    private const ZDANIE_O_BRAKU = 'nie zmieściło się w tej paczce';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config(['kuking.exports.disk' => 'local', 'kuking.exports.ttl_days' => 7]);
    }

    public function test_paczka_ze_zdjeciem_w_przygotowaniu_mowi_o_nim_wprost(): void
    {
        $basia = $this->user('basia', ['display_name' => 'Basia']);

        $gotowe = $this->zdjecie($basia, 'sernik', Media::STATUS_READY);
        $wDrodze = $this->zdjecie($basia, 'rosol', Media::STATUS_PENDING);

        Recipe::factory()->for($basia, 'author')->create([
            'title' => 'Rosół babci Zofii',
            'hero_media_id' => $wDrodze->getKey(),
        ]);

        $paczka = $this->zbudujPaczke($basia);

        $readme = $this->zArchiwum($paczka, 'CZYTAJ-TO-NAJPIERW.txt');
        $index = $this->zArchiwum($paczka, 'index.html');

        foreach (['CZYTAJ-TO-NAJPIERW.txt' => $readme, 'index.html' => $index] as $plik => $tresc) {
            $this->assertStringContainsString(
                self::ZDANIE_O_BRAKU,
                $tresc,
                "Plik {$plik} milczy o zdjęciu, którego w paczce nie ma.",
            );

            // Komunikat ma powiedzieć CO ZROBIĆ, nie tylko co się stało.
            $this->assertStringContainsString('Poproś o nową paczkę', $tresc);
        }

        // Licznik w paczce zostaje uczciwy: zdjęcie w drodze NIE jest doliczane
        // do tych, które faktycznie w niej leżą.
        $this->assertStringContainsString('Zdjęć w paczce: 1', $readme);

        $dane = json_decode($this->zArchiwum($paczka, 'dane.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $dane['o_tym_pliku']['zdjec_jeszcze_w_przygotowaniu']);

        // Gotowe zdjęcie mimo wszystko w paczce jest — ostrzeżenie nie może
        // zastąpić eksportu, tylko go opisać.
        $this->assertNotEmpty(array_filter(
            $this->plikiWArchiwum($paczka),
            fn (string $nazwa): bool => str_starts_with($nazwa, 'zdjecia/'),
        ));
        $this->assertSame(Media::STATUS_READY, $gotowe->refresh()->status);
    }

    public function test_paczka_z_kompletem_zdjec_nie_straszy_bez_powodu(): void
    {
        $basia = $this->user('basia');
        $this->zdjecie($basia, 'sernik', Media::STATUS_READY);

        $paczka = $this->zbudujPaczke($basia);

        foreach (['CZYTAJ-TO-NAJPIERW.txt', 'index.html'] as $plik) {
            $this->assertStringNotContainsString(
                self::ZDANIE_O_BRAKU,
                $this->zArchiwum($paczka, $plik),
                "Plik {$plik} ostrzega o brakach, których nie ma — fałszywy alarm.",
            );
        }

        $dane = json_decode($this->zArchiwum($paczka, 'dane.json'), true, 512, JSON_THROW_ON_ERROR);

        // Pole jest ZAWSZE, także gdy wynosi zero: klucz pojawiający się tylko
        // przy brakach zmuszałby program czytający paczkę do zgadywania, czy
        // zera nie ma, bo braków nie było, czy dlatego, że paczkę zbudowała
        // starsza wersja serwisu.
        $this->assertSame(0, $dane['o_tym_pliku']['zdjec_jeszcze_w_przygotowaniu'] ?? null);
    }

    public function test_konto_wylacznie_ze_zdjeciami_w_drodze_nie_slyszy_ze_nie_ma_zadnego(): void
    {
        // Najgorszy wariant: `photoCount` wynosi zero, więc spis treści mówił
        // „Nie masz jeszcze w Kuking żadnego zdjęcia" osobie, która wgrała je
        // pięć minut wcześniej. To jest nie tylko brak — to jest zdanie
        // nieprawdziwe, w pliku, który ma dowodzić, że jej dane są bezpieczne.
        $basia = $this->user('basia');
        $this->zdjecie($basia, 'rosol', Media::STATUS_PROCESSING);

        $index = $this->zArchiwum($this->zbudujPaczke($basia), 'index.html');

        $this->assertStringNotContainsString('Nie masz jeszcze w Kuking żadnego zdjęcia', $index);
        $this->assertStringContainsString(self::ZDANIE_O_BRAKU, $index);
    }

    public function test_zdjecie_odrzucone_na_dobre_nie_obiecuje_ze_dojdzie_pozniej(): void
    {
        // `rejected` też nie ma w paczce, ale to jest INNA wiadomość: takie
        // zdjęcie nie pojawi się w niej NIGDY, więc „poproś o nową paczkę
        // za kilka minut" byłoby zwykłą nieprawdą.
        $basia = $this->user('basia');
        $this->zdjecie($basia, 'sernik', Media::STATUS_READY);
        $this->zdjecie($basia, 'spalony', Media::STATUS_REJECTED);

        $index = $this->zArchiwum($this->zbudujPaczke($basia), 'index.html');

        $this->assertStringNotContainsString(self::ZDANIE_O_BRAKU, $index);
        $this->assertStringNotContainsString('class="uwaga"', $index);
    }

    public function test_liczebnik_w_ostrzezeniu_jest_odmieniony_po_polsku(): void
    {
        // „3 zdjęć nie zmieściło się" to zdanie, po którym człowiek widzi, że
        // pisał je automat. W pliku o skasowaniu konta to jest ostatnia rzecz,
        // jakiej potrzeba.
        $basia = $this->user('basia');

        foreach (['a', 'b', 'c'] as $nazwa) {
            $this->zdjecie($basia, $nazwa, Media::STATUS_PENDING);
        }

        $index = $this->zArchiwum($this->zbudujPaczke($basia), 'index.html');

        $this->assertStringContainsString('3 zdjęcia nie zmieściły się w tej paczce', $index);
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    private function zdjecie(User $wlasciciel, string $nazwa, string $status): Media
    {
        $klucz = "media/{$wlasciciel->getKey()}/{$nazwa}.webp";

        Storage::disk('public')->put($klucz, 'udawana-zawartosc-zdjecia');

        return Media::factory()->create([
            'owner_id' => $wlasciciel->getKey(),
            'disk' => 'public',
            'object_key' => $klucz,
            'status' => $status,
        ]);
    }

    private function zbudujPaczke(User $user): DataExport
    {
        $export = DataExport::create([
            'user_id' => $user->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        (new GenerateUserExport((string) $export->getKey()))->handle();

        return $export->refresh();
    }

    private function zArchiwum(DataExport $export, string $plik): string
    {
        $zip = $this->otworz($export);
        $tresc = $zip->getFromName($plik);
        $zip->close();

        $this->assertIsString($tresc, "W archiwum nie ma pliku {$plik}.");

        return $tresc;
    }

    /** @return list<string> */
    private function plikiWArchiwum(DataExport $export): array
    {
        $zip = $this->otworz($export);
        $pliki = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nazwa = $zip->getNameIndex($i);

            if (is_string($nazwa)) {
                $pliki[] = $nazwa;
            }
        }

        $zip->close();

        return $pliki;
    }

    private function otworz(DataExport $export): ZipArchive
    {
        $zip = new ZipArchive;

        $this->assertTrue(
            $zip->open(Storage::disk((string) $export->disk)->path((string) $export->object_key)) === true,
            'Nie udało się otworzyć paczki ZIP.',
        );

        return $zip;
    }
}

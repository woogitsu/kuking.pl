<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Collections\Actions\SaveRecipeToCollection;
use App\Jobs\GenerateUserExport;
use App\Models\DataExport;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Zeszyt z cudzymi przepisami — co paczka NAPRAWDĘ o nich niesie.
 *
 * Powód istnienia tego pliku: `o_tym_pliku` w `dane.json` jest jedynym
 * miejscem, w którym paczka opisuje samą siebie programowi i człowiekowi,
 * który do niej wróci za dziesięć lat. Jeśli ten opis mówi „wszystkie treści
 * tego konta", a zeszyt z czterdziestoma odłożonymi cudzymi przepisami niesie
 * z każdego z nich WYŁĄCZNIE tytuł i autora, to człowiek dowiaduje się
 * o brakach dopiero wtedy, gdy Kuking już nie istnieje i nie ma dokąd wrócić.
 *
 * Granica sama w sobie jest słuszna i nie jest tu zmieniana: cudzy przepis
 * to dane osoby, która go napisała (patrz `CollectUserExportData::collections()`).
 * Ten test pilnuje wyłącznie tego, żeby paczka o tej granicy MÓWIŁA.
 *
 * Czego ten test NIE dowodzi: że zdanie jest ładnie napisane po polsku ani
 * że człowiek je zrozumie. Sprawdza, że istnieje i że nazywa dokładnie to
 * ograniczenie, które paczka naprawdę stosuje.
 */
class PaczkaNieObiecujeCudzychPrzepisowTest extends TestCase
{
    use RefreshDatabase;

    private const SKLADNIK = 'Trzy szklanki mąki krupczatki od Zenka';

    private const KROK = 'Zagnieść ciasto przez dwanaście minut, aż odejdzie od ręki.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config([
            'kuking.exports.disk' => 'local',
            'kuking.exports.ttl_days' => 7,
        ]);
    }

    /**
     * Kontrola DODATNIA do testu niżej: zanim sprawdzimy, że paczka mówi
     * o ograniczeniu, mierzymy, że to ograniczenie NAPRAWDĘ jest.
     *
     * Bez tego drugi test przechodziłby także wtedy, gdyby eksport zaczął
     * kiedyś nieść pełne cudze przepisy — a wtedy zdanie o tytule i autorze
     * stałoby się nieprawdą w drugą stronę (pułapka 4 z `docs/PULAPKI_TESTOW.md`:
     * asercja „czegoś nie ma" przechodzi także wtedy, gdy nie ma niczego).
     */
    public function test_zapisany_cudzy_przepis_jest_w_paczce_jako_tytul_i_autor(): void
    {
        [$export, $zenek] = $this->paczkaZZeszytem();

        $dane = $this->jsonZArchiwum($export);
        $wpisyArchiwum = $this->wpisyArchiwum($export);

        $this->assertCount(1, $dane['kolekcje'], 'Zeszyt nie wszedł do paczki — test mierzy nie to, co trzeba.');
        $pozycje = $dane['kolekcje'][0]['przepisy'];
        $this->assertCount(1, $pozycje, 'Zapisany cudzy przepis nie wszedł do paczki.');

        // Tyle i tylko tyle: tytuł, autor, moja notatka, data zapisania.
        $this->assertSame(
            ['tytul', 'autor', 'moja_notatka', 'zapisano'],
            array_keys($pozycje[0]),
            'Zmienił się zakres zapisanego cudzego przepisu — zdanie w „czego_nie_zawiera" przestało opisywać rzeczywistość.',
        );

        $this->assertSame('Kluski śląskie Zenka', $pozycje[0]['tytul']);
        $this->assertSame($zenek->displayName(), $pozycje[0]['autor']);

        // ...a treści przepisu nie ma w CAŁEJ paczce, nie tylko w tym miejscu.
        $cala = implode("\n", array_map(
            fn (string $plik): string => $this->zArchiwum($export, $plik),
            array_values(array_filter(
                $wpisyArchiwum,
                fn (string $n): bool => str_ends_with($n, '.html') || str_ends_with($n, '.json') || str_ends_with($n, '.txt'),
            )),
        ));

        $this->assertStringNotContainsString(self::SKLADNIK, $cala,
            'Paczka wyniosła składniki cudzego przepisu poza serwis.');
        $this->assertStringNotContainsString(self::KROK, $cala,
            'Paczka wyniosła kroki cudzego przepisu poza serwis.');

        // Zdjęcie cudzego przepisu też nie — `ExportPhotoPlan` chodzi wyłącznie
        // po `$user->media()`.
        $this->assertSame(
            [],
            array_values(array_filter($wpisyArchiwum, fn (string $n): bool => str_starts_with($n, 'zdjecia/'))),
            'W paczce jest zdjęcie, którego właścicielem nie jest autor paczki.',
        );
    }

    /**
     * REGRESJA: paczka stosowała to ograniczenie po cichu.
     *
     * `o_tym_pliku.czego_nie_zawiera` wymieniało wyłącznie dane kontaktowe
     * innych osób. O tym, że odłożony cudzy przepis jest w paczce samym
     * tytułem i autorem — bez składników, kroków i zdjęć — nie mówiło nic,
     * w żadnym pliku paczki. Człowiek, który odłożył czterdzieści cudzych
     * przepisów „na kiedyś", dostawał plik wyglądający na kompletny.
     */
    public function test_paczka_mowi_wprost_czego_nie_niesie_z_cudzych_przepisow(): void
    {
        [$export] = $this->paczkaZZeszytem();

        $opis = $this->jsonZArchiwum($export)['o_tym_pliku']['czego_nie_zawiera'];

        // Granica sprzed tej regresji ma zostać — nie zastępujemy jednej
        // informacji drugą.
        $this->assertStringContainsString('adresu e-mail', $opis,
            'Zniknęła granica o danych kontaktowych innych osób.');

        // Czytelnik ma się z tego zdania dowiedzieć TRZECH rzeczy: że chodzi
        // o cudze przepisy, KTÓRE POLA z nich są, i że nie ma reszty.
        //
        // Pola są cztery (`tytul`, `autor`, `moja_notatka`, `zapisano`) —
        // asercja na klucze w `EksportWygladObietnicePaczkiTest` to mierzy.
        // Zdanie wymieniało dwa, czyli ZANIŻAŁO to, co paczka niesie; przy
        // rezygnacji z konta to jest zaniżenie w złą stronę. Każde pojęcie
        // ma własną asercję, żeby wypadnięcie jednego nie schowało się
        // za pozostałymi (pułapka 3b).
        foreach (['przepis', 'tytuł', 'autor', 'notatka', 'data zapisania', 'składnik'] as $pojecie) {
            $this->assertMatchesRegularExpression(
                '/'.preg_quote($pojecie, '/').'/ui',
                $opis,
                "Opis paczki nie mówi o „{$pojecie}” — zapisany cudzy przepis jest w niej okrojony po cichu.",
            );
        }
    }

    // -----------------------------------------------------------------
    // Pomocnicze
    // -----------------------------------------------------------------

    /** @return array{0: DataExport, 1: User} */
    private function paczkaZZeszytem(): array
    {
        $basia = $this->user('basia', ['display_name' => 'Basia Żółwiowa']);
        $zenek = $this->user('zenek', ['display_name' => 'Zenek Ćwikła']);

        $zdjecie = $this->zdjecieDla($zenek, 'kluski');

        $cudzy = Recipe::factory()->for($zenek, 'author')->create([
            'title' => 'Kluski śląskie Zenka',
            'summary' => 'Najlepsze kluski w całej okolicy.',
            'hero_media_id' => $zdjecie->getKey(),
        ]);

        RecipeIngredient::create([
            'recipe_id' => $cudzy->getKey(),
            'ingredient_text' => self::SKLADNIK,
            'position' => 0,
        ]);

        RecipeStep::create([
            'recipe_id' => $cudzy->getKey(),
            'position' => 0,
            'instruction' => self::KROK,
        ]);

        app(SaveRecipeToCollection::class)->handle($basia, $cudzy, $basia->defaultCollection());

        $export = DataExport::create([
            'user_id' => $basia->getKey(),
            'status' => DataExport::STATUS_QUEUED,
        ]);

        (new GenerateUserExport((string) $export->getKey()))->handle();

        return [$export->refresh(), $zenek];
    }

    private function zdjecieDla(User $owner, string $nazwa): Media
    {
        $key = "media/{$owner->getKey()}/{$nazwa}.webp";

        Storage::disk('public')->put($key, 'udawana-zawartosc-zdjecia');

        return Media::factory()->create([
            'owner_id' => $owner->getKey(),
            'disk' => 'public',
            'object_key' => $key,
            'status' => Media::STATUS_READY,
        ]);
    }

    /** @return array<string, mixed> */
    private function jsonZArchiwum(DataExport $export): array
    {
        return json_decode($this->zArchiwum($export, 'dane.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function wpisyArchiwum(DataExport $export): array
    {
        $zip = $this->otworz($export);

        $out = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nazwa = $zip->getNameIndex($i);

            if (is_string($nazwa)) {
                $out[] = $nazwa;
            }
        }

        $zip->close();

        return $out;
    }

    private function zArchiwum(DataExport $export, string $plik): string
    {
        $zip = $this->otworz($export);
        $tresc = $zip->getFromName($plik);
        $zip->close();

        $this->assertIsString($tresc, "W archiwum nie ma pliku {$plik}.");

        return $tresc;
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

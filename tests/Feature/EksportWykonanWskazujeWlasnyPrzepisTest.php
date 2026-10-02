<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportFileNames;
use App\Domain\Users\Exports\WersjaFormatuPaczki;
use App\Jobs\GenerateUserExport;
use App\Models\Block;
use App\Models\CookedEvent;
use App\Models\DataExport;
use App\Models\Recipe;
use App\Models\RecipeVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/** Relacja własnego wykonania z plikiem przepisu w tej samej paczce (#2639). */
class EksportWykonanWskazujeWlasnyPrzepisTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        config(['kuking.exports.disk' => 'local']);
    }

    public function test_dwa_wlasne_przepisy_o_tym_samym_tytule_maja_odrebne_prawdziwe_cele_w_paczce(): void
    {
        $autor = $this->user('autor2639');
        $pierwszy = Recipe::factory()->for($autor, 'author')->create(['title' => 'Sernik', 'slug' => 'sernik', 'visibility' => 'private']);
        $drugi = Recipe::factory()->for($autor, 'author')->create(['title' => 'Sernik', 'slug' => 'sernik-2']);
        $pierwszaWersja = RecipeVersion::create(['recipe_id' => $pierwszy->getKey(), 'editor_id' => $autor->getKey(), 'version_number' => 1, 'snapshot' => ['title' => 'Sernik']]);
        $drugaWersja = RecipeVersion::create(['recipe_id' => $drugi->getKey(), 'editor_id' => $autor->getKey(), 'version_number' => 1, 'snapshot' => ['title' => 'Sernik']]);
        CookedEvent::factory()->create(['user_id' => $autor->getKey(), 'recipe_id' => $pierwszy->getKey(), 'note' => 'Pierwsza próba'])
            ->forceFill(['recipe_version_id' => $pierwszaWersja->getKey()])->save();
        CookedEvent::factory()->create(['user_id' => $autor->getKey(), 'recipe_id' => $drugi->getKey(), 'note' => 'Druga próba'])
            ->forceFill(['recipe_version_id' => $drugaWersja->getKey()])->save();

        [$dane, $zip] = $this->paczka($autor);
        $plikiPrzepisow = array_column($dane['przepisy'], 'plik_do_czytania');
        $plikiWykonan = array_column($dane['ugotowalem'], 'plik_wlasnego_przepisu', 'notatka');

        $this->assertSame(['Sernik', 'Sernik'], array_column($dane['przepisy'], 'tytul'));
        $this->assertSame(['Sernik', 'Sernik'], array_column($dane['ugotowalem'], 'przepis'));
        $this->assertSame([1, 1], array_column($dane['ugotowalem'], 'numer_wersji_przepisu'));
        $this->assertArrayHasKey('Pierwsza próba', $plikiWykonan, 'EKSPORT_2639_RELACJA_WYKONANIA_DO_PLIKU');
        $this->assertArrayHasKey('Druga próba', $plikiWykonan, 'EKSPORT_2639_RELACJA_WYKONANIA_DO_PLIKU');
        $this->assertSame('przepisy/'.ExportFileNames::recipeFile($pierwszy), $plikiWykonan['Pierwsza próba'], 'EKSPORT_2639_RELACJA_WYKONANIA_DO_PLIKU');
        $this->assertSame('przepisy/'.ExportFileNames::recipeFile($drugi), $plikiWykonan['Druga próba'], 'EKSPORT_2639_RELACJA_WYKONANIA_DO_PLIKU');
        $this->assertNotSame($plikiWykonan['Pierwsza próba'], $plikiWykonan['Druga próba'], 'EKSPORT_2639_DWA_WLASNE_PRZEPISY_MAJA_ODREBNE_CELE');
        $this->assertEqualsCanonicalizing($plikiPrzepisow, array_values($plikiWykonan), 'EKSPORT_2639_RELACJA_WYKONANIA_DO_PLIKU');
        foreach ($plikiWykonan as $cel) {
            $this->assertIsString($cel, 'EKSPORT_2639_RELACJA_WYKONANIA_DO_PLIKU');
            $this->assertNotFalse($zip->locateName($cel), 'EKSPORT_2639_CEL_NAPRAWDE_JEST_W_ZIP');
        }

        $this->assertSame(WersjaFormatuPaczki::AKTUALNA, $dane['o_tym_pliku']['wersja_formatu']);
        $zip->close();
    }

    public function test_cudzy_przepis_nawet_widoczny_nie_dostaje_linku_do_wlasnych_plikow(): void
    {
        $kucharz = $this->user('kucharz2639');
        $autor = $this->user('obcyautor2639');
        $obcy = Recipe::factory()->for($autor, 'author')->create(['title' => 'Obcy sernik']);
        CookedEvent::factory()->create(['user_id' => $kucharz->getKey(), 'recipe_id' => $obcy->getKey(), 'note' => 'Moja notatka']);

        [$dane, $zip] = $this->paczka($kucharz);
        $this->assertSame('Obcy sernik', $dane['ugotowalem'][0]['przepis']);
        $this->assertNull($dane['ugotowalem'][0]['plik_wlasnego_przepisu'], 'EKSPORT_2639_CUDZY_PRZEPIS_BEZ_PLIKU');
        $this->assertSame([], $dane['przepisy']);
        $zip->close();
    }

    public function test_usuniety_wlasny_przepis_nie_dostaje_martwego_odsylacza(): void
    {
        $autor = $this->user('usuniety2639');
        $przepis = Recipe::factory()->for($autor, 'author')->create(['title' => 'Sernik sprzed lat']);
        CookedEvent::factory()->create(['user_id' => $autor->getKey(), 'recipe_id' => $przepis->getKey(), 'note' => 'Zachowana próba']);
        $przepis->delete();

        [$dane, $zip] = $this->paczka($autor);
        $this->assertSame([], $dane['przepisy']);
        $this->assertSame('Zachowana próba', $dane['ugotowalem'][0]['notatka']);
        $this->assertNull($dane['ugotowalem'][0]['plik_wlasnego_przepisu'], 'EKSPORT_2639_USUNIETY_PRZEPIS_BEZ_PLIKU');
        $zip->close();
    }

    public function test_prywatny_zablokowany_i_ukryty_cudzy_przepis_nie_daja_referencji_ani_tytulu(): void
    {
        $kucharz = $this->user('kucharz2639');
        $prywatnyAutor = $this->user('prywatny2639');
        $blokujacyAutor = $this->user('blokujacy2639');
        $ukrytyAutor = $this->user('ukryty2639');

        $prywatny = Recipe::factory()->for($prywatnyAutor, 'author')->create(['title' => 'Prywatny tytuł', 'visibility' => 'private']);
        $zablokowany = Recipe::factory()->for($blokujacyAutor, 'author')->create(['title' => 'Zablokowany tytuł']);
        $ukryty = Recipe::factory()->for($ukrytyAutor, 'author')->create(['title' => 'Ukryty tytuł', 'status' => Recipe::STATUS_HIDDEN]);
        Block::create(['blocker_id' => $blokujacyAutor->getKey(), 'blocked_id' => $kucharz->getKey(), 'created_at' => now()]);

        foreach ([$prywatny, $zablokowany, $ukryty] as $przepis) {
            CookedEvent::factory()->create(['user_id' => $kucharz->getKey(), 'recipe_id' => $przepis->getKey(), 'note' => 'Moja próba']);
        }

        [$dane, $zip] = $this->paczka($kucharz);
        $this->assertCount(3, $dane['ugotowalem']);
        foreach ($dane['ugotowalem'] as $wykonanie) {
            $this->assertSame(CollectUserExportData::TRESC_NIEDOSTEPNA, $wykonanie['przepis']);
            $this->assertNull($wykonanie['plik_wlasnego_przepisu'], 'EKSPORT_2639_NIEDOSTEPNY_PRZEPIS_BEZ_PLIKU');
            $this->assertNull($wykonanie['autor_przepisu']);
        }
        $zip->close();
    }

    /** @return array{0: array<string, mixed>, 1: ZipArchive} */
    private function paczka(User $user): array
    {
        $export = DataExport::create(['user_id' => $user->getKey(), 'status' => DataExport::STATUS_QUEUED]);
        (new GenerateUserExport((string) $export->getKey()))->handle();
        $export->refresh();

        $sciezka = Storage::disk((string) $export->disk)->path((string) $export->object_key);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($sciezka) === true);
        $json = $zip->getFromName('dane.json');
        $this->assertIsString($json);

        return [json_decode($json, true, 512, JSON_THROW_ON_ERROR), $zip];
    }
}

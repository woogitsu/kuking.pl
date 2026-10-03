<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Domain\Users\Exports\KopiaJednegoPrzepisu;
use App\Domain\Users\Import\PaczkaOdrzucona;
use App\Domain\Users\Import\PodgladPaczkiEksportu;
use App\Domain\Users\Import\PozycjaPodgladu;
use App\Domain\Users\Import\WczytajPaczke;
use App\Models\Comment;
use App\Models\Media;
use App\Models\Recipe;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;
use ZipArchive;

/**
 * Przenośna kopia jednego własnego przepisu (#2531, V2, decyzja właściciela z
 * 2.10.2026). Testy pilnują trzech rzeczy naraz: że autor dostaje małą paczkę w
 * istniejącym formacie, że w paczce nie ma niczego poza tekstem jego przepisu, i
 * że obecny importer ją wczytuje (jako prywatny szkic, bez mnożenia duplikatów).
 *
 * @bez-kontroli-dodatniej Test uruchamia prawdziwe pobranie i import na bazie i asertuje na wygenerowanym archiwum (zawartość ZIP, dane w bazie), nie na treści plików źródłowych; każda asercja „nie ma” ma obok kontrolę dodatnią.
 */
class KopiaJednegoPrzepisuTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $pliki = [];

    protected function tearDown(): void
    {
        foreach ($this->pliki as $plik) {
            @unlink($plik);
        }

        parent::tearDown();
    }

    public function test_autor_dostaje_mala_paczke_z_trzema_plikami_i_prywatnymi_naglowkami(): void
    {
        $basia = $this->user('basia_kopia', ['email' => 'basia-kopia@example.test']);
        $przepis = $this->przepis($basia);

        $odpowiedz = $this->actingAs($basia)->post(route('recipes.copy.download', $przepis->slug));
        $odpowiedz->assertOk();
        $this->assertInstanceOf(BinaryFileResponse::class, $odpowiedz->baseResponse);

        $naglowki = $odpowiedz->headers;
        $this->assertSame('application/zip', $naglowki->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $naglowki->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $naglowki->get('Cache-Control'));
        $this->assertMatchesRegularExpression('/attachment; filename=kuking-przepis-pierogi-z-kapusta-\d{4}-\d{2}-\d{2}\.zip/', (string) $naglowki->get('Content-Disposition'));

        $sciezka = $this->zapiszZip($odpowiedz);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($sciezka) === true);
        $nazwy = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nazwy[] = $zip->getNameIndex($i);
        }
        sort($nazwy);
        $this->assertSame(['CZYTAJ_TO.txt', 'dane.json', 'przepis.html'], $nazwy);
        $this->assertLessThan(200_000, filesize($sciezka), 'Kopia jednego przepisu ma być małą paczką.');

        // Kontrola dodatnia dla poniższych asercji „nie ma”: dane są i są czytelne.
        $dane = json_decode((string) $zip->getFromName('dane.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Kuking.pl', $dane['o_tym_pliku']['serwis']);
        $this->assertSame(1, $dane['o_tym_pliku']['wersja_formatu']);
        $this->assertSame('Pierogi z kapustą', $dane['przepisy'][0]['tytul']);
        $zip->close();
    }

    public function test_paczka_ma_caly_tekst_przepisu_i_zadnych_cudzych_ani_kontowych_danych(): void
    {
        $basia = $this->user('basia_zakres', ['email' => 'basia-zakres@example.test']);
        $przepis = $this->przepis($basia);
        // Dane innych osób i innych przepisów, które NIE mogą wejść do kopii.
        $obca = $this->user('obca_zakres', ['display_name' => 'Cudza Komentatorka', 'email' => 'cudza-zakres@example.test']);
        Comment::factory()->create(['post_id' => null, 'recipe_id' => $przepis->getKey(), 'author_id' => $obca->getKey(), 'body' => 'Komentarz-obcej-osoby']);
        Recipe::factory()->create(['author_id' => $basia->getKey(), 'title' => 'Inny-wlasny-przepis']);
        $zdjecie = Media::factory()->create(['owner_id' => $basia->getKey()]);
        $przepis->forceFill(['hero_media_id' => $zdjecie->getKey()])->save();
        $przepis->steps()->where('position', 0)->update(['media_id' => $zdjecie->getKey()]);

        $zawartosc = $this->rozpakuj($this->actingAs($basia)->post(route('recipes.copy.download', $przepis->slug)));
        $dane = json_decode($zawartosc['dane.json'], true, 512, JSON_THROW_ON_ERROR);
        $p = $dane['przepisy'][0];

        // Cały tekst: polskie znaki, grupy, „Bez ilości”, zamienniki, etapy, minutniki, pochodzenie.
        $this->assertSame('Pierogi z kapustą', $p['tytul']);
        $this->assertSame('Niedzielne, jak u babci.', $p['krotki_opis']);
        $this->assertEquals(6, $p['porcje']);
        $this->assertSame('Ciasto', $p['skladniki'][0]['grupa']);
        $this->assertSame('500 g mąki', $p['skladniki'][0]['zapis']);
        $this->assertTrue($p['skladniki'][2]['bez_ilosci']);
        $this->assertSame('sól', $p['skladniki'][2]['zapis']);
        $this->assertSame('kapusta kiszona', $p['skladniki'][1]['zamienniki']);
        $this->assertSame('Farsz', $p['kroki'][1]['etap']);
        $this->assertSame(1800, $p['kroki'][0]['minutnik_sekundy']);
        $this->assertSame('od cioci Zosi', $p['od_kogo']);
        $this->assertSame('Zawsze na Wigilię.', $p['notatka_o_zrodle']);
        $this->assertSame(1974, $p['w_rodzinie_od_roku']);
        $this->assertSame([], $dane['wpisy']);
        $this->assertSame([], $dane['kolekcje']);
        $this->assertCount(1, $dane['przepisy']);

        // Zdjęcia: ani ścieżek, ani plików.
        $this->assertNull($p['zdjecie_glowne']);
        $this->assertNull($p['skan_zeszytu']);
        $this->assertNull($p['kroki'][0]['zdjecie']);
        $this->assertCount(3, $zawartosc);

        // Nic spoza przepisu: komentarz, osoba, e-mail, inny przepis, identyfikator zdjęcia.
        $wszystko = implode("\n", $zawartosc);
        foreach (['Komentarz-obcej-osoby', 'Cudza Komentatorka', 'cudza-zakres@example.test', 'basia-zakres@example.test', 'Inny-wlasny-przepis', (string) $zdjecie->getKey()] as $obce) {
            $this->assertStringNotContainsString($obce, $wszystko, "W kopii jednego przepisu jest „{$obce}”.");
        }
        $this->assertArrayNotHasKey('komentarze', $p);
        $this->assertArrayNotHasKey('konto', $dane);
        $this->assertArrayNotHasKey('profil', $dane);
    }

    public function test_czytelna_strona_otwiera_sie_bez_internetu_i_ma_skladniki(): void
    {
        $basia = $this->user('basia_html');
        $przepis = $this->przepis($basia);

        $html = $this->rozpakuj($this->actingAs($basia)->post(route('recipes.copy.download', $przepis->slug)))['przepis.html'];

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $skladniki = array_map(
            static fn ($li): string => trim((string) preg_replace('/\s+/u', ' ', $li->textContent)),
            iterator_to_array($xpath->query('//ul[@class="skladniki"]/li')),
        );
        $this->assertContains('500 g mąki', $skladniki);
        $this->assertStringContainsString('1 główka kapusty kiszonej', implode('|', $skladniki));
        $this->assertSame(2, $xpath->query('//ol[@class="kroki"]/li')->length);
        $this->assertStringContainsString('Skąd: od cioci Zosi', (string) preg_replace('/\s+/u', ' ', $html));

        // Offline: bez zdjęć, bez zasobów z sieci, bez odnośnika do nieistniejącego spisu.
        $this->assertSame(0, $xpath->query('//img')->length);
        $this->assertDoesNotMatchRegularExpression('~(src|href)="https?://~', $html);
        $this->assertStringNotContainsString('index.html', $html);
        $this->assertStringContainsString('To jest kopia jednego Twojego przepisu', $html);
    }

    public function test_cudze_konto_moderator_i_gosc_nie_pobiora_kopii_cudzego_przepisu(): void
    {
        $basia = $this->user('basia_obca');
        $przepis = $this->przepis($basia);
        $obca = $this->user('obca_obca');
        $moderator = $this->user('mod_obca', ['role' => User::ROLE_MODERATOR]);

        foreach ([$obca, $moderator] as $osoba) {
            $this->actingAs($osoba)->get(route('recipes.copy.show', $przepis->slug))->assertForbidden();
            $this->actingAs($osoba)->post(route('recipes.copy.download', $przepis->slug))->assertForbidden();
        }

        // Domena też pilnuje własności, nie tylko Policy.
        $this->expectException(AuthorizationException::class);
        app(KopiaJednegoPrzepisu::class)->zbuduj($obca, $przepis);
    }

    public function test_gosc_jest_odsylany_do_logowania(): void
    {
        $przepis = $this->przepis($this->user('basia_gosc'));

        $this->get(route('recipes.copy.show', $przepis->slug))->assertRedirect(route('login'));
        $this->post(route('recipes.copy.download', $przepis->slug))->assertRedirect(route('login'));
    }

    public function test_autor_pobiera_tez_prywatny_szkic_a_ekran_mowi_co_jest_w_kopii(): void
    {
        $basia = $this->user('basia_szkic');
        $szkic = Recipe::factory()->draft()->create(['author_id' => $basia->getKey(), 'title' => 'Mój prywatny szkic', 'visibility' => 'private']);
        $szkic->ingredients()->create(['ingredient_text' => 'szczypta soli', 'position' => 0]);
        $szkic->steps()->create(['position' => 0, 'instruction' => 'Posól.']);

        $this->actingAs($basia)->get(route('recipes.copy.show', $szkic->slug))
            ->assertOk()
            ->assertSee('Co będzie w pliku')
            ->assertSee('Czego w pliku nie będzie')
            ->assertSee('Zdjęć')
            ->assertSee('Pobierz kopię tego przepisu')
            ->assertSee(route('settings.data.import'), false);

        $dane = json_decode($this->rozpakuj($this->actingAs($basia)->post(route('recipes.copy.download', $szkic->slug)))['dane.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('Mój prywatny szkic', $dane['przepisy'][0]['tytul']);
        $this->assertSame('draft', $dane['przepisy'][0]['status']);
    }

    public function test_przycisk_jest_na_stronie_wlasnego_przepisu_a_na_cudzym_go_nie_ma(): void
    {
        $basia = $this->user('basia_przycisk');
        $przepis = $this->przepis($basia);
        $obca = $this->user('obca_przycisk');

        $this->actingAs($basia)->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertSee('Pobierz kopię przepisu')
            ->assertSee(route('recipes.copy.show', $przepis->slug), false);
        $this->actingAs($obca)->get(route('recipes.show', $przepis->slug))
            ->assertOk()
            ->assertDontSee(route('recipes.copy.show', $przepis->slug), false);
    }

    public function test_obecny_importer_wczytuje_kopie_jako_prywatny_szkic_i_nie_mnozy_duplikatow(): void
    {
        $basia = $this->user('basia_import');
        $przepis = $this->przepis($basia);
        $sciezka = $this->zapiszZip($this->actingAs($basia)->post(route('recipes.copy.download', $przepis->slug)));

        // Wczytanie na INNE konto: stara własność, widoczność i uprawnienia nie wracają z pliku.
        $nowa = $this->user('nowa_import');
        $podglad = app(PodgladPaczkiEksportu::class)->czytaj($nowa, $sciezka);

        $this->assertCount(1, $podglad->przepisy);
        $this->assertSame([], $podglad->wpisy);
        $this->assertSame([], $podglad->zeszyty);
        $pozycja = $podglad->przepisy[0];
        $this->assertSame(PozycjaPodgladu::NOWA, $pozycja->stan);
        $this->assertSame('Pierogi z kapustą', $pozycja->tytul);

        $wynik = app(WczytajPaczke::class)->handle($nowa, $podglad, [$pozycja->odcisk]);
        $this->assertSame(1, $wynik->utworzone['przepis'] ?? null);

        $wczytany = Recipe::query()->where('author_id', $nowa->getKey())->firstOrFail();
        $this->assertSame(Recipe::STATUS_DRAFT, $wczytany->status);
        $this->assertSame('private', $wczytany->visibility);
        $this->assertNull($wczytany->published_at);
        $this->assertSame('Pierogi z kapustą', $wczytany->title);
        $this->assertSame(['500 g mąki', '1 główka kapusty kiszonej', 'sól'], $wczytany->ingredients()->orderBy('position')->pluck('ingredient_text')->all());
        $this->assertSame('Ciasto', $wczytany->ingredients()->orderBy('position')->first()->group_name);
        $this->assertSame(['Zagnieć ciasto.', 'Zrób farsz.'], $wczytany->steps()->orderBy('position')->pluck('instruction')->all());
        $this->assertSame('Farsz', $wczytany->steps()->orderBy('position')->get()[1]->section_name);
        // Autor oryginału jest nietknięty.
        $this->assertSame($basia->getKey(), $przepis->fresh()->author_id);

        // Ponowne wczytanie tej samej kopii niczego nie mnoży.
        $drugi = app(PodgladPaczkiEksportu::class)->czytaj($nowa, $sciezka);
        $this->assertSame(PozycjaPodgladu::JUZ_JEST, $drugi->przepisy[0]->stan);
        app(WczytajPaczke::class)->handle($nowa, $drugi, [$drugi->przepisy[0]->odcisk]);
        $this->assertSame(1, Recipe::query()->where('author_id', $nowa->getKey())->count());
    }

    public function test_uszkodzona_paczka_jest_odrzucana_z_komunikatem(): void
    {
        $basia = $this->user('basia_uszkodzona');
        $sciezka = $this->zapiszZip($this->actingAs($basia)->post(route('recipes.copy.download', $this->przepis($basia)->slug)));
        file_put_contents($sciezka, substr((string) file_get_contents($sciezka), 0, 60));

        $this->expectException(PaczkaOdrzucona::class);
        app(PodgladPaczkiEksportu::class)->czytaj($basia, $sciezka);
    }

    public function test_pola_przepisu_sa_te_same_co_w_pelnej_paczce_konta(): void
    {
        $basia = $this->user('basia_wspolne');
        $przepis = $this->przepis($basia);

        $pelna = app(CollectUserExportData::class)
            ->handle($basia->fresh(), new ExportPhotoPlan($basia->fresh()), Carbon::now());
        $wPelnej = collect($pelna['przepisy'])->firstWhere('adres_w_serwisie', $przepis->slug);

        $dane = json_decode($this->rozpakuj($this->actingAs($basia)->post(route('recipes.copy.download', $przepis->slug)))['dane.json'], true, 512, JSON_THROW_ON_ERROR);
        $wKopii = $dane['przepisy'][0];

        $this->assertNotNull($wPelnej, 'Kontrola: pełna paczka powinna mieć ten przepis.');
        // Jedyna różnica to nazwa pliku do czytania (inne położenie w archiwum).
        foreach ($wKopii as $klucz => $wartosc) {
            if ($klucz === 'plik_do_czytania') {
                continue;
            }
            $this->assertArrayHasKey($klucz, $wPelnej, "Pole „{$klucz}” jest w kopii, a nie ma go w pełnej paczce.");
            $this->assertEquals($wPelnej[$klucz], $wartosc, "Pole „{$klucz}” różni się między kopią a pełną paczką.");
        }
        // Pełna paczka ma więcej (komentarze, wykonania przez innych) — kopia nie.
        $this->assertArrayHasKey('komentarze', $wPelnej);
        $this->assertArrayNotHasKey('komentarze', $wKopii);
    }

    public function test_plik_tymczasowy_znika_po_wyslaniu(): void
    {
        $basia = $this->user('basia_tmp');
        $odpowiedz = $this->actingAs($basia)->post(route('recipes.copy.download', $this->przepis($basia)->slug));
        $sciezka = $this->zapiszZip($odpowiedz);
        $this->assertFileExists($sciezka);

        $plikowa = $odpowiedz->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $plikowa);
        ob_start();
        $plikowa->sendContent();
        ob_end_clean();

        $this->assertFileDoesNotExist($sciezka, 'Plik tymczasowy kopii został po wysłaniu.');
    }

    // -----------------------------------------------------------------

    private function przepis(User $autor): Recipe
    {
        $przepis = Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Pierogi z kapustą',
            'summary' => 'Niedzielne, jak u babci.',
            'servings' => 6,
            'source_type' => Recipe::SOURCE_FAMILY,
            'source_person' => 'od cioci Zosi',
            'source_note' => 'Zawsze na Wigilię.',
            'family_since_year' => 1974,
        ]);
        $przepis->ingredients()->create(['ingredient_text' => '500 g mąki', 'group_name' => 'Ciasto', 'position' => 0]);
        $przepis->ingredients()->create(['ingredient_text' => '1 główka kapusty kiszonej', 'group_name' => 'Farsz', 'substitutes' => 'kapusta kiszona', 'position' => 1]);
        $przepis->ingredients()->create(['ingredient_text' => 'sól', 'group_name' => 'Farsz', 'no_amount' => true, 'position' => 2]);
        $przepis->steps()->create(['position' => 0, 'instruction' => 'Zagnieć ciasto.', 'timer_seconds' => 1800]);
        $przepis->steps()->create(['position' => 1, 'instruction' => 'Zrób farsz.', 'section_name' => 'Farsz']);

        return $przepis;
    }

    /** @return array<string, string> nazwa pliku => zawartość */
    private function rozpakuj(TestResponse $odpowiedz): array
    {
        $odpowiedz->assertOk();
        $sciezka = $this->zapiszZip($odpowiedz);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($sciezka) === true);
        $wynik = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $wynik[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();

        return $wynik;
    }

    private function zapiszZip(TestResponse $odpowiedz): string
    {
        $odpowiedz->assertOk();
        $plikowa = $odpowiedz->baseResponse;
        $this->assertInstanceOf(BinaryFileResponse::class, $plikowa);
        $sciezka = $plikowa->getFile()->getPathname();
        $this->pliki[] = $sciezka;

        return $sciezka;
    }
}

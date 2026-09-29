<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Users\Exports\WersjaFormatuPaczki;
use App\Domain\Users\Import\PaczkaOdrzucona;
use App\Domain\Users\Import\PodgladPaczki;
use App\Domain\Users\Import\PodgladPaczkiEksportu;
use App\Domain\Users\Import\PozycjaPodgladu;
use App\Jobs\GenerateUserExport;
use App\Models\Collection;
use App\Models\DataExport;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

/**
 * Podgląd importu własnej paczki eksportu (issue #1985, etap 1) — czytanie bez zapisu.
 *
 * Paczka jest daną niezaufaną: test sprawdza prawdziwy eksport (obieg pełny),
 * paczki ręcznie zmodyfikowane, przekroczone granice, własność treści, domyślną
 * prywatność i to, że podgląd niczego nie zapisuje.
 */
class PodgladPaczkiEksportuTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $pliki = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');

        config(['kuking.exports.disk' => 'local', 'kuking.exports.ttl_days' => 7]);
    }

    protected function tearDown(): void
    {
        foreach ($this->pliki as $plik) {
            if (is_file($plik)) {
                unlink($plik);
            }
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Prawdziwa paczka z eksportu
    // -----------------------------------------------------------------

    public function test_prawdziwy_eksport_jest_czytany_a_eksport_niesie_numer_formatu(): void
    {
        $basia = $this->user('basia');
        $this->przepisZeSkladnikami($basia, 'Rosół z kury');
        Post::factory()->for($basia, 'author')->create(['body' => 'Dzisiejszy obiad u Basi.']);

        $sciezka = $this->sciezkaEksportu($basia);

        $podglad = (new PodgladPaczkiEksportu)->czytaj($this->user('zenek'), $sciezka);

        $this->assertSame(WersjaFormatuPaczki::AKTUALNA, $podglad->wersjaFormatu);
        $this->assertSame(
            WersjaFormatuPaczki::AKTUALNA,
            $this->daneZPaczki($sciezka)['o_tym_pliku']['wersja_formatu'],
        );
        $this->assertNotNull($podglad->wygenerowano);
        $this->assertSame(['Rosół z kury'], array_map(fn ($p) => $p->tytul, $podglad->przepisy));
        $this->assertCount(1, $podglad->wpisy);
        $this->assertSame('Dzisiejszy obiad u Basi.', $podglad->wpisy[0]->tytul);
        $this->assertNotEmpty($podglad->pominiete);
    }

    public function test_paczka_wczytana_na_inne_konto_daje_same_nowe_pozycje_bez_zapisu(): void
    {
        $basia = $this->user('basia');
        $this->przepisZeSkladnikami($basia, 'Rosół z kury');
        Post::factory()->for($basia, 'author')->create(['body' => 'Dzisiejszy obiad u Basi.']);
        $sciezka = $this->sciezkaEksportu($basia);

        $zenek = $this->user('zenek');
        $przedPrzepisy = Recipe::query()->count();
        $przedWpisy = Post::query()->count();
        $przedZeszyty = Collection::query()->count();
        $przedProfile = DB::table('profiles')->count();

        $podglad = (new PodgladPaczkiEksportu)->czytaj($zenek, $sciezka);

        $this->assertSame(PozycjaPodgladu::NOWA, $podglad->przepisy[0]->stan);
        $this->assertSame(PozycjaPodgladu::NOWA, $podglad->wpisy[0]->stan);

        // Podgląd niczego nie zapisuje.
        $this->assertSame($przedPrzepisy, Recipe::query()->count());
        $this->assertSame($przedWpisy, Post::query()->count());
        $this->assertSame($przedZeszyty, Collection::query()->count());
        $this->assertSame($przedProfile, DB::table('profiles')->count());
        $this->assertSame(0, Recipe::query()->where('author_id', $zenek->getKey())->count());
    }

    public function test_te_same_tresci_na_koncie_wlasciciela_sa_konfliktem_a_nie_nowoscia(): void
    {
        $basia = $this->user('basia');
        $this->przepisZeSkladnikami($basia, 'Rosół z kury');
        Post::factory()->for($basia, 'author')->create(['body' => 'Dzisiejszy obiad u Basi.']);
        $sciezka = $this->sciezkaEksportu($basia);

        $podglad = (new PodgladPaczkiEksportu)->czytaj($basia, $sciezka);

        $this->assertSame(PozycjaPodgladu::JUZ_JEST, $podglad->przepisy[0]->stan);
        $this->assertSame(PozycjaPodgladu::JUZ_JEST, $podglad->wpisy[0]->stan);
        $this->assertFalse($podglad->przepisy[0]->mozeBycUtworzona());
    }

    public function test_paczka_bez_numeru_formatu_z_czasow_sprzed_numerowania_jest_wersja_pierwsza(): void
    {
        $dane = $this->szkieletPaczki();
        unset($dane['o_tym_pliku']['wersja_formatu']);

        $podglad = (new PodgladPaczkiEksportu)->czytaj($this->user('zenek'), $this->zip(['dane.json' => json_encode($dane, JSON_THROW_ON_ERROR)]));

        $this->assertSame(WersjaFormatuPaczki::SPRZED_NUMEROWANIA, $podglad->wersjaFormatu);
    }

    // -----------------------------------------------------------------
    // Cała paczka odrzucona
    // -----------------------------------------------------------------

    public function test_plik_ktory_nie_jest_zip_jest_odrzucony_po_polsku(): void
    {
        $sciezka = $this->plik('to nie jest zip');

        $this->assertOdrzucona(PaczkaOdrzucona::NIE_ZIP, $sciezka);
    }

    public function test_brak_pliku_jest_odrzucony_tak_samo_jak_nie_zip(): void
    {
        $this->assertOdrzucona(PaczkaOdrzucona::NIE_ZIP, sys_get_temp_dir().'/kuking-nie-ma-takiego-pliku.zip');
    }

    public function test_archiwum_bez_dane_json_jest_odrzucone(): void
    {
        $this->assertOdrzucona(PaczkaOdrzucona::BRAK_DANYCH, $this->zip(['zdjecia/rosol.webp' => 'x']));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function niebezpieczneNazwy(): array
    {
        return [
            'wyjście katalog wyżej' => ['../poza.txt'],
            'wyjście w środku ścieżki' => ['zdjecia/../../poza.txt'],
            'ścieżka bezwzględna' => ['/etc/cron.d/poza'],
            'ukośnik odwrotny' => ['zdjecia\\..\\poza.txt'],
            'litera dysku' => ['C:/poza.txt'],
        ];
    }

    #[DataProvider('niebezpieczneNazwy')]
    public function test_nazwa_wychodzaca_poza_katalog_odrzuca_cala_paczke_mimo_poprawnych_danych(string $nazwa): void
    {
        $sciezka = $this->zip([
            'dane.json' => json_encode($this->szkieletPaczki(), JSON_THROW_ON_ERROR),
            $nazwa => 'zlosliwa tresc',
        ]);

        $this->assertOdrzucona(PaczkaOdrzucona::NIEBEZPIECZNA_NAZWA, $sciezka);
    }

    public function test_dwa_pliki_dane_json_w_jednym_archiwum_sa_odrzucone(): void
    {
        $sciezka = $this->plik('');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($sciezka, ZipArchive::OVERWRITE | ZipArchive::CREATE));
        $json = json_encode($this->szkieletPaczki(), JSON_THROW_ON_ERROR);
        $zip->addFromString('dane.json', $json);
        $zip->addFromString('dane.json', $json, ZipArchive::FL_ENC_UTF_8);
        $zip->close();

        // libzip przy tej samej nazwie zastępuje wpis — jeśli biblioteka kiedyś
        // zacznie trzymać oba, podgląd ma je odrzucić; dziś ma wczytać jeden.
        $zip = new ZipArchive;
        $zip->open($sciezka);
        $ile = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $ile += $zip->getNameIndex($i) === 'dane.json' ? 1 : 0;
        }
        $zip->close();

        if ($ile > 1) {
            $this->assertOdrzucona(PaczkaOdrzucona::NIEBEZPIECZNA_NAZWA, $sciezka);
        } else {
            $this->assertInstanceOf(PodgladPaczki::class, (new PodgladPaczkiEksportu)->czytaj($this->user('zenek'), $sciezka));
        }
    }

    public function test_za_duzy_plik_z_danymi_jest_odrzucony_bez_wczytywania_do_pamieci(): void
    {
        // Sto tysięcy bajtów ponad sufit; skompresowane do kilkudziesięciu kilobajtów.
        $sciezka = $this->zip(['dane.json' => str_repeat(' ', PodgladPaczkiEksportu::MAX_DANE_BAJTOW + 100_000)]);

        $this->assertLessThan(200_000, filesize($sciezka));
        $this->assertOdrzucona(PaczkaOdrzucona::ZA_DUZE_DANE, $sciezka);
    }

    public function test_za_duzo_plikow_w_archiwum_jest_odrzucone(): void
    {
        $pliki = ['dane.json' => json_encode($this->szkieletPaczki(), JSON_THROW_ON_ERROR)];
        for ($i = 0; $i <= PodgladPaczkiEksportu::MAX_PLIKOW; $i++) {
            $pliki["z/{$i}"] = '';
        }

        $this->assertOdrzucona(PaczkaOdrzucona::ZA_DUZO_PLIKOW, $this->zip($pliki));
    }

    public function test_uszkodzony_json_jest_odrzucony(): void
    {
        $this->assertOdrzucona(PaczkaOdrzucona::NIE_JSON, $this->zip(['dane.json' => '{"o_tym_pliku": ']));
    }

    public function test_json_z_nieprawidlowym_utf8_jest_odrzucony(): void
    {
        $this->assertOdrzucona(PaczkaOdrzucona::NIE_JSON, $this->zip(['dane.json' => "{\"a\": \"\xC3\x28\"}"]));
    }

    public function test_zbyt_zaglebiony_json_jest_odrzucony(): void
    {
        $gleboki = str_repeat('[', PodgladPaczkiEksportu::MAX_GLEBOKOSC_JSON + 5).str_repeat(']', PodgladPaczkiEksportu::MAX_GLEBOKOSC_JSON + 5);

        $this->assertOdrzucona(PaczkaOdrzucona::ZLA_STRUKTURA, $this->zip(['dane.json' => '{"o_tym_pliku":'.$gleboki.'}']));
    }

    public function test_json_ktory_nie_jest_obiektem_jest_odrzucony(): void
    {
        $this->assertOdrzucona(PaczkaOdrzucona::ZLA_STRUKTURA, $this->zip(['dane.json' => '[1,2,3]']));
    }

    public function test_plik_z_innego_serwisu_jest_odrzucony(): void
    {
        $dane = $this->szkieletPaczki();
        $dane['o_tym_pliku']['serwis'] = 'Garnek.pl';

        $this->assertOdrzucona(PaczkaOdrzucona::NIE_Z_KUKING, $this->zip(['dane.json' => json_encode($dane, JSON_THROW_ON_ERROR)]));
    }

    public function test_nowszy_format_niz_rozumiemy_jest_odrzucony(): void
    {
        $dane = $this->szkieletPaczki();
        $dane['o_tym_pliku']['wersja_formatu'] = WersjaFormatuPaczki::AKTUALNA + 1;

        $this->assertOdrzucona(PaczkaOdrzucona::NOWSZY_FORMAT, $this->zip(['dane.json' => json_encode($dane, JSON_THROW_ON_ERROR)]));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function zleNumeryFormatu(): array
    {
        return ['napis' => ['1'], 'zero' => [0], 'ujemny' => [-1], 'ułamek' => [1.5], 'tablica' => [[1]]];
    }

    #[DataProvider('zleNumeryFormatu')]
    public function test_numer_formatu_ktory_nie_jest_dodatnia_liczba_calkowita_jest_odrzucony(mixed $numer): void
    {
        $dane = $this->szkieletPaczki();
        $dane['o_tym_pliku']['wersja_formatu'] = $numer;

        $this->assertOdrzucona(PaczkaOdrzucona::ZLA_STRUKTURA, $this->zip(['dane.json' => json_encode($dane, JSON_THROW_ON_ERROR)]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function brakujaceSekcje(): array
    {
        return ['przepisy' => ['przepisy'], 'wpisy' => ['wpisy'], 'kolekcje' => ['kolekcje']];
    }

    #[DataProvider('brakujaceSekcje')]
    public function test_brak_sekcji_to_uszkodzona_paczka_a_nie_puste_konto(string $sekcja): void
    {
        $dane = $this->szkieletPaczki();
        unset($dane[$sekcja]);

        $this->assertOdrzucona(PaczkaOdrzucona::ZLA_STRUKTURA, $this->zip(['dane.json' => json_encode($dane, JSON_THROW_ON_ERROR)]));
    }

    public function test_sekcja_ktora_nie_jest_lista_jest_odrzucona(): void
    {
        $dane = $this->szkieletPaczki();
        $dane['przepisy'] = ['a' => 'b'];

        $this->assertOdrzucona(PaczkaOdrzucona::ZLA_STRUKTURA, $this->zip(['dane.json' => json_encode($dane, JSON_THROW_ON_ERROR)]));
    }

    public function test_za_duzo_pozycji_w_sekcji_jest_odrzucone(): void
    {
        $dane = $this->szkieletPaczki();
        $dane['wpisy'] = array_fill(0, PodgladPaczkiEksportu::MAX_POZYCJI + 1, []);

        $this->assertOdrzucona(PaczkaOdrzucona::ZA_DUZO_POZYCJI, $this->zip(['dane.json' => json_encode($dane, JSON_THROW_ON_ERROR)]));
    }

    public function test_komunikaty_odmowy_nie_zawieraja_tresci_z_pliku_ani_sciezki(): void
    {
        $dane = $this->szkieletPaczki();
        $dane['o_tym_pliku']['serwis'] = 'TAJNA-TRESC-Z-PLIKU';
        $sciezka = $this->zip(['dane.json' => json_encode($dane, JSON_THROW_ON_ERROR)]);

        try {
            (new PodgladPaczkiEksportu)->czytaj($this->user('zenek'), $sciezka);
            $this->fail('Paczka z obcego serwisu powinna zostać odrzucona.');
        } catch (PaczkaOdrzucona $e) {
            $this->assertStringNotContainsString('TAJNA-TRESC-Z-PLIKU', $e->getMessage());
            $this->assertStringNotContainsString($sciezka, $e->getMessage());
        }
    }

    // -----------------------------------------------------------------
    // Pozycje: granice pól, moderacja, prywatność, konflikty
    // -----------------------------------------------------------------

    public function test_przepis_z_polami_ponad_granice_formularza_jest_odrzucony_z_powodem_a_reszta_dziala(): void
    {
        $dane = $this->szkieletPaczki();
        $dane['przepisy'] = [
            $this->przepis('Dobry przepis'),
            $this->przepis(str_repeat('T', 181)),
            $this->przepis('Za długi składnik', ['skladniki' => [['zapis' => str_repeat('s', 241)]]]),
            $this->przepis('Za długi krok', ['kroki' => [['numer' => 1, 'opis' => str_repeat('k', 4001)]]]),
            $this->przepis('Za dużo składników', ['skladniki' => array_fill(0, Recipe::MAX_INGREDIENTS + 1, ['zapis' => '1 jajko'])]),
            $this->przepis('Za dużo kroków', ['kroki' => array_fill(0, Recipe::MAX_STEPS + 1, ['opis' => 'Wymieszaj.'])]),
            $this->przepis('Pusty składnik', ['skladniki' => [['zapis' => '   ']]]),
            $this->przepis('Bajt zerowy', ['krotki_opis' => "ala\0ma"]),
            'to nie jest przepis',
        ];

        $podglad = $this->podglad($dane);

        $stany = array_map(fn ($p) => $p->stan, $podglad->przepisy);
        $this->assertSame([PozycjaPodgladu::NOWA, ...array_fill(0, 8, PozycjaPodgladu::ODRZUCONA)], $stany);

        foreach (array_slice($podglad->przepisy, 1) as $odrzucona) {
            $this->assertNotEmpty($odrzucona->powod, "Brak powodu przy: {$odrzucona->tytul}");
        }
    }

    public function test_przepis_ukryty_lub_usuniety_przez_moderacje_nie_wraca_przez_import(): void
    {
        $dane = $this->szkieletPaczki();
        $dane['przepisy'] = [
            $this->przepis('Ukryty', ['status' => Recipe::STATUS_HIDDEN]),
            $this->przepis('Usunięty', ['status' => Recipe::STATUS_REMOVED]),
            $this->przepis('Szkic', ['status' => Recipe::STATUS_DRAFT, 'widocznosc' => 'private']),
        ];
        $dane['wpisy'] = [
            $this->wpis(['tresc' => 'Ukryty wpis', 'status' => Post::STATUS_HIDDEN]),
            $this->wpis(['tresc' => 'Usunięty wpis', 'status' => Post::STATUS_REMOVED]),
        ];

        $podglad = $this->podglad($dane);

        $this->assertSame(
            [PozycjaPodgladu::ODRZUCONA, PozycjaPodgladu::ODRZUCONA, PozycjaPodgladu::NOWA],
            array_map(fn ($p) => $p->stan, $podglad->przepisy),
        );
        $this->assertSame(
            [PozycjaPodgladu::ODRZUCONA, PozycjaPodgladu::ODRZUCONA],
            array_map(fn ($p) => $p->stan, $podglad->wpisy),
        );
        $this->assertStringContainsString('moderację', (string) $podglad->przepisy[0]->powod);
    }

    public function test_publiczna_tresc_z_paczki_jest_w_podgladzie_oznaczona_jako_prywatna_po_wczytaniu(): void
    {
        $dane = $this->szkieletPaczki();
        $dane['przepisy'] = [$this->przepis('Publiczny', ['status' => Recipe::STATUS_PUBLISHED, 'widocznosc' => 'public'])];
        $dane['wpisy'] = [$this->wpis(['tresc' => 'Publiczny wpis', 'status' => Post::STATUS_PUBLISHED, 'widocznosc' => 'public'])];
        $dane['kolekcje'] = [['nazwa' => 'Publiczny zeszyt', 'opis' => null, 'widocznosc' => 'public', 'domyslna' => false, 'przepisy' => [], 'wpisy' => []]];

        $podglad = $this->podglad($dane);

        $this->assertStringContainsString('prywatnym szkicem', implode(' ', $podglad->przepisy[0]->uwagi));
        $this->assertStringContainsString('prywatny', implode(' ', $podglad->wpisy[0]->uwagi));
        $this->assertStringContainsString('prywatny', implode(' ', $podglad->zeszyty[0]->uwagi));
        $this->assertStringContainsString('prywatne', implode(' ', $podglad->pominiete));
    }

    public function test_zdjecia_nie_sa_wczytywane_i_podglad_mowi_o_tym_przy_pozycji(): void
    {
        $dane = $this->szkieletPaczki();
        $dane['przepisy'] = [$this->przepis('Ze zdjęciem', ['zdjecie_glowne' => 'zdjecia/rosol.webp'])];
        $dane['wpisy'] = [$this->wpis(['tresc' => 'Wpis ze zdjęciem', 'zdjecia' => ['zdjecia/obiad.webp']])];

        $podglad = $this->podglad($dane);

        $this->assertStringContainsString('Zdjęć nie wczytujemy', implode(' ', $podglad->przepisy[0]->uwagi));
        $this->assertStringContainsString('Zdjęć nie wczytujemy', implode(' ', $podglad->wpisy[0]->uwagi));
        $this->assertSame(PozycjaPodgladu::NOWA, $podglad->przepisy[0]->stan, 'Brak zdjęcia nie blokuje importu tekstu.');
    }

    public function test_wpis_ze_samym_zdjeciem_oraz_zle_pytania_sa_odrzucone(): void
    {
        $dane = $this->szkieletPaczki();
        $dane['wpisy'] = [
            $this->wpis(['tresc' => null, 'zdjecia' => ['zdjecia/obiad.webp']]),
            $this->wpis(['rodzaj' => Post::KIND_QUESTION, 'tytul' => 'Za krótko', 'tresc' => null]),
            $this->wpis(['rodzaj' => Post::KIND_QUESTION, 'tytul' => 'Jak długo pieczesz sernik?', 'tresc' => null]),
            $this->wpis(['rodzaj' => 'nieznany', 'tresc' => 'coś']),
            $this->wpis(['tresc' => str_repeat('a', 4001)]),
        ];

        $podglad = $this->podglad($dane);

        $this->assertSame(
            [
                PozycjaPodgladu::ODRZUCONA,
                PozycjaPodgladu::ODRZUCONA,
                PozycjaPodgladu::NOWA,
                PozycjaPodgladu::ODRZUCONA,
                PozycjaPodgladu::ODRZUCONA,
            ],
            array_map(fn ($p) => $p->stan, $podglad->wpisy),
        );
        $this->assertSame('Jak długo pieczesz sernik?', $podglad->wpisy[2]->tytul);
    }

    public function test_powtorka_w_paczce_i_konflikt_z_kontem_sa_rozroznione_a_ten_sam_tytul_z_inna_trescia_to_nie_duplikat(): void
    {
        $zenek = $this->user('zenek');
        Recipe::factory()->for($zenek, 'author')->create(['title' => 'Sernik babci']);
        Collection::query()->create(['owner_id' => $zenek->getKey(), 'name' => 'Na święta', 'visibility' => 'private']);

        $dane = $this->szkieletPaczki();
        $dane['przepisy'] = [
            $this->przepis('Sernik babci'),
            $this->przepis('Pierogi'),
            $this->przepis('Pierogi'),
            $this->przepis('Pierogi', ['skladniki' => [['zapis' => '1 kg mąki']]]),
        ];
        $dane['kolekcje'] = [
            ['nazwa' => 'na święta', 'opis' => null, 'widocznosc' => 'private', 'domyslna' => false],
            ['nazwa' => 'Na lato', 'opis' => 'Sałatki', 'widocznosc' => 'private', 'domyslna' => false],
            ['nazwa' => 'Na LATO', 'opis' => null, 'widocznosc' => 'private', 'domyslna' => false],
            ['nazwa' => 'Zeszyt', 'opis' => null, 'widocznosc' => 'private', 'domyslna' => true],
        ];

        $podglad = $this->podglad($dane, $zenek);

        $this->assertSame(
            [PozycjaPodgladu::JUZ_JEST, PozycjaPodgladu::NOWA, PozycjaPodgladu::POWTORZONA_W_PACZCE, PozycjaPodgladu::NOWA],
            array_map(fn ($p) => $p->stan, $podglad->przepisy),
        );
        $this->assertSame(
            [PozycjaPodgladu::JUZ_JEST, PozycjaPodgladu::NOWA, PozycjaPodgladu::POWTORZONA_W_PACZCE, PozycjaPodgladu::JUZ_JEST],
            array_map(fn ($p) => $p->stan, $podglad->zeszyty),
        );
    }

    public function test_przepis_skasowany_przez_wlasciciela_nie_jest_konfliktem(): void
    {
        $zenek = $this->user('zenek');
        Recipe::factory()->for($zenek, 'author')->create(['title' => 'Sernik babci'])->delete();

        $dane = $this->szkieletPaczki();
        $dane['przepisy'] = [$this->przepis('Sernik babci')];

        $this->assertSame(PozycjaPodgladu::NOWA, $this->podglad($dane, $zenek)->przepisy[0]->stan);
    }

    public function test_cudzy_przepis_o_tym_samym_tytule_nie_jest_konfliktem_tej_osoby(): void
    {
        $zenek = $this->user('zenek');
        Recipe::factory()->for($this->user('basia'), 'author')->create(['title' => 'Sernik babci']);

        $dane = $this->szkieletPaczki();
        $dane['przepisy'] = [$this->przepis('Sernik babci')];

        $this->assertSame(PozycjaPodgladu::NOWA, $this->podglad($dane, $zenek)->przepisy[0]->stan);
    }

    public function test_odcisk_jest_trwaly_i_zalezy_od_tresci_a_nie_od_identyfikatorow_z_paczki(): void
    {
        $a = $this->przepis('Sernik', ['adres_w_serwisie' => 'sernik-abc123', 'utworzono' => '2027-01-01T10:00:00+00:00']);
        $b = $this->przepis('Sernik', ['adres_w_serwisie' => 'sernik-zzz999', 'utworzono' => '2028-05-05T10:00:00+00:00']);
        $c = $this->przepis('Sernik', ['skladniki' => [['zapis' => '1 kg twarogu']]]);

        $dane = $this->szkieletPaczki();
        $dane['przepisy'] = [$a, $b, $c];
        $podglad = $this->podglad($dane);

        $this->assertSame($podglad->przepisy[0]->odcisk, $podglad->przepisy[1]->odcisk);
        $this->assertNotSame($podglad->przepisy[0]->odcisk, $podglad->przepisy[2]->odcisk);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $podglad->przepisy[0]->odcisk);
        $this->assertSame($podglad->przepisy[0]->odcisk, $this->podglad($dane)->przepisy[0]->odcisk, 'Odcisk musi być taki sam przy każdym czytaniu.');
    }

    // -----------------------------------------------------------------
    // Własność i pola, których podgląd nie czyta
    // -----------------------------------------------------------------

    public function test_identyfikatory_konta_i_cudze_dane_z_paczki_nie_wplywaja_na_podglad(): void
    {
        $zenek = $this->user('zenek');
        $basia = $this->user('basia');

        $dane = $this->szkieletPaczki();
        $dane['konto'] = ['id' => (string) $basia->getKey(), 'email' => 'basia@example.test'];
        $dane['przepisy'] = [$this->przepis('Sernik', ['id' => (string) $basia->getKey(), 'author_id' => (string) $basia->getKey()])];
        $dane['wpisy'] = [$this->wpis(['tresc' => 'Wpis', 'author_id' => (string) $basia->getKey(), 'komentarze' => [['tresc' => 'Komentarz obcej osoby', 'autor' => 'Ktoś']]])];

        // `basia` ma już taki przepis — gdyby id z pliku miało znaczenie, wynik zależałby od niej.
        Recipe::factory()->for($basia, 'author')->create(['title' => 'Sernik']);

        $podglad = $this->podglad($dane, $zenek);

        $this->assertSame(PozycjaPodgladu::NOWA, $podglad->przepisy[0]->stan);
        $this->assertSame(PozycjaPodgladu::NOWA, $podglad->wpisy[0]->stan);
        $this->assertStringNotContainsString('basia@example.test', serialize($podglad));
        $this->assertStringNotContainsString('Komentarz obcej osoby', serialize($podglad));
    }

    public function test_adres_z_paczki_nie_jest_otwierany_ani_czytany(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $dane = $this->szkieletPaczki();
        $dane['przepisy'] = [$this->przepis('Z sieci', ['zrodlo_adres' => 'http://127.0.0.1:1/admin', 'zdjecie_glowne' => 'https://example.test/x.jpg'])];

        $podglad = $this->podglad($dane);

        $this->assertSame(PozycjaPodgladu::NOWA, $podglad->przepisy[0]->stan);
        Http::assertNothingSent();
        $this->assertStringNotContainsString('127.0.0.1', serialize($podglad));
    }

    // -----------------------------------------------------------------
    // Pomoce
    // -----------------------------------------------------------------

    private function assertOdrzucona(string $kod, string $sciezka): void
    {
        try {
            (new PodgladPaczkiEksportu)->czytaj($this->user(), $sciezka);
            $this->fail("Paczka powinna zostać odrzucona z kodem {$kod}.");
        } catch (PaczkaOdrzucona $e) {
            $this->assertSame($kod, $e->kod);
            $this->assertNotSame('', $e->getMessage());
            $this->assertMatchesRegularExpression('/[ąćęłńóśźż]/u', $e->getMessage(), 'Komunikat ma być po polsku.');
            $this->assertMatchesRegularExpression('/(Wybierz|Pobierz|Napisz)/u', $e->getMessage(), 'Komunikat ma mówić, co zrobić.');
        }
    }

    /** @param array<string, mixed> $dane */
    private function podglad(array $dane, ?User $user = null): PodgladPaczki
    {
        return (new PodgladPaczkiEksportu)->czytaj(
            $user ?? $this->user(),
            $this->zip(['dane.json' => json_encode($dane, JSON_THROW_ON_ERROR)]),
        );
    }

    /** @return array<string, mixed> */
    private function szkieletPaczki(): array
    {
        return [
            'o_tym_pliku' => [
                'serwis' => 'Kuking.pl',
                'wersja_formatu' => WersjaFormatuPaczki::AKTUALNA,
                'wygenerowano' => '2027-03-14T10:00:00+00:00',
            ],
            'przepisy' => [],
            'wpisy' => [],
            'kolekcje' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $nadpisz
     * @return array<string, mixed>
     */
    private function przepis(string $tytul, array $nadpisz = []): array
    {
        return array_merge([
            'tytul' => $tytul,
            'krotki_opis' => 'Krótki opis.',
            'status' => Recipe::STATUS_DRAFT,
            'widocznosc' => 'private',
            'skladniki' => [['grupa' => null, 'zapis' => '2 jajka', 'uwaga' => null, 'zamienniki' => null]],
            'kroki' => [['numer' => 1, 'opis' => 'Wymieszaj.']],
        ], $nadpisz);
    }

    /**
     * @param  array<string, mixed>  $nadpisz
     * @return array<string, mixed>
     */
    private function wpis(array $nadpisz = []): array
    {
        return array_merge([
            'rodzaj' => Post::KIND_DISH,
            'tytul' => null,
            'tresc' => 'Obiad.',
            'widocznosc' => 'private',
            'status' => Post::STATUS_DRAFT,
        ], $nadpisz);
    }

    private function przepisZeSkladnikami(User $autor, string $tytul): Recipe
    {
        $przepis = Recipe::factory()->for($autor, 'author')->create(['title' => $tytul]);
        $przepis->ingredients()->create(['ingredient_text' => '2 marchewki', 'position' => 0]);
        $przepis->steps()->create(['position' => 0, 'instruction' => 'Gotuj godzinę.']);

        return $przepis;
    }

    private function sciezkaEksportu(User $user): string
    {
        $export = DataExport::create(['user_id' => $user->getKey(), 'status' => DataExport::STATUS_QUEUED]);

        (new GenerateUserExport((string) $export->getKey()))->handle();

        $export->refresh();

        return Storage::disk((string) $export->disk)->path((string) $export->object_key);
    }

    /** @return array<string, mixed> */
    private function daneZPaczki(string $sciezka): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($sciezka) === true);
        $json = $zip->getFromName('dane.json');
        $zip->close();

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, string> $zawartosc */
    private function zip(array $zawartosc): string
    {
        $sciezka = $this->plik('');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($sciezka, ZipArchive::OVERWRITE | ZipArchive::CREATE) === true);

        foreach ($zawartosc as $nazwa => $tresc) {
            $zip->addFromString($nazwa, $tresc);
        }

        $this->assertTrue($zip->close());

        return $sciezka;
    }

    private function plik(string $tresc): string
    {
        $sciezka = tempnam(sys_get_temp_dir(), 'kuking-paczka-');
        $this->assertIsString($sciezka);
        file_put_contents($sciezka, $tresc);
        $this->pliki[] = $sciezka;

        return $sciezka;
    }
}

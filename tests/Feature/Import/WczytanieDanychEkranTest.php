<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Users\Exports\WersjaFormatuPaczki;
use App\Domain\Users\Import\MagazynPaczek;
use App\Domain\Users\Import\PodgladPaczkiEksportu;
use App\Models\Collection;
use App\Models\Post;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WczytanaZPaczki;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

/**
 * Ekran wczytywania własnej paczki (issue #1985, etap 2): wybór → podgląd → zapis.
 */
class WczytanieDanychEkranTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $pliki = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
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

    public function test_gosc_jest_odsylany_do_logowania(): void
    {
        $this->get(route('settings.data.import'))->assertRedirect(route('login'));
        $this->post(route('settings.data.import.check'))->assertRedirect(route('login'));
    }

    public function test_ekran_wyboru_mowi_ze_nic_nie_zapisze_sie_bez_zgody_i_ze_wszystko_bedzie_prywatne(): void
    {
        $this->actingAs($this->user('zenek'))
            ->get(route('settings.data.import'))
            ->assertOk()
            ->assertSee('Nic nie zapiszemy, dopóki nie klikniesz „Wczytaj zaznaczone”', false)
            ->assertSee('prywatne', false)
            ->assertSee('Pokaż, co jest w paczce');
    }

    public function test_ekran_danych_prowadzi_do_wczytania(): void
    {
        $this->actingAs($this->user('zenek'))
            ->get(route('settings.data'))
            ->assertOk()
            ->assertSee(route('settings.data.import'), false);
    }

    public function test_zawieszone_konto_nie_wchodzi_na_ekran(): void
    {
        $zenek = $this->user('zenek');
        $zenek->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->actingAs($zenek)->get(route('settings.data.import'))->assertForbidden();
    }

    public function test_brak_pliku_daje_polski_komunikat_z_instrukcja(): void
    {
        $this->actingAs($this->user('zenek'))
            ->from(route('settings.data.import'))
            ->post(route('settings.data.import.check'), [])
            ->assertRedirect(route('settings.data.import'))
            ->assertSessionHasErrors(['plik' => 'Wybierz plik ZIP z paczką danych przyciskiem „Wybierz plik”.']);
    }

    public function test_plik_ktory_nie_jest_paczka_jest_odrzucony_i_nie_trafia_do_poczekalni(): void
    {
        $plik = UploadedFile::fake()->createWithContent('cos.zip', 'to nie jest zip');

        $this->actingAs($this->user('zenek'))
            ->from(route('settings.data.import'))
            ->post(route('settings.data.import.check'), ['plik' => $plik])
            ->assertRedirect(route('settings.data.import'))
            ->assertSessionHasErrors('plik');

        $this->assertSame([], Storage::disk('local')->allFiles('import-paczek'));
        $this->assertSame(0, Recipe::query()->count());
    }

    public function test_za_duzy_plik_jest_odrzucony_z_granica_w_komunikacie(): void
    {
        config(['kuking.import_paczki.max_kb' => 1]);
        $plik = UploadedFile::fake()->create('paczka.zip', 5);

        $this->actingAs($this->user('zenek'))
            ->post(route('settings.data.import.check'), ['plik' => $plik])
            ->assertSessionHasErrors(['plik' => 'Ten plik jest za duży. Wybierz paczkę mniejszą niż 0 MB.']);
    }

    public function test_dane_tuz_ponad_sufitem_daja_komunikat_a_nie_blad_serwera(): void
    {
        // Tuż ponad sufitem bajtów człowiek ma dostać polski komunikat z instrukcją,
        // a nie fatal 500 bez słowa.
        $sciezka = tempnam(sys_get_temp_dir(), 'kuking-sufit-');
        $this->assertIsString($sciezka);
        $this->pliki[] = $sciezka;
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($sciezka, ZipArchive::OVERWRITE | ZipArchive::CREATE) === true);
        $zip->addFromString('dane.json', str_repeat(' ', PodgladPaczkiEksportu::MAX_DANE_BAJTOW + 1));
        $this->assertTrue($zip->close());

        $this->actingAs($this->user('zenek'))
            ->from(route('settings.data.import'))
            ->post(route('settings.data.import.check'), ['plik' => new UploadedFile($sciezka, 'paczka.zip', 'application/zip', null, true)])
            ->assertRedirect(route('settings.data.import'))
            ->assertSessionHasErrors(['plik' => 'Plik z danymi w tym archiwum jest większy niż 12 MB, a tyle potrafimy wczytać naraz. Napisz do nas przez formularz kontaktowy, a pomożemy przenieść dane w częściach.']);

        $this->assertSame([], Storage::disk('local')->allFiles('import-paczek'));
    }

    public function test_sufit_bajtow_i_budzet_struktury_sa_jawne_przy_limicie_pamieci_php(): void
    {
        // Rzeczywiste wykonanie parsera w 256M mierzy BudzetStrukturyPaczkiTest.
        $this->assertSame(12 * 1024 * 1024, PodgladPaczkiEksportu::MAX_DANE_BAJTOW);
        $this->assertSame(150_000, PodgladPaczkiEksportu::MAX_KONTENEROW_JSON);
        $this->assertSame(1_000_000, PodgladPaczkiEksportu::MAX_SEPARATOROW_JSON);
        $this->assertStringContainsString('memory_limit=256M', (string) file_get_contents(base_path('docker/php.ini')));
    }

    public function test_plaska_bomba_json_w_zip_jest_odrzucona_bez_poczekalni_i_rezerwacji(): void
    {
        $sciezka = tempnam(sys_get_temp_dir(), 'kuking-budzet-');
        $this->assertIsString($sciezka);
        $this->pliki[] = $sciezka;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($sciezka, ZipArchive::OVERWRITE | ZipArchive::CREATE) === true);
        $json = '{"o_tym_pliku":{"serwis":"Kuking.pl","wersja_formatu":1},"przepisy":[],"wpisy":[],"kolekcje":[],"extra":['
            .str_repeat('{"a":0},', 799_999).'{"a":0}]}';
        $this->assertLessThan(PodgladPaczkiEksportu::MAX_DANE_BAJTOW, strlen($json));
        $zip->addFromString('dane.json', $json);
        $this->assertTrue($zip->close());
        unset($json);

        $this->actingAs($this->user('zenek'))
            ->from(route('settings.data.import'))
            ->post(route('settings.data.import.check'), [
                'plik' => new UploadedFile($sciezka, 'paczka.zip', 'application/zip', null, true),
            ])
            ->assertRedirect(route('settings.data.import'))
            ->assertSessionHasErrors(['plik' => 'Ta paczka ma zbyt wiele drobnych części, żeby bezpiecznie ją wczytać. Pobierz paczkę z Kuking jeszcze raz. Jeśli problem się powtórzy, napisz do nas przez formularz kontaktowy.']);

        $this->assertSame([], Storage::disk('local')->allFiles('import-paczek'));
        $this->assertSame(0, WczytanaZPaczki::query()->count());
        $this->assertSame(0, Recipe::query()->count());
        $this->assertSame(0, Post::query()->count());
    }

    public function test_pelny_obieg_podglad_bez_zapisu_potem_zapis_zaznaczonych_i_sprzatanie_pliku(): void
    {
        $zenek = $this->user('zenek');
        $plik = $this->plikPaczki();

        $odpowiedz = $this->actingAs($zenek)->post(route('settings.data.import.check'), ['plik' => $plik]);

        $adres = (string) $odpowiedz->headers->get('Location');
        $this->assertStringContainsString('/ustawienia/twoje-dane/wczytaj/', $adres);

        // Podgląd: pokazuje pozycje, NICZEGO nie zapisuje.
        $this->actingAs($zenek)->get($adres)
            ->assertOk()
            ->assertSee('Paczka przygotowana 14 marca 2027, 11:00.')
            ->assertDontSee('2027-03-14T10:00')
            ->assertSee('Rosół z kury')
            ->assertSee('Obiad u Basi.')
            ->assertSee('Na święta')
            ->assertSee('Wczytaj zaznaczone')
            ->assertSee('Wszystko, co wczytamy, będzie prywatne', false);

        $this->assertSame(0, Recipe::query()->count());
        $this->assertSame(0, Post::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('import-paczek/'.$zenek->getKey()));

        $odciski = $this->odcisk($zenek, $adres);

        $this->actingAs($zenek)
            ->post($adres, ['pozycje' => $odciski])
            ->assertRedirect(route('settings.data'))
            ->assertSessionHas('status');

        $this->assertSame(1, Recipe::query()->where('author_id', $zenek->getKey())->count());
        $this->assertSame(1, Post::query()->where('author_id', $zenek->getKey())->count());
        $this->assertSame(1, Collection::query()->where('owner_id', $zenek->getKey())->where('name', 'Na święta')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('import-paczek'));

        // Wczytanie tej samej paczki jeszcze raz: podgląd nie ma już nic nowego.
        $drugi = $this->actingAs($zenek)->post(route('settings.data.import.check'), ['plik' => $this->plikPaczki()]);
        $this->actingAs($zenek)->get((string) $drugi->headers->get('Location'))
            ->assertOk()
            ->assertSee('W tej paczce nie ma nic nowego do wczytania.');
        $this->assertSame(1, Recipe::query()->count());
    }

    public function test_zapis_bez_zaznaczenia_mowi_co_zrobic_i_nic_nie_tworzy(): void
    {
        $zenek = $this->user('zenek');
        $adres = (string) $this->actingAs($zenek)->post(route('settings.data.import.check'), ['plik' => $this->plikPaczki()])->headers->get('Location');

        $this->actingAs($zenek)
            ->from($adres)
            ->post($adres, [])
            ->assertRedirect($adres)
            ->assertSessionHasErrors(['pozycje' => 'Zaznacz przynajmniej jedną pozycję do wczytania.']);

        $this->assertSame(0, Recipe::query()->count());
        $this->assertCount(1, Storage::disk('local')->allFiles('import-paczek/'.$zenek->getKey()));
    }

    public function test_podsuniety_odcisk_spoza_podgladu_niczego_nie_tworzy(): void
    {
        $zenek = $this->user('zenek');
        $adres = (string) $this->actingAs($zenek)->post(route('settings.data.import.check'), ['plik' => $this->plikPaczki()])->headers->get('Location');

        $this->actingAs($zenek)->post($adres, ['pozycje' => [str_repeat('f', 64)]])->assertRedirect(route('settings.data'));

        $this->assertSame(0, Recipe::query()->count());
        $this->assertSame(0, WczytanaZPaczki::query()->count());
    }

    public function test_zle_odciski_w_zadaniu_sa_odrzucane_walidacja(): void
    {
        $zenek = $this->user('zenek');
        $adres = (string) $this->actingAs($zenek)->post(route('settings.data.import.check'), ['plik' => $this->plikPaczki()])->headers->get('Location');

        $this->actingAs($zenek)->from($adres)->post($adres, ['pozycje' => ['../../etc/passwd']])
            ->assertSessionHasErrors('pozycje.0');
    }

    public function test_czyjs_token_nic_nie_znaczy_dla_innej_osoby(): void
    {
        $zenek = $this->user('zenek');
        $obca = $this->user('obca');
        $adres = (string) $this->actingAs($zenek)->post(route('settings.data.import.check'), ['plik' => $this->plikPaczki()])->headers->get('Location');

        $this->actingAs($obca)->get($adres)
            ->assertRedirect(route('settings.data.import'))
            ->assertSessionHasErrors(['plik' => 'Ta paczka nie czeka już na wczytanie. Wybierz plik jeszcze raz.']);

        $this->actingAs($obca)->post($adres, ['pozycje' => [str_repeat('a', 64)]])
            ->assertRedirect(route('settings.data.import'));

        $this->assertSame(0, Recipe::query()->where('author_id', $obca->getKey())->count());
        // Plik zenka nie został ruszony.
        $this->assertCount(1, Storage::disk('local')->allFiles('import-paczek/'.$zenek->getKey()));
    }

    public function test_token_ktory_nie_jest_uuid_daje_404(): void
    {
        $this->actingAs($this->user('zenek'))
            ->get('/ustawienia/twoje-dane/wczytaj/../../../etc/passwd')
            ->assertNotFound();
        $this->actingAs($this->user('zenek2'))
            ->get('/ustawienia/twoje-dane/wczytaj/nie-uuid')
            ->assertNotFound();
    }

    public function test_przeterminowana_paczka_znika_i_prosi_o_ponowny_wybor(): void
    {
        $zenek = $this->user('zenek');
        $adres = (string) $this->actingAs($zenek)->post(route('settings.data.import.check'), ['plik' => $this->plikPaczki()])->headers->get('Location');

        $this->travel(3)->hours();

        $this->actingAs($zenek)->get($adres)
            ->assertRedirect(route('settings.data.import'))
            ->assertSessionHasErrors('plik');
        $this->assertSame([], Storage::disk('local')->allFiles('import-paczek'));
    }

    public function test_magazyn_sprzata_przeterminowane_pliki_wszystkich_osob(): void
    {
        $magazyn = app(MagazynPaczek::class);
        $stary = $this->user('stary');
        $magazyn->zapisz($stary, UploadedFile::fake()->createWithContent('a.zip', 'x'));

        $this->travel(3)->hours();
        $magazyn->sprzatnijPrzeterminowane();

        $this->assertSame([], Storage::disk('local')->allFiles('import-paczek'));
    }

    public function test_limit_wczytywania_zatrzymuje_petle_zadan(): void
    {
        $zenek = $this->user('zenek');
        $limit = (int) explode(',', (string) config('kuking.limits.import_paczki'))[0];

        for ($i = 0; $i < $limit; $i++) {
            $this->actingAs($zenek)->post(route('settings.data.import.check'), []);
        }

        $this->actingAs($zenek)->post(route('settings.data.import.check'), [])->assertStatus(429);
    }

    public function test_wymazanie_konta_usuwa_czekajaca_paczke_i_slady(): void
    {
        $zenek = $this->user('zenek');
        $zostaje = $this->user('zostaje');
        $adres = (string) $this->actingAs($zenek)->post(route('settings.data.import.check'), ['plik' => $this->plikPaczki()])->headers->get('Location');
        $this->actingAs($zenek)->post($adres, ['pozycje' => $this->odcisk($zenek, $adres)]);
        $this->actingAs($zenek)->post(route('settings.data.import.check'), ['plik' => $this->plikPaczki(['Inny sernik'])]);
        $this->actingAs($zostaje)->post(route('settings.data.import.check'), ['plik' => $this->plikPaczki()]);

        $this->assertCount(1, Storage::disk('local')->allFiles('import-paczek/'.$zenek->getKey()));
        $this->assertSame(3, WczytanaZPaczki::query()->count());

        $zenek->forceFill([
            'status' => User::STATUS_PENDING_DELETE,
            'delete_requested_at' => now()->subDays(31),
            'delete_scope' => User::DELETE_SCOPE_MINIMUM,
        ])->save();

        $this->assertTrue(app(EraseAccountData::class)->handle($zenek->refresh()));

        $this->assertSame([], Storage::disk('local')->allFiles('import-paczek/'.$zenek->getKey()));
        $this->assertCount(1, Storage::disk('local')->allFiles('import-paczek/'.$zostaje->getKey()));
        $this->assertSame(0, WczytanaZPaczki::query()->where('user_id', $zenek->getKey())->count());
    }

    // -----------------------------------------------------------------

    /** @return list<string> odciski pozycji z podglądu, który zwraca ten adres */
    private function odcisk(User $user, string $adres): array
    {
        $sciezka = app(MagazynPaczek::class)->sciezka($user, basename($adres));
        $this->assertNotNull($sciezka);

        $podglad = app(PodgladPaczkiEksportu::class)->czytaj($user, $sciezka);

        return array_map(fn ($p): string => $p->odcisk, $podglad->wszystkie());
    }

    /** @param  list<string>  $tytuly */
    private function plikPaczki(array $tytuly = ['Rosół z kury']): UploadedFile
    {
        $dane = [
            'o_tym_pliku' => ['serwis' => 'Kuking.pl', 'wersja_formatu' => WersjaFormatuPaczki::AKTUALNA, 'wygenerowano' => '2027-03-14T10:00:00+00:00'],
            'przepisy' => array_map(fn (string $t): array => [
                'tytul' => $t,
                'krotki_opis' => null,
                'status' => Recipe::STATUS_PUBLISHED,
                'widocznosc' => 'public',
                'skladniki' => [['grupa' => null, 'zapis' => '2 jajka', 'uwaga' => null, 'zamienniki' => null]],
                'kroki' => [['numer' => 1, 'opis' => 'Wymieszaj.']],
            ], $tytuly),
            'wpisy' => [['rodzaj' => Post::KIND_DISH, 'tytul' => null, 'tresc' => 'Obiad u Basi.', 'widocznosc' => 'public', 'status' => Post::STATUS_PUBLISHED]],
            'kolekcje' => [['nazwa' => 'Na święta', 'opis' => null, 'widocznosc' => 'private', 'domyslna' => false]],
        ];

        $sciezka = tempnam(sys_get_temp_dir(), 'kuking-ekran-');
        $this->assertIsString($sciezka);
        $this->pliki[] = $sciezka;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($sciezka, ZipArchive::OVERWRITE | ZipArchive::CREATE) === true);
        $zip->addFromString('dane.json', json_encode($dane, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $this->assertTrue($zip->close());

        return new UploadedFile($sciezka, 'paczka.zip', 'application/zip', null, true);
    }
}

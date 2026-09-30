<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `kuking:proba-odtworzenia-zdjec` (#617, docs/infra/DR_ZDJEC_R2.md §7.1a).
 *
 * Bez MinIO i bez R2: `Storage::fake()` dla żywych dysków, migawki i dysku
 * testowego, a zdjęcia biorą się z PRAWDZIWEGO potoku, więc klucze i sumy są
 * te, które produkuje aplikacja. Test nie dowodzi niczego o Cloudflare
 * (tokeny, rygiel, lifecycle, przepustowość) — to zostaje właścicielowi.
 */
class ProbaOdtworzeniaZdjecTest extends TestCase
{
    use RefreshDatabase;

    private const ORYGINALY = 'dr_oryginaly';

    private const WARIANTY = 'dr_warianty';

    private const KOPIA = 'r2_kopia_zdjec';

    private const CEL = 'proba_odtworzenia';

    private const MIGAWKA = 'migawka-2026-09-28/';

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake(self::ORYGINALY);
        Storage::fake(self::WARIANTY);
        Storage::fake(self::KOPIA);
        Storage::fake(self::CEL);

        config([
            'kuking.media.disk' => self::ORYGINALY,
            'kuking.media.public_disk' => self::WARIANTY,
            'filesystems.disks.r2_kopia_zdjec.bucket' => 'kuking-zdjecia-kopia',
            'filesystems.disks.r2_kopia_zdjec.key' => 'token-tylko-odczytu',
            'filesystems.disks.r2_kopia_zdjec.secret' => 'sekret-tylko-odczytu',
        ]);
    }

    private function zdjecie(User $wlasciciel, int $szerokosc): Media
    {
        $media = app(StoreUploadedImage::class)->handle(
            $wlasciciel,
            UploadedFile::fake()->image('obiad.jpg', $szerokosc, 600),
        );

        (new ProcessUploadedImage($media->getKey()))->handle();
        $media->refresh();

        $this->assertSame(Media::STATUS_READY, $media->status);
        $this->assertNotEmpty($media->metadata['variants'] ?? []);

        return $media;
    }

    /**
     * @return list<array{dysk: string, klucz: string, wmigawce: string, wcelu: string}>
     */
    private function obiekty(Media $media): array
    {
        $lista = [[
            'dysk' => $media->disk,
            'klucz' => $media->object_key,
            'wmigawce' => self::MIGAWKA.'oryginaly/'.$media->object_key,
            'wcelu' => 'oryginaly/'.$media->object_key,
        ]];

        foreach ($media->metadata['variants'] as $wariant) {
            $lista[] = [
                'dysk' => $media->variantsDisk(),
                'klucz' => $wariant['key'],
                'wmigawce' => self::MIGAWKA.'warianty/'.$wariant['key'],
                'wcelu' => 'warianty/'.$wariant['key'],
            ];
        }

        return $lista;
    }

    /** @param list<Media> $zdjecia */
    private function migawka(array $zdjecia): void
    {
        foreach ($zdjecia as $media) {
            foreach ($this->obiekty($media) as $o) {
                Storage::disk(self::KOPIA)->put($o['wmigawce'], (string) Storage::disk($o['dysk'])->get($o['klucz']));
            }
        }
    }

    /** @return array<string, string[]> */
    private function stanDyskow(): array
    {
        $stan = [];

        foreach ([self::ORYGINALY, self::WARIANTY, self::KOPIA] as $dysk) {
            $pliki = Storage::disk($dysk)->allFiles();
            sort($pliki);
            $stan[$dysk] = array_map(fn (string $k) => $k.'#'.md5((string) Storage::disk($dysk)->get($k)), $pliki);
        }

        return $stan;
    }

    /**
     * @param  array<string, mixed>  $opcje
     * @return array{0: int, 1: string}
     */
    private function uruchom(array $opcje = []): array
    {
        $kod = Artisan::call('kuking:proba-odtworzenia-zdjec', $opcje + ['--prefiks' => self::MIGAWKA, '--cel' => self::CEL]);

        return [$kod, Artisan::output()];
    }

    public function test_wskazane_zdjecia_wracaja_na_dysk_testowy_bajt_w_bajt_a_zywe_dyski_i_kopia_sa_nietkniete(): void
    {
        $autor = $this->user('probadr');
        $pierwsze = $this->zdjecie($autor, 800);
        $drugie = $this->zdjecie($autor, 801);
        $this->migawka([$pierwsze, $drugie]);
        $przed = $this->stanDyskow();

        [$kod, $wyjscie] = $this->uruchom(['--media' => [$pierwsze->getKey()], '--wykonaj' => true]);

        $this->assertSame(0, $kod, $wyjscie);
        $this->assertStringContainsString('Zdjęć w próbie: 1.', $wyjscie);
        $this->assertStringContainsString('Wiek migawki (RPO w chwili próby):', $wyjscie);
        $this->assertStringContainsString('Czas próby (RTO', $wyjscie);
        $this->assertStringNotContainsString($pierwsze->object_key, $wyjscie, 'Komenda nie wypisuje kluczy obiektów.');

        foreach ($this->obiekty($pierwsze) as $o) {
            $this->assertTrue(Storage::disk(self::CEL)->exists($o['wcelu']), 'Nie odtworzono: '.$o['wcelu']);
            $this->assertSame(
                hash('sha256', (string) Storage::disk(self::KOPIA)->get($o['wmigawce'])),
                hash('sha256', (string) Storage::disk(self::CEL)->get($o['wcelu'])),
            );
        }

        foreach ($this->obiekty($drugie) as $o) {
            $this->assertFalse(Storage::disk(self::CEL)->exists($o['wcelu']), 'Odtworzono zdjęcie, którego nie wskazano.');
        }

        $this->assertSame($przed, $this->stanDyskow(), 'Żywe dyski i kopia muszą zostać bez zmian.');
    }

    public function test_bez_wykonaj_czyta_i_sprawdza_ale_nic_nie_zapisuje(): void
    {
        $media = $this->zdjecie($this->user('suchy'), 800);
        $this->migawka([$media]);

        [$kod, $wyjscie] = $this->uruchom(['--media' => [$media->getKey()]]);

        $this->assertSame(0, $kod, $wyjscie);
        $this->assertStringContainsString('sprawdzone w kopii', $wyjscie);
        $this->assertSame([], Storage::disk(self::CEL)->allFiles());
    }

    public function test_bez_media_bierze_domyslnie_najnowsze_zdjecia_do_limitu(): void
    {
        $autor = $this->user('limit');
        $a = $this->zdjecie($autor, 800);
        $b = $this->zdjecie($autor, 801);
        $c = $this->zdjecie($autor, 802);
        $this->migawka([$a, $b, $c]);

        [$kod, $wyjscie] = $this->uruchom(['--limit' => 2, '--wykonaj' => true]);

        $this->assertSame(0, $kod, $wyjscie);
        $this->assertStringContainsString('Zdjęć w próbie: 2.', $wyjscie);
    }

    public function test_uszkodzona_kopia_oryginalu_nie_trafia_na_dysk_testowy(): void
    {
        // Ten sam rozmiar, jeden bajt inny — sam rozmiar tego nie wykryje.
        $media = $this->zdjecie($this->user('uszkodzona'), 800);
        $this->migawka([$media]);
        $o = $this->obiekty($media)[0];
        $bajty = (string) Storage::disk(self::KOPIA)->get($o['wmigawce']);
        $bajty[strlen($bajty) - 1] = $bajty[strlen($bajty) - 1] === 'A' ? 'B' : 'A';
        Storage::disk(self::KOPIA)->put($o['wmigawce'], $bajty);

        [$kod, $wyjscie] = $this->uruchom(['--media' => [$media->getKey()], '--wykonaj' => true]);

        $this->assertNotSame(0, $kod);
        $this->assertStringContainsString('INNA SUMA', $wyjscie);
        $this->assertStringContainsString('Próba NIEUDANA', $wyjscie);
        $this->assertFalse(Storage::disk(self::CEL)->exists($o['wcelu']), 'Uszkodzone bajty trafiły na dysk testowy.');
    }

    public function test_brak_obiektu_w_migawce_i_zly_rozmiar_wariantu_koncza_probe_bledem(): void
    {
        $media = $this->zdjecie($this->user('braki'), 800);
        $this->migawka([$media]);
        $obiekty = $this->obiekty($media);

        Storage::disk(self::KOPIA)->delete($obiekty[1]['wmigawce']);
        Storage::disk(self::KOPIA)->put($obiekty[2]['wmigawce'], 'za krotko');

        [$kod, $wyjscie] = $this->uruchom(['--media' => [$media->getKey()], '--wykonaj' => true]);

        $this->assertNotSame(0, $kod);
        $this->assertStringContainsString('BRAK W KOPII', $wyjscie);
        $this->assertStringContainsString('INNY ROZMIAR', $wyjscie);
    }

    public function test_oryginal_bez_sumy_w_bazie_nie_jest_odtwarzany(): void
    {
        $media = $this->zdjecie($this->user('bezsumy'), 800);
        $this->migawka([$media]);
        Media::query()->whereKey($media->getKey())->update(['checksum_sha256' => null]);

        [$kod, $wyjscie] = $this->uruchom(['--media' => [$media->getKey()], '--wykonaj' => true]);

        $this->assertNotSame(0, $kod);
        $this->assertStringContainsString('BAZA NIE MA SUMY', $wyjscie);
        $this->assertFalse(Storage::disk(self::CEL)->exists('oryginaly/'.$media->object_key));
    }

    /** Wzorzec #2228: obecność klucza w kopii bez rozmiaru do porównania nie jest odtworzeniem. */
    public function test_wariant_bez_rozmiaru_w_bazie_konczy_probe_bledem(): void
    {
        $media = $this->zdjecie($this->user('bezrozmiaru'), 800);
        $this->migawka([$media]);
        $metadata = $media->metadata;
        foreach ($metadata['variants'] as $nazwa => $wariant) {
            unset($metadata['variants'][$nazwa]['bytes']);
        }
        $media->forceFill(['metadata' => $metadata])->save();

        [$kod, $wyjscie] = $this->uruchom(['--media' => [$media->getKey()], '--wykonaj' => true]);

        $this->assertNotSame(0, $kod);
        $this->assertStringContainsString('BAZA NIE MA ROZMIARU', $wyjscie);
        $this->assertSame([], Storage::disk(self::CEL)->files('warianty', true));
    }

    public function test_zdjecie_bez_wiersza_w_bazie_nie_wraca_choc_jest_w_migawce(): void
    {
        $media = $this->zdjecie($this->user('wymazana'), 800);
        $this->migawka([$media]);
        $obiekty = $this->obiekty($media);
        Media::query()->whereKey($media->getKey())->delete();

        [$kod, $wyjscie] = $this->uruchom(['--media' => [$media->getKey()], '--wykonaj' => true]);

        $this->assertNotSame(0, $kod);
        $this->assertStringContainsString('Nie ma gotowego zdjęcia', $wyjscie);
        $this->assertSame([], Storage::disk(self::CEL)->allFiles());
        $this->assertTrue(Storage::disk(self::KOPIA)->exists($obiekty[0]['wmigawce']), 'Kopia zostaje nietknięta.');
    }

    public function test_zdjecie_w_innym_stanie_niz_ready_jest_pomijane(): void
    {
        $media = $this->zdjecie($this->user('odrzucone'), 800);
        $this->migawka([$media]);
        Media::query()->whereKey($media->getKey())->update(['status' => Media::STATUS_REJECTED]);

        [$kod] = $this->uruchom(['--media' => [$media->getKey()], '--wykonaj' => true]);

        $this->assertNotSame(0, $kod);
        $this->assertSame([], Storage::disk(self::CEL)->allFiles());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function zakazaneCele(): array
    {
        return [
            'żywe oryginały z konfiguracji' => [self::ORYGINALY],
            'żywe warianty z konfiguracji' => [self::WARIANTY],
            'dysk kopii' => [self::KOPIA],
            'r2' => ['r2'],
            'r2_publiczne' => ['r2_publiczne'],
            'r2_legacy' => ['r2_legacy'],
            'r2_eksporty' => ['r2_eksporty'],
            'local' => ['local'],
            'public' => ['public'],
            'alias na ten sam bucket co żywe oryginały' => ['alias_zywego'],
            'alias na ten sam katalog co żywe oryginały' => ['alias_katalogu'],
            // Przegląd integracyjny: katalog W żywym katalogu i NAD nim to też
            // ten sam magazyn — porównanie samych napisów tego nie widziało.
            'podkatalog żywego katalogu' => ['podkatalog_zywego'],
            'katalog nad żywym katalogiem' => ['nad_zywym'],
            'żywy katalog przez ..' => ['przez_kropki'],
            'nieistniejący dysk' => ['nie_ma_takiego'],
        ];
    }

    #[DataProvider('zakazaneCele')]
    public function test_odmawia_zapisu_na_dysk_ktory_nie_jest_dyskiem_testowym(string $cel): void
    {
        config([
            'filesystems.disks.'.self::ORYGINALY.'.bucket' => 'zywy-bucket',
            'filesystems.disks.'.self::ORYGINALY.'.root' => sys_get_temp_dir().'/zywe-oryginaly-617',
            'filesystems.disks.alias_zywego' => ['driver' => 'local', 'bucket' => 'zywy-bucket', 'root' => sys_get_temp_dir().'/alias-zywego-617'],
            'filesystems.disks.alias_katalogu' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/zywe-oryginaly-617'],
            'filesystems.disks.podkatalog_zywego' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/zywe-oryginaly-617/proba'],
            'filesystems.disks.nad_zywym' => ['driver' => 'local', 'root' => sys_get_temp_dir()],
            'filesystems.disks.przez_kropki' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/inny-617/../zywe-oryginaly-617'],
        ]);
        @mkdir(sys_get_temp_dir().'/zywe-oryginaly-617');
        @mkdir(sys_get_temp_dir().'/inny-617');
        $media = $this->zdjecie($this->user('odmowa'), 800);
        $this->migawka([$media]);
        $przed = $this->stanDyskow();

        [$kod, $wyjscie] = $this->uruchom(['--media' => [$media->getKey()], '--wykonaj' => true, '--cel' => $cel]);

        $this->assertSame(1, $kod, $wyjscie);
        $this->assertStringNotContainsString('Obiekty odtworzone', $wyjscie);
        $this->assertSame($przed, $this->stanDyskow(), 'Odmowa musi nastąpić przed jakimkolwiek zapisem.');
    }

    public function test_dysk_wskazany_przez_wiersz_media_tez_jest_zakazany(): void
    {
        // Zdjęcie leży na dysku spoza domyślnej pary (np. po przenosinach).
        config(['filesystems.disks.stary_magazyn' => ['driver' => 'local', 'root' => sys_get_temp_dir().'/stary-magazyn-617']]);
        $media = $this->zdjecie($this->user('dyskwiersza'), 800);
        $this->migawka([$media]);
        Media::query()->whereKey($media->getKey())->update(['variants_disk' => 'stary_magazyn']);

        [$kod, $wyjscie] = $this->uruchom(['--media' => [$media->getKey()], '--wykonaj' => true, '--cel' => 'stary_magazyn']);

        $this->assertSame(1, $kod, $wyjscie);
        $this->assertStringContainsString('żywym magazynem', $wyjscie);
    }

    public function test_nie_nadpisuje_innego_pliku_na_dysku_testowym_a_ten_sam_uznaje_za_odtworzony(): void
    {
        $media = $this->zdjecie($this->user('nadpis'), 800);
        $this->migawka([$media]);
        $o = $this->obiekty($media)[0];

        Storage::disk(self::CEL)->put($o['wcelu'], 'cos-innego');
        [$kod, $wyjscie] = $this->uruchom(['--media' => [$media->getKey()], '--wykonaj' => true]);

        $this->assertNotSame(0, $kod);
        $this->assertStringContainsString('INNY PLIK', $wyjscie);
        $this->assertSame('cos-innego', Storage::disk(self::CEL)->get($o['wcelu']), 'Istniejący plik nie może zostać nadpisany.');

        // Ponowna próba na czystym dysku, a potem druga na tym samym: idempotentna.
        Storage::disk(self::CEL)->delete($o['wcelu']);
        [$pierwszy] = $this->uruchom(['--media' => [$media->getKey()], '--wykonaj' => true]);
        [$drugi] = $this->uruchom(['--media' => [$media->getKey()], '--wykonaj' => true]);

        $this->assertSame(0, $pierwszy);
        $this->assertSame(0, $drugi);
    }

    public function test_kopia_nieskonfigurowana_albo_bez_wlasnego_tokenu_konczy_sie_komunikatem(): void
    {
        $media = $this->zdjecie($this->user('brakkopii'), 800);

        config(['filesystems.disks.r2_kopia_zdjec.bucket' => '']);
        [$kod, $wyjscie] = $this->uruchom(['--media' => [$media->getKey()], '--wykonaj' => true]);
        $this->assertSame(1, $kod);
        $this->assertStringContainsString('KOPII NIE MA', $wyjscie);

        config(['filesystems.disks.r2_kopia_zdjec.bucket' => 'kuking-zdjecia-kopia', 'filesystems.disks.r2_kopia_zdjec.key' => '']);
        [$kod, $wyjscie] = $this->uruchom(['--media' => [$media->getKey()], '--wykonaj' => true]);
        $this->assertSame(1, $kod);
        $this->assertStringContainsString('TYLKO DO ODCZYTU', $wyjscie);
    }

    public function test_wymagane_opcje_i_poprawny_uuid(): void
    {
        $this->artisan('kuking:proba-odtworzenia-zdjec', ['--cel' => self::CEL])->assertFailed();
        $this->artisan('kuking:proba-odtworzenia-zdjec', ['--prefiks' => self::MIGAWKA])->assertFailed();
        $this->artisan('kuking:proba-odtworzenia-zdjec', ['--prefiks' => self::MIGAWKA, '--cel' => self::CEL, '--media' => ['nie-uuid']])
            ->expectsOutputToContain('To nie jest identyfikator zdjęcia')
            ->assertFailed();
    }

    public function test_prefiks_bez_daty_w_nazwie_nie_udaje_wieku_migawki(): void
    {
        $media = $this->zdjecie($this->user('bezdaty'), 800);
        Storage::disk(self::KOPIA)->put('inna/oryginaly/'.$media->object_key, (string) Storage::disk(self::ORYGINALY)->get($media->object_key));

        [, $wyjscie] = $this->uruchom(['--prefiks' => 'inna', '--media' => [$media->getKey()]]);

        $this->assertStringContainsString('nazwa prefiksu nie ma postaci', $wyjscie);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\TestCase;

/**
 * `kuking:sprawdz-kopie-zdjec` — porównanie migawki kopii zdjęć z bazą (#617).
 *
 * Kopia, której nikt nie porównał z bazą, jest zamiarem. Ta komenda jest
 * jedynym miejscem w aplikacji, które dotyka bucketu kopii — i ma to robić
 * wyłącznie odczytem: test na końcu podstawia dysk, który wybucha przy
 * każdej próbie zapisu albo usunięcia.
 */
class KopiaZdjecSprawdzanaTylkoOdczytemTest extends TestCase
{
    use RefreshDatabase;

    private const MIGAWKA = 'migawka-2026-09-24/';

    private const ORYGINAL = 'bajty oryginału bez GPS-u';

    private const WARIANT = 'bajty wariantu webp';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2_kopia_zdjec');
        config([
            'filesystems.disks.r2_kopia_zdjec.bucket' => 'kuking-zdjecia-kopia',
            'filesystems.disks.r2_kopia_zdjec.key' => 'token-tylko-odczytu',
            'filesystems.disks.r2_kopia_zdjec.secret' => 'sekret-tylko-odczytu',
        ]);
    }

    private function zdjecie(): Media
    {
        return Media::factory()->create([
            'status' => Media::STATUS_READY,
            'object_key' => 'incoming/basia/2026/09/sernik.jpg',
            // Celowo INNY niż długość oryginału: `bytes` to rozmiar przed
            // zdjęciem GPS-u, więc komenda nie ma prawa porównywać go
            // z obiektem w kopii.
            'bytes' => 999_999,
            'checksum_sha256' => hash('sha256', self::ORYGINAL),
            'metadata' => ['variants' => [
                'thumb' => ['key' => 'media/basia/2026/09/sernik_thumb.webp', 'bytes' => strlen(self::WARIANT)],
                'feed' => ['key' => 'media/basia/2026/09/sernik_feed.webp', 'bytes' => strlen(self::WARIANT)],
            ]],
        ]);
    }

    private function polozMigawke(Media $media): void
    {
        $kopia = Storage::disk('r2_kopia_zdjec');
        $kopia->put(self::MIGAWKA.'oryginaly/'.$media->object_key, self::ORYGINAL);

        foreach ($media->metadata['variants'] as $wariant) {
            $kopia->put(self::MIGAWKA.'warianty/'.$wariant['key'], self::WARIANT);
        }
    }

    public function test_kontrola_dodatnia_kompletna_migawka_daje_czysty_raport(): void
    {
        $this->polozMigawke($this->zdjecie());

        $this->artisan('kuking:sprawdz-kopie-zdjec', ['--prefiks' => self::MIGAWKA, '--sumy' => true])
            ->expectsOutputToContain('Każdy sprawdzony wiersz ma swoje pliki w tej migawce.')
            ->assertSuccessful();
    }

    public function test_brak_wariantu_w_kopii_jest_zglaszany(): void
    {
        $media = $this->zdjecie();
        $this->polozMigawke($media);
        $klucz = self::MIGAWKA.'warianty/'.$media->metadata['variants']['feed']['key'];
        Storage::disk('r2_kopia_zdjec')->delete($klucz);

        $this->artisan('kuking:sprawdz-kopie-zdjec', ['--prefiks' => self::MIGAWKA])
            ->expectsOutputToContain('BRAK W KOPII wariant feed: '.$klucz)
            ->assertFailed();
    }

    public function test_wariant_o_innym_rozmiarze_jest_zglaszany(): void
    {
        $media = $this->zdjecie();
        $this->polozMigawke($media);
        $klucz = self::MIGAWKA.'warianty/'.$media->metadata['variants']['thumb']['key'];
        Storage::disk('r2_kopia_zdjec')->put($klucz, 'ucięte');

        $this->artisan('kuking:sprawdz-kopie-zdjec', ['--prefiks' => self::MIGAWKA])
            ->expectsOutputToContain('INNY ROZMIAR wariant thumb: '.$klucz)
            ->assertFailed();
    }

    public function test_podmieniony_bajt_oryginalu_lapie_dopiero_suma(): void
    {
        // Ta sama kontrola ujemna co w próbie z #617: jeden bajt inny,
        // rozmiar identyczny. Bez `--sumy` raport jest czysty — i to jest
        // powód, dla którego runbook każe odtwarzać wyłącznie po `--sumy`.
        $media = $this->zdjecie();
        $this->polozMigawke($media);
        $klucz = self::MIGAWKA.'oryginaly/'.$media->object_key;
        $uszkodzony = self::ORYGINAL;
        $uszkodzony[0] = 'B';
        Storage::disk('r2_kopia_zdjec')->put($klucz, $uszkodzony);

        $this->artisan('kuking:sprawdz-kopie-zdjec', ['--prefiks' => self::MIGAWKA])
            ->assertSuccessful();

        $this->artisan('kuking:sprawdz-kopie-zdjec', ['--prefiks' => self::MIGAWKA, '--sumy' => true])
            ->expectsOutputToContain('INNA SUMA oryginał: '.$klucz)
            ->assertFailed();
    }

    public function test_obiekt_bez_wiersza_w_bazie_jest_nadmiarowy_i_nie_do_odtworzenia(): void
    {
        // Zdjęcie konta usuniętego po zrobieniu migawki: w bazie go nie ma,
        // w kopii jeszcze leży. Raport ma je nazwać i powiedzieć, że się go
        // nie odtwarza — ale nie oblewać migawki, bo to stan zamierzony.
        $this->polozMigawke($this->zdjecie());
        $obcy = self::MIGAWKA.'oryginaly/incoming/usuniete/2026/09/tort.jpg';
        Storage::disk('r2_kopia_zdjec')->put($obcy, 'zdjęcie osoby, która usunęła konto');

        $this->artisan('kuking:sprawdz-kopie-zdjec', ['--prefiks' => self::MIGAWKA, '--nadmiarowe' => true])
            ->expectsOutputToContain('NADMIAROWE (nie odtwarzać): '.$obcy)
            ->expectsOutputToContain('NADMIAROWE w migawce: 1 obiekt.')
            ->assertSuccessful();
    }

    public function test_bez_bucketu_kopii_mowi_wprost_ze_kopii_nie_ma(): void
    {
        config(['filesystems.disks.r2_kopia_zdjec.bucket' => '']);

        $this->artisan('kuking:sprawdz-kopie-zdjec')
            ->expectsOutputToContain('KOPII NIE MA')
            ->assertFailed();
    }

    public function test_bez_wlasnego_tokenu_kopii_odmawia_zamiast_brac_token_aplikacji(): void
    {
        // Pusty klucz s3 = AWS SDK bierze `AWS_ACCESS_KEY_ID` ze środowiska,
        // czyli token, który kasuje oryginały. Konfiguracja wyglądałaby na
        // odseparowaną, a nie byłaby.
        $this->polozMigawke($this->zdjecie());
        config(['filesystems.disks.r2_kopia_zdjec.key' => null]);

        $this->artisan('kuking:sprawdz-kopie-zdjec', ['--prefiks' => self::MIGAWKA])
            ->expectsOutputToContain('Ustaw AWS_ZDJECIA_KOPIA_ACCESS_KEY_ID')
            ->assertFailed();
    }

    public function test_komenda_niczego_nie_zapisuje_ani_nie_kasuje(): void
    {
        $media = $this->zdjecie();
        $this->polozMigawke($media);
        Storage::disk('r2_kopia_zdjec')->put(self::MIGAWKA.'warianty/media/obce_feed.webp', 'x');

        $fake = Storage::disk('r2_kopia_zdjec');
        Storage::set('r2_kopia_zdjec', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig()) extends FilesystemAdapter
        {
            public function put($path, $contents, $options = [])
            {
                throw new LogicException('zapis do kopii: '.$path);
            }

            public function writeStream($path, $resource, array $options = [])
            {
                throw new LogicException('zapis do kopii: '.$path);
            }

            public function delete($paths)
            {
                throw new LogicException('kasowanie w kopii');
            }

            public function deleteDirectory($directory)
            {
                throw new LogicException('kasowanie katalogu w kopii: '.$directory);
            }

            public function move($from, $to)
            {
                throw new LogicException('przeniesienie w kopii: '.$from);
            }

            public function copy($from, $to)
            {
                throw new LogicException('kopiowanie w kopii: '.$from);
            }

            public function setVisibility($path, $visibility)
            {
                throw new LogicException('widoczność w kopii: '.$path);
            }
        });

        // Wszystkie ścieżki odczytu naraz: obecność, rozmiar, suma i listowanie.
        $this->artisan('kuking:sprawdz-kopie-zdjec', [
            '--prefiks' => self::MIGAWKA,
            '--sumy' => true,
            '--nadmiarowe' => true,
        ])->assertSuccessful();

        $this->assertDatabaseHas('media', ['id' => $media->getKey(), 'status' => Media::STATUS_READY]);
    }
}

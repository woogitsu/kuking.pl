<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Publiczny adres pliku nie ma podwójnego ukośnika (przegląd G11).
 *
 * DLACZEGO TO MA ZNACZENIE
 * Adres z `Storage::disk('r2_legacy')->url()` jest jednocześnie adresem,
 * pod którym stary bucket SERWUJE plik, i kluczem, który
 * `KasujZdjecie::publicznyAdres()` oddaje do `PurgePublicMediaCache`.
 * Cloudflare czyści cache po DOKŁADNYM adresie: `cdn/media/x.webp`
 * i `cdn//media/x.webp` to dla niego dwa różne wpisy. Gdyby łączenie
 * `AWS_LEGACY_URL` z kluczem dawało `//`, czyszczenie kończyłoby się
 * sukcesem i nie usuwało niczego — skasowane zdjęcie dalej by się otwierało.
 *
 * Łączenie robi framework (`FilesystemAdapter::concatPathToUrl()`: `rtrim`
 * adresu + `ltrim` klucza) i flysystem (`PathPrefixer::prefixPath()` zdejmuje
 * wiodący ukośnik z klucza, więc plik leży pod tym samym kluczem, pod którym
 * powstaje adres). Ten test przypina to zachowanie na PRAWDZIWYM sterowniku
 * `r2` (`DyskR2`) — gdyby ktoś zastąpił `url()` własnym sklejaniem albo
 * framework zmienił zachowanie, dowiemy się tutaj, a nie z otwierającego się
 * po skasowaniu zdjęcia.
 */
class AdresPublicznyBezPodwojnegoUkosnikaTest extends TestCase
{
    private const SPODZIEWANY = 'https://cdn.example.test/media/basia/2026/09/sernik_feed.webp';

    /** @return iterable<string, array{string, string}> */
    public static function kombinacje(): iterable
    {
        $adresy = [
            'adres bez ukośnika' => 'https://cdn.example.test',
            'adres z ukośnikiem' => 'https://cdn.example.test/',
            'adres z dwoma ukośnikami' => 'https://cdn.example.test//',
        ];

        $klucze = [
            'klucz bez ukośnika' => 'media/basia/2026/09/sernik_feed.webp',
            'klucz z ukośnikiem' => '/media/basia/2026/09/sernik_feed.webp',
            'klucz z dwoma ukośnikami' => '//media/basia/2026/09/sernik_feed.webp',
        ];

        foreach ($adresy as $opisAdresu => $adres) {
            foreach ($klucze as $opisKlucza => $klucz) {
                yield $opisAdresu.', '.$opisKlucza => [$adres, $klucz];
            }
        }
    }

    #[DataProvider('kombinacje')]
    public function test_adres_czyszczony_jest_identyczny_z_serwowanym(string $adres, string $klucz): void
    {
        config(['filesystems.disks.r2_legacy' => array_merge(
            (array) config('filesystems.disks.r2_legacy'),
            [
                'key' => 'testowy-klucz',
                'secret' => 'testowy-sekret',
                'bucket' => 'stary-bucket',
                'endpoint' => 'https://konto.r2.example.test',
                'url' => $adres,
            ],
        )]);
        Storage::forgetDisk('r2_legacy');

        $this->assertSame(self::SPODZIEWANY, Storage::disk('r2_legacy')->url($klucz));
    }
}

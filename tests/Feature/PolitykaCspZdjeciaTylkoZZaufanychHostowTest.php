<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `img-src` i `media-src` tylko z zaufanych hostów (#2381).
 *
 * Wcześniej `img-src` miało `https:` (każdy host), a `media-src` dziedziczyło
 * `default-src 'self'` bez świadomej decyzji. Zdjęcia idą trasą `media.show`
 * i 302 na podpisany adres bucketu wariantów — ten host musi być na liście,
 * bo CSP sprawdza cel przekierowania.
 */
class PolitykaCspZdjeciaTylkoZZaufanychHostowTest extends TestCase
{
    use RefreshDatabase;

    private const KONTO = '0123456789abcdef0123456789abcdef';

    private function dyrektywa(string $nazwa): string
    {
        $csp = (string) $this->get(route('landing'))->assertOk()->headers->get('Content-Security-Policy');

        foreach (explode(';', $csp) as $czesc) {
            $czesc = trim($czesc);
            if (str_starts_with($czesc, $nazwa.' ')) {
                return $czesc;
            }
        }

        $this->fail("Brak dyrektywy {$nazwa} w CSP: {$csp}");
    }

    private function skonfigurujR2(): void
    {
        config([
            'filesystems.disks.r2_publiczne.endpoint' => 'https://'.self::KONTO.'.eu.r2.cloudflarestorage.com',
            'filesystems.disks.r2_publiczne.bucket' => 'kuking-publiczne',
            'kuking.media.publiczne_adresy' => ['https://cdn.kuking.pl'],
        ]);
    }

    public function test_img_src_i_media_src_nie_maja_dzikiej_karty(): void
    {
        $this->skonfigurujR2();

        foreach (['img-src', 'media-src'] as $nazwa) {
            $dyrektywa = $this->dyrektywa($nazwa);
            $zrodla = explode(' ', $dyrektywa);

            $this->assertNotContains('https:', $zrodla, "{$nazwa} nie może przepuszczać każdego hosta HTTPS");
            $this->assertNotContains('*', $zrodla);
            $this->assertNotContains('http:', $zrodla);
            $this->assertStringNotContainsString('*.', $dyrektywa);
        }
    }

    public function test_img_src_i_media_src_dopuszczaja_host_bucketu_i_cdn_z_konfiguracji(): void
    {
        $this->skonfigurujR2();

        foreach (['img-src', 'media-src'] as $nazwa) {
            $zrodla = explode(' ', $this->dyrektywa($nazwa));

            $this->assertContains("'self'", $zrodla);
            $this->assertContains('https://kuking-publiczne.'.self::KONTO.'.eu.r2.cloudflarestorage.com', $zrodla);
            $this->assertContains('https://cdn.kuking.pl', $zrodla);
            $this->assertNotContains('https://obcy.example', $zrodla);
        }
    }

    public function test_podglad_przed_wyslaniem_zachowuje_blob_i_data(): void
    {
        $zrodla = explode(' ', $this->dyrektywa('img-src'));

        $this->assertContains('blob:', $zrodla);
        $this->assertContains('data:', $zrodla);
    }

    public function test_bez_konfiguracji_r2_zostaje_tylko_self_blob_i_data(): void
    {
        config([
            'filesystems.disks.r2_publiczne.endpoint' => null,
            'filesystems.disks.r2_legacy.endpoint' => null,
            'kuking.media.publiczne_adresy' => [],
        ]);

        $this->assertSame("img-src 'self' data: blob:", $this->dyrektywa('img-src'));
        $this->assertSame("media-src 'self' blob:", $this->dyrektywa('media-src'));
    }

    public function test_wpis_z_dzika_karta_lub_obcym_schematem_w_konfiguracji_jest_pomijany(): void
    {
        config([
            'filesystems.disks.r2_publiczne.endpoint' => null,
            'filesystems.disks.r2_legacy.endpoint' => null,
            'kuking.media.publiczne_adresy' => ['*', 'https://*.evil.example', 'http://cdn.kuking.pl', 'https://dobry.kuking.pl'],
        ]);

        $img = $this->dyrektywa('img-src');

        $this->assertStringContainsString('https://dobry.kuking.pl', $img);
        $this->assertStringNotContainsString('evil', $img);
        $this->assertStringNotContainsString('http://cdn.kuking.pl', $img);
        $this->assertStringNotContainsString(' *', $img);
    }
}

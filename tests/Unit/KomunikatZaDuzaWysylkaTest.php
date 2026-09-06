<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\KomunikatZaDuzaWysylka;
use Illuminate\Http\Request;
use Tests\TestCase;

class KomunikatZaDuzaWysylkaTest extends TestCase
{
    public function test_odpowiedz_zawiera_polski_komunikat_i_limit_z_configu(): void
    {
        config(['kuking.media.max_bytes' => 15 * 1024 * 1024]);

        $response = KomunikatZaDuzaWysylka::odpowiedz(Request::create('/dodaj/zdjecie', 'POST'));

        $this->assertSame(413, $response->getStatusCode());
        $this->assertStringContainsString('15 MB', (string) $response->getContent());
        $this->assertStringContainsString('za duże', (string) $response->getContent());
        $this->assertStringNotContainsString('Page Expired', (string) $response->getContent());
    }

    public function test_link_powrotu_uzywa_referera_z_tego_samego_originu(): void
    {
        $request = Request::create('/dodaj/zdjecie', 'POST', server: [
            'HTTP_REFERER' => 'http://localhost/dodaj/zdjecie',
        ]);

        $response = KomunikatZaDuzaWysylka::odpowiedz($request);

        $this->assertStringContainsString('href="http://localhost/dodaj/zdjecie"', (string) $response->getContent());
    }

    public function test_link_powrotu_ignoruje_referer_z_obcego_originu(): void
    {
        $request = Request::create('/dodaj/zdjecie', 'POST', server: [
            'HTTP_REFERER' => 'http://zly-adres.example/cokolwiek',
        ]);

        $response = KomunikatZaDuzaWysylka::odpowiedz($request);

        $this->assertStringNotContainsString('zly-adres.example', (string) $response->getContent());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdresAktualizacjiWorkeraTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_zmienia_adres_workera_razem_z_pelnym_commitem(): void
    {
        foreach ([str_repeat('a', 40), str_repeat('b', 40)] as $commit) {
            config(['kuking.wersja.commit' => $commit]);
            foreach (['/login', '/pomoc'] as $adres) {
                $this->assertSame('/sw.js?v='.$commit, $this->adresWorkera($adres));
            }
        }
    }

    public function test_bez_commita_adres_korzysta_z_etykiety_wydania(): void
    {
        config(['kuking.wersja.commit' => '', 'kuking.wersja.etykieta' => 'Alfa 0.12']);
        $this->assertSame('/sw.js?v=Alfa%200.12', $this->adresWorkera('/login'));
    }

    public function test_caddy_wylacza_cache_tylko_dla_workera(): void
    {
        $caddy = file_get_contents(base_path('docker/Caddyfile'));
        $this->assertMatchesRegularExpression('/@serviceWorker path \/sw\.js\s+header @serviceWorker\s*\{\s*Cache-Control "no-store, no-cache, must-revalidate"\s*\}/', $caddy);
        preg_match('/@staticRoot path ([^\r\n]+)/', $caddy, $statyka);
        $this->assertArrayHasKey(1, $statyka);
        $this->assertStringNotContainsString('/sw.js', $statyka[1]);
        $this->assertStringContainsString('/manifest.webmanifest', $statyka[1]);
    }

    private function adresWorkera(string $adres): string
    {
        $dom = new DOMDocument;
        @$dom->loadHTML($this->get($adres)->assertOk()->getContent());
        $metadane = (new DOMXPath($dom))->query('//head/meta[@name="kuking-service-worker"]');
        $this->assertCount(1, $metadane);
        $meta = $metadane->item(0);
        $this->assertInstanceOf(DOMElement::class, $meta);

        return $meta->getAttribute('content');
    }
}

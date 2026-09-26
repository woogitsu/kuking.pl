<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Post;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KompozycjaProfiluMarkiTest extends TestCase
{
    use RefreshDatabase;

    public function test_wlasny_profil_ma_liczby_pod_naglowkiem_i_zachowuje_akcje(): void
    {
        $owner = $this->user('profilkompozycja');
        Post::factory()->create(['author_id' => $owner->id, 'visibility' => 'public']);
        Post::factory()->create(['author_id' => $owner->id, 'visibility' => 'private']);

        $html = $this->actingAs($owner)->get(route('profile.show', 'profilkompozycja'))
            ->assertOk()->getContent();
        $xpath = $this->sprawdzKompozycje($html, 2);
        $this->assertSame(1, $xpath->query('//aside[@aria-label="Skróty i podpowiedzi profilu"]')->length);
        $header = '//header[contains(@class,"marka-profil-kompozycja")]';
        foreach (['settings.avatar', 'settings.profile', 'settings.index', 'posts.create'] as $route) {
            $this->assertSame(1, $xpath->query($header.'//a[@href="'.route($route).'"]')->length, $route);
        }
        $this->assertSame(1, $xpath->query($header.'//form[@method="POST"]//input[@name="_token"]')->length);
    }

    public function test_cudzy_profil_pokazuje_tylko_widoczne_liczby_i_akcje_obserwatora(): void
    {
        $owner = $this->user('profilkompozycja');
        $viewer = $this->user('obserwatorkompozycji');
        Post::factory()->create(['author_id' => $owner->id, 'visibility' => 'public']);
        Post::factory()->create(['author_id' => $owner->id, 'visibility' => 'private', 'body' => 'Prywatny wpis kontrolny']);

        $html = $this->actingAs($viewer)->get(route('profile.show', 'profilkompozycja'))
            ->assertOk()->assertDontSee('Prywatny wpis kontrolny')->getContent();
        $xpath = $this->sprawdzKompozycje($html, 1);
        $header = '//header[contains(@class,"marka-profil-kompozycja")]';
        $this->assertSame(0, $xpath->query($header.'//a[@href="'.route('settings.avatar').'"]')->length);
        $this->assertSame(0, $xpath->query($header.'//a[@href="'.route('settings.profile').'"]')->length);
        $this->assertSame(1, $xpath->query($header.'//form[@action="'.route('social.follow', 'profilkompozycja').'"]')->length);
        $this->assertSame(1, $xpath->query($header.'//a[@href="'.route('reports.create', ['type' => 'user', 'id' => $owner->getKey()]).'"]')->length);
    }

    public function test_gosc_ma_te_same_pola_liczb_i_zaproszenie_bez_akcji_wlasciciela(): void
    {
        $this->user('profilkompozycja');
        $html = $this->get(route('profile.show', 'profilkompozycja'))->assertOk()->getContent();
        $xpath = $this->sprawdzKompozycje($html, 0);
        $this->assertSame(0, $xpath->query('//aside[@aria-label="Skróty i podpowiedzi profilu"]')->length);
        $header = '//header[contains(@class,"marka-profil-kompozycja")]';
        $this->assertSame(1, $xpath->query($header.'//a[@href="'.route('register').'"]')->length);
        $this->assertSame(0, $xpath->query($header.'//form')->length);
    }

    private function sprawdzKompozycje(string $html, int $posts): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $this->assertSame(1, $xpath->query('//*[@aria-label="Liczby tego profilu"]')->length);
        $header = '//header[contains(@class,"marka-profil-kompozycja")]';
        $this->assertSame(1, $xpath->query($header.'//h1')->length);
        $this->assertSame(1, $xpath->query($header.'//*[@data-rozmiar="170"]')->length);
        $this->assertSame(0, $xpath->query($header.'//*[@aria-label="Liczby tego profilu"]')->length);
        $stats = $header.'/following-sibling::*[1][contains(@class,"marka-profil-statystyki")]';
        $this->assertSame(5, $xpath->query($stats.'/ul/li')->length);
        $this->assertSame((string) $posts, trim($xpath->query($stats.'/ul/li[1]//*[contains(@class,"stat-value")]')->item(0)->textContent));
        foreach (['social.followers', 'social.following'] as $route) {
            $this->assertSame(1, $xpath->query($stats.'//a[@href="'.route($route, 'profilkompozycja').'"]')->length);
        }
        $dol = $stats.'/following-sibling::*[1][contains(@class,"marka-profil-dol")]';
        $this->assertSame(1, $xpath->query($dol.'//*[@aria-label="Zakładki profilu"]')->length);
        $this->assertSame(1, $xpath->query('//*[contains(@class,"app-body-tresc-z-szyna")]')->length);

        return $xpath;
    }
}

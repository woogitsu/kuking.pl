<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KAŻDE ZDJĘCIE W KOLAŻU NA STRONIE GŁÓWNEJ MA WIDOCZNY PODPIS AUTORA
 * I ODNOŚNIK DO WPISU ŹRÓDŁOWEGO (#2708; analiza prawna z 2.10.2026,
 * pytanie 1; P0-05).
 *
 * „Widoczny" znaczy: tekst w treści strony, nie `alt` ani `title` — czyta go
 * każdy, także na telefonie i bez najechania. Do 2.10.2026 pod kolażem stał
 * jeden wspólny podpis „Zdjęcia od: …", który nie wiązał nazwiska z żadnym
 * konkretnym zdjęciem i nie prowadził do wpisu.
 *
 * Test sprawdza PARĘ (autor, wpis) osobno dla każdego kafla: wspólny podpis
 * albo odnośnik pod całością nie przejdzie.
 */
class KolazPowitalnyPodpisAutoraPrzyKazdymZdjeciuTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array{0: User, 1: Post}> */
    private array $zestaw = [];

    private function przygotuj(int $ile = 4): void
    {
        $nazwy = ['Halina z Gdyni', 'Zbyszek <i>Kucharz</i> & Syn', 'Maria', 'Janina Kowalska'];

        for ($i = 0; $i < $ile; $i++) {
            $autor = $this->user('autor_podpis_'.$i, ['display_name' => $nazwy[$i]]);
            $wpis = Post::factory()->create([
                'author_id' => $autor->getKey(),
                'visibility' => Post::VISIBILITY_PUBLIC,
                'status' => Post::STATUS_PUBLISHED,
                'published_at' => now()->subMinutes($i + 1),
            ]);
            $wpis->media()->attach(Media::factory()->for($autor, 'owner')->create()->getKey(), ['position' => 0]);
            $this->zestaw[$nazwy[$i]] = [$autor, $wpis];
        }
    }

    /** @return list<DOMElement> kafle (`li.hero-kolaz-pole`) w kolejności z HTML */
    private function kafle(): array
    {
        $html = (string) $this->get('/')->assertOk()->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();

        $wynik = [];
        foreach ((new DOMXPath($dom))->query('//*[contains(concat(" ", normalize-space(@class), " "), " hero-kolaz-pole ")]') ?: [] as $wezel) {
            $this->assertInstanceOf(DOMElement::class, $wezel);
            $wynik[] = $wezel;
        }

        return $wynik;
    }

    public function test_przy_kazdym_zdjeciu_jest_widoczny_podpis_autora_i_odnosnik_do_jego_wpisu(): void
    {
        $this->przygotuj();
        $kafle = $this->kafle();

        // Kontrola dodatnia: bez kafli pętla niżej przeszłaby w próżni.
        $this->assertCount(4, $kafle);

        $widziane = [];

        foreach ($kafle as $kafel) {
            $linki = $kafel->getElementsByTagName('a');
            $this->assertSame(1, $linki->length, 'Kafel kolażu ma mieć dokładnie jeden odnośnik (cel dotyku).');

            $link = $linki->item(0);
            $this->assertInstanceOf(DOMElement::class, $link);
            $tekst = trim((string) preg_replace('/\s+/u', ' ', $link->textContent));

            // Parę (nazwa, wpis) wiążemy w TYM SAMYM odnośniku.
            $trafienia = 0;
            foreach ($this->zestaw as $nazwa => [, $wpis]) {
                if (str_contains($tekst, 'Zdjęcie: '.$nazwa)) {
                    $this->assertSame(route('posts.show', $wpis), $link->getAttribute('href'), "Podpis „{$nazwa}” prowadzi do cudzego wpisu.");
                    $widziane[] = $nazwa;
                    $trafienia++;
                }
            }
            $this->assertSame(1, $trafienia, "Kafel nie ma podpisu z nazwą dokładnie jednego autora: „{$tekst}”.");

            $this->assertStringContainsString('Zobacz wpis', $tekst);

            // Podpis to treść strony, nie atrybuty: `alt` pusty, brak `title`.
            $zdjecie = $kafel->getElementsByTagName('img')->item(0);
            $this->assertInstanceOf(DOMElement::class, $zdjecie);
            $this->assertSame('', $zdjecie->getAttribute('alt'));
            $this->assertFalse($zdjecie->hasAttribute('title'));
        }

        sort($widziane);
        $oczekiwane = array_keys($this->zestaw);
        sort($oczekiwane);
        $this->assertSame($oczekiwane, $widziane, 'Któryś autor nie ma podpisu przy swoim zdjęciu.');
    }

    public function test_odnosnik_z_kafla_otwiera_sie_gosciowi(): void
    {
        $this->przygotuj(2);

        foreach ($this->kafle() as $kafel) {
            $link = $kafel->getElementsByTagName('a')->item(0);
            $this->assertInstanceOf(DOMElement::class, $link);
            $this->get($link->getAttribute('href'))->assertOk();
        }
    }

    public function test_nazwa_autora_jest_wypisana_bezpiecznie(): void
    {
        $this->przygotuj();

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('<i>Kucharz</i>', $html);
        $this->assertStringContainsString('Zbyszek &lt;i&gt;Kucharz&lt;/i&gt; &amp; Syn', $html);
    }

    public function test_kolaz_nie_jest_ukryty_przed_czytnikiem_ekranu(): void
    {
        $this->przygotuj();

        $html = (string) $this->get('/')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('~class="hero-kolaz"[^>]*aria-hidden~', $html);
    }
}

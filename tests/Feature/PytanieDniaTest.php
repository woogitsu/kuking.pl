<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\WycinaObudoweEkranu;
use Tests\Support\WzorceRodzaju;
use Tests\TestCase;

/** Stałe powitanie oraz osobne pytanie i nazwa akcji w kaflu publikacji. */
class PytanieDniaTest extends TestCase
{
    use RefreshDatabase;
    use WycinaObudoweEkranu;

    public static function nazwy(): array
    {
        return [['Halina', 'Dzień dobry, Halina'], ['  Basia  ', 'Dzień dobry, Basia'],
            ['', 'Dzień dobry'], ['   ', 'Dzień dobry'],
            ['<script>alert(1)</script>', 'Dzień dobry, <script>alert(1)</script>']];
    }

    #[DataProvider('nazwy')]
    public function test_powitanie_uzywa_doslownej_nazwy_lub_pomija_ja(string $name, string $expected): void
    {
        $dom = $this->ekran($name);
        $headings = $dom->query('//h1');
        $this->assertSame(1, $headings->length);
        $this->assertSame($expected, trim($headings->item(0)->textContent));
        $this->assertSame(0, $dom->query('//h1/*')->length, 'Nazwa nie może tworzyć HTML.');
        $this->assertStringNotContainsString('Użytkownik Kuking', $headings->item(0)->textContent);
    }

    public static function poryDnia(): array
    {
        return [['02:30'], ['08:00'], ['13:30'], ['18:00'], ['22:30']];
    }

    #[DataProvider('poryDnia')]
    public function test_powitanie_nie_zalezy_od_czasu(string $hour): void
    {
        $this->travelTo(Carbon::parse('2026-09-12 '.$hour, 'UTC'));
        $dom = $this->ekran('Halina');
        $this->assertSame('Dzień dobry, Halina', trim($dom->query('//h1')->item(0)->textContent));
    }

    public function test_powitanie_poprzedza_pytanie_i_nazwana_akcje(): void
    {
        $dom = $this->ekran('Halina');
        $composer = $dom->query('//a[contains(concat(" ", normalize-space(@class), " "), " composer ")]');
        $this->assertSame(1, $composer->length);
        $this->assertSame('Co dziś gotujesz? Dodaj zdjęcie', self::elementDom($composer->item(0))->getAttribute('aria-label'));
        $this->assertSame(route('posts.create'), self::elementDom($composer->item(0))->getAttribute('href'));
        $this->assertSame(1, $dom->query('preceding::h1', $composer->item(0))->length);
        $title = $dom->query('.//*[contains(concat(" ", normalize-space(@class), " "), " composer-title ")]', $composer->item(0));
        $this->assertSame(1, $title->length);
        $this->assertSame('Co dziś gotujesz?', trim($title->item(0)->textContent));
        foreach (['Dodaj zdjęcie' => 'posts.create', 'Dodaj przepis' => 'recipes.create'] as $label => $route) {
            $links = $dom->query('//a[contains(concat(" ", normalize-space(@class), " "), " btn ") and @href="'.route($route).'"]');
            $this->assertGreaterThan(0, $links->length);
            $this->assertStringContainsString($label, $links->item(0)->textContent);
        }
        $greeting = $dom->query('//h1')->item(0)->textContent;
        $this->assertSame([], WzorceRodzaju::trafienia($greeting));
        foreach (array_keys(WzorceRodzaju::WYJATKI) as $exception) {
            $this->assertStringNotContainsString($exception, $greeting);
        }
    }

    private function ekran(string $name): DOMXPath
    {
        $user = $this->user('proba', ['display_name' => $name]);
        $html = $this->trescEkranu($this->actingAs($user)->get('/home')->assertOk()->getContent() ?: '');
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($document);
    }
}

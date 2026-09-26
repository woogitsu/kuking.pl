<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DailyPick;
use App\Models\Post;
use App\Support\Czas;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Spójność obu wejść do Start i prawdziwych danych; geometrię mierzy przeglądarka. */
class KompozycjaStartuTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_i_root_maja_te_same_sekcje_i_dzialajace_adresy(): void
    {
        $viewer = $this->user('widz', ['display_name' => 'Widz']);
        $cook = $this->user('kuchnia', ['display_name' => 'Prawdziwa nazwa testowa']);
        $post = Post::factory()->create(['author_id' => $cook->getKey(), 'body' => 'Danie z danych testowych', 'published_at' => now()]);
        foreach ([[DailyPick::TYPE_USER, $cook->getKey()], [DailyPick::TYPE_POST, $post->getKey()]] as $position => [$type, $id]) {
            DailyPick::create(['shown_on' => Czas::dzisiajData(), 'subject_type' => $type, 'subject_id' => $id, 'position' => $position, 'curator_id' => $viewer->getKey()]);
        }
        $snapshots = [];
        foreach (['/home', '/'] as $path) {
            $html = $this->actingAs($viewer)->get($path)->assertOk()->getContent();
            $document = new DOMDocument;
            @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
            $dom = new DOMXPath($document);
            $header = $this->classQuery('start-naglowek');
            $this->assertSame('Dzień dobry, Widz', trim($dom->query($header.'//h1')->item(0)?->textContent ?? ''));
            $this->assertSame(route('about'), self::elementDom($dom->query($header.'//a')->item(0))?->getAttribute('href'));
            $composer = $this->classQuery('composer');
            $this->assertSame('Co dziś gotujesz? Dodaj zdjęcie', self::elementDom($dom->query($composer)->item(0))?->getAttribute('aria-label'));
            $this->assertSame(route('posts.create'), self::elementDom($dom->query($composer)->item(0))?->getAttribute('href'));
            $board = $this->classQuery('app-rail').$this->classQuery('marka-tablica');
            $this->assertSame(1, $dom->query($board)->length, $path);
            $intro = $board.$this->classQuery('marka-tablica-wstep');
            $this->assertSame('Co dobrego u innych?', trim($dom->query($intro.'//h2')->item(0)?->textContent ?? ''));
            $this->assertSame(route('discover'), self::elementDom($dom->query($intro.'//a')->item(0))?->getAttribute('href'));
            $people = $board.$this->classQuery('kuking-board-people');
            $this->assertStringContainsString('Prawdziwa nazwa testowa', $dom->query($people)->item(0)?->textContent ?? '');
            $this->assertGreaterThan(0, $dom->query($people.'//a[@href="'.route('profile.show', 'kuchnia').'"]')->length);
            $this->assertGreaterThan(0, $dom->query($board.'//a[@href="'.route('search', ['sekcja' => 'ludzie']).'"]')->length);
            $this->assertStringContainsString('Danie z danych testowych', $dom->query($board)->item(0)->textContent);
            $nav = $this->classQuery('marka-nawigacja');
            $labels = [];
            foreach ($dom->query($nav.'//a') as $link) {
                $labels[] = trim($link->textContent);
            }
            $this->assertSame(['Start', 'Odkrywaj', 'Mój zeszyt'], $labels);
            $this->assertSame('Start', trim($dom->query($nav.'//a[@aria-current="page"]')->item(0)?->textContent ?? ''));
            $this->assertStringNotContainsString('Podgląd nowego wyglądu', $html);
            $this->assertStringNotContainsString('osoby, wpisy i liczby poniżej są przykładowe', mb_strtolower($html));
            $snapshots[] = trim($dom->query($board)->item(0)->textContent);
        }
        $this->assertSame($snapshots[0], $snapshots[1]);
    }

    private function classQuery(string $class): string
    {
        return '//*[contains(concat(" ", normalize-space(@class), " "), " '.$class.' ")]';
    }
}

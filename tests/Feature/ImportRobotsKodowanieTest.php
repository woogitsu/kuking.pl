<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\Url\PobieraczStron;
use App\Domain\Import\Url\RobotsTxt;
use App\Domain\Import\Url\StraznikAdresow;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\MapaNazw;
use Tests\TestCase;

final class ImportRobotsKodowanieTest extends TestCase
{
    public function test_zakodowane_unreserved_i_utf8_nie_omijaja_zakazu(): void
    {
        $cases = [
            ['/private', '/%70rivate'], ['/private', '/pr%69vate'],
            ['/baz', '/%62%61%7a'], ['/baz', '/%62%61%7A'],
            ['/%70rivate', '/private'], ['/%7e%41%2d%2e%5f', '/~A-._'],
            ['/foo/bar/ツ', '/foo/bar/%E3%83%84'],
            ['/foo/bar/%e3%83%84', '/foo/bar/ツ'],
            ['/foo/bar?baz=https%3A%2F%2Ffoo.bar', '/foo/bar?baz=https%3a%2f%2ffoo.bar'],
        ];
        foreach ($cases as [$rule, $path]) {
            $robots = RobotsTxt::zTresci("User-agent: *\nDisallow: {$rule}\n");
            $this->assertFalse($robots->wolno($path), 'ROBOTS_2569_OKTETY_ZAKAZANE: '.$path);
            $this->assertTrue($robots->wolno('/public'), 'Kontrola dodatnia: inna ścieżka pozostaje dostępna.');
        }
    }

    public function test_zarezerwowane_znaki_nie_staja_sie_separatorami_ani_operatorami(): void
    {
        foreach ([['/foo/bar', '/foo%2fbar'], ['/foo?bar', '/foo%3fbar'], ['/private', '/%2570rivate']] as [$rule, $path]) {
            $this->assertTrue(RobotsTxt::zTresci("User-agent: *\nDisallow: {$rule}\n")->wolno($path), 'ROBOTS_2569_BEZ_PONOWNEGO_DEKODOWANIA');
        }
        $robots = RobotsTxt::zTresci("User-agent: *\nDisallow: /file-%2A.html$\nDisallow: /foo-%24$\n");
        foreach (['/file-*.html', '/file-%2a.html', '/foo-$', '/foo-%24'] as $path) {
            $this->assertFalse($robots->wolno($path), 'ROBOTS_2569_LITERALNY_OPERATOR');
        }
        $this->assertTrue($robots->wolno('/file-other.html'));
        $this->assertTrue($robots->wolno('/foo-$/other'));
    }

    public function test_normalizacja_nie_zmienia_grup_query_wildcardow_i_remisu(): void
    {
        $robots = RobotsTxt::zTresci("User-agent: *\nDisallow: /\nUser-agent: KukingImport\nDisallow: /%70rivate\nAllow: /private\nDisallow: /*?s=\nDisallow: /%70rivate/sub\n");
        $this->assertTrue($robots->wolno('/private'), 'ROBOTS_2569_REMIS_ALLOW');
        $this->assertFalse($robots->wolno('/private/sub'), 'ROBOTS_2569_NAJDLUZSZY');
        $this->assertFalse($robots->wolno('/public?s=rosol'));
        $this->assertTrue($robots->wolno('/public'));
        $octets = RobotsTxt::zTresci("User-agent: *\nAllow: /%2F*\nDisallow: /*abc\n");
        $this->assertFalse($octets->wolno('/%2Fabc'), 'ROBOTS_2569_DLUGOSC_OKTETOW');
    }

    public function test_pobieracz_odmawia_zakodowanej_strony_przed_zadaniem_takze_po_przekierowaniu(): void
    {
        foreach (['/%70rivate', '/start'] as $entry) {
            Http::preventStrayRequests();
            Http::fake([
                'https://przepisy.example.pl/robots.txt' => Http::response("User-agent: *\nDisallow: /private\n", 200),
                'https://przepisy.example.pl/start' => Http::response('', 302, ['Location' => '/%70rivate']),
            ]);
            $fetcher = new PobieraczStron(new StraznikAdresow((new MapaNazw)->ustaw('przepisy.example.pl', '93.184.216.34')));
            try {
                $fetcher->pobierz('https://przepisy.example.pl'.$entry);
                $this->fail('ROBOTS_2569_POBIERACZ_ODMAWIA');
            } catch (ImportOdrzucony $error) {
                $this->assertSame(ImportOdrzucony::ROBOTS_ZABRANIA, $error->kod, 'ROBOTS_2569_POBIERACZ_ODMAWIA');
            }
            Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '%70rivate'));
            Http::assertSentCount($entry === '/start' ? 2 : 1);
        }
    }
}

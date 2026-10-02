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

final class ImportRobotsPcreTest extends TestCase
{
    public function test_wyczerpanie_pcre_nie_jest_zgoda_na_pobranie(): void
    {
        $poprzedniLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000');

        try {
            $wzorzec = '/'.str_repeat('*a', 20).'*b$';
            $robots = RobotsTxt::zTresci("User-agent: *\nDisallow: {$wzorzec}\n");

            try {
                $robots->wolno('/'.str_repeat('a', 100).'b');
                $this->fail('ROBOTS_2617_AWARIA_PCRE_NIE_JEST_ZGODA');
            } catch (ImportOdrzucony $e) {
                $this->assertSame(ImportOdrzucony::ROBOTS_NIEPEWNE, $e->kod, 'ROBOTS_2617_AWARIA_PCRE_NIE_JEST_ZGODA');
            }

            $this->assertFalse(RobotsTxt::zTresci("User-agent: *\nDisallow: /".str_repeat('*a', 10)."*b$\n")
                ->wolno('/'.str_repeat('a', 20).'b'));
            $this->assertTrue($robots->wolno('/'.str_repeat('a', 100).'c'));
            $this->assertTrue($robots->wolno('/public'));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $poprzedniLimit);
        }
    }

    public function test_przekierowanie_nie_pobiera_html_gdy_nie_da_sie_ocenic_robots(): void
    {
        $poprzedniLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '1000000');
        $sciezka = '/'.str_repeat('a', 100).'b';

        try {
            Http::preventStrayRequests();
            Http::fake([
                'https://przepisy.example.pl/robots.txt' => Http::response("User-agent: *\nDisallow: /".str_repeat('*a', 20)."*b$\n", 200),
                'https://przepisy.example.pl/start' => Http::response('', 302, ['Location' => $sciezka]),
            ]);
            $fetcher = new PobieraczStron(new StraznikAdresow((new MapaNazw)->ustaw('przepisy.example.pl', '93.184.216.34')));

            try {
                $fetcher->pobierz('https://przepisy.example.pl/start');
                $this->fail('ROBOTS_2617_PRZEKIEROWANIE_BEZ_HTML');
            } catch (ImportOdrzucony $e) {
                $this->assertSame(ImportOdrzucony::ROBOTS_NIEPEWNE, $e->kod, 'ROBOTS_2617_PRZEKIEROWANIE_BEZ_HTML');
            }

            Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), $sciezka));
            Http::assertSentCount(2);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $poprzedniLimit);
        }
    }
}

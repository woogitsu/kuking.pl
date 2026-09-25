<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Search\SearchQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DlugoscFrazyWyszukiwaniaTest extends TestCase
{
    use RefreshDatabase;

    public static function longPhrases(): array
    {
        $cases = [];
        foreach (['a', 'ż'] as $letter) {
            foreach ([121, 150] as $length) {
                foreach (['wszystko', 'przepisy', 'ludzie', 'szybkie', 'onboarding'] as $section) {
                    $cases[$letter.'-'.$length.'-'.$section] = [str_repeat($letter, $length), $section];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('longPhrases')]
    public function test_dluga_fraza_zostaje_w_polu_z_bledem_bez_wyszukiwania(string $phrase, string $section): void
    {
        if ($section === 'onboarding') {
            $this->actingAs($this->user('widz'));
        }
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, ' LIKE ?')) {
                $queries[] = $query->sql;
            }
        });
        $url = $section === 'onboarding'
            ? route('onboarding.people', ['q' => $phrase])
            : route('search', ['q' => $phrase, 'sekcja' => $section]);
        $response = $this->get($url)->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new \DOMXPath($dom);
        $input = self::elementDom($xpath->query('//input[@id="f-q"]')->item(0));
        $this->assertSame($phrase, $input->getAttribute('value'));
        $this->assertSame('true', $input->getAttribute('aria-invalid'), 'Długa fraza nie ma błędu przy polu.');
        $this->assertStringContainsString('f-q-error', $input->getAttribute('aria-describedby'));
        $error = $xpath->query('//*[@id="f-q-error"]')->item(0);
        $this->assertNotNull($error);
        $this->assertStringContainsString('120', $error->textContent);
        $this->assertStringContainsString('Skróć', $error->textContent);
        $summary = $xpath->query('//*[@role="alert"]//a[@href="#f-q"]')->item(0);
        $this->assertNotNull($summary);
        $this->assertSame(trim($error->textContent), trim($summary->textContent));
        $this->assertSame([], $queries, 'Odrzucona fraza uruchomiła wyszukiwanie.');
        $response->assertDontSee('Nic nie znaleźliśmy')->assertDontSee('Pokaż więcej osób')->assertDontSee('Pokaż więcej przepisów');
    }

    public function test_119_i_120_znakow_naprawde_trafia_do_obu_zapytan(): void
    {
        foreach ([119, 120] as $length) {
            $phrase = str_repeat('ż', $length - 1).'x';
            $queries = [];
            DB::flushQueryLog();
            DB::enableQueryLog();
            $response = $this->get(route('search', ['q' => $phrase]))->assertOk();
            $queries = array_filter(DB::getQueryLog(), fn ($query) => str_contains($query['query'], ' LIKE ?'));
            DB::disableQueryLog();
            $this->assertCount(2, $queries, 'Kontrola dodatnia: obie gałęzie naprawdę szukają.');
            foreach ($queries as $query) {
                $this->assertContains('%'.str_repeat('z', $length - 1).'x%', $query['bindings']);
            }
            $response->assertDontSee('id="f-q-error"', false);
        }
    }

    public static function searchMethods(): array
    {
        return [['recipes'], ['people']];
    }

    #[DataProvider('searchMethods')]
    public function test_bezposrednie_wywolanie_nie_szuka_ucietej_frazy(string $method): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            app(SearchQuery::class)->{$method}(str_repeat('ż', 121));
            $this->fail('Domena przyjęła zbyt długą frazę.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('q', $exception->errors());
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }
}

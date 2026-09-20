<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Moderacja\KlientOpenAI;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModeracjaOdpowiedzModeluTest extends TestCase
{
    public function test_nieskonczona_liczba_z_json_nie_jest_ocena(): void
    {
        config(['kuking.moderation.model.klucz' => 'test']);
        Http::fake(['*' => Http::response('{"results":[{"category_scores":{"hate":1e999}}]}')]);
        $this->assertNull(app(KlientOpenAI::class)->ocenTekst('Zupa'));
        Http::assertSentCount(1);
    }

    public static function timeouts(): array
    {
        return [[0, 1], [-1, 1], [3, 3], [8, 8], [60, 8]];
    }

    #[DataProvider('timeouts')]
    public function test_konfiguracja_nie_wylacza_limitu_http(int $configured, int $expected): void
    {
        config(['kuking.moderation.model.klucz' => 'test', 'kuking.moderation.model.limit_czasu' => $configured]);
        $observed = [];
        Http::fake(function ($request, $options) use (&$observed) {
            $observed = $options;

            return Http::response(['results' => [['category_scores' => ['hate' => 0]]]]);
        });
        $this->assertNotNull(app(KlientOpenAI::class)->ocenTekst('Zupa'));
        $this->assertSame($expected, $observed['timeout']);
        $this->assertSame(3, $observed['connect_timeout']);
    }

    public static function scores(): iterable
    {
        foreach ([[], ['violence' => 'bad'], ['violence' => -1], ['violence' => 2], ['violence' => true], ['violence' => null], ['violence' => '0.9'], ['hate' => 0.9, 'violence' => 'bad'], ['unknown' => 0.2], [0.3], ['hate' => 0.1], ['hate' => 0.9], ['hate' => 0.1, 'new/category' => 0.9]] as $i => $scores) {
            foreach (['text', 'image'] as $type) {
                yield "$type $i" => [$type, $scores, $i >= 10, $i >= 11];
            }
        }
    }

    #[DataProvider('scores')]
    public function test_nieznany_wynik_nie_udaje_poprawnej_oceny(string $type, array $scores, bool $valid, bool $flagged): void
    {
        config(['kuking.moderation.model.klucz' => 'test']);
        Http::fake(['*' => Http::response(['results' => [['category_scores' => $scores]]])]);
        $client = app(KlientOpenAI::class);
        $result = $type === 'text' ? $client->ocenTekst('Zupa') : $client->ocenObraz('data:image/jpeg;base64,TEST');
        Http::assertSentCount(1);
        if (! $valid) {
            $this->assertNull($result);
        } else {
            $this->assertNotNull($result);
            $this->assertSame($flagged, $result->costamZnalazl());
        }
    }
}

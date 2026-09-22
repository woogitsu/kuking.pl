<?php

declare(strict_types=1);

namespace Tests\Feature;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Kuking\AiPilots\Budget;
use Kuking\AiPilots\Pilot;
use Kuking\AiPilots\Transport;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once __DIR__.'/../../scripts/ai-pilots/Pilot.php';
require_once __DIR__.'/../../scripts/ai-pilots/Transport.php';
require_once __DIR__.'/../../scripts/ai-pilots/Budget.php';

class AiPilotContractTest extends TestCase
{
    use RefreshDatabase;

    private function client(mixed $answer, int $status = 200): array
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake([Transport::ENDPOINT => $http->response([
            'status' => 'completed',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'input_tokens_details' => ['cached_tokens' => 10]],
            'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($answer)]]]],
        ], $status)]);

        return [new Transport($http, 'atrapa-nie-klucz'), $http];
    }

    public function test_atrapa_przechodzi_przez_prawdziwy_klient_i_zamkniety_schemat(): void
    {
        [$client, $http] = $this->client(['status' => 'ok', 'q' => 'zupa', 'sekcja' => 'szybkie']);
        $result = $client->run('search', 'zupa do 30 minut');
        $this->assertNull($result['error']);
        $this->assertSame('szybkie', $result['proposal']['sekcja']);
        $this->assertEqualsWithDelta(0.0000432, $result['cost_usd'], 0.000000001);
        $http->assertSentCount(1);
        $http->assertSent(function ($request) use ($result) {
            $this->assertSame(Transport::ENDPOINT, $request->url());
            $this->assertFalse($request['store']);
            $this->assertSame(256, $request['max_output_tokens']);
            $this->assertSame(strlen($request->body()), $result['request_bytes']);
            $this->assertSame(['model', 'store', 'reasoning', 'instructions', 'input', 'max_output_tokens', 'text'], array_keys($request->data()));
            $this->assertSame([['role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'zupa do 30 minut']]]], $request['input']);

            return true;
        });
    }

    public static function badSearch(): array
    {
        $valid = ['status' => 'ok', 'q' => 'zupa', 'sekcja' => 'przepisy'];

        return [
            'nieistniejacy filtr' => [array_replace($valid, ['sekcja' => 'bez-orzechow'])],
            'dodatkowy czas' => [$valid + ['max_minutes' => 15]],
            'SQL' => [array_replace($valid, ['q' => 'SELECT * FROM recipes'])],
            'URL' => [array_replace($valid, ['q' => 'https://example.org'])],
            'HTML' => [array_replace($valid, ['q' => '<script>'])],
            'typ' => [array_replace($valid, ['q' => ['zupa']])],
            'pusta fraza' => [array_replace($valid, ['q' => ''])],
            'zbyt dluga' => [array_replace($valid, ['q' => str_repeat('a', 121)])],
            'cicha czesciowa interpretacja' => [array_replace($valid, ['status' => 'unsupported'])],
            'brak pola' => [['q' => 'zupa', 'sekcja' => 'szybkie']],
        ];
    }

    #[DataProvider('badSearch')]
    public function test_odrzuca_niedozwolone_parametry(mixed $data): void
    {
        $this->expectException(DomainException::class);
        Pilot::validate('search', 'zupa', $data);
    }

    public function test_szkic_ma_wylacznie_pelny_tekst_autora_i_nic_nie_zapisuje(): void
    {
        $source = "  mąki ile zabierze, 1,5 łyżki oleju\nMieszam, nie gotuję.  ";
        $tokens = Pilot::tokens($source);
        $before = DB::select('select count(*) as n from recipes');
        [$client] = $this->client(['segments' => [['end' => count($tokens), 'kind' => 'review']]]);
        $result = $client->run('recipe', $source);
        $this->assertNull($result['error']);
        $this->assertTrue($result['proposal']['requires_confirmation']);
        $this->assertSame($source, implode('', array_column($result['proposal']['segments'], 'text')));
        $this->assertEquals($before, DB::select('select count(*) as n from recipes'));
        $this->assertArrayNotHasKey('quantity', $result['proposal']);
        $this->assertArrayNotHasKey('servings', $result['proposal']);
    }

    public static function inventedRecipe(): array
    {
        return [
            'dopisana gramatura' => [['segments' => [['end' => 4, 'kind' => 'ingredient', 'text' => '200 g mąki']]]],
            'dopisana temperatura' => [['segments' => [['end' => 4, 'kind' => 'step']], 'temperature' => 180]],
            'dopisana ilosc' => [['segments' => [['end' => 4, 'kind' => 'ingredient', 'quantity' => 200]]]],
            'brak konca tekstu' => [['segments' => [['end' => 2, 'kind' => 'ingredient']]]],
            'powtorzenie' => [['segments' => [['end' => 2, 'kind' => 'ingredient'], ['end' => 2, 'kind' => 'ingredient'], ['end' => 4, 'kind' => 'step']]]],
            'zmiana kolejnosci' => [['segments' => [['end' => 3, 'kind' => 'ingredient'], ['end' => 2, 'kind' => 'step']]]],
            'spoza zrodla' => [['segments' => [['end' => 5, 'kind' => 'ingredient']]]],
            'typ indeksu' => [['segments' => [['end' => '4', 'kind' => 'ingredient']]]],
            'pusta propozycja' => [['segments' => []]],
        ];
    }

    #[DataProvider('inventedRecipe')]
    public function test_nie_przyjmuje_dopiskow_ani_pominiec(array $data): void
    {
        $this->expectException(DomainException::class);
        Pilot::validate('recipe', 'mąki ile zabierze mieszam', $data);
    }

    public function test_brak_ilosci_zostaje_brakiem_a_kolejnosc_jest_oryginalna(): void
    {
        $source = 'mąki ile zabierze mieszam';
        $result = Pilot::validate('recipe', $source, ['segments' => [
            ['end' => 3, 'kind' => 'ingredient'], ['end' => 4, 'kind' => 'step'],
        ]]);
        $this->assertSame('mąki ile zabierze', $result['segments'][0]['text']);
        $this->assertSame(' mieszam', $result['segments'][1]['text']);
        $this->assertSame($source, implode('', array_column($result['segments'], 'text')));
    }

    public function test_katalog_pomocy_ma_istniejace_trasy_i_nie_przyjmuje_adresu(): void
    {
        $this->assertCount(10, Pilot::HELP);
        foreach (Pilot::HELP as $intent => [$route]) {
            $this->assertTrue(app('router')->has($route), $route);
            $this->assertSame(['intent' => $intent], Pilot::validate('help', 'Pomoc', ['intent' => $intent]));
        }
        $this->expectException(DomainException::class);
        Pilot::validate('help', 'Pomoc', ['intent' => 'help', 'url' => 'https://example.org']);
    }

    public function test_limit_odmawia_przed_http_nie_obcina_tekstu(): void
    {
        [$client, $http] = $this->client([]);
        try {
            $client->run('search', str_repeat('ą', 601));
            $this->fail('Limit nie zatrzymał wysyłki.');
        } catch (DomainException) {
            $http->assertNothingSent();
        }
    }

    public function test_limit_calego_zadania_obejmuje_numerowane_tokeny(): void
    {
        [$client, $http] = $this->client([]);
        try {
            $client->run('recipe', str_repeat('a ', 1900));
            $this->fail('Limit bajtów nie zatrzymał wysyłki.');
        } catch (DomainException) {
            $http->assertNothingSent();
        }
    }

    public function test_awaria_nie_ponawia_i_nie_zwraca_tresci_bledu_dostawcy(): void
    {
        [$client, $http] = $this->client(['secret' => 'nie-wolno-wypisac'], 429);
        $result = $client->run('help', 'gdzie zeszyt');
        $this->assertSame('http_429', $result['error']);
        $this->assertNull($result['proposal']);
        $this->assertNull($result['cost_usd']);
        $this->assertStringNotContainsString('nie-wolno-wypisac', json_encode($result));
        $http->assertSentCount(1);
    }

    public function test_timeout_nie_zwraca_wyjatku_z_trescia(): void
    {
        $http = new Factory;
        $http->preventStrayRequests();
        $http->fake(fn () => throw new ConnectionException('tajna-tresc'));
        $result = (new Transport($http, 'atrapa'))->run('help', 'gdzie zeszyt');
        $this->assertSame('transport_error', $result['error']);
        $this->assertStringNotContainsString('tajna-tresc', json_encode($result));
    }

    public function test_budzet_jest_trwaly_i_odcina_przed_kolejna_proba(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kuking-ai-budget-');
        try {
            $this->assertSame(1, Budget::reserve($file, 2));
            $this->assertSame(2, Budget::reserve($file, 2));
            $this->expectException(\RuntimeException::class);
            Budget::reserve($file, 2);
        } finally {
            unlink($file);
        }
    }

    public function test_uszkodzony_budzet_nie_zeruje_sie_po_cichu(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kuking-ai-budget-');
        try {
            file_put_contents($file, 'nieznany-stan');
            $this->expectException(\RuntimeException::class);
            Budget::reserve($file);
        } finally {
            unlink($file);
        }
    }

    public function test_niepoprawny_json_niekompletna_odpowiedz_i_brak_usage_sa_jawne(): void
    {
        foreach (['invalid_json', 'incomplete', 'no_single_output', 'missing_usage'] as $case) {
            $http = new Factory;
            $http->preventStrayRequests();
            $data = ['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => '{"intent":"help"}']]]]];
            if ($case === 'invalid_json') {
                $data['output'][0]['content'][0]['text'] = '{oops';
            } elseif ($case === 'incomplete') {
                $data['status'] = 'incomplete';
            } elseif ($case === 'no_single_output') {
                $data['output'] = [];
            }
            $http->fake([Transport::ENDPOINT => $http->response($data)]);
            $result = (new Transport($http, 'atrapa'))->run('help', 'pomoc');
            $this->assertSame($case === 'missing_usage' ? null : $case, $result['error']);
            $this->assertNull($result['cost_usd']);
            $http->assertSentCount(1);
        }
    }
}

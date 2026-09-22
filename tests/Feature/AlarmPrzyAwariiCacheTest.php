<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Kolejka\AlarmKolejki;
use App\Domain\Polaczenia\AlarmPolaczen;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AlarmPrzyAwariiCacheTest extends TestCase
{
    use RefreshDatabase;

    public static function alarms(): array
    {
        return [
            'połączenia' => [AlarmPolaczen::class, 'niedostepny'],
            'kolejka' => [AlarmKolejki::class, 'niedostepna'],
        ];
    }

    #[DataProvider('alarms')]
    public function test_awaria_cache_postgres_nie_blokuje_dzwonka(string $class, string $state): void
    {
        config([
            'cache.default' => 'database',
            'cache.stores.database.table' => 'monitoring_nieistniejacy_cache',
            'logging.channels.blad_webhook.url' => 'https://alarm.example.test/hook',
        ]);
        Cache::purge('database');
        Log::forgetChannel('blad_webhook');
        Http::fake(['alarm.example.test/*' => Http::response('ok', 200)]);

        // Kontrola przyrządu: rzeczywisty PostgreSQL odrzuca odczyt cache.
        // Savepoint chroni transakcję testu przed stanem aborted.
        $failed = false;
        try {
            DB::transaction(fn () => Cache::get('kontrola'));
        } catch (QueryException) {
            $failed = true;
        }
        $this->assertTrue($failed, 'Próba nie wywołała awarii cache.');

        $this->assertTrue(app($class)->zadzwonJesliTrzeba(['stan' => $state]));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Co zrobić:'));
    }

    #[DataProvider('alarms')]
    public function test_awaria_zapisu_cache_nie_blokuje_wysylki_ani_nie_zmienia_jej_wyniku(string $class, string $state): void
    {
        config(['logging.channels.blad_webhook.url' => 'https://alarm.example.test/hook']);
        Log::forgetChannel('blad_webhook');
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('put')->andThrow(new \RuntimeException('Kontrolowana awaria zapisu'));
        Http::fake(['alarm.example.test/*' => Http::sequence()->push('ok', 200)->push('awaria', 500)]);

        $this->assertTrue(app($class)->zadzwonJesliTrzeba(['stan' => $state]));
        $this->assertFalse(app($class)->zadzwonJesliTrzeba(['stan' => $state]));
        Http::assertSentCount(2);
    }

    #[DataProvider('alarms')]
    public function test_wylaczony_kanal_przy_awarii_cache_nie_wysyla_niczego(string $class, string $state): void
    {
        config(['logging.channels.blad_webhook.url' => null]);
        Cache::shouldReceive('get')->andThrow(new \RuntimeException('Kontrolowana awaria odczytu'));
        Http::fake();
        $this->assertFalse(app($class)->zadzwonJesliTrzeba(['stan' => $state]));
        Http::assertNothingSent();
    }
}

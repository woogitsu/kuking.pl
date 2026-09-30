<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `/health` z włączonym kanałem alarmowym nie kasuje odstępu webhooka osobno
 * dla każdej przechodzącej sondy (audyt wydajności, P3 W9).
 *
 * Przy `CACHE_STORE=database` każde `Cache::forget()` to osobny `DELETE` na
 * tabeli `cache` — szesnaście na każdą zdrową odpowiedź. Teraz jeden odczyt
 * `Cache::many()` mówi, które klucze odstępu istnieją, i kasujemy tylko je.
 * Kontrakt odpowiedzi (`HealthKontraktOdpowiedziTest`) zostaje bez zmian.
 */
final class HealthOdstepyWebhookaZapytaniaDoCacheTest extends TestCase
{
    use RefreshDatabase;

    private const ADRES_WEBHOOKA = 'https://discord.example.test/api/webhooks/000/tajny-token/slack';

    private const PREFIKS = 'health:webhook_odstep:';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('storage:link');
        config([
            'cache.default' => 'database',
            'logging.channels.blad_webhook.url' => self::ADRES_WEBHOOKA,
        ]);
        Http::fake();
    }

    public function test_zdrowy_serwis_bez_odstepow_nie_robi_zadnego_delete_na_cache(): void
    {
        $zapytania = $this->zapytaniaDoCache(fn () => $this->zdrowieZeSzczegolami()->assertOk()->assertJsonPath('status', 'ok'));

        $this->assertSame([], $this->usuniecia($zapytania), 'Zdrowe sondy bez odstępu do skasowania nie dotykają cache DELETE-em.');
        $this->assertCount(
            1,
            $this->odczytyOdstepow($zapytania),
            'Istnienie kluczy odstępu sprawdza jeden odczyt (Cache::many), nie jeden na sondę.',
        );
    }

    public function test_istniejacy_odstep_jest_kasowany_tylko_dla_przechodzacej_sondy(): void
    {
        $this->zdrowieZeSzczegolami()->assertOk();
        Cache::put(self::PREFIKS.'database', true, now()->addMinutes(30));
        Cache::put(self::PREFIKS.'nieistniejaca_sonda', true, now()->addMinutes(30));

        $zapytania = $this->zapytaniaDoCache(fn () => $this->zdrowieZeSzczegolami()->assertOk()->assertJsonPath('status', 'ok'));

        $this->assertCount(1, $this->usuniecia($zapytania), 'Kasowany jest dokładnie jeden klucz: odstęp sondy, która przeszła.');
        $this->assertFalse(Cache::has(self::PREFIKS.'database'), 'Powrót do zdrowia kasuje odstęp (kontrakt sprzed zmiany).');
        $this->assertTrue(Cache::has(self::PREFIKS.'nieistniejaca_sonda'), 'Klucz spoza listy sond zostaje.');
    }

    public function test_bez_kanalu_alarmowego_health_nie_czyta_odstepow_w_ogole(): void
    {
        config(['logging.channels.blad_webhook.url' => '', 'logging.channels.blad_email.adres' => '']);

        $zapytania = $this->zapytaniaDoCache(fn () => $this->zdrowieZeSzczegolami()->assertOk());

        $this->assertSame([], $this->odczytyOdstepow($zapytania));
        $this->assertSame([], $this->usuniecia($zapytania));
    }

    /**
     * @param  callable(): mixed  $akcja
     * @return list<array{sql: string, bindings: array<int, mixed>}>
     */
    private function zapytaniaDoCache(callable $akcja): array
    {
        $zapytania = [];
        DB::listen(function ($zapytanie) use (&$zapytania): void {
            if (str_contains($zapytanie->sql, '"cache"')) {
                $zapytania[] = ['sql' => $zapytanie->sql, 'bindings' => $zapytanie->bindings];
            }
        });

        $akcja();

        return $zapytania;
    }

    /**
     * @param  list<array{sql: string, bindings: array<int, mixed>}>  $zapytania
     * @return list<array{sql: string, bindings: array<int, mixed>}>
     */
    private function usuniecia(array $zapytania): array
    {
        return array_values(array_filter($zapytania, fn (array $z): bool => str_starts_with(ltrim($z['sql']), 'delete')
            && str_contains(implode('|', array_map('strval', $z['bindings'])), self::PREFIKS)));
    }

    /**
     * @param  list<array{sql: string, bindings: array<int, mixed>}>  $zapytania
     * @return list<array{sql: string, bindings: array<int, mixed>}>
     */
    private function odczytyOdstepow(array $zapytania): array
    {
        return array_values(array_filter($zapytania, fn (array $z): bool => str_starts_with(ltrim($z['sql']), 'select')
            && str_contains(implode('|', array_map('strval', $z['bindings'])), self::PREFIKS)));
    }
}

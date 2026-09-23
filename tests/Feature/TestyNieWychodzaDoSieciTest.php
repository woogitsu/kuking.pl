<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Wspólna klasa testów odcina przypadkowe wyjścia przez klienta HTTP Laravela.
 *
 * Test celowo NIE woła tu `preventStrayRequests()`: ma dowodzić ochrony
 * odziedziczonej z `Tests\TestCase`, a nie własnego przygotowania. Adres
 * niedopasowany wskazuje loopback i zamknięty port, żeby kontrola ujemna po
 * zdjęciu ochrony nie wyszła do Internetu.
 */
class TestyNieWychodzaDoSieciTest extends TestCase
{
    public function test_niedopasowane_zadanie_jest_zatrzymane_przed_transportem(): void
    {
        Http::fake([
            'https://dozwolony.example/*' => Http::response(['ok' => true]),
        ]);

        $this->expectException(StrayRequestException::class);

        Http::get('http://127.0.0.1:1/przypadkowe-wyjscie');
    }

    public function test_dokladnie_podstawione_zadanie_nadal_przechodzi(): void
    {
        Http::fake([
            'https://dozwolony.example/*' => Http::response(['ok' => true]),
        ]);

        $odpowiedz = Http::post('https://dozwolony.example/sprawdz', [
            'pole' => 'wartosc',
        ]);

        $odpowiedz->throw();
        $this->assertTrue($odpowiedz->json('ok'));
        Http::assertSent(static fn (Request $zadanie): bool => $zadanie->method() === 'POST'
            && $zadanie->url() === 'https://dozwolony.example/sprawdz'
            && $zadanie['pole'] === 'wartosc');
        Http::assertSentCount(1);
    }
}

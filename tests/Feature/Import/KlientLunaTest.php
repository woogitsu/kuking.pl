<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\KlientLuna;
use App\Domain\Import\OdpowiedzModelu;
use App\Moderacja\ModelChwilowoNiedostepny;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Klient Responses API dla importu (D-298). ŻADNYCH prawdziwych wywołań:
 * `TestCase` ma `Http::preventStrayRequests()`, a każde żądanie idzie do
 * atrapy `Http::fake()`.
 */
final class KlientLunaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kuking.import.model.klucz' => 'sk-test-import',
            'kuking.import.model.endpoint' => KlientLuna::ADRES,
            'kuking.import.model.nazwa' => 'gpt-6-luna',
            'kuking.import.model.wysilek.ocr' => 'medium',
            'kuking.import.model.wysilek.tekst' => 'low',
            'kuking.import.model.cena_wejscie_mln_usd' => '2.5',
            'kuking.import.model.cena_wyjscie_mln_usd' => '10',
        ]);
    }

    public function test_brak_klucza_wylacza_funkcje_i_nie_wysyla_niczego(): void
    {
        config(['kuking.import.model.klucz' => null]);
        Http::fake();

        $this->assertFalse(KlientLuna::skonfigurowany(KlientLuna::ZADANIE_OCR));
        $this->assertNull($this->zapytaj());
        Http::assertNothingSent();
    }

    public function test_brak_cennika_wylacza_funkcje(): void
    {
        config(['kuking.import.model.cena_wyjscie_mln_usd' => null]);
        Http::fake();

        $this->assertFalse(KlientLuna::skonfigurowany(KlientLuna::ZADANIE_OCR));
        $this->assertNull($this->zapytaj());
        Http::assertNothingSent();
    }

    public function test_kontrola_dodatnia_pelna_konfiguracja_jest_gotowa(): void
    {
        $this->assertSame([], KlientLuna::braki(KlientLuna::ZADANIE_OCR));
        $this->assertSame([], KlientLuna::braki(KlientLuna::ZADANIE_TEKST));
    }

    /** @return array<string, array{string}> */
    public static function obceAdresy(): array
    {
        return [
            'inny host' => ['https://api.openai.com.evil.example/v1/responses'],
            'ścieżka moderacji' => ['https://api.openai.com/v1/moderations'],
            'port' => ['https://api.openai.com:8443/v1/responses'],
            'query' => ['https://api.openai.com/v1/responses?x=1'],
            'http' => ['http://api.openai.com/v1/responses'],
            'dane logowania' => ['https://user@api.openai.com/v1/responses'],
        ];
    }

    #[DataProvider('obceAdresy')]
    public function test_obcy_adres_nie_dostaje_klucza_ani_zdjecia(string $adres): void
    {
        config(['kuking.import.model.endpoint' => $adres]);
        Http::fake();

        $this->assertFalse(KlientLuna::skonfigurowany(KlientLuna::ZADANIE_OCR));
        $this->assertNull($this->zapytaj());
        Http::assertNothingSent();
    }

    public function test_intensywnosc_spoza_listy_wylacza_tylko_to_zadanie(): void
    {
        config(['kuking.import.model.wysilek.ocr' => 'turbo']);

        $this->assertFalse(KlientLuna::skonfigurowany(KlientLuna::ZADANIE_OCR));
        $this->assertTrue(KlientLuna::skonfigurowany(KlientLuna::ZADANIE_TEKST));
        $this->assertStringContainsString('KUKING_IMPORT_EFFORT_OCR', implode(' ', KlientLuna::braki(KlientLuna::ZADANIE_OCR)));
    }

    /** @return array<string, array{string, string, string}> */
    public static function intensywnosci(): array
    {
        return [
            'ocr domyślnie medium' => [KlientLuna::ZADANIE_OCR, 'medium', 'medium'],
            'tekst domyślnie low' => [KlientLuna::ZADANIE_TEKST, 'low', 'low'],
            'ocr podniesione' => [KlientLuna::ZADANIE_OCR, 'HIGH', 'high'],
            'tekst minimal' => [KlientLuna::ZADANIE_TEKST, 'minimal', 'minimal'],
        ];
    }

    #[DataProvider('intensywnosci')]
    public function test_klient_wysyla_intensywnosc_zgodna_z_konfiguracja(string $zadanie, string $wartosc, string $oczekiwana): void
    {
        config(["kuking.import.model.wysilek.{$zadanie}" => $wartosc]);
        Http::fake(['api.openai.com/*' => Http::response($this->odpowiedz(['tytul' => 'Sernik']))]);

        $this->zapytaj($zadanie);

        Http::assertSent(fn (Request $r): bool => $r['reasoning'] === ['effort' => $oczekiwana]);
    }

    public function test_cialo_zadania_ma_store_false_i_nic_poza_trescia_do_odczytu(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->odpowiedz(['tytul' => 'Sernik']))]);

        $this->zapytaj();

        Http::assertSent(function (Request $r): bool {
            $this->assertSame(KlientLuna::ADRES, $r->url());
            $this->assertSame('Bearer sk-test-import', $r->header('Authorization')[0] ?? null);
            // Dokładna lista kluczy: pole `user`, `safety_identifier`,
            // `metadata` ani nic podobnego nie ma prawa się dokleić.
            $this->assertSame(
                ['input', 'instructions', 'max_output_tokens', 'model', 'reasoning', 'store', 'text'],
                collect(array_keys($r->data()))->sort()->values()->all(),
            );
            $this->assertFalse($r['store']);
            $this->assertSame('gpt-6-luna', $r['model']);
            $this->assertSame('json_schema', $r['text']['format']['type']);
            $this->assertTrue($r['text']['format']['strict']);
            $this->assertArrayNotHasKey('tools', $r->data());

            return true;
        });
    }

    public function test_odpowiedz_z_usage_daje_dane_i_tokeny(): void
    {
        Http::fake(['api.openai.com/*' => Http::response($this->odpowiedz(['tytul' => 'Sernik'], 1200, 800))]);

        $wynik = $this->zapytaj();

        $this->assertNotNull($wynik);
        $this->assertSame(['tytul' => 'Sernik'], $wynik->dane);
        $this->assertSame(1200, $wynik->tokenyWejscia);
        $this->assertSame(800, $wynik->tokenyWyjscia);
    }

    public function test_odpowiedz_niepelna_albo_odmowa_nie_udaje_odczytu_ale_niesie_usage(): void
    {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'], 'usage' => ['input_tokens' => 10, 'output_tokens' => 20], 'output' => []])
            ->push(['status' => 'completed', 'usage' => ['input_tokens' => 1, 'output_tokens' => 2], 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'nie']]]]])
            ->push(['status' => 'completed', 'usage' => ['input_tokens' => 1, 'output_tokens' => 2], 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'to nie JSON']]]]]),
        ]);

        foreach (['status_incomplete', 'odmowa', 'nie_json'] as $powod) {
            $wynik = $this->zapytaj();
            $this->assertNotNull($wynik);
            $this->assertNull($wynik->dane);
            $this->assertSame($powod, $wynik->powodOdrzucenia);
            $this->assertTrue($wynik->maUsage());
        }
    }

    /** @return array<string, array{int}> */
    public static function przejsciowe(): array
    {
        return ['429' => [429], '500' => [500], '503' => [503]];
    }

    #[DataProvider('przejsciowe')]
    public function test_awaria_przejsciowa_rzuca_do_ponowienia_zadania(int $status): void
    {
        Http::fake(['api.openai.com/*' => Http::response([], $status)]);

        $this->expectException(ModelChwilowoNiedostepny::class);
        $this->zapytaj();
    }

    public function test_blad_staly_to_null_bez_ponawiania(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'bad']], 400)]);

        $this->assertNull($this->zapytaj());
    }

    private function zapytaj(string $zadanie = KlientLuna::ZADANIE_OCR): ?OdpowiedzModelu
    {
        return app(KlientLuna::class)->odczytaj(
            $zadanie,
            'Przepisz tekst.',
            [['type' => 'input_text', 'text' => 'Zdjęcie kartki.']],
            'test',
            ['type' => 'object', 'properties' => ['tytul' => ['type' => 'string']], 'required' => ['tytul'], 'additionalProperties' => false],
        );
    }

    /**
     * @param  array<string, mixed>  $dane
     * @return array<string, mixed>
     */
    private function odpowiedz(array $dane, int $we = 100, int $wy = 50): array
    {
        return [
            'id' => 'resp_test',
            'status' => 'completed',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($dane)]]],
            ],
            'usage' => ['input_tokens' => $we, 'output_tokens' => $wy],
        ];
    }
}

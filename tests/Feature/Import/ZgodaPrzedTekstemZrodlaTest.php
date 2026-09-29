<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\Url\RozwiazywaczNazw;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Models\ImportPrzepisu;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WpisZgody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Support\MalyPdf;
use Tests\Support\MapaNazw;
use Tests\TestCase;

/**
 * Issue #2031: tekst cudzej strony i skan PDF nie wychodzą do modelu bez
 * WŁASNEJ zgody na to jedno wysłanie. Zgoda „odczyt AI” na zdjęcie kartki
 * (D-296) niczego tu nie odblokowuje. Model jest skonfigurowany i ma atrapę
 * HTTP, więc brak żądania wynika ze zgody, a nie z wyłączonej funkcji.
 */
final class ZgodaPrzedTekstemZrodlaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(RozwiazywaczNazw::class, (new MapaNazw)->ustaw('przepisy.example.pl', '93.184.216.34'));
        config([
            'kuking.import.model.klucz' => 'sk-test-import',
            'kuking.import.model.nazwa' => 'gpt-6-luna',
            'kuking.import.model.cena_wejscie_mln_usd' => '2',
            'kuking.import.model.cena_wyjscie_mln_usd' => '8',
        ]);
    }

    private function zgodaNaZdjeciaKartek(User $osoba): void
    {
        $this->assertTrue(app(PrzestawZgodeNaOdczytAi::class)->handle($osoba, true, WpisZgody::ZRODLO_USTAWIENIA));
        $this->assertTrue(app(PrzestawZgodeNaOdczytAi::class)->udzielona($osoba), 'Kontrola dodatnia: zgoda na zdjęcia jest w dzienniku.');
    }

    public function test_zgoda_na_zdjecia_nie_wysyla_tekstu_strony_bez_zgody_na_to_wysylanie(): void
    {
        $autor = $this->user();
        $this->zgodaNaZdjeciaKartek($autor);
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/blog' => Http::response('<html><body><h1>Sernik</h1><p>1 kg twarogu</p></body></html>', 200, ['Content-Type' => 'text/html']),
            'api.openai.com/*' => Http::response([]),
        ]);

        $this->actingAs($autor)
            ->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/blog'])
            ->assertRedirect();

        // Bez zgody z TEGO formularza tekst strony zostaje u nas: zlecenie kończy się szkicem ze źródłem.
        $zlecenie = ImportPrzepisu::query()->where('user_id', $autor->getKey())->firstOrFail();
        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $zlecenie->status);
        $this->assertSame('bez_tresci', $zlecenie->drogaOdczytu());
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'openai.com'));
    }

    public function test_zgoda_na_zdjecia_nie_wysyla_skanu_pdf_bez_zgody_na_to_wysylanie(): void
    {
        $autor = $this->user();
        $this->zgodaNaZdjeciaKartek($autor);
        Http::fake(['api.openai.com/*' => Http::response([])]);
        $pdf = UploadedFile::fake()->createWithContent('skan.pdf', MalyPdf::bezTekstu());

        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), ['plik' => $pdf])
            ->assertSessionHasErrors(['plik' => ImportOdrzucony::KOMUNIKATY[ImportOdrzucony::BRAK_ZGODY_AI]]);

        $this->assertSame(0, Recipe::query()->count());
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'openai.com'));
    }

    public function test_kontrola_dodatnia_z_zaznaczona_zgoda_tekst_strony_wychodzi_do_modelu(): void
    {
        $autor = $this->user();
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/blog' => Http::response('<html><body><h1>Sernik</h1><p>1 kg twarogu</p></body></html>', 200, ['Content-Type' => 'text/html']),
            'api.openai.com/*' => Http::response([]),
        ]);

        $this->actingAs($autor)
            ->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/blog', 'zgoda_ai' => '1']);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'openai.com'));
    }
}

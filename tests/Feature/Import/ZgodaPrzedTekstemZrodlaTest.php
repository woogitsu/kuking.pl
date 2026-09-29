<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\Url\RozwiazywaczNazw;
use App\Domain\Zgody\InformacjaTekstuZrodlaAi;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
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
 *
 * Zgoda na wysłanie tekstu strony/PDF jest WERSJONOWANA: zaznaczone pole liczy
 * się tylko z aktualną wersją informacji (`InformacjaTekstuZrodlaAi`), którą
 * niesie formularz. Informacja stoi w jednym komponencie `x-zgoda-zrodlo-ai`.
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
            ->assertSessionHas('status');

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
            ->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/blog', 'zgoda_ai' => '1', InformacjaTekstuZrodlaAi::POLE => InformacjaTekstuZrodlaAi::WERSJA]);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'openai.com'));
    }

    private const STRONA_BEZ_DANYCH = '<html><body><h1>Sernik</h1><p>1 kg twarogu</p></body></html>';

    private function atrapaStrony(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/blog' => Http::response(self::STRONA_BEZ_DANYCH, 200, ['Content-Type' => 'text/html']),
            'api.openai.com/*' => Http::response([]),
        ]);
    }

    /**
     * Formularz otwarty przed zmianą informacji (stara wersja) albo sprzed tego
     * zadania (brak pola) ma zaznaczone pole zgody, ale człowiek nie widział
     * aktualnego tekstu — nic nie wychodzi do modelu i wiadomo, co zrobić.
     */
    public function test_zaznaczona_zgoda_z_nieaktualnej_albo_brakujacej_wersji_informacji_nie_wysyla_tekstu_strony(): void
    {
        $przypadki = [
            'stara' => [InformacjaTekstuZrodlaAi::POLE => '2000-01-01'],
            'brak' => [],
        ];

        foreach ($przypadki as $nazwa => $wersja) {
            $this->atrapaStrony();

            $odpowiedz = $this->actingAs($this->user("wersja_{$nazwa}"))
                ->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/blog', 'zgoda_ai' => '1', ...$wersja]);

            $odpowiedz->assertSessionHas('status', fn (string $status): bool => str_contains($status, 'informacja przy niej zmieniła się od otwarcia formularza')
                && str_contains($status, 'nic nie wysłaliśmy'));

            Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'openai.com'));
        }
    }

    public function test_aktualna_wersja_informacji_nie_dokleja_ostrzezenia_o_nieaktualnej_zgodzie(): void
    {
        $this->atrapaStrony();

        $this->actingAs($this->user())
            ->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/blog', 'zgoda_ai' => '1', InformacjaTekstuZrodlaAi::POLE => InformacjaTekstuZrodlaAi::WERSJA])
            ->assertSessionHas('status', fn (string $status): bool => ! str_contains($status, 'nieaktualn') && ! str_contains($status, 'zmieniła się'));
    }

    public function test_skan_pdf_z_nieaktualnej_wersji_informacji_nie_wychodzi_do_modelu(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([])]);
        $pdf = UploadedFile::fake()->createWithContent('skan.pdf', MalyPdf::bezTekstu());

        $this->actingAs($this->user())
            ->post(route('recipes.import.pdf.store'), ['plik' => $pdf, 'zgoda_ai' => '1', InformacjaTekstuZrodlaAi::POLE => '2000-01-01'])
            ->assertSessionHasErrors(['plik' => ImportOdrzucony::KOMUNIKATY[ImportOdrzucony::ZGODA_AI_NIEAKTUALNA]]);

        $this->assertSame(0, Recipe::query()->count());
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'openai.com'));
    }

    /** Fakty, bez których zgoda nie jest świadoma: odbiorca, miejsce, zakres, skutek, osobność od zdjęć. */
    public function test_oba_formularze_pokazuja_informacje_z_wersja_przed_polem_zgody(): void
    {
        $osoba = $this->user();
        $zAdresu = $this->actingAs($osoba)->get(route('recipes.import.url'))->assertOk();
        $zPdf = $this->actingAs($osoba)->get(route('recipes.import.pdf'))->assertOk();

        $zAdresu->assertSeeInOrder([
            'OpenAI', 'w USA', 'sam tekst tej strony', 'bez adresu strony', 'adresu e-mail i adresu IP',
            'prywatnego szkicu', 'tylko tego jednego wysłania', 'nie zastępuje zgody na zdjęcia kartek',
            'Bez zaznaczenia pola nic nie wysyłamy', 'przechowuje wysłane dane',
            'name="'.InformacjaTekstuZrodlaAi::POLE.'" value="'.InformacjaTekstuZrodlaAi::WERSJA.'"',
            'name="zgoda_ai"',
        ], false);
        $zPdf->assertSeeInOrder([
            'OpenAI', 'w USA', 'obrazy stron tego pliku', 'adresu e-mail i adresu IP', 'usuń te strony z pliku',
            'prywatnego szkicu', 'tylko tego jednego wysłania', 'nie zastępuje zgody na zdjęcia kartek',
            'Bez zaznaczenia pola nic nie wysyłamy', 'przechowuje wysłane dane',
            'name="'.InformacjaTekstuZrodlaAi::POLE.'" value="'.InformacjaTekstuZrodlaAi::WERSJA.'"',
            'name="zgoda_ai"',
        ], false);
    }

    public function test_informacja_ma_wspolny_blok_z_ta_sama_wersja_na_obu_formularzach(): void
    {
        $osoba = $this->user();
        $wersje = [];
        foreach (['recipes.import.url', 'recipes.import.pdf'] as $trasa) {
            $html = $this->actingAs($osoba)->get(route($trasa))->assertOk()->getContent();
            $this->assertSame(1, preg_match('/data-informacja-zrodlo-ai="([^"]+)"/', $html, $m), "Brak bloku informacji na {$trasa}.");
            $wersje[] = $m[1];
        }

        $this->assertSame([InformacjaTekstuZrodlaAi::WERSJA, InformacjaTekstuZrodlaAi::WERSJA], $wersje);
    }

    public function test_zgoda_na_tekst_strony_nie_odblokowuje_zdjecia_kartki(): void
    {
        // Odwrotność: zaznaczone pole przy adresie nie zapisuje trwałej zgody „odczyt AI”.
        $autor = $this->user();
        $this->atrapaStrony();

        $this->actingAs($autor)
            ->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/blog', 'zgoda_ai' => '1', InformacjaTekstuZrodlaAi::POLE => InformacjaTekstuZrodlaAi::WERSJA]);

        $this->assertFalse(app(PrzestawZgodeNaOdczytAi::class)->udzielona($autor));
        $this->assertSame(0, WpisZgody::query()->where('user_id', $autor->getKey())->count());
    }
}

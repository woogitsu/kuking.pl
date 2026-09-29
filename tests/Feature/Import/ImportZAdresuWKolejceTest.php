<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\BudzetAi;
use App\Domain\Import\KlientLuna;
use App\Domain\Import\OdzyskanieImportow;
use App\Domain\Import\Rezerwacja;
use App\Domain\Import\Url\RozwiazywaczNazw;
use App\Domain\Zgody\InformacjaTekstuZrodlaAi;
use App\Jobs\ImportujPrzepisZAdresu;
use App\Models\ImportPrzepisu;
use App\Models\Recipe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\MapaNazw;
use Tests\TestCase;

/**
 * Import z adresu strony poza żądaniem WWW (#28, D-300).
 *
 * Wysłanie formularza tylko zleca: zapisuje trwałe zlecenie i zadanie,
 * a stronę, robots.txt, DNS i model obsługuje worker. Ten plik mierzy
 * właśnie tę granicę — czego POST NIE robi, co zostaje po restarcie workera
 * i jak kończy się zadanie, które padło. Zawartość odczytu (JSON-LD,
 * fragmenty, SSRF, robots) pilnuje `ImportPrzepisuZAdresuIPdfTest`,
 * gdzie kolejka jest `sync`.
 *
 * Sieć i model tylko przez `Http::fake` (`preventStrayRequests()` w `TestCase`).
 */
final class ImportZAdresuWKolejceTest extends TestCase
{
    use RefreshDatabase;

    private const STRONA = '<html><head><script type="application/ld+json">{"@context":"https://schema.org","@type":"Recipe","name":"Sernik babci Hani","recipeIngredient":["1 kg twarogu"],"recipeInstructions":[{"@type":"HowToStep","text":"Twaróg zmiel dwa razy i utrzyj z cukrem na gładką masę."}]}</script></head><body></body></html>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(RozwiazywaczNazw::class, (new MapaNazw)->ustaw('przepisy.example.pl', '93.184.216.34'));
    }

    private function udawajStrone(): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/sernik' => Http::response(self::STRONA, 200, ['Content-Type' => 'text/html']),
        ]);
    }

    private function modelTestowy(): void
    {
        config([
            'kuking.import.model.klucz' => 'sk-test-import',
            'kuking.import.model.endpoint' => KlientLuna::ADRES,
            'kuking.import.model.nazwa' => 'gpt-6-luna',
            'kuking.import.model.cena_wejscie_mln_usd' => '2',
            'kuking.import.model.cena_wyjscie_mln_usd' => '8',
        ]);
    }

    /** @return array<string, string> */
    private function dane(?string $klucz = null): array
    {
        return ['adres' => 'https://przepisy.example.pl/sernik', 'klucz_wyslania' => $klucz ?? (string) Str::uuid()];
    }

    public function test_wyslanie_adresu_nie_siega_do_sieci_tylko_zapisuje_zlecenie_i_zadanie(): void
    {
        $autor = $this->user();
        Queue::fake();
        Http::fake();

        $odpowiedz = $this->actingAs($autor)->post(route('recipes.import.url.store'), $this->dane() + ['zgoda_ai' => '1', InformacjaTekstuZrodlaAi::POLE => InformacjaTekstuZrodlaAi::WERSJA]);

        $zlecenie = ImportPrzepisu::query()->where('user_id', $autor->getKey())->firstOrFail();

        $odpowiedz->assertRedirect(route('import.show', $zlecenie));
        Http::assertNothingSent();
        $this->assertSame(ImportPrzepisu::ZRODLO_URL, $zlecenie->zrodlo);
        $this->assertSame(ImportPrzepisu::STATUS_OCZEKUJE, $zlecenie->status);
        $this->assertSame('https://przepisy.example.pl/sernik', $zlecenie->source_url);
        $this->assertNull($zlecenie->recipe_id, 'Szkic powstaje dopiero po odczycie.');
        $this->assertSame(0, Recipe::query()->count());
        $this->assertSame($zlecenie->getKey(), DB::table('proby_importu')->where('user_id', $autor->getKey())->value('import_id'));

        Queue::assertPushed(ImportujPrzepisZAdresu::class, fn (ImportujPrzepisZAdresu $z): bool => $z->importId === $zlecenie->getKey()
            && $z->zgodaAi === true && $z->queue === 'low');
        Queue::assertPushed(ImportujPrzepisZAdresu::class, 1);

        $this->actingAs($autor)->get(route('import.show', $zlecenie))
            ->assertOk()
            ->assertSee('Pobieramy stronę')
            ->assertSee('Sprawdź, czy już gotowe');
    }

    public function test_zadanie_nie_niesie_adresu_a_zgoda_jest_tylko_z_tego_formularza(): void
    {
        $autor = $this->user();
        Queue::fake();

        $this->actingAs($autor)->post(route('recipes.import.url.store'), $this->dane());

        Queue::assertPushed(ImportujPrzepisZAdresu::class, function (ImportujPrzepisZAdresu $z): bool {
            $this->assertFalse($z->zgodaAi, 'Bez zaznaczenia w formularzu zadanie nie ma zgody.');
            $this->assertStringNotContainsString('przepisy.example.pl', serialize($z), 'Adres stoi w zleceniu, nie w zadaniu.');

            return true;
        });
    }

    public function test_powtorzone_wyslanie_tego_samego_formularza_to_jedno_zlecenie_jedno_zadanie_jedno_miejsce_w_limicie(): void
    {
        $autor = $this->user();
        Queue::fake();
        $dane = $this->dane();

        $pierwsza = $this->actingAs($autor)->post(route('recipes.import.url.store'), $dane);
        $druga = $this->actingAs($autor)->post(route('recipes.import.url.store'), $dane);

        $druga->assertRedirect($pierwsza->headers->get('Location'));
        $this->assertSame(1, ImportPrzepisu::query()->count());
        $this->assertSame(1, DB::table('proby_importu')->count());
        Queue::assertPushed(ImportujPrzepisZAdresu::class, 1);
    }

    public function test_limit_osoby_zatrzymuje_zlecenie_przed_kolejka_i_zostawia_wpisany_adres(): void
    {
        $autor = $this->user();
        Queue::fake();
        config(['kuking.import.limity.na_osobe_dzien' => 1]);

        $this->actingAs($autor)->post(route('recipes.import.url.store'), $this->dane())->assertSessionHasNoErrors();
        $this->actingAs($autor)->post(route('recipes.import.url.store'), $this->dane())
            ->assertSessionHasErrors(['adres'])
            ->assertSessionHasInput('adres', 'https://przepisy.example.pl/sernik');

        $this->assertSame(1, ImportPrzepisu::query()->count());
        Queue::assertPushed(ImportujPrzepisZAdresu::class, 1);
    }

    public function test_prawdziwy_worker_odtwarza_wejscie_ze_zlecenia_po_wyslaniu_bez_pobierania_strony(): void
    {
        $autor = $this->user();
        config(['queue.default' => 'database']);
        $this->udawajStrone();

        $this->actingAs($autor)->post(route('recipes.import.url.store'), $this->dane())->assertRedirect();

        // Przed workerem: zadanie czeka w tabeli `jobs`, strona nie była pobrana.
        Http::assertNothingSent();
        $this->assertSame(1, DB::table('jobs')->where('queue', 'low')->count(), 'Kontrola dodatnia: zadanie czeka w kolejce bazodanowej.');
        $this->assertStringNotContainsString('przepisy.example.pl', (string) DB::table('jobs')->value('payload'));
        $this->assertSame(0, Recipe::query()->count());

        Artisan::call('queue:work', ['--once' => true, '--queue' => 'low', '--stop-when-empty' => true]);

        $zlecenie = ImportPrzepisu::query()->where('user_id', $autor->getKey())->firstOrFail();
        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $zlecenie->status);
        $this->assertNull($zlecenie->source_url);
        $recipe = Recipe::query()->findOrFail($zlecenie->recipe_id);
        $this->assertSame('Sernik babci Hani', $recipe->title);
        $this->assertSame(Recipe::STATUS_DRAFT, $recipe->status);
        $this->assertSame('private', $recipe->visibility);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('gotowy', DB::table('proby_importu')->where('import_id', $zlecenie->getKey())->value('status'));
    }

    public function test_zadanie_uruchomione_drugi_raz_na_zakonczonym_zleceniu_nic_nie_robi(): void
    {
        $autor = $this->user();
        $this->udawajStrone();
        $this->actingAs($autor)->post(route('recipes.import.url.store'), $this->dane())->assertRedirect();
        $zlecenie = ImportPrzepisu::query()->firstOrFail();
        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $zlecenie->status, 'Kontrola dodatnia: kolejka sync wykonała zadanie.');
        $zapytan = Http::recorded()->count();

        app()->call([new ImportujPrzepisZAdresu((string) $zlecenie->getKey()), 'handle']);

        $this->assertSame($zapytan, Http::recorded()->count());
        $this->assertSame(1, Recipe::query()->count());
    }

    public function test_wylaczone_zrodlo_konczy_zlecenie_bez_pobierania(): void
    {
        $autor = $this->user();
        Queue::fake();
        $this->actingAs($autor)->post(route('recipes.import.url.store'), $this->dane())->assertRedirect();
        $zlecenie = ImportPrzepisu::query()->firstOrFail();
        config(['kuking.import.url.wlaczony' => false]);
        Http::fake();

        app()->call([new ImportujPrzepisZAdresu((string) $zlecenie->getKey()), 'handle']);

        $zlecenie->refresh();
        $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $zlecenie->status);
        $this->assertSame(ImportPrzepisu::KOD_WYLACZONY, $zlecenie->kod_bledu);
        $this->assertNull($zlecenie->source_url);
        Http::assertNothingSent();
    }

    public function test_strona_bez_przepisu_bez_zgody_nie_wysyla_tekstu_do_modelu_a_zlecenie_konczy_sie_szkicem_ze_zrodlem(): void
    {
        $this->modelTestowy();
        $autor = $this->user();
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/sernik' => Http::response('<html><body><h1>Sernik</h1><p>1 kg twarogu</p></body></html>', 200, ['Content-Type' => 'text/html']),
            'api.openai.com/*' => Http::response([]),
        ]);

        $this->actingAs($autor)->post(route('recipes.import.url.store'), $this->dane())->assertRedirect();

        $zlecenie = ImportPrzepisu::query()->firstOrFail();
        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $zlecenie->status);
        $this->assertSame('bez_tresci', $zlecenie->drogaOdczytu());
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'openai.com'));
    }

    public function test_strona_niedostepna_daje_nieudane_zlecenie_ze_zdaniem_co_zrobic(): void
    {
        $autor = $this->user();
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/sernik' => Http::response('Błąd', 503),
        ]);

        $this->actingAs($autor)->post(route('recipes.import.url.store'), $this->dane())->assertRedirect();

        $zlecenie = ImportPrzepisu::query()->firstOrFail();
        $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $zlecenie->status);
        $this->assertSame('strona_niedostepna', $zlecenie->kod_bledu);
        $this->assertNull($zlecenie->source_url);
        $this->assertFalse($zlecenie->moznaPonowic(), 'Import z adresu nie ma „Spróbuj jeszcze raz” — człowiek wkleja adres od nowa.');
        $this->actingAs($autor)->get(route('import.show', $zlecenie))
            ->assertOk()
            ->assertSee('Z tej strony nie da się teraz pobrać przepisu')
            ->assertSee('Wklej adres jeszcze raz')
            ->assertSee('Wpiszę przepis ręcznie')
            ->assertDontSee('Zdjęcie przyjęte')
            ->assertDontSee('zdjęcie kartki jest już przy nim');
    }

    public function test_zadanie_ktore_padlo_konczy_zlecenie_i_domyka_rezerwacje_budzetu(): void
    {
        $this->modelTestowy();
        $autor = $this->user();
        Queue::fake();
        $this->actingAs($autor)->post(route('recipes.import.url.store'), $this->dane() + ['zgoda_ai' => '1', InformacjaTekstuZrodlaAi::POLE => InformacjaTekstuZrodlaAi::WERSJA])->assertRedirect();
        $zlecenie = ImportPrzepisu::query()->firstOrFail();
        $probaId = (string) DB::table('proby_importu')->where('import_id', $zlecenie->getKey())->value('id');

        // Rezerwacja „wysłana” — proces zabity tuż po niej. Wysłana idzie w wydatki całą kwotą.
        $rezerwacja = app(BudzetAi::class)->zarezerwuj(1_000_000, $probaId, 1);
        $this->assertInstanceOf(Rezerwacja::class, $rezerwacja);
        $this->assertTrue(app(BudzetAi::class)->oznaczWyslana($rezerwacja));

        (new ImportujPrzepisZAdresu((string) $zlecenie->getKey(), true))->failed(new RuntimeException('timeout'));

        $zlecenie->refresh();
        $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $zlecenie->status);
        $this->assertSame(ImportPrzepisu::KOD_BLAD_WEWNETRZNY, $zlecenie->kod_bledu);
        $this->assertNull($zlecenie->source_url);
        $this->assertSame('nieudany', DB::table('proby_importu')->where('import_id', $zlecenie->getKey())->value('status'));
        $this->assertSame(BudzetAi::STAN_ROZLICZONA, DB::table('ai_rezerwacje')->where('import_id', $probaId)->value('stan'));
        $this->assertSame(0, (int) DB::table('ai_budzet_dzienny')->sum('zarezerwowano_mikrousd'));
        $this->assertSame(1_000_000, (int) DB::table('ai_budzet_dzienny')->sum('wydano_mikrousd'));

        // Powtórzone `failed()` niczego nie liczy drugi raz.
        (new ImportujPrzepisZAdresu((string) $zlecenie->getKey(), true))->failed(new RuntimeException('timeout'));
        $this->assertSame(1_000_000, (int) DB::table('ai_budzet_dzienny')->sum('wydano_mikrousd'));
    }

    public function test_zadanie_zgubione_w_kolejce_jest_domykane_i_adres_znika_ze_zlecenia(): void
    {
        $autor = $this->user();
        Queue::fake();
        $this->actingAs($autor)->post(route('recipes.import.url.store'), $this->dane())->assertRedirect();
        $zlecenie = ImportPrzepisu::query()->firstOrFail();
        DB::table('importy_przepisow')->where('id', $zlecenie->getKey())->update(['updated_at' => now()->subHours(3)]);

        $wynik = app(OdzyskanieImportow::class)->odzyskaj();

        $this->assertSame(1, $wynik['zlecenia']);
        $zlecenie->refresh();
        $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $zlecenie->status);
        $this->assertSame(ImportPrzepisu::KOD_BLAD_WEWNETRZNY, $zlecenie->kod_bledu);
        $this->assertNull($zlecenie->source_url);
    }

    public function test_ekran_postepu_zlecenia_z_adresu_jest_tylko_dla_wlasciciela(): void
    {
        $autor = $this->user('wlasciciel');
        $obca = $this->user('obca');
        Queue::fake();
        $this->actingAs($autor)->post(route('recipes.import.url.store'), $this->dane())->assertRedirect();
        $zlecenie = ImportPrzepisu::query()->firstOrFail();

        $this->actingAs($obca)->get(route('import.show', $zlecenie))->assertForbidden();
        $this->actingAs($obca)->get(route('import.show', ['import' => $zlecenie, 'fragment' => 1]))->assertForbidden();
        $this->actingAs($autor)->get(route('import.show', ['import' => $zlecenie, 'fragment' => 1]))
            ->assertOk()->assertSee('data-koncowy="0"', false);
    }
}

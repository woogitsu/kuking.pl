<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Import\Actions\ZapiszSzkicZImportu;
use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\OdczytanyPrzepis;
use App\Domain\Import\StrazImportu;
use App\Domain\Import\Url\RozwiazywaczNazw;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Users\Exports\CollectUserExportData;
use App\Domain\Users\Exports\ExportPhotoPlan;
use App\Exceptions\BladDlaCzlowieka;
use App\Models\PrzepisZImportu;
use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Support\MalyPdf;
use Tests\Support\MapaNazw;
use Tests\TestCase;

/**
 * Import przepisu z adresu strony i z PDF-a od formularza do szkicu (D-300).
 *
 * Granice z decyzji właściciela z 26.09.2026, każda zmierzona tutaj:
 * zawsze prywatny szkic (nigdy publikacja), źródło zapisane i zablokowane,
 * zdjęć ze strony nie pobieramy, robots.txt, SSRF, limit na osobę,
 * „Sprawdziłem odczytany tekst" przed publikacją, ostrzeżenie o podobieństwie,
 * eksport i kasowanie. Sieć i OpenAI tylko przez `Http::fake`.
 */
final class ImportPrzepisuZAdresuIPdfTest extends TestCase
{
    use RefreshDatabase;

    private const JSON_LD = [
        '@context' => 'https://schema.org',
        '@type' => 'Recipe',
        'name' => 'Sernik babci Hani',
        'image' => 'https://przepisy.example.pl/zdjecie-sernika.jpg',
        'recipeYield' => '8 porcji',
        'recipeIngredient' => ['1 kg twarogu', '5 jaj', '200 g cukru'],
        'recipeInstructions' => [
            ['@type' => 'HowToStep', 'text' => 'Twaróg zmiel dwa razy i utrzyj z cukrem na gładką masę.'],
            ['@type' => 'HowToStep', 'text' => 'Dodawaj po jednym jajku, cały czas mieszając, i przełóż do formy.'],
            ['@type' => 'HowToStep', 'text' => 'Piecz godzinę w 170 stopniach, potem studź w uchylonym piekarniku.'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(RozwiazywaczNazw::class, (new MapaNazw)
            ->ustaw('przepisy.example.pl', '93.184.216.34')
            ->ustaw('wewnetrzny.example.pl', '10.1.2.3'));
    }

    private function stronaZPrzepisem(): string
    {
        return '<html><head><script type="application/ld+json">'.json_encode(self::JSON_LD, JSON_UNESCAPED_UNICODE)
            .'</script></head><body><img src="https://przepisy.example.pl/zdjecie-sernika.jpg"><p>Sernik</p></body></html>';
    }

    private function udawajStrone(string $robots = '', int $robotsStatus = 404): void
    {
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response($robots, $robotsStatus),
            'https://przepisy.example.pl/sernik*' => Http::response($this->stronaZPrzepisem(), 200, ['Content-Type' => 'text/html; charset=utf-8']),
            'https://przepisy.example.pl/blog' => Http::response('<html><body><p>Wpis bez przepisu</p></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);
    }

    private function szkicZImportu(User $autor): Recipe
    {
        $this->udawajStrone();

        $this->actingAs($autor)
            ->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/sernik?utm_source=facebook'])
            ->assertRedirect();

        return Recipe::query()->where('author_id', $autor->getKey())->latest('created_at')->firstOrFail();
    }

    // ---------------------------------------------------------------
    // Adres strony
    // ---------------------------------------------------------------

    public function test_strona_z_json_ld_daje_prywatny_szkic_ze_zrodlem_bez_zdjec_i_bez_modelu(): void
    {
        $autor = $this->user('hania');
        $this->udawajStrone();

        $odpowiedz = $this->actingAs($autor)
            ->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/sernik?utm_source=facebook']);

        $recipe = Recipe::query()->where('author_id', $autor->getKey())->firstOrFail();

        $odpowiedz->assertRedirect(route('recipes.create', ['szkic' => $recipe->getKey()]));
        $this->assertSame(Recipe::STATUS_DRAFT, $recipe->status);
        $this->assertNull($recipe->published_at);
        $this->assertSame('private', $recipe->visibility);
        $this->assertSame(Recipe::SOURCE_EXTERNAL, $recipe->source_type);
        $this->assertSame('https://przepisy.example.pl/sernik', $recipe->source_url);
        $this->assertNull($recipe->hero_media_id);
        $this->assertSame('Sernik babci Hani', $recipe->title);
        $this->assertSame(8.0, $recipe->servings);
        $this->assertSame(['1 kg twarogu', '5 jaj', '200 g cukru'], $recipe->ingredients()->pluck('ingredient_text')->all());
        $this->assertSame(3, $recipe->steps()->count());

        $pochodzenie = PrzepisZImportu::query()->findOrFail($recipe->getKey());
        $this->assertSame('url', $pochodzenie->zrodlo);
        $this->assertSame('json_ld', $pochodzenie->droga);
        $this->assertNull($pochodzenie->sprawdzone_at);

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'zdjecie-sernika'));
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'openai.com'));
        $this->assertSame(0, $autor->posts()->count(), 'Import nie może zapowiadać przepisu w strumieniu.');
    }

    public function test_robots_zabrania_daje_szkic_z_samym_zrodlem_i_zdaniem_co_zrobic(): void
    {
        $autor = $this->user();
        $this->udawajStrone("User-agent: KukingImport\nDisallow: /\n", 200);

        $this->actingAs($autor)
            ->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/sernik'])
            ->assertSessionHas('status', ImportOdrzucony::KOMUNIKATY[ImportOdrzucony::ROBOTS_ZABRANIA]);

        $recipe = Recipe::query()->where('author_id', $autor->getKey())->firstOrFail();
        $this->assertSame('https://przepisy.example.pl/sernik', $recipe->source_url);
        $this->assertSame(0, $recipe->steps()->count());
        $this->assertSame('bez_tresci', PrzepisZImportu::query()->findOrFail($recipe->getKey())->droga);
        Http::assertNotSent(fn (Request $r): bool => $r->url() === 'https://przepisy.example.pl/sernik');
    }

    public function test_strona_bez_json_ld_bez_modelu_nie_wysyla_nic_do_openai(): void
    {
        $autor = $this->user();
        $this->udawajStrone();

        $this->actingAs($autor)
            ->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/blog'])
            ->assertSessionHas('status', ImportOdrzucony::KOMUNIKATY[ImportOdrzucony::BRAK_PRZEPISU]);

        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'openai.com'));
    }

    public function test_adres_prywatny_jest_odrzucony_bez_szkicu_a_wpisany_adres_zostaje(): void
    {
        $autor = $this->user();
        Http::fake();

        foreach (['http://127.0.0.1/', 'http://10.0.0.8/admin', 'http://169.254.169.254/latest/meta-data/', 'http://[::1]/', 'https://wewnetrzny.example.pl/'] as $adres) {
            $this->actingAs($autor)
                ->from(route('recipes.import.url'))
                ->post(route('recipes.import.url.store'), ['adres' => $adres])
                ->assertRedirect(route('recipes.import.url'))
                ->assertSessionHasErrors(['adres' => ImportOdrzucony::KOMUNIKATY[ImportOdrzucony::ADRES_NIEPUBLICZNY]])
                ->assertSessionHasInput('adres', $adres);

            RateLimiter::clear('import-przepisu:dzien:'.$autor->getKey());
            RateLimiter::clear('import-przepisu:miesiac:'.$autor->getKey());
        }

        $this->assertSame(0, Recipe::query()->count());
        Http::assertNothingSent();
    }

    public function test_przekierowanie_na_adres_prywatny_nie_tworzy_szkicu(): void
    {
        $autor = $this->user();
        Http::fake([
            'https://przepisy.example.pl/robots.txt' => Http::response('', 404),
            'https://przepisy.example.pl/sernik' => Http::response('', 302, ['Location' => 'http://10.0.0.1/']),
        ]);

        $this->actingAs($autor)
            ->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/sernik'])
            ->assertSessionHasErrors(['adres' => ImportOdrzucony::KOMUNIKATY[ImportOdrzucony::ADRES_NIEPUBLICZNY]]);

        $this->assertSame(0, Recipe::query()->count());
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '10.0.0.1'));
    }

    public function test_limit_na_osobe_zatrzymuje_szoste_zlecenie_dnia_i_zostawia_adres(): void
    {
        $autor = $this->user();
        $this->udawajStrone();
        config(['kuking.import.limity.na_osobe_dzien' => 5]);

        for ($i = 0; $i < 5; $i++) {
            RateLimiter::clear('import');
            $this->actingAs($autor)->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/sernik'])
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($autor)
            ->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/sernik-drugi'])
            ->assertSessionHasErrors(['adres'])
            ->assertSessionHasInput('adres', 'https://przepisy.example.pl/sernik-drugi');

        $this->assertStringContainsString('5 Twoich przepisów', (string) session('errors')->first('adres'));
        $this->assertSame(5, Recipe::query()->where('author_id', $autor->getKey())->count());
    }

    public function test_formularz_przyjmuje_jeden_adres_a_nie_liste(): void
    {
        $autor = $this->user();
        Http::fake();

        $this->actingAs($autor)
            ->post(route('recipes.import.url.store'), ['adres' => "https://przepisy.example.pl/a\nhttps://przepisy.example.pl/b"])
            ->assertSessionHasErrors(['adres']);

        $this->actingAs($autor)
            ->post(route('recipes.import.url.store'), ['adres' => ['https://przepisy.example.pl/a', 'https://przepisy.example.pl/b']])
            ->assertSessionHasErrors(['adres']);

        $this->assertSame(0, Recipe::query()->count());
        Http::assertNothingSent();
    }

    public function test_wylaczone_zrodlo_nie_ma_przycisku_ani_trasy(): void
    {
        $autor = $this->user();
        config(['kuking.import.url.wlaczony' => false, 'kuking.import.pdf.wlaczony' => false]);

        $this->actingAs($autor)->get(route('recipes.create'))
            ->assertOk()
            ->assertDontSee('Wklej adres strony')
            ->assertDontSee('Dodaj plik PDF');
        $this->actingAs($autor)->get(route('recipes.import.url'))->assertNotFound();
        $this->actingAs($autor)->post(route('recipes.import.pdf.store'))->assertNotFound();
    }

    public function test_przyciski_importu_sa_na_ekranie_dodawania_przepisu(): void
    {
        $this->actingAs($this->user())->get(route('recipes.create'))
            ->assertOk()
            ->assertSee('Wklej adres strony')
            ->assertSee('Dodaj plik PDF');
    }

    public function test_gosc_nie_importuje(): void
    {
        Http::fake();

        $this->post(route('recipes.import.url.store'), ['adres' => 'https://przepisy.example.pl/sernik'])->assertRedirect(route('login'));
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // Publikacja szkicu z importu
    // ---------------------------------------------------------------

    public function test_publikacja_bez_sprawdzilem_jest_odrzucona_a_z_zaznaczeniem_przechodzi(): void
    {
        $autor = $this->user();
        $recipe = $this->szkicZImportu($autor);
        $publish = app(PublishRecipe::class);
        $kroki = [['instruction' => 'Mój własny opis: ucieram, dodaję jajka, piekę.']];

        try {
            $publish->handle($autor, ['title' => $recipe->title, 'visibility' => 'public'], [], $kroki, publish: true, existing: $recipe);
            $this->fail('Szkic z importu opublikował się bez „Sprawdziłem odczytany tekst”.');
        } catch (BladDlaCzlowieka $e) {
            $this->assertSame(StrazImportu::KOMUNIKAT_SPRAWDZ, $e->getMessage());
        }

        $this->assertSame(Recipe::STATUS_DRAFT, $recipe->fresh()->status);

        $opublikowany = $publish->handle(
            $autor,
            ['title' => $recipe->title, 'visibility' => 'public', 'sprawdzilem_odczyt' => true],
            [],
            $kroki,
            publish: true,
            existing: $recipe->fresh(),
        );

        $this->assertSame(Recipe::STATUS_PUBLISHED, $opublikowany->status);
        $pochodzenie = PrzepisZImportu::query()->findOrFail($recipe->getKey());
        $this->assertNotNull($pochodzenie->sprawdzone_at);
        $this->assertNull($pochodzenie->tekst_zrodla, 'Tekst ze strony po publikacji nie jest już potrzebny.');
    }

    public function test_kreator_pokazuje_baner_i_wymaga_sprawdzilem_przed_publikacja(): void
    {
        $autor = $this->user();
        $recipe = $this->szkicZImportu($autor);

        $kreator = Livewire::actingAs($autor)
            ->test('recipe-wizard', ['recipeId' => (string) $recipe->getKey()])
            ->assertSee('Ten tekst odczytał komputer ze strony internetowej.')
            ->set('source_type', 'own')
            ->set('step', 4)
            ->assertSee('Sprawdziłem odczytany tekst')
            ->assertSee('Opis przygotowania jest prawie taki sam jak na stronie źródłowej.')
            ->call('publish')
            ->assertHasErrors(['publikacja']);

        $this->assertSame(Recipe::STATUS_DRAFT, $recipe->fresh()->status);

        $kreator->set('sprawdzilemOdczyt', true)->call('publish')->assertHasNoErrors();

        $recipe->refresh();
        $this->assertSame(Recipe::STATUS_PUBLISHED, $recipe->status);
        $this->assertSame(Recipe::SOURCE_EXTERNAL, $recipe->source_type, 'Źródło szkicu z adresu nie może zmienić się w „Mój własny”.');
        $this->assertSame('https://przepisy.example.pl/sernik', $recipe->source_url);
    }

    public function test_zrodlo_szkicu_z_adresu_jest_zablokowane_na_formularzu_jednostronicowym(): void
    {
        $autor = $this->user();
        $recipe = $this->szkicZImportu($autor);

        $this->actingAs($autor)
            ->put(route('recipes.update', $recipe), [
                'title' => $recipe->title,
                'visibility' => 'private',
                'source_type' => 'own',
                'source_url' => 'https://inna-strona.example.pl/',
                'action' => 'draft',
                'steps' => [['instruction' => 'Krok napisany po swojemu.']],
            ])
            ->assertSessionHasNoErrors();

        $recipe->refresh();
        $this->assertSame(Recipe::SOURCE_EXTERNAL, $recipe->source_type);
        $this->assertSame('https://przepisy.example.pl/sernik', $recipe->source_url);
    }

    public function test_formularz_jednostronicowy_wymaga_sprawdzilem_z_bledem_przy_polu(): void
    {
        $autor = $this->user();
        $recipe = $this->szkicZImportu($autor);
        $dane = [
            'title' => $recipe->title,
            'visibility' => 'public',
            'action' => 'publish',
            'steps' => [['instruction' => 'Ucieram twaróg po swojemu i piekę do zrumienienia.']],
        ];

        $this->actingAs($autor)->put(route('recipes.update', $recipe), $dane)
            ->assertSessionHasErrors(['sprawdzilem_odczyt' => StrazImportu::KOMUNIKAT_SPRAWDZ]);
        $this->assertSame(Recipe::STATUS_DRAFT, $recipe->fresh()->status);

        $this->actingAs($autor)->put(route('recipes.update', $recipe), [...$dane, 'sprawdzilem_odczyt' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertSame(Recipe::STATUS_PUBLISHED, $recipe->fresh()->status);
    }

    public function test_ostrzezenie_o_podobienstwie_do_strony_i_brak_ostrzezenia_po_przepisaniu(): void
    {
        $autor = $this->user();
        $recipe = $this->szkicZImportu($autor);

        $this->actingAs($autor)->get(route('recipes.edit', $recipe))
            ->assertOk()
            ->assertSee('Ten tekst odczytał komputer.')
            ->assertSee('Opis przygotowania jest prawie taki sam jak na stronie źródłowej.')
            ->assertSee('Sprawdziłem odczytany tekst');

        app(PublishRecipe::class)->handle(
            $autor,
            ['title' => $recipe->title, 'visibility' => 'private'],
            [],
            [['instruction' => 'Najpierw mielę ser, potem wbijam jajka jedno po drugim. Wstawiam na godzinę do gorącego pieca.']],
            publish: false,
            existing: $recipe,
        );

        $this->actingAs($autor)->get(route('recipes.edit', $recipe))
            ->assertOk()
            ->assertDontSee('Opis przygotowania jest prawie taki sam jak na stronie źródłowej.')
            ->assertSee('Sprawdziłem odczytany tekst');
    }

    public function test_zwykly_szkic_nie_ma_bramki_ani_banera(): void
    {
        $autor = $this->user();
        $recipe = app(PublishRecipe::class)->handle($autor, ['title' => 'Zupa ogórkowa', 'visibility' => 'public'], [], [], publish: false);

        $this->actingAs($autor)->get(route('recipes.edit', $recipe))
            ->assertOk()
            ->assertDontSee('Sprawdziłem odczytany tekst')
            ->assertDontSee('Ten tekst odczytał komputer.');

        $opublikowany = app(PublishRecipe::class)->handle(
            $autor, ['title' => 'Zupa ogórkowa', 'visibility' => 'public'], [], [['instruction' => 'Gotuj.']], publish: true, existing: $recipe,
        );
        $this->assertSame(Recipe::STATUS_PUBLISHED, $opublikowany->status);
    }

    public function test_cudzy_szkic_z_importu_pod_uuid_jest_niedostepny(): void
    {
        $recipe = $this->szkicZImportu($this->user());

        $this->actingAs($this->user())->get(route('recipes.create', ['szkic' => $recipe->getKey()]))->assertForbidden();
        $this->actingAs($this->user())->get(route('recipes.edit', $recipe))->assertForbidden();
    }

    // ---------------------------------------------------------------
    // PDF
    // ---------------------------------------------------------------

    public function test_pdf_z_tekstem_daje_prywatny_szkic_bez_modelu(): void
    {
        $autor = $this->user();
        Http::fake();
        $pdf = UploadedFile::fake()->createWithContent('sernik.pdf', MalyPdf::zTekstem([
            ['Sernik z PDF', 'Skladniki', '1 kg twarogu', '5 jaj', 'Przygotowanie', '1. Utrzyj twarog.', '2. Piecz godzine.'],
        ]));

        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), ['plik' => $pdf])->assertRedirect();

        $recipe = Recipe::query()->where('author_id', $autor->getKey())->firstOrFail();
        $this->assertSame(Recipe::STATUS_DRAFT, $recipe->status);
        $this->assertSame('private', $recipe->visibility);
        $this->assertSame('Sernik z PDF', $recipe->title);
        $this->assertSame(['1 kg twarogu', '5 jaj'], $recipe->ingredients()->pluck('ingredient_text')->all());
        $this->assertSame(['Utrzyj twarog.', 'Piecz godzine.'], $recipe->steps()->pluck('instruction')->all());
        $this->assertSame('tekst_pdf', PrzepisZImportu::query()->findOrFail($recipe->getKey())->droga);
        Http::assertNothingSent();
    }

    public function test_pdf_bez_tekstu_nie_tworzy_szkicu_i_mowi_co_zrobic(): void
    {
        $autor = $this->user();
        Http::fake();
        $pdf = UploadedFile::fake()->createWithContent('skan.pdf', MalyPdf::bezTekstu());

        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), ['plik' => $pdf])
            ->assertSessionHasErrors(['plik' => ImportOdrzucony::KOMUNIKATY[ImportOdrzucony::PDF_BEZ_TEKSTU]]);

        $this->assertSame(0, Recipe::query()->count());
        Http::assertNothingSent();
    }

    public function test_plik_udajacy_pdf_jest_odrzucony(): void
    {
        $autor = $this->user();
        $plik = UploadedFile::fake()->createWithContent('przepis.pdf', '<script>alert(1)</script>');

        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), ['plik' => $plik])
            ->assertSessionHasErrors(['plik' => ImportOdrzucony::KOMUNIKATY[ImportOdrzucony::PDF_USZKODZONY]]);
        $this->assertSame(0, Recipe::query()->count());
    }

    // ---------------------------------------------------------------
    // Eksport i kasowanie
    // ---------------------------------------------------------------

    public function test_eksport_danych_zawiera_importy_bez_cudzego_tekstu(): void
    {
        $autor = $this->user();
        $recipe = $this->szkicZImportu($autor);

        $dane = app(CollectUserExportData::class)->handle($autor, new ExportPhotoPlan($autor), now());

        $this->assertCount(1, $dane['importy_przepisow']);
        $this->assertSame((string) $recipe->getKey(), (string) $dane['importy_przepisow'][0]['przepis_id']);
        $this->assertSame('url', $dane['importy_przepisow'][0]['zrodlo']);
        $this->assertSame('https://przepisy.example.pl/sernik', $dane['importy_przepisow'][0]['adres_strony']);
        $this->assertArrayNotHasKey('tekst_zrodla', $dane['importy_przepisow'][0]);
    }

    public function test_usuniecie_przepisu_na_stale_kasuje_pochodzenie(): void
    {
        $autor = $this->user();
        $recipe = $this->szkicZImportu($autor);

        $recipe->forceDelete();

        $this->assertSame(0, PrzepisZImportu::query()->count());
    }

    public function test_szkic_z_importu_zawsze_prywatny_nawet_gdy_wolajacy_poda_inaczej(): void
    {
        $autor = $this->user();

        $recipe = app(ZapiszSzkicZImportu::class)->handle(
            $autor,
            PrzepisZImportu::ZRODLO_URL,
            'json_ld',
            new OdczytanyPrzepis('Pierogi', kroki: ['Zagnieć ciasto.']),
            'https://przepisy.example.pl/pierogi',
        );

        $this->assertSame(Recipe::STATUS_DRAFT, $recipe->status);
        $this->assertSame('private', $recipe->visibility);
    }
}

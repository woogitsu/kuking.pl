<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\KlientLuna;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Recipes\Actions\PublishRecipe;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Zgody\PrzestawZgodeNaOdczytAi;
use App\Jobs\OdczytajPrzepis;
use App\Models\ImportPrzepisu;
use App\Models\Recipe;
use App\Models\User;
use App\Models\WpisZgody;
use App\Support\Czas;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Odczyt zdjęcia kartki → prywatny szkic (V2, D-296, D-297, D-298).
 *
 * ŻADNYCH PRAWDZIWYCH WYWOŁAŃ: `TestCase` ma `Http::preventStrayRequests()`,
 * a model odpowiada atrapą `Http::fake()`. Kolejka jest `sync`, więc
 * obróbka zdjęcia i odczyt dzieją się w tym samym żądaniu.
 */
final class OdczytZdjeciaKartkiTest extends TestCase
{
    use RefreshDatabase;

    private const ODPOWIEDZ = [
        'nieczytelne' => false,
        'tytul' => 'Sernik babci Hani',
        'porcje' => '8',
        'skladniki' => [
            ['tekst' => '1 kg twarogu', 'grupa' => null],
            ['tekst' => '1 szkl. [?cukru?]', 'grupa' => null],
        ],
        'kroki' => [
            ['tekst' => 'Twaróg zmielić dwa razy.'],
            ['tekst' => 'Piec godzinę w 170 stopniach.'],
        ],
        'uwagi' => null,
    ];

    private User $osoba;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        config([
            'kuking.import.model.klucz' => 'sk-test-import',
            'kuking.import.model.endpoint' => KlientLuna::ADRES,
            'kuking.import.model.nazwa' => 'gpt-6-luna',
            'kuking.import.model.wysilek.ocr' => 'medium',
            'kuking.import.model.cena_wejscie_mln_usd' => '2',
            'kuking.import.model.cena_wyjscie_mln_usd' => '8',
            'kuking.import.zrodla.zdjecie' => true,
        ]);

        $this->osoba = $this->user('kartki');
    }

    // ------------------------------------------------------------------
    // Brak klucza = brak przycisku
    // ------------------------------------------------------------------

    public function test_bez_klucza_przycisku_nie_ma_a_wpisze_sam_jest(): void
    {
        config(['kuking.import.model.klucz' => null]);
        Http::fake();

        $this->actingAs($this->osoba)->get(route('add'))
            ->assertOk()
            ->assertDontSee(route('import.wybor'), false)
            ->assertSee(route('recipes.create'), false);

        $this->actingAs($this->osoba)->get(route('import.wybor'))
            ->assertOk()
            ->assertDontSee('Przepisz z kartki lub zeszytu')
            ->assertSee('Wpiszę sam');

        $this->actingAs($this->osoba)->get(route('import.zdjecie'))->assertRedirect(route('recipes.create'));

        $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => $this->kartka()])->assertSessionHasErrors('zdjecie');

        $this->assertSame(0, ImportPrzepisu::query()->count());
        Http::assertNothingSent();
    }

    public function test_kontrola_dodatnia_z_kluczem_przycisk_jest(): void
    {
        $this->actingAs($this->osoba)->get(route('add'))->assertSee(route('import.wybor'), false);
        $this->actingAs($this->osoba)->get(route('import.wybor'))
            ->assertSee('Przepisz z kartki lub zeszytu')
            ->assertSee('Wpiszę sam')
            // Źródła z innych etapów bez tras: przycisków nie ma (D-053).
            ->assertDontSee('Wklej adres strony')
            ->assertDontSee('Dodaj plik PDF');
    }

    // ------------------------------------------------------------------
    // Zgoda „odczyt AI” (D-296)
    // ------------------------------------------------------------------

    public function test_bez_zgody_jest_ekran_zgody_i_nic_nie_wychodzi(): void
    {
        Http::fake();

        $this->actingAs($this->osoba)->get(route('import.zdjecie'))
            ->assertOk()
            ->assertSee('Zdjęcie odczyta komputer firmy OpenAI')
            ->assertSee('Zgadzam się, odczytujcie moje kartki');

        $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => $this->kartka()])
            ->assertSessionHasErrors(['zdjecie' => 'Bez zgody na odczyt przez OpenAI możesz nadal dodać zdjęcie kartki do przepisu — tekst wpiszesz wtedy ręcznie.']);

        Http::assertNothingSent();
    }

    public function test_zgoda_z_ekranu_importu_trafia_do_dziennika_i_da_sie_ja_wycofac(): void
    {
        $this->actingAs($this->osoba)->post(route('zgoda.odczyt-ai.udziel'), ['skad' => 'import'])
            ->assertRedirect(route('import.zdjecie'));
        // Podwójne kliknięcie nie dopisuje drugiego wiersza.
        $this->actingAs($this->osoba)->post(route('zgoda.odczyt-ai.udziel'), ['skad' => 'import']);

        $this->actingAs($this->osoba)->get(route('settings.privacy'))->assertSee('Wycofaj zgodę na odczyt');
        $this->actingAs($this->osoba)->delete(route('zgoda.odczyt-ai.wycofaj'))->assertRedirect(route('settings.privacy'));

        $wpisy = WpisZgody::query()->where('user_id', $this->osoba->getKey())->where('cel', WpisZgody::CEL_ODCZYT_AI)
            ->orderBy('id')->get(['czynnosc', 'zrodlo'])->map(fn ($w) => [$w->czynnosc, $w->zrodlo])->all();

        $this->assertSame([['udzielona', 'ekran_importu'], ['wycofana', 'ustawienia']], $wpisy);
        $this->assertFalse(app(PrzestawZgodeNaOdczytAi::class)->udzielona($this->osoba));
    }

    public function test_wycofanie_zgody_dziala_takze_podczas_zawieszenia(): void
    {
        $this->zgoda();
        $this->osoba->suspend();

        $this->actingAs($this->osoba->fresh())->delete(route('zgoda.odczyt-ai.wycofaj'))->assertRedirect(route('settings.privacy'));

        $this->assertFalse(app(PrzestawZgodeNaOdczytAi::class)->udzielona($this->osoba));
    }

    public function test_zgoda_wycofana_miedzy_zleceniem_a_wysylka_to_zero_zadan(): void
    {
        Http::fake();
        $this->zgoda();
        $zlecenie = $this->zlecenieBezWysylki();
        app(PrzestawZgodeNaOdczytAi::class)->handle($this->osoba, false, WpisZgody::ZRODLO_USTAWIENIA);

        $this->app->call([new OdczytajPrzepis((string) $zlecenie->getKey()), 'handle']);

        Http::assertNothingSent();
        $this->assertSame(ImportPrzepisu::KOD_BRAK_ZGODY, $zlecenie->fresh()->kod_bledu);
        $this->assertSame(0, $this->budzetDzis());
    }

    public function test_usuniecie_konta_domyka_zgode_w_dzienniku(): void
    {
        $this->zgoda();
        $this->osoba->forceFill(['status' => User::STATUS_PENDING_DELETE, 'delete_requested_at' => now()->subDays(31)])->save();

        $this->assertTrue(app(EraseAccountData::class)->handle($this->osoba->fresh()));

        $this->assertSame(
            ['wycofana', 'usuniecie_konta'],
            WpisZgody::query()->where('user_id', $this->osoba->getKey())->where('cel', 'odczyt_ai')->orderByDesc('id')->get()->map(fn ($w) => [$w->czynnosc, $w->zrodlo])->first(),
        );
    }

    // ------------------------------------------------------------------
    // Pełny przepływ
    // ------------------------------------------------------------------

    public function test_zdjecie_kartki_daje_prywatny_szkic_nigdy_publikacje(): void
    {
        $this->zgoda();
        Http::fake(['api.openai.com/*' => Http::response($this->odpowiedzModelu(self::ODPOWIEDZ))]);

        $odpowiedz = $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => $this->kartka(2400, 3200)]);

        $zlecenie = ImportPrzepisu::query()->sole();
        $odpowiedz->assertRedirect(route('import.show', $zlecenie));
        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $zlecenie->status);

        $szkic = $zlecenie->recipe()->with(['ingredients', 'steps'])->first();
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->status);
        $this->assertSame('private', $szkic->visibility);
        $this->assertNull($szkic->published_at);
        $this->assertNotNull($szkic->source_scan_media_id);
        $this->assertSame('Sernik babci Hani', $szkic->title);
        $this->assertEquals(8, $szkic->servings);
        $this->assertSame(['1 kg twarogu', '1 szkl. [?cukru?]'], $szkic->ingredients->pluck('ingredient_text')->all());
        $this->assertSame(2, $szkic->steps->count());

        // Budżet rozliczony z usage: 1000 × 2 + 500 × 8 = 6000 mikro-USD.
        $this->assertSame(6000, $zlecenie->koszt_mikrousd);
        $this->assertSame(6000, $this->budzetDzis());

        $this->actingAs($this->osoba)->get(route('import.show', $zlecenie))
            ->assertOk()
            ->assertSee('aria-live="polite"', false)
            ->assertSee('Szkic gotowy do sprawdzenia')
            ->assertSee('Sprawdź i popraw szkic');
    }

    public function test_do_modelu_idzie_jpeg_do_2000_px_bez_metadanych_i_bez_danych_osoby(): void
    {
        $this->zgoda();
        Http::fake(['api.openai.com/*' => Http::response($this->odpowiedzModelu(self::ODPOWIEDZ))]);

        $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => $this->kartka(3000, 4000)]);

        Http::assertSentCount(1);
        Http::assertSent(function (Request $r): bool {
            $cialo = json_encode($r->data(), JSON_UNESCAPED_UNICODE) ?: '';
            $this->assertStringNotContainsString($this->osoba->email, $cialo);
            $this->assertStringNotContainsString((string) $this->osoba->getKey(), $cialo);
            $this->assertStringNotContainsString('Testowa osoba', $cialo);
            $this->assertFalse($r['store']);
            $this->assertSame(['effort' => 'medium'], $r['reasoning']);

            $obraz = collect($r['input'][0]['content'])->firstWhere('type', 'input_image');
            $this->assertStringStartsWith('data:image/jpeg;base64,', $obraz['image_url']);
            $bajty = (string) base64_decode(substr($obraz['image_url'], strlen('data:image/jpeg;base64,')));
            $rozmiar = getimagesizefromstring($bajty);
            $this->assertSame(IMAGETYPE_JPEG, $rozmiar[2]);
            $this->assertLessThanOrEqual(2000, max($rozmiar[0], $rozmiar[1]));
            $this->assertStringNotContainsString("Exif\x00\x00", $bajty);
            $this->assertStringNotContainsString('http://ns.adobe.com/xap/1.0/', $bajty);

            return true;
        });
    }

    public function test_podwojne_wyslanie_formularza_to_jedno_zlecenie(): void
    {
        $this->zgoda();
        Http::fake(['api.openai.com/*' => Http::response($this->odpowiedzModelu(self::ODPOWIEDZ))]);
        $klucz = (string) Str::uuid7();

        $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => $this->kartka(), 'klucz_wyslania' => $klucz]);
        $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => $this->kartka(), 'klucz_wyslania' => $klucz]);

        $this->assertSame(1, ImportPrzepisu::query()->count());
        $this->assertSame(1, Recipe::query()->where('author_id', $this->osoba->getKey())->count());
        Http::assertSentCount(1);
    }

    public function test_cudze_zlecenie_pod_uuid_to_odmowa(): void
    {
        $this->zgoda();
        $zlecenie = $this->zlecenieBezWysylki();
        $obcy = $this->user('obcy');

        $this->actingAs($obcy)->get(route('import.show', $zlecenie))->assertForbidden();
        $this->actingAs($obcy)->post(route('import.ponow', $zlecenie))->assertForbidden();
        $this->actingAs($obcy)->get(route('import.show', ['import' => 'to-nie-uuid']))->assertNotFound();
    }

    public function test_postep_bez_skryptu_ma_odnosnik_i_fragment_dla_skryptu(): void
    {
        $this->zgoda();
        $zlecenie = $this->zlecenieBezWysylki();

        $this->actingAs($this->osoba)->get(route('import.show', $zlecenie))
            ->assertOk()
            ->assertSee('Odczytujemy pismo')
            ->assertSee('Sprawdź, czy już gotowe')
            ->assertSee('data-postep-importu', false);

        $this->actingAs($this->osoba)->get(route('import.show', ['import' => $zlecenie, 'fragment' => 1]))
            ->assertOk()
            ->assertDontSee('<html', false)
            ->assertSee('data-koncowy="0"', false);
    }

    // ------------------------------------------------------------------
    // Limity, budżet, awarie — zdjęcie zawsze zostaje
    // ------------------------------------------------------------------

    public function test_limit_osoby_zapisuje_szkic_ze_zdjeciem_i_nie_pyta_modelu(): void
    {
        $this->zgoda();
        Http::fake();
        config(['kuking.import.limity.na_osobe_dzien' => 5]);

        for ($i = 0; $i < 5; $i++) {
            $this->zlecenieBezWysylki(ImportPrzepisu::STATUS_GOTOWY);
        }

        $this->actingAs($this->osoba)->get(route('import.zdjecie'))->assertSee('to dzienny limit');

        $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => $this->kartka()]);

        $zlecenie = ImportPrzepisu::query()->latest('created_at')->latest('id')->where('status', ImportPrzepisu::STATUS_WSTRZYMANY_LIMITEM)->sole();
        $this->assertSame(ImportPrzepisu::KOD_LIMIT_OSOBY, $zlecenie->kod_bledu);
        $this->assertNotNull($zlecenie->recipe->source_scan_media_id);
        Http::assertNothingSent();

        $this->actingAs($this->osoba)->get(route('import.show', $zlecenie))->assertSee('Twoje zdjęcie jest zapisane w szkicu');
    }

    public function test_limit_miesieczny_osoby(): void
    {
        $this->zgoda();
        Http::fake();
        config(['kuking.import.limity.na_osobe_dzien' => 100, 'kuking.import.limity.na_osobe_miesiac' => 2]);
        $this->zlecenieBezWysylki(ImportPrzepisu::STATUS_GOTOWY);
        $this->zlecenieBezWysylki(ImportPrzepisu::STATUS_NIEUDANY, ImportPrzepisu::KOD_MODEL_NIEDOSTEPNY);
        // Zatrzymane na limicie się nie liczą.
        $this->zlecenieBezWysylki(ImportPrzepisu::STATUS_WSTRZYMANY_LIMITEM, ImportPrzepisu::KOD_BUDZET_DZIENNY);

        $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => $this->kartka()]);

        $this->assertSame(1, ImportPrzepisu::query()->where('kod_bledu', ImportPrzepisu::KOD_LIMIT_OSOBY)->count());
        Http::assertNothingSent();
    }

    public function test_wyczerpany_budzet_dzienny_wstrzymuje_bez_wywolania(): void
    {
        $this->zgoda();
        Http::fake();
        DB::table('ai_budzet_dzienny')->insert(['dzien' => Czas::dzisiajData(), 'wydano_mikrousd' => 5_000_000, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->osoba)->get(route('import.zdjecie'))->assertSee('na dziś wstrzymane');
        $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => $this->kartka()]);

        $zlecenie = ImportPrzepisu::query()->sole();
        $this->assertSame(ImportPrzepisu::KOD_BUDZET_DZIENNY, $zlecenie->kod_bledu);
        Http::assertNothingSent();
        $this->actingAs($this->osoba)->get(route('import.show', $zlecenie))
            ->assertSee('Twoje zdjęcie jest zapisane')
            ->assertSee('Spróbuj jeszcze raz');
    }

    public function test_budzet_sprawdzany_takze_w_zadaniu_przed_wysylka(): void
    {
        $this->zgoda();
        Http::fake();
        $zlecenie = $this->zlecenieBezWysylki();
        DB::table('ai_budzet_dzienny')->insert(['dzien' => Czas::dzisiajData(), 'wydano_mikrousd' => 4_990_000, 'created_at' => now(), 'updated_at' => now()]);

        $this->app->call([new OdczytajPrzepis((string) $zlecenie->getKey()), 'handle']);

        Http::assertNothingSent();
        $this->assertSame(ImportPrzepisu::STATUS_WSTRZYMANY_LIMITEM, $zlecenie->fresh()->status);
        $this->assertSame(ImportPrzepisu::KOD_BUDZET_DZIENNY, $zlecenie->fresh()->kod_bledu);
    }

    public function test_awaria_modelu_konczy_sie_komunikatem_ze_zdjecie_zostalo_a_rezerwacja_jest_wydana(): void
    {
        $this->zgoda();
        Http::fake(['api.openai.com/*' => Http::response([], 503)]);

        $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => $this->kartka()]);

        $zlecenie = ImportPrzepisu::query()->sole();
        $this->assertSame(ImportPrzepisu::KOD_MODEL_NIEDOSTEPNY, $zlecenie->kod_bledu);
        $this->assertNotNull($zlecenie->recipe->source_scan_media_id);
        // Najgorszy przypadek: 6000 × 2 + 8000 × 8 = 76 000 mikro-USD.
        $this->assertSame(76_000, $this->budzetDzis());

        $this->actingAs($this->osoba)->get(route('import.show', $zlecenie))
            ->assertSee('Nic nie zginęło — zdjęcie jest zapisane.');
    }

    public function test_ponowienie_po_awarii_liczy_sie_do_limitu_i_wypelnia_ten_sam_szkic(): void
    {
        $this->zgoda();
        Http::fake(['api.openai.com/*' => Http::sequence()->push([], 503)->push($this->odpowiedzModelu(self::ODPOWIEDZ))]);

        $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => $this->kartka()]);
        $pierwsze = ImportPrzepisu::query()->sole();

        $this->actingAs($this->osoba)->post(route('import.ponow', $pierwsze))->assertRedirect();

        $drugie = ImportPrzepisu::query()->whereKeyNot($pierwsze->getKey())->sole();
        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $drugie->status);
        $this->assertSame($pierwsze->recipe_id, $drugie->recipe_id);
        $this->assertSame(1, Recipe::query()->count());
    }

    public function test_nieczytelne_zdjecie(): void
    {
        $this->zgoda();
        Http::fake(['api.openai.com/*' => Http::response($this->odpowiedzModelu(['nieczytelne' => true, 'tytul' => null, 'porcje' => null, 'skladniki' => [], 'kroki' => [], 'uwagi' => null]))]);

        $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => $this->kartka()]);

        $zlecenie = ImportPrzepisu::query()->sole();
        $this->assertSame(ImportPrzepisu::KOD_NIECZYTELNE, $zlecenie->kod_bledu);
        $this->actingAs($this->osoba)->get(route('import.show', $zlecenie))->assertSee('Zrób je w dziennym świetle, prosto z góry');
    }

    public function test_szkic_z_tekstem_czlowieka_nie_jest_nadpisywany(): void
    {
        $this->zgoda();
        Http::fake();
        $zlecenie = $this->zlecenieBezWysylki();
        $zlecenie->recipe->steps()->create(['position' => 1, 'instruction' => 'Mój własny krok.']);

        $this->app->call([new OdczytajPrzepis((string) $zlecenie->getKey()), 'handle']);

        Http::assertNothingSent();
        $this->assertSame(ImportPrzepisu::KOD_SZKIC_ZMIENIONY, $zlecenie->fresh()->kod_bledu);
        $this->assertSame(['Mój własny krok.'], $zlecenie->recipe->steps()->pluck('instruction')->all());
    }

    public function test_klucz_usuniety_po_zleceniu_konczy_bez_wywolania(): void
    {
        $this->zgoda();
        Http::fake();
        $zlecenie = $this->zlecenieBezWysylki();
        config(['kuking.import.model.klucz' => '']);

        $this->app->call([new OdczytajPrzepis((string) $zlecenie->getKey()), 'handle']);

        Http::assertNothingSent();
        $this->assertSame(ImportPrzepisu::KOD_WYLACZONY, $zlecenie->fresh()->kod_bledu);
    }

    // ------------------------------------------------------------------
    // Bramka publikacji: „Tekst sprawdzony” i znaczniki [?]
    // ------------------------------------------------------------------

    public function test_publikacja_szkicu_z_odczytu_wymaga_sprawdzenia_i_usuniecia_znacznikow(): void
    {
        $szkic = $this->gotowySzkic();
        $publikuj = fn (array $atrybuty, array $skladniki) => app(PublishRecipe::class)->handle(
            author: $this->osoba,
            attributes: ['title' => 'Sernik babci Hani', 'visibility' => 'public', ...$atrybuty],
            ingredients: $skladniki,
            steps: [['instruction' => 'Piec godzinę.']],
            publish: true,
            existing: $szkic->fresh(),
        );

        $bledy = null;
        try {
            $publikuj([], [['text' => '1 szkl. [?cukru?]']]);
        } catch (ValidationException $e) {
            $bledy = $e->errors();
        }
        $this->assertNotNull($bledy, 'Szkic z odczytu opublikował się bez sprawdzenia.');
        $this->assertArrayHasKey('odczyt_sprawdzony', $bledy);
        $this->assertSame(['Sprawdź słowo oznaczone [?] w 1. składniku i usuń znaczniki [? ?].'], $bledy['ingredients']);
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->fresh()->status);

        // Zaznaczone „Tekst sprawdzony”, ale znacznik został — dalej odmowa.
        $this->expectExceptionCount(fn () => $publikuj(['odczyt_sprawdzony' => true], [['text' => '1 szkl. [?cukru?]']]));
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->fresh()->status);

        // Kontrola dodatnia: sprawdzone i bez znaczników — publikuje się.
        $publikuj(['odczyt_sprawdzony' => true], [['text' => '1 szkl. cukru']]);
        $this->assertSame(Recipe::STATUS_PUBLISHED, $szkic->fresh()->status);

        // Później edytuje się zwyczajnie, bez ponownego „Tekst sprawdzony”.
        app(PublishRecipe::class)->handle($this->osoba, ['title' => 'Sernik babci Hani', 'visibility' => 'public'], [['text' => '1 szkl. cukru']], [['instruction' => 'Piec 70 minut.']], true, $szkic->fresh());
        $this->assertSame('Piec 70 minut.', $szkic->fresh()->steps()->value('instruction'));
    }

    public function test_zwykly_przepis_z_nawiasem_publikuje_sie_jak_dotad(): void
    {
        $przepis = app(PublishRecipe::class)->handle($this->osoba, ['title' => 'Zwykły', 'visibility' => 'private'], [['text' => 'sól [?]']], [['instruction' => 'Gotuj.']], false);

        app(PublishRecipe::class)->handle($this->osoba, ['title' => 'Zwykły', 'visibility' => 'public'], [['text' => 'sól [?]']], [['instruction' => 'Gotuj.']], true, $przepis);

        $this->assertSame(Recipe::STATUS_PUBLISHED, $przepis->fresh()->status);
    }

    public function test_kreator_pokazuje_baner_oryginal_i_blokuje_publikacje_przy_polu(): void
    {
        $szkic = $this->gotowySzkic();

        $kreator = Livewire::actingAs($this->osoba)->test('recipe-wizard', ['recipeId' => $szkic->getKey()])
            ->assertSet('zOdczytu', true)
            ->assertSee('Ten tekst odczytał komputer.')
            ->assertSee('Do sprawdzenia: 1 fragment oznaczony znakiem [?].');

        $kreator->set('step', 2)->assertSee('Zdjęcie kartki. Dotknij, żeby zobaczyć je w pełnym rozmiarze.');

        $kreator->set('step', 4)->call('publish')
            ->assertHasErrors(['ingredients.1.text', 'odczyt_sprawdzony'])
            ->assertSet('step', 2);
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->fresh()->status);

        $kreator->set('ingredients.1.text', '1 szkl. cukru')->set('odczytSprawdzony', true)->set('step', 4)->call('publish')
            ->assertHasNoErrors();
        $this->assertSame(Recipe::STATUS_PUBLISHED, $szkic->fresh()->status);
    }

    public function test_formularz_bez_javascriptu_ma_to_samo_pole_i_te_sama_bramke(): void
    {
        $szkic = $this->gotowySzkic();

        $this->actingAs($this->osoba)->get(route('recipes.edit', $szkic))
            ->assertOk()
            ->assertSee('Ten tekst odczytał komputer.')
            ->assertSee('Odczytany tekst jest sprawdzony ze zdjęciem');

        $this->actingAs($this->osoba)->put(route('recipes.update', $szkic), [
            'title' => 'Sernik babci Hani', 'visibility' => 'private', 'source_type' => 'own', 'action' => 'publish',
            'ingredients' => [['text' => '1 kg twarogu']], 'steps' => [['instruction' => 'Piec godzinę.']],
        ])->assertSessionHasErrors('odczyt_sprawdzony');
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->fresh()->status);
    }

    public function test_import_nigdy_nie_publikuje_sprawdzone_w_kodzie(): void
    {
        $pliki = [...glob(app_path('Domain/Import/*.php')) ?: [], app_path('Jobs/OdczytajPrzepis.php')];
        $this->assertGreaterThan(5, count($pliki), 'Skan nie znalazł plików importu — test nic nie mierzy.');

        foreach ($pliki as $plik) {
            $kod = (string) file_get_contents($plik);
            $this->assertDoesNotMatchRegularExpression('/publish:\s*true/', $kod, "{$plik} publikuje przepis.");
            $this->assertStringNotContainsString('STATUS_PUBLISHED', $kod, "{$plik} ustawia status opublikowany.");
        }

        // Kontrola dodatnia: skan widzi zapis szkicu.
        $this->assertMatchesRegularExpression('/publish:\s*false/', (string) file_get_contents(app_path('Jobs/OdczytajPrzepis.php')));
    }

    // ------------------------------------------------------------------

    private function expectExceptionCount(callable $akcja): void
    {
        try {
            $akcja();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('ingredients', $e->errors());

            return;
        }

        $this->fail('Publikacja przeszła mimo znacznika [?].');
    }

    private function zgoda(): void
    {
        app(PrzestawZgodeNaOdczytAi::class)->handle($this->osoba, true, WpisZgody::ZRODLO_EKRAN_IMPORTU);
    }

    private function kartka(int $szer = 1200, int $wys = 1600): UploadedFile
    {
        return UploadedFile::fake()->image('kartka.jpg', $szer, $wys);
    }

    /** Szkic ze zdjęciem i zlecenie, bez puszczania zadania (kolejka udawana). */
    private function zlecenieBezWysylki(string $status = ImportPrzepisu::STATUS_OCZEKUJE, ?string $kod = null): ImportPrzepisu
    {
        $media = app(StoreUploadedImage::class)->handle($this->osoba, $this->kartka());
        $szkic = app(PublishRecipe::class)->handle($this->osoba, [
            'title' => 'Przepis z kartki', 'visibility' => 'private', 'source_scan_media_id' => $media->getKey(),
        ], [], [], false);

        $zlecenie = new ImportPrzepisu;
        $zlecenie->forceFill([
            'user_id' => $this->osoba->getKey(),
            'recipe_id' => $szkic->getKey(),
            'zrodlo' => ImportPrzepisu::ZRODLO_ZDJECIE,
            'status' => $status,
            'kod_bledu' => $kod ?? ($status === ImportPrzepisu::STATUS_NIEUDANY || $status === ImportPrzepisu::STATUS_WSTRZYMANY_LIMITEM ? ImportPrzepisu::KOD_BLAD_WEWNETRZNY : null),
        ])->save();

        return $zlecenie;
    }

    private function gotowySzkic(): Recipe
    {
        $this->zgoda();
        Http::fake(['api.openai.com/*' => Http::response($this->odpowiedzModelu(self::ODPOWIEDZ))]);
        $this->actingAs($this->osoba)->post(route('import.zlec'), ['zdjecie' => $this->kartka()]);

        return ImportPrzepisu::query()->sole()->recipe;
    }

    private function budzetDzis(): int
    {
        $wiersz = DB::table('ai_budzet_dzienny')->where('dzien', Czas::dzisiajData())->first();

        return $wiersz === null ? 0 : (int) $wiersz->zarezerwowano_mikrousd + (int) $wiersz->wydano_mikrousd;
    }

    /**
     * @param  array<string, mixed>  $dane
     * @return array<string, mixed>
     */
    private function odpowiedzModelu(array $dane): array
    {
        return [
            'id' => 'resp_test',
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($dane)]]]],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ];
    }
}

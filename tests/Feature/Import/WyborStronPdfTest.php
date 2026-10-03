<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\OdzyskanieImportow;
use App\Domain\Import\Pdf\TekstZPdf;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Zgody\InformacjaTekstuZrodlaAi;
use App\Models\ImportPrzepisu;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Storage\PoczekalniaPdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\MalyPdf;
use Tests\TestCase;

/**
 * Wybór stron krótkiego PDF-a przed odczytem przepisu (#2535, V2, decyzja
 * właściciela z 2.10.2026).
 *
 * Prawdziwy Poppler i prawdziwe małe PDF-y (`MalyPdf`); kolejka jest `sync`, więc
 * podgląd powstaje od razu. Model tylko przez `Http::fake`: atrapa odbiorcy
 * liczy obrazy w żądaniu i sprawdza, że obraz niewybranej strony nie został
 * wysłany. Każda scena ujemna ma kontrolę dodatnią obok.
 *
 * @bez-kontroli-dodatniej Test uruchamia prawdziwe narzędzia, kolejkę i bazę oraz asertuje na wynikach (zapisany szkic, liczba obrazów w żądaniu do modelu, pliki na dysku), nie na treści plików źródłowych.
 */
final class WyborStronPdfTest extends TestCase
{
    use RefreshDatabase;

    private string $dysk;

    protected function setUp(): void
    {
        parent::setUp();

        if (Process::run(['pdftoppm', '-v'])->exitCode() === 127) {
            $this->markTestSkipped('Brak pdftoppm w środowisku testów — zainstaluj pakiet systemowy poppler-utils.');
        }

        config(['kuking.import.url.wlaczony' => true, 'kuking.import.pdf.wlaczony' => true, 'kuking.import.zrodla.zdjecie' => true]);
        $this->dysk = (string) config('kuking.import.pdf.dysk');
        Storage::fake($this->dysk);
    }

    private function pdfZTrzemaStronami(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('rodzinny.pdf', MalyPdf::zTekstem([
            ['Spis rodzinnych zapiskow', 'Strona druga: sernik', 'Strona trzecia: zupa'],
            ['Sernik z PDF', 'Skladniki', '1 kg twarogu', '5 jaj', 'Przygotowanie', '1. Utrzyj twarog.', '2. Piecz godzine.'],
            ['Zupa Grzybowa', 'Skladniki', '200 g grzybow', '1 cebula', 'Przygotowanie', '1. Gotuj grzyby.', '2. Dodaj cebule.'],
        ]));
    }

    private function skanTrzechStron(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('skan.pdf', MalyPdf::bezTekstu(3));
    }

    /** Wysyła plik drogą „najpierw wybiorę strony” i zwraca token z adresu przekierowania. */
    private function wyslijDoWyboru(User $osoba, UploadedFile $plik): string
    {
        $odpowiedz = $this->actingAs($osoba)->post(route('recipes.import.pdf.wybor.przyjmij'), ['plik' => $plik]);
        $odpowiedz->assertRedirect();

        $this->assertMatchesRegularExpression('~/wybor/([0-9a-f-]{36})$~', (string) $odpowiedz->headers->get('Location'));
        preg_match('~/wybor/([0-9a-f-]{36})$~', (string) $odpowiedz->headers->get('Location'), $m);

        return $m[1];
    }

    /** @return list<string> */
    private function pliki(): array
    {
        return Storage::disk($this->dysk)->allFiles((string) config('kuking.import.pdf.katalog_wyboru'));
    }

    private function modelTestowy(): void
    {
        config([
            'kuking.import.model.klucz' => 'sk-test-import',
            'kuking.import.model.nazwa' => 'gpt-6-luna',
            'kuking.import.model.cena_wejscie_mln_usd' => '2',
            'kuking.import.model.cena_wyjscie_mln_usd' => '8',
        ]);
    }

    /** @return array<string, mixed> */
    private function odpowiedzModelu(): array
    {
        $dane = [
            'nieczytelne' => false, 'tytul' => 'Sernik ze skanu', 'porcje' => '8',
            'skladniki' => [['tekst' => '1 kg twarogu', 'grupa' => null]],
            'kroki' => [['tekst' => 'Piecz godzinę.']], 'uwagi' => null,
        ];

        return [
            'status' => 'completed',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($dane)]]]],
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ];
    }

    private function obrazowWZadaniu(Request $zadanie): int
    {
        return substr_count((string) json_encode($zadanie->data(), JSON_UNESCAPED_SLASHES), 'data:image/jpeg;base64,');
    }

    // -----------------------------------------------------------------

    public function test_formularz_pdf_ma_osobny_przycisk_wyboru_stron_obok_dotychczasowego(): void
    {
        $this->actingAs($this->user())->get(route('recipes.import.pdf'))
            ->assertOk()
            ->assertSee('Zapisz jako szkic')
            ->assertSee('Najpierw wybiorę strony z przepisem')
            ->assertSee('formaction="'.route('recipes.import.pdf.wybor.przyjmij').'"', false);
    }

    public function test_podglad_pokazuje_podpisane_strony_bez_wywolania_ai_i_bez_zajmowania_limitu(): void
    {
        Http::fake();
        $osoba = $this->user();

        $token = $this->wyslijDoWyboru($osoba, $this->pdfZTrzemaStronami());

        $html = $this->actingAs($osoba)->get(route('recipes.import.pdf.wybor', $token))
            ->assertOk()
            ->assertHeader('Cache-Control')
            ->getContent();

        foreach ([1, 2, 3] as $numer) {
            $this->assertStringContainsString('Strona '.$numer.' z 3', $html);
            $this->assertStringContainsString(route('recipes.import.pdf.miniatura', [$token, $numer]), $html);
            $this->assertStringContainsString('name="strony[]" value="'.$numer.'"', $html);
        }
        // Podpis dla osób, które nie rozpoznają przepisu po obrazku.
        $this->assertStringContainsString('Sernik z PDF', $html);
        $this->assertStringContainsString('Odczytaj zaznaczone strony', $html);
        $this->assertStringContainsString('Zgoda na wysłanie do OpenAI', $html);

        // Podgląd nie jest odczytem: żadnego zlecenia, próby w limicie ani żądania do sieci.
        $this->assertSame(0, ImportPrzepisu::query()->count());
        $this->assertSame(0, DB::table('proby_importu')->count());
        Http::assertNothingSent();
        $this->assertCount(1 + 1 + 3, $this->pliki(), 'Oczekiwane: plik, opis i trzy miniatury.');
    }

    public function test_miniatury_widzi_tylko_wlasciciel_i_nie_sa_cache_owane(): void
    {
        $osoba = $this->user();
        $obca = $this->user();
        $token = $this->wyslijDoWyboru($osoba, $this->pdfZTrzemaStronami());
        $adres = route('recipes.import.pdf.miniatura', [$token, 2]);

        $odpowiedz = $this->actingAs($osoba)->get($adres)->assertOk();
        $this->assertSame('image/jpeg', $odpowiedz->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $odpowiedz->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $odpowiedz->headers->get('Cache-Control'));
        $this->assertStringStartsWith("\xFF\xD8", (string) $odpowiedz->getContent());

        $this->actingAs($obca)->get($adres)->assertNotFound();
        $this->actingAs($osoba)->get(route('recipes.import.pdf.miniatura', [$token, 9]))->assertNotFound();
        auth()->logout();
        $this->get($adres)->assertRedirect(route('login'));
    }

    public function test_wybor_jednej_strony_tekstowego_pdf_daje_szkic_tylko_z_tej_strony(): void
    {
        Http::fake();
        $osoba = $this->user();
        $token = $this->wyslijDoWyboru($osoba, $this->pdfZTrzemaStronami());

        $odpowiedz = $this->actingAs($osoba)->post(route('recipes.import.pdf.wybor.store', $token), ['strony' => ['2']]);

        $zlecenie = ImportPrzepisu::query()->where('user_id', $osoba->getKey())->firstOrFail();
        $odpowiedz->assertRedirect(route('import.show', $zlecenie));
        $odpowiedz->assertSessionHas('status', fn (string $tresc): bool => str_contains($tresc, 'strona 2') && str_contains($tresc, 'Pozostałych stron pliku nie czytamy'));

        $szkic = Recipe::query()->where('author_id', $osoba->getKey())->firstOrFail();
        $this->assertSame('Sernik z PDF', $szkic->title);
        $this->assertSame('private', $szkic->visibility);
        $this->assertSame(Recipe::STATUS_DRAFT, $szkic->status);
        $tekstSzkicu = $szkic->title.' '.$szkic->ingredients()->pluck('ingredient_text')->implode(' ').' '.$szkic->steps()->pluck('instruction')->implode(' ');
        $this->assertStringContainsString('twarogu', $tekstSzkicu);
        $this->assertStringNotContainsString('grzyb', mb_strtolower($tekstSzkicu), 'Do szkicu trafił tekst niewybranej strony.');
        Http::assertNothingSent();

        // Poczekalnia sprzątnięta po zatwierdzeniu.
        $this->assertSame([], $this->pliki());
    }

    public function test_wybor_trzeciej_strony_czyta_trzecia_a_kolejnosc_stron_zostaje_oryginalna(): void
    {
        $plik = $this->pdfZTrzemaStronami();
        $tekst = app(TekstZPdf::class);

        // Wybór podany w odwrotnej kolejności i z powtórzeniem: czytamy 2, potem 3.
        $wynik = $tekst->odczytaj($plik->getRealPath(), TekstZPdf::normalizujWybor([3, 2, 3, 9, 0], 3));
        $this->assertSame([2, 3], TekstZPdf::normalizujWybor([3, 2, 3, 9, 0, -1], 3));
        $this->assertLessThan(mb_strpos($wynik, 'Zupa Grzybowa'), mb_strpos($wynik, 'Sernik z PDF'));
        $this->assertStringNotContainsString('Spis rodzinnych zapiskow', $wynik);

        $tylkoTrzecia = $tekst->odczytaj($plik->getRealPath(), [3]);
        $this->assertStringContainsString('Zupa Grzybowa', $tylkoTrzecia);
        $this->assertStringNotContainsString('Sernik z PDF', $tylkoTrzecia);

        // Kontrola dodatnia: bez wyboru czytamy wszystko, jak dotąd.
        $wszystko = $tekst->odczytaj($plik->getRealPath());
        $this->assertStringContainsString('Spis rodzinnych zapiskow', $wszystko);
        $this->assertStringContainsString('Sernik z PDF', $wszystko);
        $this->assertStringContainsString('Zupa Grzybowa', $wszystko);
    }

    public function test_brak_wyboru_i_numery_spoza_pliku_nie_wysylaja_niczego(): void
    {
        Http::fake();
        $osoba = $this->user();
        $token = $this->wyslijDoWyboru($osoba, $this->pdfZTrzemaStronami());
        $adres = route('recipes.import.pdf.wybor.store', $token);

        $this->actingAs($osoba)->from(route('recipes.import.pdf.wybor', $token))->post($adres, [])
            ->assertSessionHasErrors(['strony' => 'Zaznacz co najmniej jedną stronę, na której jest przepis. Nic nie zostało odczytane ani wysłane.']);

        foreach ([['9'], ['0'], ['abc'], ['2', '9'], ['2', '2']] as $zle) {
            $this->actingAs($osoba)->from(route('recipes.import.pdf.wybor', $token))->post($adres, ['strony' => $zle])
                ->assertSessionHasErrors('strony');
        }

        $this->assertSame(0, ImportPrzepisu::query()->count());
        $this->assertSame(0, DB::table('proby_importu')->count());
        Http::assertNothingSent();
        $this->assertNotEmpty($this->pliki(), 'Błędny wybór nie może kasować pliku — da się wybrać jeszcze raz.');

        // Kontrola dodatnia: poprawny wybór z tego samego tokenu przechodzi.
        $this->actingAs($osoba)->post($adres, ['strony' => ['2']])->assertRedirect();
        $this->assertSame(1, ImportPrzepisu::query()->count());
    }

    public function test_skan_do_modelu_idzie_tylko_z_zaznaczonymi_stronami_po_jawnej_zgodzie(): void
    {
        $this->modelTestowy();
        Http::fake(['api.openai.com/*' => Http::response($this->odpowiedzModelu())]);
        $osoba = $this->user();
        $token = $this->wyslijDoWyboru($osoba, $this->skanTrzechStron());

        $this->actingAs($osoba)->post(route('recipes.import.pdf.wybor.store', $token), [
            'strony' => ['2', '3'],
            'zgoda_ai' => '1',
            InformacjaTekstuZrodlaAi::POLE => InformacjaTekstuZrodlaAi::WERSJA,
        ])->assertRedirect();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $zadanie): bool => $this->obrazowWZadaniu($zadanie) === 2);
        $this->assertSame('Sernik ze skanu', Recipe::query()->where('author_id', $osoba->getKey())->firstOrFail()->title);
    }

    public function test_jedna_wybrana_strona_skanu_to_jeden_obraz_w_zadaniu_do_modelu(): void
    {
        $this->modelTestowy();
        Http::fake(['api.openai.com/*' => Http::response($this->odpowiedzModelu())]);
        $osoba = $this->user();
        $token = $this->wyslijDoWyboru($osoba, $this->skanTrzechStron());

        $this->actingAs($osoba)->post(route('recipes.import.pdf.wybor.store', $token), [
            'strony' => ['2'],
            'zgoda_ai' => '1',
            InformacjaTekstuZrodlaAi::POLE => InformacjaTekstuZrodlaAi::WERSJA,
        ])->assertRedirect();

        Http::assertSent(fn (Request $zadanie): bool => $this->obrazowWZadaniu($zadanie) === 1);
    }

    public function test_dotychczasowa_droga_bez_wyboru_wysyla_wszystkie_strony_skanu(): void
    {
        // Kontrola dodatnia dla dwóch testów wyżej: bez wyboru do modelu idą 3 obrazy.
        $this->modelTestowy();
        Http::fake(['api.openai.com/*' => Http::response($this->odpowiedzModelu())]);
        $osoba = $this->user();

        $this->actingAs($osoba)->post(route('recipes.import.pdf.store'), [
            'plik' => $this->skanTrzechStron(),
            'zgoda_ai' => '1',
            InformacjaTekstuZrodlaAi::POLE => InformacjaTekstuZrodlaAi::WERSJA,
        ])->assertRedirect();

        Http::assertSent(fn (Request $zadanie): bool => $this->obrazowWZadaniu($zadanie) === 3);
    }

    public function test_wybranie_stron_nie_jest_zgoda_skan_bez_zgody_nic_nie_wysyla(): void
    {
        $this->modelTestowy();
        Http::fake();
        $osoba = $this->user();
        $token = $this->wyslijDoWyboru($osoba, $this->skanTrzechStron());

        $this->actingAs($osoba)->post(route('recipes.import.pdf.wybor.store', $token), ['strony' => ['1']])->assertRedirect();

        $zlecenie = ImportPrzepisu::query()->where('user_id', $osoba->getKey())->firstOrFail();
        $this->assertSame(ImportPrzepisu::KOD_BRAK_ZGODY, $zlecenie->kod_bledu);
        $this->actingAs($osoba)->get(route('import.show', $zlecenie))
            ->assertOk()->assertSee(ImportOdrzucony::KOMUNIKATY[ImportOdrzucony::BRAK_ZGODY_AI]);
        Http::assertNothingSent();
        $this->assertSame(0, Recipe::query()->count());
    }

    public function test_cala_za_dlugi_plik_jest_odrzucony_mimo_wyboru_jednej_strony(): void
    {
        $osoba = $this->user();
        $szescStron = UploadedFile::fake()->createWithContent('dluga.pdf', MalyPdf::zTekstem(array_fill(0, 6, ['Sernik z PDF', 'Skladniki', '1 kg twarogu', 'Przygotowanie', '1. Piecz.'])));

        $token = $this->wyslijDoWyboru($osoba, $szescStron);

        $this->actingAs($osoba)->get(route('recipes.import.pdf.wybor', $token))
            ->assertRedirect(route('recipes.import.pdf'))
            ->assertSessionHasErrors('plik');
        $this->assertStringContainsString('za dużo stron', (string) session('errors')->first('plik'));
        $this->assertSame([], $this->pliki(), 'Odrzucony plik nie zostaje w poczekalni.');
        $this->assertSame(0, ImportPrzepisu::query()->count());

        // Kontrola dodatnia: plik mieszczący się w limicie przechodzi do wyboru.
        $this->wyslijDoWyboru($osoba, $this->pdfZTrzemaStronami());
        $this->assertNotEmpty($this->pliki());
    }

    public function test_plik_udajacy_pdf_jest_odrzucany_od_razu_bez_zapisu_w_poczekalni(): void
    {
        $osoba = $this->user();

        $this->actingAs($osoba)
            ->post(route('recipes.import.pdf.wybor.przyjmij'), ['plik' => UploadedFile::fake()->createWithContent('x.pdf', '<script>alert(1)</script>')])
            ->assertSessionHasErrors(['plik' => ImportOdrzucony::KOMUNIKATY[ImportOdrzucony::PDF_USZKODZONY]]);

        $this->assertSame([], $this->pliki());
    }

    public function test_zmiana_pliku_daje_nowy_token_a_wybor_sprawdza_sie_wzgledem_tego_pliku(): void
    {
        Http::fake();
        $osoba = $this->user();
        $staryToken = $this->wyslijDoWyboru($osoba, $this->pdfZTrzemaStronami());
        $jednaStrona = UploadedFile::fake()->createWithContent('inny.pdf', MalyPdf::zTekstem([['Pierogi', 'Skladniki', '1 kg maki', 'Przygotowanie', '1. Zagnies ciasto.']]));
        $nowyToken = $this->wyslijDoWyboru($osoba, $jednaStrona);

        $this->assertNotSame($staryToken, $nowyToken);

        // Strona 3 istnieje w starym pliku, ale nie w nowym.
        $this->actingAs($osoba)->from(route('recipes.import.pdf.wybor', $nowyToken))
            ->post(route('recipes.import.pdf.wybor.store', $nowyToken), ['strony' => ['3']])
            ->assertSessionHasErrors('strony');
        $this->assertSame(0, ImportPrzepisu::query()->count());

        // Kontrola dodatnia: ta sama strona przechodzi w starym tokenie.
        $this->actingAs($osoba)->post(route('recipes.import.pdf.wybor.store', $staryToken), ['strony' => ['3']])->assertRedirect();
        $this->assertSame('Zupa Grzybowa', Recipe::query()->where('author_id', $osoba->getKey())->firstOrFail()->title);
    }

    public function test_cudze_konto_nie_wybierze_ani_nie_zatwierdzi_cudzego_pliku(): void
    {
        Http::fake();
        $osoba = $this->user();
        $obca = $this->user();
        $token = $this->wyslijDoWyboru($osoba, $this->pdfZTrzemaStronami());

        $this->actingAs($obca)->get(route('recipes.import.pdf.wybor', $token))->assertRedirect(route('recipes.import.pdf'));
        $this->actingAs($obca)->post(route('recipes.import.pdf.wybor.store', $token), ['strony' => ['2']])->assertRedirect(route('recipes.import.pdf'));
        $this->actingAs($obca)->delete(route('recipes.import.pdf.wybor.destroy', $token))->assertRedirect(route('recipes.import.pdf'));

        $this->assertSame(0, ImportPrzepisu::query()->count());
        $this->assertNotEmpty($this->pliki(), 'Plik właściciela został naruszony przez cudze konto.');

        // Kontrola dodatnia: właściciel ten sam token otwiera.
        $this->actingAs($osoba)->get(route('recipes.import.pdf.wybor', $token))->assertOk()->assertSee('Strona 2 z 3');
    }

    public function test_dwuklik_na_zatwierdzeniu_nie_mnozy_zlecen_ani_wywolan_modelu(): void
    {
        $this->modelTestowy();
        Http::fake(['api.openai.com/*' => Http::response($this->odpowiedzModelu())]);
        $osoba = $this->user();
        $token = $this->wyslijDoWyboru($osoba, $this->skanTrzechStron());
        $klucz = (string) Str::uuid();
        $dane = [
            'strony' => ['1'], 'klucz_wyslania' => $klucz, 'zgoda_ai' => '1',
            InformacjaTekstuZrodlaAi::POLE => InformacjaTekstuZrodlaAi::WERSJA,
        ];

        $pierwsza = $this->actingAs($osoba)->post(route('recipes.import.pdf.wybor.store', $token), $dane);
        $druga = $this->actingAs($osoba)->post(route('recipes.import.pdf.wybor.store', $token), $dane);

        $druga->assertRedirect($pierwsza->headers->get('Location'));
        Http::assertSentCount(1);
        $this->assertSame(1, ImportPrzepisu::query()->count());
        $this->assertSame(1, Recipe::query()->where('author_id', $osoba->getKey())->count());
    }

    public function test_odrzucenie_kasuje_plik_miniatury_i_opis_bez_odczytu(): void
    {
        Http::fake();
        $osoba = $this->user();
        $token = $this->wyslijDoWyboru($osoba, $this->pdfZTrzemaStronami());
        $this->assertNotEmpty($this->pliki());

        $this->actingAs($osoba)->delete(route('recipes.import.pdf.wybor.destroy', $token))
            ->assertRedirect(route('recipes.import.pdf'));

        $this->assertSame([], $this->pliki());
        $this->assertSame(0, ImportPrzepisu::query()->count());
        Http::assertNothingSent();
    }

    public function test_porzucony_podglad_znika_po_retencji_a_swiezy_zostaje(): void
    {
        $osoba = $this->user();
        $stary = $this->wyslijDoWyboru($osoba, $this->pdfZTrzemaStronami());

        // Czas zapisu pliku na dysku jest prawdziwy, więc „stary” robimy przesunięciem jego daty.
        $godziny = (int) config('kuking.import.pdf.wybor_stron_godziny') + 1;
        $plikStarego = collect($this->pliki())->first(fn (string $f): bool => str_ends_with($f, 'plik.pdf'));
        $this->assertTrue(touch(Storage::disk($this->dysk)->path((string) $plikStarego), time() - $godziny * 3600));
        $swiezy = $this->wyslijDoWyboru($osoba, $this->pdfZTrzemaStronami());

        $wynik = app(OdzyskanieImportow::class)->odzyskaj();

        $this->assertGreaterThanOrEqual(1, $wynik['pliki']);
        $this->actingAs($osoba)->get(route('recipes.import.pdf.wybor', $stary))->assertRedirect(route('recipes.import.pdf'));
        $this->actingAs($osoba)->get(route('recipes.import.pdf.wybor', $swiezy))->assertOk();
    }

    public function test_najwyzej_kilka_oczekujacych_plikow_na_osobe_najstarszy_ustepuje(): void
    {
        $osoba = $this->user();
        $max = (int) config('kuking.import.pdf.wybor_stron_max_oczekujacych');
        $tokeny = [];

        for ($i = 0; $i < $max + 1; $i++) {
            $tokeny[] = $this->wyslijDoWyboru($osoba, $this->pdfZTrzemaStronami());
        }

        $poczekalnia = app(PoczekalniaPdf::class);
        $zyjace = array_filter($tokeny, fn (string $t): bool => $poczekalnia->opis($osoba, $t) !== null);
        $this->assertCount($max, $zyjace);
        $this->assertContains(end($tokeny), $zyjace, 'Najnowszy plik musi zostać.');
    }

    public function test_wymazanie_konta_kasuje_poczekalnie(): void
    {
        $osoba = $this->user();
        $this->wyslijDoWyboru($osoba, $this->pdfZTrzemaStronami());
        $this->assertNotEmpty($this->pliki());

        $osoba->markForDeletion();
        app(EraseAccountData::class)->handle($osoba->fresh());

        $this->assertSame([], $this->pliki());
    }
}

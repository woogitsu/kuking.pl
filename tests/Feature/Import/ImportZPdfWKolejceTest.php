<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Domain\Import\ImportOdrzucony;
use App\Domain\Import\KlientLuna;
use App\Domain\Import\OdzyskanieImportow;
use App\Domain\Users\Actions\EraseAccountData;
use App\Domain\Zgody\InformacjaTekstuZrodlaAi;
use App\Jobs\ImportujPrzepisZPdf;
use App\Models\ImportPrzepisu;
use App\Models\Recipe;
use App\Models\User;
use App\Support\Storage\PlikTymczasowyImportu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\MalyPdf;
use Tests\TestCase;

/**
 * Import z pliku PDF poza żądaniem WWW (#28, etap 2, D-300) razem z retencją
 * pliku tymczasowego (#2051).
 *
 * Wysłanie formularza tylko zleca: zapisuje plik na prywatny dysk importu,
 * trwałe zlecenie i zadanie, a Popplera i model obsługuje worker. Ten plik
 * mierzy tę granicę i to, że PLIK ZNIKA w każdym stanie końcowym: sukces,
 * odmowa, `failed()`, wyłączone źródło, usunięcie konta, a po czasie także
 * wtedy, gdy nikt go już nie wskazuje. Zawartość odczytu (warstwa tekstu, OCR,
 * budżet) pilnuje `ImportPrzepisuZAdresuIPdfTest`, gdzie kolejka jest `sync`.
 *
 * Sieć i model tylko przez `Http::fake`; dysk to `Storage::fake`.
 */
final class ImportZPdfWKolejceTest extends TestCase
{
    use RefreshDatabase;

    private string $dysk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dysk = (string) config('kuking.import.pdf.dysk');
        Storage::fake($this->dysk);
    }

    private function katalog(): string
    {
        return (string) config('kuking.import.pdf.katalog');
    }

    /** @return list<string> */
    private function pliki(): array
    {
        return Storage::disk($this->dysk)->allFiles($this->katalog());
    }

    private function pdfZTekstem(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('sernik.pdf', MalyPdf::zTekstem([
            ['Sernik z PDF', 'Skladniki', '1 kg twarogu', '5 jaj', 'Przygotowanie', '1. Utrzyj twarog.', '2. Piecz godzine.'],
        ]));
    }

    /** @return array<string, mixed> */
    private function dane(?UploadedFile $plik = null, ?string $klucz = null): array
    {
        return ['plik' => $plik ?? $this->pdfZTekstem(), 'klucz_wyslania' => $klucz ?? (string) Str::uuid()];
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

    /** Zlecenie „oczekuje” z plikiem na dysku, bez uruchamiania zadania. */
    private function zlecenieZPlikiem(User $autor, string $zawartosc = ''): ImportPrzepisu
    {
        Queue::fake();
        Http::fake();
        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane(
            $zawartosc === '' ? null : UploadedFile::fake()->createWithContent('a.pdf', $zawartosc),
        ))->assertRedirect();

        return ImportPrzepisu::query()->where('user_id', $autor->getKey())->firstOrFail();
    }

    public function test_wyslanie_pliku_nie_uruchamia_popplera_ani_sieci_tylko_zapisuje_plik_zlecenie_i_zadanie(): void
    {
        $autor = $this->user();
        Queue::fake();
        Http::fake();
        Process::fake();

        $odpowiedz = $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane() + ['zgoda_ai' => '1', InformacjaTekstuZrodlaAi::POLE => InformacjaTekstuZrodlaAi::WERSJA]);

        $zlecenie = ImportPrzepisu::query()->where('user_id', $autor->getKey())->firstOrFail();

        $odpowiedz->assertRedirect(route('import.show', $zlecenie));
        Http::assertNothingSent();
        Process::assertNothingRan();
        $this->assertSame(ImportPrzepisu::ZRODLO_PDF, $zlecenie->zrodlo);
        $this->assertSame(ImportPrzepisu::STATUS_OCZEKUJE, $zlecenie->status);
        $this->assertNull($zlecenie->recipe_id, 'Szkic powstaje dopiero po odczycie.');
        $this->assertSame(0, Recipe::query()->count());
        $this->assertSame($zlecenie->getKey(), DB::table('proby_importu')->where('user_id', $autor->getKey())->value('import_id'));

        $this->assertStringStartsWith($this->katalog().'/', (string) $zlecenie->plik_tymczasowy);
        $this->assertSame([$zlecenie->plik_tymczasowy], $this->pliki(), 'Kontrola dodatnia: plik leży na dysku importu.');
        $this->assertStringStartsWith('%PDF-', (string) Storage::disk($this->dysk)->get((string) $zlecenie->plik_tymczasowy));
        $this->assertStringNotContainsString('sernik', (string) $zlecenie->plik_tymczasowy, 'Nazwa pliku od człowieka nie istnieje na dysku.');

        Queue::assertPushed(ImportujPrzepisZPdf::class, fn (ImportujPrzepisZPdf $z): bool => $z->importId === $zlecenie->getKey()
            && $z->zgodaAi === true && $z->queue === 'low');
        Queue::assertPushed(ImportujPrzepisZPdf::class, 1);

        $this->actingAs($autor)->get(route('import.show', $zlecenie))
            ->assertOk()
            ->assertSee('Przepis z pliku PDF')
            ->assertSee('Odczytujemy plik')
            ->assertSee('Sprawdź, czy już gotowe')
            ->assertDontSee('zdjęcie kartki jest już przy nim');
    }

    public function test_zadanie_nie_niesie_pliku_ani_sciezki_a_zgoda_jest_tylko_z_tego_formularza(): void
    {
        $autor = $this->user();
        Queue::fake();

        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane());

        Queue::assertPushed(ImportujPrzepisZPdf::class, function (ImportujPrzepisZPdf $z): bool {
            $this->assertFalse($z->zgodaAi, 'Bez zaznaczenia w formularzu zadanie nie ma zgody.');
            $this->assertStringNotContainsString($this->katalog(), serialize($z), 'Ścieżka stoi w zleceniu, nie w zadaniu.');
            $this->assertStringNotContainsString('%PDF', serialize($z));

            return true;
        });
    }

    public function test_plik_udajacy_pdf_jest_odrzucony_przed_zleceniem_bez_pliku_i_bez_miejsca_w_limicie(): void
    {
        $autor = $this->user();
        Queue::fake();

        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane(
            UploadedFile::fake()->createWithContent('przepis.pdf', '<script>alert(1)</script>'),
        ))->assertSessionHasErrors(['plik' => ImportOdrzucony::KOMUNIKATY[ImportOdrzucony::PDF_USZKODZONY]]);

        $this->assertSame(0, ImportPrzepisu::query()->count());
        $this->assertSame(0, DB::table('proby_importu')->count());
        $this->assertSame([], $this->pliki());
        Queue::assertNothingPushed();
    }

    public function test_zbyt_duzy_plik_jest_odrzucony_przed_zapisem_na_dysk(): void
    {
        $autor = $this->user();
        Queue::fake();
        config(['kuking.import.pdf.max_mb' => 1]);

        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane(
            UploadedFile::fake()->createWithContent('duzy.pdf', '%PDF-1.4'.str_repeat('a', 1024 * 1024 + 10)),
        ))->assertSessionHasErrors(['plik']);

        $this->assertSame(0, ImportPrzepisu::query()->count());
        $this->assertSame([], $this->pliki());
        Queue::assertNothingPushed();
    }

    public function test_powtorzone_wyslanie_tego_samego_formularza_to_jedno_zlecenie_jeden_plik_jedno_zadanie_jedno_miejsce_w_limicie(): void
    {
        $autor = $this->user();
        Queue::fake();
        $klucz = (string) Str::uuid();

        $pierwsza = $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane(null, $klucz));
        $druga = $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane(null, $klucz));

        $druga->assertRedirect($pierwsza->headers->get('Location'));
        $this->assertSame(1, ImportPrzepisu::query()->count());
        $this->assertSame(1, DB::table('proby_importu')->count());
        $this->assertCount(1, $this->pliki(), 'Drugi plik z tego samego formularza nie zostaje na dysku.');
        Queue::assertPushed(ImportujPrzepisZPdf::class, 1);
    }

    public function test_limit_osoby_zatrzymuje_zlecenie_przed_kolejka_i_nie_zostawia_pliku(): void
    {
        $autor = $this->user();
        Queue::fake();
        config(['kuking.import.limity.na_osobe_dzien' => 1]);

        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane())->assertSessionHasNoErrors();
        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane())->assertSessionHasErrors(['plik']);

        $this->assertSame(1, ImportPrzepisu::query()->count());
        $this->assertCount(1, $this->pliki(), 'Plik odrzuconego zlecenia jest kasowany od razu.');
        Queue::assertPushed(ImportujPrzepisZPdf::class, 1);
    }

    public function test_prawdziwy_worker_odtwarza_wejscie_z_dysku_i_po_sukcesie_kasuje_plik(): void
    {
        $autor = $this->user();
        config(['queue.default' => 'database']);
        Http::fake();

        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane())->assertRedirect();

        // Przed workerem: zadanie czeka w tabeli `jobs`, plik leży na dysku, nikt go nie czytał.
        $this->assertSame(1, DB::table('jobs')->where('queue', 'low')->count(), 'Kontrola dodatnia: zadanie czeka w kolejce bazodanowej.');
        $this->assertCount(1, $this->pliki());
        $this->assertStringNotContainsString($this->katalog(), (string) DB::table('jobs')->value('payload'));
        $this->assertSame(0, Recipe::query()->count());

        Artisan::call('queue:work', ['--once' => true, '--queue' => 'low', '--stop-when-empty' => true]);

        $zlecenie = ImportPrzepisu::query()->where('user_id', $autor->getKey())->firstOrFail();
        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $zlecenie->status);
        $this->assertNull($zlecenie->plik_tymczasowy);
        $this->assertSame([], $this->pliki(), 'Po sukcesie plik znika z dysku.');
        $recipe = Recipe::query()->findOrFail($zlecenie->recipe_id);
        $this->assertSame('Sernik z PDF', $recipe->title);
        $this->assertSame(Recipe::STATUS_DRAFT, $recipe->status);
        $this->assertSame('private', $recipe->visibility);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame('gotowy', DB::table('proby_importu')->where('import_id', $zlecenie->getKey())->value('status'));
        Http::assertNothingSent();

        $this->actingAs($autor)->get(route('import.show', $zlecenie))
            ->assertOk()->assertSee('Szkic gotowy do sprawdzenia')->assertSee('Sprawdź i popraw szkic');
    }

    public function test_skan_bez_zgody_konczy_sie_odmowa_bez_wyslania_do_modelu_a_plik_znika(): void
    {
        $this->modelTestowy();
        $autor = $this->user();
        Http::fake(['api.openai.com/*' => Http::response([])]);

        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane(
            UploadedFile::fake()->createWithContent('skan.pdf', MalyPdf::bezTekstu()),
        ))->assertRedirect();

        $zlecenie = ImportPrzepisu::query()->firstOrFail();
        $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $zlecenie->status);
        $this->assertSame(ImportPrzepisu::KOD_BRAK_ZGODY, $zlecenie->kod_bledu);
        $this->assertNull($zlecenie->plik_tymczasowy);
        $this->assertSame([], $this->pliki(), 'Po porażce plik też znika.');
        Http::assertNothingSent();
        $this->actingAs($autor)->get(route('import.show', $zlecenie))
            ->assertOk()
            ->assertSee(ImportOdrzucony::KOMUNIKATY[ImportOdrzucony::BRAK_ZGODY_AI])
            ->assertSee('Dodaj plik jeszcze raz')
            ->assertSee('Wpiszę przepis ręcznie');
    }

    public function test_pdf_z_tekstem_nigdy_nie_idzie_do_modelu_nawet_po_zgodzie(): void
    {
        $this->modelTestowy();
        $autor = $this->user();
        Http::fake(['api.openai.com/*' => Http::response([])]);

        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane() + ['zgoda_ai' => '1', InformacjaTekstuZrodlaAi::POLE => InformacjaTekstuZrodlaAi::WERSJA])->assertRedirect();

        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, ImportPrzepisu::query()->firstOrFail()->status);
        Http::assertNothingSent();
    }

    public function test_uszkodzony_plik_z_sygnatura_konczy_zlecenie_kodem_odmowy_i_kasuje_plik(): void
    {
        $autor = $this->user();
        Http::fake();

        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane(
            UploadedFile::fake()->createWithContent('zepsuty.pdf', '%PDF-1.4 to nie jest prawdziwy plik'),
        ))->assertRedirect();

        $zlecenie = ImportPrzepisu::query()->firstOrFail();
        $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $zlecenie->status);
        $this->assertSame('pdf_uszkodzony', $zlecenie->kod_bledu);
        $this->assertNull($zlecenie->plik_tymczasowy);
        $this->assertSame([], $this->pliki());
        $this->assertFalse($zlecenie->moznaPonowic());
        $this->actingAs($autor)->get(route('import.show', $zlecenie))
            ->assertOk()->assertSee('Nie umiemy otworzyć tego pliku')->assertDontSee('zdjęcie kartki jest już przy nim');
    }

    public function test_pdf_z_za_duza_liczba_stron_pokazuje_limit_z_konfiguracji(): void
    {
        $autor = $this->user();
        config(['kuking.import.pdf.max_stron' => 2]);
        Http::fake();

        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane(
            UploadedFile::fake()->createWithContent('duzo.pdf', MalyPdf::zTekstem([['a'], ['b'], ['c']])),
        ))->assertRedirect();

        $zlecenie = ImportPrzepisu::query()->firstOrFail();
        $this->assertSame('pdf_za_duzo_stron', $zlecenie->kod_bledu);
        $this->actingAs($autor)->get(route('import.show', $zlecenie))
            ->assertOk()->assertSee('najwyżej 2)', false)->assertDontSee(':strony');
    }

    public function test_zadanie_uruchomione_drugi_raz_na_zakonczonym_zleceniu_nic_nie_robi_i_domyka_plik(): void
    {
        $autor = $this->user();
        Http::fake();
        $this->actingAs($autor)->post(route('recipes.import.pdf.store'), $this->dane())->assertRedirect();
        $zlecenie = ImportPrzepisu::query()->firstOrFail();
        $this->assertSame(ImportPrzepisu::STATUS_GOTOWY, $zlecenie->status, 'Kontrola dodatnia: kolejka sync wykonała zadanie.');

        app()->call([new ImportujPrzepisZPdf((string) $zlecenie->getKey()), 'handle']);

        $this->assertSame(1, Recipe::query()->count());
        $this->assertSame([], $this->pliki());
    }

    public function test_wylaczone_zrodlo_konczy_zlecenie_bez_czytania_pliku_i_kasuje_go(): void
    {
        $autor = $this->user();
        $zlecenie = $this->zlecenieZPlikiem($autor);
        config(['kuking.import.pdf.wlaczony' => false]);
        Process::fake();

        app()->call([new ImportujPrzepisZPdf((string) $zlecenie->getKey()), 'handle']);

        $zlecenie->refresh();
        $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $zlecenie->status);
        $this->assertSame(ImportPrzepisu::KOD_WYLACZONY, $zlecenie->kod_bledu);
        $this->assertNull($zlecenie->plik_tymczasowy);
        $this->assertSame([], $this->pliki());
        Process::assertNothingRan();
    }

    public function test_brak_pliku_na_dysku_konczy_zlecenie_jawnym_bledem(): void
    {
        $autor = $this->user();
        $zlecenie = $this->zlecenieZPlikiem($autor);
        Storage::disk($this->dysk)->delete((string) $zlecenie->plik_tymczasowy);

        app()->call([new ImportujPrzepisZPdf((string) $zlecenie->getKey()), 'handle']);

        $zlecenie->refresh();
        $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $zlecenie->status);
        $this->assertSame(ImportPrzepisu::KOD_BLAD_WEWNETRZNY, $zlecenie->kod_bledu);
        $this->assertNull($zlecenie->plik_tymczasowy);
    }

    public function test_zadanie_ktore_padlo_konczy_zlecenie_domyka_rezerwacje_i_kasuje_plik(): void
    {
        $autor = $this->user();
        $zlecenie = $this->zlecenieZPlikiem($autor);
        $this->assertCount(1, $this->pliki());

        (new ImportujPrzepisZPdf((string) $zlecenie->getKey(), true))->failed(new RuntimeException('timeout'));

        $zlecenie->refresh();
        $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $zlecenie->status);
        $this->assertSame(ImportPrzepisu::KOD_BLAD_WEWNETRZNY, $zlecenie->kod_bledu);
        $this->assertNull($zlecenie->plik_tymczasowy);
        $this->assertSame([], $this->pliki());
        $this->assertSame('nieudany', DB::table('proby_importu')->where('import_id', $zlecenie->getKey())->value('status'));

        // Powtórzone `failed()` niczego nie psuje.
        (new ImportujPrzepisZPdf((string) $zlecenie->getKey(), true))->failed(new RuntimeException('timeout'));
        $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $zlecenie->fresh()?->status);
    }

    public function test_odzyskiwanie_domyka_zgubione_zlecenie_i_kasuje_jego_plik(): void
    {
        $autor = $this->user();
        $zlecenie = $this->zlecenieZPlikiem($autor);
        DB::table('importy_przepisow')->where('id', $zlecenie->getKey())->update(['updated_at' => now()->subHours(3)]);

        $wynik = app(OdzyskanieImportow::class)->odzyskaj();

        $this->assertSame(1, $wynik['zlecenia']);
        $this->assertSame(1, $wynik['pliki']);
        $zlecenie->refresh();
        $this->assertSame(ImportPrzepisu::STATUS_NIEUDANY, $zlecenie->status);
        $this->assertNull($zlecenie->plik_tymczasowy);
        $this->assertSame([], $this->pliki());
    }

    public function test_odzyskiwanie_nie_rusza_pliku_zlecenia_ktore_jeszcze_trwa(): void
    {
        $autor = $this->user();
        $zlecenie = $this->zlecenieZPlikiem($autor);
        $plik = (string) $zlecenie->plik_tymczasowy;
        // Plik starszy niż retencja, ale zlecenie dopiero co się poruszyło.
        $this->postarz($plik, 10);

        $wynik = app(OdzyskanieImportow::class)->odzyskaj();

        $this->assertSame(0, $wynik['pliki']);
        $this->assertSame([$plik], $this->pliki());
        $this->assertSame($plik, $zlecenie->fresh()?->plik_tymczasowy);
    }

    public function test_odzyskiwanie_kasuje_plik_zlecenia_konczacego_sie_gdy_dysk_wczesniej_odmowil(): void
    {
        $autor = $this->user();
        $zlecenie = $this->zlecenieZPlikiem($autor);
        // Stan końcowy, ale ścieżka została (kasowanie nie doszło do skutku).
        $zlecenie->forceFill([
            'status' => ImportPrzepisu::STATUS_NIEUDANY, 'kod_bledu' => ImportPrzepisu::KOD_BLAD_WEWNETRZNY, 'zakonczono_at' => now(),
        ])->save();
        $this->assertNotNull($zlecenie->fresh()?->plik_tymczasowy);

        $wynik = app(OdzyskanieImportow::class)->odzyskaj();

        $this->assertSame(1, $wynik['pliki']);
        $this->assertNull($zlecenie->fresh()->plik_tymczasowy);
        $this->assertSame([], $this->pliki());
    }

    public function test_osierocony_plik_starszy_niz_retencja_jest_kasowany_a_swiezy_i_wskazywany_zostaja(): void
    {
        $autor = $this->user();
        $zlecenie = $this->zlecenieZPlikiem($autor);
        $wskazywany = (string) $zlecenie->plik_tymczasowy;
        $dysk = Storage::disk($this->dysk);
        $dysk->put($this->katalog().'/stary-osierocony.pdf', '%PDF-1.4');
        $dysk->put($this->katalog().'/swiezy-osierocony.pdf', '%PDF-1.4');
        $this->postarz($this->katalog().'/stary-osierocony.pdf', 10);
        $this->postarz($wskazywany, 10);

        $wynik = app(OdzyskanieImportow::class)->odzyskaj();

        $this->assertSame(1, $wynik['pliki']);
        $this->assertFalse($dysk->exists($this->katalog().'/stary-osierocony.pdf'));
        $this->assertTrue($dysk->exists($this->katalog().'/swiezy-osierocony.pdf'), 'Plik przyjęty przed chwilą może jeszcze dostać wiersz.');
        $this->assertTrue($dysk->exists($wskazywany), 'Plik, który wskazuje trwające zlecenie, zostaje mimo wieku.');
    }

    public function test_retencja_nie_jest_krotsza_niz_czas_po_ktorym_zlecenie_uznaje_sie_za_zgubione(): void
    {
        config(['kuking.import.pdf.retencja_godzin' => 0, 'kuking.import.odzyskiwanie.zlecenie_minut' => 120]);
        $autor = $this->user();
        $zlecenie = $this->zlecenieZPlikiem($autor);
        $this->postarz((string) $zlecenie->plik_tymczasowy, 1);
        DB::table('importy_przepisow')->where('id', $zlecenie->getKey())->update(['updated_at' => now()->subMinutes(100)]);

        app(OdzyskanieImportow::class)->odzyskaj();

        $this->assertCount(1, $this->pliki(), 'Zlecenie sprzed 100 minut jeszcze trwa — nikt nie zabiera mu pliku.');
        $this->assertSame(ImportPrzepisu::STATUS_OCZEKUJE, $zlecenie->fresh()?->status);
    }

    public function test_polecenie_odzyskiwania_raportuje_skasowane_pliki(): void
    {
        $autor = $this->user();
        $zlecenie = $this->zlecenieZPlikiem($autor);
        $zlecenie->forceFill([
            'status' => ImportPrzepisu::STATUS_NIEUDANY, 'kod_bledu' => ImportPrzepisu::KOD_BLAD_WEWNETRZNY,
        ])->save();

        $this->artisan('kuking:odzyskaj-importy')
            ->expectsOutputToContain('skasowane zaległe pliki PDF: 1')
            ->assertSuccessful();
    }

    public function test_wymazanie_konta_kasuje_plik_pdf_tej_osoby_i_tylko_jej(): void
    {
        $odchodzi = $this->user('odchodzi');
        $zostaje = $this->user('zostaje');
        $wlasny = $this->zlecenieZPlikiem($odchodzi);
        $cudzy = $this->zlecenieZPlikiem($zostaje);
        $this->assertCount(2, $this->pliki());
        // Prośba o usunięcie konta przychodzi PO wysłaniu pliku.
        $odchodzi->forceFill(['status' => User::STATUS_PENDING_DELETE, 'delete_requested_at' => now()->subDays(31)])->save();

        $this->assertTrue(app(EraseAccountData::class)->handle($odchodzi));

        $this->assertSame([$cudzy->plik_tymczasowy], $this->pliki());
        $this->assertNull(ImportPrzepisu::query()->find($wlasny->getKey()));
    }

    public function test_kasowanie_ignoruje_sciezki_spoza_katalogu_importu(): void
    {
        $dysk = Storage::disk($this->dysk);
        $dysk->put('livewire-tmp/cudzy.jpg', 'x');
        $dysk->put('incoming/cudzy.jpg', 'x');
        $pliki = app(PlikTymczasowyImportu::class);

        $this->assertTrue($pliki->skasuj('livewire-tmp/cudzy.jpg'));
        $this->assertTrue($pliki->skasuj('incoming/cudzy.jpg'));
        $this->assertTrue($pliki->skasuj($this->katalog().'/../incoming/cudzy.jpg'));

        $this->assertTrue($dysk->exists('livewire-tmp/cudzy.jpg'), 'Katalog Livewire nie należy do tego sprzątania.');
        $this->assertTrue($dysk->exists('incoming/cudzy.jpg'));
    }

    public function test_sprzatanie_osieroconych_nie_dotyka_innych_katalogow(): void
    {
        $dysk = Storage::disk($this->dysk);
        $dysk->put('livewire-tmp/stary.jpg', 'x');
        $dysk->put('incoming/stary.jpg', 'x');
        $this->postarz('livewire-tmp/stary.jpg', 48);
        $this->postarz('incoming/stary.jpg', 48);

        app(OdzyskanieImportow::class)->odzyskaj();

        $this->assertTrue($dysk->exists('livewire-tmp/stary.jpg'));
        $this->assertTrue($dysk->exists('incoming/stary.jpg'));
    }

    public function test_katalog_plikow_pdf_jest_rozlaczny_z_katalogami_ktorych_dotyczy_inna_retencja(): void
    {
        $katalog = trim((string) config('kuking.import.pdf.katalog'), '/');
        $livewire = trim((string) (config('livewire.temporary_file_upload.directory') ?? 'livewire-tmp'), '/');

        foreach ([$livewire, 'incoming'] as $inny) {
            $this->assertStringStartsNotWith($inny, $katalog, "Reguła lifecycle dla {$inny}/ nie może objąć plików importu PDF.");
            $this->assertStringStartsNotWith($katalog, $inny);
        }
        $this->assertNotSame('', $katalog);
    }

    public function test_ekran_postepu_zlecenia_z_pdf_jest_tylko_dla_wlasciciela(): void
    {
        $autor = $this->user('wlasciciel');
        $obca = $this->user('obca');
        $zlecenie = $this->zlecenieZPlikiem($autor);

        $this->actingAs($obca)->get(route('import.show', $zlecenie))->assertForbidden();
        $this->actingAs($obca)->get(route('import.show', ['import' => $zlecenie, 'fragment' => 1]))->assertForbidden();
        $this->actingAs($autor)->get(route('import.show', ['import' => $zlecenie, 'fragment' => 1]))
            ->assertOk()->assertSee('data-koncowy="0"', false)->assertSee('Odczytujemy plik');
    }

    /** Cofa czas modyfikacji pliku na dysku testowym (Flysystem czyta go z systemu plików). */
    private function postarz(string $sciezka, int $godzin): void
    {
        $this->assertTrue(touch(Storage::disk($this->dysk)->path($sciezka), time() - $godzin * 3600));
    }
}

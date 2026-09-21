<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\PrzedawnioneSygnaly;
use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Models\ProductSignal;
use App\Models\Recipe;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * `product_signals` (issue #115): `photo_upload_failed` i `search_performed`.
 *
 * FAKTYCZNA LICZBA `throw` W `StoreUploadedImage`, WOBEC PIĘCIU Z ISSUE
 * Issue #115 wymienia pięć powodów `photo_upload_failed`
 * (`unreadable|too_large|not_an_image|unsupported_format|too_many_megapixels`).
 * W `StoreUploadedImage::handle()` są naprawdę CZTERY instrukcje `throw`, nie
 * pięć — a jedna z tych czterech (drugie wywołanie `getimagesize()`) jest
 * dziś NIEOSIĄGALNA, co mówi wprost komentarz w samym kodzie: skoro
 * `RozpoznanieZdjecia::rozpoznaj()` już złapałaby nieudany odczyt obrazu
 * wcześniej, ten drugi warunek nigdy nie jest prawdziwy. Trzy z pięciu kodów
 * (`not_an_image`, `unsupported_format`, `too_many_megapixels`) to trzy
 * gałęzie WEWNĄTRZ tego jednego trzeciego `throw` — nie trzy osobne `throw`.
 * Testy niżej sprawdzają więc pięć REGUŁ WYNIKU (bo tyle naprawdę rozróżnia
 * kod), a nie pięć instrukcji `throw` w źródle — audyt/issue mylił się co do
 * kształtu, nie co do tego, że te pięć sytuacji istnieje.
 */
class SygnalyProduktoweTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['kuking.media.disk' => 'public', 'kuking.media.public_disk' => 'public']);
    }

    // -----------------------------------------------------------------
    // photo_upload_failed — pięć „reason" z issue #115
    // -----------------------------------------------------------------

    public function test_niemozliwy_do_odczytania_plik_zapisuje_sygnal_z_powodem_unreadable(): void
    {
        $basia = $this->user('basia');

        // `tempnam()` tworzy PRAWDZIWY plik o zerze bajtów — dokładnie ten
        // przypadek, w którym `$file->getSize()` w `StoreUploadedImage`
        // zwraca `<= 0`, zanim jakikolwiek magic-bytes zdąży się policzyć.
        $pusty = new UploadedFile(tempnam(sys_get_temp_dir(), 'pusty'), 'pusty.jpg', null, null, true);

        try {
            app(StoreUploadedImage::class)->handle($basia, $pusty);
            $this->fail('Powinien polecieć wyjątek.');
        } catch (RuntimeException) {
            // Komunikat dla człowieka sprawdza już MediaUploadTest —
            // ten test dotyczy wyłącznie sygnału.
        }

        $this->assertDatabaseCount('product_signals', 1);
        $sygnal = ProductSignal::sole();
        $this->assertSame(ZapiszSygnal::PHOTO_UPLOAD_FAILED, $sygnal->signal_name);
        $this->assertSame('unreadable', $sygnal->properties['reason']);
        $this->assertSame($basia->getKey(), $sygnal->user_id);
    }

    public function test_za_duzy_plik_zapisuje_sygnal_z_powodem_too_large(): void
    {
        $basia = $this->user('basia');
        config(['kuking.media.max_bytes' => 1024]);

        $plik = UploadedFile::fake()->image('obiad.jpg', 800, 600)->size(5000);

        try {
            app(StoreUploadedImage::class)->handle($basia, $plik);
            $this->fail('Powinien polecieć wyjątek.');
        } catch (RuntimeException) {
        }

        $this->assertDatabaseCount('product_signals', 1);
        $sygnal = ProductSignal::sole();
        $this->assertSame('too_large', $sygnal->properties['reason']);
        // Liczby są w porządku w sygnale (issue: „ewentualnie liczby") —
        // w odróżnieniu od frazy wyszukiwania czy nazwy pliku, to nie są dane
        // osobowe.
        $this->assertArrayHasKey('bytes', $sygnal->properties);
    }

    public function test_plik_ktory_nie_jest_zdjeciem_zapisuje_sygnal_z_powodem_not_an_image(): void
    {
        $basia = $this->user('basia');

        $plik = UploadedFile::fake()->createWithContent('notatka.jpg', 'to nie jest obraz');

        try {
            app(StoreUploadedImage::class)->handle($basia, $plik);
            $this->fail('Powinien polecieć wyjątek.');
        } catch (RuntimeException) {
        }

        $this->assertDatabaseCount('product_signals', 1);
        $this->assertSame('not_an_image', ProductSignal::sole()->properties['reason']);
    }

    public function test_nieobslugiwany_format_zapisuje_sygnal_z_powodem_unsupported_format(): void
    {
        $basia = $this->user('basia');

        // Prawdziwy GIF — `getimagesize()` go rozpoznaje, ale
        // `config('kuking.media.accepted_mime_types')` go nie ma (nie umiemy
        // przetworzyć), więc pada na DRUGIEJ gałęzi wewnątrz `rozpoznaj()`,
        // nie na pierwszej („nie wygląda na zdjęcie").
        $obraz = imagecreatetruecolor(60, 40);
        $sciezka = tempnam(sys_get_temp_dir(), 'proba').'.gif';
        imagegif($obraz, $sciezka);
        imagedestroy($obraz);

        $plik = new UploadedFile($sciezka, 'animacja.gif', null, null, true);

        try {
            app(StoreUploadedImage::class)->handle($basia, $plik);
            $this->fail('Powinien polecieć wyjątek.');
        } catch (RuntimeException) {
        }

        $this->assertDatabaseCount('product_signals', 1);
        $this->assertSame('unsupported_format', ProductSignal::sole()->properties['reason']);
    }

    public function test_za_duzo_megapikseli_zapisuje_sygnal_z_powodem_too_many_megapixels(): void
    {
        $basia = $this->user('basia');
        config(['kuking.media.max_megapixels' => 1]);

        $plik = UploadedFile::fake()->image('ogromne.jpg', 3000, 3000);

        try {
            app(StoreUploadedImage::class)->handle($basia, $plik);
            $this->fail('Powinien polecieć wyjątek.');
        } catch (RuntimeException) {
        }

        $this->assertDatabaseCount('product_signals', 1);
        $sygnal = ProductSignal::sole();
        $this->assertSame('too_many_megapixels', $sygnal->properties['reason']);
        $this->assertArrayHasKey('megapixels', $sygnal->properties);
    }

    public function test_udane_wgranie_zdjecia_nie_zapisuje_zadnego_sygnalu(): void
    {
        // Sygnał jest dla NIEUDANEJ próby (issue #115) — udane wgranie
        // zdjęcia nie ma tu nic do zgłoszenia.
        $basia = $this->user('basia');

        app(StoreUploadedImage::class)->handle($basia, UploadedFile::fake()->image('obiad.jpg', 800, 600));

        $this->assertDatabaseCount('product_signals', 0);
    }

    // -----------------------------------------------------------------
    // search_performed — nigdy frazy wyszukiwania
    // -----------------------------------------------------------------

    public function test_wyszukiwanie_zapisuje_dlugosc_frazy_i_czy_byly_wyniki(): void
    {
        $autor = $this->user('autor');
        Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Żurek testowy',
            'slug' => 'zurek-testowy-sygnaly',
        ]);

        $this->actingAs($this->user('basia'))
            ->get(route('search', ['q' => 'zurek', 'sekcja' => 'przepisy']))
            ->assertOk();

        $sygnal = ProductSignal::sole();
        $this->assertSame(ZapiszSygnal::SEARCH_PERFORMED, $sygnal->signal_name);
        $this->assertSame(mb_strlen('zurek'), $sygnal->properties['query_length']);
        $this->assertTrue($sygnal->properties['has_results']);
    }

    public function test_wyszukiwanie_bez_wynikow_zapisuje_has_results_false(): void
    {
        $this->actingAs($this->user('basia'))
            ->get(route('search', ['q' => 'zupelnienieznanafraza', 'sekcja' => 'przepisy']))
            ->assertOk();

        $this->assertFalse(ProductSignal::sole()->properties['has_results']);
    }

    public function test_gosc_niezalogowany_generuje_sygnal_wyszukiwania_z_user_id_null(): void
    {
        // Bez `actingAs` — wyszukiwarka działa też bez konta.
        $this->get(route('search', ['q' => 'pierogi', 'sekcja' => 'przepisy']))
            ->assertOk();

        $this->assertDatabaseCount('product_signals', 1);
        $this->assertNull(ProductSignal::sole()->user_id);
    }

    /**
     * Issue #737 — pusty ekran „Szukaj" (brak `q`, `q` puste albo same
     * spacje) NIE odpytuje bazy w `SearchQuery` i nie ma prawa zostawić
     * `search_performed`. Bez tej poprawki samo wejście na `/szukaj` liczyło
     * się jako wyszukiwanie bez wyników (`has_results=false`), zatruwając
     * miarę. Kontrola ujemna: przywrócenie bezwarunkowego `$this->sygnaly->handle(...)`
     * sprawia, że ten test oblewa (sygnał jednak powstaje).
     */
    public function test_pusty_ekran_szukaj_nie_zapisuje_zadnego_sygnalu(): void
    {
        foreach ([[], ['q' => ''], ['q' => '   ']] as $parametry) {
            $this->get(route('search', $parametry))->assertOk();
        }

        $this->assertDatabaseCount('product_signals', 0);
    }

    /**
     * Issue #737 — fraza krótsza niż dwa znaki (ASCII i polska litera) to
     * ten sam przypadek co pusty ekran: `SearchQuery` w ogóle nie pyta bazy,
     * więc nie może powstać sygnał „bez wyników". Próg 2 MUSI się zgadzać
     * z `SearchQuery::recipes()`/`::people()` — to ta sama liczba, nie
     * niezależna kopia.
     */
    public function test_zbyt_krotka_fraza_nie_zapisuje_zadnego_sygnalu(): void
    {
        foreach (['a', 'ż'] as $fraza) {
            $this->get(route('search', ['q' => $fraza]))->assertOk();
        }

        $this->assertDatabaseCount('product_signals', 0);
    }

    /**
     * Kontrola dodatnia do dwóch testów wyżej: fraza dostatecznie długa
     * (gość i zalogowany) NADAL zapisuje dokładnie jeden sygnał — inaczej
     * „zero sygnałów" dla pustego/za krótkiego wejścia przechodziłoby też
     * wtedy, gdyby zapis sygnału przestał działać w ogóle.
     */
    public function test_fraza_dwuznakowa_zapisuje_dokladnie_jeden_sygnal_gosc_i_zalogowany(): void
    {
        $this->get(route('search', ['q' => 'zz']))->assertOk();
        $this->assertDatabaseCount('product_signals', 1);

        $this->actingAs($this->user('basia'))
            ->get(route('search', ['q' => 'zz']))
            ->assertOk();
        $this->assertDatabaseCount('product_signals', 2);
    }

    /**
     * NAJWAŻNIEJSZY TEST W TYM PLIKU (patrz zadanie): fraza z danymi
     * osobowymi (imię i nazwisko) nie może trafić do ŻADNEGO pola zapisanego
     * wiersza — sprawdzamy to na CAŁYM wierszu zserializowanym do JSON-a,
     * surowym z bazy (nie przez model Eloquent, żeby złapać też kolumnę,
     * o której ten test dziś nie wie), nie tylko na `properties`.
     */
    public function test_zdarzenie_wyszukiwania_nie_zawiera_frazy_z_danymi_osobowymi_w_zadnym_polu(): void
    {
        $fraza = 'Barbara Kowalska z ulicy Długiej 12';

        $this->get(route('search', ['q' => $fraza, 'sekcja' => 'przepisy']))
            ->assertOk();

        $wiersz = (array) DB::table('product_signals')->sole();
        $zserializowany = json_encode($wiersz, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString($fraza, $zserializowany);
        $this->assertStringNotContainsString('Barbara', $zserializowany);
        $this->assertStringNotContainsString('Kowalska', $zserializowany);

        // Kontrola pozytywna: to, co MA tam być, naprawdę tam jest — inaczej
        // powyższe trzy asercje przechodziłyby też wtedy, gdyby sygnał w ogóle
        // się nie zapisał.
        //
        // `properties` z surowego wiersza (`DB::table()`, nie model Eloquent)
        // jest STRINGIEM z JSON-em, nie tablicą — stąd osobny `json_decode`
        // na tym jednym polu, zanim sprawdzimy liczbę w środku.
        $this->assertStringContainsString('query_length', $zserializowany);
        $wlasciwosci = json_decode((string) $wiersz['properties'], true);
        $this->assertSame(mb_strlen($fraza), $wlasciwosci['query_length'] ?? null);
    }

    // -----------------------------------------------------------------
    // user_id: gość, usunięcie konta
    // -----------------------------------------------------------------

    public function test_usuniecie_konta_ustawia_user_id_na_null_a_wiersz_zostaje(): void
    {
        $basia = $this->user('basia');

        app(ZapiszSygnal::class)->handle($basia, ZapiszSygnal::SEARCH_PERFORMED, [
            'query_length' => 5,
            'has_results' => true,
        ]);

        $sygnal = ProductSignal::sole();
        $this->assertSame($basia->getKey(), $sygnal->user_id);

        // `User` nie ma `SoftDeletes` — `delete()` jest prawdziwym DELETE
        // w bazie, dokładnie tym, przed czym broni CHECK `ON DELETE SET
        // NULL` z migracji. To testuje samo ograniczenie bazy, niezależnie
        // od tego, że dzisiejszy przepływ usuwania konta (`EraseAccountData`)
        // anonimizuje wiersz `users`, a nie kasuje go — issue mówi wprost:
        // „usunięcie konta nie może kasować historii metryk".
        $basia->delete();

        $sygnal->refresh();
        $this->assertNull($sygnal->user_id);
        $this->assertDatabaseCount('product_signals', 1);
    }

    // -----------------------------------------------------------------
    // Retencja: 90 dni, dokładnie na granicy
    // -----------------------------------------------------------------

    public function test_retencja_kasuje_wiersz_starszy_niz_90_dni_i_zostawia_mlodszy(): void
    {
        $stary = DB::table('product_signals')->insertGetId([
            'signal_name' => ZapiszSygnal::SEARCH_PERFORMED,
            'properties' => json_encode(['query_length' => 3, 'has_results' => true]),
            // Wyraźnie POZA granicą 90 dni — nie tylko „bardzo stary", żeby
            // test naprawdę pilnował granicy, a nie dowolnej dużej różnicy.
            'occurred_at' => now()->subDays(90)->subMinute(),
        ]);

        $mlody = DB::table('product_signals')->insertGetId([
            'signal_name' => ZapiszSygnal::SEARCH_PERFORMED,
            'properties' => json_encode(['query_length' => 3, 'has_results' => true]),
            // Wyraźnie WEWNĄTRZ granicy 90 dni.
            'occurred_at' => now()->subDays(90)->addMinute(),
        ]);

        $skasowane = (new PrzedawnioneSygnaly)->posprzataj(90);

        $this->assertSame(1, $skasowane);
        $this->assertDatabaseMissing('product_signals', ['id' => $stary]);
        $this->assertDatabaseHas('product_signals', ['id' => $mlody]);
    }

    public function test_komenda_retencji_dziala_i_respektuje_opcje_na_sucho(): void
    {
        DB::table('product_signals')->insert([
            'signal_name' => ZapiszSygnal::SEARCH_PERFORMED,
            'properties' => json_encode(['query_length' => 3, 'has_results' => true]),
            'occurred_at' => now()->subDays(200),
        ]);

        $this->artisan('kuking:sprzataj-sygnaly', ['--na-sucho' => true])->assertSuccessful();
        $this->assertDatabaseCount('product_signals', 1);

        $this->artisan('kuking:sprzataj-sygnaly')->assertSuccessful();
        $this->assertDatabaseCount('product_signals', 0);
    }

    // -----------------------------------------------------------------
    // Awaria zapisu sygnału NIE wywraca operacji, którą opisuje
    // -----------------------------------------------------------------

    public function test_zapisz_sygnal_nie_rzuca_dalej_gdy_tabela_nie_istnieje(): void
    {
        // Szpieg przez ZMIENNĄ, nie przez fasadę — `Log::shouldHaveReceived()`
        // nie istnieje na samej fasadzie (patrz inne testy w repo, np.
        // `LogCspNieZapisujeSekretowTest`).
        $log = Log::spy();

        Schema::drop('product_signals');

        // Nie oczekujemy wyjątku — to jest cała treść tego testu.
        app(ZapiszSygnal::class)->handle(null, ZapiszSygnal::SEARCH_PERFORMED, [
            'query_length' => 1,
            'has_results' => false,
        ]);

        $log->shouldHaveReceived('warning')->once();
    }

    public function test_awaria_zapisu_sygnalu_nie_wywraca_wyszukiwania(): void
    {
        $autor = $this->user('autor');
        Recipe::factory()->create([
            'author_id' => $autor->getKey(),
            'title' => 'Bigos awaryjny',
            'slug' => 'bigos-awaryjny-sygnaly',
        ]);

        Schema::drop('product_signals');

        // Wyszukiwarka MUSI dalej pokazać wyniki, mimo że nie ma gdzie
        // zapisać sygnału.
        $this->get(route('search', ['q' => 'bigos', 'sekcja' => 'przepisy']))
            ->assertOk()
            ->assertSee('Bigos awaryjny');
    }

    public function test_awaria_zapisu_sygnalu_nie_wywraca_wgrywania_zdjecia(): void
    {
        $basia = $this->user('basia');

        Schema::drop('product_signals');

        // Nawet przy NIEUDANYM wgraniu (ta sama ścieżka, która chce zapisać
        // sygnał) komunikat błędu ma dojść do człowieka — wyjątek zostaje
        // `RuntimeException` o wgraniu, nie wyjątek o brakującej tabeli.
        try {
            app(StoreUploadedImage::class)->handle($basia, UploadedFile::fake()->createWithContent('z.jpg', 'to nie zdjęcie'));
            $this->fail('Powinien polecieć wyjątek o zdjęciu.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nie wygląda na zdjęcie', $e->getMessage());
        }

        // I samo, UDANE wgranie zdjęcia ma dalej działać.
        $media = app(StoreUploadedImage::class)->handle($basia, UploadedFile::fake()->image('obiad.jpg', 800, 600));
        $this->assertTrue($media->exists);
    }

    /**
     * Gdy CHECK w bazie odrzuca wiersz z frazą wyszukiwania, ta fraza NIE MOŻE
     * wylądować w logu.
     *
     * PO CO TO JEST, SKORO CHECK I TAK DZIAŁA
     * Bo działa tylko w jedną stronę. CHECK trzyma dane osobowe poza TABELĄ —
     * i robi to dobrze. Ale komunikat wyjątku, którym Postgres odmawia, zawiera
     * odrzucony wiersz W CAŁOŚCI, a Laravel dokleja do niego jeszcze zapytanie
     * SQL z wstawionymi wartościami. Zmierzone: fraza „Jan Kowalski, Kwiatowa 5"
     * pojawiała się w kontekście logu DWA RAZY — raz w postgresowym
     * „DETAIL: Failing row contains (…)", raz w „SQL: insert into … values (…)".
     *
     * Czyli w momencie, w którym ochrona prywatności ZADZIAŁAŁA, po cichu
     * przenosiła chronione dane z bazy do pliku z logami. To jest dokładnie ta
     * sama usterka co W7-07 (surowy wyjątek trafiający tam, gdzie nie powinien),
     * tylko o warstwę dalej, i wprost wbrew AGENTS.md §7.
     *
     * Test celowo robi to, czego kod NIGDY nie powinien zrobić — wkłada
     * `query_text` do właściwości — bo tylko tak da się sprawdzić, co się dzieje,
     * gdy ktoś kiedyś popełni ten błąd.
     */
    public function test_odrzucony_wiersz_nie_przenosi_frazy_wyszukiwania_do_logu(): void
    {
        $zapisane = [];

        Log::listen(function ($wiadomosc) use (&$zapisane): void {
            $zapisane[] = $wiadomosc;
        });

        app(ZapiszSygnal::class)->handle(null, ZapiszSygnal::SEARCH_PERFORMED, [
            'query_length' => 24,
            'query_text' => 'Jan Kowalski, Kwiatowa 5',
        ]);

        $this->assertDatabaseCount('product_signals', 0);

        $wpisy = array_values(array_filter(
            $zapisane,
            fn ($w) => str_contains($w->message, 'sygnału produktowego'),
        ));

        $this->assertCount(1, $wpisy, 'Nieudany zapis sygnału ma zostawić dokładnie jeden wpis w logu.');

        $caly = json_encode($wpisy[0]->context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('Jan Kowalski', $caly);
        $this->assertStringNotContainsString('Kwiatowa', $caly);
        $this->assertStringNotContainsString('query_text', $caly);
        $this->assertStringNotContainsString('Failing row', $caly);
        $this->assertStringNotContainsString('insert into', $caly);

        // To, co ZOSTAJE, ma wystarczyć do diagnozy: która nazwa sygnału,
        // jaka klasa wyjątku, jaki SQLSTATE.
        $this->assertSame(ZapiszSygnal::SEARCH_PERFORMED, $wpisy[0]->context['signal_name']);
        $this->assertSame(QueryException::class, $wpisy[0]->context['wyjatek']);
        $this->assertSame('23514', $wpisy[0]->context['sqlstate']);
    }

    /**
     * Drugi CHECK z migracji: zamknięty zbiór nazw zdarzeń.
     *
     * DLACZEGO OSOBNY TEST, SKORO NAZWY SĄ STAŁYMI W `ZapiszSygnal`
     * Bo stała w PHP mówi tylko, co kod pisze DZIŚ. CHECK mówi, czego baza
     * NIE PRZYJMIE — także od migracji danych, od `php artisan tinker`,
     * od przyszłego kodu, który doda trzecie zdarzenie i zapomni rozszerzyć
     * ograniczenie. To jest ta sama różnica, którą ten projekt już raz
     * zapłacił: reguła istnieje poprawnie w jednej warstwie, a druga
     * implementuje ją inaczej.
     *
     * Brakowało go: `docs/research/ANALITYKA.md` §5 notował tę lukę, zamiast
     * jej domknąć. Teraz notuje, że jest domknięta.
     */
    public function test_baza_odrzuca_nazwe_zdarzenia_spoza_zamknietego_zbioru(): void
    {
        $zapisane = [];

        Log::listen(function ($wiadomosc) use (&$zapisane): void {
            $zapisane[] = $wiadomosc;
        });

        app(ZapiszSygnal::class)->handle(null, 'wymyslone_zdarzenie', ['cokolwiek' => 1]);

        $this->assertSame(
            0,
            ProductSignal::query()->count(),
            'Baza przyjęła nazwę zdarzenia spoza zbioru z `product_signals_signal_name_check`. '
            .'Ograniczenie albo zniknęło, albo ktoś je rozszerzył i nie dopisał nazwy tutaj.',
        );

        $wpisy = array_values(array_filter(
            $zapisane,
            fn ($w) => str_contains($w->message, 'sygnału produktowego'),
        ));

        $this->assertCount(1, $wpisy);
        $this->assertSame('23514', $wpisy[0]->context['sqlstate']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Rozdzielenie bucketów ma bezpieczną drogę dla zdjęć, które już leżą
 * (audyt W4-03).
 *
 * CZEGO BRAKOWAŁO
 * Rozdzielenie oryginałów i wariantów zmieniło to, GDZIE ZAPISUJEMY. Nie
 * przeniosło niczego, co już leżało. Wiersze sprzed tej zmiany mają
 * `disk = 'r2'` i `variants_disk = NULL`, a `variantsDisk()` czyta `NULL`
 * jako „ten sam dysk co oryginał" — czyli `r2`. Po przestawieniu `AWS_BUCKET`
 * na nowy, prywatny bucket ta sama nazwa wskazywała miejsce, w którym tych
 * plików nie ma: warianty przestawały się wyświetlać, a oryginałów nie dałoby
 * się dołączyć do paczki RODO.
 *
 * Komentarz w migracji twierdził, że „oba źródła współistnieją i kod obsługuje
 * jedno i drugie". Nie było to prawdą, dopóki nie powstał dysk `r2_legacy`.
 * To był komentarz pewniejszy niż kod — dokładnie to, przed czym ostrzegał
 * audyt fali 2.
 *
 * CO PILNUJE TEN TEST
 * Że przenosiny są bezpieczne w najgorszym momencie: przy awarii w połowie.
 */
class PrzenosinyZdjecDoNowychBucketowTest extends TestCase
{
    use RefreshDatabase;

    private function stareZdjecie(): Media
    {
        $media = Media::factory()->create([
            'disk' => 'r2_legacy',
            'variants_disk' => null,
            'object_key' => 'incoming/basia/2026/09/sernik.jpg',
            'metadata' => ['variants' => [
                'thumb' => ['key' => 'media/basia/2026/09/sernik_thumb.webp'],
                'feed' => ['key' => 'media/basia/2026/09/sernik_feed.webp'],
            ]],
        ]);

        Storage::disk('r2_legacy')->put($media->object_key, 'oryginal z exifem');

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('r2_legacy')->put($wariant['key'], 'wariant');
        }

        return $media;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2_legacy');
        Storage::fake('nowe_oryginaly');
        Storage::fake('nowe_publiczne');

        config([
            'kuking.media.disk' => 'nowe_oryginaly',
            'kuking.media.public_disk' => 'nowe_publiczne',
            'filesystems.disks.r2_legacy.bucket' => 'kuking-media-stary',
        ]);
    }

    public function test_oryginal_idzie_do_prywatnego_a_warianty_do_publicznego(): void
    {
        $media = $this->stareZdjecie();

        $this->artisan('kuking:przenies-zdjecia')->assertSuccessful();

        $media->refresh();

        $this->assertSame('nowe_oryginaly', $media->disk);
        $this->assertSame('nowe_publiczne', $media->variantsDisk());

        // Oryginał TYLKO w prywatnym — to jest cały sens rozdzielenia.
        Storage::disk('nowe_oryginaly')->assertExists($media->object_key);
        Storage::disk('nowe_publiczne')->assertMissing($media->object_key);

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('nowe_publiczne')->assertExists($wariant['key']);
            Storage::disk('nowe_oryginaly')->assertMissing($wariant['key']);
        }
    }

    public function test_stary_bucket_zostaje_nietkniety(): void
    {
        // Dopóki nie ma pewności, że przeniósł się komplet, stary bucket jest
        // jedyną kopią zapasową. Kasowanie z niego to osobna decyzja i osobne
        // uruchomienie — nie skutek uboczny kopiowania.
        $media = $this->stareZdjecie();

        $this->artisan('kuking:przenies-zdjecia')->assertSuccessful();

        Storage::disk('r2_legacy')->assertExists($media->object_key);

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('r2_legacy')->assertExists($wariant['key']);
        }
    }

    public function test_brak_oryginalu_w_starym_buckecie_nie_przestawia_wiersza(): void
    {
        // NAJWAŻNIEJSZY TEST W TYM PLIKU (#1031).
        //
        // Do 22 września 2026 `skopiuj()` zwracało `true`, kiedy pliku nie było
        // w starym buckecie. Wiersz dostawał `disk` nowego bucketu, w którym
        // pliku nie ma, WYPADAŁ z zapytania `where('disk', 'r2_legacy')`
        // i żaden kolejny przebieg nie miał go już jak znaleźć: zdjęcia nie ma,
        // baza twierdzi, że jest, i nic tego nie wykrywa.
        //
        // Ten test nie ustawia pustego bucketu ani niczego innego, co i tak
        // zatrzymałoby komendę na wejściu. Jedyne, co jest nie tak, to BRAK
        // PLIKU — i to samo w sobie ma wystarczyć, żeby wiersza nie ruszyć.
        $media = $this->stareZdjecie();

        Storage::disk('r2_legacy')->delete($media->object_key);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('POMINIĘTE')
            ->expectsOutputToContain($media->object_key)
            ->assertFailed();

        // SEDNO: wiersz stoi tam, gdzie stał.
        $media->refresh();
        $this->assertSame('r2_legacy', $media->disk);
        $this->assertNull($media->variants_disk);
        $this->assertDatabaseHas('media', ['id' => $media->getKey(), 'disk' => 'r2_legacy']);

        // A skoro stoi, to wraca w każdym kolejnym przebiegu — czyli nie da się
        // o nim zapomnieć, i dokładnie po to zostaje przy starym dysku.
        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('POMINIĘTE')
            ->assertFailed();
    }

    /**
     * Uszkodzony najstarszy wiersz nie może zablokować zdrowych nowszych
     * (#1031, uzupełnienie). Przy „najstarsze N" wracał na początek każdej
     * partii, więc przy `--limit=1` kolejne przebiegi nie dochodziły dalej.
     */
    public function test_uszkodzony_wiersz_nie_blokuje_kolejnych_przy_malym_limicie(): void
    {
        // Jawne identyfikatory: uszkodzony jest PIERWSZY w kolejności kursora,
        // jak w opisie usterki — bez zgadywania kolejności losowych UUID.
        $uszkodzone = $this->stareZdjecie();
        $uszkodzone->forceFill(['id' => '00000000-0000-4000-8000-000000000001'])->save();
        Storage::disk('r2_legacy')->delete($uszkodzone->object_key);

        $zdrowe = Media::factory()->create([
            'id' => '00000000-0000-4000-8000-000000000002',
            'disk' => 'r2_legacy',
            'variants_disk' => null,
            'object_key' => 'incoming/basia/2026/09/pierogi.jpg',
            // NIEPUSTE warianty (issue #1905): status jest `ready` (domyślny
            // w fabryce), a pusta tablica wariantów przy tym statusie jest od
            // tej poprawki błędem danych, nie „zdrowym, bez wariantów do
            // sprawdzenia" — inaczej ten test mierzyłby dokładnie usterkę,
            // którą #1905 zamyka.
            'metadata' => ['variants' => [
                'feed' => ['key' => 'media/basia/2026/09/pierogi_feed.webp'],
            ]],
        ]);
        Storage::disk('r2_legacy')->put($zdrowe->object_key, 'pierogi');
        Storage::disk('r2_legacy')->put($zdrowe->metadata['variants']['feed']['key'], 'wariant');

        // Kontrola: bez kursora ten sam uszkodzony wiersz wraca w kółko.
        foreach ([1, 2] as $przebieg) {
            $this->artisan('kuking:przenies-zdjecia', ['--limit' => 1])
                ->expectsOutputToContain('POMINIĘTE')
                ->expectsOutputToContain('Następna partia: uruchom z --po='.$uszkodzone->id)
                ->assertFailed();
            $this->assertSame('r2_legacy', $zdrowe->refresh()->disk);
        }

        // Z kursorem przebieg dochodzi do zdrowego zdjęcia.
        $this->artisan('kuking:przenies-zdjecia', ['--limit' => 1, '--po' => (string) $uszkodzone->id])
            ->expectsOutputToContain('Przeniesione: '.$zdrowe->id)
            ->assertSuccessful();

        $this->assertSame('nowe_oryginaly', $zdrowe->refresh()->disk);
        Storage::disk('nowe_oryginaly')->assertExists($zdrowe->object_key);

        // Uszkodzony NIE jest oznaczony jako przeniesiony i wraca bez --po.
        $this->assertSame('r2_legacy', $uszkodzone->refresh()->disk);
        $this->artisan('kuking:przenies-zdjecia', ['--limit' => 1])
            ->expectsOutputToContain('POMINIĘTE')
            ->assertFailed();
    }

    public function test_kursor_odmawia_czegos_co_nie_jest_identyfikatorem(): void
    {
        $this->stareZdjecie();

        $this->artisan('kuking:przenies-zdjecia', ['--po' => 'wczoraj'])
            ->expectsOutputToContain('Opcja --po przyjmuje identyfikator zdjęcia')
            ->assertFailed();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function niepoprawneLimity(): array
    {
        return [
            'zero' => ['0'],
            'ujemny' => ['-1'],
            'tekst' => ['duzo'],
            'ulamek' => ['1.5'],
            'pusty' => [''],
        ];
    }

    #[DataProvider('niepoprawneLimity')]
    public function test_limit_mniejszy_niz_jeden_albo_nie_liczba_jest_odmowa(string $limit): void
    {
        // Regresja: `--limit=0` kończył się „Nie ma zdjęć do przeniesienia"
        // z kodem 0, choć zdjęcia czekały (operator mógł zdjąć stary bucket).
        $media = $this->stareZdjecie();

        $this->artisan('kuking:przenies-zdjecia', ['--limit' => $limit])
            ->expectsOutputToContain('Opcja --limit przyjmuje liczbę całkowitą od 1 wzwyż')
            ->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
    }

    public function test_brak_jednego_wariantu_zatrzymuje_przenosiny_w_polowie(): void
    {
        // Oryginał jest, wariantu nie ma. Kopiowanie zatrzymuje się w połowie
        // i wiersz NIE może zostać przestawiony: `variantsDisk()` wskazywałby
        // wtedy bucket, w którym tego wariantu nie ma.
        $media = $this->stareZdjecie();

        Storage::disk('r2_legacy')->delete($media->metadata['variants']['feed']['key']);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('POMINIĘTE')
            ->expectsOutputToContain('wariant feed')
            ->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
    }

    public function test_nieudany_zapis_w_polowie_nie_przestawia_wiersza(): void
    {
        // Tu plik JEST w starym buckecie, ale zapis do nowego się nie udaje —
        // awaria sieci, brak uprawnień, pełny bucket. To inny przypadek niż
        // brak pliku i ma inną etykietę w raporcie, ale skutek dla wiersza
        // musi być ten sam: bez zmian.
        $media = $this->stareZdjecie();

        $zepsuty = Mockery::mock(Filesystem::class);
        $zepsuty->shouldReceive('exists')->andReturn(false);
        $zepsuty->shouldReceive('writeStream')->andReturn(false);

        Storage::set('nowe_publiczne', $zepsuty);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('NIE UDAŁO SIĘ')
            ->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
    }

    public function test_ponowienie_po_czesciowym_przebiegu_konczy_robote(): void
    {
        // Idempotencja: przebieg przerwany w połowie wolno po prostu powtórzyć.
        // To, co zdążyło się skopiować, nie jest kopiowane drugi raz, a to,
        // czego brakowało, dochodzi.
        $media = $this->stareZdjecie();

        $brakujacy = $media->metadata['variants']['thumb']['key'];
        Storage::disk('r2_legacy')->delete($brakujacy);

        $this->artisan('kuking:przenies-zdjecia')->assertFailed();
        $this->assertSame('r2_legacy', $media->refresh()->disk);

        // Oryginał zdążył się przekopiować — ponowienie ma go zastać na miejscu
        // i nie próbować drugi raz.
        Storage::disk('nowe_oryginaly')->assertExists($media->object_key);

        // Brakujący wariant wraca do starego bucketu (np. z kopii zapasowej).
        Storage::disk('r2_legacy')->put($brakujacy, 'wariant');

        $this->artisan('kuking:przenies-zdjecia')->assertSuccessful();

        $media->refresh();
        $this->assertSame('nowe_oryginaly', $media->disk);
        $this->assertSame('nowe_publiczne', $media->variantsDisk());

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('nowe_publiczne')->assertExists($wariant['key']);
        }
    }

    public function test_tryb_tylko_raport_melduje_brak_i_niczego_nie_zapisuje(): void
    {
        // Tryb „tylko raport" ma dać odpowiedź PRZED prawdziwym przebiegiem:
        // ile zdjęć pójdzie, ile odpadnie i dlaczego. Bez jednego zapisu —
        // ani do bucketu, ani do bazy.
        $media = $this->stareZdjecie();

        Storage::disk('r2_legacy')->delete($media->object_key);

        $this->artisan('kuking:przenies-zdjecia', ['--tylko-raport' => true])
            ->expectsOutputToContain('POMINIĘTE')
            ->expectsOutputToContain($media->object_key)
            ->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('nowe_publiczne')->assertMissing($wariant['key']);
        }
    }

    public function test_bez_ustawionego_starego_bucketu_komenda_odmawia_nawet_gdy_plikow_brak(): void
    {
        // Stary warunek wejścia trzyma się dalej: bez `AWS_LEGACY_BUCKET`
        // komenda nie rusza żadnego wiersza.
        $media = $this->stareZdjecie();

        Storage::disk('r2_legacy')->delete($media->object_key);
        config(['filesystems.disks.r2_legacy.bucket' => '']);

        $this->artisan('kuking:przenies-zdjecia')->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
    }

    public function test_bez_ustawionego_starego_bucketu_komenda_odmawia(): void
    {
        // Zgadywanie, skąd kopiować, oznacza tu utratę zdjęć. Lepiej nie
        // zrobić nic i powiedzieć dlaczego.
        config(['filesystems.disks.r2_legacy.bucket' => '']);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('AWS_LEGACY_BUCKET')
            ->assertFailed();
    }

    public function test_ponowne_uruchomienie_nic_nie_psuje(): void
    {
        // Komenda chodzi partiami i będzie uruchamiana wielokrotnie. Zdjęcie
        // już przeniesione nie może wrócić do kolejki ani nadpisać nowej kopii.
        $media = $this->stareZdjecie();

        $this->artisan('kuking:przenies-zdjecia')->assertSuccessful();
        $this->artisan('kuking:przenies-zdjecia')->assertSuccessful();

        $media->refresh();

        $this->assertSame('nowe_oryginaly', $media->disk);
        Storage::disk('nowe_oryginaly')->assertExists($media->object_key);
    }

    public function test_tryb_podgladu_nic_nie_kopiuje(): void
    {
        $media = $this->stareZdjecie();

        $this->artisan('kuking:przenies-zdjecia', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
        Storage::disk('nowe_oryginaly')->assertMissing($media->object_key);
    }

    public function test_migracja_odmawia_gdy_nie_wiadomo_gdzie_lezy_stary_bucket(): void
    {
        // Wiersze sprzed rozdzielenia mają `disk = 'r2'`, a ta nazwa wskazuje
        // teraz NOWY, prywatny bucket. Przestawienie ich bez podania, gdzie
        // naprawdę leżą pliki, znaczyłoby „są nigdzie" — a to jest gorsze niż
        // stan sprzed migracji, bo wygląda na uporządkowane.
        Media::factory()->create(['disk' => 'r2', 'variants_disk' => null]);

        config(['filesystems.disks.r2_legacy.bucket' => '']);

        $migracja = require database_path(
            'migrations/2026_09_06_180000_point_existing_media_at_legacy_disk.php',
        );

        try {
            $migracja->up();

            $this->fail('Migracja przestawiła wiersze, nie wiedząc, gdzie leżą pliki.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('AWS_LEGACY_BUCKET', $e->getMessage());
        }

        $this->assertDatabaseHas('media', ['disk' => 'r2']);
    }

    public function test_migracja_przechodzi_bez_pytania_gdy_nie_ma_czego_przestawiac(): void
    {
        // Dziś to jest każde środowisko: pierwszego wdrożenia jeszcze nie było.
        // Migracja, która pyta ZAWSZE, blokowałaby staging bez powodu.
        config(['filesystems.disks.r2_legacy.bucket' => '']);

        $migracja = require database_path(
            'migrations/2026_09_06_180000_point_existing_media_at_legacy_disk.php',
        );

        $migracja->up();

        $this->addToAssertionCount(1);
    }

    /**
     * #1905: `metadata.variants` pusta przy statusie `ready` jest błędem
     * DANYCH, nie „nic do skopiowania". Migrator nie wolno mu przestawić
     * wiersza, którego kompletu wariantów nigdy nie potwierdził.
     */
    public function test_pusta_tablica_wariantow_przy_ready_nie_przestawia_wiersza(): void
    {
        $media = Media::factory()->create([
            'disk' => 'r2_legacy',
            'variants_disk' => null,
            'object_key' => 'incoming/basia/2026/09/pusty.jpg',
            'metadata' => ['variants' => []],
        ]);
        Storage::disk('r2_legacy')->put($media->object_key, 'oryginal');

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('metadata.variants niepełne')
            ->assertFailed();

        // Oryginał mógł się już skopiować (kopiowany jest PRZED sprawdzeniem
        // wariantów) — jak przy brakującym wariancie w
        // `test_brak_jednego_wariantu_zatrzymuje_przenosiny_w_polowie`. SEDNO
        // tego testu jest niżej: wiersz NIE jest przestawiony.
        $media->refresh();
        $this->assertSame('r2_legacy', $media->disk);
        $this->assertNull($media->variants_disk);
    }

    /** #1905: wariant bez pola `key` (kształt `{feed: {width: 640}}` z opisu zgłoszenia). */
    public function test_wariant_bez_klucza_nie_przestawia_wiersza(): void
    {
        $media = Media::factory()->create([
            'disk' => 'r2_legacy',
            'variants_disk' => null,
            'object_key' => 'incoming/basia/2026/09/uszkodzony.jpg',
            'metadata' => ['variants' => ['feed' => ['width' => 640]]],
        ]);
        Storage::disk('r2_legacy')->put($media->object_key, 'oryginal');

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('metadata.variants niepełne')
            ->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
    }

    /** #1905: `metadata.variants` = null (a nie tablica) jest tym samym błędem co pusta tablica. */
    public function test_warianty_null_nie_przestawiaja_wiersza(): void
    {
        $media = Media::factory()->create([
            'disk' => 'r2_legacy',
            'variants_disk' => null,
            'object_key' => 'incoming/basia/2026/09/null.jpg',
            'metadata' => ['variants' => null],
        ]);
        Storage::disk('r2_legacy')->put($media->object_key, 'oryginal');

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('metadata.variants niepełne')
            ->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
    }

    public function test_zgodna_zastana_kopia_przelacza_wiersz(): void
    {
        // #2228: kopia, która JUŻ leży w nowym buckecie i ma dokładnie te
        // same bajty co źródło, jest pełnoprawnie przeniesiona — idempotencja
        // przebiegu przerwanego w połowie zostaje.
        $media = $this->stareZdjecie();

        Storage::disk('nowe_oryginaly')->put($media->object_key, 'oryginal z exifem');

        foreach ($media->metadata['variants'] as $wariant) {
            Storage::disk('nowe_publiczne')->put($wariant['key'], 'wariant');
        }

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('Niezgodne kopie: 0')
            ->assertSuccessful();

        $media->refresh();
        $this->assertSame('nowe_oryginaly', $media->disk);
        $this->assertSame('nowe_publiczne', $media->variantsDisk());
    }

    public function test_ucieta_zastana_kopia_oryginalu_nie_przelacza_wiersza(): void
    {
        // #2228, SEDNO: do 30 września 2026 samo `exists()` w nowym buckecie
        // wystarczało, żeby przestawić wiersz. Ucięty upload z przerwanego
        // przebiegu stawał się aktywnym oryginałem, a wiersz wypadał z kolejki.
        $media = $this->stareZdjecie();

        Storage::disk('nowe_oryginaly')->put($media->object_key, 'oryginal z');

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('NIEZGODNA KOPIA (wiersz BEZ ZMIAN)')
            ->expectsOutputToContain('oryginał: '.$media->object_key.' — inny rozmiar: stary bucket 17 B, nowy 10 B')
            ->expectsOutputToContain('Niezgodne kopie: 1')
            ->expectsOutputToContain('usuń go stamtąd i uruchom komendę ponownie')
            ->assertFailed();

        $media->refresh();
        $this->assertSame('r2_legacy', $media->disk);
        $this->assertNull($media->variants_disk);

        // Obcego/uciętego obiektu komenda sama NIE nadpisuje.
        $this->assertSame('oryginal z', Storage::disk('nowe_oryginaly')->get($media->object_key));
    }

    public function test_kolizja_klucza_z_inna_trescia_tego_samego_rozmiaru_nie_przelacza_wiersza(): void
    {
        // Rozmiar się zgadza, treść nie — sam rozmiar nie wystarcza (podmieniony
        // bajt ma ten sam rozmiar), rozstrzyga SHA-256.
        $media = $this->stareZdjecie();
        $klucz = $media->metadata['variants']['feed']['key'];

        Storage::disk('nowe_publiczne')->put($klucz, 'WARIANT');

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('NIEZGODNA KOPIA (wiersz BEZ ZMIAN)')
            ->expectsOutputToContain('wariant feed: '.$klucz.' — ten sam rozmiar (7 B), ale inna suma SHA-256')
            ->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
        $this->assertSame('WARIANT', Storage::disk('nowe_publiczne')->get($klucz));
    }

    public function test_kopia_ucieta_przy_zapisie_nie_przelacza_wiersza(): void
    {
        // Zapis „się udał" i obiekt istnieje, ale odczyt zwrotny ma mniej bajtów
        // niż źródło — ucięty upload. Obecność po zapisie to za mało.
        $media = $this->stareZdjecie();

        $ucinajacy = Mockery::mock(Filesystem::class);
        $ucinajacy->shouldReceive('exists')->andReturn(false, true);
        $ucinajacy->shouldReceive('writeStream')->andReturn(true);
        $ucinajacy->shouldReceive('readStream')->andReturnUsing(function () {
            $strumien = fopen('php://memory', 'w+b');
            fwrite($strumien, 'orygi');
            rewind($strumien);

            return $strumien;
        });

        Storage::set('nowe_oryginaly', $ucinajacy);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('NIEZGODNA KOPIA (wiersz BEZ ZMIAN)')
            ->expectsOutputToContain('inny rozmiar: stary bucket 17 B, nowy 5 B')
            ->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
    }

    public function test_kopia_bez_zrodla_do_porownania_nie_przelacza_wiersza(): void
    {
        // Obiekt jest w nowym buckecie, ale w starym nie ma z czym go porównać.
        // Nie wiadomo, czy to ten plik — więc to nie jest „ok".
        $media = $this->stareZdjecie();

        Storage::disk('nowe_oryginaly')->put($media->object_key, 'cokolwiek');
        Storage::disk('r2_legacy')->delete($media->object_key);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('w starym nie ma źródła')
            ->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
    }

    public function test_tryb_podgladu_melduje_niezgodna_kopie_i_niczego_nie_zapisuje(): void
    {
        $media = $this->stareZdjecie();
        $klucz = $media->metadata['variants']['thumb']['key'];

        Storage::disk('nowe_publiczne')->put($klucz, 'obcy obiekt');

        $this->artisan('kuking:przenies-zdjecia', ['--dry-run' => true])
            ->expectsOutputToContain('NIEZGODNA KOPIA (wiersz BEZ ZMIAN)')
            ->expectsOutputToContain('Niezgodne kopie: 1')
            ->assertFailed();

        $this->assertSame('r2_legacy', $media->refresh()->disk);
        $this->assertSame('obcy obiekt', Storage::disk('nowe_publiczne')->get($klucz));
        Storage::disk('nowe_oryginaly')->assertMissing($media->object_key);
    }

    public function test_dysk_zgodnosci_ma_publiczny_adres_a_dysk_oryginalow_nie(): void
    {
        // Stary bucket JEST publiczny i dopóki wariantów z niego nie
        // przeniesiemy, to stamtąd się wyświetlają. Zdjęcie mu `url`-a
        // i stare zdjęcia znikają z serwisu w dniu wdrożenia.
        $this->assertArrayHasKey('url', (array) config('filesystems.disks.r2_legacy'));
        $this->assertArrayNotHasKey('url', (array) config('filesystems.disks.r2'));
    }

    // ------------------------------------------------------------------
    // Jasność komunikatów operatora (audyt UX): kursor, suma, „co zrobić".
    // ------------------------------------------------------------------

    private function drugieStareZdjecie(string $nazwa): Media
    {
        $media = Media::factory()->create([
            'disk' => 'r2_legacy',
            'variants_disk' => null,
            'object_key' => "incoming/basia/2026/09/{$nazwa}.jpg",
            'metadata' => ['variants' => [
                'feed' => ['key' => "media/basia/2026/09/{$nazwa}_feed.webp"],
            ]],
        ]);

        Storage::disk('r2_legacy')->put($media->object_key, 'oryginal '.$nazwa);
        Storage::disk('r2_legacy')->put($media->metadata['variants']['feed']['key'], 'wariant '.$nazwa);

        return $media;
    }

    public function test_podsumowanie_przebiegu_z_limitem_podaje_pelna_komende_z_kursorem(): void
    {
        $this->drugieStareZdjecie('rosol');
        $this->drugieStareZdjecie('barszcz');
        $ostatni = (string) Media::query()->where('disk', 'r2_legacy')->orderBy('id')->firstOrFail()->getKey();

        // Wcześniej koniec przebiegu mówił tylko „Uruchom komendę ponownie" —
        // bez --po, czyli od początku.
        $this->artisan('kuking:przenies-zdjecia', ['--limit' => 1])
            ->expectsOutputToContain('Kolejna partia: php artisan kuking:przenies-zdjecia --po='.$ostatni.' (bez --po zaczniesz od początku, razem z pominiętymi).')
            ->assertSuccessful();
    }

    public function test_podsumowanie_bez_nastepnej_partii_kaze_wrocic_bez_kursora(): void
    {
        $brakujace = $this->drugieStareZdjecie('zurek');
        Storage::disk('r2_legacy')->delete($brakujace->object_key);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('żadne nie leży za ostatnim sprawdzonym w tym przebiegu — to pominięte, nieudane albo niezgodne. Uruchom komendę ponownie, bez --po')
            ->assertFailed();
    }

    public function test_tryb_podgladu_podaje_sume_do_przeniesienia_a_nie_tylko_partie(): void
    {
        $this->drugieStareZdjecie('rosol');
        $this->drugieStareZdjecie('barszcz');
        $this->drugieStareZdjecie('bigos');

        $this->artisan('kuking:przenies-zdjecia', ['--dry-run' => true, '--limit' => 2])
            ->expectsOutputToContain('W tej partii: 2, w sumie do przeniesienia: 3.')
            ->assertSuccessful();
    }

    public function test_pominiete_i_nieudane_mowia_co_zrobic(): void
    {
        $brakujace = $this->drugieStareZdjecie('zurek');
        Storage::disk('r2_legacy')->delete($brakujace->object_key);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('Co zrobić: odtwórz plik w starym buckecie z kopii albo uznaj zdjęcie za utracone — ponowne uruchomienie go nie naprawi')
            ->assertFailed();

        $brakujace->delete();
        $this->drugieStareZdjecie('kapusniak');

        $zepsuty = Mockery::mock(Filesystem::class);
        $zepsuty->shouldReceive('exists')->andReturn(false);
        $zepsuty->shouldReceive('writeStream')->andReturn(false);
        Storage::set('nowe_publiczne', $zepsuty);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('wiersz bez zmian, uruchom komendę ponownie')
            ->expectsOutputToContain('Co zrobić: uruchom komendę ponownie; jeśli to samo id wraca za każdym razem, sprawdź logi')
            ->assertFailed();
    }

    public function test_kazda_galaz_bledu_ma_krotki_powod(): void
    {
        // Zapis do nowego bucketu zwraca false.
        $this->drugieStareZdjecie('rosol');
        $zepsuty = Mockery::mock(Filesystem::class);
        $zepsuty->shouldReceive('exists')->andReturn(false);
        $zepsuty->shouldReceive('writeStream')->andReturn(false);
        Storage::set('nowe_oryginaly', $zepsuty);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('zapis do nowego bucketu się nie powiódł')
            ->assertFailed();

        // Zapis się „udał", ale pliku po nim nie widać.
        $niewidoczny = Mockery::mock(Filesystem::class);
        $niewidoczny->shouldReceive('exists')->andReturn(false);
        $niewidoczny->shouldReceive('writeStream')->andReturn(true);
        Storage::set('nowe_oryginaly', $niewidoczny);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('po zapisie pliku nie widać w nowym buckecie')
            ->assertFailed();

        // Źródła w starym buckecie nie da się odczytać.
        Storage::fake('nowe_oryginaly');
        $nieczytelne = Mockery::mock(Filesystem::class);
        $nieczytelne->shouldReceive('exists')->andReturn(true);
        $nieczytelne->shouldReceive('readStream')->andReturn(false);
        Storage::set('r2_legacy', $nieczytelne);

        $this->artisan('kuking:przenies-zdjecia')
            ->expectsOutputToContain('nie da się odczytać źródła w starym buckecie do końca')
            ->assertFailed();
    }
}

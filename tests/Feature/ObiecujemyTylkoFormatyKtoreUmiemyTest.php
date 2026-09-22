<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Analytics\ZapiszSygnal;
use App\Domain\Media\Actions\StoreUploadedImage;
use App\Models\ProductSignal;
use App\Support\LimityZdjec;
use App\Support\RozpoznanieZdjecia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Lista formatów w konfiguracji zgadza się z tym, co naprawdę umiemy otworzyć.
 *
 * SKĄD SIĘ WZIĄŁ PROBLEM
 * `image/heic` i `image/heif` stały w `accepted_mime_types`, bo
 * `mime_content_type()` je rozpoznaje. Rozpoznanie to jednak nie obsługa:
 *
 *   * PHP 8.4 nie ma stałej `IMAGETYPE_HEIC` ani `IMAGETYPE_HEIF`, więc
 *     `getimagesize()` — pierwsze sprawdzenie w `StoreUploadedImage` — zwraca
 *     dla nich `false`;
 *   * GD, którego używa `ProcessUploadedImage`, HEIC-a nie dekoduje.
 *
 * Efekt: siedem formularzy podpowiadało HEIC w oknie wyboru pliku, a serwis
 * odpowiadał „ten plik nie wygląda na zdjęcie" komuś, kto właśnie zrobił
 * zdjęcie telefonem. HEIC jest domyślnym formatem iPhone'a od 2017 roku, więc
 * to nie jest przypadek brzegowy naszej grupy — to jej codzienność.
 *
 * Ten test pilnuje trzech rzeczy naraz, bo każda z nich osobno by nie wystarczyła.
 */
class ObiecujemyTylkoFormatyKtoreUmiemyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['kuking.media.disk' => 'public']);
    }

    public function test_kazdy_dozwolony_format_umie_przeczytac_php_i_gd(): void
    {
        // TO JEST NAJWAŻNIEJSZA ASERCJA W TYM PLIKU. Sprawdza KONTRAKT:
        // że lista w konfiguracji nie wyprzedza możliwości środowiska.
        // Gdyby ktoś dopisał tam format „bo przeglądarka go zna", oblewa tutaj,
        // a nie u człowieka z niedziałającym formularzem.
        $gd = gd_info();

        $obslugaGd = [
            'image/jpeg' => (bool) ($gd['JPEG Support'] ?? false),
            'image/png' => (bool) ($gd['PNG Support'] ?? false),
            'image/webp' => (bool) ($gd['WebP Support'] ?? false),
            'image/avif' => (bool) ($gd['AVIF Support'] ?? false),
        ];

        $dozwolone = LimityZdjec::dozwoloneTypy();

        // ASERCJA KONTROLNA. Cała pętla niżej to zero iteracji, gdy lista
        // dozwolonych typów jest pusta — a pusta bywa nie tylko „nigdy":
        // `dozwoloneTypy()` czyta `config('kuking.media.accepted_mime_types')`,
        // więc wystarczy przeniesiony albo przemianowany klucz konfiguracji.
        // Zmierzone: po podmianie tego klucza na pustą tablicę ten test —
        // opisany we własnym komentarzu jako najważniejszy w pliku — był
        // dalej zielony, nie sprawdzając ani jednego formatu.
        $this->assertNotEmpty(
            $dozwolone,
            'Lista dozwolonych formatów jest pusta — ten test nie sprawdza wtedy niczego, '.
            'a formularze wysyłki zdjęć nie proponują żadnego formatu.',
        );

        foreach ($dozwolone as $mime) {
            $this->assertArrayHasKey(
                $mime,
                $obslugaGd,
                "Format {$mime} jest na liście dozwolonych, ale ten test nie wie, czy GD go czyta. ".
                'Dopisz go do mapy wyżej albo zdejmij z `config/kuking.php` — obiecywanie formatu, '.
                'którego nie umiemy przetworzyć, kończy się komunikatem „ten plik nie wygląda na zdjęcie”.',
            );

            $this->assertTrue(
                $obslugaGd[$mime],
                "Format {$mime} jest dozwolony, ale GD w tym środowisku go nie dekoduje — ".
                'zdjęcie utknie w przetwarzaniu i wyląduje jako odrzucone.',
            );
        }
    }

    public function test_heic_nie_jest_juz_obiecywany(): void
    {
        $this->assertNotContains('image/heic', LimityZdjec::dozwoloneTypy());
        $this->assertNotContains('image/heif', LimityZdjec::dozwoloneTypy());

        // Atrybut `accept` w formularzach idzie z tej samej listy, więc okno
        // wyboru pliku nie podpowie już formatu, którego serwis nie przyjmie.
        //
        // To NIE jest tylko kosmetyka: iOS potrafi przekonwertować HEIC do JPEG
        // przy wysyłce właśnie wtedy, gdy formularz nie deklaruje, że HEIC
        // przyjmie. Deklarowanie go mogło wyłączać konwersję, która działa sama.
        $this->assertStringNotContainsString('heic', LimityZdjec::atrybutAccept());
    }

    public function test_zaden_widok_nie_wpisuje_listy_formatow_od_siebie(): void
    {
        // Lista stała wpisana na sztywno w SIEDMIU widokach. Poprawienie jej
        // w sześciu z nich wyglądałoby na skończoną robotę, a siódmy formularz
        // dalej podpowiadałby HEIC.
        // Rekurencyjnie, nie przez `glob('**')` — ten wzorzec w PHP schodzi
        // o JEDEN poziom, więc pominąłby `pages/settings/two_factor/` i test
        // przechodziłby, nie sprawdzając połowy widoków.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(resource_path('views')),
        );

        $widoki = [];

        /** @var \SplFileInfo $plik */
        foreach ($iterator as $plik) {
            if ($plik->isFile() && str_ends_with($plik->getFilename(), '.blade.php')) {
                $widoki[] = $plik->getPathname();
            }
        }

        $this->assertGreaterThan(50, count($widoki), 'Nie znalazłem widoków — test niczego nie sprawdza.');

        foreach ($widoki as $plik) {
            $this->assertStringNotContainsString(
                'accept="image/',
                (string) file_get_contents($plik),
                basename($plik).' wpisuje listę formatów od siebie zamiast wołać '.
                'LimityZdjec::atrybutAccept() — przy następnej zmianie zostanie w tyle.',
            );
        }
    }

    /**
     * Prawdziwe pudełko `ftyp` marki `heic` — TYLE, ILE CZYTA `mime_content_type()`.
     *
     * PUŁAPKA, PRZED KTÓRĄ TO CHRONI (issue #119, zadanie wprost o niej
     * ostrzega): plik o rozszerzeniu `.heic`, w którym leżą bajty JPEG, nie
     * mierzy niczego — `RozpoznanieZdjecia` i tak nie patrzy na rozszerzenie.
     * Ten plik ma PRAWDZIWE magic bytes formatu HEIC (ISO BMFF, marka
     * kompatybilności `heic`), więc `getimagesize()` zawodzi i
     * `mime_content_type()` naprawdę rozpoznaje go jako `image/heic` —
     * dokładnie tę samą drogę, którą przejdzie plik z iPhone'a.
     */
    private static function sciezkaHeic(): string
    {
        $sciezka = tempnam(sys_get_temp_dir(), 'heic').'.heic';
        $pudelko = 'ftyp'.'heic'.pack('N', 0).'heic'.'mif1';
        file_put_contents($sciezka, pack('N', strlen($pudelko) + 4).$pudelko.str_repeat("\0", 512));

        return $sciezka;
    }

    public function test_zdjecie_z_iphone_a_dostaje_komunikat_mowiacy_co_zrobic(): void
    {
        $sciezka = self::sciezkaHeic();

        $this->assertSame(
            'image/heic',
            mime_content_type($sciezka),
            'Środowisko nie rozpoznaje tego pliku jako HEIC — test nie sprawdziłby tego, co trzeba.',
        );

        try {
            (new StoreUploadedImage)->handle(
                $this->user('basia'),
                new UploadedFile($sciezka, 'sernik.heic', 'image/heic', null, true),
            );

            $this->fail('Plik HEIC został przyjęty, choć nie umiemy go otworzyć.');
        } catch (RuntimeException $e) {
            // Komunikat ma powiedzieć CO ZROBIĆ. „Ten plik nie wygląda na
            // zdjęcie" jest dla człowieka trzymającego zdjęcie sernika po
            // prostu nieprawdą — i nie zostawia żadnego kolejnego kroku.
            $this->assertStringContainsString('HEIC', $e->getMessage());
            $this->assertStringContainsString('Najbardziej zgodny', $e->getMessage());
            $this->assertStringNotContainsString('nie wygląda na zdjęcie', $e->getMessage());
        } finally {
            @unlink($sciezka);
        }

        // D-064 / issue #119: HEIC dostaje WŁASNY kod powodu w sygnale
        // `photo_upload_failed`, nie ogólne `not_an_image` — inaczej nie da
        // się z `product_signals` odpowiedzieć na pytanie „ile zdjęć odpada
        // dziś na HEIC w praktyce" (kryterium akceptacji #119).
        $sygnal = ProductSignal::query()->where('signal_name', ZapiszSygnal::PHOTO_UPLOAD_FAILED)->sole();

        $this->assertSame(RozpoznanieZdjecia::POWOD_HEIC_NIEOBSLUGIWANY, $sygnal->properties['reason'] ?? null);
        $this->assertNotSame(
            RozpoznanieZdjecia::POWOD_NIECZYTELNY,
            $sygnal->properties['reason'] ?? null,
            'HEIC zapisał się pod ogólnym kodem "not_an_image" — nie da się go już odróżnić '
            .'od zwykłego uszkodzonego pliku w statystykach.',
        );
    }

    /**
     * Ta sama próba, ale PRAWDZIWYM formularzem (`posts.store`), nie
     * bezpośrednim wywołaniem akcji domenowej — czyli drogą `ObslugiwaneZdjecie`
     * w walidacji, tą samą, którą przechodzi każde zdjęcie wysłane z telefonu.
     *
     * Bez tego testu HEIC miał sprawdzoną tylko JEDNĄ z dwóch dróg (patrz
     * `ObslugiwaneZdjecie` — reguła pyta o to samo co `StoreUploadedImage`,
     * ale osobnym kodem), a to jest droga, którą naprawdę przechodzi każdy
     * wpis wysłany z formularza „Co dziś ugotowałeś?" — czyli głównej akcji
     * produktu.
     */
    public function test_zdjecie_heic_wyslane_prawdziwym_formularzem_odpada_przy_polu_z_wyjasnieniem(): void
    {
        $sciezka = self::sciezkaHeic();
        $basia = $this->user('basia');

        try {
            $odpowiedz = $this->actingAs($basia)
                ->from(route('posts.create'))
                ->post(route('posts.store'), [
                    'body' => 'Sernik wyszedł idealnie.',
                    'visibility' => 'public',
                    'photos' => [new UploadedFile($sciezka, 'sernik.heic', 'image/heic', null, true)],
                ]);

            // Błąd stoi PRZY POLU (docs/UX_50_PLUS.md), nie tylko ogólnie
            // przy `photos` — inaczej przy kilku wybranych zdjęciach człowiek
            // nie wiedziałby, które odpadło.
            $odpowiedz->assertSessionHasErrors('photos.0');
            $odpowiedz->assertRedirect(route('posts.create'));

            $bledy = $odpowiedz->baseResponse->getSession()->get('errors');
            $komunikat = (string) $bledy->get('photos.0')[0];

            $this->assertStringContainsString('HEIC', $komunikat);
            $this->assertStringContainsString('Najbardziej zgodny', $komunikat);
            $this->assertStringNotContainsString('nie wygląda na zdjęcie', $komunikat);

            // Poprawnie wpisany tekst NIE ZNIKA po nieudanej walidacji
            // (docs/UX_50_PLUS.md: `old()`) — inaczej człowiek, który napisał
            // kilka zdań o obiedzie, traci je tylko dlatego, że telefon
            // wybrał złe zdjęcie.
            $odpowiedz->assertSessionHas('_old_input.body', 'Sernik wyszedł idealnie.');

            // Zdjęcie NIE LĄDUJE jako pusta ramka ani jako `rejected` bez
            // wyjaśnienia (zadanie #119 wprost) — bo w ogóle nie dociera do
            // `StoreUploadedImage`: żaden wiersz `media` nie powstaje.
            $this->assertDatabaseCount('media', 0);
        } finally {
            @unlink($sciezka);
        }

        $sygnal = ProductSignal::query()->where('signal_name', ZapiszSygnal::PHOTO_UPLOAD_FAILED)->sole();
        $this->assertSame(RozpoznanieZdjecia::POWOD_HEIC_NIEOBSLUGIWANY, $sygnal->properties['reason'] ?? null);
    }

    public function test_plik_ktory_naprawde_nie_jest_zdjeciem_dalej_odpada(): void
    {
        // Kontrola w drugą stronę: gdyby poprawka rozmiękczyła walidację,
        // asercja wyżej przechodziłaby przy dziurze w bezpieczeństwie.
        $sciezka = tempnam(sys_get_temp_dir(), 'txt').'.jpg';
        file_put_contents($sciezka, "<?php echo 'to nie jest zdjęcie';");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nie wygląda na zdjęcie');

        try {
            (new StoreUploadedImage)->handle(
                $this->user('basia'),
                new UploadedFile($sciezka, 'zdjecie.jpg', 'image/jpeg', null, true),
            );
        } finally {
            @unlink($sciezka);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use Aws\CommandInterface;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Zapis do Cloudflare R2 nie wysyła nagłówka `x-amz-acl` (issue #120, G-02).
 *
 * DLACZEGO TEN TEST ISTNIEJE
 * R2 nie implementuje S3-owych ACL na obiektach — `x-amz-acl` jest w tabeli
 * zgodności Cloudflare oznaczony jako NIEOBSŁUGIWANY dla `PutObject`.
 * Wbudowany sterownik `s3` wysyłał go i tak, przy każdym zapisie:
 * `AwsS3V3Adapter::upload()` liczy ACL zawsze, a przy braku podanej
 * widoczności wypada `private`. Dziś R2 traktuje `private` jak brak żądania,
 * ale Cloudflare tego nie gwarantuje — a od tego zależała prywatność
 * oryginałów, czyli plików z pełnym EXIF-em i współrzędnymi GPS kuchni.
 *
 * JAK TO JEST SPRAWDZANE — I DLACZEGO WŁAŚNIE TAK
 * `Storage::fake()` ani mock na poziomie polecenia AWS nie pokazują ani
 * jednego nagłówka: nagłówki powstają dopiero przy serializacji żądania.
 * Podstawiamy więc własny `handler` klienta S3 — ostatnie ogniwo stosu AWS
 * SDK, za middleware podpisującym — i patrzymy na PRAWDZIWE, podpisane
 * żądanie HTTP. Nic nie wychodzi do sieci: handler nie wysyła, tylko zapisuje
 * i oddaje przygotowaną odpowiedź.
 *
 * `test_wbudowany_sterownik_s3_wysyla_acl_i_dlatego_go_nie_uzywamy` pilnuje,
 * żeby ten test nie stał się bezwartościowy: dowodzi, że przy dawnym
 * sterowniku nagłówek NAPRAWDĘ leci, więc asercje wyżej mają co łapać.
 *
 * CZEGO TEN TEST NIE UDOWODNI: że prawdziwy bucket R2 przyjmie takie żądanie
 * i że oryginał nie jest publiczny. Tego z PHP nie widać — dwunastopunktowa
 * bramka z issue #120 zostaje ręcznym przebiegiem na prawdziwym R2.
 */
class ZapisDoR2BezAclTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Przechwycone żądania: `['polecenie' => 'PutObject', 'zadanie' => Request]`.
     *
     * @var list<array{polecenie: string, zadanie: RequestInterface}>
     */
    private array $zadania = [];

    /**
     * Udawany bucket — treść zapisanych obiektów, żeby `get()` w potoku
     * zdjęć dostał to, co naprawdę zostało wysłane.
     *
     * @var array<string, string>
     */
    private array $obiekty = [];

    public function test_zapis_zdjecia_nie_wysyla_naglowka_x_amz_acl(): void
    {
        $dysk = $this->dyskR2('r2_probny');

        $dysk->put('incoming/uzytkownik/2026/09/zdjecie.jpg', 'udawane-bajty-jpeg');

        $zadanie = $this->jednoZadanie('PutObject');

        $this->assertFalse(
            $zadanie->hasHeader('x-amz-acl'),
            'Zapis do R2 nadal wysyła `x-amz-acl`. R2 tego nagłówka nie obsługuje '.
            'i nie gwarantuje, jak na niego zareaguje — a od tego zależałaby prywatność '.
            'oryginałów z EXIF-em i GPS-em (issue #120).',
        );

        // Rodzina `x-amz-grant-*` to ten sam mechanizm inną drogą.
        foreach (array_keys($zadanie->getHeaders()) as $naglowek) {
            $this->assertStringStartsNotWith(
                'x-amz-grant',
                strtolower((string) $naglowek),
                'Zapis do R2 wysyła nagłówek uprawnień obiektu.',
            );
        }
    }

    public function test_wbudowany_sterownik_s3_wysyla_acl_i_dlatego_go_nie_uzywamy(): void
    {
        // TEST NA TEST. Gdyby nagłówek nie leciał także tą drogą, asercje
        // wyżej przechodziłyby zawsze i nie pilnowałyby niczego.
        $dysk = $this->dyskR2('s3_probny', ['driver' => 's3']);

        $dysk->put('incoming/uzytkownik/2026/09/zdjecie.jpg', 'udawane-bajty-jpeg');

        $zadanie = $this->jednoZadanie('PutObject');

        $this->assertSame(
            'private',
            $zadanie->getHeaderLine('x-amz-acl'),
            'Wbudowany sterownik `s3` przestał wysyłać ACL. To dobra wiadomość, ale '.
            'sprawia, że `ZapisDoR2BezAclTest` nie ma już czego pilnować — sprawdź, '.
            'czy własny sterownik `r2` jest jeszcze potrzebny.',
        );
    }

    public function test_zapis_strumieniem_tez_nie_wysyla_acl(): void
    {
        // Paczki RODO (`r2_eksporty`) i komenda przenosząca buckety zapisują
        // strumieniem, nie stringiem — to osobna metoda adaptera.
        $dysk = $this->dyskR2('r2_probny');

        $strumien = fopen('php://temp', 'r+');
        fwrite($strumien, 'udawana-paczka-zip');
        rewind($strumien);

        $dysk->writeStream('eksporty/konto/paczka.zip', $strumien);

        // Strumień wywołującego MUSI zostać otwarty — zamyka go ten, kto go
        // otworzył (`PrzeniesZdjeciaDoNowychBucketow` robi to sam).
        $this->assertTrue(is_resource($strumien), 'Adapter zamknął cudzy strumień.');
        fclose($strumien);

        $this->assertFalse($this->jednoZadanie('PutObject')->hasHeader('x-amz-acl'));
    }

    public function test_plik_o_rozmiarze_zdjecia_idzie_jednym_putobject(): void
    {
        // Limit jednego zdjęcia to 15 MB, a próg multipartu w AWS SDK — 16 MB.
        // Potok zdjęć ma więc zawsze iść jednym `PutObject`, bez dzielenia na
        // części. Sprawdzamy to na rozmiarze granicznym, nie na 20 bajtach.
        $dysk = $this->dyskR2('r2_probny');

        $bajty = str_repeat('x', (int) config('kuking.media.max_bytes'));

        $dysk->put('incoming/uzytkownik/2026/09/duze.jpg', $bajty);

        $this->assertSame(
            ['PutObject'],
            array_column($this->zadania, 'polecenie'),
            'Zdjęcie w granicznym rozmiarze poszło inną drogą niż jedno `PutObject`.',
        );

        $this->assertFalse($this->jednoZadanie('PutObject')->hasHeader('x-amz-acl'));
    }

    public function test_zapis_ponad_progiem_idzie_multipartem_tez_bez_acl(): void
    {
        // Paczka RODO to kopia całego konta razem ze zdjęciami — bywa większa
        // niż próg multipartu. `MultipartUploader` dokłada ACL do
        // `CreateMultipartUpload` tylko wtedy, gdy dostanie klucz `acl`;
        // adapter go nie podaje, ale to jest cudza biblioteka i trzeba to
        // sprawdzić, nie założyć.
        $dysk = $this->dyskR2('r2_probny', ['options' => ['mup_threshold' => 1024]]);

        $dysk->put('eksporty/konto/paczka.zip', str_repeat('z', 4096));

        $polecenia = array_column($this->zadania, 'polecenie');

        $this->assertContains('CreateMultipartUpload', $polecenia);
        $this->assertContains('UploadPart', $polecenia);
        $this->assertContains('CompleteMultipartUpload', $polecenia);

        foreach ($this->zadania as $wpis) {
            $this->assertFalse(
                $wpis['zadanie']->hasHeader('x-amz-acl'),
                "Multipart wysyła `x-amz-acl` przy {$wpis['polecenie']}.",
            );
        }
    }

    public function test_acl_wpisane_w_konfiguracje_dysku_jest_ignorowane(): void
    {
        // `options` dysku trafia do każdego żądania jako domyślne parametry.
        // Gdyby ktoś wpisał tam ACL „żeby wymusić publiczność", nie może to
        // przejść do R2 — publiczność bierze się z konfiguracji bucketu.
        $dysk = $this->dyskR2('r2_probny', ['options' => ['ACL' => 'public-read']]);

        $dysk->put('media/uzytkownik/2026/09/zdjecie_feed.webp', 'udawany-webp');

        $this->assertFalse($this->jednoZadanie('PutObject')->hasHeader('x-amz-acl'));
    }

    public function test_zapis_z_jawna_widocznoscia_jest_bledem(): void
    {
        // Nie ciche nic. `put($klucz, $bajty, 'public')` wyglądało jak
        // ustawienie publiczności i nie ustawiało niczego — to jest ten rodzaj
        // kodu-obietnicy, przed którym ostrzega cały ten audyt.
        $dysk = $this->dyskR2('r2_probny');

        $this->expectException(\InvalidArgumentException::class);

        $dysk->put('media/uzytkownik/2026/09/zdjecie_feed.webp', 'udawany-webp', 'public');
    }

    public function test_pytanie_o_widocznosc_nie_wysyla_getobjectacl(): void
    {
        // `GetObjectAcl` i `PutObjectAcl` na R2 nie istnieją. Adapter nie ma
        // prawa ich wywołać ani zgadywać odpowiedzi — „private" byłoby
        // nieprawdą dla bucketu z własną domeną, „public" dla bucketu bez niej.
        $dysk = $this->dyskR2('r2_probny');

        try {
            $dysk->getVisibility('media/uzytkownik/2026/09/zdjecie_feed.webp');
            $this->fail('Pytanie o widoczność obiektu na R2 powinno być błędem.');
        } catch (\Throwable) {
            // Oczekiwane.
        }

        $this->assertSame([], $this->zadania, 'Adapter wysłał żądanie ACL do R2.');
    }

    public function test_typ_tresci_wariantu_nadal_jest_ustawiany(): void
    {
        // Bez `ContentType` wariant WebP wyjeżdża z R2 jako
        // `application/octet-stream`, czyli jako plik do pobrania, a nie
        // zdjęcie do pokazania. Adapter nadrzędny to ustawiał — nasz musi też.
        $dysk = $this->dyskR2('r2_probny');

        $dysk->put('media/uzytkownik/2026/09/zdjecie_feed.webp', 'udawany-webp');

        $this->assertSame('image/webp', $this->jednoZadanie('PutObject')->getHeaderLine('Content-Type'));
    }

    public function test_podpisany_adres_zdjecia_nadal_daje_sie_zbudowac(): void
    {
        // `MediaController` przekierowuje na adres podpisany na kilka minut
        // (W7-02). Zmiana sterownika nie może tego zabrać — podpisywanie idzie
        // przez tego samego klienta S3 i nie łączy się z siecią.
        $dysk = $this->dyskR2('r2_probny');

        $adres = $dysk->temporaryUrl('media/uzytkownik/2026/09/zdjecie_feed.webp', now()->addMinutes(5));

        $this->assertStringContainsString('X-Amz-Signature=', $adres);
        $this->assertSame([], $this->zadania, 'Podpisanie adresu wysłało żądanie do R2.');
    }

    public function test_caly_potok_zdjecia_nie_wysyla_ani_jednego_acl(): void
    {
        // Najważniejszy test w tym pliku: nie sztuczne `put()`, ale prawdziwa
        // droga zdjęcia — przyjęcie pliku plus zadanie w tle z trzema
        // wariantami — na dyskach ze sterownikiem `r2`.
        $this->dyskR2('r2_oryginaly');
        $this->dyskR2('r2_warianty', ['bucket' => 'kuking-warianty']);

        config([
            'kuking.media.disk' => 'r2_oryginaly',
            'kuking.media.public_disk' => 'r2_warianty',
        ]);

        $media = (new StoreUploadedImage)->handle(
            $this->user('basia'),
            UploadedFile::fake()->image('sernik.jpg', 800, 600),
        );

        (new ProcessUploadedImage($media->getKey()))->handle();

        $media->refresh();

        $this->assertSame(Media::STATUS_READY, $media->status);

        $zapisy = array_values(array_filter(
            $this->zadania,
            fn (array $wpis): bool => $wpis['polecenie'] === 'PutObject',
        ));

        // Oryginał plus trzy warianty (`kuking.media.variants`).
        $this->assertCount(1 + count((array) config('kuking.media.variants')), $zapisy);

        foreach ($zapisy as $wpis) {
            $this->assertFalse(
                $wpis['zadanie']->hasHeader('x-amz-acl'),
                'Potok zdjęcia wysyła `x-amz-acl` do R2: '.$wpis['zadanie']->getUri()->getPath(),
            );
        }
    }

    public function test_wszystkie_dyski_r2_uzywaja_sterownika_bez_acl(): void
    {
        // Asercja na konfigurację, nie na kod adaptera: sam adapter niczego nie
        // chroni, dopóki dyski go nie używają. Powrót `driver => 's3'` na
        // którymkolwiek z tych dysków przywraca `x-amz-acl` przy każdym
        // zapisie — i nie widać tego nigdzie poza tym testem.
        foreach (['r2', 'r2_publiczne', 'r2_legacy', 'r2_eksporty'] as $dysk) {
            $this->assertSame(
                'r2',
                config("filesystems.disks.{$dysk}.driver"),
                "Dysk `{$dysk}` wrócił na wbudowany sterownik `s3`, który wysyła "
                .'`x-amz-acl` przy każdym zapisie — nagłówek nieobsługiwany na R2 (issue #120).',
            );
        }
    }

    /**
     * Dysk R2 z podstawionym handlerem klienta S3.
     *
     * @param  array<string, mixed>  $nadpisania
     */
    private function dyskR2(string $nazwa, array $nadpisania = []): Filesystem
    {
        config(["filesystems.disks.{$nazwa}" => array_merge([
            'driver' => 'r2',
            'key' => 'probny-klucz',
            'secret' => 'probny-sekret',
            'region' => 'auto',
            'bucket' => 'kuking-oryginaly',
            'endpoint' => 'https://konto.r2.cloudflarestorage.com',
            'use_path_style_endpoint' => false,
            'throw' => true,
            // Ostatnie ogniwo stosu AWS SDK. Żądanie jest tu już podpisane
            // i kompletne — dokładnie takie, jakie poszłoby do Cloudflare.
            'handler' => $this->handler(),
        ], $nadpisania)]);

        Storage::forgetDisk($nazwa);

        return Storage::disk($nazwa);
    }

    /**
     * Handler, który nic nie wysyła: zapisuje żądanie i oddaje odpowiedź
     * z udawanego bucketu w pamięci.
     */
    private function handler(): callable
    {
        return function (CommandInterface $polecenie, RequestInterface $zadanie): PromiseInterface {
            $this->zadania[] = ['polecenie' => $polecenie->getName(), 'zadanie' => $zadanie];

            $klucz = ltrim($zadanie->getUri()->getPath(), '/');

            return Create::promiseFor(match ($polecenie->getName()) {
                'PutObject' => $this->zapisz($klucz, (string) $zadanie->getBody(), '"etag"'),
                'GetObject' => new Result(['Body' => Utils::streamFor($this->obiekty[$klucz] ?? '')]),
                'CreateMultipartUpload' => new Result(['UploadId' => 'probne-id']),
                'UploadPart' => new Result(['ETag' => '"etag"']),
                default => new Result([]),
            });
        };
    }

    private function zapisz(string $klucz, string $tresc, string $etag): Result
    {
        $this->obiekty[$klucz] = $tresc;

        return new Result(['ETag' => $etag]);
    }

    private function jednoZadanie(string $polecenie): RequestInterface
    {
        $pasujace = array_values(array_filter(
            $this->zadania,
            fn (array $wpis): bool => $wpis['polecenie'] === $polecenie,
        ));

        $this->assertCount(1, $pasujace, "Oczekiwano dokładnie jednego żądania {$polecenie}.");

        return $pasujace[0]['zadanie'];
    }
}

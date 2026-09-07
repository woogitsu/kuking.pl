<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Media\Actions\StoreUploadedImage;
use App\Domain\Media\KasujZdjecie;
use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Oryginały i publiczne warianty nie mogą leżeć w jednym publicznym buckecie
 * (audyt G-01, G-02).
 *
 * CZEGO TEN TEST NIE UDOWODNI — I TRZEBA TO POWIEDZIEĆ WPROST
 * Nie sprawdza prawdziwego R2. Prawdziwą granicę stawia konfiguracja
 * Cloudflare — własna domena bucketu i wyłączony `r2.dev` — a tego nie widać
 * z PHP. Ten test pilnuje strony aplikacyjnej: żeby kod nie WRÓCIŁ do
 * założenia, że oryginał jest prywatny dlatego, że zapisano go jako „private"
 * w tym samym buckecie, co publiczne warianty. Bramka na prawdziwym R2 jest
 * osobna i musi zostać przejściem ręcznym przed wystawieniem produkcji.
 *
 * DLACZEGO TO BYŁA GROŹNA POMYŁKA
 * Cloudflare nie implementuje S3-owych ACL na obiektach: `x-amz-acl` jest
 * w tabeli zgodności oznaczony jako nieobsługiwany dla `PutObject`.
 * Publiczność w R2 jest cechą BUCKETU. Bucket wystawiony pod `cdn.kuking.pl`
 * wystawiał więc również prefiks `incoming/` z oryginałami, a te niosą pełny
 * EXIF — czyli współrzędne GPS kuchni, w której zrobiono zdjęcie.
 *
 * Adres oryginału dawał się przy tym wyprowadzić z publicznego adresu wariantu:
 *
 *     media/{uuid_wlasciciela}/{rok}/{mc}/{uuid}_feed.webp   ← publiczny
 *     incoming/{uuid_wlasciciela}/{rok}/{mc}/{uuid}.jpg      ← oryginał
 *
 * ten sam UUID, ta sama data — wystarczyło zamienić prefiks, uciąć `_feed`
 * i zgadnąć rozszerzenie spośród czterech dozwolonych.
 */
class RozdzialMagazynowTest extends TestCase
{
    use RefreshDatabase;

    public function test_dysk_oryginalow_nie_ma_publicznego_adresu(): void
    {
        // Gdy dysk oryginałów zna swój publiczny URL, to znaczy, że stoi za nim
        // bucket z własną domeną — czyli oryginały są osiągalne z internetu.
        // Brak `url` sprawia, że `Storage::url()` rzuci wyjątek zamiast po
        // cichu zwrócić adres pliku, który publiczny być nie może.
        // Asercja na KLUCZ, nie na wartość. `config('...r2.url')` jest w testach
        // pusty także wtedy, gdy ktoś dopisze tam `env('AWS_URL')` — bo tej
        // zmiennej w testach nie ma. Test przechodziłby, nie sprawdzając nic.
        $this->assertArrayNotHasKey(
            'url',
            (array) config('filesystems.disks.r2'),
            'Dysk `r2` (oryginały) ma klucz `url`. Oryginały niosą pełny EXIF z GPS-em — '.
            'bucket, w którym leżą, nie może mieć własnej domeny ani r2.dev. Bez tego klucza '.
            '`Storage::url()` rzuci wyjątek zamiast po cichu zwrócić publiczny adres.',
        );

        // ODWRÓCONE PRZY W7-02. Wcześniej stało tu `assertArrayHasKey` z
        // uzasadnieniem „bez tego zdjęcia nie wyświetlą się nikomu" — i to
        // było prawdą dopóty, dopóki adresem zdjęcia był adres pliku. Dziś
        // adresem jest trasa `media.show`, a klucz `url` na tym dysku
        // znaczyłby, że bucket wariantów ma własną domenę: czyli że wariant
        // przepisu PRYWATNEGO da się otworzyć bez pytania kogokolwiek o zgodę.
        $this->assertArrayNotHasKey(
            'url',
            (array) config('filesystems.disks.r2_publiczne'),
            'Dysk wariantów ma klucz `url`. Po W7-02 warianty nie mają publicznego '.
            'adresu: adresem zdjęcia jest trasa `media.show`, która pyta Policy treści '.
            'nadrzędnej i przekierowuje na adres podpisany na kilka minut.',
        );
    }

    public function test_wdrozenie_nie_podaje_juz_publicznej_domeny_zdjec(): void
    {
        // Sam brak klucza `url` w `config/filesystems.php` nie wystarczy jako
        // dowód: gdyby wdrożenie dalej ustawiało `AWS_URL`, następna osoba
        // dopisałaby ten klucz z powrotem, „bo zmienna przecież jest".
        $railway = (string) file_get_contents(base_path('.railway/railway.ts'));

        $this->assertStringNotContainsString(
            'AWS_URL:',
            $railway,
            'Wdrożenie nadal ustawia AWS_URL — publiczną domenę bucketu wariantów. '.
            'Po W7-02 warianty nie mają publicznego adresu (audyt W7-02, issue #120).',
        );
    }

    public function test_ustawienie_oryginalow_na_r2_samo_kieruje_warianty_gdzie_indziej(): void
    {
        // Wpisanie wartości do `config()` i sprawdzenie ich zaraz potem nie
        // dowodzi niczego — dowodzi tylko, że `config()` działa. Ładujemy więc
        // plik konfiguracyjny OD NOWA, ze zmienną środowiskową jak na produkcji,
        // i patrzymy, co z niego naprawdę wychodzi.
        //
        // Rozdział ma sens wyłącznie tam, gdzie są buckety. Lokalnie i w testach
        // jeden dysk `public` jest w porządku: nie ma tam ani CDN-u, ani ACL-i,
        // ani niczego, co dałoby się pomylić z granicą bezpieczeństwa.
        $poprzednie = $_SERVER['KUKING_MEDIA_DISK'] ?? null;
        $_SERVER['KUKING_MEDIA_DISK'] = 'r2';

        try {
            /** @var array{media: array{disk: string, public_disk: string}} $swiezy */
            $swiezy = require config_path('kuking.php');

            $this->assertSame('r2', $swiezy['media']['disk']);

            $this->assertSame(
                'r2_publiczne',
                $swiezy['media']['public_disk'],
                'Przestawienie zdjęć na R2 nie przenosi wariantów na osobny dysk. '.
                'Na R2 jeden dysk znaczy jeden bucket, a więc jedną politykę publiczności '.
                'dla oryginałów z EXIF-em i dla wariantów.',
            );
        } finally {
            if ($poprzednie === null) {
                unset($_SERVER['KUKING_MEDIA_DISK']);
            } else {
                $_SERVER['KUKING_MEDIA_DISK'] = $poprzednie;
            }
        }
    }

    public function test_konfiguracja_wdrozenia_podaje_osobny_bucket_publiczny(): void
    {
        // Asercja na KOD WDROŻENIA, nie na lokalne `.env`. Sprawdzanie tu
        // `config('filesystems.disks.r2.bucket')` mierzyłoby maszynę, na której
        // akurat chodzą testy — a ta nigdy nie ma bucketów R2, więc test
        // oblewałby w CI zawsze i zostałby wyłączony w tydzień.
        //
        // `AWS_PUBLIC_BUCKET` wraca domyślnie do `AWS_BUCKET`, żeby środowisko
        // sprzed rozdzielenia nie przestało działać z dnia na dzień. Ten test
        // pilnuje, żeby produkcja tak nie została.
        $railway = (string) file_get_contents(base_path('.railway/railway.ts'));

        $this->assertStringContainsString(
            'AWS_PUBLIC_BUCKET: ctx.shared.R2_PUBLIC_BUCKET',
            $railway,
            'Wdrożenie nie podaje osobnego bucketu publicznego, więc oryginały i warianty '.
            'lądują w JEDNYM buckecie R2 — a wtedy własna domena wystawia także prefiks '.
            '`incoming/` z oryginałami i ich EXIF-em (audyt G-01).',
        );

        // I musi to być INNY sekret niż bucket oryginałów — wskazanie obu
        // zmiennych na tę samą wartość dałoby rozdział wyłącznie na papierze.
        $this->assertStringContainsString('AWS_BUCKET: ctx.shared.R2_BUCKET', $railway);
    }

    public function test_zaden_zapis_zdjecia_nie_ustawia_widocznosci_obiektu(): void
    {
        // `x-amz-acl` jest na R2 nieobsługiwany dla `PutObject`. Argument
        // widoczności w `put()` nie dawał więc ani prywatności oryginału, ani
        // publiczności wariantu — a wyglądał, jakby dawał oba. To jest właśnie
        // ten rodzaj komentarza-obietnicy, przed którym ostrzega audyt.
        foreach ([
            'app/Domain/Media/Actions/StoreUploadedImage.php',
            'app/Jobs/ProcessUploadedImage.php',
            'app/Domain/Media/KasujZdjecie.php',
        ] as $plik) {
            $kod = (string) file_get_contents(base_path($plik));

            // Bez komentarzy: one CYTUJĄ dawne `'public'` i `'private'`
            // w wyjaśnieniu, dlaczego ich tam nie ma.
            $kod = (string) preg_replace('~//.*$|/\*.*?\*/~ms', '', $kod);

            $this->assertDoesNotMatchRegularExpression(
                "/->put\([^)]*,\s*'(public|private)'\s*\)/",
                $kod,
                basename($plik).' ustawia widoczność obiektu przy zapisie. Na R2 to nie działa: '.
                'prywatność bierze się z tego, że bucket nie ma własnej domeny.',
            );

            $this->assertStringNotContainsString('setVisibility', $kod);
        }
    }

    public function test_warianty_ida_na_dysk_publiczny_a_oryginal_zostaje(): void
    {
        Storage::fake('oryginaly');
        Storage::fake('publiczne');

        config([
            'kuking.media.disk' => 'oryginaly',
            'kuking.media.public_disk' => 'publiczne',
        ]);

        $plik = UploadedFile::fake()->image('sernik.jpg', 800, 600);

        $media = (new StoreUploadedImage)
            ->handle($this->user('basia'), $plik);

        // Oryginał: tylko w prywatnym.
        Storage::disk('oryginaly')->assertExists($media->object_key);
        Storage::disk('publiczne')->assertMissing($media->object_key);

        (new ProcessUploadedImage($media->getKey()))->handle();

        $media->refresh();

        $this->assertSame(Media::STATUS_READY, $media->status);
        $this->assertSame('publiczne', $media->variantsDisk());

        $warianty = $media->metadata['variants'] ?? [];
        $this->assertNotSame([], $warianty, 'Nie powstał żaden wariant.');

        foreach ($warianty as $wariant) {
            // NAJWAŻNIEJSZE: wariant w publicznym, i ANI JEDEN plik oryginału
            // tam nie trafił.
            Storage::disk('publiczne')->assertExists($wariant['key']);
            Storage::disk('oryginaly')->assertMissing($wariant['key']);
        }

        Storage::disk('oryginaly')->assertExists($media->object_key);
    }

    public function test_kasowanie_zabiera_pliki_z_obu_dyskow(): void
    {
        // Kasowanie wariantów z dysku oryginałów kończyłoby się cichym niczym:
        // `delete()` na nieistniejącym kluczu nie jest błędem. Publiczne kopie
        // zostawałyby w buckecie za CDN-em na zawsze — także po wymazaniu konta.
        Storage::fake('oryginaly');
        Storage::fake('publiczne');

        config([
            'kuking.media.disk' => 'oryginaly',
            'kuking.media.public_disk' => 'publiczne',
        ]);

        $media = (new StoreUploadedImage)
            ->handle($this->user('basia'), UploadedFile::fake()->image('sernik.jpg', 800, 600));

        (new ProcessUploadedImage($media->getKey()))->handle();

        $media->refresh();
        $warianty = $media->metadata['variants'] ?? [];
        $oryginal = (string) $media->object_key;

        $this->assertTrue((new KasujZdjecie)->jesliNieuzywane($media));

        Storage::disk('oryginaly')->assertMissing($oryginal);

        foreach ($warianty as $wariant) {
            Storage::disk('publiczne')->assertMissing($wariant['key']);
        }
    }

    public function test_stare_zdjecia_bez_variants_disk_dzialaja_jak_dawniej(): void
    {
        // Kolumny nie backfillujemy: każdy istniejący wiersz ma warianty tam,
        // gdzie oryginał, a wpisanie nazwy nowego dysku byłoby stwierdzeniem
        // nieprawdy o tym, gdzie te pliki fizycznie leżą.
        $media = Media::factory()->create([
            'disk' => 'public',
            'variants_disk' => null,
        ]);

        $this->assertSame('public', $media->variantsDisk());
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\User;
use App\Support\LimityZdjec;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Przyjęcie zdjęcia od użytkownika.
 *
 * Zasada nadrzędna: NIE UFAMY NICZEMU, co przyszło od klienta — ani
 * rozszerzeniu, ani nagłówkowi Content-Type, ani nazwie pliku.
 *
 * Kolejność sprawdzeń jest celowa i idzie od najtańszej do najdroższej:
 *   1. rozmiar w bajtach (nie czytamy zawartości),
 *   2. czy PHP w ogóle rozpoznaje to jako obraz (getimagesize — czyta nagłówek),
 *   3. limit megapikseli — obrona przed "decompression bomb": plik 2 MB może
 *      się rozpakować do 30 000 × 30 000 px i położyć serwer,
 *   4. dopiero potem zapis i przetworzenie w tle.
 *
 * Wynikowy obiekt Media ma status `pending`. Widoki nie pokazują nic, co nie
 * jest `ready`, więc zdjęcie z nieusuniętym EXIF-em nigdy nie trafia na stronę.
 */
final class StoreUploadedImage
{
    public function handle(User $owner, UploadedFile $file, ?string $altText = null): Media
    {
        $maxBytes = (int) config('kuking.media.max_bytes');
        $bytes = $file->getSize();

        if ($bytes === false || $bytes <= 0) {
            throw new RuntimeException('Nie udało się odczytać pliku. Spróbuj wybrać zdjęcie jeszcze raz.');
        }

        if ($bytes > $maxBytes) {
            $limitMb = (int) round($maxBytes / 1024 / 1024);

            throw new RuntimeException(
                "To zdjęcie waży za dużo. Maksymalny rozmiar to {$limitMb} MB — wybierz mniejsze zdjęcie.",
            );
        }

        // getimagesize czyta tylko nagłówek pliku, więc jest tanie i przy okazji
        // odpowiada na pytanie "czy to na pewno obraz", niezależnie od rozszerzenia.
        $info = @getimagesize($file->getRealPath());

        if ($info === false) {
            // ZANIM POWIEMY „to nie jest zdjęcie", SPRAWDŹMY, CZY TO NIE HEIC.
            //
            // `getimagesize()` nie zna HEIC — PHP 8.4 nie ma nawet stałej
            // `IMAGETYPE_HEIC` — więc dla zdjęcia prosto z iPhone'a zwraca
            // `false`. Bez tego rozróżnienia serwis mówił „ten plik nie wygląda
            // na zdjęcie" komuś, kto właśnie zrobił zdjęcie sernika telefonem.
            // Człowiek nie ma wtedy żadnej drogi dalej: plik JEST zdjęciem,
            // widzi go w galerii, a komunikat twierdzi coś przeciwnego.
            //
            // `mime_content_type()` HEIC rozpoznaje (to inna biblioteka niż
            // `getimagesize`), więc da się powiedzieć, co jest naprawdę nie tak
            // i co z tym zrobić.
            throw new RuntimeException($this->komunikatNieczytelnegoPliku($file));
        }

        [$width, $height] = $info;
        $detectedMime = $info['mime'] ?? null;

        if (! in_array($detectedMime, config('kuking.media.accepted_mime_types'), true)) {
            throw new RuntimeException(
                'Nie obsługujemy tego formatu zdjęć. Wybierz plik '.LimityZdjec::formatyDlaCzlowieka().'.',
            );
        }

        $megapixels = ($width * $height) / 1_000_000;

        if ($megapixels > (int) config('kuking.media.max_megapixels')) {
            throw new RuntimeException('To zdjęcie ma za duże wymiary. Zmniejsz je i spróbuj ponownie.');
        }

        $disk = (string) config('kuking.media.disk');
        $extension = $this->extensionFor($detectedMime);

        // Klucz obiektu generujemy sami. Nazwa pliku od użytkownika nigdy nie
        // trafia do ścieżki — to zamyka drogę do path traversal i do plików
        // udających skrypty.
        // PREFIKS `incoming/`, NIE `media/` — i zapis jako PRYWATNY.
        //
        // Warianty publikowane na stronie powstają przez przekodowanie do WebP,
        // więc EXIF w nich nie ma. ORYGINAŁ zachowuje go w całości — łącznie
        // ze współrzędnymi GPS, czyli adresem kuchni użytkownika.
        //
        // Wcześniej oryginał lądował pod `media/` jako `public`, a klucz
        // wariantu powstawał z niego przez odcięcie rozszerzenia. Znając
        // publiczny adres miniatury:
        //
        //     media/{id}/2026/09/{uuid}_feed.webp
        //
        // wystarczyło odciąć `_feed.webp` i dopisać `.jpg`, żeby pobrać
        // oryginał w pełnej rozdzielczości, z GPS-em włącznie. Nic tego adresu
        // nie publikowało, ale „nie linkujemy" nie jest zabezpieczeniem.
        //
        // Oryginału NIE kasujemy po przetworzeniu: eksport danych (RODO
        // art. 20) ma oddać człowiekowi jego własne zdjęcie, a nie zmniejszoną
        // kopię. Aplikacja czyta go po stronie serwera, więc prywatny dostęp
        // niczego nie psuje.
        //
        // Zgodne z architekturą opisaną w INFRA_DECISION.md: `incoming/`
        // prywatne, `media/…` publiczne.
        $objectKey = sprintf(
            'incoming/%s/%s/%s.%s',
            $owner->getKey(),
            now()->format('Y/m'),
            Str::uuid()->toString(),
            $extension,
        );

        // BEZ TRZECIEGO ARGUMENTU (dawniej `'private'`).
        //
        // Na R2 widoczność obiektu nie istnieje: `x-amz-acl` jest w tabeli
        // zgodności Cloudflare oznaczony jako NIEOBSŁUGIWANY dla `PutObject`.
        // Ten argument nie robił więc tego, co obiecywał — prywatność
        // oryginału zapewnia dziś to, że ten bucket nie ma własnej domeny
        // ani `r2.dev` (audyt G-01, G-02).
        //
        // Sam Flysystem i tak dokłada `ACL` do każdego żądania (`upload()`
        // w `AwsS3V3Adapter` liczy je zawsze, także bez podanej widoczności),
        // ale domyślne `private` R2 traktuje jak brak żądania — w odróżnieniu
        // od `public-read`, które szło tu dla wariantów. Całkowite pozbycie się
        // ACL z żądania wymaga własnego adaptera i testu na prawdziwym R2 —
        // patrz osobne zgłoszenie.
        Storage::disk($disk)->put($objectKey, $file->get());

        $media = Media::create([
            'owner_id' => $owner->getKey(),
            'disk' => $disk,
            // Gdzie trafią WARIANTY. Zapisujemy to teraz, a nie czytamy
            // z konfiguracji przy każdym odczycie: konfiguracja może się
            // zmienić, a pliki zostaną tam, gdzie je położono (audyt G-01).
            'variants_disk' => (string) config('kuking.media.public_disk'),
            'object_key' => $objectKey,
            'mime_type' => $detectedMime,
            'bytes' => $bytes,
            'width' => $width,
            'height' => $height,
            'status' => Media::STATUS_PENDING,
            'alt_text' => $altText,
            'checksum_sha256' => hash_file('sha256', $file->getRealPath()) ?: null,
            'metadata' => [
                'original_name_length' => mb_strlen($file->getClientOriginalName()),
                // Orientację czytamy TERAZ, dopóki mamy plik na dysku.
                // Zadanie w tle dostaje same bajty ze storage, a sterownik GD
                // nie czyta EXIF-u — bez tej wartości zdjęcia z telefonu
                // publikowałyby się obrócone. Osoba 50+ tego nie zgłosi,
                // po prostu przestanie wrzucać zdjęcia.
                'exif_orientation' => $this->readOrientation($file->getRealPath()),
            ],
        ]);

        ProcessUploadedImage::dispatch($media->getKey());

        return $media;
    }

    /**
     * Wartość znacznika EXIF Orientation (1-8) albo null.
     *
     * Czytamy wyłącznie ten jeden znacznik — reszta EXIF-u, w tym GPS,
     * i tak znika przy re-enkodowaniu i nie chcemy jej nigdzie zapisywać.
     */
    private function readOrientation(string $path): ?int
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        $exif = @exif_read_data($path);

        if ($exif === false || ! isset($exif['Orientation'])) {
            return null;
        }

        $orientation = (int) $exif['Orientation'];

        return ($orientation >= 1 && $orientation <= 8) ? $orientation : null;
    }

    /**
     * Co powiedzieć, gdy PHP nie umie odczytać pliku jako obrazu.
     *
     * HEIC jest domyślnym formatem aparatu w iPhonie od 2017 roku, więc to nie
     * jest przypadek brzegowy — to najzwyklejszy sposób, w jaki nasza grupa
     * robi zdjęcia. Zwykle iOS konwertuje takie zdjęcie do JPEG przy wysyłce
     * sam, ale nie zawsze: przy udostępnianiu z Plików albo z aplikacji innej
     * niż aparat plik idzie w oryginale.
     *
     * Komunikat mówi, CO ZROBIĆ, i podaje drogę, którą da się przejść raz
     * i mieć spokój — a nie „zmień format", bo osoba, która nie wie, co to
     * format, nie ma po tym zdaniu żadnego kolejnego kroku.
     */
    private function komunikatNieczytelnegoPliku(UploadedFile $file): string
    {
        $wykryty = @mime_content_type($file->getRealPath());

        if (in_array($wykryty, ['image/heic', 'image/heif'], true)) {
            return 'To zdjęcie jest w formacie HEIC, którego jeszcze nie umiemy otworzyć. '
                .'W iPhonie wejdź w Ustawienia → Aparat → Formaty i wybierz „Najbardziej zgodny” — '
                .'kolejne zdjęcia zapiszą się jako JPG. To zdjęcie możesz wysłać sobie e-mailem '
                .'albo otworzyć w Zdjęciach i użyć „Duplikuj”, żeby dostać wersję JPG.';
        }

        return 'Ten plik nie wygląda na zdjęcie. Wybierz plik '.LimityZdjec::formatyDlaCzlowieka().'.';
    }

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            // HEIC nie przejdzie już walidacji wyżej, więc tej gałęzi nie da
            // się dziś osiągnąć. Zostaje, bo w bazie MOGĄ leżeć wiersze zapisane,
            // gdy format był na liście dozwolonych, a `ExportPhotoPlan` nazywa
            // po niej pliki w paczce RODO. Usunięcie jej dałoby tym zdjęciom
            // rozszerzenie `.bin` w archiwum człowieka.
            'image/heic', 'image/heif' => 'heic',
            default => 'bin',
        };
    }
}

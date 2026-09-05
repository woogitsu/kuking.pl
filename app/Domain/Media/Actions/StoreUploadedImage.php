<?php

declare(strict_types=1);

namespace App\Domain\Media\Actions;

use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Models\User;
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
            throw new RuntimeException('Ten plik nie wygląda na zdjęcie. Wybierz plik JPG, PNG lub WebP.');
        }

        [$width, $height] = $info;
        $detectedMime = $info['mime'] ?? null;

        if (! in_array($detectedMime, config('kuking.media.accepted_mime_types'), true)) {
            throw new RuntimeException('Nie obsługujemy tego formatu zdjęć. Wybierz plik JPG, PNG lub WebP.');
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
        $objectKey = sprintf(
            'media/%s/%s/%s.%s',
            $owner->getKey(),
            now()->format('Y/m'),
            Str::uuid()->toString(),
            $extension,
        );

        Storage::disk($disk)->put($objectKey, $file->get(), 'public');

        $media = Media::create([
            'owner_id' => $owner->getKey(),
            'disk' => $disk,
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

    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/heic', 'image/heif' => 'heic',
            default => 'bin',
        };
    }
}

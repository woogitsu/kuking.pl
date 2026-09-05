<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Media;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

/**
 * Przetworzenie wgranego zdjęcia.
 *
 * Co się tu dzieje i dlaczego:
 *
 * 1. Obraz jest DEKODOWANY I ZAPISANY OD NOWA. Nie serwujemy nigdy pliku,
 *    który przyszedł od użytkownika. Re-enkodowanie zdejmuje przy okazji
 *    wszystkie metadane — w tym GPS z EXIF, czyli dokładny adres domu osoby,
 *    która zrobiła zdjęcie obiadu w kuchni. To nie jest opcja, to warunek.
 *
 * 2. Generujemy warianty (thumb/feed/large). Feed nigdy nie ładuje zdjęcia
 *    3000 px — na wolnym łączu to jest różnica między "działa" i "nie działa".
 *
 * 3. Dopiero na końcu status zmienia się na `ready`. Do tego momentu zdjęcie
 *    nie pokazuje się nigdzie w interfejsie.
 *
 * Jeśli cokolwiek pójdzie nie tak, zdjęcie dostaje status `rejected`, a powód
 * ląduje w metadanych — użytkownik widzi wtedy komunikat po polsku, a nie
 * pustą ramkę.
 */
class ProcessUploadedImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public string $mediaId) {}

    public function handle(): void
    {
        $media = Media::find($this->mediaId);

        if ($media === null || $media->status === Media::STATUS_READY) {
            return;
        }

        $media->update(['status' => Media::STATUS_PROCESSING]);

        try {
            $disk = Storage::disk($media->disk);
            $original = $disk->get($media->object_key);

            if ($original === null) {
                throw new \RuntimeException('Brak pliku źródłowego w storage.');
            }

            $manager = ImageManager::gd();
            $variants = [];

            foreach (config('kuking.media.variants') as $name => $maxEdge) {
                $image = $manager->read($original);

                // scaleDown nigdy nie powiększa — małe zdjęcie zostaje małe,
                // zamiast być rozmyte na siłę.
                $image->scaleDown(width: $maxEdge, height: $maxEdge);

                $encoded = $image->toWebp(quality: 82);

                $variantKey = preg_replace('/\.[^.]+$/', '', $media->object_key)."_{$name}.webp";
                $disk->put($variantKey, (string) $encoded, 'public');

                $variants[$name] = [
                    'key' => $variantKey,
                    'width' => $image->width(),
                    'height' => $image->height(),
                    'bytes' => strlen((string) $encoded),
                ];
            }

            $media->update([
                'status' => Media::STATUS_READY,
                'metadata' => array_merge($media->metadata ?? [], [
                    'variants' => $variants,
                    'exif_stripped' => true,
                    'processed_at' => now()->toIso8601String(),
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Nie udało się przetworzyć zdjęcia', [
                'media_id' => $media->getKey(),
                'error' => $e->getMessage(),
            ]);

            $media->update([
                'status' => Media::STATUS_REJECTED,
                'metadata' => array_merge($media->metadata ?? [], [
                    'failure_reason' => 'processing_failed',
                ]),
            ]);

            throw $e;
        }
    }
}

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
 *
 * Zdjęcie NIGDY nie zostaje w `processing` — pilnuje tego zarówno `catch`
 * w `handle()`, jak i hook `failed()`. Ten drugi jest konieczny, bo przy
 * przekroczeniu `$timeout` proces dostaje sygnał w środku wykonania i nie ma
 * już żadnego wyjątku do przechwycenia: `catch` się nie wykona, a zdjęcie
 * zostałoby w `processing` na zawsze. Widok dla tego stanu mówi „odśwież
 * stronę za chwilę”, więc bez `failed()` człowiek dostaje obietnicę, która
 * nigdy się nie spełni, i nie ma w interfejsie żadnej drogi, żeby to naprawić
 * samodzielnie (issue #112). Ten sam mechanizm ma `GenerateUserExport`.
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
            // DWA DYSKI, NIE JEDEN (audyt G-01). Oryginał czytamy z bucketu
            // prywatnego, warianty zapisujemy do publicznego. Na R2
            // publiczność jest cechą bucketu, nie obiektu, więc trzymanie obu
            // w jednym buckecie wystawiało oryginały z EXIF-em i GPS-em pod
            // adresem dającym się wyprowadzić z adresu wariantu.
            $disk = Storage::disk($media->disk);
            $publiczny = Storage::disk($media->variantsDisk());
            $original = $disk->get($media->object_key);

            if ($original === null) {
                throw new \RuntimeException('Brak pliku źródłowego w storage.');
            }

            $manager = ImageManager::gd();
            $variants = [];

            $orientation = $media->metadata['exif_orientation'] ?? null;

            foreach (config('kuking.media.variants') as $name => $maxEdge) {
                $image = $manager->read($original);

                $this->applyOrientation($image, $orientation);

                // scaleDown nigdy nie powiększa — małe zdjęcie zostaje małe,
                // zamiast być rozmyte na siłę.
                $image->scaleDown(width: $maxEdge, height: $maxEdge);

                $encoded = $image->toWebp(quality: 82);

                // Wariant idzie do PUBLICZNEGO prefiksu `media/`, oryginał
                // został w prywatnym `incoming/`. Sama zamiana prefiksu, nie
                // przepisywanie ścieżki — dzięki temu stare wiersze, zapisane
                // jeszcze pod `media/`, przetwarzają się bez zmian.
                $publicznyKlucz = str_starts_with($media->object_key, 'incoming/')
                    ? 'media/'.substr($media->object_key, strlen('incoming/'))
                    : $media->object_key;

                $variantKey = preg_replace('/\.[^.]+$/', '', $publicznyKlucz)."_{$name}.webp";
                // BEZ `'public'`. Na R2 `x-amz-acl: public-read` jest wprost
                // nieobsługiwany dla `PutObject` — publiczność bierze się
                // z własnej domeny bucketu, a nie z ACL na obiekcie. Ten
                // argument nie dawał więc publiczności, a mógł żądanie wywrócić.
                $publiczny->put($variantKey, (string) $encoded);

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
                    'orientation_applied' => $orientation !== null && $orientation !== 1,
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

    /**
     * Ostatnia linia obrony: po wyczerpaniu prób — albo po timeoucie, po którym
     * nie ma wyjątku w `handle()` — zdjęcie nie może zostać w `processing`.
     *
     * Dekodowanie zdjęcia 45 Mpx i budowa trzech wariantów w GD to jest realnie
     * ten kawałek serwisu, który potrafi nie zmieścić się w limicie czasu
     * i pamięci workera (`--memory=384`). Bez tego hooka takie zdjęcie zostaje
     * w stanie przejściowym bez końca.
     */
    public function failed(?\Throwable $e): void
    {
        $media = Media::find($this->mediaId);

        // `ready` zostawiamy nietknięte: `failed()` może dojść po spóźnionej
        // próbie, która i tak zakończyła się sukcesem. Cofnięcie gotowego
        // zdjęcia do `rejected` skasowałoby je z widoków bez powodu.
        if ($media === null || $media->status === Media::STATUS_READY) {
            return;
        }

        Log::warning('Przetwarzanie zdjęcia nie powiodło się do końca', [
            'media_id' => $this->mediaId,
            // Bez treści wyjątku przy timeoucie — wtedy wyjątku po prostu nie ma.
            'error' => $e?->getMessage() ?? 'przekroczony limit czasu zadania',
        ]);

        $media->update([
            'status' => Media::STATUS_REJECTED,
            'metadata' => array_merge($media->metadata ?? [], [
                'failure_reason' => $e === null
                    ? 'processing_timeout'
                    : 'processing_failed_or_timeout',
            ]),
        ]);
    }

    /**
     * Ustawia zdjęcie tak, jak trzymano telefon.
     *
     * Znacznik EXIF Orientation ma osiem wartości i cztery z nich to odbicia
     * lustrzane, nie same obroty. Pomijanie ich dawałoby zdjęcia poprawnie
     * obrócone, ale odbite — co przy zdjęciu kartki z przepisem oznacza tekst
     * czytany od tyłu.
     */
    private function applyOrientation(object $image, ?int $orientation): void
    {
        if ($orientation === null || $orientation === 1) {
            return;
        }

        match ($orientation) {
            2 => $image->flop(),
            3 => $image->rotate(180),
            4 => $image->flip(),
            5 => $image->rotate(-90)->flop(),
            6 => $image->rotate(-90),
            7 => $image->rotate(90)->flop(),
            8 => $image->rotate(90),
            default => null,
        };
    }
}

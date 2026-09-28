<?php

declare(strict_types=1);

namespace App\Domain\Import;

use App\Models\Media;
use App\Moderacja\ExceptionContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Zdjęcie kartki w postaci, w jakiej wolno je wysłać do OpenAI (D-296, D-298).
 *
 *  - TYLKO gotowy wariant, nigdy oryginał — oryginał niesie EXIF z GPS-em
 *    kuchni. Warianty powstają przez przekodowanie (`ProcessUploadedImage`),
 *    a tutaj przekodowujemy jeszcze raz przez GD do JPEG: GD nie przenosi
 *    żadnych metadanych (EXIF, XMP, ICC z opisem).
 *  - Dłuższy bok ≤ 2000 px, ZMIERZONY Z BAJTÓW — przed dekodowaniem i na
 *    gotowym JPEG-u (ta sama zasada co w D-240: nazwa wariantu i liczby
 *    w metadanych to deklaracja, a granica dotyczy tego, co opuszcza serwer).
 *  - Wariant `large` (1600 px) ma rozdzielczość, w której da się przeczytać
 *    pismo; `feed` (960 px) jest zapasowy, gdy `large` nie powstał.
 */
final class ObrazDoOdczytu
{
    /** Twardy sufit w kodzie; konfiguracja może go tylko obniżyć. */
    public const MAX_BOK = 2000;

    public static function maxBok(): int
    {
        return max(320, min(self::MAX_BOK, (int) config('kuking.import.limity.max_bok_px', self::MAX_BOK)));
    }

    public function jpeg(Media $media): ?string
    {
        if ($media->status !== Media::STATUS_READY) {
            return null;
        }

        foreach (['large', 'feed'] as $nazwa) {
            $wariant = $media->wariant($nazwa);
            $klucz = is_array($wariant) ? ($wariant['key'] ?? null) : null;

            if (! is_string($klucz) || trim($klucz) === '') {
                continue;
            }

            try {
                $bajty = Storage::disk($media->variantsDisk())->get($klucz);

                if (! is_string($bajty) || $bajty === '' || ! $this->miesciSie($bajty)) {
                    continue;
                }

                $jpeg = (string) ImageManager::gd()->read($bajty)
                    ->scaleDown(width: self::maxBok(), height: self::maxBok())
                    ->toJpeg(quality: 85);
            } catch (Throwable $blad) {
                // Bez bajtów i treści — to jest czyjaś kartka.
                Log::warning('Nie udało się przygotować zdjęcia kartki do odczytu.', [
                    'media_id' => (string) $media->getKey(),
                    ...ExceptionContext::forStage($blad, 'import_obraz'),
                ]);

                continue;
            }

            if ($this->miesciSie($jpeg)) {
                return $jpeg;
            }
        }

        return null;
    }

    private function miesciSie(string $bajty): bool
    {
        $rozmiar = @getimagesizefromstring($bajty);

        return $rozmiar !== false
            && $rozmiar[0] > 0 && $rozmiar[1] > 0
            && max($rozmiar[0], $rozmiar[1]) <= self::maxBok();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Logging\BezpiecznyBlad;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

/**
 * Surowe uploady Livewire, których nikt nie zapisał (#2178).
 *
 * SKĄD SIĘ BIORĄ
 * Kreator przepisu wgrywa zdjęcie do `livewire-tmp/` zaraz po wyborze pliku.
 * `StoreUploadedImage` kasuje źródło po UDANYM zapisie, ale plik może zostać
 * także wtedy, gdy zdjęcie odpadło (zły format, za duże — kreator zeruje pole
 * i człowiek wybiera plik od nowa), gdy ktoś wybrał plik i zamknął kartę,
 * albo gdy usunięcie po zapisie zawiodło. Takiego obiektu nie widzi ani
 * `OsieroconeZdjecia` (nie ma wiersza `media`), ani wymazanie konta.
 * Livewire sprząta starsze pliki dopiero przy KOLEJNYM uploadzie, więc bez
 * ruchu w kreatorze surowe zdjęcie — z EXIF-em, GPS-em i oryginalną nazwą
 * pliku w sąsiednim `.json` — leży w prywatnym buckecie bezterminowo.
 *
 * To jest linia obrony w repozytorium. Reguła lifecycle R2 z #2051 zostaje
 * drugą, niezależną linią (krok właściciela w panelu Cloudflare).
 *
 * GRANICE
 * - Dotyka WYŁĄCZNIE katalogu Livewire (`FileUploadConfiguration::directory()`),
 *   nigdy `incoming/`, wariantów ani eksportów. Pusta nazwa katalogu oznaczałaby
 *   cały dysk, więc wtedy nie robimy nic.
 * - Wiek liczymy od zapisu obiektu. Domyślnie 24 h, jak w `cleanupOldUploads()`
 *   Livewire: plik zapisany przed chwilą to formularz, który ktoś właśnie
 *   poprawia, a nie sierota.
 * - Do dziennika nie idzie ani klucz obiektu, ani nazwa pliku: obok losowej
 *   nazwy leży plik `.json` z nazwą od klienta, czyli daną osobową.
 */
final class PorzuconePlikiLivewire
{
    /** Podłoga: krócej niż godzina kasowałaby pliki właśnie wgrywane. */
    public const MIN_GODZIN = 1;

    public function __construct(private readonly int $godzinKarencji = 24) {}

    /**
     * @return array{skasowane: int, bledy: int} ile obiektów skasowano (przy
     *                                           `$naSucho`: skasowano by) i ile razy się nie udało
     */
    public function posprzataj(bool $naSucho = false): array
    {
        $katalog = trim(FileUploadConfiguration::directory(), '/');

        if ($katalog === '') {
            return ['skasowane' => 0, 'bledy' => 0];
        }

        $nazwaDysku = (string) (config('livewire.temporary_file_upload.disk') ?: config('filesystems.default'));
        $granica = now()->subHours(max(self::MIN_GODZIN, $this->godzinKarencji))->getTimestamp();
        $skasowane = 0;
        $bledy = 0;

        try {
            $magazyn = Storage::disk($nazwaDysku);
            $pliki = $magazyn->files($katalog);
        } catch (\Throwable $blad) {
            Log::warning('Nie udało się wylistować porzuconych plików Livewire', [
                'dysk' => $nazwaDysku,
                'error' => BezpiecznyBlad::kontekst($blad),
            ]);

            return ['skasowane' => 0, 'bledy' => 1];
        }

        foreach ($pliki as $sciezka) {
            try {
                // Strażnik prefiksu: nawet gdyby dysk oddał coś spoza katalogu.
                if (! str_starts_with($sciezka, $katalog.'/')) {
                    continue;
                }

                if ($magazyn->lastModified($sciezka) >= $granica) {
                    continue;
                }

                if ($naSucho) {
                    $skasowane++;

                    continue;
                }

                $magazyn->delete($sciezka);

                // `delete()` na dysku z `throw => false` oddaje cichy `false`,
                // więc dowodem jest dopiero `exists()`.
                if ($magazyn->exists($sciezka)) {
                    $bledy++;
                    Log::warning('Porzucony plik Livewire nadal istnieje po próbie usunięcia', ['dysk' => $nazwaDysku]);

                    continue;
                }

                $skasowane++;
            } catch (\Throwable $blad) {
                $bledy++;
                Log::warning('Nie udało się usunąć porzuconego pliku Livewire', [
                    'dysk' => $nazwaDysku,
                    'error' => BezpiecznyBlad::kontekst($blad),
                ]);
            }
        }

        return ['skasowane' => $skasowane, 'bledy' => $bledy];
    }
}

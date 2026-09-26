<?php

declare(strict_types=1);

namespace App\Domain\Media;

use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Zdjęcie z kreatora przepisu jako plik na LOKALNYM dysku (audyt A5-08).
 *
 * PO CO TO ISTNIEJE
 * Livewire trzyma wgrany plik na dysku tymczasowym, a na produkcji tym
 * dyskiem jest R2 (`LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=r2`). Wtedy
 * `TemporaryUploadedFile::getRealPath()` oddaje `storage->path()`, czyli dla
 * dysku zdalnego ścieżkę WZGLĘDNĄ w buckecie (`livewire-tmp/…`), a nie plik.
 * `getimagesize()` i `exif_read_data()` takiego pliku nie znajdują, więc
 * `StoreUploadedImage` odrzucał każde zdjęcie komunikatem „Ten plik nie
 * wygląda na zdjęcie". Testy tego nie widziały, bo Livewire podmienia
 * w nich dysk tymczasowy na lokalny.
 *
 * Kopia powstaje TYLKO wtedy, gdy pliku pod `getRealPath()` nie ma na dysku
 * lokalnym — zwykły upload z formularza i lokalny dysk Livewire idą bez
 * zmian. Plik tymczasowy kasuje `sprzataj()`; wywołujący robi to w `finally`.
 */
final class LokalnaKopiaZdjecia
{
    private function __construct(
        public readonly UploadedFile $plik,
        private readonly ?string $sciezkaKopii,
    ) {}

    public static function zapewnij(UploadedFile $plik): self
    {
        if (! $plik instanceof TemporaryUploadedFile || is_file($plik->getRealPath())) {
            return new self($plik, null);
        }

        $zrodlo = $plik->readStream();
        $sciezka = tempnam(sys_get_temp_dir(), 'kuking-zdjecie-');

        // Nie da się odczytać ani skopiować — oddajemy plik bez zmian.
        // `StoreUploadedImage` odrzuci go wtedy zwykłą drogą, z sygnałem
        // i komunikatem po polsku, zamiast wywrócić żądanie wyjątkiem.
        if (! is_resource($zrodlo) || $sciezka === false) {
            if (is_resource($zrodlo)) {
                fclose($zrodlo);
            }

            return new self($plik, null);
        }

        $cel = fopen($sciezka, 'wb');

        try {
            $skopiowano = $cel !== false && stream_copy_to_stream($zrodlo, $cel) !== false;
        } finally {
            fclose($zrodlo);

            if ($cel !== false) {
                fclose($cel);
            }
        }

        if (! $skopiowano) {
            @unlink($sciezka);

            return new self($plik, null);
        }

        // `test: true` — plik nie przyszedł przez `move_uploaded_file()`,
        // więc bez tego `isValid()` oddałoby fałsz. Przyszedł od Livewire,
        // który go już przyjął; to niczego nie odblokowuje, bo zawartości
        // i tak nie ufamy — sprawdza ją dalej `StoreUploadedImage`.
        return new self(
            new UploadedFile($sciezka, $plik->getClientOriginalName(), null, null, true),
            $sciezka,
        );
    }

    public function sprzataj(): void
    {
        if ($this->sciezkaKopii !== null && is_file($this->sciezkaKopii)) {
            @unlink($this->sciezkaKopii);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Users\Import;

use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Prywatna poczekalnia dla paczki, którą człowiek wybrał, a jeszcze nie zatwierdził.
 *
 * Podgląd i zapis to dwa osobne żądania, więc ZIP musi gdzieś poczekać. Leży na
 * dysku `local` (NIGDY publicznym), w katalogu konkretnej osoby:
 * `import-paczek/<id osoby>/<token>.zip`. Token z adresu nie jest autoryzacją —
 * ścieżkę składamy z identyfikatora ZALOGOWANEJ osoby, więc cudzy token nie
 * wskaże niczego. Plik żyje krótko (`przechowanie_godzin`), znika po wczytaniu,
 * po odrzuceniu i przy wymazaniu konta; przeterminowane sprząta każde wejście
 * w ekran wczytywania.
 */
final class MagazynPaczek
{
    private const KATALOG = 'import-paczek';

    private function dysk(): Filesystem
    {
        return Storage::disk('local');
    }

    public function zapisz(User $user, UploadedFile $plik): string
    {
        $this->sprzatnijPrzeterminowane();

        $token = (string) Str::uuid();
        $this->dysk()->putFileAs(self::KATALOG.'/'.$user->getKey(), $plik, $token.'.zip');

        return $token;
    }

    /** Ścieżka na dysku do pliku tej osoby albo `null`, gdy go nie ma (wygasł, cudzy, zły token). */
    public function sciezka(User $user, string $token): ?string
    {
        if (! Str::isUuid($token)) {
            return null;
        }

        $wzgledna = self::KATALOG.'/'.$user->getKey().'/'.strtolower($token).'.zip';

        if (! $this->dysk()->exists($wzgledna)) {
            return null;
        }

        if ($this->przeterminowany($wzgledna)) {
            $this->dysk()->delete($wzgledna);

            return null;
        }

        return $this->dysk()->path($wzgledna);
    }

    public function zapomnij(User $user, string $token): void
    {
        if (Str::isUuid($token)) {
            $this->dysk()->delete(self::KATALOG.'/'.$user->getKey().'/'.strtolower($token).'.zip');
        }
    }

    /** Wymazanie konta: nic z paczek tej osoby nie zostaje. */
    public function zapomnijWszystkie(User $user): void
    {
        $this->dysk()->deleteDirectory(self::KATALOG.'/'.$user->getKey());
    }

    /**
     * Kasuje przeterminowane paczki wszystkich osób (i puste już katalogi osób).
     *
     * @return int liczba skasowanych plików
     */
    public function sprzatnijPrzeterminowane(): int
    {
        $skasowane = 0;

        foreach ($this->dysk()->allFiles(self::KATALOG) as $plik) {
            if ($this->przeterminowany($plik)) {
                $this->dysk()->delete($plik);
                $skasowane++;
            }
        }

        foreach ($this->dysk()->directories(self::KATALOG) as $katalog) {
            if ($this->dysk()->allFiles($katalog) === []) {
                $this->dysk()->deleteDirectory($katalog);
            }
        }

        return $skasowane;
    }

    private function przeterminowany(string $wzgledna): bool
    {
        $godziny = max(1, (int) config('kuking.import_paczki.przechowanie_godzin'));

        return $this->dysk()->lastModified($wzgledna) < now()->subHours($godziny)->getTimestamp();
    }
}

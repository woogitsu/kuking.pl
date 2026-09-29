<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Plik PDF czekający na worker (#28, etap 2, #2051).
 *
 * WSPÓLNY, PRYWATNY DYSK, NIE `getRealPath()`. Formularz przyjmuje plik
 * w procesie WWW, a odczyt idzie w zadaniu — na produkcji to mogą być dwa
 * kontenery. Plik trafia więc na dysk z `kuking.import.pdf.dysk` (domyślnie
 * prywatny dysk surowych uploadów) w KATALOGU `kuking.import.pdf.katalog`,
 * odrębnym od `livewire-tmp/`, `incoming/` i publicznych wariantów, żeby
 * reguła lifecycle dla jednego z nich nie objęła drugiego przypadkiem.
 *
 * NAZWA PLIKU OD CZŁOWIEKA NIE ISTNIEJE NA DYSKU: klucz to losowy UUID
 * z rozszerzeniem `.pdf`. Klucza ani ścieżki nie logujemy.
 *
 * KASOWANIE JEST POJEDYNCZE I POWTARZALNE: `skasuj()` zwraca prawdę, gdy
 * pliku już nie ma (także gdy nie było go wcześniej), a fałsz tylko gdy
 * dysk odmówił — wtedy wiersz zlecenia zachowuje ścieżkę i sprząta ją
 * `kuking:odzyskaj-importy`.
 */
final class PlikTymczasowyImportu
{
    public function dysk(): Filesystem
    {
        return Storage::disk((string) config('kuking.import.pdf.dysk', 'local'));
    }

    public function katalog(): string
    {
        return trim((string) config('kuking.import.pdf.katalog', 'import-pdf-tmp'), '/');
    }

    /**
     * Kopiuje plik z żądania na dysk importu i zwraca ścieżkę na dysku.
     * Wołać PO szybkich kontrolach (rozmiar, sygnatura), PRZED transakcją
     * zlecenia — zapis do zdalnego bucketu nie trzyma blokady osoby.
     */
    public function zapisz(string $sciezkaLokalna): ?string
    {
        $strumien = @fopen($sciezkaLokalna, 'rb');

        if ($strumien === false) {
            return null;
        }

        $sciezka = $this->katalog().'/'.Str::uuid7().'.pdf';

        try {
            $zapisano = $this->dysk()->put($sciezka, $strumien);
        } catch (Throwable) {
            $zapisano = false;
        } finally {
            if (is_resource($strumien)) {
                fclose($strumien);
            }
        }

        return $zapisano ? $sciezka : null;
    }

    /**
     * Kopia robocza w katalogu tymczasowym systemu — Poppler czyta ścieżkę,
     * nie strumień. Wołający kasuje ją w `finally` (`usunLokalna()`).
     */
    public function pobierzDoLokalnego(string $sciezka): ?string
    {
        if (! $this->nalezyDoKatalogu($sciezka)) {
            return null;
        }

        try {
            $zrodlo = $this->dysk()->readStream($sciezka);
        } catch (Throwable) {
            return null;
        }

        if (! is_resource($zrodlo)) {
            return null;
        }

        $lokalna = tempnam(sys_get_temp_dir(), 'kuking-pdf-');

        if ($lokalna === false) {
            fclose($zrodlo);

            return null;
        }

        $cel = fopen($lokalna, 'wb');

        if ($cel === false || stream_copy_to_stream($zrodlo, $cel) === false) {
            fclose($zrodlo);
            if (is_resource($cel)) {
                fclose($cel);
            }
            @unlink($lokalna);

            return null;
        }

        fclose($zrodlo);
        fclose($cel);
        @chmod($lokalna, 0600);

        return $lokalna;
    }

    public function usunLokalna(?string $lokalna): void
    {
        if ($lokalna !== null && is_file($lokalna)) {
            @unlink($lokalna);
        }
    }

    /** Prawda, gdy pliku na dysku już nie ma. Ścieżka spoza katalogu importu jest ignorowana (nic nie kasujemy poza nim). */
    public function skasuj(?string $sciezka): bool
    {
        if ($sciezka === null || $sciezka === '') {
            return true;
        }

        if (! $this->nalezyDoKatalogu($sciezka)) {
            return true;
        }

        try {
            $this->dysk()->delete($sciezka);

            return ! $this->dysk()->exists($sciezka);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Pliki z katalogu importu, których wiek przekracza granicę, a które nie
     * są już potrzebne żadnemu zleceniu (`$potrzebne` — ścieżki z wierszy).
     * To obejmuje plik przyjęty, zanim powstał wiersz (przerwana transakcja
     * zlecenia) i plik po usuniętym koncie.
     *
     * @param  list<string>  $potrzebne
     */
    public function skasujOsierocone(int $starszeNizSekund, array $potrzebne): int
    {
        $granica = time() - max(60, $starszeNizSekund);
        $skasowano = 0;

        try {
            $pliki = $this->dysk()->files($this->katalog());
        } catch (Throwable) {
            return 0;
        }

        $potrzebne = array_flip($potrzebne);

        foreach ($pliki as $plik) {
            if (isset($potrzebne[$plik])) {
                continue;
            }

            try {
                if ($this->dysk()->lastModified($plik) >= $granica) {
                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            if ($this->skasuj($plik)) {
                $skasowano++;
            }
        }

        return $skasowano;
    }

    private function nalezyDoKatalogu(string $sciezka): bool
    {
        return str_starts_with($sciezka, $this->katalog().'/') && ! str_contains($sciezka, '..');
    }
}

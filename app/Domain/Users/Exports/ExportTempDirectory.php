<?php

declare(strict_types=1);

namespace App\Domain\Users\Exports;

use ErrorException;
use FilesystemIterator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SplFileInfo;
use UnexpectedValueException;

/**
 * Katalog tymczasowy JEDNEGO eksportu (issue #993).
 *
 * `GenerateUserExport` składa tu najpełniejszą kopię konta, jaka w ogóle
 * istnieje: ZIP, `dane.json` i kopie zdjęć. Do 23 września 2026 pliki
 * leżały luzem w `sys_get_temp_dir()` jako `kuking-eksport-<losowy uuid>.*`,
 * a ich lista żyła wyłącznie w pamięci jednego uruchomienia joba. Twarde
 * zakończenie procesu (timeout, zabity kontener, OOM) zostawiało je bez
 * właściciela: `failed()` działa na NOWEJ instancji joba, odtworzonej
 * z ładunku kolejki, więc tej listy już nie znał, a `kuking:sprzataj-eksporty`
 * sprząta tylko obiekty znane bazie.
 *
 * Teraz ścieżka wynika z samego identyfikatora eksportu
 * (`<katalog główny>/<data_export_id>/`). Każda instancja joba — także ta
 * w `failed()` i ta przy ponowieniu — umie więc usunąć pliki poprzedniej.
 *
 * KATALOG GŁÓWNY JEST OSOBNY DLA UŻYTKOWNIKA SYSTEMU (`kuking-eksport.u<uid>`)
 * albo ustawiony wprost (`kuking.exports.temp_dir`). Wspólny
 * `<tmp>/kuking-eksport` założony z prawami 0700 przez jednego użytkownika był
 * nieczytelny dla drugiego (kilka runnerów CI na jednym hoście), a
 * `sweepStale()` rzucało wtedy z `FilesystemIterator` i wywracało eksport,
 * zanim cokolwiek zbudował. Kropka, nie myślnik: glob starego układu
 * (`kuking-eksport-*`) nie może złapać nowego katalogu głównego.
 *
 * SPRZĄTANIE NIGDY NIE WYWRACA EKSPORTU. Nieczytelny katalog albo wpis to
 * jeden `Log::warning` (klasa wyjątku, bez ścieżki i bez komunikatu, który ją
 * zawiera) i idziemy dalej — sprzątanie to higiena, nie warunek paczki.
 *
 * SPRZĄTANIE STARYCH KATALOGÓW ROBI WORKER, NIE HARMONOGRAM. Na Railway
 * worker i scheduler to osobne usługi z osobnymi dyskami (`docker/entrypoint.sh`),
 * więc komenda z harmonogramu nie zobaczyłaby katalogu tymczasowego workera.
 * Dlatego `sweepStale()` woła każdy start eksportu, w tym samym kontenerze,
 * w którym pliki powstały.
 *
 * PRÓG `STALE_AFTER_SECONDS` = godzina, czyli czterokrotność limitu jednej
 * próby (`GenerateUserExport::$timeout` = 15 minut). Aktywny eksport dotyka
 * swoich plików co najwyżej co kilka sekund (każde zdjęcie, każde domknięcie
 * ZIP-a), więc katalog nieruszany od godziny nie należy do żadnego żywego
 * joba — także przy kilku workerach naraz.
 *
 * Nieudane usunięcie NIE jest ciche: `Log::warning` z identyfikatorem
 * eksportu i liczbą plików, bez ścieżek i bez treści.
 */
final class ExportTempDirectory
{
    public const STALE_AFTER_SECONDS = 3600;

    private const ROOT_NAME = 'kuking-eksport';

    public static function root(): string
    {
        $configured = config('kuking.exports.temp_dir');

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return sys_get_temp_dir().'/'.self::ROOT_NAME.'.u'.self::processOwner();
    }

    /** Identyfikator użytkownika systemu, pod którym działa proces. */
    private static function processOwner(): string
    {
        if (function_exists('posix_geteuid')) {
            return (string) posix_geteuid();
        }

        return preg_replace('/[^A-Za-z0-9_]/', '_', get_current_user()) ?: 'nieznany';
    }

    public static function forExport(string $dataExportId): string
    {
        return self::root().'/'.$dataExportId;
    }

    /** Nowa, pusta ścieżka pliku w katalogu tego eksportu (katalog powstaje w razie potrzeby). */
    public static function newFile(string $dataExportId, string $extension): string
    {
        $dir = self::forExport($dataExportId);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Nie udało się utworzyć katalogu tymczasowego eksportu.');
        }

        return $dir.'/'.Str::uuid()->toString().'.'.$extension;
    }

    /** Usuwa katalog jednego eksportu razem z zawartością. Zwraca `false`, gdy coś zostało. */
    public static function remove(string $dataExportId): bool
    {
        $dir = self::forExport($dataExportId);

        if (! is_dir($dir)) {
            return true;
        }

        $failed = 0;

        try {
            foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $entry) {
                /** @var SplFileInfo $entry */
                if (! @unlink($entry->getPathname()) && file_exists($entry->getPathname())) {
                    $failed++;
                }
            }
        } catch (UnexpectedValueException|ErrorException $e) {
            // Katalogu nie da się nawet przeczytać — nic z niego nie zeszło.
            Log::warning('Nie udało się odczytać katalogu tymczasowego eksportu danych', [
                'data_export_id' => $dataExportId,
                'blad' => $e::class,
            ]);

            return false;
        }

        if ($failed === 0 && ! @rmdir($dir) && is_dir($dir)) {
            $failed++;
        }

        if ($failed > 0) {
            Log::warning('Nie udało się usunąć plików tymczasowych eksportu danych', [
                'data_export_id' => $dataExportId,
                'pozostalo_plikow' => $failed,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Usuwa katalogi eksportów nieruszane dłużej niż próg — osierocone po
     * twardym przerwaniu procesu. Obejmuje też luźne pliki
     * `kuking-eksport-*` w starym układzie sprzed #993.
     *
     * @return int liczba usuniętych katalogów i plików
     */
    public static function sweepStale(?int $now = null): int
    {
        $threshold = ($now ?? time()) - self::STALE_AFTER_SECONDS;
        $removed = 0;

        foreach (glob(sys_get_temp_dir().'/'.self::ROOT_NAME.'-*') ?: [] as $legacy) {
            if (is_file($legacy) && self::lastTouched($legacy) < $threshold) {
                if (@unlink($legacy) || ! file_exists($legacy)) {
                    $removed++;
                } else {
                    Log::warning('Nie udało się usunąć osieroconego pliku tymczasowego eksportu danych');
                }
            }
        }

        $root = self::root();

        if (! is_dir($root)) {
            return $removed;
        }

        $unreadable = null;

        try {
            foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
                /** @var SplFileInfo $entry */
                try {
                    if (! $entry->isDir() || self::lastTouched($entry->getPathname()) >= $threshold) {
                        continue;
                    }
                } catch (UnexpectedValueException|ErrorException $e) {
                    // Nieczytelny wpis pomijamy; pozostałe sprzątamy dalej.
                    $unreadable ??= $e::class;

                    continue;
                }

                if (self::remove($entry->getFilename())) {
                    $removed++;
                }
            }
        } catch (UnexpectedValueException|ErrorException $e) {
            $unreadable ??= $e::class;
        }

        if ($unreadable !== null) {
            // Raz na przebieg, bez ścieżki: komunikat wyjątku ją zawiera.
            Log::warning('Nie udało się odczytać katalogu tymczasowego eksportów — sprzątanie pominięte', [
                'blad' => $unreadable,
            ]);
        }

        return $removed;
    }

    /** Najpóźniejsza modyfikacja katalogu albo któregokolwiek pliku w nim. */
    private static function lastTouched(string $path): int
    {
        $latest = (int) @filemtime($path);

        if (is_dir($path)) {
            foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
                /** @var SplFileInfo $entry */
                $latest = max($latest, (int) @filemtime($entry->getPathname()));
            }
        }

        return $latest;
    }
}

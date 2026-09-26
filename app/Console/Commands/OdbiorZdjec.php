<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Support\Odmiana;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Odbiór przetwarzania zdjęć na produkcji po wdrożeniu (#601).
 *
 * PO CO TO ISTNIEJE
 * #625 wdrożył „jedno dekodowanie oryginału na zadanie" i CI oraz Railway są
 * zielone — ale to dowodzi, że kod się wdrożył, nie że PRAWDZIWE zadanie
 * `ProcessUploadedImage` na produkcji zrobiło z prawdziwego zdjęcia komplet
 * poprawnych wariantów. Brakowało narzędzia, które po wgraniu zdjęcia
 * kontrolnego powie to jednym przebiegiem, zamiast oglądania wpisu okiem.
 *
 * CO SPRAWDZA — dla zdjęć wgranych od chwili `--od` (albo wskazanych `--media`)
 *  - status: `ready` jest odbiorem, `rejected` jest porażką, `pending`
 *    i `processing` dłużej niż `--utkniete-min` minut to zadanie, które
 *    utknęło; krócej — „jeszcze w toku", czyli odbioru jeszcze nie ma;
 *  - komplet wariantów z `config('kuking.media.variants')`: klucz, wymiary,
 *    dłuższy bok nie większy niż limit wariantu, niezerowy rozmiar;
 *  - orientację: przy EXIF `Orientation` 5–8 wariant ma mieć boki zamienione
 *    względem kolumn oryginału (kolumny opisują plik PRZED obrotem), przy
 *    pozostałych — ten sam kształt. To jest błąd, którego okiem nie widać
 *    na kwadratowej miniaturze, a który grupa 50+ odbiera jako „serwis
 *    odwrócił mi zdjęcie";
 *  - `--pliki`: czy każdy wariant naprawdę LEŻY w buckecie wariantów
 *    (`exists()`, żądanie klasy B na R2);
 *  - nieudane zadania `ProcessUploadedImage` w `failed_jobs` od `--od`;
 *  - czas od wgrania do `metadata.processed_at` (najdłuższy i mediana).
 *
 * CZEGO TO NIE ROBI
 * Nie zapisuje, nie kasuje i nie ponawia niczego — ani w bazie, ani
 * w bucketach. Nie wypisuje właściciela zdjęcia ani kluczy obiektów, tylko
 * UUID wiersza `media`. Nie mierzy jakości obrazu ani pamięci workera: to
 * zrobiły pomiary w `docs/infra/JEDNO_DEKODOWANIE_601.md`.
 *
 * KOD WYJŚCIA
 *  0 — każde sprawdzone zdjęcie przeszło odbiór;
 *  1 — cokolwiek nie przeszło albo jest jeszcze w toku;
 *  2 — w oknie nie ma ANI JEDNEGO zdjęcia. To znaczy „nic nie zmierzono",
 *      a nie „wszystko w porządku".
 */
class OdbiorZdjec extends Command
{
    public const NIC_NIE_ZMIERZONO = 2;

    protected $signature = 'kuking:odbior-zdjec
                            {--od= : Chwila, od której liczyć wgrane zdjęcia (np. czas wdrożenia, 2026-09-25T10:00:00Z)}
                            {--media=* : UUID konkretnego zdjęcia kontrolnego (można podać kilka razy)}
                            {--pliki : Sprawdź też, czy warianty leżą w buckecie (exists)}
                            {--utkniete-min=15 : Po ilu minutach pending/processing uznać za utknięte}';

    protected $description = 'Odbiór (tylko odczyt): czy zadanie zdjęć zrobiło komplet poprawnych wariantów';

    public function handle(): int
    {
        $od = $this->odKiedy();

        if ($od === false) {
            return self::FAILURE;
        }

        $uuidy = array_values(array_filter((array) $this->option('media'), fn ($v): bool => is_string($v) && $v !== ''));

        if ($od === null && $uuidy === []) {
            $this->error('Podaj --od (np. czas wdrożenia) albo --media=<UUID zdjęcia kontrolnego>.');

            return self::FAILURE;
        }

        $zapytanie = Media::query()->orderBy('created_at')->orderBy('id');

        if ($od !== null) {
            $zapytanie->where('created_at', '>=', $od);
        }

        if ($uuidy !== []) {
            $zapytanie->whereIn('id', $uuidy);
        }

        $limitWariantow = (array) config('kuking.media.variants');
        $utknieteMin = max(1, (int) $this->option('utkniete-min'));
        $pliki = (bool) $this->option('pliki');

        $sprawdzone = 0;
        $pominieteUsuniete = 0;
        $zProblemem = 0;
        $wToku = 0;
        $czasy = [];

        foreach ($zapytanie->cursor() as $zdjecie) {
            if ($zdjecie->status === Media::STATUS_DELETED) {
                $pominieteUsuniete++;

                continue;
            }

            $sprawdzone++;
            $id = (string) $zdjecie->getKey();

            if (in_array($zdjecie->status, [Media::STATUS_PENDING, Media::STATUS_PROCESSING], true)) {
                $minuty = (int) $zdjecie->created_at?->diffInMinutes(now(), true);

                if ($minuty >= $utknieteMin) {
                    $zProblemem++;
                    $this->warn("media {$id}: UTKNĘŁO — status {$zdjecie->status} od {$minuty} min. Sprawdź workera kolejki `media` i failed_jobs.");
                } else {
                    $wToku++;
                    $this->line("media {$id}: jeszcze w toku ({$zdjecie->status}, {$minuty} min). Uruchom odbiór ponownie za chwilę.");
                }

                continue;
            }

            if ($zdjecie->status !== Media::STATUS_READY) {
                $zProblemem++;
                $powod = (string) ($zdjecie->metadata['failure_reason'] ?? 'bez powodu w metadanych');
                $this->warn("media {$id}: ODRZUCONE ({$zdjecie->status}, {$powod}).");

                continue;
            }

            $problemy = $this->problemyGotowego($zdjecie, $limitWariantow, $pliki);

            $przetworzone = $zdjecie->metadata['processed_at'] ?? null;

            if (is_string($przetworzone) && $zdjecie->created_at !== null) {
                try {
                    $czasy[] = max(0.0, $zdjecie->created_at->diffInSeconds(CarbonImmutable::parse($przetworzone), false));
                } catch (Throwable) {
                    $problemy[] = 'metadata.processed_at nie jest datą';
                }
            } else {
                $problemy[] = 'brak metadata.processed_at — zdjęcie nie przeszło przez zadanie w tle';
            }

            if ($problemy === []) {
                $this->line("media {$id}: OK");

                continue;
            }

            $zProblemem++;
            $this->warn("media {$id}:");

            foreach ($problemy as $problem) {
                $this->warn('  - '.$problem);
            }
        }

        $nieudane = $this->nieudaneZadania($od);

        $this->newLine();
        $this->info('Sprawdzone zdjęcia: '.$sprawdzone.' (pominięte usunięte: '.$pominieteUsuniete.').');

        if ($czasy !== []) {
            sort($czasy);
            $mediana = $czasy[intdiv(count($czasy), 2)];
            $this->info(sprintf('Czas od wgrania do gotowości: mediana %.1f s, najdłużej %.1f s.', $mediana, end($czasy)));
        }

        if ($nieudane !== null) {
            $this->info('Nieudane zadania ProcessUploadedImage w failed_jobs'.($od !== null ? ' od '.$od->toIso8601String() : '').': '.$nieudane.'.');
        }

        if (! $pliki) {
            $this->line('Obecność plików w buckecie NIE była sprawdzana (brak --pliki): zgodne metadane nie dowodzą, że plik leży.');
        }

        $this->line('Ten raport niczego nie zmienił — ani w bazie, ani w bucketach.');

        if ($sprawdzone === 0) {
            $this->error('W tym oknie nie ma ani jednego zdjęcia. To znaczy „nic nie zmierzono", a nie „odbiór przeszedł". Wgraj zdjęcie kontrolne i uruchom ponownie.');

            return self::NIC_NIE_ZMIERZONO;
        }

        if ($zProblemem > 0 || ($nieudane ?? 0) > 0) {
            $this->error('Odbiór NIE przeszedł: '.$zProblemem.' '.Odmiana::rzeczownik($zProblemem, 'zdjęcie', 'zdjęcia', 'zdjęć').' z problemem, nieudanych zadań: '.($nieudane ?? 0).'.');

            return self::FAILURE;
        }

        if ($wToku > 0) {
            $this->warn('Odbioru jeszcze nie ma: '.$wToku.' '.Odmiana::rzeczownik($wToku, 'zdjęcie jest', 'zdjęcia są', 'zdjęć jest').' w toku.');

            return self::FAILURE;
        }

        $this->info('Odbiór przeszedł: każde sprawdzone zdjęcie ma komplet poprawnych wariantów.');

        return self::SUCCESS;
    }

    /**
     * `null` — nie podano; `false` — podano coś, co nie jest datą.
     */
    private function odKiedy(): CarbonImmutable|false|null
    {
        $od = $this->option('od');

        if (! is_string($od) || $od === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($od);
        } catch (Throwable) {
            $this->error("--od={$od} nie jest datą. Podaj np. 2026-09-25T10:00:00Z.");

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $limitWariantow
     * @return list<string>
     */
    private function problemyGotowego(Media $zdjecie, array $limitWariantow, bool $pliki): array
    {
        $problemy = [];
        $orientacja = (int) ($zdjecie->metadata['exif_orientation'] ?? 1);
        $obrocone = in_array($orientacja, [5, 6, 7, 8], true);
        $szer = (int) $zdjecie->width;
        $wys = (int) $zdjecie->height;

        // Kształt oryginału po obrocie: poziomy, pionowy albo kwadrat.
        // Kwadratu nie da się pomylić przy obrocie, więc go nie sprawdzamy.
        $ksztaltOczekiwany = $szer === $wys ? 0 : (($szer > $wys) !== $obrocone ? 1 : -1);

        foreach ($limitWariantow as $nazwa => $limit) {
            $nazwa = (string) $nazwa;
            $wariant = $zdjecie->wariant($nazwa);

            if ($wariant === null || ! isset($wariant['key'])) {
                $problemy[] = "brak wariantu {$nazwa}";

                continue;
            }

            $w = (int) ($wariant['width'] ?? 0);
            $h = (int) ($wariant['height'] ?? 0);

            if ($w <= 0 || $h <= 0) {
                $problemy[] = "wariant {$nazwa} bez wymiarów";

                continue;
            }

            if (max($w, $h) > (int) $limit) {
                $problemy[] = "wariant {$nazwa} ma {$w}×{$h}, a dłuższy bok nie może przekraczać {$limit}";
            }

            $bajty = $wariant['bytes'] ?? null;

            if ($bajty !== null && (int) $bajty <= 0) {
                $problemy[] = "wariant {$nazwa} ma zerowy rozmiar";
            }

            $ksztalt = $w === $h ? 0 : ($w > $h ? 1 : -1);

            // Przy małym dłuższym boku zaokrąglenie scaleDown może zrobić
            // z prawie-kwadratu kwadrat — to nie jest błąd obrotu.
            if ($ksztaltOczekiwany !== 0 && $ksztalt !== 0 && $ksztalt !== $ksztaltOczekiwany) {
                $problemy[] = "wariant {$nazwa} ma {$w}×{$h}, a oryginał {$szer}×{$wys} z EXIF Orientation {$orientacja} — zła orientacja";
            }

            if ($pliki) {
                try {
                    if (! Storage::disk($zdjecie->variantsDisk())->exists((string) $wariant['key'])) {
                        $problemy[] = "wariant {$nazwa} nie leży w buckecie {$zdjecie->variantsDisk()}";
                    }
                } catch (Throwable $e) {
                    $problemy[] = "nie udało się sprawdzić pliku wariantu {$nazwa}: ".$e->getMessage();
                }
            }
        }

        return $problemy;
    }

    private function nieudaneZadania(?CarbonImmutable $od): ?int
    {
        try {
            $zapytanie = DB::table((string) config('queue.failed.table', 'failed_jobs'))
                // Sama nazwa klasy, bez przestrzeni nazw: w `payload` ukośniki są
                // podwójnie ucieczkowane (JSON), a w LIKE ukośnik też jest
                // znakiem ucieczki — dopasowanie po pełnej nazwie byłoby kruche.
                ->where('payload', 'like', '%'.class_basename(ProcessUploadedImage::class).'%');

            if ($od !== null) {
                $zapytanie->where('failed_at', '>=', $od);
            }

            return $zapytanie->count();
        } catch (Throwable $e) {
            $this->warn('Nie udało się odczytać failed_jobs: '.$e->getMessage());

            return null;
        }
    }
}

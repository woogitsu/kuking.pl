<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Logging\BezpiecznyBlad;
use App\Models\Media;
use App\Support\Odmiana;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * BRAMKA: czy stary bucket (`r2_legacy`) jest jeszcze czyjąś JEDYNĄ kopią.
 *
 * PO CO
 * Zdjęcia sprzed rozdzielenia bucketów (migracja
 * `2026_09_06_180000_point_existing_media_at_legacy_disk`) mają
 * `disk = 'r2_legacy'` i leżą wyłącznie w starym, jednym buckecie. Nie
 * obejmuje ich migawka kopii zdjęć (`docs/infra/DR_ZDJEC_R2.md`: „najpierw
 * dokończ przenosiny"), więc dla nich stary bucket nie jest „starym
 * bucketem", tylko jedynym egzemplarzem. Do 26.09.2026 odpowiedź na pytanie
 * „ile takich zdjęć jest" wymagała `tinker` na produkcji
 * (`docs/infra/KOPIE_I_ODTWORZENIE.md` §8, pytanie 8), a pytania „czy ich
 * pliki tam naprawdę są" nie zadawało nic.
 *
 * CO MÓWI
 *  1. ile wierszy `media` wskazuje stary bucket — oryginałem albo wariantami
 *     — w rozbiciu na status;
 *  2. z `--pliki`: czy pliki tych wierszy (tylko `ready`) leżą w starym
 *     buckecie. Plik, którego tam nie ma, jest UTRACONY — nie ma go nigdzie
 *     indziej, bo przenosiny przestawiają wiersz dopiero po skopiowaniu (#1031);
 *  3. werdykt bramki.
 *
 * KOD WYJŚCIA JEST BRAMKĄ
 *  - 0 — żaden wiersz nie wskazuje starego bucketu. To WARUNEK KONIECZNY,
 *        nie wystarczający, zanim ktokolwiek rozważy czyszczenie starego
 *        bucketu (runbook `docs/infra/STARY_BUCKET_R2_LEGACY.md` §4);
 *  - 1 — są wiersze zależne od starego bucketu, brakuje plików, bucket nie
 *        jest skonfigurowany albo odczyt się nie udał.
 * Świadomie nie ma kodu „zależne, ale wszystko na miejscu = OK": taki stan
 * znaczy dokładnie „nie wolno ruszać starego bucketu".
 *
 * CZEGO NIE ROBI
 * Niczego nie kopiuje, nie przestawia i nie kasuje — same `SELECT`
 * i `exists()`. Wolno ją uruchomić na produkcji bez pytania o zgodę
 * (`AGENTS.md` §6 dotyczy operacji destrukcyjnych). Nie wypisuje kluczy
 * obiektów ani komunikatów wyjątków (#973) — tylko identyfikator wiersza.
 */
class ZaleznoscOdStaregoBucketu extends Command
{
    private const STARY = 'r2_legacy';

    protected $signature = 'kuking:zaleznosc-od-starego-bucketu
                            {--pliki : Sprawdź też, czy pliki gotowych zdjęć leżą w starym buckecie (żądania klasy B)}
                            {--limit=0 : Z --pliki: sprawdź najwyżej tyle wierszy (0 = wszystkie)}';

    protected $description = 'Bramka (tylko odczyt): ile zdjęć ma stary bucket r2_legacy za jedyną kopię i czy ich pliki tam są';

    public function handle(): int
    {
        $zalezne = fn () => Media::query()->where(
            fn ($q) => $q->where('disk', self::STARY)->orWhere('variants_disk', self::STARY),
        );

        /** @var array<string, int> $wgStatusu */
        $wgStatusu = $zalezne()
            ->select('status', DB::raw('count(*) as ile'))
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('ile', 'status')
            ->map(fn ($ile) => (int) $ile)
            ->all();

        $razem = array_sum($wgStatusu);
        $bucket = (string) config('filesystems.disks.'.self::STARY.'.bucket');

        $this->info('Zależność od starego bucketu `'.self::STARY.'`');
        $this->line('Bucket w konfiguracji (AWS_LEGACY_BUCKET): '.($bucket === '' ? 'NIE USTAWIONY' : 'ustawiony'));

        if ($razem === 0) {
            $this->newLine();
            $this->info('Żaden wiersz media nie wskazuje starego bucketu.');
            $this->line('To warunek KONIECZNY, nie wystarczający. Zanim ktokolwiek rozważy czyszczenie starego bucketu, '
                .'przejdź resztę bramki: docs/infra/STARY_BUCKET_R2_LEGACY.md §4.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(['Status', 'Wierszy'], [
            ...array_map(fn ($status, $ile) => [$status, $ile], array_keys($wgStatusu), $wgStatusu),
            ['RAZEM', $razem],
        ]);

        $gotowe = $wgStatusu[Media::STATUS_READY] ?? 0;
        $this->error('STARY BUCKET JEST JEDYNĄ KOPIĄ '.$gotowe.' '
            .Odmiana::rzeczownik($gotowe, 'gotowego zdjęcia', 'gotowych zdjęć', 'gotowych zdjęć')
            .'. Nie czyść go, nie przemianowuj i nie zdejmuj z niego tokenu.');

        if ($bucket === '') {
            $this->error('AWS_LEGACY_BUCKET jest puste, a wiersze wskazują ten dysk — te zdjęcia NIE SERWUJĄ SIĘ. '
                .'Ustaw nazwę starego bucketu w AWS_LEGACY_BUCKET (DEPLOYMENT_RUNBOOK.md §2.1).');

            return self::FAILURE;
        }

        if ((bool) $this->option('pliki')) {
            $this->sprawdzPliki($zalezne()->where('status', Media::STATUS_READY)->orderBy('id'));
        } else {
            $this->line('Pliki NIE były sprawdzane (brak --pliki): liczba wierszy nie mówi, czy ich pliki istnieją.');
        }

        $this->line('Dalej: kuking:przenies-zdjecia --dry-run, potem przenosiny partiami (docs/infra/STARY_BUCKET_R2_LEGACY.md §3).');

        return self::FAILURE;
    }

    /** @param  Builder<Media>  $zapytanie */
    private function sprawdzPliki($zapytanie): void
    {
        $limit = (int) $this->option('limit');

        if ($limit > 0) {
            $zapytanie->limit($limit);
        }

        $dysk = Storage::disk(self::STARY);
        $sprawdzone = 0;
        $utracone = 0;
        $bledy = 0;

        foreach ($zapytanie->cursor() as $zdjecie) {
            $sprawdzone++;
            $braki = [];

            try {
                foreach ($this->kluczeWStarym($zdjecie) as $co => $klucz) {
                    if (! $dysk->exists($klucz)) {
                        $braki[] = $co;
                    }
                }
            } catch (Throwable $e) {
                $bledy++;
                $opis = BezpiecznyBlad::kontekst($e);
                $this->error('BŁĄD ODCZYTU: media '.$zdjecie->getKey().' — '.$opis['wyjatek']
                    .(isset($opis['kod']) ? ' ('.$opis['kod'].')' : '').', odcisk '.$opis['odcisk']);

                continue;
            }

            if ($braki !== []) {
                $utracone++;
                $this->warn('UTRACONE media '.$zdjecie->getKey().': brak '.implode(', ', $braki).' w starym buckecie');
            }
        }

        $this->newLine();
        $this->info('Sprawdzone gotowe zdjęcia: '.$sprawdzone.'.');
        $this->info('Z brakującym plikiem (UTRACONE — nie ma ich nigdzie indziej): '.$utracone.'.');

        if ($bledy > 0) {
            $this->error('Odczytów, które się nie udały: '.$bledy.'. Wynik jest NIEPEŁNY.');
        }
    }

    /**
     * Klucze, które powinny leżeć w STARYM buckecie: oryginał, gdy `disk`
     * jest stary, i warianty, gdy ich dysk (`variantsDisk()`) jest stary.
     *
     * @return array<string, string> opis => klucz
     */
    private function kluczeWStarym(Media $zdjecie): array
    {
        $klucze = [];

        if ($zdjecie->disk === self::STARY && (string) $zdjecie->object_key !== '') {
            $klucze['oryginał'] = (string) $zdjecie->object_key;
        }

        if ($zdjecie->variantsDisk() === self::STARY) {
            foreach ((array) ($zdjecie->metadata['variants'] ?? []) as $nazwa => $wariant) {
                if (is_array($wariant) && isset($wariant['key'])) {
                    $klucze['wariant '.(string) $nazwa] = (string) $wariant['key'];
                }
            }
        }

        return $klucze;
    }
}

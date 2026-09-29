<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProcessUploadedImage;
use App\Models\Media;
use App\Support\Odmiana;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Warianty gotowych zdjęć od nowa, z oryginału (issue #2223).
 *
 * PO CO
 * Runbook `docs/infra/KOPIE_I_ODTWORZENIE.md` §3, scenariusz (c1): publiczne
 * warianty (`thumb`, `feed`, `large`) przepadły, oryginały są całe. Do
 * 29.09.2026 jedyną drogą była pętla wpisana z palca w `php artisan tinker`
 * na produkcji — a ta pętla NIC by nie zrobiła: `ProcessUploadedImage`
 * odpuszcza zdjęcia `ready` jako spóźnione kopie zlecenia z uploadu.
 * Tinker wyszedł z obrazu produkcyjnego (D-333), więc ta komenda jest
 * jedyną drogą i robi to, co runbook obiecywał.
 *
 * CO ROBI
 * Bez `--wykonaj` tylko liczy i mówi, co zleciłaby. Z `--wykonaj` zleca na
 * kolejkę `media` zadanie `ProcessUploadedImage::odtworzWarianty()` dla
 * każdego gotowego zdjęcia z zakresu. Zadania wykonuje worker — ten sam,
 * który robi warianty przy uploadzie, z tymi samymi limitami pamięci.
 * Zdjęcie przez cały czas zostaje `ready`; porażka nie zmienia go
 * w `rejected`.
 *
 * NAJPIERW JEDNO ZDJĘCIE. `--media=<uuid>` zawęża zakres do jednego wiersza —
 * runbook każe sprawdzić wynik na nim, zanim ruszy cała reszta.
 *
 * CZEGO NIE ROBI: nie odtwarza oryginału (scenariusz c2 — nie ma z czego),
 * nie kasuje niczego i nie wypisuje kluczy obiektów (#973).
 */
class PrzetworzZdjeciaPonownie extends Command
{
    protected $signature = 'kuking:przetworz-zdjecia-ponownie
                            {--media= : Tylko to jedno zdjęcie (UUID z tabeli media) — zacznij od niego}
                            {--dysk= : Tylko zdjęcia, których warianty leżą na tym dysku (np. r2_publiczne)}
                            {--wykonaj : Naprawdę zleć zadania; bez tego komenda tylko liczy}';

    protected $description = 'Zleca zrobienie wariantów gotowych zdjęć od nowa z oryginałów (runbook kopii, scenariusz c1)';

    public function handle(): int
    {
        $media = $this->option('media');
        $dysk = $this->option('dysk');

        if ($media !== null && ! Str::isUuid((string) $media)) {
            $this->error('To nie jest identyfikator zdjęcia. Podaj UUID z kolumny `id` tabeli media, np. --media=9d1c…');

            return self::FAILURE;
        }

        $zakres = $this->zakres(
            $media === null ? null : (string) $media,
            $dysk === null || $dysk === '' ? null : (string) $dysk,
        );

        $ile = (clone $zakres)->count();
        $zdjec = Odmiana::rzeczownik($ile, 'gotowe zdjęcie', 'gotowe zdjęcia', 'gotowych zdjęć');

        if ($ile === 0) {
            $this->warn('Nie ma ani jednego gotowego zdjęcia w tym zakresie — nic do zrobienia.');
            $this->line('Sprawdź UUID albo nazwę dysku. Zdjęcia w innym stanie niż `ready` ta komenda pomija.');

            return $media === null ? self::SUCCESS : self::FAILURE;
        }

        if (! $this->option('wykonaj')) {
            $this->info("W zakresie jest {$ile} {$zdjec}. Nic nie zlecono.");
            $this->line('Żeby naprawdę zlecić zadania, uruchom to samo z --wykonaj.');

            return self::SUCCESS;
        }

        $zlecone = 0;

        $zakres->select('id')->chunkById(100, function ($partia) use (&$zlecone): void {
            foreach ($partia as $zdjecie) {
                dispatch(ProcessUploadedImage::odtworzWarianty((string) $zdjecie->getKey()));
                $zlecone++;
            }
        });

        $zadan = Odmiana::rzeczownik($zlecone, 'zadanie', 'zadania', 'zadań');
        $this->info("Zlecono {$zlecone} {$zadan} na kolejkę `media`.");
        $this->line('Wykonuje je worker. Zadania, które padły po wszystkich próbach, pokaże kuking:martwe-zadania.');
        $this->line('Po ostatnim zadaniu sprawdź jedno zdjęcie w przeglądarce i uruchom kuking:bramka-r2 --media=<uuid>.');

        return self::SUCCESS;
    }

    /** @return Builder<Media> */
    private function zakres(?string $media, ?string $dysk): Builder
    {
        $zapytanie = Media::query()->where('status', Media::STATUS_READY);

        if ($media !== null) {
            $zapytanie->whereKey($media);
        }

        if ($dysk !== null) {
            // Ta sama reguła co `Media::variantsDisk()`: puste `variants_disk`
            // znaczy „warianty leżą tam, gdzie oryginał".
            $zapytanie->where(fn (Builder $q) => $q->where('variants_disk', $dysk)
                ->orWhere(fn (Builder $q) => $q->whereNull('variants_disk')->where('disk', $dysk)));
        }

        return $zapytanie;
    }
}

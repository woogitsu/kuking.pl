<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\OcenaRetencjiLivewireR2;
use Aws\Exception\AwsException;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Throwable;

/**
 * Czy bucket ma regułę lifecycle dla `livewire-tmp/`? Tylko ODCZYT (#2051).
 *
 * Reguła żyje w panelu Cloudflare, poza repozytorium, i może zniknąć bez
 * jednej zmiany w kodzie. Ta komenda pyta bucket o `GetBucketLifecycleConfiguration`
 * (jedno żądanie, nic nie zapisuje ani nie kasuje) i mówi po polsku, czy
 * reguła jest, czy obejmuje dokładnie katalog Livewire i czy nie zahacza
 * o `incoming/`. Werdykt bierze się z odpowiedzi serwera, nie z konfiguracji.
 *
 * To NIE jest dowód, że pliki wygasają: to robi obiekt kontrolny na buckecie
 * nieprodukcyjnym (runbook §5.3). Bucket Lock też jest poza zasięgiem API.
 *
 * Nie jest w harmonogramie: sprawdzenie wymaga uprawnienia odczytu konfiguracji
 * bucketu, którego klucz aplikacji może nie mieć, a samo sprzątanie porzuconych
 * plików robi już `kuking:sprzataj-porzucone-uploady`. Uruchamia ją właściciel
 * (runbook §8).
 *
 * Na wyjściu nie ma nazwy bucketu, endpointu, kluczy obiektów ani identyfikatorów
 * reguł — wynik wkleja się do zgłoszeń.
 */
class SprawdzRetencjeLivewire extends Command
{
    protected $signature = 'kuking:sprawdz-retencje-livewire
                            {--dysk= : Nazwa dysku (domyślnie dysk uploadów Livewire)}';

    protected $description = 'Sprawdza (tylko odczyt), czy bucket R2 ma regułę lifecycle dla katalogu uploadów Livewire (#2051).';

    public function handle(): int
    {
        $katalog = trim(FileUploadConfiguration::directory(), '/');

        if ($katalog === '') {
            $this->error('Katalog uploadów Livewire jest pusty — reguła obejmowałaby cały bucket. Popraw `livewire.temporary_file_upload.directory`.');

            return self::FAILURE;
        }

        $prefiks = $katalog.'/';
        $nazwaDysku = (string) ($this->option('dysk') ?: config('livewire.temporary_file_upload.disk') ?: config('filesystems.default'));

        try {
            $dysk = Storage::disk($nazwaDysku);
        } catch (Throwable) {
            $this->error('Nie da się zbudować dysku uploadów Livewire — sprawdź konfigurację.');

            return self::FAILURE;
        }

        if (! $dysk instanceof AwsS3V3Adapter) {
            $this->error('Dysk uploadów Livewire nie jest zdalnym magazynem S3/R2 — nie ma reguły lifecycle do sprawdzenia.');
            $this->line('Uruchom tę komendę na środowisku, które używa R2 (`railway ssh -- php artisan kuking:sprawdz-retencje-livewire`).');

            return self::FAILURE;
        }

        $bucket = (string) ($dysk->getConfig()['bucket'] ?? '');

        try {
            $wynik = $dysk->getClient()->getBucketLifecycleConfiguration(['Bucket' => $bucket]);
            $reguly = array_values((array) ($wynik['Rules'] ?? []));
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'NoSuchLifecycleConfiguration') {
                // Sam kod błędu; komunikat SDK potrafi zawierać nazwę bucketu.
                $this->error('Nie udało się odczytać reguł bucketu (kod: '.($e->getAwsErrorCode() ?: 'brak').'). Klucz może nie mieć uprawnienia odczytu konfiguracji — wtedy sprawdź regułę w panelu (runbook §5.1).');

                return self::FAILURE;
            }

            $reguly = [];
        } catch (Throwable) {
            $this->error('Nie udało się połączyć z R2 — nie wiemy, jaka jest reguła. „Nie wiemy” nie znaczy „jest dobrze”.');

            return self::FAILURE;
        }

        $ocena = OcenaRetencjiLivewireR2::ocen($reguly, $prefiks);

        $this->line('Prefiks Livewire: '.$prefiks.' · reguł w buckecie: '.count($reguly));

        foreach ($ocena['alarm'] as $linia) {
            $this->error('ALARM: '.$linia);
        }

        if ($ocena['dobra']) {
            $this->info("Reguła dla `{$prefiks}` jest, włączona, wygasza po najwyżej ".OcenaRetencjiLivewireR2::MAKS_DNI.' dniu.');
            $this->line('To dowodzi konfiguracji, nie wygasania: obiekt kontrolny (runbook §5.3) i Bucket Lock (§4 krok 1) zostają do sprawdzenia.');

            return self::SUCCESS;
        }

        foreach ($ocena['uwagi'] as $linia) {
            $this->warn($linia);
        }

        $this->error('RETENCJA NIEPOTWIERDZONA. Załóż regułę według docs/infra/LIVEWIRE_TMP_R2_RETENCJA_2051.md §4.');

        return self::FAILURE;
    }
}

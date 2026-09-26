<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puls harmonogramu do ZEWNĘTRZNEGO monitora typu „dead man's switch" (issue #599).
 *
 * PO CO. Wszystkie czujki (`kuking:sprawdz-kolejke`, `kuking:budzet-polaczen`,
 * `kuking:sprawdz-kopie`) uruchamia harmonogram. Gdy stanie SAM harmonogram,
 * stają wszystkie naraz — i żadna nie powie o tym ani słowa, bo milczenie
 * czujki wygląda dokładnie jak spokój (MONITORING_ODBIOR_2026_09_20.md §8,
 * wiersz „Brak schedulera"). `/health` tego nie widzi: odpowiada proces web.
 *
 * Dlatego odwrotny kierunek: to NIE monitor pyta nas, tylko my co kilka minut
 * dajemy znak życia. Monitor, który przez ustalone okno znaku nie dostał,
 * alarmuje sam — z własnej infrastruktury, niezależnej od Railway.
 *
 * WYŁĄCZONE DOMYŚLNIE. Bez `KUKING_PULS_HARMONOGRAMU_URL` komenda nic nie
 * wysyła i kończy się sukcesem — ta sama umowa „brak zmiennej = zero efektu"
 * co przy kopiach i webhooku. Włącza właściciel, zakładając monitor
 * (docs/infra/MONITORING_599_KROKI.md).
 *
 * ADRES JEST SEKRETEM. Adres pulsu zawiera zwykle token: kto go zna, może
 * „karmić" monitor i zagłuszyć prawdziwą awarię. Dlatego nie trafia ani do
 * dziennika, ani do wyjścia konsoli — tylko kod odpowiedzi.
 */
class PulsHarmonogramu extends Command
{
    protected $signature = 'kuking:puls-harmonogramu';

    protected $description = 'Wysyła znak życia harmonogramu do zewnętrznego monitora, jeśli jest skonfigurowany (issue #599).';

    public function handle(): int
    {
        $adres = trim((string) config('kuking.monitoring.puls_harmonogramu_url'));

        if ($adres === '') {
            $this->line('Puls harmonogramu wyłączony (brak KUKING_PULS_HARMONOGRAMU_URL).');

            return self::SUCCESS;
        }

        // Tylko https: puls idzie przez Internet, a adres jest sekretem.
        if (parse_url($adres, PHP_URL_SCHEME) !== 'https' || parse_url($adres, PHP_URL_HOST) === null) {
            Log::warning('Puls harmonogramu: adres monitora nie jest poprawnym adresem https — nic nie wysłano.');
            $this->error('Adres pulsu nie jest poprawnym adresem https.');

            return self::FAILURE;
        }

        try {
            $odpowiedz = Http::connectTimeout(3)->timeout(5)->get($adres);
        } catch (Throwable) {
            // Bez treści wyjątku — komunikat klienta HTTP zawiera adres.
            Log::warning('Puls harmonogramu nie doszedł do monitora: brak połączenia.');
            $this->error('Puls nie doszedł: brak połączenia z monitorem.');

            return self::FAILURE;
        }

        if (! $odpowiedz->successful()) {
            Log::warning('Puls harmonogramu nie doszedł do monitora.', ['status' => $odpowiedz->status()]);
            $this->error('Puls nie doszedł: monitor odpowiedział kodem '.$odpowiedz->status().'.');

            return self::FAILURE;
        }

        $this->info('Puls wysłany.');

        return self::SUCCESS;
    }
}

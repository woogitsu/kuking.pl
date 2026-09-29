<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Support\Storage\DozwolonyHostR2;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;

/**
 * Sonda `magazyn` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaHostaMagazynu implements Sonda
{
    public function nazwa(): string
    {
        return 'magazyn';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_MAGAZYN_ZLY_HOST;
    }

    /**
     * Czy każdy dysk R2/S3 z konfiguracji ma adres, pod który wolno wysłać
     * klucz (D-255). Ta sama kontrola, którą `DyskR2` robi przy budowie —
     * tu bez budowania, więc sonda niczego nie wysyła.
     */
    public function sprawdz(): void
    {
        $zle = [];

        foreach ((array) config('filesystems.disks') as $nazwa => $dysk) {
            if (! is_array($dysk) || ! in_array($dysk['driver'] ?? null, ['r2', 's3'], true)) {
                continue;
            }

            $adres = (string) ($dysk['endpoint'] ?? '');

            // Dysk bez adresu i bez klucza nie ma czego wysłać — to produkcja
            // na dysku lokalnym, bez R2. Pusty adres Z kluczem to już awaria
            // (AWS SDK poszedłby do Amazona) i tę łapie kontrola niżej.
            if ($adres === '' && blank($dysk['key'] ?? null)) {
                continue;
            }

            $powod = DozwolonyHostR2::powod($adres);

            if ($powod !== null) {
                $zle[] = "`{$nazwa}` (".DozwolonyHostR2::opisHosta($adres).", {$powod})";
            }
        }

        if ($zle === []) {
            return;
        }

        // Host (z identyfikatorem konta) idzie wyłącznie do logu; publicznie
        // i na webhook — sam kod.
        throw new KontrolaZdrowiaNieprzeszla(
            Powody::POWOD_MAGAZYN_ZLY_HOST,
            'AWS_ENDPOINT nie ma postaci https://<identyfikator konta>.eu.r2.cloudflarestorage.com '
            .'dla dysków: '.implode(', ', $zle).'. Te dyski się nie zbudują (D-255).',
        );
    }
}

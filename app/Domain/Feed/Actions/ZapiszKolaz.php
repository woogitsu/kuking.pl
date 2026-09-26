<?php

declare(strict_types=1);

namespace App\Domain\Feed\Actions;

use App\Domain\Feed\ZamekWyboruRedakcji;
use App\Models\AuditLogEntry;
use App\Models\HeroPick;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Zastąpienie i wyczyszczenie wyboru zdjęć do kolażu strony powitalnej.
 *
 * Te same dwie gwarancje co w `ZapiszTabliceDnia`: jeden pełny wybór naraz
 * pod blokadą doradczą kolażu (#1027) oraz wybór i wpis audytu w jednej
 * transakcji — awaria dziennika cofa zmianę kolażu (#1329, D-249 klasa 1).
 */
final class ZapiszKolaz
{
    /**
     * @param  list<string>  $zadane  identyfikatory zdjęć, bez powtórzeń, w kolejności z formularza
     * @param  array<string, string>  $dopuszczone  zdjęcie → wpis, wynik `HeroKolaz::dopuszczZdjecia()`
     * @return int ile zdjęć stoi w kolażu po zapisie
     */
    public function zastap(?User $gospodarz, array $zadane, array $dopuszczone, ?string $ip): int
    {
        return DB::transaction(function () use ($gospodarz, $zadane, $dopuszczone, $ip): int {
            ZamekWyboruRedakcji::kolazu();

            HeroPick::query()->delete();

            $pozycja = 0;

            // Kolejność Z FORMULARZA, nie z wyniku bramki: gospodarz widzi
            // listę w jednym porządku i pozycje w kolażu mają za nim iść.
            foreach ($zadane as $mediaId) {
                if (! isset($dopuszczone[$mediaId])) {
                    continue;
                }

                // Po blokadzie kolażu zderzenie z UNIQUE (`hero_picks.media_id`)
                // jest już tylko siatką bezpieczeństwa. SAVEPOINT, bo
                // w PostgreSQL zderzenie unieważnia całą otaczającą transakcję.
                try {
                    DB::transaction(function () use ($mediaId, $dopuszczone, $pozycja, $gospodarz): void {
                        HeroPick::create([
                            'media_id' => $mediaId,
                            'post_id' => $dopuszczone[$mediaId],
                            'position' => $pozycja,
                            'curator_id' => $gospodarz?->getKey(),
                        ]);
                    });
                } catch (UniqueConstraintViolationException) {
                    // To zdjęcie już stoi w kolażu — kończymy cicho.
                }

                $pozycja++;
            }

            AuditLogEntry::record(
                action: 'hero_kolaz.updated',
                actor: $gospodarz,
                metadata: [
                    'zapisanych' => count($dopuszczone),
                    'odrzuconych' => count($zadane) - count($dopuszczone),
                ],
                ip: $ip,
            );

            return count($dopuszczone);
        });
    }

    /** Czyści wybór — kolaż wraca do doboru automatycznego. */
    public function wyczysc(?User $gospodarz, ?string $ip): void
    {
        DB::transaction(function () use ($gospodarz, $ip): void {
            ZamekWyboruRedakcji::kolazu();

            HeroPick::query()->delete();

            AuditLogEntry::record(
                action: 'hero_kolaz.cleared',
                actor: $gospodarz,
                metadata: [],
                ip: $ip,
            );
        });
    }
}

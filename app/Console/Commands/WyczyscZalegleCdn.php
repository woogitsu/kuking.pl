<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Media\ZalegleCzyszczeniaCdn;
use App\Jobs\PurgePublicMediaCache;
use App\Support\Odmiana;
use Illuminate\Console\Command;

class WyczyscZalegleCdn extends Command
{
    protected $signature = 'kuking:wyczysc-zalegle-cdn
                            {--partie=10 : Najwięcej ile partii po '.ZalegleCzyszczeniaCdn::PARTIA.' adresów w jednym przebiegu}';

    protected $description = 'Czyści z cache CDN adresy skasowanych zdjęć, które czekają na konfigurację Cloudflare albo ponowienie (#959).';

    public function handle(): int
    {
        if (! PurgePublicMediaCache::skonfigurowane()) {
            $ile = ZalegleCzyszczeniaCdn::ile();

            // Sukces, nie porażka: brak konfiguracji świeci już w `/health`
            // (`cdn` i `cdn_zalegle`), a alarm z harmonogramu co kwadrans
            // niczego by nie dodał. Wiersze czekają.
            $this->warn('Brak CLOUDFLARE_ZONE_ID albo CLOUDFLARE_PURGE_TOKEN — nic nie wysłano. Czeka: '
                .$ile.' '.Odmiana::rzeczownik($ile, 'adres', 'adresy', 'adresów').'.');

            return self::SUCCESS;
        }

        $razem = 0;

        for ($i = 0; $i < max(1, (int) $this->option('partie')); $i++) {
            $wyczyszczone = ZalegleCzyszczeniaCdn::wyczysc();
            $razem += $wyczyszczone;

            if ($wyczyszczone < ZalegleCzyszczeniaCdn::PARTIA) {
                break;
            }
        }

        $this->info('Wyczyszczono z cache CDN '.$razem.' '
            .Odmiana::rzeczownik($razem, 'adres', 'adresy', 'adresów').'.');

        return self::SUCCESS;
    }
}

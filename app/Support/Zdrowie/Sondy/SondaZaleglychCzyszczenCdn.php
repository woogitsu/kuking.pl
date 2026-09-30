<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Domain\Media\ZalegleCzyszczeniaCdn;
use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Support\Odmiana;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;

/**
 * Sonda `cdn_zalegle` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaZaleglychCzyszczenCdn implements Sonda
{
    public function nazwa(): string
    {
        return 'cdn_zalegle';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_CZYSZCZENIE_CDN_ZALEGLE;
    }

    /**
     * Czy są adresy, których cache CDN jeszcze nie wyczyszczono (#959).
     *
     * WSZĘDZIE, NIE TYLKO NA PRODUKCJI. W przeciwieństwie do pustej
     * konfiguracji niepusta tabela nie jest poprawnym stanem nigdzie: poza
     * produkcją trafia tam tylko zadanie, które wyczerpało próby.
     *
     * Do publicznej odpowiedzi idzie sam kod, bez liczby — liczba i nazwa
     * komendy zostają w logu i na webhooku.
     */
    public function sprawdz(): void
    {
        $ile = ZalegleCzyszczeniaCdn::ile();

        if ($ile === 0) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(
            Powody::POWOD_CZYSZCZENIE_CDN_ZALEGLE,
            "W `zalegle_czyszczenia_cdn` czeka na wyczyszczenie z cache CDN {$ile} "
            .Odmiana::rzeczownik($ile, 'adres', 'adresy', 'adresów').' skasowanych zdjęć — '
            .'mogą się nadal otwierać. Gdy CLOUDFLARE_ZONE_ID i CLOUDFLARE_PURGE_TOKEN są ustawione, '
            .'`kuking:wyczysc-zalegle-cdn` wysyła je co kwadrans; jeśli liczba nie maleje, Cloudflare '
            .'odmawia — szukaj w logu „Nie udało się wyczyścić cache CDN".',
        );
    }
}

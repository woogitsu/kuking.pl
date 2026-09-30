<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Support\Facebook;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;

/**
 * Sonda `facebook` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaFacebooka implements Sonda
{
    public function nazwa(): string
    {
        return 'facebook';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_FACEBOOK_BEZ_KLUCZY;
    }

    /**
     * Wejście kontem Facebooka — ten sam wywód co przy Google wyżej
     * (issue #259), z jedną różnicą, przez którą ten sygnał jest tu bardziej
     * potrzebny niż tam.
     *
     * RÓŻNICA: DROGA DO KLUCZY JEST DŁUŻSZA, WIĘC ŁATWIEJ JĄ ZOSTAWIĆ
     * NIEDOKOŃCZONĄ. Google to pięć minut w jednym panelu. U Meta właściciel
     * przechodzi kilkanaście czynności w trzech miejscach panelu
     * (`docs/infra/FACEBOOK_LOGIN_URUCHOMIENIE.md` §12), a ostatnia z nich —
     * przestawienie aplikacji w tryb **Live** — ma się wydarzyć DOPIERO po
     * wdrożeniu kodu. Między jednym a drugim jest okno, w którym wdrożenie
     * wygląda na zdrowe, a droga wejścia nie istnieje. To okno jest dokładnie
     * tym, co ten sygnał ma oświetlić.
     *
     * Zdanie dla właściciela (z nazwami zmiennych i odnośnikiem do runbooka
     * Meta) idzie WYŁĄCZNIE do logu — patrz `check()`. Na zewnątrz wychodzi
     * sam kod `facebook_bez_kluczy`, bo ta odpowiedź jest publiczna.
     */
    public function sprawdz(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (! (bool) config('kuking.facebook.wlaczone', true)) {
            return;
        }

        if (Facebook::skonfigurowany()) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(
            Powody::POWOD_FACEBOOK_BEZ_KLUCZY,
            Facebook::komunikatBrakuKluczy(),
        );
    }
}

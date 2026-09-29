<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Support\Turnstile;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;

/**
 * Sonda `turnstile` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaTurnstile implements Sonda
{
    public function nazwa(): string
    {
        return 'turnstile';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_TURNSTILE_BEZ_KLUCZY;
    }

    /**
     * Turnstile: czy to, co obiecuje konfiguracja, ma czym działać (D-050).
     *
     * PO CO TO TU JEST, SKORO BRAK KLUCZY NICZEGO NIE PSUJE
     * Właśnie dlatego. Bez kluczy widget się nie renderuje, walidacja nikogo
     * nie zatrzymuje i wszystko wygląda dobrze — a `/register`,
     * `/nie-pamietam-hasla`, `/napisz-do-nas` i `/zglos-nielegalna-tresc`
     * stoją bez ochrony, którą konfiguracja właśnie zapowiedziała. To jest ta
     * sama klasa awarii co `MAIL_MAILER=log`, martwy `kuking.media_disk`
     * i limit `upload` niepodpięty do żadnej trasy: narzędzie melduje sukces,
     * nie robiąc nic. Jedyna obrona to twardy, zewnętrznie widoczny sygnał.
     *
     * DLACZEGO `degraded`, A NIE 503
     * Bo `turnstile` NIE JEST na liście `KRYTYCZNE`, i to jest decyzja, nie
     * przeoczenie. Healthcheck oddający 503 już raz położył ten serwis
     * (patrz komentarz na górze klasy). Serwis działający bez captchy jest
     * o wiele lepszy niż serwis w pętli restartów — a monitoring i tak ma
     * pilnować TREŚCI odpowiedzi. Przy okazji `check()` woła `Log::error`
     * (log serwera, zawsze) i — jeśli `LOG_BLAD_WEBHOOK_URL` jest ustawiony —
     * `powiadomWebhook()` (Discord/Slack, z odstępem `WEBHOOK_ODSTEP_MINUT`,
     * żeby trwająca awaria nie zalała kanału). Sentry nie ma dziś w kodzie
     * wcale (D-041) — to zdanie było nieprawdziwe do 10 września 2026, kiedy
     * ten komentarz to zauważył i poprawił.
     *
     * DLACZEGO TYLKO NA PRODUKCJI I TYLKO GDY KTOŚ O TURNSTILE POPROSIŁ
     * Lokalnie, w CI i w testach kluczy nie ma i mieć nie musi — stały
     * `degraded` w tych środowiskach byłby szumem, który uczy ignorować to
     * pole. A jeśli właściciel świadomie wyłączy WSZYSTKIE miejsca
     * w `config/kuking.php`, to konfiguracja nie kłamie i nie ma o czym
     * krzyczeć.
     */
    public function sprawdz(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (! Turnstile::ktoresMiejsceWlaczone()) {
            return;
        }

        if (Turnstile::skonfigurowany()) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(
            Powody::POWOD_TURNSTILE_BEZ_KLUCZY,
            Turnstile::komunikatBrakuKluczy(),
        );
    }
}

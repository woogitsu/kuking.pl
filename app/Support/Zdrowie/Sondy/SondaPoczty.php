<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Support\Poczta;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;

/**
 * Sonda `poczta` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaPoczty implements Sonda
{
    public function nazwa(): string
    {
        return 'poczta';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_POCZTA_NIE_WYSYLA;
    }

    /**
     * Czy wysyłka poczty ma w ogóle czym ruszyć — `App\Support\Poczta` jest
     * TU JEDYNYM źródłem prawdy (ta sama klasa decyduje na ekranie „Nie
     * pamiętam hasła" i w `kuking:sprawdz-poczte`), żeby te trzy miejsca nie
     * mogły się rozjechać.
     *
     * DLACZEGO TYLKO NA PRODUKCJI
     * `MAIL_MAILER=array` jest domyślnym ustawieniem całej suity testów
     * (`phpunit.xml`), a `log` jest domyślną wartością w `.env.example` do
     * pierwszego zielonego deployu (`docs/infra/DEPLOYMENT_RUNBOOK.md`,
     * KROK 8). Sprawdzanie tego poza produkcją dawałoby stały `degraded`
     * wszędzie poza nią — szum, który uczy ignorować to pole, dokładnie ta
     * sama lekcja co przy Turnstile wyżej.
     *
     * DLACZEGO `Poczta::przeszkoda()`, A NIE PUBLICZNY KOD Z JEJ TREŚCI
     * `przeszkoda()` mówi wprost w swoim komentarzu: „NIE POKAZUJ TEGO
     * UŻYTKOWNIKOWI i nie wysyłaj na webhook" — bo ostatni fragment zdania
     * bywa komunikatem wyjątku CUDZEJ biblioteki transportu i nie jest niczym
     * ograniczony (ta sama klasa ryzyka co `$e->getMessage()` w
     * `WebhookBleduHandler`, audyt A6-01). Dlatego trafia wyłącznie do `$doLogu`
     * `KontrolaZdrowiaNieprzeszla` — do serwerowego logu, którego `/health`
     * nigdy nie pokazuje światu (patrz `check()`); na zewnątrz i na webhook
     * idzie tylko zamknięty kod `POWOD_POCZTA_NIE_WYSYLA`.
     */
    public function sprawdz(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        $przeszkoda = Poczta::przeszkoda();

        if ($przeszkoda === null) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(Powody::POWOD_POCZTA_NIE_WYSYLA, $przeszkoda);
    }
}

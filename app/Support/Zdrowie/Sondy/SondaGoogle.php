<?php

declare(strict_types=1);

namespace App\Support\Zdrowie\Sondy;

use App\Exceptions\KontrolaZdrowiaNieprzeszla;
use App\Support\Google;
use App\Support\Zdrowie\Powody;
use App\Support\Zdrowie\Sonda;

/**
 * Sonda `google` endpointu `/health` (issue #2212) — logika wyjęta z
 * `HealthController` bez zmiany zachowania; kontroler składa z sond odpowiedź.
 */
final class SondaGoogle implements Sonda
{
    public function nazwa(): string
    {
        return 'google';
    }

    public function powodDomyslny(): string
    {
        return Powody::POWOD_GOOGLE_BEZ_KLUCZY;
    }

    /**
     * Wejście kontem Google: czy droga, którą konfiguracja właśnie obiecała,
     * ma czym działać (D-069, issue #258).
     *
     * PO CO TO TU JEST, SKORO BRAK KLUCZY NICZEGO NIE PSUJE
     * Dokładnie ten sam wywód co przy Turnstile wyżej i to samo zdanie
     * z runbooka: **cicha, nieistniejąca droga wejścia jest gorsza niż
     * wyłączona**. Bez kluczy przycisku „Wejdź kontem Google" po prostu nie
     * ma na ekranie — a to wygląda identycznie jak poprawne wdrożenie, na
     * którym właściciel świadomie tej drogi nie chciał. Jedyną różnicą jest
     * to, czy konfiguracja nadal ją obiecuje. Dlatego pytamy o obietnicę,
     * nie o obecność kluczy samą w sobie.
     *
     * SKĄD SIĘ WZIĄŁ TEN SYGNAŁ
     * Był zaplanowany w D-069 i świadomie odłożony („`HealthController`
     * przerabia równolegle inne zlecenie"), a potem stał w issue #258 i #259
     * jako jedyna luka w kodzie obu tych funkcji. Do chwili jego dołożenia
     * jedynym sprawdzeniem było „wejdź na /login i zobacz, czy jest
     * przycisk" — czyli czynność, której nikt nie robi co pięć minut.
     *
     * DLACZEGO `degraded`, A NIE 503
     * Bo `google` NIE JEST na liście `KRYTYCZNE`. Serwis bez jednej
     * z trzech dróg wejścia działa (hasło i link e-mail stoją tam, gdzie
     * stały); serwis w pętli restartów nie działa wcale.
     *
     * DLACZEGO TYLKO NA PRODUKCJI I TYLKO GDY FUNKCJA JEST WŁĄCZONA
     * Lokalnie, w CI i w testach kluczy nie ma i mieć nie musi — to jest
     * stan domyślny opisany w `.env.example`. A `KUKING_WEJSCIE_GOOGLE=false`
     * znaczy „nie chcę tej drogi": konfiguracja wtedy nie kłamie i nie ma
     * o czym krzyczeć. Ta sama lekcja co przy Turnstile: stały `degraded`
     * uczy ignorować to pole.
     */
    public function sprawdz(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (! (bool) config('kuking.google.wlaczone', true)) {
            return;
        }

        if (Google::skonfigurowany()) {
            return;
        }

        throw new KontrolaZdrowiaNieprzeszla(
            Powody::POWOD_GOOGLE_BEZ_KLUCZY,
            Google::komunikatBrakuKluczy(),
        );
    }
}

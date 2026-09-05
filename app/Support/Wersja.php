<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Wersja aplikacji pokazywana w stopce.
 *
 * SKŁADA SIĘ Z DWÓCH CZĘŚCI, BO KAŻDA ODPOWIADA NA INNE PYTANIE
 *
 *   „Alfa 0.1"  — na jakim etapie jest produkt. Zmienia się rzadko, ręcznie,
 *                 razem z kamieniami milowymi z docs/ROADMAP.md.
 *
 *   „a1b2c3d"   — CO DOKŁADNIE jest wdrożone. Zmienia się przy każdym
 *                 wdrożeniu, samo, bez niczyjej pamięci.
 *
 * Sama etykieta nie wystarcza: stałaby tygodniami bez zmian i nie
 * odpowiadałaby na pytanie „czy moja poprawka już weszła". Sam skrót commita
 * też nie wystarcza: nic nie mówi człowiekowi, który nie zna gita.
 *
 * Skrót bierzemy z RAILWAY_GIT_COMMIT_SHA — Railway wstrzykuje ją do każdego
 * wdrożenia. Ta sama wartość idzie do SENTRY_RELEASE (`.railway/railway.ts`),
 * więc wersja w stopce i wersja przy błędzie w Sentry to dokładnie ten sam
 * commit. To jest cała wartość tego rozwiązania: widząc błąd, wiesz, którego
 * kodu dotyczy.
 */
final class Wersja
{
    /** Ile znaków skrótu pokazujemy. Siedem to konwencja gita — wystarcza. */
    private const DLUGOSC_SKROTU = 7;

    /**
     * Pełny opis do stopki, np. „Alfa 0.1 · a1b2c3d".
     *
     * Lokalnie i w testach nie ma zmiennej od Railway, więc zamiast pustego
     * miejsca albo myślnika piszemy wprost, że to nie jest wdrożona wersja.
     */
    public static function pelna(): string
    {
        return self::etykieta().' · '.self::wydanie();
    }

    /** Etap produktu — podbijany ręcznie, razem z ROADMAP.md. */
    public static function etykieta(): string
    {
        return (string) config('kuking.wersja.etykieta');
    }

    /** Skrót wdrożonego commita albo „lokalnie", gdy nic nie wdrożono. */
    public static function wydanie(): string
    {
        $commit = config('kuking.wersja.commit');

        if (! is_string($commit) || $commit === '') {
            return 'lokalnie';
        }

        return substr($commit, 0, self::DLUGOSC_SKROTU);
    }
}

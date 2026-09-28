<?php

declare(strict_types=1);

namespace App\Domain\Zgody;

/**
 * Wersja informacji, którą człowiek widzi przed zgodą „odczyt AI” (D-296, issue #2033).
 *
 * JEDNA TREŚĆ, JEDNO MIEJSCE. Tekst informacji — kto odczyta (OpenAI, USA),
 * co wysyłamy, gdzie trafia odczytany tekst, co zrobić z cudzymi danymi na
 * kartce, że bez zgody nic nie wychodzi i jak ją wycofać — stoi wyłącznie
 * w komponencie `resources/views/components/zgoda-odczyt-ai.blade.php`.
 * Ekran „Przepisz z kartki” i ustawienia prywatności renderują ten sam
 * komponent, więc ta sama zgoda w dzienniku ma za sobą tę samą informację
 * niezależnie od miejsca, z którego jej udzielono.
 *
 * WERSJA W FORMULARZU. Komponent wysyła `WERSJA` w ukrytym polu. Strona
 * otwarta przed zmianą treści niesie starą wersję — kontroler wtedy NIE
 * zapisuje zgody i pokazuje aktualną informację. Zmieniasz fakty w komponencie
 * (odbiorca, miejsce, zakres, skutek) — podbij `WERSJA` (data zmiany).
 * Poprawka literówki wersji nie wymaga.
 *
 * Fakty muszą zgadzać się z D-296 i z projektem polityki
 * `docs/legal/projekty/POLITYKA_ODCZYT_AI.md`.
 */
final class InformacjaOdczytuAi
{
    public const WERSJA = '2026-09-28';

    /** Nazwa pola formularza z wersją informacji. */
    public const POLE = 'informacja';

    /** Osobny worek błędów — błąd zgody nie może podświetlić formularza digestu na tej samej stronie. */
    public const WOREK_BLEDOW = 'zgodaOdczytuAi';

    /**
     * Czy formularz niesie wersję informacji, która jest dziś aktualna.
     *
     * Brak pola (`null`) na razie przepuszczamy: test z aktywnej gałęzi importu
     * (`tests/Feature/Import/OdczytZdjeciaKartkiTest.php`) wysyła zgodę bez
     * niego. Po scaleniu #1899 ten test dostaje pole, a tutaj `null` przestaje
     * być aktualną wersją (issue #2033).
     */
    public static function aktualna(mixed $wersja): bool
    {
        return $wersja === null || $wersja === self::WERSJA;
    }
}

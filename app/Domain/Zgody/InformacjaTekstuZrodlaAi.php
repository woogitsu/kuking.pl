<?php

declare(strict_types=1);

namespace App\Domain\Zgody;

/**
 * Wersja informacji przed zgodą na wysłanie TEKSTU STRONY albo STRON SKANU PDF
 * do modelu (D-300 pkt 9, issue #2031).
 *
 * TO INNA ZGODA NIŻ „ODCZYT AI” (`InformacjaOdczytuAi`). Tamta obejmuje
 * wyłącznie zdjęcie kartki, jest trwała (dziennik zgód) i można ją wycofać.
 * Ta dotyczy JEDNEGO wysłania: zaznaczasz pole w formularzu adresu albo PDF,
 * a zgoda znika po tym żądaniu — nie ma stanu, który dałoby się „odziedziczyć”
 * przy kolejnym imporcie. Zgoda na zdjęcia niczego tu nie odblokowuje i odwrotnie.
 *
 * JEDNA TREŚĆ, JEDNO MIEJSCE. Informacja stoi w komponencie
 * `resources/views/components/zgoda-zrodlo-ai.blade.php`; formularze adresu
 * i PDF renderują ten sam komponent. Komponent wysyła `WERSJA` w ukrytym
 * polu obok zaznaczonego pola zgody.
 *
 * WERSJA W FORMULARZU. Zgoda liczy się tylko z aktualną wersją: strona
 * otwarta przed zmianą treści (albo sprzed tego zadania, bez pola) niesie
 * starą wersję — kontroler wtedy NIE wysyła niczego do modelu i prosi
 * o przeczytanie informacji od nowa. Zmieniasz fakty w komponencie (odbiorca,
 * miejsce, zakres, retencja, skutek) — podbij `WERSJA` (data zmiany).
 * Poprawka literówki wersji nie wymaga.
 *
 * Fakty muszą zgadzać się z D-300 i z projektem polityki
 * `docs/legal/projekty/POLITYKA_ODCZYT_AI.md` (część „Tekst strony i skan PDF”)
 * oraz z rejestrem `docs/legal/REJESTR_CZYNNOSCI_PRZETWARZANIA.md` §3.23.
 */
final class InformacjaTekstuZrodlaAi
{
    public const WERSJA = '2026-09-29';

    /** Nazwa ukrytego pola formularza z wersją informacji. */
    public const POLE = 'zgoda_ai_informacja';

    /**
     * Czy formularz niesie wersję informacji, która jest dziś aktualna.
     * Brak pola (`null`) NIE jest aktualną wersją.
     */
    public static function aktualna(mixed $wersja): bool
    {
        return $wersja === self::WERSJA;
    }
}

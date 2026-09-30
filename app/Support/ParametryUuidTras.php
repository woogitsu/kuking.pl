<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PARAMETR TRASY, KTÓRY JEST UUID-EM, PRZYJMUJE TYLKO UUID (#2327).
 *
 * Nazwy niżej dostają wzorzec UUID w CAŁYM routerze (`Route::patterns()`
 * na górze `routes/web.php`) — ten sam, który trasy API mają przez
 * `whereUuid()`. Adres z czymś innym (`/wpisy/abc`) nie pasuje do trasy
 * i dostaje zwykłe 404, zanim cokolwiek dotknie bazy. Wiązanie modelu
 * (`HasUuids`) też odpowiada wtedy 404, ale router nie powinien w ogóle
 * zaczynać od zapytania — a kontroler, który bierze identyfikator jako
 * napis i woła `findOrFail()`, bez tej bramki dawał Postgresowi `abc`
 * jako wartość kolumny `uuid` i 500 (`SQLSTATE[22P02]`).
 *
 * Jedna lista zamiast `->whereUuid()` przy każdej z ~60 tras: nowa trasa
 * z parametrem o tej nazwie jest chroniona od razu. Strażnik
 * `KazdaTrasaZIdentyfikatoremPodPolicyTest` pilnuje obu stron: każdy parametr
 * trasy wiązany z modelem po kluczu UUID ma ten wzorzec, a żadna nazwa
 * z listy nie wiąże modelu po czymś innym niż UUID (np. po nazwie konta).
 */
final class ParametryUuidTras
{
    /** Dokładnie ten sam wzorzec co `Route::whereUuid()` Laravela. */
    public const WZORZEC = '[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}';

    /** Nazwy parametrów tras, które zawsze wskazują wiersz po UUID. */
    public const NAZWY = [
        'action',
        'appeal',
        'collection',
        'comment',
        'cookedEvent',
        'export',
        'hide',
        'import',
        'invitation',
        'media',
        'notification',
        'osoba',
        'pantryItem',
        'post',
        'report',
        'urzadzenie',
        'user',
        'wiadomosc',
        'wpis',
        'wybor',
        'wyroznienie',
    ];

    /** @return array<string, string> nazwa parametru => wzorzec */
    public static function wzorce(): array
    {
        return array_fill_keys(self::NAZWY, self::WZORZEC);
    }
}

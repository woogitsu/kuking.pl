<?php

declare(strict_types=1);

namespace App\Support\Sesja;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Generacja sesji konta (#1046) — jedyne miejsce, które zna klucz w sesji.
 *
 * `users.session_generation` rośnie przy każdym `User::invalidateSessions()`
 * (w TYM SAMYM `UPDATE` co rotacja `remember_token`). Sesja zapamiętuje
 * generację z chwili logowania, a `SprawdzGeneracjeSesji` odrzuca sesję
 * z generacją inną niż bieżąca konta.
 *
 * Po co, skoro `invalidateSessions()` kasuje wiersze w `sessions`: bo
 * żądanie rozpoczęte przed skasowaniem potrafi zapisać wiersz z powrotem
 * (`StartSession` zapisuje na końcu, a nowy identyfikator idzie
 * `INSERT`-em). Znacznik jest liczony POZA kasowanym wierszem, więc wiersz
 * odtworzony ze starego stanu niesie starą generację i dostępu nie odzyskuje.
 *
 * BRAK KLUCZA = 0. Sesje sprzed wdrożenia go nie mają, a konta startują od 0,
 * więc do pierwszego unieważnienia działają dalej; po nim — już nie.
 */
final class GeneracjaSesji
{
    public const KLUCZ = 'kuking_generacja_sesji';

    public static function zapamietaj(Session $sesja, User $user): void
    {
        // Wartość z modelu, który przeszedł uwierzytelnienie — a nie świeży
        // odczyt z bazy. Model wczytany przed unieważnieniem niesie starą
        // generację, więc logowanie ścigające się z „wyloguj wszędzie” dostaje
        // znacznik, który następne żądanie odrzuci. Świeży odczyt przyjąłby
        // już nową generację i wskrzesiłby właśnie tę sesję.
        //
        // Model bez tej kolumny w atrybutach (świeże `create()`, zawężony
        // `select`) czyta ją z bazy — inaczej `null` dawałby 0 i wylogowanie
        // zaraz po poprawnym logowaniu.
        $generacja = array_key_exists('session_generation', $user->getAttributes())
            ? $user->session_generation
            : User::query()->whereKey($user->getKey())->value('session_generation');

        $sesja->put(self::KLUCZ, (int) $generacja);
    }

    public static function zgodna(Session $sesja, User $user): bool
    {
        return (int) $sesja->get(self::KLUCZ, 0) === (int) $user->session_generation;
    }
}

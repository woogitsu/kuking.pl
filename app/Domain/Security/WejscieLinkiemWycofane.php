<?php

declare(strict_types=1);

namespace App\Domain\Security;

use RuntimeException;
use Throwable;

/**
 * Wejście linkiem z e-maila wycofane, bo nie zapisał się wpis dziennika
 * audytu (D-249, klasa 1; #1530).
 *
 * Transakcja zużycia tokenu jest cofnięta w całości: link nadal działa,
 * konto jest nietknięte. Poprzednik niesie prawdziwą przyczynę dla operatora.
 */
final class WejscieLinkiemWycofane extends RuntimeException
{
    public function __construct(public readonly string $kontoId, ?Throwable $previous = null)
    {
        parent::__construct(self::opis($kontoId), 0, $previous);
    }

    /**
     * Treść dla operatora: nazwa brakującego wpisu i identyfikator konta,
     * bez tokenu i adresu. Kontroler buduje z niej raport sam, zamiast
     * przepisywać `getMessage()` (LogOperacyjnyBezKomunikatuWyjatkuTest).
     */
    public static function opis(string $kontoId): string
    {
        return 'Nie zapisał się wpis dziennika audytu „account.login_link_used" dla User '
            .$kontoId.' — wejście linkiem wycofane, link nadal ważny.';
    }
}

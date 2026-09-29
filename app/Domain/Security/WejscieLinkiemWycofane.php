<?php

declare(strict_types=1);

namespace App\Domain\Security;

use RuntimeException;

/**
 * Wejście linkiem z e-maila wycofane, bo nie zapisał się wpis dziennika
 * audytu (D-249, klasa 1; #1530).
 *
 * Transakcja zużycia tokenu jest cofnięta w całości: link nadal działa,
 * konto jest nietknięte. Poprzednik niesie prawdziwą przyczynę dla operatora.
 */
final class WejscieLinkiemWycofane extends RuntimeException {}

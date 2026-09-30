<?php

declare(strict_types=1);

namespace App\Console\Commands;

use RuntimeException;

/**
 * Wyjątek `kuking:proba-odtworzenia-zdjec` z własnym zdaniem po polsku, bez
 * kluczy obiektów i bez treści storage (#617). Osobny plik, bo autoloader
 * PSR-4 szuka klasy pod jej nazwą — w pliku komendy byłaby widoczna tylko
 * po załadowaniu komendy.
 */
final class BladProbyOdtworzenia extends RuntimeException {}

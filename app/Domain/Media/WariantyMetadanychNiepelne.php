<?php

declare(strict_types=1);

namespace App\Domain\Media;

use RuntimeException;

/**
 * `metadata.variants` nie spełnia kontraktu, którego wymaga status `ready`
 * (issue #1905): tablicy nie ma, jest pusta, `null`, albo choć jeden wpis
 * jest uszkodzony (nie jest tablicą, brak `key`, `key` puste albo nie-tekst).
 *
 * Wyjątek NIE niesie identyfikatora medium ani żadnej treści właściciela —
 * to dokłada wołający, który zna kontekst (komenda, rekord). Wiadomość jest
 * bezpieczna do pokazania operatorowi w konsoli.
 */
final class WariantyMetadanychNiepelne extends RuntimeException {}

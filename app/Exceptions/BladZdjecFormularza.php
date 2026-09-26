<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Czytelny błąd uploadu wraz ze zdjęciami, które formularz ma zachować.
 */
class BladZdjecFormularza extends BladDlaCzlowieka
{
    /**
     * @param  list<string>  $mediaIds
     */
    public function __construct(
        string $message,
        public readonly array $mediaIds,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

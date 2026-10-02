<?php

declare(strict_types=1);

namespace App\Domain\Collections;

use App\Exceptions\BladDlaCzlowieka;

/**
 * Zeszyt docelowy ma już tę pozycję (#2430). Nic nie zostało przeniesione,
 * nadpisane ani połączone: obie notatki zostają tam, gdzie były. Wyjątek niesie
 * obie notatki, żeby ekran mógł je obok siebie pokazać (to prywatna strona
 * właściciela).
 */
final class KonfliktPrzeniesienia extends BladDlaCzlowieka
{
    public function __construct(
        public readonly string $nazwaCelu,
        public readonly ?string $notatkaWZrodle,
        public readonly ?string $notatkaWCelu,
    ) {
        parent::__construct(
            'W zeszycie „'.$nazwaCelu.'” ta pozycja już jest. Nic nie zostało przeniesione ani nadpisane — '
            .'obie notatki zostają tam, gdzie były. Wybierz inny zeszyt albo wróć bez zmian.',
        );
    }
}

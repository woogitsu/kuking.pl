<?php

declare(strict_types=1);

namespace App\Domain\Users\Actions;

use App\Models\Media;

/**
 * Stan zapisywany z domknięcia transakcji wymazania konta i czytany po niej
 * (`EraseAccountData`). Obiekt zamiast referencji `&`: analiza statyczna nie
 * śledzi zapisu przez referencję z drugiego domknięcia i uznawała późniejsze
 * warunki za martwe (#1731, poziom 4).
 */
final class StanTransakcjiWymazania
{
    /** @var list<Media> */
    public array $doSkasowania = [];

    public bool $wpisDopisany = false;
}

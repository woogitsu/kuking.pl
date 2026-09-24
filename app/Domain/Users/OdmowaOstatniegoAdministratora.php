<?php

declare(strict_types=1);

namespace App\Domain\Users;

use DomainException;

/** Odmowa przejścia, które zostawiłoby serwis bez czynnego administratora (#1016). */
final class OdmowaOstatniegoAdministratora extends DomainException
{
    public function __construct()
    {
        parent::__construct(OstatniAdministrator::KOMUNIKAT);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Users;

use DomainException;

/**
 * Link resetu hasła przestał być ważny, zanim żądanie wzięło blokadę konta
 * (issue #2055): wykorzystało go równoległe żądanie, wygasł albo został
 * zastąpiony nowym. `PasswordResetController` odpowiada wtedy tym samym
 * zdaniem, co przy każdym innym nieaktualnym linku.
 *
 * Bez tokenu i bez adresu w komunikacie — ten wyjątek nie ma czego zdradzić
 * dziennikowi.
 */
final class LinkResetuNieaktualny extends DomainException
{
    public function __construct()
    {
        parent::__construct('Link resetu hasła nie jest już ważny pod blokadą konta.');
    }
}

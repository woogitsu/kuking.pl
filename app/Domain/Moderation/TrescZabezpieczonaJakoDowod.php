<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Exceptions\BladDlaCzlowieka;

/**
 * Próba przywrócenia treści zabezpieczonej jako dowód (ścieżka CSAM, D-333).
 *
 * Osobna klasa z tego samego powodu co `WlasnejTresciNiePrzywracasz`:
 * `ResolveAppeal::cofnij()` połyka zwykłe `BladDlaCzlowieka`, a odwołanie
 * zamknięte jako „cofnięto” przy treści, która dalej jest ukryta, mówiłoby
 * autorowi nieprawdę (DSA art. 20). Tej odmowy połknąć nie wolno.
 */
final class TrescZabezpieczonaJakoDowod extends BladDlaCzlowieka
{
    public function __construct()
    {
        parent::__construct(
            'Ta treść jest zabezpieczona jako dowód i nie wraca do serwisu, więc nie da się uznać tego odwołania w panelu. '
            .'Sprawa z zabezpieczonym dowodem: przekaż ją właścicielowi serwisu albo prawnikowi — o jej losie decyduje człowiek poza panelem.',
        );
    }
}

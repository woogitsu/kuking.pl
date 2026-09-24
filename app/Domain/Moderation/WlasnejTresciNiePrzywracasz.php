<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Exceptions\BladDlaCzlowieka;

/**
 * Moderator próbuje przywrócić treść, której sam jest autorem (#1479).
 *
 * Osobna klasa, bo `ResolveAppeal::cofnij()` połyka `BladDlaCzlowieka`
 * („już widoczna — nie ma czego cofać") i zamyka odwołanie dalej. Tej
 * odmowy połknąć nie wolno: odwołanie zamknięte jako „cofam", przy
 * treści, która dalej jest schowana, mówiłoby autorowi nieprawdę.
 */
final class WlasnejTresciNiePrzywracasz extends BladDlaCzlowieka
{
    public function __construct()
    {
        parent::__construct(
            'To Twoja własna treść, więc przywrócić ją może tylko ktoś inny z moderacji. '
            .'Jeśli nie zgadzasz się z ukryciem, odwołaj się od tej decyzji tak jak każdy autor.',
        );
    }
}

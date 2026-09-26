<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use Carbon\CarbonInterface;

/**
 * Nowa decyzja wybrana w formularzu rozpatrzenia odwołania (#989).
 *
 * Tylko przy uznaniu odwołania ZGŁASZAJĄCEGO od decyzji bez działania
 * (`Appeal::wymagaNowejDecyzji()`). Te same pola co przy decyzji ze
 * zgłoszenia: akcja z macierzy `ModerationAction::DOZWOLONE`, podstawa
 * z `PodstawaDecyzji`, wiadomość dla autora i — przy zawieszeniu — termin
 * (`null` = bezterminowo, jak w `DlugoscZawieszenia::termin()`).
 */
final readonly class NowaDecyzja
{
    public function __construct(
        public string $akcja,
        public string $podstawa,
        public ?string $wiadomoscDlaAutora = null,
        public ?CarbonInterface $terminZawieszenia = null,
    ) {}
}

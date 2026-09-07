<?php

declare(strict_types=1);

namespace App\Domain\Moderation\Actions;

use App\Models\Appeal;
use App\Notifications\OdpowiedzNaOdwolanieZglaszajacego;
use Illuminate\Support\Facades\Notification;

/**
 * Odpowiedź na odwołanie ZGŁASZAJĄCEGO — dostarczona mailem, nie zapisana
 * jako powiadomienie w serwisie (issue #23, DSA art. 20).
 *
 * Odpowiednik `NotifyAppealOutcome` dla drugiej strony sprawy. Zgłaszający
 * nie ma tabeli `notifications`, do której moglibyśmy pisać — może nie mieć
 * konta wcale — więc kanałem jest e-mail zapisany na `Report`, ten sam,
 * na który poszedł podpisany link do złożenia odwołania.
 */
final class NotifyReporterAppealOutcome
{
    public function handle(Appeal $odwolanie): void
    {
        $zgloszenie = $odwolanie->report;

        // Nie powinno się zdarzyć: żeby to odwołanie w ogóle powstało,
        // zgłaszający musiał dostać podpisany link mailem (`FileReporterAppeal`),
        // a to wymaga adresu. Sprawdzenie zostaje jako obrona w głąb, nie
        // jako ścieżka, którą się dziś przechodzi.
        if ($zgloszenie === null || $zgloszenie->notifier_email === null) {
            return;
        }

        Notification::route('mail', $zgloszenie->notifier_email)
            ->notify(new OdpowiedzNaOdwolanieZglaszajacego($odwolanie));
    }
}

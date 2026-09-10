<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Models\Appeal;
use App\Models\ModerationAction;

/**
 * Wynik `HistoriaSankcji::dlaSpraw()` dla JEDNEJ sprawy (D-070).
 *
 * DLACZEGO OBIEKT, A NIE TABLICA Z TRZEMA KLUCZAMI
 * Bo dwie z tych trzech liczb łatwo pomylić, a pomyłka jest tu kosztowna:
 * `$wszystkich` to ile sankcji autor ma w ogóle, a `$wystapienia` to ile
 * z nich LICZY SIĘ do eskalacji z podręcznika — bez decyzji cofniętych
 * w odwołaniu, bo te są naszymi pomyłkami, nie jego przewinieniami.
 * Widok pytający o „liczbę" dostałby z tablicy tę pierwszą i eskalowałby
 * na podstawie własnych błędów moderacji.
 *
 * `$pozycje` jest przycięte do kilku ostatnich; `$wszystkich` mówi, ile ich
 * było, żeby ucięcie listy nie ukryło skali.
 */
final class HistoriaAutora
{
    /**
     * @param  list<ModerationAction>  $pozycje  najnowsze pierwsze, przycięte
     * @param  int  $wszystkich  wszystkie sankcje tego autora
     * @param  int  $wystapienia  sankcje LICZĄCE SIĘ do eskalacji (bez cofniętych)
     */
    public function __construct(
        public readonly array $pozycje,
        public readonly int $wszystkich,
        public readonly int $wystapienia,
    ) {}

    /** Czy lista na karcie jest przycięta — wtedy trzeba to napisać. */
    public function przyciete(): bool
    {
        return $this->wszystkich > count($this->pozycje);
    }

    /**
     * Zdanie o wyniku odwołania — albo `null`, gdy odwołania nie było.
     *
     * Osobna metoda, a nie `Appeal::statusLabel()` wprost w widoku: tam
     * etykieta jest pisana z punktu widzenia OSOBY, która się odwołała
     * („Decyzja podtrzymana"), a tu potrzebne jest zdanie mówiące
     * moderatorowi, czy pozycja liczy się do eskalacji.
     */
    public static function wynikOdwolania(ModerationAction $decyzja): ?string
    {
        $odwolanie = $decyzja->authorAppeal;

        if ($odwolanie === null) {
            return null;
        }

        return match ($odwolanie->status) {
            Appeal::STATUS_OVERTURNED => 'odwołanie uwzględnione — ta decyzja NIE liczy się do eskalacji',
            Appeal::STATUS_UPHELD => 'odwołanie rozpatrzone, decyzja utrzymana',
            default => 'odwołanie czeka na rozpatrzenie',
        };
    }
}

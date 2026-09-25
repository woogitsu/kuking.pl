<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Questions\PytaniaBezOdpowiedzi;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Przeliczenie licznika „Czeka na odpowiedź (N)” w tle (#372).
 *
 * `ShouldBeUniqueUntilProcessing`: seria komentarzy pod pytaniami zleca jedno
 * zadanie, dopóki poprzednie czeka w kolejce — i tak policzy stan po
 * wszystkich. Blokada zwalnia się, gdy zadanie RUSZA, więc zapis w trakcie
 * liczenia zleca kolejne i nie ginie. Idempotentne: liczy od zera z bazy.
 */
class PrzeliczPytaniaBezOdpowiedzi implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** Zapas na wypadek zatrzymanego workera — harmonogram i tak liczy co 5 minut. */
    public int $uniqueFor = 600;

    /** Za wszystkim, co robi człowiek (D-052) — licznik może poczekać kilka sekund. */
    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(PytaniaBezOdpowiedzi $licznik): void
    {
        if (config('kuking.questions.enabled')) {
            $licznik->przelicz();
        }
    }
}

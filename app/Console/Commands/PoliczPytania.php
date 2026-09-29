<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Questions\PytaniaBezOdpowiedzi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Przelicza licznik „Czeka na odpowiedź (N)” na /pytania (#372).
 *
 * Siatka bezpieczeństwa dla zdarzeń modeli (`AppServiceProvider`): łapie to,
 * czego zapis pytania i komentarza nie widzi — zmianę statusu konta autora,
 * scalenie tagów, zapisy z pominięciem modeli. Pełne uzasadnienie:
 * `App\Domain\Questions\PytaniaBezOdpowiedzi`.
 *
 * Przy wyłączonym dziale czyści cache: liczba sprzed wyłączenia nie może
 * wrócić po ponownym włączeniu jako aktualna.
 */
class PoliczPytania extends Command
{
    protected $signature = 'kuking:policz-pytania';

    protected $description = 'Przelicza licznik pytań bez odpowiedzi (/pytania) i zapisuje go w cache';

    public function handle(PytaniaBezOdpowiedzi $licznik): int
    {
        if (! config('kuking.questions.enabled')) {
            Cache::forget(PytaniaBezOdpowiedzi::KLUCZ_CACHE);
            $this->line('Dział pytań wyłączony — licznik wyczyszczony.');

            return self::SUCCESS;
        }

        $dane = $licznik->przelicz();
        $this->line('Czeka na odpowiedź: '.$dane['wszystkie'].' (tagów z pytaniami: '.count($dane['tagi']).')');

        return self::SUCCESS;
    }
}

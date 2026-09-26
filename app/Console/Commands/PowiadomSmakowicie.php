<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reakcje\PowiadomOSmakowicie;
use Illuminate\Console\Command;

/** Zbiorcze powiadomienie „Smakowicie wygląda", raz dziennie (issue #1813, D-280). */
class PowiadomSmakowicie extends Command
{
    protected $signature = 'kuking:powiadom-smakowicie';

    protected $description = 'Raz dziennie: jedno powiadomienie na autora „N osób napisało: Smakowicie wygląda” (issue #1813).';

    public function handle(PowiadomOSmakowicie $powiadom): int
    {
        $this->info('Powiadomień „Smakowicie wygląda”: '.$powiadom->wyslij().'.');

        return self::SUCCESS;
    }
}

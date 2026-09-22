<?php

declare(strict_types=1);

namespace Kuking\AiPilots;

use RuntimeException;

/** Trwała rezerwacja 0,01 USD na próbę; błędy też zużywają rezerwację. */
final class Budget
{
    public static function reserve(string $path, int $limit = 500): int
    {
        $file = fopen($path, 'c+');
        if ($file === false || ! flock($file, LOCK_EX)) {
            throw new RuntimeException('Nie udało się zarezerwować budżetu. Zatrzymaj pomiar.');
        }
        try {
            $old = stream_get_contents($file);
            if ($old !== '' && ! preg_match('/^\d+\n?$/D', $old)) {
                throw new RuntimeException('Sprawdź uszkodzony licznik budżetu.');
            }
            $count = (int) $old;
            if ($count >= $limit) {
                throw new RuntimeException('Budżet prób wyczerpany. Nie wykonuj kolejnego żądania.');
            }
            rewind($file);
            $next = ($count + 1)."\n";
            if (fwrite($file, $next) !== strlen($next) || ! fflush($file) || ! fsync($file)) {
                throw new RuntimeException('Nie utrwalono rezerwacji. Zatrzymaj pomiar.');
            }

            return $count + 1;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;

/** Cisza 21–8 w strefie odbiorcy; zapis i wynik pozostają w UTC. */
final class PushDeliveryWindow
{
    public function nextAllowedAt(CarbonInterface $instant, string $timezone): CarbonImmutable
    {
        if (! in_array($timezone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('Wybierz prawidłową strefę czasową przed przygotowaniem powiadomień.');
        }

        $local = CarbonImmutable::instance($instant)->setTimezone($timezone);
        if ($local->hour >= 21) {
            $local = $local->addDay()->setTime(8, 0);
        } elseif ($local->hour < 8) {
            $local = $local->setTime(8, 0);
        }

        return $local->setTimezone('UTC');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Monitoring;

use DateInterval;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Pamięć wyciszania nie może odciąć alarmu o awarii tej samej bazy (#599).
 * Przy niedostępnym cache tracimy deduplikację i odwołanie, ale próbujemy
 * wysłać bieżący alarm przy każdym przebiegu. Nie udajemy trwałego zapisu.
 * Nie ma zapasowego cache lokalnego: po restarcie i tak zgubiłby stan,
 * a różne repliki pamiętałyby różne epizody.
 */
final class AlarmMemory
{
    public function get(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $state */
    public function put(string $key, array $state, DateInterval $ttl): void
    {
        try {
            Cache::put($key, $state, $ttl);
        } catch (Throwable) {
            // Brak zapisu nie jest potwierdzeniem dostarczenia ani ciszy.
        }
    }

    public function forget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable) {
            // Stan może wrócić po naprawie cache; możliwe ponowne odwołanie.
        }
    }
}

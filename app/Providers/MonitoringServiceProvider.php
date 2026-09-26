<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Monitoring\CzasZapytan;
use Illuminate\Support\ServiceProvider;

/**
 * Pomiary działające w każdym żądaniu HTTP (#599). Dziś jeden: łączny czas
 * zapytań SQL (`CzasZapytan`). W konsoli (komendy, worker, testy) nic się
 * tu nie rejestruje — patrz uzasadnienie w klasie pomiaru.
 */
final class MonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CzasZapytan::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        CzasZapytan::zarejestruj($this->app, (int) config('kuking.monitoring.czas_bazy_prog_ms'));
    }
}

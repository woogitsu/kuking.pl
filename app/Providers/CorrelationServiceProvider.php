<?php

declare(strict_types=1);

namespace App\Providers;

use App\Logging\QueueCorrelation;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Throwable;

final class CorrelationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QueueCorrelation::class);
    }

    public function boot(): void
    {
        // Nie przechwytujemy $app w statycznym hooku payloadu: w testach
        // i długowiecznych procesach kontener może zostać odtworzony.
        Queue::createPayloadUsing(static fn () => app(QueueCorrelation::class)->payload());
        $this->app['events']->listen(JobProcessing::class, static fn (JobProcessing $event) => app(QueueCorrelation::class)->begin($event));
        $this->app['events']->listen(JobAttempted::class, static fn (JobAttempted $event) => app(QueueCorrelation::class)->finish($event));

        $handler = $this->app->make(ExceptionHandler::class);
        if ($handler instanceof Handler) {
            $handler->buildContextUsing(static fn (Throwable $e) => app(QueueCorrelation::class)->forException($e));
        }
    }
}

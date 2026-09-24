<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Prawdziwie serializowany job, wyłącznie do pomiaru cyklu workera. */
final class CorrelationProbeJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $marker, public string $mode = 'success') {}

    public function handle(): void
    {
        Log::warning($this->marker);
        Log::channel('queue_correlation_late')->warning($this->marker.'-late');
        if ($this->mode === 'nested') {
            try {
                dispatch_sync(new self('nested-child', 'failure'));
            } catch (RuntimeException $e) {
                report($e);
            }
            Log::warning('after-nested-child');
        }
        if ($this->mode === 'failure' || ($this->mode === 'retry' && $this->attempts() === 1)) {
            throw new RuntimeException('correlation-probe-failure');
        }
    }
}

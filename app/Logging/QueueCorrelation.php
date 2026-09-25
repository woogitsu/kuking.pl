<?php

declare(strict_types=1);

namespace App\Logging;

use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;
use WeakMap;

/** Jawna, ograniczona koperta techniczna; żadnych danych konta ani sesji. */
final class QueueCorrelation
{
    public const PAYLOAD = 'kuking:correlation';

    private const KEYS = ['request_id', 'job_id', 'attempt_id'];

    /** @var WeakMap<object, array{previous: array<string, mixed>, current: array<string, string>}> */
    private WeakMap $jobs;

    /** @var WeakMap<Throwable, array<string, string>> */
    private WeakMap $exceptions;

    public function __construct()
    {
        $this->jobs = new WeakMap;
        $this->exceptions = new WeakMap;
    }

    /** @return array<string, array<string, string>> */
    public function payload(): array
    {
        $context = [];
        $requestId = ((array) Log::sharedContext())['request_id'] ?? null;
        if (self::validId($requestId)) {
            $context['request_id'] = $requestId;
        }

        return [self::PAYLOAD => $context];
    }

    public function begin(JobProcessing $event): void
    {
        $payload = $event->job->payload()[self::PAYLOAD] ?? [];
        $payload = is_array($payload) ? $payload : [];
        $context = [
            // Laravel nadaje losowy UUID w payloadzie i zachowuje go przy retry.
            // Nie tworzymy drugiego ID tego samego zadania. Raw/stare payloady
            // bez poprawnego UUID dostają kod tego wykonania, nie danych domeny.
            'job_id' => self::validId($event->job->uuid()) ? $event->job->uuid() : (string) Str::uuid(),
            'attempt_id' => (string) Str::uuid(),
        ];
        if (self::validId($payload['request_id'] ?? null)) {
            $context['request_id'] = $payload['request_id'];
        }

        $this->jobs[$event->job] = [
            'previous' => array_intersect_key((array) Log::sharedContext(), array_flip(self::KEYS)),
            'current' => $context,
        ];
        $this->replace($context);
    }

    public function finish(JobAttempted $event): void
    {
        if (! isset($this->jobs[$event->job])) {
            return;
        }
        $scope = $this->jobs[$event->job];
        if ($event->exception instanceof Throwable) {
            // Worker raportuje dopiero PO JobAttempted. Słaba mapa utrzymuje
            // same ID przy wyjątku, bez przecieku do następnego zadania.
            // Zagnieżdżone sync może rzucić ten sam obiekt: zachowujemy
            // najgłębsze zadanie, z którego rzeczywiście przyszedł błąd.
            $this->exceptions[$event->exception] ??= $scope['current'];
        }
        unset($this->jobs[$event->job]);
        $this->replace($scope['previous']);
    }

    /** @return array<string, string> */
    public function forException(Throwable $exception): array
    {
        return $this->exceptions[$exception] ?? [];
    }

    /** @phpstan-assert-if-true string $id */
    public static function validId(mixed $id): bool
    {
        return is_string($id)
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $id) === 1;
    }

    /** @param array<string, mixed> $context poprzedni kontekst dziennika albo identyfikatory tego zadania */
    private function replace(array $context): void
    {
        $other = array_diff_key((array) Log::sharedContext(), array_flip(self::KEYS));
        Log::withoutContext(self::KEYS);
        Log::flushSharedContext();
        Log::shareContext([...$other, ...$context]);
    }
}

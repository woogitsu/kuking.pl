<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Logging\QueueCorrelation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\LoggedExceptionCollection;
use Monolog\Formatter\JsonFormatter;
use Tests\Support\CorrelationProbeJob;
use Tests\TestCase;
use WeakReference;

/** Database queue i ten sam prawdziwy Worker; bez Queue::fake i ręcznych eventów. */
final class KorelacjaKolejkiTest extends TestCase
{
    use RefreshDatabase;

    private string $logPath;

    private string $queueName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queueName = 'correlation-'.Str::uuid();
        $this->logPath = storage_path('logs/'.$this->queueName.'.log');
        $channel = ['driver' => 'single', 'path' => $this->logPath, 'formatter' => JsonFormatter::class];
        config([
            'app.debug' => false,
            'logging.default' => 'queue_correlation',
            'logging.channels.queue_correlation' => $channel,
            'logging.channels.queue_correlation_late' => $channel,
            'logging.channels.blad_webhook.url' => 'https://alarm.example.test/blad',
        ]);
        Http::preventStrayRequests();
        Http::fake();
    }

    protected function tearDown(): void
    {
        Log::forgetChannel('queue_correlation');
        Log::forgetChannel('queue_correlation_late');
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
        parent::tearDown();
    }

    public function test_http_do_workera_retry_i_nastepnego_zadania_bez_przecieku(): void
    {
        Route::get('/_test/kolejka-korelacja', function () {
            // Dodatkowy kontekst nie ma prawa trafić do naszej koperty.
            Log::shareContext(['test_secret' => 'sekret-sesji']);
            Queue::connection('database')->push(new CorrelationProbeJob('retry-job', 'retry'), '', $this->queueName);

            return response('', 204);
        });
        $response = $this->get('/_test/kolejka-korelacja', ['X-Request-ID' => '11111111-1111-4111-8111-111111111111'])->assertNoContent();
        $requestId = $response->headers->get('X-Request-ID');
        $payload = json_decode(DB::table('jobs')->where('queue', $this->queueName)->value('payload'), true);
        $this->assertSame(['request_id' => $requestId], $payload[QueueCorrelation::PAYLOAD], 'BRAK_PROPAGACJI_HTTP');
        $this->assertStringNotContainsString('sekret-sesji', json_encode($payload));
        $jobId = $payload['uuid'];
        $this->assertTrue(Str::isUuid($jobId, 4));
        Log::withoutContext();
        Log::flushSharedContext();
        Queue::connection('database')->push(new CorrelationProbeJob('independent-job'), '', $this->queueName);

        $events = [];
        $references = [];
        Event::listen(JobAttempted::class, function (JobAttempted $event) use (&$events, &$references): void {
            $events[] = $event->exception !== null;
            if ($event->exception !== null) {
                $references[] = WeakReference::create($event->exception);
            }
            // Ten obserwator działa PO naszym listenerze, ale PRZED report().
            Log::warning('after-attempt');
        });
        $alarmOrder = [];
        Http::fake(function () use (&$events, &$alarmOrder) {
            $alarmOrder[] = count($events);

            return Http::response('', 200);
        });
        $worker = app('queue.worker');
        $this->assertInstanceOf(Worker::class, $worker);
        for ($i = 0; $i < 3; $i++) {
            $worker->runNextJob('database', $this->queueName, new WorkerOptions(sleep: 0, maxTries: 2, backoff: 0));
            Log::warning('between-jobs');
        }
        $this->assertCount(3, $events);
        $this->assertSame(1, count(array_filter($events)));
        $this->assertSame([1], $alarmOrder, 'Alarm musi powstać po JobAttempted pierwszej próby.');
        // Testowy kolektor Laravela celowo zatrzymuje raportowane wyjątki
        // dla assertStatus. Zwolnij jego referencje przed pomiarem naszej mapy.
        $collected = $this->app->make(LoggedExceptionCollection::class);
        $collected->forget($collected->keys()->all());
        gc_collect_cycles();
        $this->assertCount(1, $references);
        $this->assertNull($references[0]->get(), 'Korelacja nie może utrzymywać wyjątku przy życiu.');
        $this->assertSame(0, DB::table('jobs')->where('queue', $this->queueName)->count());
        $records = $this->records();
        $attempts = $this->matching($records, 'retry-job');
        $this->assertCount(2, $attempts);
        foreach ($attempts as $record) {
            $this->assertSame($requestId, $record['context']['request_id'] ?? null, 'BRAK_PROPAGACJI_HTTP');
            $this->assertSame($jobId, $record['context']['job_id']);
            $this->assertTrue(Str::isUuid($record['context']['attempt_id'], 4));
        }
        $this->assertNotSame($attempts[0]['context']['attempt_id'], $attempts[1]['context']['attempt_id']);
        $late = $this->matching($records, 'retry-job-late');
        $this->assertCount(2, $late);
        $this->assertSame($attempts[0]['context'], $late[0]['context']);

        $independent = $this->matching($records, 'independent-job');
        $this->assertCount(1, $independent);
        $this->assertArrayNotHasKey('request_id', $independent[0]['context'], 'PRZECIEK_KORELACJI_KOLEJKI');
        $this->assertNotSame($jobId, $independent[0]['context']['job_id']);
        $failure = $this->matching($records, 'correlation-probe-failure');
        $this->assertCount(1, $failure);
        foreach (['request_id', 'job_id', 'attempt_id'] as $key) {
            $this->assertSame($attempts[0]['context'][$key], $failure[0]['context'][$key] ?? null, 'BRAK_KORELACJI_PO_ATTEMPTED');
        }
        Http::assertSentCount(1);
        $alarm = Http::recorded()[0][0]['text'];
        foreach ([$requestId, $jobId, $attempts[0]['context']['attempt_id']] as $id) {
            $this->assertStringContainsString($id, $alarm, 'BRAK_KORELACJI_ALARMU_KOLEJKI');
        }
        foreach (['after-attempt', 'between-jobs'] as $marker) {
            $between = $this->matching($records, $marker);
            $this->assertCount(3, $between);
            foreach ($between as $record) {
                foreach (['request_id', 'job_id', 'attempt_id'] as $key) {
                    $this->assertArrayNotHasKey($key, $record['context'], 'PRZECIEK_KORELACJI_KOLEJKI');
                }
            }
        }
    }

    public function test_zagniezdzone_sync_przywraca_kontekst_po_bledzie_dziecka(): void
    {
        $requestId = (string) Str::uuid();
        Log::shareContext(['request_id' => $requestId, 'retained' => 'yes']);
        dispatch_sync(new CorrelationProbeJob('parent-job', 'nested'));
        Log::warning('after-sync');
        $records = $this->records();
        $parent = $this->matching($records, 'parent-job')[0]['context'];
        $child = $this->matching($records, 'nested-child')[0]['context'];
        $after = $this->matching($records, 'after-nested-child')[0]['context'];
        $this->assertSame($parent, $after, 'NIE_PRZYWROCONO_RODZICA');
        $this->assertSame($requestId, $child['request_id']);
        $this->assertNotSame($parent['job_id'], $child['job_id']);
        $this->assertSame($child['job_id'], $this->matching($records, 'correlation-probe-failure')[0]['context']['job_id']);
        $this->assertSame(['retained' => 'yes', 'request_id' => $requestId], $this->matching($records, 'after-sync')[0]['context']);
        Http::assertSentCount(1);
        $this->assertStringContainsString($child['job_id'], Http::recorded()[0][0]['text']);
        $this->assertStringNotContainsString($parent['job_id'], Http::recorded()[0][0]['text']);
    }

    public function test_ostateczna_porazka_nie_zostawia_kontekstu_w_workerze(): void
    {
        Queue::connection('database')->push(new CorrelationProbeJob('failed-job', 'failure'), '', $this->queueName);
        $failed = 0;
        Event::listen(JobFailed::class, function () use (&$failed): void {
            $failed++;
            Log::warning('failed-event');
        });
        $worker = app('queue.worker');
        for ($i = 0; $i < 2; $i++) {
            $worker->runNextJob('database', $this->queueName, new WorkerOptions(sleep: 0, maxTries: 2, backoff: 0));
            Log::warning('after-failed-job');
        }
        $this->assertSame(1, $failed);
        $this->assertSame(0, DB::table('jobs')->where('queue', $this->queueName)->count());
        Http::assertSentCount(2);
        $records = $this->records();
        $attempts = $this->matching($records, 'failed-job');
        $this->assertCount(2, $attempts);
        $this->assertSame($attempts[0]['context']['job_id'], $attempts[1]['context']['job_id']);
        $this->assertNotSame($attempts[0]['context']['attempt_id'], $attempts[1]['context']['attempt_id']);
        $this->assertSame($attempts[1]['context'], $this->matching($records, 'failed-event')[0]['context']);
        foreach ($this->matching($records, 'after-failed-job') as $record) {
            foreach (['request_id', 'job_id', 'attempt_id'] as $key) {
                $this->assertArrayNotHasKey($key, $record['context'], 'PRZECIEK_PO_FAILED');
            }
        }
        foreach (Http::recorded() as $index => $pair) {
            $this->assertStringContainsString($attempts[$index]['context']['attempt_id'], $pair[0]['text']);
        }
        $this->assertSame([], Log::sharedContext());
    }

    public function test_uszkodzona_koperta_nie_wynosi_sekretow_i_nie_blokuje_zadania(): void
    {
        Queue::connection('database')->push(new CorrelationProbeJob('bad-envelope'), '', $this->queueName);
        $row = DB::table('jobs')->where('queue', $this->queueName)->first();
        $payload = json_decode($row->payload, true);
        $payload[QueueCorrelation::PAYLOAD] = ['request_id' => "sekret@example.test\nheader", 'session' => 'haslo'];
        DB::table('jobs')->where('id', $row->id)->update(['payload' => json_encode($payload)]);
        app('queue.worker')->runNextJob('database', $this->queueName, new WorkerOptions(sleep: 0));
        $context = $this->matching($this->records(), 'bad-envelope')[0]['context'];
        $this->assertArrayNotHasKey('request_id', $context);
        $this->assertSame(['job_id', 'attempt_id'], array_keys($context));
        $this->assertTrue(Str::isUuid($context['job_id'], 4));
        $this->assertStringNotContainsString('sekret@example.test', file_get_contents($this->logPath));
        $this->assertStringNotContainsString('haslo', file_get_contents($this->logPath));
        $this->assertSame([], Log::sharedContext());
    }

    /** @return list<array<string, mixed>> */
    private function records(): array
    {
        return array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    /** @return list<array<string, mixed>> */
    private function matching(array $records, string $message): array
    {
        return array_values(array_filter($records, fn ($record) => $record['message'] === $message));
    }
}

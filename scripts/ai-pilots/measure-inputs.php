<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Kuking\AiPilots\Pilot;
use Kuking\AiPilots\Transport;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/Pilot.php';
require __DIR__.'/Transport.php';

$http = new Factory;
$http->preventStrayRequests();
$http->fake();
$client = new Transport($http, 'atrapa');
$report = [];
foreach (['search', 'help', 'recipe', 'public-recipe'] as $name) {
    $path = __DIR__.'/corpus/'.$name.'.json';
    if (! is_file($path)) {
        continue;
    }
    $cases = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    foreach ($cases as $case) {
        $task = $name === 'public-recipe' ? 'recipe' : $name;
        $payload = $client->payload($task, $case['text']);
        $integrity = null;
        if ($task === 'recipe') {
            $count = count(Pilot::tokens($case['text']));
            $valid = ['segments' => [['end' => $count, 'kind' => 'review']]];
            $draft = Pilot::validate('recipe', $case['text'], $valid);
            if ($draft['segments'][0]['text'] !== $case['text']) {
                throw new RuntimeException('Nie zachowano oryginału.');
            }
            $rejected = 0;
            foreach ([
                ['segments' => [['end' => $count, 'kind' => 'review', 'text' => '200 g mąki']]],
                $valid + ['servings' => 4],
                ['segments' => [['end' => $count - 1, 'kind' => 'review']]],
                ['segments' => [['end' => $count + 1, 'kind' => 'ingredient']]],
            ] as $bad) {
                try {
                    Pilot::validate('recipe', $case['text'], $bad);
                } catch (DomainException) {
                    $rejected++;
                }
            }
            if ($rejected !== 4) {
                throw new RuntimeException('Przyjęto dopisek lub pominięcie.');
            }
            $integrity = ['exact_source_preserved' => true, 'fabrications_rejected' => $rejected,
                'proposal_origin' => 'ręczna atrapa, cały tekst do sprawdzenia', 'model_quality_measured' => false];
        }
        $report[] = ['id' => $case['id'], 'task' => $task, 'corpus' => $name,
            'chars' => mb_strlen($case['text']), 'bytes' => strlen($case['text']),
            'request_bytes' => strlen(Transport::encode($payload)),
            'max_output_tokens' => $payload['max_output_tokens'],
            'source_sha256' => hash('sha256', $case['text']),
            'source_url' => $case['source'] ?? null, 'requests' => 0, 'integrity' => $integrity];
    }
}
$http->assertNothingSent();
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

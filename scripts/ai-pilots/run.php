<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Kuking\AiPilots\Budget;
use Kuking\AiPilots\Transport;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/Pilot.php';
require __DIR__.'/Transport.php';
require __DIR__.'/Budget.php';

// To NIE jest test PHPUnit ani komenda dostępna w portalu. Nie czyta .env.
$options = getopt('', ['live', 'task:', 'repeat:', 'limit:', 'public']);
$task = $options['task'] ?? '';
if (PHP_SAPI !== 'cli' || ! isset($options['live']) || getenv('APP_ENV') === 'testing' || defined('PHPUNIT_COMPOSER_INSTALL')
    || ! in_array($task, ['search', 'help', 'recipe'], true)) {
    fwrite(STDERR, "Uruchom świadomy pomiar CLI: --live --task=search|help|recipe [--repeat=2] [--public]. Nigdy w testach.\n");
    exit(2);
}
$key = getenv('OPENAI_API_KEY');
if (! is_string($key) || trim($key) === '') {
    fwrite(STDERR, "Udostępnij OPENAI_API_KEY w środowisku WSL. Nie wpisuj klucza w poleceniu.\n");
    exit(2);
}
$public = isset($options['public']);
if ($public && $task !== 'recipe') {
    fwrite(STDERR, "Publiczny zbiór opisów dotyczy pilota recipe.\n");
    exit(2);
}
$name = $public ? 'public-recipe' : $task;
$path = __DIR__.'/corpus/'.$name.'.json';
$cases = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
$repeat = filter_var($options['repeat'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 3]]);
$limit = filter_var($options['limit'] ?? count($cases), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => count($cases)]]);
if ($repeat === false || $limit === false) {
    fwrite(STDERR, "Wybierz 1–3 powtórzenia i dodatni limit nie większy od zbioru.\n");
    exit(2);
}
$directory = getenv('KUKING_AI_PILOT_OUTPUT') ?: __DIR__.'/../../storage/ai-pilots';
if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
    exit(2);
}
$output = $directory.'/'.$name.'-'.gmdate('Ymd-His').'.jsonl';
$handle = fopen($output, 'x');
if ($handle === false) {
    exit(2);
}
$client = new Transport(new Factory, $key);
$failures = 0;
try {
    for ($round = 1; $round <= $repeat; $round++) {
        foreach (array_slice($cases, 0, $limit) as $case) {
            // Tylko tekst. URL źródła i etykiety referencyjne NIE trafiają do modelu.
            $client->payload($task, $case['text']);
            $reservation = Budget::reserve($directory.'/reservations');
            $result = $client->run($task, $case['text']);
            $record = ['id' => $case['id'], 'round' => $round, 'reservation' => $reservation,
                'corpus_sha256' => hash_file('sha256', $path),
                'code_sha256' => hash_file('sha256', __DIR__.'/Pilot.php'), 'time_utc' => gmdate('c')] + $result;
            $line = Transport::encode($record)."\n";
            if (fwrite($handle, $line) !== strlen($line) || ! fflush($handle)) {
                throw new RuntimeException('Nie zapisano wyniku. Zatrzymaj pomiar.');
            }
            echo $case['id'].' #'.$round.' '.($result['error'] ?? 'ok').' '.$result['latency_ms']." ms\n";
            // Brak prawa do modelu/środków: jedna próba wystarczy. Nie mielimy setki odmów.
            if (in_array($result['http_status'] ?? null, [401, 403, 404, 429], true)) {
                exit(3);
            }
            $failures = $result['error'] === 'transport_error' ? $failures + 1 : 0;
            if ($failures >= 3) {
                exit(3);
            }
        }
    }
} catch (Throwable) {
    // Wyjątki transportu ani dane klucza nie są diagnostyką do wypisania.
    fwrite(STDERR, "Pomiar zatrzymany. Sprawdź limity wejścia, pliki wyników i licznik rezerwacji.\n");
    exit(2);
} finally {
    fclose($handle);
}
echo 'Wyniki: '.$output."\n";

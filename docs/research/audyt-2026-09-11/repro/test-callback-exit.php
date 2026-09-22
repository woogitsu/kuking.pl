<?php
declare(strict_types=1);
// Isolated contract check. The expression is the one used by Laravel 13.x
// CallbackEvent::execute. This is NOT an execution of the complete application.
function scheduledExit(mixed $callbackResult): int {
    return $callbackResult === false ? 1 : 0;
}
$rows = [];
foreach ([0, 1, 2, false] as $result) {
    $rows[] = ['callback_result' => $result, 'event_exit_code' => scheduledExit($result)];
}
if (scheduledExit(1) !== 0 || scheduledExit(false) !== 1) {
    throw new RuntimeException('Unexpected callback contract');
}
echo json_encode([
    'scope' => 'Isolated Laravel 13.x callback-result contract, not full scheduler execution',
    'integer_failure_reported_as_success' => scheduledExit(1) === 0,
    'cases' => $rows,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
